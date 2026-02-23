<?php
defined('ABSPATH') or die();

class ISPAG_Replicate_Project {
    protected $wpdb;
    protected $table_articles;
    protected $table_project;
    protected $table_tank_dimensions;
    protected static $instance = null;

    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->table_articles = $wpdb->prefix . 'achats_details_commande';
        $this->table_project = $wpdb->prefix . 'achats_liste_commande';
        
    }
    public static function init() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        add_action('ispag_replicate_project', [self::$instance, 'replicate_project_action'], 10, 2);
        add_action('wp_ajax_ispag_duplicate_project', [self::$instance, 'ajax_duplicate_project']);
        // add_action('admin_enqueue_scripts', [self::$instance, 'enqueue_scripts']);
        add_filter('ispag_render_duplicate_button', [self::$instance, 'render_duplicate_button'], 10, 2);

       add_action('wp_enqueue_scripts', [self::$instance, 'enqueue_scripts']);
    }

    // /**
    //  * Enqueue le script JavaScript pour l'AJAX et le bouton.
    //  */
    public function enqueue_scripts($hook) {
        // CORRECTION 2 : Utilisez 'ispag-duplicate' pour l'enregistrement et le localize (voir point 2)
        // CORRECTION 3 : Assurez-vous d'avoir jQuery comme dépendance ! (voir point 3)
        
        $script_url = plugin_dir_url( __FILE__ ) . '../assets/js/duplicate-project.js'; 
        
        wp_enqueue_script(
            'ispag-duplicate', // NOM DE HANLDE UNIQUE
            $script_url, 
            ['jquery'], // DEPENDANCE OBLIGATOIRE
            false, 
            true
        );
        
        // Localisez le script en utilisant le HANDLE d'enregistrement
        wp_localize_script(
            'ispag-duplicate', // UTILISER LE MÊME HANDLE QUE wp_enqueue_script
            'ispag_ajax',
            [
                'ajax_url' => admin_url( 'admin-ajax.php' ),
                'nonce'    => wp_create_nonce( 'ispag_duplicate_nonce' ),
            ]
        );
    }

    /**
     * Affiche le bouton de duplication pour un projet donné.
     * @param int $deal_id L'ID du projet à dupliquer.
     */
    public function render_duplicate_button($html, $deal_id) {
        if (empty($deal_id)) return '';
        
        $button_html = sprintf(
            '<button class="ispag-btn ispag-btn-grey-outlined" id="ispag-duplicate-btn" data-deal-id="%d">'. __('Replicate project', 'creation-reservoir') . ' <i class="fa-solid fa-clone"></i></button>',
            esc_attr($deal_id)
        );
        
        // Vous pouvez ajouter un élément pour les messages de statut
        $status_html = sprintf('<span id="ispag-status-%d" style="margin-left: 10px;"></span>', esc_attr($deal_id));
        
        echo $button_html . $status_html;
    }

    /**
     * Gère l'appel AJAX pour dupliquer le projet.
     */
    public function ajax_duplicate_project() {
        // 1. Vérification du nonce de sécurité
        if ( ! check_ajax_referer( 'ispag_duplicate_nonce', 'security' ) ) {
            wp_send_json_error( ['message' => 'Erreur de sécurité.'] );
        }

        // 2. Vérification des permissions
        if ( ! current_user_can( 'manage_order' ) ) { 
            wp_send_json_error( ['message' => 'Permission refusée.'] );
        }
        
        // 3. Récupération et validation de l'ID
        $deal_id = isset($_POST['deal_id']) ? intval($_POST['deal_id']) : 0;
        if ( $deal_id <= 0 ) {
            wp_send_json_error( ['message' => 'ID de projet invalide.'] );
        }

        // 4. Exécution de la logique de duplication du projet
        $new_deal_id = $this->replicate_project($deal_id);
        
        if ( is_int($new_deal_id) && $new_deal_id > 0 ) {
            // Duplication des articles
            $result_article = $this->replicate_article($deal_id, $new_deal_id);
            
            // CORRECTION DE LA LOGIQUE DE VÉRIFICATION : Teste si le retour est STRICTEMENT TRUE
            if($result_article === true) { 
                wp_send_json_success( 
                    [
                        'message' => 'Projet et articles dupliqués avec succès ! Nouvel ID: ' . $new_deal_id,
                        'new_deal_id' => $new_deal_id
                    ] 
                );
            } else {
                // Échec des articles (result_article est un tableau d'erreurs)
                $error_message = is_array($result_article) ? 
                                 'Projet dupliqué, mais échec sur les articles : ' . implode(' / ', $result_article) : 
                                 'Projet dupliqué, mais échec de duplication des articles.';

                // Envoi d'une réponse d'erreur pour indiquer que le processus n'est pas complet
                wp_send_json_error( [
                    'message' => $error_message,
                    'new_deal_id' => $new_deal_id
                ] );
            }
        } else {
            // Si la duplication du projet a échoué
            $error_message = is_string($new_deal_id) ? $new_deal_id : 'Échec de la duplication du projet.';
            wp_send_json_error( ['message' => $error_message] );
        }
    }


    public function replicate_project_action($html, $deal_id){
        $new_deal_id = $this->replicate_project($deal_id);
        $this->replicate_article($deal_id, $new_deal_id);
    }

    private function replicate_project($deal_id = null){
        if(empty($deal_id)) return 'Aucun ID de defini';

        // 1. Récupération de la ligne
        $row = $this->wpdb->get_row($this->wpdb->prepare("SELECT * FROM $this->table_project WHERE hubspot_deal_id = %d", $deal_id), ARRAY_A);
        
        if (!$row) return "Projet introuvable";

        // CORRECTION 2 : Supprimer la clé primaire 'id' (minuscule)
        if (isset($row['id'])) {
            unset($row['id']);
        }
        if (isset($row['Id'])) {
            unset($row['Id']);
        }

        // Mise à jour des valeurs du nouveau projet
        $row['hubspot_deal_id'] = time(); // Nouvel ID unique (temporaire)
        $row['TimestampDateCommande'] = time(); // Nouvelle date de commande
        // $row['is_copy_from'] = $deal_id; // Nouvelle date de commande

        // 3. Insertion de la nouvelle ligne
        $result = $this->wpdb->insert( $this->table_project, $row);

        if ($result === false) {
            // Log en cas d'échec
//             error_log("ISPAG Duplicate Error (Project): " . $this->wpdb->last_error . " | Query: " . $this->wpdb->last_query);
            return 'Erreur lors de la creation du nouveau projet. (Voir log PHP)';
        }

        if ($this->wpdb->insert_id) {
            return $row['hubspot_deal_id'];
        } else {
            return 'Erreur interne après INSERT réussi.';
        }
    }

    private function replicate_article($deal_id, $new_deal_id = null){
        if(empty($new_deal_id)) return 'Aucun Projet ID défini pour les articles';

        // Récupérer tous les anciens IDs d'articles
        $query = $this->wpdb->prepare(
            "SELECT Id FROM $this->table_articles WHERE hubspot_deal_id = %d",
            $deal_id
        );
        $ids = $this->wpdb->get_col( $query );

        $errors = [];

        foreach ($ids as $article_id) {
            
            // 1. Récupérer la ligne d'article
            $row = $this->wpdb->get_row($this->wpdb->prepare("SELECT * FROM $this->table_articles WHERE Id = %d", $article_id), ARRAY_A);
            
            if (!$row) {
                $errors[] = "Article introuvable: ID $article_id";
                continue; 
            }

            // CORRECTION 2 : Supprimer la clé primaire 'id' (minuscule)
            if (isset($row['id'])) {
                unset($row['id']);
            }
            if (isset($row['Id'])) {
                unset($row['Id']);
            }

            // Mettre à jour les champs pour la duplication
            // $row['sales_price'] = 0;
            // unset($row['DemandeAchatOk']);
            $row['hubspot_deal_id'] = $new_deal_id;
            
            // 2. Insérer le nouvel article
            $result = $this->wpdb->insert( $this->table_articles, $row);

            if ($result === false) {
                // Affiche l'erreur MySQL et la requête qui a échoué dans le log
//                 error_log("### ÉCHEC INSERTION ARTICLE $article_id ###");
//                 error_log("MySQL Error: " . $this->wpdb->last_error);
//                 error_log("Failing Query: " . $this->wpdb->last_query);
                $errors[] = "Échec insertion article $article_id. Voir le log pour l'erreur.";
                continue;
            }
            elseif ($result !== false && $this->wpdb->insert_id) {
                $old_article_id = $article_id;
                $new_article_id = $this->wpdb->insert_id;
                
                // Exécute l'action pour dupliquer les données de la cuve/réservoir
                do_action('ispag_duplicate_tank_data', $old_article_id, $new_article_id);
            } else {
                // L'insertion de l'article a échoué
                $errors[] = "Échec insertion article $article_id. MySQL Error: " . $this->wpdb->last_error;
//                 error_log("ISPAG Article Duplicate Error (Old ID: $article_id): " . $this->wpdb->last_error);
            }
        }
        
        if (!empty($errors)) {
            // Retourne un tableau d'erreurs s'il y a eu un problème sur un ou plusieurs articles
            return $errors; 
        }
        
        // Retourne TRUE en cas de succès complet
        return true;
    }
}