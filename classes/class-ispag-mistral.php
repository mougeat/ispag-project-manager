<?php

class ISPAG_Mistral {

    private static $api_key;
    private static $api_url_agent = 'https://api.mistral.ai/v1/agents/completions';
    private static $log_file;

    public static function init() {
        self::$api_key  = getenv('CRM_MISTRAL_API_KEY');
        self::$log_file = WP_CONTENT_DIR . '/ispag_mistral.log';
        add_filter('ispag_send_to_mistral', [self::class, 'send_to_mistral'], 10, 3);
    }

    private static function log($message, $data = null) {
        $entry = "[" . date('Y-m-d H:i:s') . "] " . $message;
        if ($data) $entry .= " | Détails: " . (is_string($data) ? $data : print_r($data, true));
        error_log($entry . PHP_EOL, 3, self::$log_file);
    }

    public static function send_to_mistral($html, $content, $type = 'purchase') {
        self::log("--- REQUETE MISTRAL ($type) ---");

        if (empty(self::$api_key)) return null;

        $agent_ids = [
            'project'             => 'ag_019c21fca53276b38f0bbf0f9fe734ed',
            'purchase'            => 'ag_019c2206aaff774a9b5d852b491285ef',
            'product_drawing'     => 'ag_019c3364992976279a1e85b9d0a2b840',
            'tank_data_extractor' => 'ag_019c3df9f301711eadf27ffcdc91ce41'
        ];

        $agent_id = $agent_ids[$type] ?? 'ag_019c21fca53276b38f0bbf0f9fe734ed';

        self::log("--- Contenu du prompt ($content) ---");

        $payload = [
            'agent_id' => $agent_id,
            'messages' => [['role' => 'user', 'content' => $content]]
        ];

        $response = wp_remote_post(self::$api_url_agent, [
            'method'  => 'POST',
            'timeout' => 60,
            'headers' => [
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . trim(self::$api_key)
            ],
            'body'    => json_encode($payload),
        ]);

        // self::log("RAW Response", $response);

        if (is_wp_error($response)) return null;

        $body_raw = wp_remote_retrieve_body($response);
        $body = json_decode($body_raw, true);
        $content_ai = $body['choices'][0]['message']['content'] ?? '';

        if (empty($content_ai)) return null;

        // --- NETTOYAGE AGRESSIF ---
        // 1. Enlever les balises Markdown ```json
        $cleaned = preg_replace('/^```json\s+/i', '', $content_ai);
        $cleaned = preg_replace('/\s+```$/', '', $cleaned);
        
        // 2. SUPPRIMER LES COMMENTAIRES /* ... */ (C'est ça qui bloquait !)
        $cleaned = preg_replace('!/\*.*?\*/!s', '', $cleaned);
        
        // 3. Supprimer les commentaires // ...
        $cleaned = preg_replace('/(?<!:)\/\/.*/', '', $cleaned);

        // 4. Caractères de contrôle et espaces
        $cleaned = trim($cleaned);

        self::log("JSON NETTOYÉ", $cleaned);

        $extracted_data = json_decode($cleaned, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            self::log("ERREUR FINALE JSON: " . json_last_error_msg());
            // Tentative de secours : enlever les virgules traînantes
            $cleaned = preg_replace('/,\s*([\]}])/', '$1', $cleaned);
            $extracted_data = json_decode($cleaned, true);
        }

        return $extracted_data;
    }
}