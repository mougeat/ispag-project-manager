<?php
defined('ABSPATH') || exit;

/**
 * Application mobile (PWA) : consultation des projets et saisie des livraisons avec signature, y compris hors ligne.
 *
 *  - L'application est servie sous /ispag-app/ (page, script, feuille de style, service worker, manifeste) : aucune
 *    règle de réécriture à régénérer, la requête est interceptée très tôt (parse_request).
 *  - Elle parle à une petite API REST (ispag/v1/mobile/…) avec un jeton propre à l'appareil (en-tête X-ISPAG-Token) :
 *    connexion une seule fois, jeton révocable (table ispag_mobile_tokens, valable 90 jours, renouvelé à l'usage).
 *  - /snapshot : instantané des projets actifs de l'utilisateur (sans aucun prix) à stocker sur le téléphone.
 *  - /deliveries : reçoit une livraison saisie hors ligne (nom + signature + articles). Chaque livraison porte un
 *    identifiant client unique : la renvoyer deux fois ne crée jamais deux bulletins.
 *    Le serveur produit le PDF signé, marque les articles livrés et prévient le chef de projet (ISPAG_Delivery_Receipt::complete).
 */
class ISPAG_Mobile_App {

    const NS          = 'ispag/v1/mobile';
    const SLUG        = 'ispag-app';
    const TOKEN_DAYS  = 90;
    const MAX_SIGNATURE = 700000; // octets du PNG (après décodage)
    const MAX_PROJECTS  = 300;
    const MAX_DOCS      = 60;   // documents par projet
    const DOC_SLUGS     = ['sketch', 'product_drawing', 'drawingApproval', 'drawingModification', 'design_detail', 'documentation', 'certificat_conformity', 'delivery_note', 'picture', 'note', 'request_supplier_quotation'];

    /** Fichiers servis tels quels : nom => type MIME */
    const FILES = [
        'app.js'        => 'application/javascript; charset=utf-8',
        'app.css'       => 'text/css; charset=utf-8',
        'icon-192.png'  => 'image/png',
        'icon-512.png'  => 'image/png',
    ];

    public static function init() {
        add_action('rest_api_init', [self::class, 'register_routes']);
        add_action('parse_request', [self::class, 'maybe_serve_app'], 1);
    }

    private static function tokens_table(): string { global $wpdb; return $wpdb->prefix . 'ispag_mobile_tokens'; }
    private static function deliveries_table(): string { global $wpdb; return $wpdb->prefix . 'ispag_mobile_deliveries'; }

    // ------------------------------------------------------------------ routes REST

    public static function register_routes() {
        register_rest_route(self::NS, '/login', [
            'methods' => 'POST', 'callback' => [self::class, 'rest_login'], 'permission_callback' => '__return_true',
        ]);
        register_rest_route(self::NS, '/logout', [
            'methods' => 'POST', 'callback' => [self::class, 'rest_logout'], 'permission_callback' => [self::class, 'authenticate'],
        ]);
        register_rest_route(self::NS, '/snapshot', [
            'methods' => 'GET', 'callback' => [self::class, 'rest_snapshot'], 'permission_callback' => [self::class, 'authenticate'],
        ]);
        register_rest_route(self::NS, '/push', [
            'methods' => 'GET', 'callback' => [self::class, 'rest_push_info'], 'permission_callback' => [self::class, 'authenticate'],
        ]);
        register_rest_route(self::NS, '/push/subscribe', [
            'methods' => 'POST', 'callback' => [self::class, 'rest_push_subscribe'], 'permission_callback' => [self::class, 'authenticate'],
        ]);
        register_rest_route(self::NS, '/push/unsubscribe', [
            'methods' => 'POST', 'callback' => [self::class, 'rest_push_unsubscribe'], 'permission_callback' => [self::class, 'authenticate'],
        ]);
        register_rest_route(self::NS, '/push/test', [
            'methods' => 'POST', 'callback' => [self::class, 'rest_push_test'], 'permission_callback' => [self::class, 'authenticate'],
        ]);
        register_rest_route(self::NS, '/deliveries', [
            'methods' => 'POST', 'callback' => [self::class, 'rest_delivery'], 'permission_callback' => [self::class, 'authenticate'],
        ]);
    }

    /** Authentifie la requête par son jeton, puis impose l'utilisateur courant (droit « manage_order » requis). */
    public static function authenticate(WP_REST_Request $request) {
        global $wpdb;
        $token = trim((string) ($request->get_header('X-ISPAG-Token') ?: ''));
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            return new WP_Error('ispag_no_token', 'Authentication required', ['status' => 401]);
        }
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM " . self::tokens_table() . " WHERE token_hash = %s AND expires_at > %s",
            hash('sha256', $token), current_time('mysql')
        ));
        if (!$row) {
            return new WP_Error('ispag_bad_token', 'Session expired', ['status' => 401]);
        }
        $user = get_user_by('id', (int) $row->user_id);
        if (!$user || !self::can_use_app($user)) {
            return new WP_Error('ispag_forbidden', 'Forbidden', ['status' => 403]);
        }
        wp_set_current_user($user->ID);
        // Renouvellement glissant (au plus une écriture par heure)
        if (!$row->last_used_at || strtotime($row->last_used_at) < time() - HOUR_IN_SECONDS) {
            $wpdb->update(self::tokens_table(), [
                'last_used_at' => current_time('mysql'),
                'expires_at'   => gmdate('Y-m-d H:i:s', time() + self::TOKEN_DAYS * DAY_IN_SECONDS),
            ], ['id' => $row->id]);
        }
        $request->set_param('_ispag_token_id', (int) $row->id);
        return true;
    }

    public static function rest_login(WP_REST_Request $request) {
        global $wpdb;
        $login    = sanitize_text_field((string) $request->get_param('username'));
        $password = (string) $request->get_param('password');
        $device   = mb_substr(sanitize_text_field((string) $request->get_param('device')), 0, 120);
        if ($login === '' || $password === '') {
            return new WP_REST_Response(['message' => 'missing'], 400);
        }
        $user = wp_authenticate($login, $password); // déclenche les hooks habituels (limitation des tentatives, 2FA…)
        if (is_wp_error($user)) {
            return new WP_REST_Response(['message' => 'invalid'], 401);
        }
        if (!self::can_use_app($user)) {
            return new WP_REST_Response(['message' => 'forbidden'], 403);
        }
        $token = bin2hex(random_bytes(32));
        $wpdb->insert(self::tokens_table(), [
            'user_id'    => $user->ID,
            'token_hash' => hash('sha256', $token),
            'device'     => $device,
            'created_at' => current_time('mysql'),
            'expires_at' => gmdate('Y-m-d H:i:s', time() + self::TOKEN_DAYS * DAY_IN_SECONDS),
        ]);
        return new WP_REST_Response(['token' => $token, 'user' => self::user_info($user)], 200);
    }

    public static function rest_logout(WP_REST_Request $request) {
        global $wpdb;
        $wpdb->delete(self::tokens_table(), ['id' => (int) $request->get_param('_ispag_token_id')]);
        return new WP_REST_Response(['ok' => true], 200);
    }

    /** Projets / livraisons : « manage_order » ; contacts, tâches, notes et offres : droits CRM. Au moins l'un des deux suffit pour utiliser l'application. */
    public static function can_use_app($user): bool {
        return user_can($user, 'manage_order') || user_can($user, 'view_contact') || user_can($user, 'view_company');
    }

    private static function user_info(WP_User $user): array {
        $locale = get_user_meta($user->ID, 'locale', true) ?: get_locale();
        return ['id' => $user->ID, 'name' => $user->display_name, 'lang' => strtolower(substr((string) $locale, 0, 2))];
    }

    // ------------------------------------------------------------------ notifications push (mêmes abonnements et mêmes envois que le CRM)

    private static function push_ready(): bool {
        return class_exists('ISPAG_WebPush_Handler') && ISPAG_WebPush_Handler::is_supported() && ISPAG_WebPush_Handler::get_public_key() !== '';
    }

    public static function rest_push_info(WP_REST_Request $request) {
        global $wpdb;
        if (!self::push_ready()) return new WP_REST_Response(['supported' => false], 200);
        return new WP_REST_Response([
            'supported' => true,
            'key'       => ISPAG_WebPush_Handler::get_public_key(),
            'devices'   => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}ispag_push_subscriptions WHERE user_id = %d", get_current_user_id())),
        ], 200);
    }

    public static function rest_push_subscribe(WP_REST_Request $request) {
        global $wpdb;
        if (!self::push_ready()) return new WP_REST_Response(['message' => 'unsupported'], 400);
        $endpoint = esc_url_raw((string) $request->get_param('endpoint'));
        $p256dh   = sanitize_text_field((string) $request->get_param('p256dh'));
        $auth     = sanitize_text_field((string) $request->get_param('auth'));
        if (strpos($endpoint, 'https://') !== 0 || strlen(ISPAG_WebPush_Handler::b64url_decode($p256dh)) !== 65 || strlen(ISPAG_WebPush_Handler::b64url_decode($auth)) < 16) {
            return new WP_REST_Response(['message' => 'invalid'], 400);
        }
        $now = current_time('mysql');
        $wpdb->query($wpdb->prepare(
            "INSERT INTO {$wpdb->prefix}ispag_push_subscriptions (user_id, endpoint, endpoint_hash, p256dh, auth, user_agent, created_at, last_used_at)
             VALUES (%d, %s, %s, %s, %s, %s, %s, %s)
             ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), endpoint = VALUES(endpoint), p256dh = VALUES(p256dh), auth = VALUES(auth), user_agent = VALUES(user_agent)",
            get_current_user_id(), $endpoint, hash('sha256', $endpoint), $p256dh, $auth, 'ISPAG app · ' . mb_substr(sanitize_text_field((string) $request->get_header('User-Agent')), 0, 200), $now, $now
        ));
        return new WP_REST_Response(['ok' => true], 200);
    }

    public static function rest_push_unsubscribe(WP_REST_Request $request) {
        global $wpdb;
        $endpoint = esc_url_raw((string) $request->get_param('endpoint'));
        if ($endpoint) $wpdb->delete($wpdb->prefix . 'ispag_push_subscriptions', ['endpoint_hash' => hash('sha256', $endpoint), 'user_id' => get_current_user_id()], ['%s', '%d']);
        return new WP_REST_Response(['ok' => true], 200);
    }

    /** Notification de test envoyée à tous les appareils de l'utilisateur (vérifie l'abonnement de bout en bout). */
    public static function rest_push_test(WP_REST_Request $request) {
        if (!self::push_ready()) return new WP_REST_Response(['message' => 'unsupported'], 400);
        $n = ISPAG_WebPush_Handler::send_push_notification(get_current_user_id(), 'ISPAG', __('Notifications are working ✓', 'creation-reservoir'), home_url('/' . self::SLUG . '/'));
        return new WP_REST_Response(['ok' => true, 'sent' => (int) $n], 200);
    }

    // ------------------------------------------------------------------ accès aux projets

    /** Projets actifs que l'utilisateur peut voir : les siens (chef de projet, créateur, ingénieur) ou tous avec « real_all_orders ». */
    private static function visible_projects_sql(int $user_id, bool $with_offers = false): string {
        global $wpdb;
        // Projets en commande ; avec $with_offers, aussi les offres (isQotation) des 3 derniers mois
        $kind  = $with_offers
            ? $wpdb->prepare("((p.isQotation IS NULL OR p.isQotation = 0) OR (p.isQotation = 1 AND p.date_creation >= %s))", wp_date('Y-m-d', strtotime('-3 months')))
            : "(p.isQotation IS NULL OR p.isQotation = 0)";
        $where = $kind . " AND p.project_status = 1";
        if (!user_can($user_id, 'real_all_orders')) {
            $where .= $wpdb->prepare(" AND (p.project_manager = %d OR p.created_by = %d OR p.ingenieur_id = %d)", $user_id, $user_id, $user_id);
        }
        return $where;
    }

    private static function can_access_deal(int $user_id, int $deal_id): bool {
        global $wpdb;
        if (!$deal_id) return false;
        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT p.id FROM {$wpdb->prefix}achats_liste_commande p WHERE p.hubspot_deal_id = %d AND " . self::visible_projects_sql($user_id) . " LIMIT 1",
            $deal_id
        ));
    }

    private static function clean_text($text): string {
        $text = html_entity_decode(wp_strip_all_tags(stripslashes((string) $text)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim(preg_replace('/\s+/', ' ', $text));
    }

    // ------------------------------------------------------------------ instantané (lecture hors ligne)

    public static function rest_snapshot(WP_REST_Request $request) {
        global $wpdb;
        $user_id = get_current_user_id();
        $p = $wpdb->prefix;

        $rows = !user_can($user_id, 'manage_order') ? [] : $wpdb->get_results("
            SELECT p.hubspot_deal_id AS deal_id, p.NumCommande AS number, p.ObjetCommande AS title, p.customer_order_id AS customer_ref,
                   p.AssociatedCompanyID AS company_id, c.company_name AS company, p.project_manager, p.TimestampDateCommande AS ordered_at,
                   p.AssociatedContactIDs AS contact_ids, p.isQotation AS is_offer, p.date_creation AS created
            FROM {$p}achats_liste_commande p
            LEFT JOIN {$p}ispag_companies c ON c.Id = p.AssociatedCompanyID
            WHERE " . self::visible_projects_sql($user_id, true) . "
            ORDER BY p.isQotation ASC, p.TimestampDateCommande DESC, p.date_creation DESC
            LIMIT " . (int) self::MAX_PROJECTS
        );

        $deal_ids = array_map(function ($r) { return (int) $r->deal_id; }, $rows);
        $articles = $infos = $receipts = $docs = $tanks = [];
        if ($deal_ids) {
            $in = implode(',', $deal_ids);

            foreach ($wpdb->get_results("
                SELECT Id, hubspot_deal_id, serial_no, Article, Description, Qty, Livre, Groupe, IdArticleMaster, TimestampDateDeLivraison, TimestampDateDeLivraisonFin, Type, DrawingApproved
                FROM {$p}achats_details_commande
                WHERE hubspot_deal_id IN ($in) AND archive = 0
                ORDER BY hubspot_deal_id, Groupe, tri, Id") as $a) {
                $articles[(int) $a->hubspot_deal_id][] = [
                    'id'      => (int) $a->Id,
                    'ref'     => (string) $a->serial_no,
                    'name'    => self::clean_text($a->Article),
                    'desc'    => mb_substr(self::clean_text($a->Description), 0, 700),
                    'group'   => (string) $a->Groupe,
                    'type'    => (int) $a->Type,
                    'plan_ok' => (int) $a->DrawingApproved === 1,
                    'eta_end' => $a->TimestampDateDeLivraisonFin ? (int) $a->TimestampDateDeLivraisonFin : 0,
                    'qty'     => (float) $a->Qty,
                    'done'    => (int) $a->Livre > 0,
                    'master'  => (int) $a->IdArticleMaster,
                    'eta'     => $a->TimestampDateDeLivraison ? (int) $a->TimestampDateDeLivraison : 0,
                ];
            }

            foreach ($wpdb->get_results("SELECT * FROM {$p}achats_info_commande WHERE hubspot_deal_id IN ($in) AND purchase_order = 0") as $i) {
                $infos[(int) $i->hubspot_deal_id] = [
                    'address'  => self::clean_text($i->AdresseDeLivraison),
                    'address2' => self::clean_text($i->DeliveryAdresse2),
                    'address3' => self::clean_text($i->DeliveryAdresse3),
                    'zip'      => self::clean_text($i->NIP),
                    'city'     => self::clean_text($i->City),
                    'contact'  => self::clean_text($i->PersonneContact),
                    'phone'    => self::clean_text($i->num_tel_contact),
                    'site'     => array_filter([
                        'comment' => self::clean_text($i->Comment), 'unloading' => self::clean_text($i->unloadingFacilities), 'corridor' => self::clean_text($i->corridor_width),
                        'door' => self::clean_text($i->door_width), 'doors' => self::clean_text($i->number_doors), 'obstacles' => self::clean_text($i->other_obstacles),
                        'room' => self::clean_text($i->room_size), 'room_h' => self::clean_text($i->room_height), 'ceiling' => self::clean_text($i->ceiling_type),
                        'hoist' => self::clean_text($i->hoist_allowed), 'floor' => self::clean_text($i->floor_covering), 'vent' => self::clean_text($i->ventilation),
                        'elec' => self::clean_text($i->electricity_available), 'parking' => self::clean_text($i->parking_address), 'notes' => self::clean_text($i->observations),
                    ], function ($v) { return $v !== '' && $v !== '0'; }),
                ];
            }

            // Documents du projet : plans, croquis, validations, photos, notices… — jamais les pièces chiffrées (offre, commande, confirmation, factures, tableur de calcul)
            $slugs = implode(',', array_map(function ($x) { return "'" . esc_sql($x) . "'"; }, (array) apply_filters('ispag_mobile_doc_slugs', self::DOC_SLUGS)));
            foreach ($wpdb->get_results("
                SELECT h.hubspot_deal_id, h.IdMedia, h.Date, h.Historique, dt.label
                FROM {$p}achats_historique h
                INNER JOIN {$p}achats_doc_types dt ON dt.slug COLLATE utf8mb4_unicode_ci = h.ClassCss COLLATE utf8mb4_unicode_ci
                WHERE h.hubspot_deal_id IN ($in) AND h.purchase_order = 0 AND h.IdMedia > 0 AND dt.slug IN ($slugs)
                ORDER BY h.Date DESC") as $d) {
                $deal = (int) $d->hubspot_deal_id;
                if (count($docs[$deal] ?? []) >= self::MAX_DOCS) continue;
                $url = wp_get_attachment_url((int) $d->IdMedia);
                if (!$url) continue;
                $file = get_attached_file((int) $d->IdMedia);
                $docs[$deal][] = [
                    'id'      => (int) $d->IdMedia,
                    'title'   => self::clean_text(get_the_title((int) $d->IdMedia)),
                    'type'    => self::clean_text($d->label),
                    'at'      => (int) $d->Date,
                    'mime'    => (string) (get_post_mime_type((int) $d->IdMedia) ?: ''),
                    'size'    => ($file && file_exists($file)) ? (int) filesize($file) : 0,
                    'url'     => (string) $url,
                    'article' => is_numeric($d->Historique) ? (int) $d->Historique : 0,
                ];
            }

            // Réservoirs (articles de type 1) : fiche technique et piquages, en lecture
            $tanks = self::tank_details($deal_ids);

            foreach ($wpdb->get_results("
                SELECT hubspot_deal_id, receiver_name, signed_at, signed_media_id
                FROM {$p}achats_delivery_receipts
                WHERE hubspot_deal_id IN ($in) AND purchase_order = 0 AND signed_at IS NOT NULL
                ORDER BY signed_at DESC") as $r) {
                $receipts[(int) $r->hubspot_deal_id][] = [
                    'by'   => (string) $r->receiver_name,
                    'at'   => mysql2date('Y-m-d H:i', $r->signed_at),
                    'pdf'  => $r->signed_media_id ? (string) wp_get_attachment_url((int) $r->signed_media_id) : '',
                ];
            }
        }

        $projects = [];
        foreach ($rows as $r) {
            $id = (int) $r->deal_id;
            $projects[] = [
                'deal_id'      => $id,
                'number'       => (string) $r->number,
                'title'        => self::clean_text($r->title),
                'customer_ref' => (string) $r->customer_ref,
                'company'      => (string) $r->company,
                'manager'      => ($m = get_userdata((int) $r->project_manager)) ? $m->display_name : '',
                'ordered_at'   => $r->ordered_at ? (int) $r->ordered_at : 0,
                'contacts'     => self::project_contacts((string) $r->contact_ids),
                'documents'    => $docs[$id] ?? [],
                'delivery'     => $infos[$id] ?? ['address' => '', 'address2' => '', 'address3' => '', 'zip' => '', 'city' => '', 'contact' => '', 'phone' => '', 'site' => []],
                'is_offer'     => (int) $r->is_offer === 1,
                'created'      => (string) $r->created,
                'articles'     => array_map(function ($a) use ($tanks) { if (isset($tanks[$a['id']])) $a['tank'] = $tanks[$a['id']]; return $a; }, $articles[$id] ?? []),
                'receipts'     => $receipts[$id] ?? [],
            ];
        }

        return new WP_REST_Response([
            'generated_at' => gmdate('c'),
            'user'         => self::user_info(wp_get_current_user()),
            'caps'         => ['projects' => user_can($user_id, 'manage_order'), 'crm' => class_exists('ISPAG_Mobile_Crm') && ISPAG_Mobile_Crm::available($user_id)],
            'projects'     => $projects,
        ], 200);
    }

    /** Fiches techniques des réservoirs des projets : [id article => données lisibles]. Aucun prix. */
    private static function tank_details(array $deal_ids): array {
        global $wpdb;
        $p = $wpdb->prefix;
        if (!$deal_ids || !(bool) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $p . 'achats_tank_dimensions'))) return [];
        $in = implode(',', array_map('intval', $deal_ids));

        $label = [];
        foreach ($wpdb->get_results("SELECT Id, Value FROM {$p}achats_tank_conception") as $c) $label[(int) $c->Id] = __((string) $c->Value, 'creation-reservoir');
        $lab = function ($id) use ($label) { $id = (int) $id; return $id && isset($label[$id]) ? $label[$id] : ''; };

        $dims = (array) $wpdb->get_results("SELECT * FROM {$p}achats_tank_dimensions WHERE hubspot_deal_id IN ($in)");
        $dim_ids = array_map(function ($d) { return (int) $d->Id; }, $dims);
        $conns = [];
        if ($dim_ids) {
            $din = implode(',', $dim_ids);
            foreach ($wpdb->get_results("
                SELECT c.*, f.DN AS dn FROM {$p}achats_tank_connection c LEFT JOIN {$p}achats_flange_dimensions f ON f.Id = c.Pouces
                WHERE c.TankId IN ($din) ORDER BY c.Id") as $c) {
                $conns[(int) $c->TankId][] = [
                    'type'     => $lab($c->Type) ?: self::clean_text($c->Type),
                    'dn'       => (string) ($c->dn ?? ''),
                    'height'   => (string) $c->Height,
                    'ok'       => (int) $c->heightApproved === 1,
                    'angle'    => (string) $c->Angle,
                    'acc'      => mb_substr(self::clean_text($c->Accessories), 0, 120),
                ];
            }
        }
        $out = [];
        foreach ($dims as $d) {
            $out[(int) $d->customerTankId] = array_filter([
                'type' => $lab($d->TankType), 'material' => $lab($d->Material), 'support' => $lab($d->Support),
                'volume' => (string) $d->Volume, 'diameter' => (string) $d->Diameter, 'height' => (string) $d->Height,
                'feet' => (string) $d->FeetHeight, 'clearance' => (string) $d->GroundClearance, 'bottom' => (string) $d->BottomHeight,
                'pressure' => (string) $d->MaxPressure, 'test' => (string) $d->TestPressure, 'temp' => (string) $d->usingTemperature,
                'ins' => $lab($d->insulation), 'ins_thick' => $lab($d->InsulationThickness), 'ins_cover' => $lab($d->insulationCover),
                'welding_client' => (int) $d->weldingByClient === 1 ? '1' : '',
                'comment' => self::clean_text($d->openComment),
                'conns' => $conns[(int) $d->Id] ?? [],
            ], function ($v) { return $v !== '' && $v !== '0' && $v !== [] && $v !== null; });
        }
        return $out;
    }

    /** Contacts d'un projet : nom, téléphone, e-mail. */
    private static function project_contacts(string $csv): array {
        $out = [];
        foreach (array_slice(array_filter(array_map('intval', preg_split('/[\s,;]+/', $csv, -1, PREG_SPLIT_NO_EMPTY))), 0, 8) as $id) {
            $u = get_userdata($id);
            if (!$u) continue;
            $out[] = ['id' => $id, 'name' => $u->display_name, 'email' => $u->user_email, 'phone' => self::clean_text(get_user_meta($id, 'billing_phone', true))];
        }
        return $out;
    }

    // ------------------------------------------------------------------ livraison saisie (éventuellement hors ligne)

    public static function rest_delivery(WP_REST_Request $request) {
        global $wpdb;
        $user_id   = get_current_user_id();
        $client_id = sanitize_key((string) $request->get_param('client_id'));
        $deal_id   = (int) $request->get_param('deal_id');
        $name      = trim(sanitize_text_field((string) $request->get_param('receiver_name')));
        $data      = (string) $request->get_param('signature');
        $ids       = array_values(array_unique(array_filter(array_map('intval', (array) $request->get_param('article_ids')))));

        if (strlen($client_id) < 8 || strlen($client_id) > 64 || !$ids || $name === '' || mb_strlen($name) > 150) {
            return new WP_REST_Response(['message' => 'invalid'], 400);
        }
        if (!user_can($user_id, 'manage_order') || !self::can_access_deal($user_id, $deal_id)) {
            return new WP_REST_Response(['message' => 'forbidden'], 403);
        }
        if (!preg_match('#^data:image/png;base64,([A-Za-z0-9+/=]+)$#', $data, $m)) {
            return new WP_REST_Response(['message' => 'signature'], 400);
        }
        $png  = base64_decode($m[1], true);
        $info = $png ? @getimagesizefromstring($png) : false;
        if (!$png || strlen($png) > self::MAX_SIGNATURE || !$info || $info[2] !== IMAGETYPE_PNG) {
            return new WP_REST_Response(['message' => 'signature'], 400);
        }

        // Réservation de l'identifiant : une livraison n'est jamais traitée deux fois (renvoi après coupure réseau)
        $reserved = $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO " . self::deliveries_table() . " (client_id, user_id, receipt_id, created_at) VALUES (%s, %d, 0, %s)",
            $client_id, $user_id, current_time('mysql')
        ));
        if (!$reserved) {
            $existing = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::deliveries_table() . " WHERE client_id = %s", $client_id));
            if ($existing && (int) $existing->receipt_id > 0) {
                return new WP_REST_Response(['ok' => true, 'duplicate' => true, 'receipt_id' => (int) $existing->receipt_id], 200);
            }
            return new WP_REST_Response(['message' => 'processing'], 409); // en cours de traitement : réessayer plus tard
        }
        $release = function () use ($wpdb, $client_id) {
            $wpdb->delete(self::deliveries_table(), ['client_id' => $client_id, 'receipt_id' => 0]);
        };

        // Les articles doivent appartenir au projet
        $in    = implode(',', $ids);
        $valid = $wpdb->get_results($wpdb->prepare(
            "SELECT Id, serial_no, Article, Qty FROM {$wpdb->prefix}achats_details_commande WHERE hubspot_deal_id = %d AND archive = 0 AND Id IN ($in)", $deal_id
        ));
        if (!$valid) {
            $release();
            return new WP_REST_Response(['message' => 'articles'], 400);
        }

        // Date de la signature = moment où elle a été saisie sur le téléphone (bornée : pas dans le futur, pas plus de 60 jours en arrière)
        $ts = strtotime((string) $request->get_param('signed_at'));
        if (!$ts || $ts > time() + HOUR_IN_SECONDS || $ts < time() - 60 * DAY_IN_SECONDS) $ts = time();
        $signed_at = wp_date('Y-m-d H:i:s', $ts);
        $note_date = wp_date('d.m.Y', $ts);

        $project = apply_filters('ispag_get_project_by_deal_id', null, $deal_id);
        $details = new ISPAG_Project_Details_Repository();
        $infos   = (array) $details->get_infos_livraison($deal_id);
        $over    = (array) $request->get_param('delivery');
        foreach (['AdresseDeLivraison', 'DeliveryAdresse2', 'DeliveryAdresse3', 'NIP', 'City', 'PersonneContact', 'num_tel_contact'] as $k) {
            if (isset($over[$k])) $infos[$k] = sanitize_text_field((string) $over[$k]);
        }

        $articles = [];
        foreach ($valid as $a) {
            $articles[] = ['ref' => $a->serial_no, 'description' => $a->Article, 'qty' => $a->Qty];
        }
        $payload = [
            'title'          => __('Delivery note', 'creation-reservoir'),
            'company'        => $project->nom_entreprise ?? '',
            'project_header' => [
                __('Project', 'creation-reservoir')         => $project->ObjetCommande ?? '',
                __('Project number', 'creation-reservoir')  => $project->NumCommande ?? '',
                __('Delivery date', 'creation-reservoir')   => $note_date,
            ],
            'infos'          => array_intersect_key($infos, array_flip(['AdresseDeLivraison', 'DeliveryAdresse2', 'DeliveryAdresse3', 'NIP', 'City', 'PersonneContact', 'num_tel_contact'])),
            'table_header'   => [
                ['label' => __('Reference', 'creation-reservoir'), 'key' => 'ref', 'width' => 40],
                ['label' => __('Description', 'creation-reservoir'), 'key' => 'description', 'width' => 110],
                ['label' => __('Quantity', 'creation-reservoir'), 'key' => 'qty', 'width' => 30, 'align' => 'C'],
            ],
            'articles'       => $articles,
            'article_ids'    => array_map(function ($a) { return (int) $a->Id; }, $valid),
        ];

        require_once __DIR__ . '/class-ispag-pdf-generator.php';
        $url = ISPAG_Delivery_Receipt::create($payload, $deal_id, 0);
        parse_str((string) wp_parse_url($url, PHP_URL_QUERY), $q);
        $row = ISPAG_Delivery_Receipt::find((string) ($q['t'] ?? ''));
        if (!$row) {
            $release();
            return new WP_REST_Response(['message' => 'error'], 500);
        }

        $result = ISPAG_Delivery_Receipt::complete($row, $name, $png, 'app', $signed_at);
        if (is_wp_error($result)) {
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}achats_delivery_receipts WHERE id = %d AND signed_at IS NULL", $row->id));
            $release();
            return new WP_REST_Response(['message' => 'error'], 500);
        }

        $wpdb->update(self::deliveries_table(), ['receipt_id' => (int) $row->id], ['client_id' => $client_id]);
        return new WP_REST_Response([
            'ok'         => true,
            'receipt_id' => (int) $row->id,
            'delivered'  => (int) $result['delivered'],
            'pdf'        => (string) wp_get_attachment_url((int) $result['media_id']),
        ], 200);
    }

    // ------------------------------------------------------------------ service de l'application (/ispag-app/…)

    public static function maybe_serve_app() {
        $path = (string) wp_parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
        $base = trailingslashit((string) wp_parse_url(home_url('/'), PHP_URL_PATH)) . self::SLUG;
        if ($path !== $base && strpos($path, $base . '/') !== 0) return;

        $file = ltrim(substr($path, strlen($base)), '/');
        $dir  = dirname(__DIR__) . '/assets/mobile/';

        nocache_headers();
        if ($file === '' || $file === 'index.html') {
            self::serve_index();
        } elseif ($file === 'manifest.webmanifest') {
            header('Content-Type: application/manifest+json; charset=utf-8');
            $start = home_url('/' . self::SLUG . '/');
            echo wp_json_encode([
                'name' => 'ISPAG', 'short_name' => 'ISPAG', 'start_url' => $start, 'scope' => $start,
                'display' => 'standalone', 'background_color' => '#efefef', 'theme_color' => '#ffffff',
                'icons' => [
                    ['src' => $start . 'icon-192.png', 'sizes' => '192x192', 'type' => 'image/png'],
                    ['src' => $start . 'icon-512.png', 'sizes' => '512x512', 'type' => 'image/png'],
                ],
            ]);
        } elseif ($file === 'sw.js') {
            header('Content-Type: application/javascript; charset=utf-8');
            header('Service-Worker-Allowed: ' . $base); // sans « / » final : couvre /ispag-app et /ispag-app/
            header('Cache-Control: no-cache');
            echo str_replace('__VERSION__', self::version(), (string) file_get_contents($dir . 'sw.js'));
        } elseif (isset(self::FILES[$file]) && is_readable($dir . $file)) {
            header('Content-Type: ' . self::FILES[$file]);
            header('Cache-Control: no-cache'); // le service worker gère le cache ; ici toujours la dernière version
            readfile($dir . $file);
        } else {
            status_header(404);
            echo 'Not found';
        }
        exit;
    }

    private static function version(): string {
        $v = 0;
        foreach (['app.js', 'app.css', 'sw.js'] as $f) $v = max($v, (int) @filemtime(dirname(__DIR__) . '/assets/mobile/' . $f));
        return (string) $v;
    }

    private static function serve_index() {
        header('Content-Type: text/html; charset=utf-8');
        $start = home_url('/' . self::SLUG . '/');
        $cfg = [
            'api'     => untrailingslashit(rest_url(self::NS)),
            'base'    => $start,
            'version' => self::version(),
            'company' => (string) (get_option('wpcb_companyName') ?: 'ISPAG'),
            // Logo du site (Apparence → Personnaliser → Identité du site), repris dans l'en-tête de l'application
            'logo'    => (string) apply_filters('ispag_mobile_logo_url', ($lid = (int) get_theme_mod('custom_logo')) ? (string) wp_get_attachment_image_url($lid, 'full') : ''),
        ];
        ?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex,nofollow">
<meta name="theme-color" content="#ffffff">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<meta name="apple-mobile-web-app-title" content="ISPAG">
<link rel="manifest" href="<?php echo esc_url($start . 'manifest.webmanifest'); ?>">
<link rel="apple-touch-icon" href="<?php echo esc_url($start . 'icon-192.png'); ?>">
<link rel="stylesheet" href="<?php echo esc_url($start . 'app.css?v=' . self::version()); ?>">
<title>ISPAG</title>
</head>
<body>
<div id="app"></div>
<script>window.ISPAG_APP = <?php echo wp_json_encode($cfg); ?>;</script>
<script src="<?php echo esc_url($start . 'app.js?v=' . self::version()); ?>"></script>
</body>
</html>
        <?php
    }
}
