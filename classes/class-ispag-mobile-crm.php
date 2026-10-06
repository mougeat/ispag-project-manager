<?php
defined('ABSPATH') || exit;

/**
 * Application mobile : partie CRM (contacts, tâches, notes / appels, offres récentes), consultable et saisissable hors ligne.
 *
 *  - GET  /crm         : instantané à stocker sur le téléphone (mes contacts, mes tâches ouvertes, mes offres des 3 derniers mois).
 *  - POST /crm-action  : une action saisie sur le téléphone (note / appel, nouvelle tâche, tâche terminée), éventuellement hors ligne ;
 *                        chaque action porte un identifiant client unique : la renvoyer deux fois ne crée jamais de doublon
 *                        (table ispag_mobile_deliveries partagée avec les livraisons).
 * Même jeton d'appareil que le reste de l'application (ISPAG_Mobile_App::authenticate).
 */
class ISPAG_Mobile_Crm {

    const OFFER_MONTHS   = 3;
    const MAX_CONTACTS   = 600;
    const MAX_TASKS      = 300;
    const MAX_OFFERS     = 300;
    const ACTIVITIES_PER_CONTACT = 4;
    const NOTE_TYPES     = ['NOTE', 'CALL', 'MEETING', 'EMAIL'];
    // Résultats, comme dans le CRM (formulaire de note : appel / rendez-vous)
    const CALL_OUTCOMES    = ['connected', 'left_live_message', 'left_voicemail', 'no_answer', 'busy', 'wrong_number'];
    const MEETING_OUTCOMES = ['scheduled', 'completed', 'rescheduled', 'no_show', 'canceled'];

    public static function init() {
        add_action('rest_api_init', [self::class, 'register_routes']);
    }

    /** Les tables du CRM existent-elles et l'utilisateur a-t-il un droit CRM ? */
    public static function available(int $user_id): bool {
        return class_exists('ISPAG_Crm_Deal_Constants') && class_exists('ISPAG_Note_Manager')
            && (user_can($user_id, 'view_contact') || user_can($user_id, 'view_company'));
    }

    public static function register_routes() {
        if (!class_exists('ISPAG_Mobile_App')) return;
        register_rest_route(ISPAG_Mobile_App::NS, '/crm', [
            'methods' => 'GET', 'callback' => [self::class, 'rest_snapshot'], 'permission_callback' => [ISPAG_Mobile_App::class, 'authenticate'],
        ]);
        register_rest_route(ISPAG_Mobile_App::NS, '/crm-action', [
            'methods' => 'POST', 'callback' => [self::class, 'rest_action'], 'permission_callback' => [ISPAG_Mobile_App::class, 'authenticate'],
        ]);
    }

    private static function clean($text, int $max = 0): string {
        $text = html_entity_decode(wp_strip_all_tags(stripslashes((string) $text)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = trim(preg_replace('/\s+/', ' ', $text));
        return $max && mb_strlen($text) > $max ? mb_substr($text, 0, $max - 1) . '…' : $text;
    }

    private static function ids(string $csv): array {
        return array_values(array_filter(array_map('intval', preg_split('/[\s,;]+/', $csv, -1, PREG_SPLIT_NO_EMPTY))));
    }

    // ------------------------------------------------------------------ instantané

    public static function rest_snapshot(WP_REST_Request $request) {
        global $wpdb;
        $me = get_current_user_id();
        if (!self::available($me)) return new WP_REST_Response(['message' => 'forbidden'], 403);

        $p        = $wpdb->prefix;
        $notes    = ISPAG_Note_Manager::TABLE_NOTE;
        $deals    = ISPAG_Crm_Deal_Constants::TABLE_NAME;
        $link     = ISPAG_Crm_Deal_Constants::TABLE_DEALS_STAGES;
        $stages   = ISPAG_Crm_Deal_Constants::TABLE_DEAL_STAGES;
        $prices   = user_can($me, 'display_sales_prices');
        $since    = wp_date('Y-m-d', strtotime('-' . self::OFFER_MONTHS . ' months'));

        // --- mes tâches ouvertes
        $task_rows = (array) $wpdb->get_results($wpdb->prepare(
            "SELECT id, contact_id, company_id, deal_id, title, content, due_date, priority FROM {$notes}
             WHERE is_task = 1 AND is_completed = 0 AND user_id = %d ORDER BY due_date ASC LIMIT %d", $me, self::MAX_TASKS
        ));

        // --- mes offres des 3 derniers mois (une ligne par offre)
        $offer_rows = (array) $wpdb->get_results($wpdb->prepare(
            "SELECT d.deal_group_ref, d.offer_num, d.project_name, d.associated_company_id, d.associated_contact_ids, d.date_creation,
                    d.closing_date, d.total_excl_vat, d.project_db_status, l.current_stage_key, s.stage_label
             FROM {$deals} d
             LEFT JOIN {$link} l ON l.deal_group_ref COLLATE utf8mb4_unicode_ci = (COALESCE(NULLIF(d.deal_group_ref, ''), SUBSTRING_INDEX(d.offer_num, '.', 1)) COLLATE utf8mb4_unicode_ci)
             LEFT JOIN {$stages} s ON s.stage_key COLLATE utf8mb4_unicode_ci = (l.current_stage_key COLLATE utf8mb4_unicode_ci)
             WHERE d.deal_owner = %d AND d.date_creation >= %s ORDER BY d.date_creation DESC, d.id DESC LIMIT %d", $me, $since, self::MAX_OFFERS * 2
        ));

        // --- contacts : ceux de mes offres et de mes tâches d'abord, puis ceux dont je suis le responsable
        $contact_ids = [];
        foreach ($offer_rows as $o) foreach (self::ids((string) $o->associated_contact_ids) as $id) $contact_ids[$id] = true;
        foreach ($task_rows as $t) foreach (self::ids((string) $t->contact_id) as $id) $contact_ids[$id] = true;
        $owned = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT contact_id FROM {$p}ispag_contacts_owners WHERE user_id = %d AND status = 'active' LIMIT %d", $me, self::MAX_CONTACTS
        ));
        foreach ($owned as $id) $contact_ids[(int) $id] = true;
        $contact_ids = array_slice(array_keys($contact_ids), 0, self::MAX_CONTACTS);

        $contacts = $companies = [];
        if ($contact_ids) {
            update_meta_cache('user', $contact_ids);
            $users = get_users(['include' => $contact_ids, 'fields' => ['ID', 'display_name', 'user_email'], 'number' => count($contact_ids)]);
            $company_ids = [];
            foreach ($users as $u) {
                $cid = (int) get_user_meta($u->ID, ISPAG_Crm_Contact_Constants::META_COMPANY_ID, true);
                if ($cid) $company_ids[$cid] = true;
            }
            if ($company_ids) {
                $in = implode(',', array_map('intval', array_keys($company_ids)));
                foreach ($wpdb->get_results("SELECT Id, company_name FROM {$p}ispag_companies WHERE Id IN ($in)") as $c) $companies[(int) $c->Id] = self::clean($c->company_name);
            }
            // dernières activités (hors tâches et événements système), 6 mois : une seule requête, triée ensuite par contact
            $acts = [];
            $wanted = array_flip($contact_ids);
            foreach ((array) $wpdb->get_results($wpdb->prepare(
                "SELECT id, contact_id, type, title, content, outcome, created_at FROM {$notes}
                 WHERE is_task = 0 AND type IN ('NOTE','CALL','MEETING','EMAIL','LINKEDIN','WHATSAPP','SMS') AND created_at >= %s
                 ORDER BY created_at DESC LIMIT 6000", wp_date('Y-m-d', strtotime('-6 months'))
            )) as $a) {
                foreach (self::ids((string) $a->contact_id) as $cid) {
                    if (!isset($wanted[$cid]) || count($acts[$cid] ?? []) >= self::ACTIVITIES_PER_CONTACT) continue;
                    $acts[$cid][] = ['t' => (string) $a->type, 'o' => (string) $a->outcome, 'at' => mysql2date('Y-m-d H:i', $a->created_at), 'x' => self::clean($a->title ? $a->title . ' — ' . $a->content : $a->content, 280)];
                }
            }
            foreach ($users as $u) {
                $cid = (int) get_user_meta($u->ID, ISPAG_Crm_Contact_Constants::META_COMPANY_ID, true);
                $contacts[] = [
                    'id'       => (int) $u->ID,
                    'name'     => self::clean($u->display_name),
                    'email'    => (string) $u->user_email,
                    'phone'    => self::clean(get_user_meta($u->ID, ISPAG_Crm_Contact_Constants::META_LEAD_PHONE, true)),
                    'function' => self::clean(get_user_meta($u->ID, ISPAG_Crm_Contact_Constants::META_LEAD_FUNCTION, true)),
                    'company'  => $companies[$cid] ?? '',
                    'acts'     => $acts[$u->ID] ?? [],
                ];
            }
            usort($contacts, function ($a, $b) { return strcasecmp($a['name'], $b['name']); });
        }
        $cname = [];
        foreach ($contacts as $c) $cname[$c['id']] = $c['name'];

        // --- sortie des tâches et des offres
        $tasks = [];
        foreach ($task_rows as $t) {
            $cids = self::ids((string) $t->contact_id);
            $tasks[] = [
                'id'      => (int) $t->id,
                'title'   => self::clean($t->title ?: $t->content, 160),
                'text'    => self::clean($t->content, 400),
                'due'     => $t->due_date ? mysql2date('Y-m-d H:i', $t->due_date) : '',
                'contact' => $cids ? $cids[0] : 0,
                'contact_name' => $cids ? ($cname[$cids[0]] ?? '') : '',
                'deal'    => (string) $t->deal_id,
            ];
        }

        $offers = []; $seen = [];
        foreach ($offer_rows as $o) {
            $ref = (string) ($o->deal_group_ref !== '' && $o->deal_group_ref !== null ? $o->deal_group_ref : strtok((string) $o->offer_num, '.'));
            if (isset($seen[$ref])) continue;
            $seen[$ref] = true;
            $cids = self::ids((string) $o->associated_contact_ids);
            $offers[] = [
                'ref'      => $ref,
                'name'     => self::clean($o->project_name, 160),
                'company'  => $companies[(int) $o->associated_company_id] ?? self::company_name((int) $o->associated_company_id),
                'stage'    => self::clean(function_exists('ispag_crm_db_label') && $o->stage_label ? ispag_crm_db_label($o->stage_label) : ($o->stage_label ?: $o->current_stage_key)),
                'created'  => (string) $o->date_creation,
                'closing'  => (string) $o->closing_date,
                'amount'   => $prices ? (float) $o->total_excl_vat : null,
                'contacts' => array_map(function ($id) use ($cname) { return ['id' => $id, 'name' => $cname[$id] ?? '#' . $id]; }, $cids),
                'status'   => (int) $o->project_db_status,
            ];
            if (count($offers) >= self::MAX_OFFERS) break;
        }

        return new WP_REST_Response([
            'generated_at' => gmdate('c'),
            'prices'       => (bool) $prices,
            'contacts'     => $contacts,
            'tasks'        => $tasks,
            'offers'       => $offers,
        ], 200);
    }

    private static function company_name(int $id): string {
        global $wpdb;
        if (!$id) return '';
        return self::clean($wpdb->get_var($wpdb->prepare("SELECT company_name FROM {$wpdb->prefix}ispag_companies WHERE Id = %d", $id)));
    }

    // ------------------------------------------------------------------ actions saisies sur le téléphone

    private static function bounded_time($iso): int {
        $ts = strtotime((string) $iso);
        return (!$ts || $ts > time() + HOUR_IN_SECONDS || $ts < time() - 60 * DAY_IN_SECONDS) ? time() : $ts;
    }

    public static function rest_action(WP_REST_Request $request) {
        global $wpdb;
        $me = get_current_user_id();
        if (!self::available($me)) return new WP_REST_Response(['message' => 'forbidden'], 403);

        $client_id = sanitize_key((string) $request->get_param('client_id'));
        $kind      = (string) $request->get_param('kind');
        if (strlen($client_id) < 8 || strlen($client_id) > 64 || !in_array($kind, ['note', 'task_add', 'task_done'], true)) {
            return new WP_REST_Response(['message' => 'invalid'], 400);
        }
        $notes = ISPAG_Note_Manager::TABLE_NOTE;

        // Terminer une tâche : naturellement idempotent
        if ($kind === 'task_done') {
            $id = (int) $request->get_param('task_id');
            $at = wp_date('Y-m-d H:i:s', self::bounded_time($request->get_param('at')));
            $wpdb->query($wpdb->prepare(
                "UPDATE {$notes} SET is_completed = 1, completed_at = %s, updated_at = %s WHERE id = %d AND user_id = %d AND is_task = 1",
                $at, current_time('mysql'), $id, $me
            ));
            return new WP_REST_Response(['ok' => true, 'id' => $id], 200);
        }

        // Note / appel / tâche : l'identifiant client est réservé avant l'écriture
        $table = $wpdb->prefix . 'ispag_mobile_deliveries';
        $reserved = $wpdb->query($wpdb->prepare("INSERT IGNORE INTO {$table} (client_id, user_id, receipt_id, created_at) VALUES (%s, %d, 0, %s)", $client_id, $me, current_time('mysql')));
        if (!$reserved) {
            $existing = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE client_id = %s", $client_id));
            if ($existing && (int) $existing->receipt_id > 0) return new WP_REST_Response(['ok' => true, 'duplicate' => true, 'id' => (int) $existing->receipt_id], 200);
            return new WP_REST_Response(['message' => 'processing'], 409);
        }
        $release = function () use ($wpdb, $table, $client_id) { $wpdb->delete($table, ['client_id' => $client_id, 'receipt_id' => 0]); };

        $contact_id = (int) $request->get_param('contact_id');
        $content    = trim(wp_strip_all_tags((string) $request->get_param('content')));
        $title      = trim(sanitize_text_field((string) $request->get_param('title')));
        if (mb_strlen($content) > 5000) $content = mb_substr($content, 0, 5000);
        if (mb_strlen($title) > 200) $title = mb_substr($title, 0, 200);

        $company = '';
        if ($contact_id) {
            if (!get_userdata($contact_id)) { $release(); return new WP_REST_Response(['message' => 'contact'], 400); }
            $cid = (int) get_user_meta($contact_id, ISPAG_Crm_Contact_Constants::META_COMPANY_ID, true);
            $company = $cid ? (string) $cid : '';
        }
        $created = wp_date('Y-m-d H:i:s', self::bounded_time($request->get_param('at')));

        if ($kind === 'note') {
            $type = strtoupper((string) $request->get_param('type'));
            if (!in_array($type, self::NOTE_TYPES, true) || !$contact_id || $content === '') { $release(); return new WP_REST_Response(['message' => 'invalid'], 400); }
            $row = [
                'contact_id' => (string) $contact_id, 'user_id' => $me, 'company_id' => $company, 'deal_id' => sanitize_text_field((string) $request->get_param('deal')),
                'type' => $type, 'title' => $title, 'content' => $content, 'is_task' => 0, 'created_at' => $created,
            ];
            // Appel / rendez-vous : résultat et date saisis ; un rendez-vous porte aussi son échéance (comme dans le CRM, pour l'agenda)
            if ($type === 'CALL' || $type === 'MEETING') {
                $allowed = $type === 'CALL' ? self::CALL_OUTCOMES : self::MEETING_OUTCOMES;
                $outcome = sanitize_key((string) $request->get_param('outcome'));
                $row['outcome'] = in_array($outcome, $allowed, true) ? $outcome : ($type === 'CALL' ? 'connected' : 'completed');
                $ts = self::local_ts((string) $request->get_param('when'));   // heure saisie sur le téléphone = heure du site
                $max = $type === 'MEETING' ? time() + 2 * YEAR_IN_SECONDS : time() + HOUR_IN_SECONDS;
                if ($ts && $ts <= $max && $ts >= time() - 60 * DAY_IN_SECONDS) {
                    $row['created_at'] = wp_date('Y-m-d H:i:s', $ts);
                    if ($type === 'MEETING') $row['due_date'] = $row['created_at'];
                }
            }
        } else { // task_add
            if ($title === '' && $content === '') { $release(); return new WP_REST_Response(['message' => 'invalid'], 400); }
            $due = self::bounded_due($request->get_param('due'));
            $row = [
                'contact_id' => $contact_id ? (string) $contact_id : '', 'user_id' => $me, 'company_id' => $company, 'deal_id' => sanitize_text_field((string) $request->get_param('deal')),
                'type' => 'TASK', 'title' => $title !== '' ? $title : mb_substr($content, 0, 80), 'content' => $content !== '' ? $content : $title,
                'is_task' => 1, 'is_completed' => 0, 'due_date' => $due, 'reminder_date' => $due, 'reminder_offset' => 'none', 'created_at' => $created, 'priority' => 'Normal',
            ];
        }
        if ($wpdb->insert($notes, $row) === false) {
            $release();
            return new WP_REST_Response(['message' => 'error'], 500);
        }
        $new_id = (int) $wpdb->insert_id;
        $wpdb->update($table, ['receipt_id' => $new_id], ['client_id' => $client_id]);
        return new WP_REST_Response(['ok' => true, 'id' => $new_id], 200);
    }

    /** « 2026-10-06 14:30 » saisi sur le téléphone, lu dans le fuseau du site (et non en UTC) ; 0 si illisible. */
    private static function local_ts(string $value): int {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}/', $value)) return 0;
        try { return (new DateTimeImmutable(str_replace('T', ' ', $value), wp_timezone()))->getTimestamp(); } catch (Exception $e) { return 0; }
    }

    /** Échéance d'une tâche : date saisie ; à défaut demain 9 h ; jamais dans un passé lointain. */
    private static function bounded_due($value): string {
        $ts = self::local_ts((string) $value);
        if (!$ts || $ts < time() - 30 * DAY_IN_SECONDS || $ts > time() + 5 * YEAR_IN_SECONDS) $ts = strtotime('tomorrow 09:00');
        return wp_date('Y-m-d H:i:s', $ts);
    }
}
