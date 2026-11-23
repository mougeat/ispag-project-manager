<?php
defined('ABSPATH') || exit;
/**
 * Class ISPAG_Mistral
 * Gère les interactions avec l'API Mistral pour l'analyse de documents et données.
 * Logging : Toutes les actions sont loguées dans ispag_mistral.log.
 */
class ISPAG_Mistral
{
    private static $api_key;
    private static $api_url_agent = 'https://api.mistral.ai/v1/agents/completions';

    /** @var ISPAG_Logger Instance du logger. */
    private static $logger;

    public static function init()
    {
        self::$api_key = ISPAG_Settings::mistral_api_key();
        self::$logger = ISPAG_Logger::get_instance();
        $user_id = get_current_user_id();
        // self::$logger->log_user_action('mistral', 'class_initialized', [], $user_id);

        // On intercepte les demandes d'analyses via le filtre d'origine
        add_filter('ispag_send_to_mistral', [self::class, 'send_to_mistral'], 10, 4);

        // L'action asynchrone qui sera exécutée par le Cron WordPress
        add_action('ispag_process_mistral_async', [self::class, 'execute_async_analysis'], 10, 6);

        // On expose l'action AJAX à WordPress pour les utilisateurs connectés
        add_action('wp_ajax_check_analysis_status', [self::class, 'ajax_check_analysis_status']);

        // Journal détaillé des analyses + page de détail (administrateurs)
        ISPAG_Mistral_Runs::init();
    }

    /** @var array|null Analyse en cours (pour le garde-fou de fin de requête). */
    private static $guard = null;

    /** Fin de requête PHP sans que l'analyse ait écrit son résultat : on la marque « échouée » avec la dernière erreur PHP. */
    public static function shutdown_guard()
    {
        if (!self::$guard) {
            return;
        }
        $g = self::$guard;
        self::$guard = null;
        $last = error_get_last();
        $detail = $last ? ($last['message'] ?? '') : '';
        $msg = __('The analysis stopped unexpectedly (PHP error or time limit exceeded).', 'creation-reservoir');
        update_option('status_' . $g['task_id'], [
            'status' => 'failed',
            'error' => $msg . ($detail !== '' ? ' ' . $detail : ''),
            'failed_at' => current_time('mysql'),
            'user_id' => $g['user_id'],
        ]);
        try {
            $run = self::$diag;
            $run['ok'] = false;
            $run['stage'] = 'php_stopped';
            $run['error'] = $msg . ($detail !== '' ? ' ' . $detail : '');
            $run['type'] = $g['type'];
            $run['deal_id'] = $g['deal_id'];
            $run['duration_s'] = round(microtime(true) - $g['started'], 1);
            ISPAG_Mistral_Runs::save($g['task_id'], $run);
        } catch (\Throwable $e) {
            // rien de plus à faire : l'état « échoué » est déjà écrit
        }
    }

    /** @var array Diagnostic de la dernière requête (étape, code HTTP, finish_reason, tokens…), lu par execute_async_analysis. */
    private static $diag = [];

    /** Message d'échec précis, selon l'étape où la requête a échoué. */
    private static function failure_message(array $d): string
    {
        switch ($d['stage'] ?? '') {
            case 'no_api_key':
                return __('Mistral API key is missing in the settings.', 'creation-reservoir');
            case 'network':
                return sprintf(__('Could not reach Mistral: %s', 'creation-reservoir'), $d['error'] ?? '');
            case 'http_status':
                return sprintf(__('Mistral answered with HTTP error %s.', 'creation-reservoir'), $d['http_code'] ?? '?')
                    . (!empty($d['api_message']) ? ' ' . $d['api_message'] : '');
            case 'empty_answer':
                return __('Mistral returned no answer text.', 'creation-reservoir');
            case 'invalid_json':
                if (($d['finish_reason'] ?? '') === 'length') {
                    return __('The answer was cut off (length limit reached), so the JSON is incomplete.', 'creation-reservoir');
                }
                return sprintf(__('The answer is not valid JSON (%s).', 'creation-reservoir'), $d['json_error'] ?? '');
        }
        return __('Unknown error while contacting Mistral.', 'creation-reservoir');
    }

    /**
     * Log un message dans le fichier de log.
     */
    private static function log($message, $data = null)
    {
        $user_id = get_current_user_id();
        $context = [];
        if ($data !== null)
        {
            $context['data'] = is_scalar($data) ? $data : print_r($data, true);
        }
        self::$logger->log('mistral', $message, $user_id, $context);
    }

    /**
     * POINT D'ENTRÉE COMPATIBLE (Restauration de la méthode originale)
     * Cette méthode sert de passerelle : elle intercepte l'appel, planifie l'asynchrone 
     * et évite que ton code existant (ou tes filtres) ne crashe.
     */
    public static function send_to_mistral($html = null, $content = '', $type = 'purchase', $file_id = null)
    {
        // On redirige immédiatement vers la planification asynchrone
        return self::queue_mistral_analysis($html, $content, $type, $file_id);
    }

    /**
     * ÉTAPE 1 : Modifiée pour capturer l'ID de l'utilisateur qui lance l'analyse
     */
    public static function queue_mistral_analysis($html = null, $content = '', $type = 'purchase', $file_id = null)
    {
        $user_id = get_current_user_id() ?: 1;
        $deal_id = isset($_POST['deal_id']) ? intval($_POST['deal_id']) : 0; // <-- Récupération immédiate dans la requête AJAX principale

        $content = !empty($content) ? $content : 'Analyse standard';
        $file_id = !empty($file_id) ? $file_id : '';
        $type    = !empty($type) ? $type : 'purchase';

        $task_id = uniqid('mistral_');

        self::$logger->log_user_action('mistral', 'queue_analysis_start', [
            'task_id' => $task_id, 
            'type' => $type, 
            'file_id' => $file_id
        ], $user_id);

        // --- CORRECTION : On ajoute le $deal_id à la fin du tableau des arguments pour le Cron ---
        wp_schedule_single_event(time(), 'ispag_process_mistral_async', [$task_id, $content, $type, $file_id, $user_id, $deal_id]);

        update_option('status_' . $task_id, [
            'status' => 'processing',
            'created_at' => current_time('mysql'),
            'user_id' => $user_id
        ]);

        return [
            'async' => true,
            'task_id' => $task_id,
            'status' => 'processing'
        ];
    }

    /**
     * ÉTAPE 2 : Modifiée pour envoyer la notification OneSignal push à la fin
     */
    public static function execute_async_analysis($task_id = null, $content = '', $type = 'purchase', $file_id = null, $user_id = 1, $deal_id = 0)
    {
        if (empty($task_id)) {
            self::log("CRITICAL: execute_async_analysis appelé sans task_id");
            return;
        }
        self::log("[execute_async_analysis]]: type reçu : {$type}");
        if ($user_id > 0) {
            wp_set_current_user($user_id);
        }

        self::$diag = [];
        $started = microtime(true);

        // Garde-fou : si PHP s'arrête en cours de route (erreur fatale, durée maximale dépassée), l'état passe quand même à « échoué »
        // au lieu de rester « en cours » (l'écran tournerait alors sans fin).
        self::$guard = ['task_id' => $task_id, 'user_id' => (int) $user_id, 'type' => $type, 'deal_id' => (int) $deal_id, 'started' => $started];
        register_shutdown_function([self::class, 'shutdown_guard']);

        $result = self::run_actual_mistral_request($content, $type, $file_id);
        $external_user_id = $user_id;

        // Journal détaillé (succès et échec) : consultable par les administrateurs
        $run = self::$diag;
        $run['ok']            = (bool) $result;
        $run['type']          = $type;
        $run['file_id']       = is_string($file_id) ? $file_id : '';
        $run['deal_id']       = (int) $deal_id;
        $run['user_id']       = (int) $user_id;
        $run['prompt_length'] = strlen((string) $content);
        $run['duration_s']    = round(microtime(true) - $started, 1);
        if (!$result) {
            $run['error'] = self::failure_message($run);
        }

        // L'état est écrit AVANT le journal : un souci d'enregistrement du journal ne doit jamais laisser l'écran en attente
        update_option('status_' . $task_id, $result ? [
            'status' => 'completed',
            'result' => $result,
            'completed_at' => current_time('mysql'),
            'user_id' => (int) $user_id,
        ] : [
            'status' => 'failed',
            'error' => $run['error'],
            'failed_at' => current_time('mysql'),
            'user_id' => (int) $user_id,
        ]);
        self::$guard = null;

        try {
            ISPAG_Mistral_Runs::save($task_id, $run);
        } catch (\Throwable $e) {
            self::log('ERROR: journal de l\'analyse non enregistré - ' . $e->getMessage());
        }

        // Plus besoin de chercher dans $_POST['deal_id'] qui est vide, on utilise la variable reçue du Cron !

        if ($result) {
            update_option('status_' . $task_id, [
                'status' => 'completed',
                'result' => $result,
                'completed_at' => current_time('mysql'),
                'user_id' => (int) $user_id,
            ]);

            $tank_saving = '';
            if (class_exists('ISPAG_Document_Analyser') AND $type == 'project') {
                $analyser = new ISPAG_Document_Analyser();
                // --- Le $deal_id possède maintenant sa vraie valeur (ex: 1770889365) ---
                $analyser->auto_save_extracted_data($result, $deal_id, $type);
                $tank_saving = __('The tanks and equipment have been added.', 'creation-reservoir');
            }

            if (class_exists('ISPAG_Notifications_Manager')) {
                // Nettoyage de l'ID utilisateur si besoin (retrait éventuel du préfixe 'WP_')
                $target_user_id = $external_user_id;
                if (is_string($target_user_id) && strpos($target_user_id, 'WP_') === 0) {
                    $target_user_id = intval(str_replace('WP_', '', $target_user_id));
                }

                ISPAG_Notifications_Manager::send(
                    [$target_user_id, 1],
                    'document_analysis', // Type de notification
                    "🤖 " . __('AI analysis complete', 'creation-reservoir') . " !",
                    __('The document has been successfully processed.', 'creation-reservoir') . ' ' . $tank_saving,
                    "project-detail/" . $deal_id, // URL combinée
                    $deal_id
                );
            }
        } else {
            // Log & Push notification d'échec standard...
            update_option('status_' . $task_id, [
                'status' => 'failed',
                'error' => $run['error'],
                'failed_at' => current_time('mysql'),
                'user_id' => (int) $user_id,
            ]);
            if (class_exists('ISPAG_Notifications_Manager')) {
                // Nettoyage de l'ID utilisateur si besoin (retrait éventuel du préfixe 'WP_')
                $target_user_id = $external_user_id;
                if (is_string($target_user_id) && strpos($target_user_id, 'WP_') === 0) {
                    $target_user_id = intval(str_replace('WP_', '', $target_user_id));
                }

                ISPAG_Notifications_Manager::send(
                    [$target_user_id, 1],
                    'document_analysis', // Type de notification
                    "⚠️ " . __('Analysis failed', 'creation-reservoir'),
                    __('The AI ​​was unable to extract the data from the document. Please try again.', 'creation-reservoir'),
                    "project-detail/" . $deal_id, // URL de redirection
                    $deal_id
                );
            }
        }
    }

    /**
     * Agents Mistral par type de demande (type => identifiant). Leurs instructions sont dans Mistral AI Studio, pas dans le code :
     * ISPAG Settings → ISPAG AI → « Mistral agents » les liste avec leur situation d'appel.
     */
    public static function agents(): array
    {
        return [
            'analyse_complete' => 'ag_019c21fca53276b38f0bbf0f9fe734ed',
            'project' => 'ag_019c21fca53276b38f0bbf0f9fe734ed',
            'purchase' => 'ag_019c2206aaff774a9b5d852b491285ef',
            'product_drawing' => 'ag_019c3364992976279a1e85b9d0a2b840',
            'drawing_approval_control' => 'ag_01a0906263127738b81866b0077a512f',
            'tank_data_extractor' => 'ag_019c3df9f301711eadf27ffcdc91ce41',
            'invoice_analyse' => 'ag_019f1c03b4f8774f86c434894a66b7c5',
        ];
    }

    /**
     * ÉTAPE 3 : Requête directe vers l'API Mistral (avec timeout allongé à 5min).
     */
    private static function run_actual_mistral_request($content, $type = 'purchase', $file_id = null)
    {
        $user_id = get_current_user_id();
        self::$logger->log_user_action('mistral', 'send_to_mistral_start', ['type' => $type, 'file_id' => $file_id], $user_id);

        self::log("--- REQUETE MISTRAL ($type) ---");

        self::$diag = ['agent_id' => '', 'stage' => 'start'];

        if (empty(self::$api_key))
        {
            self::$logger->log('mistral', 'ERROR: API key is empty', $user_id);
            self::$diag['stage'] = 'no_api_key';
            return null;
        }

        $agent_ids = self::agents();

        self::$logger->log_user_action('mistral', 'agent_ids_loaded', ['count' => count($agent_ids)], $user_id);

        // Détermination de la langue
        if (function_exists('get_locale'))
        {
            $lang = substr(get_locale(), 0, 2);
            self::$logger->log_user_action('mistral', 'language_from_locale', ['lang' => $lang], $user_id);
        }
        elseif (isset($_SERVER['HTTP_ACCEPT_LANGUAGE']))
        {
            $lang = substr($_SERVER['HTTP_ACCEPT_LANGUAGE'], 0, 2);
            self::$logger->log_user_action('mistral', 'language_from_browser', ['lang' => $lang], $user_id);
        }
        else
        {
            $lang = 'fr';
            self::$logger->log_user_action('mistral', 'default_language_used', ['lang' => $lang], $user_id);
        }

        // Texte modifiable : ISPAG Settings → ISPAG AI (« Answer language »). Ajouté tel quel à la suite du message.
        $content .= ISPAG_Ai_Prompts::get('pm_language', 'main', ['lang' => $lang]);
        self::$logger->log_user_action('mistral', 'language_appended_to_content', [], $user_id);

        $agent_id = $agent_ids[$type] ?? 'ag_019c21fca53276b38f0bbf0f9fe734ed';
        self::$diag['agent_id'] = $agent_id;
        self::$logger->log_user_action('mistral', 'agent_selected', ['type' => $type, 'agent_id' => $agent_id], $user_id);

        self::log("--- Contenu du prompt ($type) ---");

        // Construction du payload
        if (!empty($file_id))
        {
            $message_content = [
                [
                    "type" => "text",
                    "text" => $content
                ],
                [
                    "type" => "document_url",
                    "document_url" => $file_id
                ]
            ];
            self::$logger->log_user_action('mistral', 'payload_with_file', ['file_id' => $file_id], $user_id);
        }
        else
        {
            $message_content = $content;
            self::$logger->log_user_action('mistral', 'payload_without_file', [], $user_id);
        }

        $payload = [
            'agent_id' => $agent_id,
            'messages' => [
                [
                    'role' => 'user',
                    'content' => $message_content
                ]
            ]
        ];

        self::$logger->log_user_action('mistral', 'request_payload_prepared', ['agent_id' => $agent_id], $user_id);

        $response = wp_remote_post(self::$api_url_agent, [
            'method' => 'POST',
            'timeout' => 600, // 5 minutes autorisées en tâche de fond
            'headers' => [
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . trim(self::$api_key)
            ],
            'body' => json_encode($payload),
        ]);

        if (is_wp_error($response))
        {
            $error_message = $response->get_error_message();
            self::$logger->log('mistral', 'ERROR: WP Remote request failed - ' . $error_message, $user_id);
            self::$diag['stage'] = 'network';
            self::$diag['error'] = $error_message;
            return null;
        }

        $body_raw = wp_remote_retrieve_body($response);
        self::log("DEBUG RAW RESPONSE FROM MISTRAL:\n" . $body_raw);
        self::$logger->log_user_action('mistral', 'raw_response_received', ['body_length' => strlen($body_raw)], $user_id);

        $http_code = (int) wp_remote_retrieve_response_code($response);
        $body = json_decode($body_raw, true);
        $content_ai = $body['choices'][0]['message']['content'] ?? '';

        self::$diag['http_code']     = $http_code;
        self::$diag['finish_reason'] = $body['choices'][0]['finish_reason'] ?? '';
        self::$diag['usage']         = is_array($body['usage'] ?? null) ? $body['usage'] : [];
        self::$diag['raw_response']  = $body_raw;
        // Message d'erreur de l'API (formats possibles : message, error.message, detail)
        $api_msg = $body['message'] ?? ($body['error']['message'] ?? ($body['detail'] ?? ''));
        if ($api_msg !== '') {
            self::$diag['api_message'] = is_scalar($api_msg) ? (string) $api_msg : wp_json_encode($api_msg, JSON_UNESCAPED_UNICODE);
        }

        // Le contenu peut être une liste de blocs : on concatène les textes
        if (is_array($content_ai)) {
            $parts = [];
            foreach ($content_ai as $chunk) {
                if (is_array($chunk) && isset($chunk['text'])) {
                    $parts[] = $chunk['text'];
                }
            }
            $content_ai = implode('', $parts);
        }

        if ($http_code >= 400 || $http_code === 0)
        {
            self::$logger->log('mistral', 'ERROR: HTTP ' . $http_code, $user_id);
            self::$diag['stage'] = 'http_status';
            return null;
        }

        if (empty($content_ai)) 
        {
            self::$logger->log('mistral', 'ERROR: Empty AI response', $user_id);
            self::$diag['stage'] = 'empty_answer';
            return null;
        }
        self::$diag['answer_length'] = strlen($content_ai);

        self::$logger->log_user_action('mistral', 'ai_content_received', ['content_length' => strlen($content_ai)], $user_id);

        // Nettoyage agressif
        $cleaned = preg_replace('/^```json\s+/i', '', $content_ai);$cleaned = preg_replace('/\s+```$/', '', $cleaned);
        $cleaned = preg_replace('!/\*.*?\*/!s', '', $cleaned);
        $cleaned = preg_replace('/(?<!:)\/\/.*/', '', $cleaned);
        $cleaned = trim($cleaned);

        self::$diag['cleaned_json'] = $cleaned;
        self::log("JSON NETTOYÉ:\n" . $cleaned);
        self::$logger->log_user_action('mistral', 'json_cleaned', ['cleaned_length' => strlen($cleaned)], $user_id);

        $extracted_data = json_decode($cleaned, true);

        if (json_last_error() !== JSON_ERROR_NONE)
        {
            $error_msg = json_last_error_msg();
            self::log("ERREUR FINALE JSON: " . $error_msg);
            self::$logger->log('mistral', 'ERROR: JSON decode failed - ' . $error_msg, $user_id, ['cleaned' => substr($cleaned, 0, 200)]);

            // Tentative de secours : enlever les virgules traînantes
            $cleaned = preg_replace('/,\s*([\]}])/', '$1', $cleaned);
            $extracted_data = json_decode($cleaned, true);

            if (json_last_error() !== JSON_ERROR_NONE)
            {
                self::$logger->log('mistral', 'ERROR: JSON decode failed after cleanup - ' . json_last_error_msg(), $user_id);
                self::$diag['stage'] = 'invalid_json';
                self::$diag['json_error'] = json_last_error_msg();
                self::$diag['cleaned_json'] = $cleaned;
                return null;
            }
        }

        if (!is_array($extracted_data))
        {
            // JSON valide mais pas un objet/tableau (ex. « null », un nombre) : inexploitable
            self::$diag['stage'] = 'invalid_json';
            self::$diag['json_error'] = 'not an object';
            return null;
        }

        self::$logger->log_user_action('mistral', 'json_decoded_successfully', [], $user_id);
        self::$diag['stage'] = 'ok';
        return $extracted_data;
    }

    /**
     * ÉTAPE 4 : Point d'accès REST/AJAX pour interroger l'état d'avancement d'une tâche
     */
    public static function check_analysis_status($task_id)
    {
        $data = get_option('status_' . $task_id);
        
        if (!$data) {
            return ['status' => 'not_found'];
        }

        // Lien vers le détail de l'analyse (administrateurs seulement ; le lien est généré ici, dans la requête de l'utilisateur)
        if (($data['status'] === 'completed' || $data['status'] === 'failed') && current_user_can('manage_options')) {
            $data['detail_url'] = ISPAG_Mistral_Runs::detail_url($task_id);
        }

        // Si l'analyse est terminée, on supprime l'option pour éviter de saturer la table wp_options
        if ($data['status'] === 'completed' || $data['status'] === 'failed') {
            delete_option('status_' . $task_id); 
        }

        return $data;
    }

    /**
     * Endpoint AJAX exposé à WordPress pour le JavaScript
     */
    public static function ajax_check_analysis_status()
    {
        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => 'Unauthorized'], 403);
        }

        $task_id = sanitize_text_field($_POST['task_id'] ?? '');

        if (empty($task_id)) {
            wp_send_json_error(['message' => 'Missing task_id'], 400);
        }

        $status_data = self::check_analysis_status($task_id);
        wp_send_json_success($status_data);
    }
}