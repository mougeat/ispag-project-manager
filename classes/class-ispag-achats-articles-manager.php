<?php
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class ISPAG_Achats_Articles_Manager
{
    private $table_name;
    private $type_prestations_table;
    private $price_history_table;
    private $purchase_supplier_table;

    /**
     * Constructor: Initializes the table names and registers hooks.
     */
    public function __construct()
    {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'achats_articles';
        $this->type_prestations_table = $wpdb->prefix . 'achats_type_prestations';
        $this->price_history_table = $wpdb->prefix . 'achats_articles_price_history';
        $this->purchase_supplier_table = $wpdb->prefix . 'achats_articles_purchase';

        // Register the shortcode
        add_shortcode('ispag_articles_table', [$this, 'render_articles_table_shortcode']);

        // Enqueue scripts and styles
        add_action('wp_enqueue_scripts', [$this, 'enqueue_scripts'], 30); // Priorité élevée pour éviter les conflits

        // AJAX actions pour les utilisateurs connectés
        add_action('wp_ajax_ispag_delete_standard_article', [$this, 'handle_delete_article_ajax']);
        add_action('wp_ajax_ispag_get_standard_article_data', [$this, 'handle_get_article_data_ajax']);
        add_action('wp_ajax_ispag_update_standard_article', [$this, 'handle_update_article_ajax']);
    }

    // --- Enqueue Scripts and Styles ---
    public function enqueue_scripts()
    {
        // Désactiver jQuery Migrate pour éviter les conflits
        // wp_deregister_script('jquery-migrate');

        // Charger le script JS externe
        wp_enqueue_script(
            'ispag-articles-modal',
            plugins_url('assets/js/ispag-articles-modal.js', __DIR__),
            ['jquery'],
            filemtime(plugin_dir_path(__DIR__) . '/assets/js/ispag-articles-modal.js'),
            true
        );

        // Localiser le script avec les données nécessaires
        wp_localize_script('ispag-articles-modal', 'ispagArticlesAjax', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('ispag_article_nonce'),
            'get_article_nonce' => wp_create_nonce('ispag_get_article_nonce'),
            'update_article_nonce' => wp_create_nonce('ispag_update_article_nonce'),
            'confirm_delete' => __('Are you sure you want to delete this article?', 'creation-reservoir'),
            'currency' => get_option('wpcb_currency', '€'),
            'loading_text' => __('Loading...', 'creation-reservoir'),
            'saving_text' => __('Saving...', 'creation-reservoir'),
            'save_text' => __('Save', 'creation-reservoir'),
            'error_text' => __('An error occurred. Please try again.', 'creation-reservoir')
        ]);
    }

    // --- Database Methods ---
    public function get_all_articles(): array
    {
        global $wpdb;
        return $wpdb->get_results("SELECT * FROM {$this->table_name}", ARRAY_A);
    }

    public function get_article_by_id(int $id): ?array
    {
        global $wpdb;
        return $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$this->table_name} WHERE Id = %d", $id),
            ARRAY_A
        );
    }

    public function get_type_prestation_name(int $type_id): string
    {
        global $wpdb;
        $type_name = $wpdb->get_var(
            $wpdb->prepare("SELECT type FROM {$this->type_prestations_table} WHERE Id = %d", $type_id)
        );
        return $type_name ? esc_html($type_name) : '<span class="suivi-status-badge">' . __('Unknown', 'creation-reservoir') . '</span>';
    }

    public function get_all_types_prestations(): array
    {
        global $wpdb;
        return $wpdb->get_results(
            "SELECT Id, type FROM {$this->type_prestations_table}",
            ARRAY_A
        );
    }

    /**
     * Récupère le dernier prix de vente valide pour un article
     * @param int $article_id ID de l'article
     * @return float Prix de vente ou 0.0 si non trouvé
     */
    public function get_latest_sales_price(int $article_id): float
    {
        global $wpdb;

        // Récupérer le prix le plus récent qui est valide aujourd'hui ou sans date de fin
        $price = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT sales_price
                FROM {$this->price_history_table}
                WHERE article_id = %d
                AND (valid_to IS NULL OR valid_to >= CURDATE())
                ORDER BY valid_from DESC, created_at DESC
                LIMIT 1",
                $article_id
            )
        );

        // Si aucun prix valide trouvé, prendre le dernier prix enregistré
        if ($price === null) {
            $price = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT sales_price
                    FROM {$this->price_history_table}
                    WHERE article_id = %d
                    ORDER BY valid_from DESC, created_at DESC
                    LIMIT 1",
                    $article_id
                )
            );
        }

        return $price !== null ? floatval($price) : 0.0;
    }

    public function filter_articles(string $search = '', ?int $type_filter = null): array
    {
        global $wpdb;
        $query = "SELECT * FROM {$this->table_name} WHERE 1=1";

        if (!empty($search)) {
            $search_term = '%' . $wpdb->esc_like($search) . '%';
            $query .= $wpdb->prepare(
                " AND (TitreArticle LIKE %s OR ref_article_ispag LIKE %s)",
                $search_term,
                $search_term
            );
        }

        if ($type_filter !== null) {
            $query .= $wpdb->prepare(" AND TypeArticle = %d", $type_filter);
        }

        return $wpdb->get_results($query, ARRAY_A);
    }

    public function delete_article(int $id)
    {
        global $wpdb;
        return $wpdb->delete($this->table_name, ['Id' => $id]);
    }

    public function update_article(int $id, array $data)
    {
        global $wpdb;
        return $wpdb->update($this->table_name, $data, ['Id' => $id]);
    }

    /**
     * Récupère TOUS les fournisseurs pour un article avec leurs noms
     *
     * @param int $article_id ID de l'article
     * @return array Tableau de fournisseurs
     */
    private function get_suppliers_data(int $article_id): array
    {
        global $wpdb;

        // Nom de la table des fournisseurs
        $suppliers_table = $wpdb->prefix . 'ispag_companies';

        // Requête avec LEFT JOIN pour récupérer tous les fournisseurs
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT
                    ps.supplier_id,
                    ps.supplier_reference as fournisseur_ref,
                    ps.supplier_description as fournisseur_description,
                    ps.purchase_price as prix_achat,
                    ps.discount as discount,
                    f.company_name as fournisseur_nom,
                    f.Id as fournisseur_id,
                    f.email as fournisseur_mail,
                    f.phone as fournisseur_tel,
                    (SELECT m.meta_value FROM {$wpdb->prefix}ispag_companies_meta m WHERE m.company_id = f.Id AND m.meta_key = 'ispag_company_adress' ORDER BY m.meta_id DESC LIMIT 1) as fournisseur_adresse
                FROM {$this->purchase_supplier_table} ps
                LEFT JOIN {$suppliers_table} f ON ps.supplier_id = f.Id
                WHERE ps.article_id = %d
                ORDER BY f.company_name ASC",  // Tri par nom de fournisseur
                $article_id
            ),
            ARRAY_A
        );
    }

    // --- AJAX Handlers ---
    public function handle_delete_article_ajax()
    {
        // Vérifier le nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'ispag_article_nonce')) {
            wp_send_json_error(['message' => __('Invalid nonce', 'creation-reservoir')]);
        }

        if (!current_user_can('edit_posts')) {
            wp_send_json_error(['message' => __('Unauthorized', 'creation-reservoir')]);
        }

        $article_id = isset($_POST['article_id']) ? intval($_POST['article_id']) : 0;
        if (!$article_id) {
            wp_send_json_error(['message' => __('Invalid article ID', 'creation-reservoir')]);
        }

        $result = $this->delete_article($article_id);

        if ($result !== false) {
            wp_send_json_success(['message' => __('Article deleted successfully!', 'creation-reservoir')]);
        } else {
            wp_send_json_error(['message' => __('Failed to delete article', 'creation-reservoir')]);
        }
    }

    public function handle_get_article_data_ajax()
    {
        // Vérifier le nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'ispag_get_article_nonce')) {
            wp_send_json_error(['message' => __('Invalid nonce', 'creation-reservoir')]);
        }

        if (!current_user_can('edit_posts')) {
            wp_send_json_error(['message' => __('Unauthorized', 'creation-reservoir')]);
        }

        $article_id = isset($_POST['article_id']) ? intval($_POST['article_id']) : 0;
        if (!$article_id) {
            wp_send_json_error(['message' => __('Invalid article ID', 'creation-reservoir')]);
        }

        $article = $this->get_article_by_id($article_id);
        if (!$article) {
            wp_send_json_error(['message' => __('Article not found', 'creation-reservoir')]);
        }

        try {
            $this->render_article_edit_modal($article);
        } catch (Exception $e) {
            wp_send_json_error(['message' => __('Error loading article: ', 'creation-reservoir') . $e->getMessage()]);
        }
    }

    public function handle_update_article_ajax()
    {
        // Vérifier le nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'ispag_update_article_nonce')) {
            wp_send_json_error(['message' => __('Invalid nonce', 'creation-reservoir')]);
        }

        if (!current_user_can('edit_posts')) {
            wp_send_json_error(['message' => __('Unauthorized', 'creation-reservoir')]);
        }

        $article_id = isset($_POST['article_id']) ? intval($_POST['article_id']) : 0;
        if (!$article_id) {
            wp_send_json_error(['message' => __('Invalid article ID', 'creation-reservoir')]);
        }

        // Sanitize all input data
        $data = [
            'TitreArticle' => isset($_POST['TitreArticle']) ? sanitize_text_field($_POST['TitreArticle']) : '',
            'ref_article_ispag' => isset($_POST['ref_article_ispag']) ? sanitize_text_field($_POST['ref_article_ispag']) : '',
            'TypeArticle' => isset($_POST['TypeArticle']) ? intval($_POST['TypeArticle']) : 0,
            'fournisseur_nom' => isset($_POST['fournisseur_nom']) ? sanitize_text_field($_POST['fournisseur_nom']) : '',
            'fournisseur_ref' => isset($_POST['fournisseur_ref']) ? sanitize_text_field($_POST['fournisseur_ref']) : '',
            'fournisseur_description' => isset($_POST['fournisseur_description']) ? sanitize_textarea_field($_POST['fournisseur_description']) : '',
            'prix_achat' => isset($_POST['prix_achat']) ? floatval($_POST['prix_achat']) : 0,
            'discount' => isset($_POST['discount']) ? floatval($_POST['discount']) : 0,
            'Poids' => isset($_POST['Poids']) ? floatval($_POST['Poids']) : 0,
            'UnitePoids' => isset($_POST['UnitePoids']) ? sanitize_text_field($_POST['UnitePoids']) : '',
            'delivery_time' => isset($_POST['delivery_time']) ? intval($_POST['delivery_time']) : 0,
        ];

        $result = $this->update_article($article_id, $data);

        if ($result !== false) {
            wp_send_json_success(['message' => __('Article updated successfully!', 'creation-reservoir')]);
        } else {
            wp_send_json_error(['message' => __('Failed to update article', 'creation-reservoir')]);
        }
    }

    private function render_article_edit_modal(array $article): void
    {
        $types = $this->get_all_types_prestations();
        $type_options = '';
        foreach ($types as $type) {
            $selected = selected($article['TypeArticle'], $type['Id'], false);
            $type_options .= '<option value="' . esc_attr($type['Id']) . '" ' . $selected . '>' . esc_html($type['type']) . '</option>';
        }

        $latest_sales_price = $this->get_latest_sales_price($article['Id']);
        $supplier_data = $this->get_suppliers_data($article['Id']);
        $currency = get_option('wpcb_currency', '€');

        $image_id = isset($article['image']) ? intval($article['image']) : 0;
        $image_html = $this->get_image_html($image_id, $article['TitreArticle']);

        // Load the modal template
        ob_start();
        include plugin_dir_path(__FILE__) . 'templates/ispag-article-edit-modal.php';
        $modal_content = ob_get_clean();

        wp_send_json_success(['form' => $modal_content]);
    }

    // --- Shortcode Render ---
    public function render_articles_table_shortcode(): string
    {
        $search = isset($_GET['search']) ? sanitize_text_field($_GET['search']) : '';
        $type_filter = isset($_GET['type_filter']) ? intval($_GET['type_filter']) : null;
        $articles = $this->filter_articles($search, $type_filter);
        $types = $this->get_all_types_prestations();

        ob_start();
        include plugin_dir_path(__FILE__) . 'templates/ispag-articles-table.php';
        return ob_get_clean();
    }
    public function get_image_html(int $image_id, string $alt = ''): string
    {
        if (!$image_id) {
            return '';
        }

        return wp_get_attachment_image($image_id, 'medium', false, ['alt' => $alt, 'class' => 'responsive-svg']);
    }

    // public function display_modal(): string
    // {
    //     return '<div id="ispag-modal-product" class="ispag-product-modal" style="display:none;">
    //         <div class="ispag-modal-content">
    //             <span class="ispag-modal-close ispag-btn ispag-btn-red-outlined">&times;</span>
    //             <div id="ispag-modal-body"></div>
    //         </div>
    //     </div>';
    // }
}