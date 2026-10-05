<?php
defined('ABSPATH') || exit;

/**
 * Synchronisation des livraisons ISPAG vers un calendrier Baïkal (CalDAV) : un événement « journée entière » par projet.
 *
 *  - Plage réglable (jours avant / jours après aujourd'hui) : sont synchronisés les projets dont au moins un article a une
 *    livraison qui CHEVAUCHE la plage ; l'événement couvre de la première à la dernière date de livraison de ces articles.
 *  - Seuls les vrais projets (ni offres, ni articles archivés) sont envoyés.
 *  - Un événement n'est renvoyé que si son contenu a changé (empreinte mémorisée) ; ceux qui ne sont plus concernés
 *    (date supprimée, hors plage, projet supprimé) sont retirés de Baïkal, y compris d'anciens événements restés dans le calendrier
 *    (seuls les fichiers « deal-<n>.ics » créés par cette synchronisation sont touchés).
 *  - Réglages (serveur, calendrier, mot de passe, plage, fréquence) : ISPAG Settings → Calendar sync.
 *    Le mot de passe peut aussi venir de la constante / variable d'environnement ISPAG_BAIKAL_PASSWORD (prioritaire).
 */
class ISPAG_Baikal_Calendar_Sync {

    const CRON_HOOK   = 'ispag_cron_sync_calendar';
    const LOG_NAME    = 'baikal_sync';
    const PAGE        = 'ispag-calendar-sync';
    const LOCK        = 'ispag_baikal_sync_lock';
    const OPT_STATE   = 'ispag_baikal_state';     // [deal_id => empreinte du contenu envoyé]
    const OPT_LAST    = 'ispag_baikal_last_run';  // résumé de la dernière exécution
    const INTERVALS   = ['hourly' => 1, 'twicedaily' => 1, 'daily' => 1]; // fréquences WP-Cron proposées

    public function __construct() {
        // Toujours enregistrer l'action pour que WP-Cron la trouve
        add_action(self::CRON_HOOK, [$this, 'sync_all_deliveries_cron']);
        add_action('init', [$this, 'ensure_scheduled'], 20);
        add_action('parse_request', [self::class, 'maybe_serve_feed'], 1);

        if (is_admin()) {
            add_action('admin_menu', [$this, 'menu']);
            add_action('admin_post_ispag_baikal_save', [$this, 'handle_save']);
            add_action('admin_post_ispag_baikal_action', [$this, 'handle_action']);
            add_action('admin_post_ispag_calendar_feed_token', [$this, 'handle_feed_token']);
        }
    }

    // ------------------------------------------------------------------ réglages

    public static function settings(): array {
        return [
            'enabled'  => (int) get_option('ispag_baikal_enabled', 1),
            'host'     => ISPAG_Baikal_Settings::host(),
            'user'     => (string) get_option('ispag_baikal_user', 'cyril'),
            'calendar' => (string) get_option('ispag_baikal_calendar', 'default'),
            'before'   => max(0, (int) get_option('ispag_baikal_days_before', 30)),
            'after'    => max(1, (int) get_option('ispag_baikal_days_after', 90)),
            'interval' => (string) get_option('ispag_baikal_interval', 'hourly'),
        ];
    }

    private static function password_is_external(): bool { return ISPAG_Baikal_Settings::password_is_external(); }

    private static function password(): string { return ISPAG_Baikal_Settings::password(); }

    public function ensure_scheduled() {
        $s       = self::settings();
        $next    = wp_next_scheduled(self::CRON_HOOK);
        $current = $next ? wp_get_schedule(self::CRON_HOOK) : false;

        if (!$s['enabled']) {
            if ($next) wp_clear_scheduled_hook(self::CRON_HOOK);
            return;
        }
        $interval = isset(self::INTERVALS[$s['interval']]) ? $s['interval'] : 'hourly';
        if ($current !== $interval) {
            if ($next) wp_clear_scheduled_hook(self::CRON_HOOK);
            wp_schedule_event(time() + 60, $interval, self::CRON_HOOK);
        }
    }

    // ------------------------------------------------------------------ synchronisation

    /** Point d'entrée du cron. */
    public function sync_all_deliveries_cron() {
        $s = self::settings();
        if (!$s['enabled']) return;
        $this->run(false);
    }

    /** Synchronisation immédiate (bouton / ancien lien ?baikal_ispag_calendar_sync). */
    public function sync_all_deliveries_now() {
        return $this->run(true);
    }

    private function log($message) {
        if (class_exists('ISPAG_Logger')) {
            ISPAG_Logger::get_instance()->log(self::LOG_NAME, $message);
        }
    }

    /**
     * @param bool $force true = tout renvoyer, même sans changement
     * @return array résumé [time, pushed, unchanged, deleted, errors, message]
     */
    public function run(bool $force): array {
        $s   = self::settings();
        $sum = ['time' => time(), 'pushed' => 0, 'unchanged' => 0, 'deleted' => 0, 'errors' => 0, 'message' => ''];

        if (self::password() === '' || $s['host'] === '' || $s['user'] === '') {
            $sum['message'] = 'Not configured: set the server, user and password in ISPAG Settings → Calendar sync.';
            $sum['errors'] = 1;
            update_option(self::OPT_LAST, $sum, false);
            $this->log($sum['message']);
            return $sum;
        }
        if (get_transient(self::LOCK)) {
            $sum['message'] = 'A synchronization is already running.';
            return $sum;
        }
        set_transient(self::LOCK, 1, 10 * MINUTE_IN_SECONDS);
        if (function_exists('set_time_limit')) @set_time_limit(0);

        try {
            $desired = $this->desired_events($s);                    // deal_id => ['hash' => …, 'ics' => …]
            $state   = (array) get_option(self::OPT_STATE, []);

            foreach ($desired as $deal_id => $ev) {
                if (!$force && isset($state[$deal_id]) && $state[$deal_id] === $ev['hash']) {
                    $sum['unchanged']++;
                    continue;
                }
                $code = $this->dav('PUT', $this->url($s, "deal-$deal_id.ics"), $ev['ics']);
                if (in_array($code, [200, 201, 204], true)) {
                    $state[$deal_id] = $ev['hash'];
                    $sum['pushed']++;
                } else {
                    unset($state[$deal_id]); // sera retenté à la prochaine exécution
                    $sum['errors']++;
                    $this->log("PUT deal-$deal_id.ics refusé (HTTP $code).");
                }
            }

            // Événements à retirer : ceux de Baïkal (liste réelle du calendrier) qui ne sont plus désirés
            $remote = $this->list_remote_deals($s);
            if ($remote === null) {
                $sum['errors']++;
                $this->log('Liste du calendrier impossible (PROPFIND) : aucun événement retiré.');
            } else {
                foreach (array_diff($remote, array_keys($desired)) as $deal_id) {
                    $code = $this->dav('DELETE', $this->url($s, "deal-$deal_id.ics"));
                    if (in_array($code, [200, 204, 404], true)) {
                        unset($state[$deal_id]);
                        $sum['deleted']++;
                    } else {
                        $sum['errors']++;
                        $this->log("DELETE deal-$deal_id.ics refusé (HTTP $code).");
                    }
                }
            }
            // Mémoire : on ne garde que les projets désirés
            $state = array_intersect_key($state, $desired);
            update_option(self::OPT_STATE, $state, false);
            $sum['message'] = sprintf('%d sent, %d unchanged, %d removed, %d error(s).', $sum['pushed'], $sum['unchanged'], $sum['deleted'], $sum['errors']);
        } catch (Throwable $e) {
            $sum['errors']++;
            $sum['message'] = 'Error: ' . $e->getMessage();
        }
        delete_transient(self::LOCK);
        update_option(self::OPT_LAST, $sum, false);
        $this->log($sum['message']);
        return $sum;
    }

    /**
     * Événements désirés : un par projet dont une livraison chevauche la plage.
     * @return array deal_id => ['hash' => string, 'ics' => string]
     */
    private function desired_events(array $s): array {
        global $wpdb;
        $p      = $wpdb->prefix;
        $win_lo = time() - $s['before'] * DAY_IN_SECONDS;
        $win_hi = time() + $s['after']  * DAY_IN_SECONDS;

        // Dates de livraison d'un article : de la plus petite à la plus grande de (début, fin)
        $rows = $wpdb->get_results($wpdb->prepare("
            SELECT d.hubspot_deal_id AS deal_id, d.Qty, d.Livre, t.prestation,
                   LEAST(d.TimestampDateDeLivraison, IF(d.TimestampDateDeLivraisonFin > 0, d.TimestampDateDeLivraisonFin, d.TimestampDateDeLivraison)) AS lo,
                   GREATEST(d.TimestampDateDeLivraison, IF(d.TimestampDateDeLivraisonFin > 0, d.TimestampDateDeLivraisonFin, d.TimestampDateDeLivraison)) AS hi
            FROM {$p}achats_details_commande d
            JOIN {$p}achats_liste_commande pr ON pr.hubspot_deal_id = d.hubspot_deal_id AND (pr.isQotation IS NULL OR pr.isQotation = 0)
            LEFT JOIN {$p}achats_type_prestations t ON t.Id = d.Type
            WHERE d.hubspot_deal_id > 0 AND d.archive = 0 AND d.TimestampDateDeLivraison > 0
            HAVING lo <= %d AND hi >= %d
            ORDER BY d.hubspot_deal_id, d.TimestampDateDeLivraison, d.Id
        ", $win_hi, $win_lo));

        $by_deal = [];
        foreach ($rows as $r) {
            $d = (int) $r->deal_id;
            $by_deal[$d]['lo'] = isset($by_deal[$d]['lo']) ? min($by_deal[$d]['lo'], (int) $r->lo) : (int) $r->lo;
            $by_deal[$d]['hi'] = isset($by_deal[$d]['hi']) ? max($by_deal[$d]['hi'], (int) $r->hi) : (int) $r->hi;
            $by_deal[$d]['items'][] = $r;
        }

        $out = [];
        foreach ($by_deal as $deal_id => $info) {
            $built = $this->build_event((int) $deal_id, $info['lo'], $info['hi'], $info['items']);
            if ($built) $out[$deal_id] = $built;
        }
        return $out;
    }

    private function build_event(int $deal_id, int $lo, int $hi, array $items) {
        global $wpdb;
        $p = $wpdb->prefix;

        $site = trailingslashit(get_site_url());
        $info = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}achats_info_commande WHERE hubspot_deal_id = %d AND purchase_order = 0 LIMIT 1", $deal_id));
        $project = apply_filters('ispag_get_project_by_deal_id', null, $deal_id);

        $name = !empty($project->ObjetCommande) ? $project->ObjetCommande : 'Projet #' . $deal_id;

        $articles = [];
        foreach ($items as $it) {
            $articles[] = '- ' . (float) $it->Qty . 'x ' . ($it->prestation ?: 'Article') . ((int) $it->Livre > 0 ? ' (livré)' : '');
        }

        $company_info = $contact_info = '';
        if ($project) {
            if (!empty($project->associated_company_id) && class_exists('ISPAG_Crm_Company_Repository')) {
                $company = (new ISPAG_Crm_Company_Repository())->get_company_by_id($project->associated_company_id);
                $company_info = 'ENTREPRISE : ' . (!empty($company->company_name) ? $company->company_name : 'Entreprise #' . $project->associated_company_id)
                    . "\nLien : " . $site . 'company/' . $project->associated_company_id . '/';
            }
            if (!empty($project->associated_contact_ids)) {
                $lines = [];
                foreach (explode(',', $project->associated_contact_ids) as $c_id) {
                    $c_id = (int) trim($c_id);
                    $user = $c_id ? get_userdata($c_id) : null;
                    if ($user) {
                        $lines[] = (trim($user->first_name . ' ' . $user->last_name) ?: $user->display_name) . ' (' . $site . 'contact/' . $c_id . '/)';
                    }
                }
                if ($lines) $contact_info = "CONTACT(S) :\n- " . implode("\n- ", $lines);
            }
        }

        $location = $info ? implode(', ', array_filter([$info->AdresseDeLivraison, $info->DeliveryAdresse2, trim($info->NIP . ' ' . $info->City)])) : '';
        $description = implode("\n", array_filter([
            'PROJET : ' . $name,
            '--------------------------',
            'CONTENU DU PROJET :',
            implode("\n", $articles),
            '--------------------------',
            $info ? 'LIVRAISON : ' . trim($info->PersonneContact . ' (' . $info->num_tel_contact . ')') : '',
            $info && trim((string) $info->Comment) !== '' ? 'NOTE : ' . $info->Comment : '',
            '--------------------------',
            $company_info,
            $contact_info,
            '--------------------------',
            'VOIR LE PROJET : ' . $site . 'project-detail/' . $deal_id,
        ]));

        // Jours calendaires dans le fuseau du site ; DTEND (exclusif) = lendemain du dernier jour
        $dtstart = wp_date('Ymd', $lo);
        $dtend   = wp_date('Ymd', $hi + DAY_IN_SECONDS);
        if ($dtend <= $dtstart) $dtend = wp_date('Ymd', $lo + DAY_IN_SECONDS);

        $lines = [
            'UID:project-' . $deal_id . '@ispag-crm',
            'DTSTART;VALUE=DATE:' . $dtstart,
            'DTEND;VALUE=DATE:' . $dtend,
            'SUMMARY:' . $this->esc('LIVRAISON : ' . $name),
            'LOCATION:' . $this->esc($location),
            'DESCRIPTION:' . $this->esc($description),
        ];
        $hash = md5(implode("\n", $lines)); // sans DTSTAMP : l'empreinte ne change que si le contenu change

        $vevent = array_merge(['BEGIN:VEVENT', 'DTSTAMP:' . gmdate('Ymd\THis\Z')], $lines, ['END:VEVENT']);
        $ics    = array_merge(['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//ISPAG//CalendarSync//FR'], $vevent, ['END:VCALENDAR']);

        return [
            'hash'   => $hash,
            'ics'    => implode("\r\n", array_map([$this, 'fold'], $ics)),
            'vevent' => implode("\r\n", array_map([$this, 'fold'], $vevent)),
        ];
    }

    /** Échappement d'une valeur TEXT (RFC 5545). */
    private function esc($text): string {
        return str_replace(["\\", ";", ",", "\r\n", "\n", "\r"], ["\\\\", "\\;", "\\,", "\\n", "\\n", "\\n"], (string) $text);
    }

    /** Repli des lignes à 75 octets (sans couper un caractère UTF-8). */
    private function fold(string $line): string {
        if (strlen($line) <= 75) return $line;
        $out = '';
        $cur = '';
        foreach (preg_split('//u', $line, -1, PREG_SPLIT_NO_EMPTY) as $ch) {
            $limit = $out === '' ? 75 : 74; // les lignes de suite commencent par une espace
            if (strlen($cur) + strlen($ch) > $limit) {
                $out .= ($out === '' ? '' : "\r\n ") . $cur;
                $cur = '';
            }
            $cur .= $ch;
        }
        return $out . ($out === '' ? '' : "\r\n ") . $cur;
    }

    // ------------------------------------------------------------------ CalDAV

    private function url(array $s, string $file = ''): string {
        return apply_filters('ispag_baikal_scheme', 'https') . '://' . $s['host'] . '/dav.php/calendars/' . rawurlencode($s['user']) . '/' . rawurlencode($s['calendar']) . '/' . $file;
    }

    /** @return int code HTTP (0 = échec réseau) */
    private function dav(string $method, string $url, string $body = '', array $headers = [], &$response_body = null): int {
        $s = self::settings();
        $args = [
            'method'    => $method,
            'headers'   => array_merge(['Authorization' => 'Basic ' . base64_encode($s['user'] . ':' . self::password())], $method === 'PUT' ? ['Content-Type' => 'text/calendar; charset=utf-8'] : [], $headers),
            'timeout'   => 20,
            'sslverify' => true,
            'reject_unsafe_urls' => false,
        ];
        if ($body !== '') $args['body'] = $body;
        $res = wp_remote_request($url, $args);
        if (is_wp_error($res)) {
            $this->log("$method $url : " . $res->get_error_message());
            return 0;
        }
        $response_body = wp_remote_retrieve_body($res);
        return (int) wp_remote_retrieve_response_code($res);
    }

    /** Identifiants des projets présents dans Baïkal (fichiers deal-<n>.ics du calendrier) ; null si la liste est impossible. */
    private function list_remote_deals(array $s) {
        $body = null;
        $code = $this->dav('PROPFIND', $this->url($s), '<?xml version="1.0"?><d:propfind xmlns:d="DAV:"><d:prop><d:getetag/></d:prop></d:propfind>', ['Depth' => '1', 'Content-Type' => 'application/xml; charset=utf-8'], $body);
        if ($code !== 207 || !is_string($body)) return null;
        preg_match_all('#<[^>]*href[^>]*>[^<]*deal-(\d+)\.ics\s*<#i', $body, $m);
        return array_map('intval', array_unique($m[1]));
    }

    /** Vérifie la connexion : lecture du calendrier. @return array [ok, message] */
    public function test_connection(): array {
        $s = self::settings();
        if (self::password() === '') return [false, __('No password set.', 'creation-reservoir')];
        $code = $this->dav('PROPFIND', $this->url($s), '<?xml version="1.0"?><d:propfind xmlns:d="DAV:"><d:prop><d:displayname/></d:prop></d:propfind>', ['Depth' => '0', 'Content-Type' => 'application/xml; charset=utf-8']);
        if ($code === 207) return [true, __('Connection OK: the calendar is reachable.', 'creation-reservoir')];
        if ($code === 401 || $code === 403) return [false, __('Access refused: check the user and password.', 'creation-reservoir')];
        if ($code === 404) return [false, __('Calendar not found: check the user and the calendar name.', 'creation-reservoir')];
        if ($code === 0) return [false, __('The server cannot be reached: check the address.', 'creation-reservoir')];
        return [false, sprintf(__('Unexpected answer from the server (HTTP %d).', 'creation-reservoir'), $code)];
    }


    /** Vérifie l'accès au carnet d'adresses de chaque utilisateur cible. @return array [ok, message] */
    public function test_addressbooks(): array {
        $c = ISPAG_Baikal_Settings::contacts();
        if ($c['password'] === '') return [false, __('No password set.', 'creation-reservoir')];
        if (!$c['users']) return [false, __('No Baïkal user set.', 'creation-reservoir')];
        $bad = [];
        foreach ($c['users'] as $user) {
            $url = apply_filters('ispag_baikal_scheme', 'https') . '://' . $c['host'] . '/dav.php/addressbooks/' . rawurlencode($user) . '/' . rawurlencode($c['addressbook']) . '/';
            $res = wp_remote_request($url, ['method' => 'PROPFIND', 'timeout' => 20, 'headers' => [
                'Authorization' => 'Basic ' . base64_encode($user . ':' . $c['password']), 'Depth' => '0', 'Content-Type' => 'application/xml; charset=utf-8',
            ], 'body' => '<?xml version="1.0"?><d:propfind xmlns:d="DAV:"><d:prop><d:displayname/></d:prop></d:propfind>']);
            $code = is_wp_error($res) ? 0 : (int) wp_remote_retrieve_response_code($res);
            if ($code !== 207) $bad[] = $user . ' (' . ($code ?: 'no answer') . ')';
        }
        if (!$bad) return [true, sprintf(__('Connection OK: %d address book(s) reachable.', 'creation-reservoir'), count($c['users']))];
        return [false, sprintf(__('Address book not reachable for: %s', 'creation-reservoir'), implode(', ', $bad))];
    }

    // ------------------------------------------------------------------ flux d'abonnement (Outlook, Google Agenda, Apple…)

    const OPT_FEED_TOKEN = 'ispag_calendar_feed_token';
    const FEED_SLUG      = 'ispag-calendar';

    private static function feed_token(): string {
        $token = (string) get_option(self::OPT_FEED_TOKEN, '');
        if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
            $token = bin2hex(random_bytes(16));
            update_option(self::OPT_FEED_TOKEN, $token, false);
        }
        return $token;
    }

    public static function feed_url(): string {
        return home_url('/' . self::FEED_SLUG . '/' . self::feed_token() . '.ics');
    }

    /** /ispag-calendar/<jeton>.ics : calendrier en lecture seule, mêmes événements et même plage que la synchronisation Baïkal. */
    public static function maybe_serve_feed() {
        $path = (string) wp_parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
        $base = trailingslashit((string) wp_parse_url(home_url('/'), PHP_URL_PATH)) . self::FEED_SLUG . '/';
        if (strpos($path, $base) !== 0) return;

        $given = preg_replace('/\.ics$/', '', substr($path, strlen($base)));
        if (!hash_equals(self::feed_token(), (string) $given)) {
            status_header(404);
            nocache_headers();
            echo 'Not found';
            exit;
        }

        $body = get_transient('ispag_calendar_feed_cache');
        if (!is_string($body) || $body === '') {
            $self   = new self();
            $events = $self->desired_events(self::settings());
            $name   = (string) (get_option('wpcb_companyName') ?: 'ISPAG') . ' – ' . 'Livraisons';
            $head = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//ISPAG//CalendarFeed//FR', 'CALSCALE:GREGORIAN', 'METHOD:PUBLISH',
                     'X-WR-CALNAME:' . $self->esc($name), 'NAME:' . $self->esc($name),
                     'REFRESH-INTERVAL;VALUE=DURATION:PT1H', 'X-PUBLISHED-TTL:PT1H'];
            $body = implode("\r\n", array_map([$self, 'fold'], $head)) . "\r\n";
            foreach ($events as $ev) $body .= $ev['vevent'] . "\r\n";
            $body .= 'END:VCALENDAR' . "\r\n";
            set_transient('ispag_calendar_feed_cache', $body, 10 * MINUTE_IN_SECONDS);
        }
        nocache_headers();
        header('Content-Type: text/calendar; charset=utf-8');
        header('Content-Disposition: inline; filename="ispag-livraisons.ics"');
        header('X-Robots-Tag: noindex, nofollow');
        header('Cache-Control: private, max-age=600');
        echo $body;
        exit;
    }

    public function handle_feed_token() {
        if (!current_user_can('manage_options')) wp_die(esc_html__('Access denied', 'creation-reservoir'));
        check_admin_referer('ispag_calendar_feed_token');
        update_option(self::OPT_FEED_TOKEN, bin2hex(random_bytes(16)), false);
        delete_transient('ispag_calendar_feed_cache');
        wp_safe_redirect($this->page_url(['notice' => rawurlencode(__('A new subscription link was generated. The previous link no longer works.', 'creation-reservoir')), 'ok' => 1]));
        exit;
    }

    // ------------------------------------------------------------------ page d'administration

    public function menu() {
        add_submenu_page('ispag-settings', __('Calendar sync', 'creation-reservoir'), __('Calendar sync', 'creation-reservoir'), 'manage_options', self::PAGE, [$this, 'render_page']);
    }

    private function page_url(array $args = []): string {
        return add_query_arg($args, admin_url('admin.php?page=' . self::PAGE));
    }

    public function render_page() {
        if (!current_user_can('manage_options')) wp_die(esc_html__('Access denied', 'creation-reservoir'));
        $s    = self::settings();
        $last = (array) get_option(self::OPT_LAST, []);
        $next = wp_next_scheduled(self::CRON_HOOK);
        $has_pw = self::password() !== '';
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Calendar sync (Baïkal)', 'creation-reservoir'); ?></h1>
            <p><?php esc_html_e('Delivery dates of the projects are sent to a CalDAV calendar: one all-day event per project, from its first to its last delivery date.', 'creation-reservoir'); ?></p>

            <?php if (!empty($_GET['notice'])): $ok = !empty($_GET['ok']); ?>
                <div class="notice notice-<?php echo $ok ? 'success' : 'error'; ?> is-dismissible"><p><?php echo esc_html(wp_unslash($_GET['notice'])); ?></p></div>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('ispag_baikal_save'); ?>
                <input type="hidden" name="action" value="ispag_baikal_save">
                <h2><?php esc_html_e('Date range', 'creation-reservoir'); ?></h2>
                <table class="form-table">
                    <tr><th scope="row"><label for="days_before"><?php esc_html_e('Days before today', 'creation-reservoir'); ?></label></th>
                        <td><input type="number" min="0" max="3650" id="days_before" name="days_before" value="<?php echo (int) $s['before']; ?>" style="width:100px">
                        <p class="description"><?php esc_html_e('Deliveries that ended up to this many days ago are still synchronized.', 'creation-reservoir'); ?></p></td></tr>
                    <tr><th scope="row"><label for="days_after"><?php esc_html_e('Days after today', 'creation-reservoir'); ?></label></th>
                        <td><input type="number" min="1" max="3650" id="days_after" name="days_after" value="<?php echo (int) $s['after']; ?>" style="width:100px">
                        <p class="description"><?php esc_html_e('Deliveries starting up to this many days from now are synchronized. Events leaving the range are removed from the calendar.', 'creation-reservoir'); ?></p></td></tr>
                </table>

                <h2><?php esc_html_e('Synchronization', 'creation-reservoir'); ?></h2>
                <table class="form-table">
                    <tr><th scope="row"><?php esc_html_e('Automatic synchronization', 'creation-reservoir'); ?></th>
                        <td><label><input type="checkbox" name="enabled" value="1" <?php checked($s['enabled']); ?>> <?php esc_html_e('Enabled', 'creation-reservoir'); ?></label></td></tr>
                    <tr><th scope="row"><label for="interval"><?php esc_html_e('Frequency', 'creation-reservoir'); ?></label></th>
                        <td><select id="interval" name="interval">
                            <?php foreach (['hourly' => __('Every hour', 'creation-reservoir'), 'twicedaily' => __('Twice a day', 'creation-reservoir'), 'daily' => __('Once a day', 'creation-reservoir')] as $key => $label): ?>
                                <option value="<?php echo esc_attr($key); ?>" <?php selected($s['interval'], $key); ?>><?php echo esc_html($label); ?></option>
                            <?php endforeach; ?>
                        </select></td></tr>
                </table>

                <h2><?php esc_html_e('Calendar server', 'creation-reservoir'); ?></h2>
                <table class="form-table">
                    <tr><th scope="row"><label for="host"><?php esc_html_e('Server address', 'creation-reservoir'); ?></label></th>
                        <td><input class="regular-text" type="text" id="host" name="host" value="<?php echo esc_attr($s['host']); ?>" placeholder="calendar.example.com">
                        <p class="description"><?php esc_html_e('Host name only, without https://.', 'creation-reservoir'); ?></p></td></tr>
                    <tr><th scope="row"><label for="user"><?php esc_html_e('User', 'creation-reservoir'); ?></label></th>
                        <td><input class="regular-text" type="text" id="user" name="user" value="<?php echo esc_attr($s['user']); ?>" autocomplete="off"></td></tr>
                    <tr><th scope="row"><label for="calendar"><?php esc_html_e('Calendar name', 'creation-reservoir'); ?></label></th>
                        <td><input class="regular-text" type="text" id="calendar" name="calendar" value="<?php echo esc_attr($s['calendar']); ?>"></td></tr>
                    <tr><th scope="row"><label for="password"><?php esc_html_e('Password', 'creation-reservoir'); ?></label></th>
                        <td>
                        <?php if (self::password_is_external()): ?>
                            <p><em><?php esc_html_e('The password is defined by the server (ISPAG_BAIKAL_PASSWORD) and takes priority; it cannot be changed here.', 'creation-reservoir'); ?></em></p>
                        <?php else: ?>
                            <input class="regular-text" type="password" id="password" name="password" autocomplete="new-password" placeholder="<?php echo $has_pw ? esc_attr__('Password saved — leave empty to keep it', 'creation-reservoir') : ''; ?>">
                            <p class="description"><?php esc_html_e('Stored in the database and never displayed again. You can also define ISPAG_BAIKAL_PASSWORD in wp-config.php.', 'creation-reservoir'); ?></p>
                        <?php endif; ?>
                        </td></tr>
                </table>

                <h2><?php esc_html_e('Contacts (CRM address book)', 'creation-reservoir'); ?></h2>
                <p><?php esc_html_e('CRM contacts of the department below are synchronized both ways with the Baïkal address book (the most recent change wins). The server and password above are shared with the calendar.', 'creation-reservoir'); ?></p>
                <?php $c = ISPAG_Baikal_Settings::contacts(); ?>
                <table class="form-table">
                    <tr><th scope="row"><?php esc_html_e('Contacts synchronization', 'creation-reservoir'); ?></th>
                        <td><label><input type="checkbox" name="ab_enabled" value="1" <?php checked($c['enabled']); ?>> <?php esc_html_e('Enabled', 'creation-reservoir'); ?></label></td></tr>
                    <tr><th scope="row"><label for="ab_name"><?php esc_html_e('Address book name', 'creation-reservoir'); ?></label></th>
                        <td><input class="regular-text" type="text" id="ab_name" name="ab_name" value="<?php echo esc_attr($c['addressbook']); ?>"></td></tr>
                    <tr><th scope="row"><label for="ab_users"><?php esc_html_e('Baïkal users', 'creation-reservoir'); ?></label></th>
                        <td><input class="regular-text" type="text" id="ab_users" name="ab_users" value="<?php echo esc_attr(implode(', ', $c['users'])); ?>" placeholder="cyril, claudio">
                        <p class="description"><?php esc_html_e('Each user gets the contacts in his address book. Separate the names with commas.', 'creation-reservoir'); ?></p></td></tr>
                    <tr><th scope="row"><?php esc_html_e('Departments synchronized', 'creation-reservoir'); ?></th>
                        <td><fieldset>
                            <label style="display:block;margin-bottom:6px"><input type="checkbox" id="ab_dept_all"> <strong><?php esc_html_e('Select all', 'creation-reservoir'); ?></strong></label>
                            <?php foreach (ISPAG_Baikal_Settings::departments() as $key => $label): ?>
                                <label style="display:block;margin-bottom:4px"><input type="checkbox" class="ab-dept" name="ab_departments[]" value="<?php echo esc_attr($key); ?>" <?php checked(in_array($key, $c['departments'], true)); ?>> <?php echo esc_html($label); ?> <code><?php echo esc_html($key); ?></code></label>
                            <?php endforeach; ?>
                            </fieldset>
                        <p class="description"><?php esc_html_e('Only the contacts assigned to a checked department are synchronized.', 'creation-reservoir'); ?></p>
                        <script>(function(){var all=document.getElementById('ab_dept_all'),c=document.querySelectorAll('.ab-dept');function u(){var n=document.querySelectorAll('.ab-dept:checked').length;all.checked=n===c.length;all.indeterminate=n>0&&n<c.length;}all.addEventListener('change',function(){c.forEach(function(x){x.checked=all.checked;});u();});c.forEach(function(x){x.addEventListener('change',u);});u();})();</script></td></tr>
                    <tr><th scope="row"><label for="ab_interval"><?php esc_html_e('Frequency', 'creation-reservoir'); ?></label></th>
                        <td><select id="ab_interval" name="ab_interval">
                            <?php foreach (['hourly' => __('Every hour', 'creation-reservoir'), 'twicedaily' => __('Twice a day', 'creation-reservoir'), 'daily' => __('Once a day', 'creation-reservoir')] as $key => $label): ?>
                                <option value="<?php echo esc_attr($key); ?>" <?php selected($c['interval'], $key); ?>><?php echo esc_html($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description"><?php esc_html_e('How often changes made in Baïkal are imported. Changes made in the CRM are sent immediately.', 'creation-reservoir'); ?></p></td></tr>
                </table>
                <?php submit_button(__('Save settings', 'creation-reservoir')); ?>
            </form>

            <h2><?php esc_html_e('Status', 'creation-reservoir'); ?></h2>
            <table class="widefat striped" style="max-width:720px"><tbody>
                <tr><th><?php esc_html_e('Next automatic run', 'creation-reservoir'); ?></th><td><?php echo $next && $s['enabled'] ? esc_html(wp_date('d.m.Y H:i', $next)) : '—'; ?></td></tr>
                <tr><th><?php esc_html_e('Last run', 'creation-reservoir'); ?></th><td><?php echo !empty($last['time']) ? esc_html(wp_date('d.m.Y H:i', (int) $last['time'])) : '—'; ?></td></tr>
                <tr><th><?php esc_html_e('Result', 'creation-reservoir'); ?></th><td><?php echo !empty($last['message']) ? esc_html($last['message']) : '—'; ?></td></tr>
                <tr><th><?php esc_html_e('Current range', 'creation-reservoir'); ?></th><td><?php echo esc_html(wp_date('d.m.Y', time() - $s['before'] * DAY_IN_SECONDS) . ' → ' . wp_date('d.m.Y', time() + $s['after'] * DAY_IN_SECONDS)); ?></td></tr>
            </tbody></table>

            <p style="margin-top:14px">
                <?php foreach (['test' => __('Test the connection', 'creation-reservoir'), 'sync' => __('Synchronize now', 'creation-reservoir'), 'resync' => __('Resend everything', 'creation-reservoir')] as $act => $label): ?>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline">
                        <?php wp_nonce_field('ispag_baikal_action'); ?>
                        <input type="hidden" name="action" value="ispag_baikal_action">
                        <input type="hidden" name="do" value="<?php echo esc_attr($act); ?>">
                        <button class="button<?php echo $act === 'sync' ? ' button-primary' : ''; ?>" type="submit"><?php echo esc_html($label); ?></button>
                    </form>
                <?php endforeach; ?>
            </p>

            <h2><?php esc_html_e('Contacts status', 'creation-reservoir'); ?></h2>
            <?php $cl = (array) get_option('ispag_baikal_contacts_last_run', []); $cn = wp_next_scheduled('ispag_sync_from_baikal_cron'); ?>
            <table class="widefat striped" style="max-width:720px"><tbody>
                <tr><th><?php esc_html_e('Next automatic import', 'creation-reservoir'); ?></th><td><?php echo $cn && $c['enabled'] ? esc_html(wp_date('d.m.Y H:i', $cn)) : '—'; ?></td></tr>
                <tr><th><?php esc_html_e('Last import', 'creation-reservoir'); ?></th><td><?php echo !empty($cl['time']) ? esc_html(wp_date('d.m.Y H:i', (int) $cl['time'])) : '—'; ?></td></tr>
                <tr><th><?php esc_html_e('Result', 'creation-reservoir'); ?></th><td><?php echo !empty($cl['time']) ? esc_html(sprintf(__('%1$d contact file(s) found, %2$d unchanged, %3$d processed, %4$d error(s).', 'creation-reservoir'), $cl['found'], $cl['unchanged'], $cl['processed'], $cl['errors'])) : '—'; ?></td></tr>
            </tbody></table>
            <p style="margin-top:14px">
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline">
                    <?php wp_nonce_field('ispag_baikal_action'); ?>
                    <input type="hidden" name="action" value="ispag_baikal_action">
                    <input type="hidden" name="do" value="test_contacts">
                    <button class="button" type="submit"><?php esc_html_e('Test the contacts connection', 'creation-reservoir'); ?></button>
                </form>
                <a class="button" href="<?php echo esc_url(admin_url('?run_baikal_sync=1')); ?>"><?php esc_html_e('Synchronize all contacts now', 'creation-reservoir'); ?></a>
            </p>

            <h2><?php esc_html_e('Subscribe from Outlook (read-only)', 'creation-reservoir'); ?></h2>
            <p><?php esc_html_e('To show the deliveries in Outlook (or Google Calendar, Apple Calendar), subscribe to this private link: in Outlook, Add calendar → Subscribe from web. The calendar is read-only and uses the date range above. Anyone with the link can see the deliveries: do not share it publicly.', 'creation-reservoir'); ?></p>
            <p><input type="text" id="ispag-feed-url" class="large-text code" readonly value="<?php echo esc_attr(self::feed_url()); ?>" style="max-width:720px" onclick="this.select()">
               <button type="button" class="button" id="ispag-feed-copy"><?php esc_html_e('Copy the link', 'creation-reservoir'); ?></button></p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm(<?php echo esc_attr(wp_json_encode(__('Generate a new link? Current subscriptions will stop working.', 'creation-reservoir'))); ?>);">
                <?php wp_nonce_field('ispag_calendar_feed_token'); ?>
                <input type="hidden" name="action" value="ispag_calendar_feed_token">
                <button class="button" type="submit"><?php esc_html_e('Generate a new link', 'creation-reservoir'); ?></button>
            </form>
            <script>document.getElementById('ispag-feed-copy').addEventListener('click', function () { var i = document.getElementById('ispag-feed-url'); i.select(); (navigator.clipboard ? navigator.clipboard.writeText(i.value) : Promise.resolve(document.execCommand('copy'))); this.textContent = '✓'; });</script>
        </div>
        <?php
    }

    public function handle_save() {
        if (!current_user_can('manage_options')) wp_die(esc_html__('Access denied', 'creation-reservoir'));
        check_admin_referer('ispag_baikal_save');

        $host = strtolower(trim((string) wp_unslash($_POST['host'] ?? '')));
        $host = preg_replace('#^https?://#', '', $host);
        $host = preg_replace('#[^a-z0-9.\-:]#', '', strtok($host, '/'));
        update_option('ispag_baikal_host', $host);
        update_option('ispag_baikal_user', sanitize_text_field(wp_unslash($_POST['user'] ?? '')));
        update_option('ispag_baikal_calendar', preg_replace('#[^A-Za-z0-9_.\-]#', '', (string) wp_unslash($_POST['calendar'] ?? '')) ?: 'default');
        update_option('ispag_baikal_days_before', max(0, min(3650, (int) ($_POST['days_before'] ?? 30))));
        update_option('ispag_baikal_days_after', max(1, min(3650, (int) ($_POST['days_after'] ?? 90))));
        update_option('ispag_baikal_enabled', empty($_POST['enabled']) ? 0 : 1);
        $interval = sanitize_key(wp_unslash($_POST['interval'] ?? 'hourly'));
        update_option('ispag_baikal_interval', isset(self::INTERVALS[$interval]) ? $interval : 'hourly');

        $pw = trim((string) wp_unslash($_POST['password'] ?? ''));
        if ($pw !== '' && !self::password_is_external()) {
            update_option('ispag_baikal_password', $pw, false);
        }
        update_option('ispag_baikal_ab_enabled', empty($_POST['ab_enabled']) ? 0 : 1);
        update_option('ispag_baikal_ab_name', preg_replace('#[^A-Za-z0-9_.\-]#', '', (string) wp_unslash($_POST['ab_name'] ?? '')) ?: 'ispag');
        update_option('ispag_baikal_ab_users', implode(', ', ISPAG_Baikal_Settings::parse_users(wp_unslash($_POST['ab_users'] ?? ''))));
        update_option('ispag_baikal_ab_departments', array_values(array_unique(array_filter(array_map('sanitize_key', (array) wp_unslash($_POST['ab_departments'] ?? []))))));
        $ab_interval = sanitize_key(wp_unslash($_POST['ab_interval'] ?? 'hourly'));
        update_option('ispag_baikal_ab_interval', isset(self::INTERVALS[$ab_interval]) ? $ab_interval : 'hourly');
        wp_clear_scheduled_hook('ispag_sync_from_baikal_cron'); // replanifié par le plugin CRM avec la nouvelle fréquence
        delete_transient('ispag_calendar_feed_cache');
        delete_option(self::OPT_STATE); // nouveau serveur / calendrier / plage : on renvoie tout au prochain passage
        $this->ensure_scheduled();

        wp_safe_redirect($this->page_url(['notice' => rawurlencode(__('Settings saved.', 'creation-reservoir')), 'ok' => 1]));
        exit;
    }

    public function handle_action() {
        if (!current_user_can('manage_options')) wp_die(esc_html__('Access denied', 'creation-reservoir'));
        check_admin_referer('ispag_baikal_action');
        $do = sanitize_key(wp_unslash($_POST['do'] ?? ''));

        if ($do === 'test') {
            [$ok, $msg] = $this->test_connection();
        } elseif ($do === 'test_contacts') {
            [$ok, $msg] = $this->test_addressbooks();
        } else {
            $sum = $this->run($do === 'resync');
            $ok  = empty($sum['errors']);
            $msg = $sum['message'];
        }
        wp_safe_redirect($this->page_url(['notice' => rawurlencode($msg), 'ok' => $ok ? 1 : 0]));
        exit;
    }
}
