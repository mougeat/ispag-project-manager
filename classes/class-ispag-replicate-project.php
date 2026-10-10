<?php
defined('ABSPATH') or die();

class ISPAG_Replicate_Project {
    protected $wpdb;
    protected $table_articles;
    protected $table_project;
    protected $table_doc;
    protected $table_tank_dimensions;
    
    // Nouvelles tables pour les achats
    protected $table_purchases;
    protected $table_purchases_articles;
    protected $table_purchases_history; // alias ou même table que $table_doc selon l'archi, ici wor9711_achats_historique

    protected static $instance = null;

    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->table_articles = $wpdb->prefix . 'achats_details_commande';
        $this->table_project = $wpdb->prefix . 'achats_liste_commande';
        $this->table_doc = $wpdb->prefix . 'achats_historique'; // ou autre selon projet
        
        // Initialisation des tables d'achats fournisseurs
        $this->table_purchases = $wpdb->prefix . 'achats_commande_liste_fournisseurs';
        $this->table_purchases_articles = $wpdb->prefix . 'achats_articles_cmd_fournisseurs';
        $this->table_purchases_history = $wpdb->prefix . 'achats_historique'; // Table historique / documents achats
    }

    public static function init() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        add_action('ispag_replicate_project', [self::$instance, 'replicate_project_action'], 10, 2);
        add_action('wp_ajax_ispag_duplicate_project', [self::$instance, 'ajax_duplicate_project']);
        add_filter('ispag_render_duplicate_button', [self::$instance, 'render_duplicate_button'], 10, 2);
        add_action('wp_enqueue_scripts', [self::$instance, 'enqueue_scripts']);
    }

    public function enqueue_scripts($hook) {
        $script_url = plugin_dir_url( __FILE__ ) . '../assets/js/duplicate-project.js'; 
        
        wp_enqueue_script(
            'ispag-duplicate',
            $script_url, 
            ['jquery'],
            (int) @filemtime( plugin_dir_path( __FILE__ ) . '../assets/js/duplicate-project.js' ), 
            true
        );
        
        wp_localize_script(
            'ispag-duplicate',
            'ispag_ajax',
            [
                'ajax_url' => admin_url( 'admin-ajax.php' ),
                'nonce'    => wp_create_nonce( 'ispag_duplicate_nonce' ),
                'i18n' => [
                    'select_action'  => __('Please select an action.', 'ispag-crm'),
                    'select_contact' => __('Please select at least one contact.', 'ispag-crm'),
                    'confirm_delete' => __('Are you sure you want to delete the selected contacts?', 'ispag-crm'),
                    'high'           => __('A - High', 'ispag-crm'),
                    'medium'         => __('B - Medium', 'ispag-crm'),
                    'low'            => __('C - Low', 'ispag-crm'),
                    'company_id'     => __('Company ID', 'ispag-crm'),
                    'select_owner'   => __('-- Select owner --', 'ispag-crm'),
                    'preparing'      => __('Preparing', 'ispag-crm'),
                    'prepare_meeting'=> __('Prepare meeting', 'ispag-crm'),
                ]
            ]
        );
    }

    public function render_duplicate_button($html, $deal_id) {
        if (empty($deal_id)) return '';
        
        $button_html = sprintf(
            '<button class="ispag-btn ispag-btn-grey-outlined" id="ispag-duplicate-btn" data-deal-id="%d">'. __('Replicate project', 'creation-reservoir') . ' <i class="fa-solid fa-clone"></i></button>',
            esc_attr($deal_id)
        );
        
        $status_html = sprintf('<span id="ispag-status-%d" style="margin-left: 10px;"></span>', esc_attr($deal_id));
        
        return $button_html . $status_html; // Correction d'affichage propre au lieu d'un echo direct
    }

    public function ajax_duplicate_project() {
        if ( ! check_ajax_referer( 'ispag_duplicate_nonce', 'security' ) ) {
            wp_send_json_error( ['message' => 'Security error.'] );
        }

        if ( ! ISPAG_Capabilities::can_manage_project_actions() ) { 
            wp_send_json_error( ['message' => 'Permission denied.'] );
        }
        
        $deal_id = isset($_POST['deal_id']) ? intval($_POST['deal_id']) : 0;
        if ( $deal_id <= 0 ) {
            wp_send_json_error( ['message' => 'ID de projet invalide.'] );
        }

        // 1. Duplication du projet
        $new_deal_id = $this->replicate_project($deal_id);
        if ( !is_int($new_deal_id) && $new_deal_id <= 0 ) {
            $error_message = is_string($new_deal_id) ? $new_deal_id : 'Project duplication failed.';
            wp_send_json_error( ['message' => $error_message] );
        }

        // 2. Duplication des documents du projet
        $this->replicate_project_document($deal_id, $new_deal_id);
        
        // 3. Duplication des articles du projet (récupération du mapping [ancien_id => nouvel_id])
        $article_mapping = [];
        $result_article = $this->replicate_article($deal_id, $new_deal_id, true, $article_mapping);
        
        if ($result_article !== true) {
            $error_message = is_array($result_article) ? 
                'Project duplicated, but articles failed: ' . implode(' / ', $result_article) : 
                'Project duplicated, but article duplication failed.';

            wp_send_json_error( [
                'message' => $error_message,
                'new_deal_id' => $new_deal_id
            ] );
        }

        // 4. NOUVEAU : Duplication des achats liés au projet (avec leurs articles et documents)
        $result_purchases = $this->replicate_purchases($deal_id, $new_deal_id, $article_mapping);
        if ($result_purchases !== true) {
            $error_message = is_array($result_purchases) ? 
                'Project duplicated with articles, but purchases failed: ' . implode(' / ', $result_purchases) : 
                'Project duplicated with articles, but purchase duplication failed.';

            wp_send_json_error( [
                'message' => $error_message,
                'new_deal_id' => $new_deal_id
            ] );
        }

        // Redirection finale en cas de succès total
        $current_lang = function_exists( 'pll_current_language' ) ? pll_current_language() : 'fr';

        if ( current_user_can( 'navigate_new_project_details_presentation' ) ) {
            $slug = ( $current_lang === 'de' ) ? 'de/project-detail' : 'projectdetail';
        } else {
            $slug = ( $current_lang === 'de' ) ? 'de/project-detail' : 'project-detail';
        }

        $redirect_url = home_url( '/' . $slug . '/' . $new_deal_id );   
    
        wp_send_json_success( [
            'message' => 'Project, articles, documents and purchases duplicated successfully! New ID: ' . $new_deal_id,
            'new_deal_id' => $new_deal_id,
            'redirect_url' => $redirect_url
        ] );
    }

    public function replicate_project_document( $deal_id, $new_deal_id ) {
        if ( empty( $deal_id ) ) {
            return 'No ID defined';
        }

        $query = $this->wpdb->prepare(
            "SELECT * FROM $this->table_doc WHERE hubspot_deal_id = %d",
            $deal_id
        );
        $rows = $this->wpdb->get_results( $query, ARRAY_A );

        if ( empty( $rows ) ) {
            return true;
        }

        $errors = [];

        foreach ( $rows as $row ) {
            $old_doc_id = $row['Id'] ?? $row['id'] ?? null;
            unset( $row['id'], $row['Id'] );

            if ( ! empty( $row['attachment_id'] ) ) {
                $new_attachment_id = $this->duplicate_wp_attachment( $row['attachment_id'] );
                if ( $new_attachment_id ) {
                    $row['attachment_id'] = $new_attachment_id;
                    $row['file_url'] = wp_get_attachment_url( $new_attachment_id );
                } else {
                    $errors[] = "Physical document duplication failed (Attachment ID: {$row['attachment_id']})";
                    continue;
                }
            }

            $row['hubspot_deal_id'] = $new_deal_id;
            $inserted = $this->wpdb->insert( $this->table_doc, $row );

            if ( false === $inserted ) {
                $errors[] = "Error lors de l'insertion en BDD du document initial ID : " . $old_doc_id;
            }
        }

        return empty( $errors ) ? true : $errors;
    }

    private function duplicate_wp_attachment( $attachment_id ) {
        $file_path = get_attached_file( $attachment_id );
        if ( ! $file_path || ! file_exists( $file_path ) ) {
            return false;
        }

        $info = pathinfo( $file_path );
        $new_file_path = $info['dirname'] . '/' . $info['filename'] . '-copy-' . time() . '.' . $info['extension'];

        if ( ! copy( $file_path, $new_file_path ) ) {
            return false;
        }

        $post = get_post( $attachment_id );
        $file_type = wp_check_filetype( basename( $new_file_path ), null );
        $attachment = [
            'post_mime_type' => $file_type['type'],
            'post_title'     => ($post ? $post->post_title : 'Document') . ' (Copie)',
            'post_content'   => $post ? $post->post_content : '',
            'post_status'    => 'inherit',
        ];

        $new_attach_id = wp_insert_attachment( $attachment, $new_file_path );

        if ( ! is_wp_error( $new_attach_id ) ) {
            require_once( ABSPATH . 'wp-admin/includes/image.php' );
            $attach_data = wp_generate_attachment_metadata( $new_attach_id, $new_file_path );
            wp_update_attachment_metadata( $new_attach_id, $attach_data );
            return $new_attach_id;
        }

        return false;
    }

    public function replicate_project_action($html, $deal_id){
        $new_deal_id = $this->replicate_project($deal_id);
        $article_mapping = [];
        $this->replicate_article($deal_id, $new_deal_id, false, $article_mapping);
        $this->replicate_purchases($deal_id, $new_deal_id, $article_mapping);
    }

    private function replicate_project($deal_id = null){
        if(empty($deal_id)) return 'Aucun ID de defini';

        $row = $this->wpdb->get_row($this->wpdb->prepare("SELECT * FROM $this->table_project WHERE hubspot_deal_id = %d", $deal_id), ARRAY_A);
        
        if (!$row) return "Projet introuvable";

        unset($row['id'], $row['Id']);

        $row['hubspot_deal_id'] = time(); 
        $row['TimestampDateCommande'] = time(); 
        if(isset($row['version'])) {
            $row['version'] = (int) $row['version'] + 1;
        }

        $result = $this->wpdb->insert( $this->table_project, $row);

        if ($result === false) {
            return 'Error lors de la creation du nouveau projet.';
        }

        return $this->wpdb->insert_id ? $row['hubspot_deal_id'] : 'Internal error after successful INSERT.';
    }

    private function replicate_article($deal_id, $new_deal_id = null, $copy_price = false, &$article_mapping = []){
        if(empty($new_deal_id)) return 'No project ID defined for the articles';

        $query = $this->wpdb->prepare(
            "SELECT Id FROM $this->table_articles WHERE hubspot_deal_id = %d",
            $deal_id
        );
        $ids = $this->wpdb->get_col( $query );

        $errors = [];

        foreach ($ids as $article_id) {
            $row = $this->wpdb->get_row($this->wpdb->prepare("SELECT * FROM $this->table_articles WHERE Id = %d", $article_id), ARRAY_A);
            
            if (!$row) {
                $errors[] = "Article introuvable: ID $article_id";
                continue; 
            }

            unset($row['id'], $row['Id']);

            $row['hubspot_deal_id'] = $new_deal_id;
            $row['created_at'] = date('Y-m-d H:i:s');

            if($copy_price && isset($row['sales_price']) && $row['sales_price'] == 0 && class_exists('ISPAG_Article_Pricing')){
                $pricing = new ISPAG_Article_Pricing();
                $row['sales_price'] = $pricing->calculate_sales_price($article_id);
                $row['is_manual_price'] = 1;
            }
            
            $result = $this->wpdb->insert( $this->table_articles, $row);

            if ($result === false) {
                $errors[] = "Article insertion failed: $article_id.";
                continue;
            } elseif ($this->wpdb->insert_id) {
                $old_article_id = $article_id;
                $new_article_id = $this->wpdb->insert_id;
                
                // Remplir le tableau de correspondance (mapping)
                $article_mapping[$old_article_id] = $new_article_id;
                
                do_action('ispag_duplicate_tank_data', $old_article_id, $new_article_id);
                do_action('ispag_duplicate_exchanger_data', $old_article_id, $new_article_id);
            }
        }
        
        return empty($errors) ? true : $errors;
    }

    /**
     * NOUVELLE MÉTHODE : Duplication des achats, de leurs articles et de leurs documents/historiques liés.
     */
    private function replicate_purchases($deal_id, $new_deal_id, $article_mapping) {
        // 1. Récupérer toutes les commandes d'achats liées à l'ancien hubspot_deal_id
        $query = $this->wpdb->prepare(
            "SELECT * FROM $this->table_purchases WHERE hubspot_deal_id = %s",
            $deal_id
        );
        $purchases = $this->wpdb->get_results( $query, ARRAY_A );

        if (empty($purchases)) {
            return true; // Pas d'achats, rien à dupliquer
        }

        $errors = [];

        foreach ($purchases as $purchase) {
            $old_purchase_id = $purchase['Id'] ?? null;
            unset($purchase['Id']);

            // Mettre à jour l'identifiant du projet vers le nouveau deal ID
            // Suivant le type de champ (text ou int), on adapte l'affectation
            $purchase['hubspot_deal_id'] = (string) $new_deal_id; 
            $purchase['TimestampDateCreation'] = time();

            // Insérer la nouvelle commande d'achat
            $inserted = $this->wpdb->insert($this->table_purchases, $purchase);

            if (false === $inserted) {
                $errors[] = "Purchase order duplication failed, ID: " . $old_purchase_id;
                continue;
            }

            $new_purchase_id = $this->wpdb->insert_id;

            // 2. Dupliquer les articles de cette commande d'achat
            if ($old_purchase_id) {
                $this->replicate_purchase_articles($old_purchase_id, $new_purchase_id, $article_mapping, $errors);
            }

            // 3. Dupliquer l'historique / documents liés à cet achat
            if ($old_purchase_id) {
                $this->replicate_purchase_documents($old_purchase_id, $new_purchase_id, $new_deal_id, $errors);
            }
        }

        return empty($errors) ? true : $errors;
    }

    /**
     * Duplique les lignes d'articles d'achats et réassocie `IdCommandeClient` grâce au mapping.
     */
    private function replicate_purchase_articles($old_purchase_id, $new_purchase_id, $article_mapping, &$errors) {
        $query = $this->wpdb->prepare(
            "SELECT * FROM $this->table_purchases_articles WHERE IdCommande = %d",
            $old_purchase_id
        );
        $articles = $this->wpdb->get_results($query, ARRAY_A);

        foreach ($articles as $article) {
            unset($article['Id']);

            // Rattacher au nouvel ID de commande d'achat fournisseur
            $article['IdCommande'] = $new_purchase_id;

            // Mettre à jour l'ID de l'article client (IdCommandeClient) avec le nouveau correspondant
            $old_client_article_id = $article['IdCommandeClient'];
            if (isset($article_mapping[$old_client_article_id])) {
                $article['IdCommandeClient'] = $article_mapping[$old_client_article_id];
            }

            $inserted = $this->wpdb->insert($this->table_purchases_articles, $article);
            if (false === $inserted) {
                $errors[] = "Purchase article insertion failed for order $new_purchase_id.";
            }
        }
    }

    /**
     * Duplique l'historique et les fichiers/documents joints aux achats (`wor9711_achats_historique`)
     */
    private function replicate_purchase_documents($old_purchase_id, $new_purchase_id, $new_deal_id, &$errors) {
        $query = $this->wpdb->prepare(
            "SELECT * FROM $this->table_purchases_history WHERE purchase_order = %d",
            $old_purchase_id
        );
        $history_rows = $this->wpdb->get_results($query, ARRAY_A);

        foreach ($history_rows as $row) {
            $old_history_id = $row['Id'] ?? null;
            unset($row['Id']);

            // $row['hubspot_deal_id'] = $new_deal_id;
            $row['purchase_order'] = $new_purchase_id;

            // Si la table gère aussi des fichiers physiques (via une colonne IdMedia ou équivalent dans ton schéma)
            if (!empty($row['IdMedia'])) {
                $new_media_id = $this->duplicate_wp_attachment($row['IdMedia']);
                if ($new_media_id) {
                    $row['IdMedia'] = $new_media_id;
                }
            }

            $inserted = $this->wpdb->insert($this->table_purchases_history, $row);
            if (false === $inserted) {
                $errors[] = "Purchase history/document insertion failed (original ID: $old_history_id).";
            }
        }
    }
}