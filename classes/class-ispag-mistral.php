<?php
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

        $result = self::run_actual_mistral_request($content, $type, $file_id);
        $external_user_id = $user_id;

        // Plus besoin de chercher dans $_POST['deal_id'] qui est vide, on utilise la variable reçue du Cron !

        if ($result) {
            update_option('status_' . $task_id, [
                'status' => 'completed',
                'result' => $result,
                'completed_at' => current_time('mysql')
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
                'error' => 'Timeout, empty response or invalid JSON from Mistral',
                'failed_at' => current_time('mysql')
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
     * ÉTAPE 3 : Requête directe vers l'API Mistral (avec timeout allongé à 5min).
     */
    private static function run_actual_mistral_request($content, $type = 'purchase', $file_id = null)
    {
        $user_id = get_current_user_id();
        self::$logger->log_user_action('mistral', 'send_to_mistral_start', ['type' => $type, 'file_id' => $file_id], $user_id);

        self::log("--- REQUETE MISTRAL ($type) ---");

        if (empty(self::$api_key))
        {
            self::$logger->log('mistral', 'ERROR: API key is empty', $user_id);
            return null;
        }

        $agent_ids = [
            'analyse_complete' => 'ag_019c21fca53276b38f0bbf0f9fe734ed',
            'project' => 'ag_019c21fca53276b38f0bbf0f9fe734ed',
            'purchase' => 'ag_019c2206aaff774a9b5d852b491285ef',
            'product_drawing' => 'ag_019c3364992976279a1e85b9d0a2b840',
            'drawing_approval_control' => 'ag_01a0906263127738b81866b0077a512f',
            'tank_data_extractor' => 'ag_019c3df9f301711eadf27ffcdc91ce41',
            'invoice_analyse' => 'ag_019f1c03b4f8774f86c434894a66b7c5',
        ];

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

        $content .= 'Langue de réponse :' . $lang;
        self::$logger->log_user_action('mistral', 'language_appended_to_content', [], $user_id);

        $agent_id = $agent_ids[$type] ?? 'ag_019c21fca53276b38f0bbf0f9fe734ed';
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
            return null;
        }

        $body_raw = wp_remote_retrieve_body($response);
        self::log("DEBUG RAW RESPONSE FROM MISTRAL:\n" . $body_raw);
        self::$logger->log_user_action('mistral', 'raw_response_received', ['body_length' => strlen($body_raw)], $user_id);

        $body = json_decode($body_raw, true);
        $content_ai = $body['choices'][0]['message']['content'] ?? '';

        if (empty($content_ai)) 
        {
            self::$logger->log('mistral', 'ERROR: Empty AI response', $user_id);
            return null;
        }

        self::$logger->log_user_action('mistral', 'ai_content_received', ['content_length' => strlen($content_ai)], $user_id);

        // Nettoyage agressif
        $cleaned = preg_replace('/^```json\s+/i', '', $content_ai);$cleaned = preg_replace('/\s+```$/', '', $cleaned);
        $cleaned = preg_replace('!/\*.*?\*/!s', '', $cleaned);
        $cleaned = preg_replace('/(?<!:)\/\/.*/', '', $cleaned);
        $cleaned = trim($cleaned);

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
                return null;
            }
        }

        self::$logger->log_user_action('mistral', 'json_decoded_successfully', [], $user_id);
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