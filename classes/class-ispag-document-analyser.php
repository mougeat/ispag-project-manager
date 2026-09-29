<?php
/**
 * Class ISPAG_Document_Analyser
 * Gère l'analyse des documents PDF pour extraire les données techniques.
 * Logging : Toutes les actions sont loguées dans ispag_document_analyser.log.
 */
class ISPAG_Document_Analyser
{
    private $wpdb;
    private $table_historique;
    private $table_media;
    private $table_projet_articles;
    private $table_achat_articles;
    private $table_prestations;
    private $table_doc_type;
    private const LOG_NAME = 'document_analyser';

    /** @var ISPAG_Logger Instance du logger. */
    private $logger;

    public function __construct()
    {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->logger = ISPAG_Logger::get_instance();
        $user_id = get_current_user_id();

        $this->table_historique = $wpdb->prefix . 'achats_historique';
        $this->table_media = $wpdb->prefix . 'posts';
        $this->table_projet_articles = $wpdb->prefix . 'achats_details_commande';
        $this->table_achat_articles = $wpdb->prefix . 'achats_articles_cmd_fournisseurs';
        $this->table_prestations = $wpdb->prefix . 'achats_type_prestations';
        $this->table_doc_type = $wpdb->prefix . 'achats_doc_types';

        // $this->logger->log_user_action(self::LOG_NAME, 'class_constructed', [], $user_id);

        // Actions AJAX
        add_action('wp_ajax_ispag_extract_request_datas', [$this, 'extract_request_datas']);
        add_action('wp_ajax_analyze_project_data', [$this, 'analyze_project_data_handle']);
        add_action('wp_ajax_analyze_drawing', [self::class, 'ajax_analyze_drawing']);
        add_action('wp_ajax_tank_data_extractor', [self::class, 'ajax_tank_data_extractor']);
        add_action('wp_ajax_drawing_approval_control', [$this, 'ajax_drawing_approval_control']);
        /**
         * AJAX Handler pour récupérer les données actuelles d'un réservoir pour la modale de comparaison
         */
        add_action('wp_ajax_get_tank_db_data', [$this, 'ispag_handle_get_tank_db_data'] );
        add_action('wp_ajax_ispag_update_tank_from_drawing', [$this, 'ispag_handle_update_tank_from_drawing'] );


    }

    /**
     * Log un message dans le fichier de log.
     *
     * @param string $message Message à logger.
     * @param mixed|null $data Données supplémentaires à logger.
     */
    private function log($message, $data = null)
    {
        $user_id = get_current_user_id();
        $context = [];
        if ($data !== null)
        {
            $context['data'] = is_scalar($data) ? $data : print_r($data, true);
        }
        $this->logger->log(self::LOG_NAME, $message, $user_id, $context);
    }

    /**
     * AJAX : Point d'entrée pour l'extraction simple
     */
    public function extract_request_datas()
    {
        $user_id = get_current_user_id();
        $this->logger->log_user_action(self::LOG_NAME, 'extract_request_datas_start', [], $user_id);

        if (!current_user_can('manage_order'))
        {
            $this->logger->log(self::LOG_NAME, 'ERROR: User cannot manage order', $user_id);
            wp_send_json_error('You do not have the required permissions.');
        }

        $doc_id = isset($_POST['doc_id']) ? intval($_POST['doc_id']) : 0;
        $deal_id = isset($_POST['deal_id']) ? intval($_POST['deal_id']) : 0;

        $this->logger->log_user_action(self::LOG_NAME, 'params_received', ['doc_id' => $doc_id, 'deal_id' => $deal_id], $user_id);

        if ($doc_id > 0)
        {
            $file_path = get_attached_file($doc_id);
            if ($file_path && file_exists($file_path))
            {
                $this->logger->log_user_action(self::LOG_NAME, 'file_exists', ['file_path' => $file_path], $user_id);
                $result = $this->analyze_pdf_keywords($file_path, $deal_id);
                if ($result)
                {
                    $this->logger->log_user_action(self::LOG_NAME, 'extraction_success', ['result_count' => count($result)], $user_id);
                    wp_send_json_success('Extraction completed successfully.', ['result' => $result]);
                }
                else
                {
                    $this->logger->log(self::LOG_NAME, 'ERROR: PDF analysis failed', $user_id);
                    wp_send_json_error('PDF analysis failed.');
                }
            }
            else
            {
                $this->logger->log(self::LOG_NAME, 'ERROR: File not found', $user_id);
                wp_send_json_error('File not found.');
            }
        }
        wp_die();
    }

    public static function ispag_handle_get_tank_db_data() {
        // Log de démarrage de l'action AJAX
        error_log("🔍 [ISPAG AJAX] Début de ispag_handle_get_tank_db_data. POST data: " . print_r($_POST, true));

        // Vérification de sécurité
        if (!isset($_POST['tank_id']) || empty($_POST['tank_id'])) {
            error_log("❌ [ISPAG AJAX] Erreur : Missing tank ID.");
            wp_send_json_error(array('message' => 'Missing tank ID.'));
        }

        $tank_id = intval($_POST['tank_id']);
        error_log("📌 [ISPAG AJAX] Tank ID nettoyé : " . $tank_id);

        $tank_data = null;
        $method_used = '';

        // Utilisation de votre repository existant
        if (class_exists('ISPAG_Tank_Repository') && method_exists('ISPAG_Tank_Repository', 'get_tank_details')) {
            $method_used = 'ISPAG_Tank_Repository::get_tank_details';
            $tank_data = ISPAG_Tank_Repository::get_tank_details($tank_id);
        } else {
            // Fallback direct sur la base de données WordPress
            $method_used = 'WPDB direct query';
            global $wpdb;
            $table_name = $wpdb->prefix . 'ispag_tanks'; // Adaptez si nécessaire
            $tank_data = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table_name} WHERE id = %d", $tank_id), ARRAY_A);
        }

        error_log("🛠️ [ISPAG AJAX] Méthode utilisée pour récupérer les données : " . $method_used);
        error_log("📦 [ISPAG AJAX] Données brutes récupérées : " . print_r($tank_data, true));

        if ($tank_data) {
            error_log("✅ [ISPAG AJAX] Succès : Envoi des données du réservoir #{$tank_id}");
            wp_send_json_success($tank_data);
        } else {
            error_log("❌ [ISPAG AJAX] Échec : Réservoir #{$tank_id} introuvable en BDD.");
            wp_send_json_error(array('message' => 'Tank not found in the database.'));
        }
    }

    public function ispag_handle_update_tank_from_drawing() {
        // Vérification de sécurité de base
        if (!isset($_POST['article_id']) || empty($_POST['article_id']) || !isset($_POST['article_id'])) {
            wp_send_json_error(array('message' => 'Incomplete data.'));
        }

        $article_id = intval($_POST['article_id']);
        $tank_data = $_POST['tank_data']; // Tableau associatif des champs cochés (volume, diameter, etc.)

        // Mappage des clés reçues du JS vers vos colonnes en BDD (dans dimensions_principales ou table dédiée)
        // Exemple d'adaptation des clés :
        $update_fields = array();
        if (isset($tank_data['volume'])) $update_fields['Volume'] = sanitize_text_field($tank_data['volume']);
        if (isset($tank_data['diameter'])) $update_fields['Diameter'] = sanitize_text_field($tank_data['diameter']);
        if (isset($tank_data['height'])) $update_fields['Height'] = sanitize_text_field($tank_data['height']);
        if (isset($tank_data['pressure'])) $update_fields['MaxPressure'] = sanitize_text_field($tank_data['pressure']);
        if (isset($tank_data['temperature'])) $update_fields['usingTemperature'] = sanitize_text_field($tank_data['temperature']);

        if (empty($update_fields)) {
            wp_send_json_error(array('message' => 'No valid field to update.'));
        }

        // Utilisation de votre repository ou mise à jour directe via wpdb
        $updated = false;
        if (class_exists('ISPAG_Tank_Repository') && method_exists('ISPAG_Tank_Repository', 'update_tank_dimensions')) {
            $updated = ISPAG_Tank_Repository::update_tank_dimensions($article_id, $update_fields);
        } else {
            global $wpdb;
            $table_name = $wpdb->prefix . 'achats_tank_dimensions'; // Adaptez selon votre structure
            // Si vos données sont stockées en colonnes directes ou dans un champ sérialisé/JSON
            $updated = $wpdb->update($table_name, $update_fields, array('id' => $article_id));
        }

        if ($updated !== false) {
            wp_send_json_success(array('message' => 'Update saved successfully.'));
        } else {
            wp_send_json_error(array('message' => 'Error while writing to the database.'));
        }
    }
    
    /**
     * AJAX : Analyse globale d'un projet ou d'un dessin
     */
    public function analyze_project_data_handle()
    {
        $user_id = get_current_user_id();
        $this->logger->log_user_action(self::LOG_NAME, 'analyze_project_data_handle_start', [], $user_id);

        $docId = $_POST['docId'];
        $docType = $_POST['docType'];
        $file_path = get_attached_file($docId);
        $deal_id = $_POST['deal_id'];

        $this->log("START analyze_project_data_handle", ["DocID" => $docId, "Type" => $docType, "Deal" => $deal_id]);

        if ($docType == 'product_drawing')
        {
            $this->logger->log_user_action(self::LOG_NAME, 'product_drawing_detected', [], $user_id);
            $response_data = $this->extract_all_datas($file_path, $deal_id, $docType);
        }
        else
        {
            $this->logger->log_user_action(self::LOG_NAME, 'other_doc_type_detected', ['docType' => $docType], $user_id);
            $response_data = $this->analyze_pdf_with_visual_agent($file_path, $deal_id);
        }

        // --- NOUVEAU & CRITIQUE : Interception du traitement asynchrone ---
        // Si le filtre a planifié une tâche de fond (WP-Cron), on retourne directement
        // le ticket d'attente (task_id) au JavaScript sans continuer la sauvegarde directe.
        if (is_array($response_data) && isset($response_data['async']) && $response_data['async'] === true)
        {
            $this->log("ASYNCHRONOUS MODE DETECTED - Bypassing direct save and returning task_id to JS", $response_data);
            wp_send_json_success($response_data);
            wp_die();
        }

        $this->log("END analyze_project_data_handle - Datas", $response_data);

        if ($response_data)
        {
            $this->logger->log_user_action(self::LOG_NAME, 'analysis_success', ['data_count' => count($response_data)], $user_id);
            wp_send_json_success(['data' => $response_data]);
        }
        else
        {
            $this->logger->log(self::LOG_NAME, 'ERROR: Data extraction failed', $user_id);
            wp_send_json_error('Data extraction failed.');
        }
    }

    /**
     * AJAX : Contrôle d'approbation d'un plan validé (Mode Asynchrone / Synchrone)
     */
    public function ajax_drawing_approval_control()
    {
        $user_id = get_current_user_id();
        $this->logger->log_user_action(self::LOG_NAME, 'ajax_drawing_approval_control_start', [], $user_id);

        $doc_id = intval($_POST['docId'] ?? 0);
        $tank_id = intval($_POST['tankId'] ?? 0);
        $deal_id = intval($_POST['deal_id'] ?? 0);

        if (!$doc_id || !$tank_id) {
            wp_send_json_error(['message' => 'Missing parameters (docId or tankId).']);
        }

        $file_path = get_attached_file($doc_id);
        if (!$file_path || !file_exists($file_path)) {
            wp_send_json_error(['message' => 'Fichier PDF introuvable.']);
        }

        $this->log("START ajax_drawing_approval_control", ["DocID" => $doc_id, "TankID" => $tank_id, "Deal" => $deal_id]);

        try {
            // 1. Récupération des données actuelles en BDD[cite: 2]
            $tank_specs = ISPAG_Tank_Repository::get_tank_details($tank_id);
            $this->logger->log_db_change(self::LOG_NAME, 'tank_details', 'FETCH', ['tank_id' => $tank_id], $user_id);

            // 2. Extraction du texte du PDF du plan validé[cite: 2]
            require_once plugin_dir_path(__FILE__) . '../libs/pdfparser/autoload.php';
            $parser = new \Smalot\PdfParser\Parser();
            $pdf = $parser->parseFile($file_path);
            $drawing_text = trim(preg_replace('/\s+/', ' ', $pdf->getText()));

            // 3. Construction du contenu combiné (Attendu vs Extrait du plan)[cite: 2]
            $combined_content = "### SPÉCIFICATIONS ACTUELLES (BDD) ###\n" . json_encode($tank_specs) . "\n\n### TEXTE / DONNÉES DU PLAN VALIDÉ ###\n" . $drawing_text;

            // 4. Envoi à l'IA[cite: 2]
            $response_data = apply_filters('ispag_send_to_mistral', null, $combined_content, 'drawing_approval_control');

            // --- INTERCEPTION DU TRAITEMENT ASYNCHRONE ---
            if (is_array($response_data) && isset($response_data['async']) && $response_data['async'] === true)
            {
                $this->log("ASYNCHRONOUS MODE DETECTED - Returning task_id to JS", $response_data);
                wp_send_json_success($response_data);
                wp_die();
            }

            if ($response_data) {
                $this->logger->log_user_action(self::LOG_NAME, 'drawing_approval_analysis_success', ['tank_id' => $tank_id], $user_id);
                
                // Réponse directe pour l'affichage immédiat de la modale de comparaison[cite: 2]
                wp_send_json_success([
                    'tank_id' => $tank_id,
                    'current_db_data' => $tank_specs,
                    'extracted_drawing_data' => $response_data
                ]);
            } else {
                $this->logger->log(self::LOG_NAME, 'ERROR: Mistral analysis failed for drawing approval', $user_id);
                wp_send_json_error(['message' => "AI analysis failed."]);
            }

        } catch (\Exception $e) {
            $this->logger->log(self::LOG_NAME, 'ERROR: Exception in ajax_drawing_approval_control - ' . $e->getMessage(), $user_id);
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    

    /**
     * Analyse globale via l'Agent Visuel (Envoi du PDF complet)
     */
    private function analyze_pdf_with_visual_agent($file_path, $deal_id)
    {
        $user_id = get_current_user_id();
        $this->logger->log_user_action(self::LOG_NAME, 'analyze_pdf_with_visual_agent_start', ['file_path' => $file_path, 'deal_id' => $deal_id], $user_id);

        if (!file_exists($file_path))
        {
            $this->logger->log(self::LOG_NAME, 'ERROR: File not found - ' . $file_path, $user_id);
            return null;
        }

        $doc_id = $_POST['docId'];
        $file_url = wp_get_attachment_url($doc_id);

        $this->logger->log_user_action(self::LOG_NAME, 'file_url_retrieved', ['doc_id' => $doc_id, 'file_url' => $file_url], $user_id);

        // Chargement des référentiels techniques
        $tank_builder_js_dir = WP_PLUGIN_DIR . '/ispag-tank-builder/assets/json/'; 

        $json_defaults_path = $tank_builder_js_dir . 'default_value.json';
        $json_restrictions_path = $tank_builder_js_dir . 'tank_data.json';

        $json_defaults = "";
        $json_restrictions = "";

        if (file_exists($json_defaults_path))
        {
            $json_defaults = "\n[DIMENSIONS STANDARDS PAR VOLUME] :\n" . file_get_contents($json_defaults_path);
            $this->logger->log_user_action(self::LOG_NAME, 'default_values_loaded', [], $user_id);
        }

        if (file_exists($json_restrictions_path))
        {
            $json_restrictions = "\n[RESTRICTIONS TECHNIQUES ET MATÉRIAUX] :\n" . file_get_contents($json_restrictions_path);
            $this->logger->log_user_action(self::LOG_NAME, 'restrictions_loaded', [], $user_id);
        }

        $prompt = "Voici les fichiers de configuration ISPAG :\n";
        $prompt .= "--- DIMENSIONS STANDARDS ---\n" . $json_defaults . "\n";
        $prompt .= "--- RESTRICTIONS TECHNIQUES ---\n" . $json_restrictions . "\n\n";
        $prompt .= "Analyse maintenant le document PDF ci-joint en suivant tes instructions d'agent.";

        $this->logger->log_user_action(self::LOG_NAME, 'prompt_prepared', ['prompt_length' => strlen($prompt), 'prompt' => $prompt], $user_id);

        $response_data = ISPAG_Mistral::send_to_mistral(null, $prompt, 'project', $file_url);

        // Dans analyze_project_data_handle()
        if ($response_data && (!isset($response_data['async']) || !$response_data['async'])) {
            $this->auto_save_extracted_data($response_data, $deal_id);
            wp_send_json_success(['data' => $response_data]);
        }
        else
        {
            $this->logger->log(self::LOG_NAME, 'ERROR: No response from Mistral', $user_id);
        }

        return $response_data;
    }

    /**
     * Sauvegarde automatique des données extraites par l'agent visuel (incluant réservoirs, échangeurs et vannes)
     */
    public function auto_save_extracted_data($response_data, $deal_id, $doc_type = '') {
        $user_id = get_current_user_id();

        // 1. Log d'entrée dans la méthode
        $this->log("AUTO SAVE CHECK", [
            'deal_id'  => $deal_id,
            'doc_type' => $doc_type,
            'user_id'  => $user_id
        ]);

        if (empty($response_data) || empty($deal_id)) {
            $this->log("AUTO SAVE ABORT: response_data ou deal_id vide", [
                'has_response_data' => !empty($response_data),
                'deal_id'           => $deal_id
            ]);
            return false;
        }

        // 2. Dump brut de la structure reçue dans le fichier de log dédié
        file_put_contents(
            WP_CONTENT_DIR . '/uploads/ispag_logs/ispag_document_analyser.log', 
            "[" . date("Y-m-d H:i:s") . "] [USER:" . $user_id . "] [DIAGNOSTIC BRUT] Data: " . json_encode($response_data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL, 
            FILE_APPEND
        );

        $this->logger->log_user_action(self::LOG_NAME, 'auto_save_extracted_data_start', ['deal_id' => $deal_id], $user_id);

        // --- TRAITEMENT SPÉCIFIQUE : Offre fournisseur / Devis ---
        if ($doc_type === 'request_supplier_quotation' || isset($response_data['supplier_quotation'])) {
            $this->logger->log_user_action(self::LOG_NAME, 'processing_supplier_quotation_branch', ['doc_type' => $doc_type], $user_id);
            $this->save_supplier_quotation_data($response_data, $deal_id);
        }

        if (empty($response_data)) {
            $this->logger->log(self::LOG_NAME, 'ERROR: Empty response data after supplier check', $user_id);
            return;
        }

        // Initialiser le gestionnaire de vannes
        $valves_manager = new ISPAG_Valves_Manager();

        // --- 1. CRÉATION DU BILAN GLOBAL (NOTE) ---
        $note_content = "";
        if (!empty($response_data['analyse_bilan'])) {
            $bilan = $response_data['analyse_bilan'];
            $this->logger->log_user_action(self::LOG_NAME, 'bilan_data_received', ['bilan' => $bilan], $user_id);

            $note_content = "🤖 **" . __('ISPAG AI ANALYSIS REPORT', 'creation-reservoir') . "**\n";
            $note_content .= "━━━━━━━━━━━━━━━━━━━━━\n";

            // Résumé
            $note_content .= "📝 **" . __('Summary:', 'creation-reservoir') . "** " . ($bilan['resume'] ?? __('N/A', 'creation-reservoir')) . "\n\n";

            // Exclusions
            if (!empty($bilan['exclusions'])) {
                $note_content .= "🚫 **" . __('Exclusions:', 'creation-reservoir') . "** " . $bilan['exclusions'] . "\n";
            }

            // Logistique et accès
            if (!empty($bilan['logistique_acces'])) {
                $log = $bilan['logistique_acces'];
                $note_content .= "\n🚚 **" . __('LOGISTICS & ACCESS', 'creation-reservoir') . "**\n";
                $note_content .= "• " . __('Doors:', 'creation-reservoir') . " " . ($log['passage_portes'] ?? __('N/A', 'creation-reservoir')) . "\n";
                $note_content .= "• " . __('Room Height:', 'creation-reservoir') . " " . ($log['hauteur_local'] ?? __('N/A', 'creation-reservoir')) . "\n";
                $note_content .= "• " . __('Constraints:', 'creation-reservoir') . " " . ($log['autres_contraintes'] ?? __('N/A', 'creation-reservoir')) . "\n";
            }

            // Points d'attention
            if (!empty($bilan['points_attention'])) {
                $note_content .= "\n⚠️ **" . __('POINTS OF ATTENTION:', 'creation-reservoir') . "**\n" . $bilan['points_attention'];
            }
        } else {
            $this->logger->log_user_action(self::LOG_NAME, 'bilan_data_skipped_or_empty', [], $user_id);
        }

        // --- 2. TRAITEMENT DES RÉSERVOIRS ---
        if (!empty($response_data['tanks']) && is_array($response_data['tanks'])) {
            $this->logger->log_user_action(self::LOG_NAME, 'tanks_data_received', ['count' => count($response_data['tanks'])], $user_id);

            foreach ($response_data['tanks'] as $index => $tank_datas) {
                $vol = intval($tank_datas['volume'] ?? 0);
                if (isset($tank_datas['volume']) && $vol > 0) {
                    $this->logger->log_user_action(self::LOG_NAME, 'processing_tank', ['index' => $index, 'volume' => $tank_datas['volume']], $user_id);

                    $data_save = [
                        'tank'     => $tank_datas,
                        'supplier' => $tank_datas['supplier'] ?? 'Diem-Werke GmbH',
                        'type'     => 1,
                        'deal_id'  => $deal_id,
                        'group'    => "p" . sprintf('%02d', $tank_datas['page'] ?? 0) . " - " . ($tank_datas['titre'] ?? "Réservoir " . ($index + 1))
                    ];

                    if (isset($tank_datas['materiau']) && $tank_datas['materiau'] == 1) {
                        $data_save['supplier'] = 'RUDERT Edelstahl-Technik GmbH';
                    }

                    $article_project = apply_filters('ispag_article_save_pdf', null, 0, $data_save);
                    if ($article_project && !empty($article_project['success'])) {
                        $this->logger->log_user_action(self::LOG_NAME, 'tank_saved', ['article_id' => $article_project['id']], $user_id);
                        $data_save['article_id'] = $article_project['id'];
                        apply_filters('ispag_auto_saver_tank_data', null, $data_save);

                        $saver = new Ispag_Fitting_Autosaver();
                        $result = $saver->save($data_save);
                        $this->logger->log_user_action(self::LOG_NAME, 'fittings_saved', ['result' => $result], $user_id);
                    } else {
                        $this->logger->log(self::LOG_NAME, "ERROR: Filter ispag_article_save_pdf failed or returned invalid response for tank {$index}", $user_id);
                    }
                } else {
                    $this->logger->log_user_action(self::LOG_NAME, 'tank_skipped_invalid_volume', ['index' => $index, 'raw_volume' => $tank_datas['volume'] ?? null], $user_id);
                }
            }
        } else {
            $this->logger->log_user_action(self::LOG_NAME, 'tanks_data_skipped_or_empty', [], $user_id);
        }

        // --- 3. TRAITEMENT DES ÉCHANGEURS ---
        if (!empty($response_data['exchangers']) && is_array($response_data['exchangers'])) {
            $this->logger->log_user_action(self::LOG_NAME, 'exchangers_data_received', ['count' => count($response_data['exchangers'])], $user_id);

            foreach ($response_data['exchangers'] as $index => $exch_datas) {
                $data_save = [
                    'exchanger' => $exch_datas,
                    'supplier'  => $exch_datas['supplier'] ?? 'CIPRIANI PHE Srl',
                    'type'      => 5,
                    'deal_id'   => $deal_id,
                    'group'     => "p" . sprintf('%02d', $exch_datas['page'] ?? 0) . " - " . ($exch_datas['titre'] ?? "Échangeur " . ($index + 1))
                ];

                $article_project = apply_filters('ispag_article_save_pdf', null, 0, $data_save);
                if ($article_project && !empty($article_project['success'])) {
                    $this->logger->log_user_action(self::LOG_NAME, 'exchanger_saved', ['article_id' => $article_project['id']], $user_id);
                    $data_save['article_id'] = $article_project['id'];
                    $designer = new ISPAG_Plate_Heat_exchanger_Designer();
                    $designer->save_exchanger_data(null, $data_save);
                } else {
                    $this->logger->log(self::LOG_NAME, "ERROR: Filter ispag_article_save_pdf failed for exchanger {$index}", $user_id);
                }
            }
        } else {
            $this->logger->log_user_action(self::LOG_NAME, 'exchangers_data_skipped_or_empty', [], $user_id);
        }

        // --- 4. TRAITEMENT DES VANNES ---
        if (!empty($response_data['valves']) && is_array($response_data['valves'])) {
            $this->logger->log_user_action(self::LOG_NAME, 'valves_data_received', ['count' => count($response_data['valves'])], $user_id);
            $note_content .= "\n\n🔧 **" . __('Valves Detected:', 'creation-reservoir') . "**\n";

            foreach ($response_data['valves'] as $index => $valve_data) {
                $this->logger->log_user_action(self::LOG_NAME, 'processing_valve', ['index' => $index, 'titre' => $valve_data['titre'] ?? 'N/A'], $user_id);

                if (empty($valve_data['supplier'])) {
                    $valve_data['supplier'] = 'M.P. Welding SA';
                }

                $valve_id = $valves_manager->save_valve($valve_data, $deal_id);
                if ($valve_id) {
                    $this->logger->log_user_action(self::LOG_NAME, 'valve_saved', ['valve_id' => $valve_id], $user_id);

                    $note_content .= "• " . ($valve_data['titre'] ?? __('Untitled', 'creation-reservoir')) . " (";
                    $note_content .= __('Brand:', 'creation-reservoir') . " " . ($valve_data['marque'] ?? __('N/A', 'creation-reservoir')) . ", ";
                    $note_content .= __('Type:', 'creation-reservoir') . " " . ($valve_data['type'] ?? __('N/A', 'creation-reservoir')) . ", ";
                    $note_content .= __('Qty:', 'creation-reservoir') . " " . ($valve_data['quantity'] ?? 1) . ")\n";
                } else {
                    $this->logger->log(self::LOG_NAME, "ERROR: save_valve failed for index {$index}", $user_id);
                }
            }
        } else {
            $this->logger->log_user_action(self::LOG_NAME, 'valves_data_skipped_or_empty', [], $user_id);
        }

        // --- 5. SAUVEGARDE DE LA NOTE DE BILAN ---
        if (!empty($note_content)) {
            $task_data = [
                'contact_id' => get_current_user_id() ?: 1,
                'deal_id'    => $deal_id,
                'type'       => 'NOTE',
                'is_task'    => 0,
                'content'    => $note_content,
                'title'      => __('AI Analysis Report', 'creation-reservoir'),
                'created_by' => get_current_user_id() ?: 1
            ];

            do_action('ispag_save_contact_note', $task_data, null, get_current_user_id() ?: 1, true);
            $this->logger->log_user_action(self::LOG_NAME, 'bilan_note_saved', [], $user_id);
        } else {
            $this->logger->log_user_action(self::LOG_NAME, 'bilan_note_skipped_empty_content', [], $user_id);
        }

        $this->logger->log_user_action(self::LOG_NAME, 'auto_save_extracted_data_complete', [], $user_id);
    }

    private function save_supplier_quotation_data($data, $deal_id) {
        $this->log("SAVING SUPPLIER QUOTATION DATA", ['deal_id' => $deal_id]);

        // Exemple d'enregistrement dans les post_meta du Deal WP
        update_post_meta($deal_id, '_ispag_supplier_quotation_raw', $data);

        if (isset($data['total_price'])) {
            update_post_meta($deal_id, '_ispag_supplier_price', sanitize_text_field($data['total_price']));
        }

        if (isset($data['supplier_name'])) {
            update_post_meta($deal_id, '_ispag_supplier_name', sanitize_text_field($data['supplier_name']));
        }

        // Si tu as des articles / lignes de devis à enregistrer
        if (!empty($data['items']) && is_array($data['items'])) {
            update_post_meta($deal_id, '_ispag_supplier_items', $data['items']);
        }

        return true;
    }

    private function upload_file_to_mistral($file_path)
    {
        $user_id = get_current_user_id();
        $this->logger->log_user_action(self::LOG_NAME, 'upload_file_to_mistral_start', ['file_path' => $file_path], $user_id);

        $api_key = getenv('CRM_MISTRAL_API_KEY');
        $url = 'https://api.mistral.ai/v1/files';

        if (!file_exists($file_path))
        {
            $this->logger->log(self::LOG_NAME, 'ERROR: File not found for upload - ' . $file_path, $user_id);
            return null;
        }

        $boundary = wp_generate_password(24, false);
        $payload = '';

        $payload .= '--' . $boundary . "\r\n";
        $payload .= 'Content-Disposition: form-data; name="file"; filename="' . basename($file_path) . '"' . "\r\n";
        $payload .= 'Content-Type: application/pdf' . "\r\n\r\n";
        $payload .= file_get_contents($file_path) . "\r\n";

        $payload .= '--' . $boundary . "\r\n";
        $payload .= 'Content-Disposition: form-data; name="purpose"' . "\r\n\r\n";
        $payload .= 'ocr' . "\r\n";
        $payload .= '--' . $boundary . '--';

        $this->logger->log_user_action(self::LOG_NAME, 'payload_prepared', ['boundary' => $boundary], $user_id);

        $response = wp_remote_post($url, [
            'method' => 'POST',
            'timeout' => 60,
            'headers' => [
                'Authorization' => 'Bearer ' . trim($api_key),
                'Content-Type' => 'multipart/form-data; boundary=' . $boundary,
            ],
            'body' => $payload,
        ]);

        if (is_wp_error($response))
        {
            $this->logger->log(self::LOG_NAME, 'ERROR: WP Remote error - ' . $response->get_error_message(), $user_id);
            return null;
        }

        $body_raw = wp_remote_retrieve_body($response);
        $body = json_decode($body_raw, true);

        if (isset($body['id']))
        {
            $this->logger->log_user_action(self::LOG_NAME, 'file_uploaded_to_mistral', ['file_id' => $body['id']], $user_id);
            return $body['id'];
        }
        else
        {
            $this->logger->log(self::LOG_NAME, 'ERROR: Mistral API response without ID', $user_id, ['response' => $body_raw]);
            return null;
        }
    }

    /**
     * Analyse des mots-clés et extraction IA par page
     */
    private function analyze_pdf_keywords($file_path, $deal_id)
    {
        $user_id = get_current_user_id();
        $this->logger->log_user_action(self::LOG_NAME, 'analyze_pdf_keywords_start', ['file_path' => $file_path, 'deal_id' => $deal_id], $user_id);

        if (!file_exists($file_path))
        {
            $this->logger->log(self::LOG_NAME, 'ERROR: File not found - ' . $file_path, $user_id);
            return;
        }

        set_time_limit(300);

        require_once plugin_dir_path(__FILE__) . '../libs/pdfparser/autoload.php';

        $keywords = ['accumulateur', 'chauffe-eau', 'bouilleur', 'réservoir', 'Speicher', 'Energiespeicher', 'Wassererwärmer', 'Behälter'];
        $parser = new \Smalot\PdfParser\Parser();
        $all_extracted_data = [];

        $this->logger->log_user_action(self::LOG_NAME, 'pdf_parser_initialized', ['keywords' => $keywords], $user_id);

        try
        {
            $pdf = $parser->parseFile($file_path);
            $pages = $pdf->getPages();
            $pages_with_keywords = [];
            $summary_lines = [];

            foreach ($pages as $index => $page)
            {
                $text = strtolower($page->getText());
                $found_keywords = [];

                foreach ($keywords as $word)
                {
                    if (strpos($text, $word) !== false)
                    {
                        $found_keywords[] = $word;
                    }
                }

                if (!empty($found_keywords))
                {
                    $label = implode(', ', $found_keywords);
                    $summary_lines[] = "Page " . ($index + 1) . " : {$label}";
                    $pages_with_keywords[$index] = [
                        'text' => $text,
                        'keywords' => $found_keywords
                    ];
                    $this->log("Keywords found page " . ($index + 1), $found_keywords);
                }
            }

            if (!empty($summary_lines))
            {
                apply_filters('ispag_add_note', null, implode("\n", $summary_lines), $deal_id, 0, 0, 1);
                $this->logger->log_user_action(self::LOG_NAME, 'summary_note_added', ['summary_lines' => $summary_lines], $user_id);
            }

            foreach ($pages_with_keywords as $index => $page_data)
            {
                $tanks = $this->extract_tank_specs($page_data['text'], $deal_id);
                if (!empty($tanks))
                {
                    foreach ($tanks as $tank_datas)
                    {
                        if (!empty($tank_datas['volume']) || !empty($tank_datas['technical']['volume']))
                        {
                            $this->logger->log_user_action(self::LOG_NAME, 'tank_with_volume_found', ['page' => $index + 1, 'volume' => $tank_datas['volume'] ?? $tank_datas['technical']['volume']], $user_id);

                            $data_save = [
                                'tank' => $tank_datas,
                                'type' => 1,
                                'deal_id' => $deal_id,
                                'group' => ($tank_datas['titre'] . " (p" . ($index + 1) . ")" ?? 'Product detected')
                            ];

                            $article_project = apply_filters('ispag_article_save_pdf', null, 0, $data_save);
                            if ($article_project['success'])
                            {
                                $this->logger->log_user_action(self::LOG_NAME, 'tank_data_saved', ['article_id' => $article_project['id']], $user_id);
                                $data_save['article_id'] = $article_project['id'];
                                apply_filters('ispag_auto_saver_tank_data', null, $data_save);
                            }
                            $all_extracted_data[] = $tank_datas;
                        }
                        else
                        {
                            $this->log("Page " . ($index + 1) . " : Données insuffisantes (pas de volume)");
                        }
                    }
                }
            }
        }
        catch (Exception $e)
        {
            $this->logger->log(self::LOG_NAME, 'ERROR: Exception in analyze_pdf_keywords - ' . $e->getMessage(), $user_id);
        }

        $this->logger->log_user_action(self::LOG_NAME, 'analyze_pdf_keywords_complete', ['extracted_count' => count($all_extracted_data)], $user_id);
        return $all_extracted_data;
    }

    /**
     * Interface avec le filtre Mistral (Gère la nouvelle structure JSON)
     */
    private function extract_tank_specs($text, $deal_id = null, $type = 'project')
    {
        $user_id = get_current_user_id();
        $this->logger->log_user_action(self::LOG_NAME, 'extract_tank_specs_start', ['text_length' => strlen($text), 'deal_id' => $deal_id, 'type' => $type], $user_id);

        $raw_data = apply_filters('ispag_send_to_mistral', null, $text, $type);
        $this->log("raw_data", ["raw_data" => $raw_data]);

        $tank_list = [];

        if (isset($raw_data['tanks']) && is_array($raw_data['tanks']))
        {
            foreach ($raw_data['tanks'] as $tank)
            {
                $tank_list[] = $tank;
            }
        }
        elseif (is_array($raw_data) && !empty($raw_data))
        {
            $tank_list = $raw_data;
        }

        $this->logger->log_user_action(self::LOG_NAME, 'tank_specs_extracted', ['count' => count($tank_list)], $user_id);
        return $tank_list;
    }

    public static function ajax_tank_data_extractor()
    {
        $user_id = get_current_user_id();
        $logger = ISPAG_Logger::get_instance();
        $logger->log_user_action(self::LOG_NAME, 'ajax_tank_data_extractor_start', [], $user_id);

        $tank_id = intval($_POST['tankId'] ?? 0);
        $deal_id = intval($_POST['deal_id'] ?? 0);
        $logger->log("AJAX DXF EXTRACTOR START - NATIVE MODE", $tank_id);

        if (!$tank_id)
        {
            $logger->log(self::LOG_NAME, 'ERROR: Missing tank_id', $user_id);
            wp_send_json_error(['message' => 'Missing tank ID.']);
        }
        if (!$deal_id)
        {
            $logger->log(self::LOG_NAME, 'ERROR: Missing deal_id', $user_id);
            wp_send_json_error(['message' => 'ID du deal manquant.']);
        }

        // $repo = new ISPAG_Tank_Repository();
        $tank_specs =ISPAG_Tank_Repository::get_tank_details($tank_id);
        $logger->log_db_change(self::LOG_NAME, 'tank_details', 'FETCH', ['tank_id' => $tank_id], $user_id);

        $project_repo = new ISPAG_Projet_Repository();
        $project = $project_repo->get_project_by_deal_id(null, $deal_id);
        $logger->log_db_change(self::LOG_NAME, 'project', 'FETCH', ['deal_id' => $deal_id], $user_id);

        if (!$tank_specs)
        {
            $logger->log(self::LOG_NAME, 'ERROR: Tank not found', $user_id);
            wp_send_json_error(['message' => 'Tank not found.']);
        }

        $logger->log_user_action(self::LOG_NAME, 'tank_specs_retrieved', ['tank_id' => $tank_id], $user_id);

        if (ob_get_length()) ob_clean();
        wp_send_json_success([
            'tank_id' => $tank_id,
            'tank_specs' => $tank_specs,
            'project' => $project,
            'generated_at' => current_time('mysql')
        ]);
    }

    /**
     * AJAX : Comparaison de conformité entre BDD et Dessin PDF
     */
    public static function ajax_analyze_drawing()
    {
        $user_id = get_current_user_id();
        $logger = ISPAG_Logger::get_instance();
        $logger->log_user_action(self::LOG_NAME, 'ajax_analyze_drawing_start', [], $user_id);

        $doc_id = intval($_POST['docId'] ?? 0);
        $tank_id = intval($_POST['tankId'] ?? 0);
        // $logger->log("AJAX ANALYZE DRAWING START", ["Doc" => $doc_id, "Tank" => $tank_id]);

        $existing_analysis = get_post_meta($doc_id, '_ispag_drawing_analysis', true);
        if (!empty($existing_analysis) && $existing_analysis['tank_id'] == $tank_id)
        {
            $logger->log_user_action(self::LOG_NAME, 'cached_analysis_returned', [], $user_id);
            wp_send_json_success(['comparison' => $existing_analysis['data'], 'tank_id' => $tank_id, 'cached' => true]);
        }

        require_once plugin_dir_path(__FILE__) . '../libs/pdfparser/autoload.php';

        $file_path = get_attached_file($doc_id);
        if (!$file_path || !file_exists($file_path))
        {
            $logger->log(self::LOG_NAME, 'ERROR: File not found', $user_id);
            wp_send_json_error(['message' => 'Fichier introuvable.']);
        }

        try
        {
            $parser = new \Smalot\PdfParser\Parser();
            $pdf = $parser->parseFile($file_path);
            $drawing_text = trim(preg_replace('/\s+/', ' ', $pdf->getText()));

            $logger->log_user_action(self::LOG_NAME, 'pdf_parsed', ['text_length' => strlen($drawing_text)], $user_id);

            // $repo = new ISPAG_Tank_Repository();
            $tank_specs = ISPAG_Tank_Repository::get_tank_details($tank_id);
            $logger->log_db_change(self::LOG_NAME, 'tank_details', 'FETCH', ['tank_id' => $tank_id], $user_id);

            $combined_content = "### SPÉCIFICATIONS ATTENDUES ###\n" . json_encode($tank_specs) . "\n\n### TEXTE DESSIN ###\n" . $drawing_text;

            $logger->log_user_action(self::LOG_NAME, 'combined_content_prepared', [], $user_id);

            $analysis = apply_filters('ispag_send_to_mistral', null, $combined_content, 'product_drawing');
            $logger->log_user_action(self::LOG_NAME, 'mistral_analysis_received', [], $user_id);

            if ($analysis)
            {
                update_post_meta($doc_id, '_ispag_drawing_analysis', ['timestamp' => current_time('mysql'), 'data' => $analysis, 'tank_id' => $tank_id]);
                $logger->log_db_change(self::LOG_NAME, 'post_meta', 'UPDATE_ANALYSIS', ['doc_id' => $doc_id, 'tank_id' => $tank_id], $user_id);

                wp_send_json_success(['comparison' => $analysis, 'tank_id' => $tank_id]);
            }
            else
            {
                $logger->log(self::LOG_NAME, 'ERROR: Mistral analysis failed', $user_id);
                wp_send_json_error(['message' => "L'IA n'a pas pu traiter la demande."]);
            }
        }
        catch (\Exception $e)
        {
            $logger->log(self::LOG_NAME, 'ERROR: Exception in ajax_analyze_drawing - ' . $e->getMessage(), $user_id);
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    /**
     * Extraction forcée de toutes les données (Analyse complète sans filtre mots-clés)
     */
    private function extract_all_datas($file_path, $deal_id, $docType = 'product_drawing')
    {
        $user_id = get_current_user_id();
        $this->logger->log_user_action(self::LOG_NAME, 'extract_all_datas_start', ['file_path' => $file_path, 'deal_id' => $deal_id, 'docType' => $docType], $user_id);

        if (!file_exists($file_path))
        {
            $this->logger->log(self::LOG_NAME, 'ERROR: File not found - ' . $file_path, $user_id);
            return;
        }

        set_time_limit(300);

        require_once plugin_dir_path(__FILE__) . '../libs/pdfparser/autoload.php';
        $parser = new \Smalot\PdfParser\Parser();
        $all_data = [];

        $this->logger->log_user_action(self::LOG_NAME, 'pdf_parser_initialized', [], $user_id);

        try
        {
            $pdf = $parser->parseFile($file_path);
            foreach ($pdf->getPages() as $index => $page)
            {
                $text = $page->getText();
                if (!empty($text))
                {
                    $this->logger->log_user_action(self::LOG_NAME, 'processing_page', ['page_index' => $index, 'text_length' => strlen($text)], $user_id);

                    $tanks = $this->extract_tank_specs($text, $deal_id, $docType);
                    foreach ($tanks as $t)
                    {
                        apply_filters('ispag_add_note', null, "Forced analysis page " . ($index+1), $deal_id, 0, 0, 1);
                        $all_data[] = $t;
                    }
                }
            }
        }
        catch (Exception $e)
        {
            $this->logger->log(self::LOG_NAME, 'ERROR: Exception in extract_all_datas - ' . $e->getMessage(), $user_id);
        }

        $this->logger->log_user_action(self::LOG_NAME, 'extract_all_datas_complete', ['extracted_count' => count($all_data)], $user_id);
        return $all_data;
    }
}