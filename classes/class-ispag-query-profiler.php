<?php
defined('ABSPATH') || exit;

/**
 * Journal TEMPORAIRE du nombre de requêtes SQL par page / appel ajax / cron.
 * Sert à repérer ce qui fait dépasser la limite de requêtes de l'hébergeur (OVH).
 *
 * Activation : « ISPAG Settings → Journal des requêtes » (2 à 72 h). Il se coupe tout seul à l'échéance
 * (ou si le fichier dépasse 5 Mo). Une ligne par requête HTTP dans uploads/ispag_logs/ispag_query_profile.log.
 * Coût : un get_num_queries() et une ligne de log en fin de requête ; aucune requête SQL en plus.
 */
class ISPAG_Query_Profiler {

    const OPT_UNTIL = 'ispag_query_log_until';
    const PAGE      = 'ispag-query-profile';
    const MAX_BYTES = 5242880;

    private static $until = null;

    public static function init() {
        add_action('shutdown', [__CLASS__, 'record'], 9999);
        add_action('admin_menu', [__CLASS__, 'menu'], 99);
        add_action('admin_post_ispag_query_log', [__CLASS__, 'handle_post']);
    }

    private static function active() {
        if (self::$until === null) self::$until = (int) get_option(self::OPT_UNTIL, 0);
        return self::$until > time();
    }

    private static function file() {
        $up = wp_upload_dir();
        return trailingslashit($up['basedir']) . 'ispag_logs/ispag_query_profile.log';
    }

    /** Ce qui identifie la requête : appel ajax (action), cron, ou chemin de la page (+ ?page= en administration). */
    private static function key() {
        if (defined('DOING_CRON') && DOING_CRON) return 'cron';
        if (wp_doing_ajax()) return 'ajax:' . sanitize_key($_REQUEST['action'] ?? '?');
        $path = (string) wp_parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
        $page = isset($_GET['page']) ? '?page=' . sanitize_key($_GET['page']) : '';
        return ($_SERVER['REQUEST_METHOD'] ?? 'GET') . ' ' . $path . $page;
    }

    public static function record() {
        if (!self::active()) return;
        $file = self::file();
        if (!is_dir(dirname($file))) return;
        if (is_file($file) && filesize($file) > self::MAX_BYTES) return;
        $line = sprintf("%s\t%d\t%.3f\t%d\t%s\n", gmdate('c'), get_num_queries(), timer_stop(0, 3), get_current_user_id(), self::key());
        @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
    }

    // ------------------------------------------------------------------ administration

    public static function menu() {
        if (class_exists('ISPAG_Settings')) {
            add_submenu_page(ISPAG_Settings::PAGE, 'Journal des requêtes', 'Journal des requêtes', 'manage_options', self::PAGE, [__CLASS__, 'render']);
        }
    }

    public static function handle_post() {
        if (!current_user_can('manage_options')) wp_die('Forbidden', '', ['response' => 403]);
        check_admin_referer('ispag_query_log');
        $do = sanitize_key($_POST['do'] ?? '');
        if ($do === 'start') {
            $h = max(1, min(72, (int) ($_POST['hours'] ?? 24)));
            update_option(self::OPT_UNTIL, time() + $h * HOUR_IN_SECONDS, false);
        } elseif ($do === 'stop') {
            update_option(self::OPT_UNTIL, 0, false);
        } elseif ($do === 'clear') {
            @unlink(self::file());
        }
        wp_safe_redirect(admin_url('admin.php?page=' . self::PAGE));
        exit;
    }

    /** @return array<string,array{n:int,q:int,max:int,t:float}> cumul par clé, du plus coûteux au moins coûteux */
    private static function aggregate() {
        $file = self::file();
        $agg = [];
        if (!is_file($file)) return $agg;
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $l) {
            $p = explode("\t", $l);
            if (count($p) < 5) continue;
            $k = $p[4];
            $agg[$k] = $agg[$k] ?? ['n' => 0, 'q' => 0, 'max' => 0, 't' => 0.0];
            $agg[$k]['n']++;
            $agg[$k]['q'] += (int) $p[1];
            $agg[$k]['max'] = max($agg[$k]['max'], (int) $p[1]);
            $agg[$k]['t'] += (float) $p[2];
        }
        uasort($agg, function ($a, $b) { return $b['q'] <=> $a['q']; });
        return $agg;
    }

    public static function render() {
        if (!current_user_can('manage_options')) return;
        $until = (int) get_option(self::OPT_UNTIL, 0);
        $on = $until > time();
        $agg = self::aggregate();
        $total = array_sum(array_column($agg, 'q'));
        $calls = array_sum(array_column($agg, 'n'));
        $post = function ($do, $label, $extra = '') {
            return '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block;margin-right:8px">'
                . wp_nonce_field('ispag_query_log', '_wpnonce', true, false) . '<input type="hidden" name="action" value="ispag_query_log"><input type="hidden" name="do" value="' . esc_attr($do) . '">'
                . $extra . '<button class="button">' . esc_html($label) . '</button></form>';
        };
        echo '<div class="wrap"><h1>Journal des requêtes SQL (temporaire)</h1>';
        echo '<p>Compte les requêtes SQL de chaque page, appel ajax et tâche cron. Statut : <strong>' . ($on ? 'actif jusqu\'au ' . esc_html(wp_date('d.m.Y H:i', $until)) : 'arrêté') . '</strong>. Coupé automatiquement à l\'échéance ou à 5 Mo.</p>';
        if (!$on) echo $post('start', 'Activer', '<select name="hours"><option value="2">2 h</option><option value="24" selected>24 h</option><option value="72">72 h</option></select> ');
        else echo $post('stop', 'Arrêter');
        echo $post('clear', 'Effacer le journal');
        echo '<h2>Classement par nombre total de requêtes</h2><p>' . (int) $calls . ' appels enregistrés, ' . (int) $total . ' requêtes au total.</p>';
        echo '<table class="widefat striped"><thead><tr><th>Page / appel</th><th>Appels</th><th>Requêtes (total)</th><th>Part</th><th>Moyenne</th><th>Max</th><th>Temps moyen</th></tr></thead><tbody>';
        foreach (array_slice($agg, 0, 40, true) as $k => $r) {
            printf('<tr><td><code>%s</code></td><td>%d</td><td>%d</td><td>%s %%</td><td>%d</td><td>%d</td><td>%s s</td></tr>',
                esc_html($k), $r['n'], $r['q'], $total ? round(100 * $r['q'] / $total) : 0, round($r['q'] / max(1, $r['n'])), $r['max'], number_format($r['t'] / max(1, $r['n']), 2));
        }
        if (!$agg) echo '<tr><td colspan="7">Aucune donnée.</td></tr>';
        echo '</tbody></table></div>';
    }
}
