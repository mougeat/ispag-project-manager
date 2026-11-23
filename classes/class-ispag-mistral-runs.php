<?php
defined('ABSPATH') || exit;

/**
 * Journal détaillé des analyses Mistral (succès et échecs) + page de détail réservée aux administrateurs.
 *
 * Chaque analyse est conservée dans une option non chargée automatiquement (ispag_mistral_run_<task_id>) ;
 * un index (ispag_mistral_runs) garde les plus récentes. Rien n'est écrit dans un dossier accessible depuis le web.
 * La page s'ouvre via admin-ajax.php?action=ispag_mistral_run_detail (administrateur + nonce).
 */
class ISPAG_Mistral_Runs
{
    const INDEX_OPTION = 'ispag_mistral_runs';
    const MAX_RUNS     = 30;                 // nombre d'analyses conservées
    const MAX_AGE_DAYS = 30;                 // ancienneté maximale
    const MAX_RAW      = 300000;             // octets conservés pour la réponse brute

    public static function init()
    {
        add_action('wp_ajax_ispag_mistral_run_detail', [self::class, 'render_page']);
    }

    /** Tronque une chaîne à $max octets sans couper un caractère UTF-8. */
    private static function cap($text, $max = self::MAX_RAW): string
    {
        $text = (string) $text;
        if (strlen($text) <= $max) {
            return $text;
        }
        $cut = substr($text, 0, $max);
        if (function_exists('mb_strcut')) {
            $cut = mb_strcut($text, 0, $max, 'UTF-8');
        }
        return $cut . "\n… [" . sprintf('%d', strlen($text) - strlen($cut)) . " bytes cut]";
    }

    /** Enregistre une analyse et élague les anciennes. */
    public static function save($task_id, array $run): void
    {
        if ($task_id === '' || $task_id === null) {
            return;
        }
        foreach (['raw_response', 'cleaned_json'] as $k) {
            if (isset($run[$k])) {
                $run[$k] = self::cap($run[$k]);
            }
        }
        $run['task_id'] = $task_id;
        $run['saved_at'] = current_time('mysql');
        update_option('ispag_mistral_run_' . $task_id, $run, false);

        $index = get_option(self::INDEX_OPTION, []);
        $index = is_array($index) ? $index : [];
        $index = array_values(array_filter($index, function ($e) use ($task_id) {
            return ($e['task_id'] ?? '') !== $task_id;
        }));
        array_unshift($index, [
            'task_id' => $task_id,
            'at'      => $run['saved_at'],
            'ts'      => time(),
            'ok'      => !empty($run['ok']),
            'type'    => $run['type'] ?? '',
            'deal_id' => $run['deal_id'] ?? 0,
        ]);

        $limit = time() - self::MAX_AGE_DAYS * DAY_IN_SECONDS;
        $kept = [];
        foreach ($index as $i => $e) {
            if ($i < self::MAX_RUNS && (int) ($e['ts'] ?? 0) >= $limit) {
                $kept[] = $e;
            } else {
                delete_option('ispag_mistral_run_' . $e['task_id']);
            }
        }
        update_option(self::INDEX_OPTION, $kept, false);
    }

    public static function get($task_id)
    {
        $run = get_option('ispag_mistral_run_' . $task_id);
        return is_array($run) ? $run : null;
    }

    /** Adresse de la page de détail d'une analyse (ou de la liste si $task_id est vide). */
    public static function detail_url($task_id = ''): string
    {
        $args = ['action' => 'ispag_mistral_run_detail'];
        if ($task_id !== '') {
            $args['task_id'] = $task_id;
        }
        $nonce_action = $task_id !== '' ? 'ispag_mistral_run_' . $task_id : 'ispag_mistral_runs';
        // Pas de wp_nonce_url() : il échappe les « & » en « &amp; » (HTML), ce qui casse l'adresse quand elle est utilisée telle quelle
        // (JavaScript, window.open). L'échappement se fait à l'affichage (esc_url).
        $args['_wpnonce'] = wp_create_nonce($nonce_action);
        return add_query_arg($args, admin_url('admin-ajax.php'));
    }

    private static function e($v): string
    {
        return esc_html(is_scalar($v) || $v === null ? (string) $v : wp_json_encode($v, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }

    public static function render_page()
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to see this page.', 'creation-reservoir'), '', ['response' => 403]);
        }
        $task_id = sanitize_text_field(wp_unslash($_GET['task_id'] ?? ''));
        $nonce   = sanitize_text_field(wp_unslash($_GET['_wpnonce'] ?? ''));
        $action  = $task_id !== '' ? 'ispag_mistral_run_' . $task_id : 'ispag_mistral_runs';
        if (!wp_verify_nonce($nonce, $action)) {
            wp_die(esc_html__('This link has expired. Open the analysis again.', 'creation-reservoir'), '', ['response' => 403]);
        }

        nocache_headers();
        header('Content-Type: text/html; charset=utf-8');
        header('X-Robots-Tag: noindex');

        echo '<!doctype html><html><head><meta charset="utf-8"><meta name="robots" content="noindex">';
        echo '<title>' . esc_html__('Mistral analysis detail', 'creation-reservoir') . '</title>';
        echo '<style>body{font:14px/1.5 system-ui,sans-serif;margin:24px;color:#222}h1{font-size:20px}h2{font-size:16px;margin-top:28px}'
            . 'table{border-collapse:collapse}td,th{border:1px solid #ddd;padding:6px 10px;text-align:left;vertical-align:top}'
            . 'th{background:#f5f5f5;white-space:nowrap}pre{background:#f7f7f7;border:1px solid #ddd;padding:12px;overflow:auto;max-height:520px;white-space:pre-wrap;word-break:break-word}'
            . '.ok{color:#1a7f37;font-weight:600}.ko{color:#b32d2e;font-weight:600}</style></head><body>';

        if ($task_id === '') {
            self::render_list();
        } else {
            self::render_run($task_id);
        }
        echo '</body></html>';
        exit;
    }

    private static function render_list(): void
    {
        $index = get_option(self::INDEX_OPTION, []);
        echo '<h1>' . esc_html__('Latest Mistral analyses', 'creation-reservoir') . '</h1>';
        if (!is_array($index) || !$index) {
            echo '<p>' . esc_html__('No analysis recorded yet.', 'creation-reservoir') . '</p>';
            return;
        }
        echo '<table><tr><th>' . esc_html__('Date', 'creation-reservoir') . '</th><th>' . esc_html__('Result', 'creation-reservoir')
            . '</th><th>' . esc_html__('Type', 'creation-reservoir') . '</th><th>' . esc_html__('Project', 'creation-reservoir') . '</th><th></th></tr>';
        foreach ($index as $e) {
            echo '<tr><td>' . self::e($e['at'] ?? '') . '</td><td class="' . (!empty($e['ok']) ? 'ok' : 'ko') . '">'
                . esc_html(!empty($e['ok']) ? __('Success', 'creation-reservoir') : __('Failed', 'creation-reservoir')) . '</td><td>'
                . self::e($e['type'] ?? '') . '</td><td>' . self::e($e['deal_id'] ?? '') . '</td><td><a href="'
                . esc_url(self::detail_url($e['task_id'])) . '">' . esc_html__('See the detail', 'creation-reservoir') . '</a></td></tr>';
        }
        echo '</table>';
    }

    private static function render_run($task_id): void
    {
        $run = self::get($task_id);
        echo '<h1>' . esc_html__('Mistral analysis detail', 'creation-reservoir') . '</h1>';
        echo '<p><a href="' . esc_url(self::detail_url()) . '">← ' . esc_html__('Latest Mistral analyses', 'creation-reservoir') . '</a></p>';
        if (!$run) {
            echo '<p>' . esc_html__('This analysis is no longer available (kept 30 days, last 30 analyses).', 'creation-reservoir') . '</p>';
            return;
        }

        $rows = [
            __('Result', 'creation-reservoir')            => !empty($run['ok']) ? __('Success', 'creation-reservoir') : __('Failed', 'creation-reservoir'),
            __('Step that failed', 'creation-reservoir')  => $run['stage'] ?? '',
            __('Cause', 'creation-reservoir')             => $run['error'] ?? '',
            __('Date', 'creation-reservoir')              => $run['saved_at'] ?? '',
            __('Duration (s)', 'creation-reservoir')      => $run['duration_s'] ?? '',
            __('Type', 'creation-reservoir')              => $run['type'] ?? '',
            __('Agent', 'creation-reservoir')             => $run['agent_id'] ?? '',
            __('Project', 'creation-reservoir')           => $run['deal_id'] ?? '',
            __('Document', 'creation-reservoir')          => $run['file_id'] ?? '',
            __('HTTP code', 'creation-reservoir')         => $run['http_code'] ?? '',
            __('Finish reason', 'creation-reservoir')     => $run['finish_reason'] ?? '',
            __('Tokens (prompt / answer / total)', 'creation-reservoir') => isset($run['usage']) && is_array($run['usage'])
                ? ($run['usage']['prompt_tokens'] ?? '?') . ' / ' . ($run['usage']['completion_tokens'] ?? '?') . ' / ' . ($run['usage']['total_tokens'] ?? '?') : '',
            __('Prompt length (characters)', 'creation-reservoir') => $run['prompt_length'] ?? '',
            __('Answer length (characters)', 'creation-reservoir') => $run['answer_length'] ?? '',
            __('JSON error', 'creation-reservoir')        => $run['json_error'] ?? '',
            __('Task', 'creation-reservoir')              => $task_id,
        ];
        echo '<table>';
        foreach ($rows as $label => $val) {
            if ($val === '' || $val === null) {
                continue;
            }
            echo '<tr><th>' . esc_html($label) . '</th><td>' . self::e($val) . '</td></tr>';
        }
        echo '</table>';

        $blocks = [
            'api_message'  => __('Message returned by the Mistral API', 'creation-reservoir'),
            'cleaned_json' => __('JSON after cleaning', 'creation-reservoir'),
            'raw_response' => __('Raw HTTP response', 'creation-reservoir'),
        ];
        foreach ($blocks as $key => $title) {
            if (!empty($run[$key])) {
                echo '<h2>' . esc_html($title) . '</h2><pre>' . self::e($run[$key]) . '</pre>';
            }
        }
    }
}
