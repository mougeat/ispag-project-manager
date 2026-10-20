<?php
if (!defined('ABSPATH')) exit;

/**
 * Application iPhone — écrans en lecture seule : fiche société, stock, commandes fournisseurs.
 * Mêmes droits que sur le site : view_company, view_stock / manage_stock, view_supplier_order.
 * Ces données ne sont jamais gardées sur le téléphone (lues en ligne à chaque ouverture).
 */
class ISPAG_Mobile_More {

    public static function init() {
        add_action('rest_api_init', [self::class, 'register_routes']);
    }

    public static function caps(int $user_id): array {
        return [
            'company'   => class_exists('ISPAG_Crm_Company_Repository') && user_can($user_id, 'view_company'),
            'stock'     => class_exists('ISPAG_Stock_Movements') && (user_can($user_id, 'view_stock') || user_can($user_id, 'manage_stock')),
            'purchases' => user_can($user_id, 'view_supplier_order'),
        ];
    }

    public static function register_routes() {
        if (!class_exists('ISPAG_Mobile_App')) return;
        foreach (['company' => 'rest_company', 'stock' => 'rest_stock', 'purchases' => 'rest_purchases', 'purchase' => 'rest_purchase'] as $route => $cb) {
            register_rest_route(ISPAG_Mobile_App::NS, '/' . $route, [
                'methods' => 'GET', 'callback' => [self::class, $cb], 'permission_callback' => [ISPAG_Mobile_App::class, 'authenticate'],
            ]);
        }
        register_rest_route(ISPAG_Mobile_App::NS, '/companies', [
            'methods' => 'GET', 'callback' => [self::class, 'rest_companies'], 'permission_callback' => [ISPAG_Mobile_App::class, 'authenticate'],
        ]);
        register_rest_route(ISPAG_Mobile_App::NS, '/company-active', [
            'methods' => 'POST', 'callback' => [self::class, 'rest_company_active'], 'permission_callback' => [ISPAG_Mobile_App::class, 'authenticate'],
        ]);
        // Relance groupée des offres d'une entreprise (case et rythme de la fiche entreprise du CRM)
        register_rest_route(ISPAG_Mobile_App::NS, '/company-grouped', [
            'methods' => 'POST', 'callback' => [self::class, 'rest_company_grouped'], 'permission_callback' => [ISPAG_Mobile_App::class, 'authenticate'],
        ]);
    }

    private static function clean($text, int $max = 0): string {
        $text = html_entity_decode(wp_strip_all_tags(stripslashes((string) $text)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = trim(preg_replace('/\s+/', ' ', $text));
        return $max && mb_strlen($text) > $max ? mb_substr($text, 0, $max - 1) . '…' : $text;
    }

    private static function forbidden() {
        return new WP_REST_Response(['message' => 'forbidden'], 403);
    }

    // ------------------------------------------------------------------ société

    public static function rest_company(WP_REST_Request $request) {
        global $wpdb;
        $me = get_current_user_id();
        if (!self::caps($me)['company']) return self::forbidden();
        $id = (int) $request->get_param('id');
        $c  = $id ? (new ISPAG_Crm_Company_Repository())->get_company_by_id($id) : null;
        if (!$c) return new WP_REST_Response(['message' => 'not found'], 404);
        $p = $wpdb->prefix;

        $contacts = [];
        $uids = $wpdb->get_col($wpdb->prepare("SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value = %s LIMIT 60", ISPAG_Crm_Contact_Constants::META_COMPANY_ID, (string) $id));
        if ($uids) {
            foreach (get_users(['include' => array_map('intval', $uids), 'fields' => ['ID', 'display_name', 'user_email'], 'number' => 60]) as $u) {
                $contacts[] = [
                    'id' => (int) $u->ID, 'name' => self::clean($u->display_name), 'email' => (string) $u->user_email,
                    'phone'    => self::clean(get_user_meta($u->ID, ISPAG_Crm_Contact_Constants::META_LEAD_PHONE, true)),
                    'function' => self::clean(get_user_meta($u->ID, ISPAG_Crm_Contact_Constants::META_LEAD_FUNCTION, true)),
                ];
            }
            usort($contacts, function ($a, $b) { return strcasecmp($a['name'], $b['name']); });
        }

        $prices = user_can($me, 'display_sales_prices');
        $offers = [];
        if (class_exists('ISPAG_Crm_Deal_Constants')) {
            $deals = ISPAG_Crm_Deal_Constants::TABLE_NAME;
            foreach ((array) $wpdb->get_results($wpdb->prepare(
                "SELECT deal_group_ref, offer_num, project_name, date_creation, total_excl_vat, deal_owner FROM {$deals}
                 WHERE associated_company_id = %d ORDER BY date_creation DESC, id DESC LIMIT 40", $id
            )) as $o) {
                $ref = (string) ($o->deal_group_ref !== '' && $o->deal_group_ref !== null ? $o->deal_group_ref : strtok((string) $o->offer_num, '.'));
                if (isset($offers[$ref])) continue;
                $offers[$ref] = ['ref' => $ref, 'name' => self::clean($o->project_name, 140), 'created' => (string) $o->date_creation, 'amount' => $prices ? (float) $o->total_excl_vat : null, 'mine' => (int) $o->deal_owner === $me];
                if (count($offers) >= 15) break;
            }
        }

        $projects = [];
        if (user_can($me, 'manage_order')) {
            $where = ISPAG_Mobile_App::any_status_sql($me);
            foreach ((array) $wpdb->get_results($wpdb->prepare(
                "SELECT p.hubspot_deal_id AS deal_id, p.NumCommande AS number, p.ObjetCommande AS title, p.isQotation AS is_offer, p.project_status AS status, p.date_creation AS created
                 FROM {$p}achats_liste_commande p WHERE p.AssociatedCompanyID = %d AND {$where} ORDER BY p.date_creation DESC LIMIT 20", $id
            )) as $r) {
                $projects[] = ['deal_id' => (int) $r->deal_id, 'number' => (string) $r->number, 'title' => self::clean($r->title, 140), 'is_offer' => (int) $r->is_offer === 1, 'closed' => (int) $r->status !== 1, 'created' => (string) $r->created];
            }
        }

        $grouped = ['available' => false];
        if (class_exists('ISPAG_Crm_Deal_Follow_Up') && class_exists('ISPAG_Crm_Company_Constants')) {
            $all = ISPAG_Crm_Deal_Follow_Up::grouped_companies(true);
            $days = (int) ($all[$id] ?? 0);
            if (!$days) $days = self::grouped_days($id);
            $grouped = ['available' => true, 'on' => isset($all[$id]), 'days' => $days, 'can_edit' => self::can_edit_company($me)];
        }

        $addr = trim(implode(' ', array_filter([(string) ($c->address ?? ''), (string) ($c->postal_code ?? ''), (string) ($c->city ?? '')])));
        return new WP_REST_Response([
            'id'       => $id,
            'name'     => self::clean($c->company_name),
            'address'  => self::clean($addr . (!empty($c->country) ? ', ' . $c->country : '')),
            'phone'    => self::clean($c->phone ?? ''),
            'email'    => (string) ($c->email ?? ''),
            'contacts' => $contacts,
            'offers'   => array_values($offers),
            'projects' => $projects,
            'grouped'  => $grouped,
            'active'   => (int) ($c->is_active ?? 1) === 1,
            'can_edit' => self::can_edit_company($me),
        ], 200);
    }

    /** Recherche d'entreprises par nom ; sans recherche : celles des offres les plus récentes. [{id, name, city}] */
    public static function rest_companies(WP_REST_Request $request) {
        global $wpdb;
        $me = get_current_user_id();
        if (!self::caps($me)['company']) return self::forbidden();
        $t = ISPAG_Crm_Company_Constants::TABLE_NAME;
        $q = trim((string) sanitize_text_field((string) $request->get_param('q')));
        if ($q !== '') {
            $rows = $wpdb->get_results($wpdb->prepare("SELECT Id, company_name, city, is_active FROM {$t} WHERE company_name LIKE %s ORDER BY company_name ASC LIMIT 40", '%' . $wpdb->esc_like($q) . '%'));
        } else {
            $deals = ISPAG_Crm_Deal_Constants::TABLE_NAME;
            $rows = $wpdb->get_results("SELECT c.Id, c.company_name, c.city, c.is_active FROM {$t} c JOIN {$deals} d ON d.associated_company_id = c.Id GROUP BY c.Id, c.company_name, c.city, c.is_active ORDER BY MAX(d.date_creation) DESC LIMIT 30");
        }
        $out = [];
        foreach ((array) $rows as $r) $out[] = ['id' => (int) $r->Id, 'name' => self::clean($r->company_name, 120), 'city' => self::clean($r->city ?? '', 60), 'active' => (int) ($r->is_active ?? 1) === 1];
        return new WP_REST_Response(['results' => $out], 200);
    }

    private static function can_edit_company(int $user_id): bool {
        return user_can($user_id, 'edit_company') || user_can($user_id, 'manage_options');
    }

    /** Active / désactive une entreprise (colonne is_active, comme le switch « Entreprise active » de la fiche du site). */
    public static function rest_company_active(WP_REST_Request $request) {
        global $wpdb;
        $me = get_current_user_id();
        if (!self::caps($me)['company'] || !self::can_edit_company($me)) return self::forbidden();
        $id = (int) $request->get_param('id');
        if (!$id || !(new ISPAG_Crm_Company_Repository())->get_company_by_id($id)) return new WP_REST_Response(['message' => 'not found'], 404);
        $on = filter_var($request->get_param('active'), FILTER_VALIDATE_BOOLEAN);
        if ($wpdb->update(ISPAG_Crm_Company_Constants::TABLE_NAME, ['is_active' => $on ? 1 : 0], ['Id' => $id], ['%d'], ['%d']) === false) return new WP_REST_Response(['message' => 'error'], 500);
        return new WP_REST_Response(['active' => $on], 200);
    }

    /** Rythme enregistré de la relance groupée (même si la case est décochée), 14 jours à défaut. */
    private static function grouped_days(int $company_id): int {
        global $wpdb;
        $v = $wpdb->get_var($wpdb->prepare(
            'SELECT meta_value FROM ' . ISPAG_Crm_Company_Constants::TABLE_COMPANY_META . ' WHERE company_id = %d AND meta_key = %s ORDER BY meta_id DESC LIMIT 1',
            $company_id, ISPAG_Crm_Company_Constants::GROUPED_FOLLOW_UP_DAYS
        ));
        return $v !== null && (int) $v > 0 ? min(365, (int) $v) : ISPAG_Crm_Deal_Follow_Up::DEFAULT_GROUP_DAYS;
    }

    /** Active / désactive la relance groupée d'une entreprise et règle son rythme (mêmes métas que la fiche entreprise du site). */
    public static function rest_company_grouped(WP_REST_Request $request) {
        $me = get_current_user_id();
        if (!self::caps($me)['company'] || !self::can_edit_company($me)) return self::forbidden();
        if (!class_exists('ISPAG_Crm_Deal_Follow_Up') || !class_exists('ISPAG_Crm_Company_Creator') || !class_exists('ISPAG_Crm_Company_Constants')) return new WP_REST_Response(['message' => 'unavailable'], 503);
        $id = (int) $request->get_param('id');
        if (!$id || !(new ISPAG_Crm_Company_Repository())->get_company_by_id($id)) return new WP_REST_Response(['message' => 'not found'], 404);
        if ($request->get_param('enabled') !== null) {
            ISPAG_Crm_Company_Creator::save_company_meta($id, ISPAG_Crm_Company_Constants::GROUPED_FOLLOW_UP, filter_var($request->get_param('enabled'), FILTER_VALIDATE_BOOLEAN) ? '1' : '0');
        }
        if ($request->get_param('days') !== null) {
            ISPAG_Crm_Company_Creator::save_company_meta($id, ISPAG_Crm_Company_Constants::GROUPED_FOLLOW_UP_DAYS, (string) min(365, max(1, (int) $request->get_param('days'))));
        }
        $all = ISPAG_Crm_Deal_Follow_Up::grouped_companies(true);
        return new WP_REST_Response(['on' => isset($all[$id]), 'days' => isset($all[$id]) ? (int) $all[$id] : self::grouped_days($id)], 200);
    }

    // ------------------------------------------------------------------ stock

    public static function rest_stock(WP_REST_Request $request) {
        global $wpdb;
        $me = get_current_user_id();
        if (!self::caps($me)['stock']) return self::forbidden();
        $q = trim(sanitize_text_field((string) $request->get_param('q')));
        $locations = [];
        foreach ((array) ISPAG_Stock_Locations::all(true) as $l) $locations[] = ['id' => (int) $l->id, 'name' => self::clean($l->name)];
        $overview = ISPAG_Stock_Movements::overview();
        $ids = array_keys($overview);
        $rows = [];
        if ($ids) {
            $ids = array_slice(array_map('intval', $ids), 0, 2000);
            $in  = implode(',', $ids);
            $like = $q !== '' ? $wpdb->prepare(' AND (ref_article_ispag LIKE %s OR TitreArticle LIKE %s)', '%' . $wpdb->esc_like($q) . '%', '%' . $wpdb->esc_like($q) . '%') : '';
            foreach ((array) $wpdb->get_results("SELECT Id, ref_article_ispag, TitreArticle FROM {$wpdb->prefix}achats_articles WHERE Id IN ({$in}){$like} ORDER BY TitreArticle ASC LIMIT 150") as $a) {
                $by = []; $total = 0.0;
                foreach ($overview[(int) $a->Id] as $loc => $qty) { $by[(int) $loc] = round((float) $qty, 2); $total += (float) $qty; }
                $rows[] = ['id' => (int) $a->Id, 'ref' => self::clean($a->ref_article_ispag), 'title' => self::clean($a->TitreArticle, 140), 'total' => round($total, 2), 'by' => (object) $by];
            }
        }
        return new WP_REST_Response(['locations' => $locations, 'rows' => $rows], 200);
    }

    // ------------------------------------------------------------------ commandes fournisseurs

    public static function rest_purchases(WP_REST_Request $request) {
        global $wpdb;
        $me = get_current_user_id();
        if (!self::caps($me)['purchases']) return self::forbidden();
        $p = $wpdb->prefix;
        $q = trim(sanitize_text_field((string) $request->get_param('q')));
        $where = '1=1';
        if ($q !== '') {
            $like = '%' . $wpdb->esc_like($q) . '%';
            $where = $wpdb->prepare('(o.RefCommande LIKE %s OR o.NrCommande LIKE %s OR c.company_name LIKE %s)', $like, $like, $like);
        }
        $rows = (array) $wpdb->get_results(
            "SELECT o.Id, o.RefCommande, o.NrCommande, o.TimestampDateCreation, o.TimestampDateReception, o.TimestampDateReceptionConfirmee, o.Total, o.EtatCommande,
                    c.company_name, e.Etat AS etat
             FROM {$p}achats_commande_liste_fournisseurs o
             LEFT JOIN {$p}ispag_companies c ON c.Id = o.IdFournisseur
             LEFT JOIN {$p}achats_etat_commandes_fournisseur e ON e.Id = o.EtatCommande
             WHERE {$where} ORDER BY o.Id DESC LIMIT 80"
        );
        return new WP_REST_Response(['results' => array_map([self::class, 'purchase_row'], $rows)], 200);
    }

    private static function purchase_row($r): array {
        $due = (int) $r->TimestampDateReceptionConfirmee ?: (int) $r->TimestampDateReception;
        return [
            'id'       => (int) $r->Id,
            'ref'      => self::clean($r->RefCommande ?: $r->NrCommande),
            'number'   => self::clean($r->NrCommande),
            'supplier' => self::clean($r->company_name),
            'state'    => self::clean($r->etat),
            'created'  => $r->TimestampDateCreation ? wp_date('Y-m-d', (int) $r->TimestampDateCreation) : '',
            'due'      => $due ? wp_date('Y-m-d', $due) : '',
            'confirmed' => (int) $r->TimestampDateReceptionConfirmee > 0,
            'total'    => (float) $r->Total,
        ];
    }

    public static function rest_purchase(WP_REST_Request $request) {
        global $wpdb;
        $me = get_current_user_id();
        if (!self::caps($me)['purchases']) return self::forbidden();
        $p  = $wpdb->prefix;
        $id = (int) $request->get_param('id');
        $r  = $wpdb->get_row($wpdb->prepare(
            "SELECT o.Id, o.RefCommande, o.NrCommande, o.TimestampDateCreation, o.TimestampDateReception, o.TimestampDateReceptionConfirmee, o.Total, o.EtatCommande, o.Remarque,
                    o.delivery_number, o.invoice_number, c.company_name, e.Etat AS etat
             FROM {$p}achats_commande_liste_fournisseurs o
             LEFT JOIN {$p}ispag_companies c ON c.Id = o.IdFournisseur
             LEFT JOIN {$p}achats_etat_commandes_fournisseur e ON e.Id = o.EtatCommande
             WHERE o.Id = %d", $id
        ));
        if (!$r) return new WP_REST_Response(['message' => 'not found'], 404);
        $lines = [];
        foreach ((array) $wpdb->get_results($wpdb->prepare(
            "SELECT l.Id, l.RefSurMesure, l.DescSurMesure, l.Qty, l.Recu, l.UnitPrice, l.discount, l.TimestampDateLivraison, l.TimestampDateLivraisonConfirme, a.ref_article_ispag, a.TitreArticle
             FROM {$p}achats_articles_cmd_fournisseurs l LEFT JOIN {$p}achats_articles a ON a.Id = l.IdArticleStandard
             WHERE l.IdCommande = %d AND (l.archive IS NULL OR l.archive = 0) ORDER BY l.tri ASC, l.Id ASC LIMIT 200", $id
        )) as $l) {
            $due = (int) $l->TimestampDateLivraisonConfirme ?: (int) $l->TimestampDateLivraison;
            $lines[] = [
                'title' => self::clean($l->TitreArticle ?: $l->RefSurMesure, 160), 'ref' => self::clean($l->ref_article_ispag), 'desc' => self::clean($l->DescSurMesure, 240),
                'qty' => (int) $l->Qty, 'received' => (int) $l->Recu, 'unit' => round((float) $l->UnitPrice * (1 - (float) $l->discount / 100), 2),
                'due' => $due ? wp_date('Y-m-d', $due) : '', 'confirmed' => (int) $l->TimestampDateLivraisonConfirme > 0,
            ];
        }
        return new WP_REST_Response(['order' => self::purchase_row($r) + ['remark' => self::clean($r->Remarque, 600), 'delivery_number' => (string) $r->delivery_number, 'invoice_number' => (string) $r->invoice_number], 'lines' => $lines], 200);
    }
}
