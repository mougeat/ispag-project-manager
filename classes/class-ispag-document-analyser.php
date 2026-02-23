<?php

class ISPAG_Document_Analyser {
    private $wpdb;
    private $table_historique;
    private $table_media;
    private $table_projet_articles;
    private $table_achat_articles;
    private $table_prestations;
    private $table_doc_type;
    private static $log_file;

    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->table_historique = $wpdb->prefix . 'achats_historique';
        $this->table_media = $wpdb->prefix . 'posts';
        $this->table_projet_articles = $wpdb->prefix . 'achats_details_commande';
        $this->table_achat_articles = $wpdb->prefix . 'achats_articles_cmd_fournisseurs';
        $this->table_prestations = $wpdb->prefix . 'achats_type_prestations';
        $this->table_doc_type = $wpdb->prefix . 'achats_doc_types';

        self::$log_file = WP_CONTENT_DIR . '/ispag_mistral.log';

        // Actions AJAX
        add_action('wp_ajax_ispag_extract_request_datas', [$this, 'extract_request_datas']);
        add_action('wp_ajax_analyze_project_data', [$this, 'analyze_project_data_handle']);
        add_action('wp_ajax_analyze_drawing', [self::class, 'ajax_analyze_drawing']);
        add_action('wp_ajax_tank_data_extractor', [self::class, 'ajax_tank_data_extractor']);
    }

    /**
     * SYSTÈME DE LOG CENTRALISÉ AVEC TIMING
     */
    private static function log($message, $data = null) {
        $timestamp = "[" . date('Y-m-d H:i:s') . "] ";
        $content = $message;
        if ($data !== null) {
            $content .= " : " . (is_scalar($data) ? $data : print_r($data, true));
        }
        error_log($timestamp . rtrim($content) . PHP_EOL, 3, self::$log_file);
    }

    /**
     * AJAX : Point d'entrée pour l'extraction simple
     */
    public function extract_request_datas(){
        if (!current_user_can('manage_order')) {
            wp_send_json_error('Vous n\'avez pas les permissions nécessaires.');
        }

        $doc_id = isset($_POST['doc_id']) ? intval($_POST['doc_id']) : 0;
        $deal_id = isset($_POST['deal_id']) ? intval($_POST['deal_id']) : 0;

        if ($doc_id > 0) {
            $file_path = get_attached_file($doc_id);
            if ($file_path && file_exists($file_path)) {
                $result = $this->analyze_pdf_keywords($file_path, $deal_id);
                if ($result) {
                    wp_send_json_success('Extraction terminée avec succès.', ['result' => $result]);
                } else {
                    wp_send_json_error('Échec de l\'analyse du PDF.');
                }
            } else {
                wp_send_json_error('Fichier non trouvé.');
            }
        }
        wp_die();
    }

    /**
     * AJAX : Analyse globale d'un projet ou d'un dessin
     */
    public function analyze_project_data_handle(){
        $docId = $_POST['docId'];
        $docType = $_POST['docType'];
        $file_path = get_attached_file($docId);
        $deal_id = $_POST['deal_id'];

        self::log("START analyze_project_data_handle", ["DocID" => $docId, "Type" => $docType, "Deal" => $deal_id]);

        if($docType == 'product_drawing'){
            $response_data = $this->extract_all_datas($file_path, $deal_id, $docType);
        } else {
            $response_data = $this->analyze_pdf_keywords($file_path, $deal_id);
        }

        self::log("END analyze_project_data_handle - Datas", $response_data);

        if ($response_data) {
            wp_send_json_success(['data' => $response_data]);
        } else {
            wp_send_json_error('Extraction des données échouée.');
        }
    }

    /**
     * Analyse des mots-clés et extraction IA par page
     */
    private function analyze_pdf_keywords($file_path, $deal_id) {
        if (!file_exists($file_path)) return;
        set_time_limit(300);
        self::log("IN analyze_pdf_keywords", $file_path);

        require_once plugin_dir_path(__FILE__) . '../libs/pdfparser/autoload.php';

        $keywords = ['accumulateur', 'chauffe-eau', 'bouilleur', 'réservoir'];
        $parser = new \Smalot\PdfParser\Parser();
        $all_extracted_data = [];

        try {
            $pdf = $parser->parseFile($file_path);
            $pages = $pdf->getPages();
            $pages_with_keywords = [];
            $summary_lines = [];

            foreach ($pages as $index => $page) {
                $text = strtolower($page->getText());
                $found_keywords = [];

                foreach ($keywords as $word) {
                    if (strpos($text, $word) !== false) {
                        $found_keywords[] = $word;
                    }
                }

                if (!empty($found_keywords)) {
                    $label = implode(', ', $found_keywords);
                    $summary_lines[] = "Page " . ($index + 1) . " : {$label}";
                    
                    $pages_with_keywords[$index] = [
                        'text' => $text,
                        'keywords' => $found_keywords
                    ];
                    self::log("Keywords found page " . ($index + 1), $found_keywords);
                }
            }

            if (!empty($summary_lines)) {
                apply_filters('ispag_add_note', null, implode("\n", $summary_lines), $deal_id, 0, 0, 1);
            }

            foreach ($pages_with_keywords as $index => $page_data) {
                $tanks = $this->extract_tank_specs($page_data['text'], $deal_id);
                
                if (!empty($tanks)) {
                    foreach ($tanks as $tank_datas) {
                        // Vérification de la présence d'un volume (clé de succès de l'IA)
                        if (!empty($tank_datas['volume']) || !empty($tank_datas['technical']['volume'])) {
                            
                            $data_save = [
                                'tank' => $tank_datas,
                                'type' => 1,
                                'deal_id' => $deal_id,
                                'group' => ($tank_datas['titre'] . " (p" . ($index + 1) . ")" ?? 'Produit détecté')
                            ];

                            $article_project = apply_filters('ispag_article_save_pdf', null, 0, $data_save);
                            if ($article_project['success']) {
                                $data_save['article_id'] = $article_project['id'];
                                apply_filters('ispag_auto_saver_tank_data', null, $data_save);
                            }
                            $all_extracted_data[] = $tank_datas;
                        } else {
                            self::log("Page " . ($index + 1) . " : Données insuffisantes (pas de volume)");
                        }
                    }
                }
            }
        } catch (Exception $e) {
            self::log("EXCEPTION analyze_pdf_keywords", $e->getMessage());
        }

        return $all_extracted_data;
    }

    /**
     * Interface avec le filtre Mistral (Gère la nouvelle structure JSON)
     */
    private function extract_tank_specs($text, $deal_id = null, $type = 'project') {
        self::log("START extract_tank_specs", ["text" => $text, "deal_id" => $deal_id, "type" => $type]);
        $raw_data = apply_filters('ispag_send_to_mistral', null, $text, $type); 
        self::log("raw_data", ["raw_data" => $raw_data]);
        $tank_list = [];

        // Gestion de la nouvelle structure { "tanks": { "1": {...} } }
        if (isset($raw_data['tanks']) && is_array($raw_data['tanks'])) {
            foreach ($raw_data['tanks'] as $tank) {
                $tank_list[] = $tank;
            }
        } 
        // Gestion de l'ancienne structure (tableau direct)
        elseif (is_array($raw_data) && !empty($raw_data)) {
            $tank_list = $raw_data;
        }

        self::log("Extract Specs result count", count($tank_list));
        return $tank_list;
    }

    public static function ajax_tank_data_extractor() {
        $tank_id = intval($_POST['tankId'] ?? 0);
        $deal_id = intval($_POST['deal_id'] ?? 0);
        self::log("AJAX DXF EXTRACTOR START - NATIVE MODE", $tank_id);

        if (!$tank_id) {
            wp_send_json_error(['message' => 'ID du réservoir manquant.']);
        }
        if (!$deal_id) {
            wp_send_json_error(['message' => 'ID du deal manquant.']);
        }

        $repo = new ISPAG_Tank_Repository();
        $tank_specs = $repo->get_tank_details($tank_id);

        $project_repo = new ISPAG_Projet_Repository();
        $project = $project_repo->get_project_by_deal_id(null, $deal_id);

        if (!$tank_specs) {
            wp_send_json_error(['message' => 'Réservoir introuvable.']);
        }

        // PLUS D'APPEL A MISTRAL ICI
        // On envoie les specs brutes au JS qui va dessiner
        wp_send_json_success([
            'tank_id'       => $tank_id,
            'tank_specs'    => $tank_specs, // On change la clé pour plus de clarté
            'project'       => $project,
            'generated_at'  => current_time('mysql')
        ]);
    }
 
    /**
     * AJAX : Comparaison de conformité entre BDD et Dessin PDF
     */
    public static function ajax_analyze_drawing() {
        $doc_id  = intval($_POST['docId'] ?? 0);
        $tank_id = intval($_POST['tankId'] ?? 0);
        self::log("AJAX ANALYZE DRAWING START", ["Doc" => $doc_id, "Tank" => $tank_id]);

        // Vérification Cache
        $existing_analysis = get_post_meta($doc_id, '_ispag_drawing_analysis', true);
        if (!empty($existing_analysis) && $existing_analysis['tank_id'] == $tank_id) {
            self::log("Returning cached analysis");
            wp_send_json_success(['comparison' => $existing_analysis['data'], 'tank_id' => $tank_id, 'cached' => true]);
        }

        require_once plugin_dir_path(__FILE__) . '../libs/pdfparser/autoload.php';

        $file_path = get_attached_file($doc_id);
        if (!$file_path || !file_exists($file_path)) {
            wp_send_json_error(['message' => 'Fichier introuvable.']);
        }

        try {
            $parser = new \Smalot\PdfParser\Parser();
            $pdf = $parser->parseFile($file_path);
            $drawing_text = trim(preg_replace('/\s+/', ' ', $pdf->getText()));

            $repo = new ISPAG_Tank_Repository();
            $tank_specs = $repo->get_tank_details($tank_id);

            $combined_content = "### SPÉCIFICATIONS ATTENDUES ###\n" . json_encode($tank_specs) . "\n\n### TEXTE DESSIN ###\n" . $drawing_text;
            
            $analysis = apply_filters('ispag_send_to_mistral', null, $combined_content, 'product_drawing');

            if ($analysis) {
                update_post_meta($doc_id, '_ispag_drawing_analysis', ['timestamp' => current_time('mysql'), 'data' => $analysis, 'tank_id' => $tank_id]);
                self::log("Analysis completed and saved");
                wp_send_json_success(['comparison' => $analysis, 'tank_id' => $tank_id]);
            } else {
                wp_send_json_error(['message' => "L'IA n'a pas pu traiter la demande."]);
            }

        } catch (\Exception $e) {
            self::log("EXCEPTION ajax_analyze_drawing", $e->getMessage());
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    /**
     * Extraction forcée de toutes les données (Analyse complète sans filtre mots-clés)
     */
    private function extract_all_datas($file_path, $deal_id, $docType = 'product_drawing'){
        if (!file_exists($file_path)) return;
        set_time_limit(300);
        self::log("START extract_all_datas", $file_path);

        require_once plugin_dir_path(__FILE__) . '../libs/pdfparser/autoload.php';
        $parser = new \Smalot\PdfParser\Parser();
        $all_data = [];

        try {
            $pdf = $parser->parseFile($file_path);
            foreach ($pdf->getPages() as $index => $page) {
                $text = $page->getText();
                if (!empty($text)) {
                    
                    $tanks = $this->extract_tank_specs($text, $deal_id, $docType);
                    foreach ($tanks as $t) {
                        apply_filters('ispag_add_note', null, "Analyse forcée page " . ($index+1), $deal_id, 0, 0, 1);
                        $all_data[] = $t;
                    }
                }
            }
        } catch (Exception $e) {
            self::log("EXCEPTION extract_all_datas", $e->getMessage());
        }
        return $all_data;
    }
}