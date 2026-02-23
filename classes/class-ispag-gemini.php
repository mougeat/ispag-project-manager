<?php

class ISPAG_Gemini {

    private static $api_key;
    private static $api_url;
    private static $log_file;

    public static function init() {
        self::$api_key  = getenv('GEMINI_API_KEY');
        self::$log_file = WP_CONTENT_DIR . '/ispag_gemini.log';
        
        // Utilisation du modèle 2.0 Flash-Lite
        self::$api_url = 'https://generativelanguage.googleapis.com/v1/models/gemini-1.5-flash:generateContent?key=' . self::$api_key;
        
        add_filter('ispag_send_to_gemini', [self::class, 'send_to_gemini'], 10, 3);
    }

    /**
     * Log les erreurs dans wp-content/ispag_gemini.log
     */
    private static function log_error($message, $data = null) {
        $timestamp = current_time('mysql');
        $entry = "[$timestamp] $message";
        if ($data) {
            $entry .= " | Détails: " . (is_string($data) ? $data : json_encode($data));
        }
        error_log($entry . PHP_EOL, 3, self::$log_file);
    }

    public static function send_to_gemini($html, $content, $type = 'project') {
        
        // 1. Conservation stricte de tes prompts d'origine
        if ($type === 'purchase') {
            $prompt_instruction = "je vais t'envoyer le contenu d'un document. La partie 'Referenz' ne sert qu'à informer sur la nature du projet. elle ne sert en aucun cas a donner les dimensions des cuves. J'ai besoin que me l'analyse et que tu me retourne sous forme de JSON, les données techniques (Technische Daten), avec les clé suivante: type: le type de réservoir (peut être précisé après Anzahl) => Si accumulateur d'énergie alors 4 => Si accumulateur sanitaire ou chauffe-eau alors 5 => Si accumulateur froid alors 6 => Si accumulateur glacée alors 7 => Si pas préciser 4 materiau: le materiaux de la cuve => Si accumulateur d'énergie alors 2 => Si accumulateur sanitaire ou chauffe-eau alors 1 => Si accumulateur froid alors 2 => Si accumulateur glacée alors 2 => Si pas préciser 2 support: si le réservoir est posé sur pieds (10) ou sur virole (11) => Si pas précisé et réservoir eau froide ou glacée alors 10 => si non 11 volume: le volume de la cuve (int) diameter: le diametre de la cuve (int) height: la hauteur de la cuve (int) (Gesamthöhe en allemand) max_pressure: la pression de service (int) (=> Si pas préciser 3) test_pressure: la pression d'essais (parfois entre parenthese après la pression de service)(int) (=> Si pas préciser 4.5) qty: le nombre de pièces (=> quantity minimum 1) temperature: la température de service (pas souvent précisé par défaut 109) clearance: le vide sous la cuve (par défaut 100) (Bodenfreiheit en allemand) InsulationThickness: si précisé, l'épaisseur de l'isolation de la cuve page: le numéro de page titre: titre de la section sales_price: le prix net que tu trouve sur le document (probalement Gesamtpreis netto) date_depart: La date d'expedition que tu trouvera uniquement sur la confirmation de commande + 1 jour par sécurité (si non vide). Assure-toi que chaque réservoir est bien sous une clé numérique dans le tableau 'tanks'.";
        } elseif ($type === 'product_drawing') {
            $prompt_instruction = "Je vais t'envoyer le contenu d'un plan de fabrication de reservoir. tu va m'identifier la longueur des piquages (raccords) et me dire si ils sont suffisement long ou pas. Assure toi de me retourner l'information sous une clé length avec comme valeur true (pour ok) et false (pour erreur)";
        } else {
            $prompt_instruction = "je vais t'envoyer le contenu d'une soumission. il y aura des details de réservoirs. j'ai besoin que tu me retourne sous forme de JSON les cle suivante: type: le type de réservoir => Si accumulateur d'énergie alors 4 => Si accumulateur sanitaire ou chauffe-eau alors 5 => Si accumulateur froid alors 6 => Si accumulateur glacée alors 7 => Si pas préciser 4 materiau: le materiaux de la cuve => Si accumulateur d'énergie alors 2 => Si accumulateur sanitaire ou chauffe-eau alors 1 => Si accumulateur froid alors 2 => Si accumulateur glacée alors 2 => Si pas préciser 2 support: si le réservoir est posé sur pieds (10) ou sur virole (11) => Si pas précisé et réservoir eau froide ou glacée alors 10 => si non 11 volume: le volume de la cuve (int) diameter: le diametre de la cuve (int) height: la hauteur de la cuve (int) max_pressure: la pression de service (int) (=> Si pas préciser 3) test_pressure: la pression d'essais (parfois entre parenthese après la pression de service)(int) (=> Si pas préciser 4.5) quantity: le nombre de pièces (=> quantity minimum 1) temperature: la température de service (pas souvent précisé par défaut 109) clearance: le vide sous la cuve (par défaut 100) InsulationThickness: si précisé, l'épaisseur de l'isolation de la cuve titre: titre de la section page: le numéro de page Assure-toi que chaque réservoir est bien sous une clé numérique dans le tableau 'tanks'.";
        }

        $payload = [
            'contents' => [
                ['parts' => [['text' => $prompt_instruction . "\n\n" . $content]]]
            ],
            'generationConfig' => [
                'responseMimeType' => 'application/json',
                'temperature'      => 0.1, // Très bas pour les calculs techniques
                'maxOutputTokens'  => 2048,
            ]
        ];

        $response = wp_remote_post(self::$api_url, [
            'method'    => 'POST',
            'timeout'   => 45,
            'headers'   => ['Content-Type' => 'application/json'],
            'body'      => json_encode($payload),
        ]);

        if (is_wp_error($response)) {
            self::log_error("Erreur de connexion API", $response->get_error_message());
            return null;
        }

        $body = wp_remote_retrieve_body($response);
        $decoded_api = json_decode($body, true);

        // 2. Vérification structure API
        $text_response = $decoded_api['candidates'][0]['content']['parts'][0]['text'] ?? '';
        if (empty($text_response)) {
            self::log_error("Réponse API vide ou bloquée", $body);
            return null;
        }

        // 3. Décodage du JSON (plus besoin de regex avec responseMimeType)
        $extracted_data = json_decode(trim($text_response), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            self::log_error("JSON généré invalide", $text_response);
            return null;
        }

        // 4. Retour conforme à ton format initial
        if (isset($extracted_data['tanks']) && is_array($extracted_data['tanks'])) {
            return $extracted_data['tanks'];
        } elseif (is_array($extracted_data) && !empty($extracted_data) && isset($extracted_data[0]['type'])) {
            return $extracted_data;
        }

        return $extracted_data;
    }
}