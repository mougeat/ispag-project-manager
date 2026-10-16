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
        ], 200);
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
