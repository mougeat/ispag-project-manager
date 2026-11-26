<?php
defined('ABSPATH') || exit;

/**
 * Conversion EUR → CHF des prix d'achat (calcul de vente actuel et calcul « coût complet »).
 *
 * Taux réglable dans ISPAG Settings (1 = aucune conversion : c'est la valeur par défaut, les prix ne changent donc pas
 * tant que le taux n'est pas saisi). Chaque mois, le site va chercher les taux de référence de la BCE (service gratuit
 * frankfurter.dev), en calcule la moyenne des 6 derniers mois, la mémorise et l'envoie par e-mail à l'administrateur du site : le taux utilisé dans les calculs
 * ne change JAMAIS tout seul, il faut le recopier dans les réglages.
 *
 * Seuls les achats d'un fournisseur dont la devise est EUR sont convertis (devise = champ `ispag_supplier_currency` du fournisseur).
 */
class ISPAG_Currency_Rate {

    const OPT_RATE    = 'ispag_eur_chf_rate';
    const OPT_FETCHED = 'ispag_eur_chf_rate_fetched';
    const CRON_HOOK   = 'ispag_eur_chf_rate_fetch';
    const API_URL     = 'https://api.frankfurter.dev/v1/%1$s..%2$s?base=EUR&symbols=CHF';   // série quotidienne entre deux dates
    const MONTHS      = 6;     // le taux proposé est la moyenne des 6 derniers mois
    const MIN_DAYS    = 20;    // moins de jours que cela = réponse incomplète, pas une moyenne

    /** Devise par article (mémoire de la requête). */
    private static $currency_cache = [];

    public static function init() {
        add_filter('cron_schedules', function ($schedules) {
            $schedules['ispag_monthly'] = ['interval' => 30 * DAY_IN_SECONDS, 'display' => 'Every 30 days'];
            return $schedules;
        });
        add_action(self::CRON_HOOK, [self::class, 'cron_fetch']);
        add_action('admin_post_ispag_fetch_eur_chf', [self::class, 'handle_fetch_now']);
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'ispag_monthly', self::CRON_HOOK);
        }
    }

    /** Réglage affiché dans ISPAG Settings : option => [libellé, type, défaut, aide]. */
    public static function fields() {
        return [
            self::OPT_RATE => ['EUR → CHF rate', 'number', 1, '1 = no conversion (prices unchanged). Enter the CHF value of 1 EUR (for example 0.91) to convert the purchase prices of suppliers whose currency is EUR, in the current calculation and in the full-cost calculation. The proposed rate below (average of the last 6 months, fetched monthly) is only a proposal: it never changes this setting by itself.'],
        ];
    }

    public static function rate() {
        $v = (float) get_option(self::OPT_RATE, 1.0);
        return $v > 0 ? $v : 1.0;
    }

    public static function is_active() {
        return abs(self::rate() - 1.0) > 0.0000001;
    }

    /** Montant converti en CHF si la devise est EUR, inchangé sinon. */
    public static function convert($amount, $currency) {
        return strtoupper(trim((string) $currency)) === 'EUR' ? (float) $amount * self::rate() : (float) $amount;
    }

    /** Prix d'achat d'un article, converti si son fournisseur est en EUR (aucune requête tant que le taux vaut 1). */
    public static function convert_article_purchase($article_id, $amount) {
        if (!self::is_active()) {
            return $amount;
        }
        return self::convert($amount, self::article_currency($article_id));
    }

    private static function article_currency($article_id) {
        global $wpdb;
        $id = (int) $article_id;
        if (!isset(self::$currency_cache[$id])) {
            $wpdb->last_error = '';
            $cur = $wpdb->get_var($wpdb->prepare(
                "SELECT (SELECT m.meta_value FROM {$wpdb->prefix}ispag_companies_meta m
                          WHERE m.company_id = c.IdFournisseur AND m.meta_key = 'ispag_supplier_currency'
                          ORDER BY m.meta_id DESC LIMIT 1)
                 FROM {$wpdb->prefix}achats_articles_cmd_fournisseurs f
                 LEFT JOIN {$wpdb->prefix}achats_commande_liste_fournisseurs c ON c.Id = f.IdCommande
                 WHERE f.IdCommandeClient = %d",
                $id
            ));
            // Erreur SQL : pas de conversion plutôt qu'un prix faux (l'erreur est visible dans le journal PHP)
            if ($wpdb->last_error) {
                error_log('ISPAG_Currency_Rate: ' . $wpdb->last_error);
                $cur = '';
            }
            self::$currency_cache[$id] = strtoupper(trim((string) $cur));
        }
        return self::$currency_cache[$id];
    }

    /**
     * Va chercher la moyenne des taux quotidiens des 6 derniers mois (taux de référence BCE).
     * @return array{rate?:float,latest?:float,date?:string,from?:string,days?:int,error?:string}
     */
    public static function fetch() {
        $to   = gmdate('Y-m-d');
        $from = gmdate('Y-m-d', strtotime('-' . self::MONTHS . ' months'));
        $res  = wp_remote_get(sprintf(self::API_URL, $from, $to), ['timeout' => 20]);
        if (is_wp_error($res)) {
            return ['error' => $res->get_error_message()];
        }
        $code = (int) wp_remote_retrieve_response_code($res);
        if ($code !== 200) {
            return ['error' => 'HTTP ' . $code];
        }
        $json  = json_decode((string) wp_remote_retrieve_body($res), true);
        $daily = is_array($json['rates'] ?? null) ? $json['rates'] : [];
        ksort($daily);
        $values = [];
        foreach ($daily as $day => $r) {
            $v = isset($r['CHF']) ? (float) $r['CHF'] : 0.0;
            // Garde-fou : un taux hors de cette plage est une erreur de réponse, pas un taux
            if ($v < 0.5 || $v > 1.5) {
                return ['error' => 'Unexpected response'];
            }
            $values[$day] = $v;
        }
        if (count($values) < self::MIN_DAYS) {
            return ['error' => 'Incomplete response (' . count($values) . ' days)'];
        }
        $days = array_keys($values);
        return [
            'rate'   => array_sum($values) / count($values),
            'latest' => end($values),
            'date'   => sanitize_text_field((string) end($days)),
            'from'   => sanitize_text_field((string) $days[0]),
            'days'   => count($values),
        ];
    }

    /** Récupère et mémorise le taux ; $notify envoie l'e-mail à l'administrateur du site. */
    public static function run_fetch($notify) {
        $r = self::fetch();
        $previous = (array) get_option(self::OPT_FETCHED, []);
        if (!empty($r['error'])) {
            // On garde le dernier bon taux et on note l'échec
            $previous['error'] = $r['error'];
            $previous['error_t'] = time();
            update_option(self::OPT_FETCHED, $previous, false);
        } else {
            update_option(self::OPT_FETCHED, ['rate' => $r['rate'], 'latest' => $r['latest'], 'date' => $r['date'], 'from' => $r['from'], 'days' => $r['days'], 't' => time()], false);
        }
        if ($notify) {
            self::send_mail($r);
        }
        return $r;
    }

    public static function cron_fetch() {
        self::run_fetch(true);
    }

    private static function send_mail($r) {
        $to = get_option('admin_email');
        if (!$to) {
            return;
        }
        if (!empty($r['error'])) {
            wp_mail($to, __('EUR/CHF rate: monthly update failed', 'creation-reservoir'),
                sprintf(__('The monthly EUR/CHF rate could not be fetched (%s). The rate used in the calculations is unchanged.', 'creation-reservoir'), $r['error']));
            return;
        }
        $used = self::rate();
        $diff = $used > 0 ? ($r['rate'] / $used - 1) * 100 : 0;
        $settings = class_exists('ISPAG_Settings') ? ISPAG_Settings::url() : '';
        wp_mail(
            $to,
            sprintf(__('EUR/CHF 6-month average to %1$s: %2$s', 'creation-reservoir'), $r['date'], number_format($r['rate'], 4, '.', '')),
            sprintf(
                /* translators: 1: average rate, 2: first day, 3: last day, 4: number of days, 5: latest daily rate, 6: rate used in the calculations, 7: difference in %, 8: settings URL */
                __("Average of the daily ECB reference rates (via frankfurter.dev) over the last 6 months: 1 EUR = %1\$s CHF (%4\$s days, from %2\$s to %3\$s).\nLatest daily rate: %5\$s.\nRate used in the calculations: %6\$s (difference with the average: %7\$s %%).\nThe rate used has NOT been changed. To use the average, enter it in ISPAG Settings: %8\$s", 'creation-reservoir'),
                number_format($r['rate'], 4, '.', ''), $r['from'], $r['date'], $r['days'], number_format($r['latest'], 4, '.', ''), number_format($used, 4, '.', ''), number_format($diff, 1, '.', ''), $settings
            )
        );
    }

    public static function handle_fetch_now() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Access denied', 'creation-reservoir'));
        }
        check_admin_referer('ispag_fetch_eur_chf');
        self::run_fetch(false);
        wp_safe_redirect(class_exists('ISPAG_Settings') ? ISPAG_Settings::url() : admin_url());
        exit;
    }

    /** Bloc d'état affiché sous le réglage (ISPAG Settings, en anglais comme le reste de la page). */
    public static function render_status() {
        $f = (array) get_option(self::OPT_FETCHED, []);
        $h = '<p>';
        if (!empty($f['rate'])) {
            $h .= 'Proposed rate (average of the last 6 months): <strong>1 EUR = ' . esc_html(number_format((float) $f['rate'], 4, '.', '')) . ' CHF</strong> (ECB daily rates, ' . (int) ($f['days'] ?? 0) . ' days from ' . esc_html((string) ($f['from'] ?? '')) . ' to ' . esc_html((string) ($f['date'] ?? '')) . '; latest daily rate ' . esc_html(number_format((float) ($f['latest'] ?? 0), 4, '.', '')) . '; fetched ' . esc_html(wp_date('Y-m-d H:i', (int) ($f['t'] ?? 0))) . '). It is a proposal: enter it above to use it.';
        } else {
            $h .= 'No rate fetched yet. The site fetches the ECB rates every 30 days and proposes their average over the last 6 months (WordPress cron: it runs when the site is visited) and e-mails it to the site administrator.';
        }
        if (!empty($f['error'])) {
            $h .= '<br><span style="color:#d63638">Last attempt failed: ' . esc_html((string) $f['error']) . '</span>';
        }
        $url = wp_nonce_url(admin_url('admin-post.php?action=ispag_fetch_eur_chf'), 'ispag_fetch_eur_chf');
        $h .= ' <a class="button" href="' . esc_url($url) . '">Fetch the rate now</a></p>';
        return $h;
    }
}
