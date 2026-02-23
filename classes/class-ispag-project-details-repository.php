<?php

class ISPAG_Project_Details_Repository {
    private $table;
    private $table_details;
    private $wpdb;
    protected static $instance = null;

    public function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . 'achats_info_commande';
        $this->table_details = $wpdb->prefix . 'achats_details_commande';
        $this->wpdb = $wpdb;
    }

    public static function init() {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        add_filter('ispag_display_deal_stats', [self::class, 'build_deal_stats_html'], 10, 2);
        add_filter('wp_ajax_ispag_display_deal_stats', [self::class, 'ispag_handle_ajax_deal_stats'], 1);
        add_filter('ispag_get_project_discount', [self::$instance, 'get_project_discount'], 2);
        
    }


    public function get_infos_livraison($deal_id) {
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare("SELECT * FROM {$this->table} WHERE hubspot_deal_id = %d", $deal_id)
        );

        if ($row) {
            return $row;
        }

        // Retourne un objet par défaut si rien trouvé
        return (object)[
            'hubspot_deal_id' => $deal_id,
            'AdresseDeLivraison' => '',
            'DeliveryAdresse2' => '',
            'Postal code' => '',
            'City' => '',
            'Comment' => '',
            'PersonneContact' => '',
            'num_tel_contact' => '',
            'ConfCommande' => '',
            'unloadingFacilities' => 0,
        ];
    }

    public static function ispag_handle_ajax_deal_stats() {
        if ( ! current_user_can( 'manage_order' ) ) {
            wp_send_json_error( array( 'message' => 'Accès refusé.' ) ); 
        }
 
        // Récupération du deal_id depuis POST (car l'appel JS est POST)
        // $deal_id = filter_input(INPUT_POST, 'deal_id', FILTER_VALIDATE_INT);
        $deal_id = isset( $_GET['deal_id'] ) ? intval( $_GET['deal_id'] ) : null;
        $deal_id = (empty( $deal_id ) && isset( $_POST['deal_id'] )) ? intval( $_POST['deal_id'] ) : null;
        
        if ( ! $deal_id ) {
            $output = '<div id="ispag_project_stat" class="isp-stats-box error">ID de projet manquant dans la requête AJAX.</div>';
            wp_send_json_success( array( 'html' => $output ) );
        }
        
        // On appelle la fonction de construction du HTML
        $output = self::build_deal_stats_html(null, $deal_id );
        
        
        // On renvoie la réponse au format JSON (avec 'html' à l'intérieur de 'data')
        wp_send_json_success( array( 'html' => $output ) );
    }

    // La fonction doit être placée dans une classe, ici je la laisse statique comme dans votre exemple.

    public static function build_deal_stats_html($html, $deal_id) {
    $text_domain = 'creation-reservoir';
    global $wpdb;
    $instance = new self($wpdb);

    if (!current_user_can('manage_order')) return '';

    if ($deal_id === 0) {
        return '<div class="ispag-stats-alert error">' . esc_html__('No project specified.', $text_domain) . '</div>';
    }

    $projet = apply_filters('ispag_get_project_by_deal_id', null, $deal_id);
    $revenu_total = floatval($projet ? $projet->total_amount : 0);
    $cout_projet = 0;

    $achats = apply_filters('ispag_get_achats', null, null, false, $deal_id);
    foreach ($achats as $achat) {
        $articles = apply_filters('ispag_get_articles_by_order', null, $achat->Id);
        foreach ($articles as $article) {
            $cout_projet += floatval($article->total_price);
        }
    }

    $gain_chf = $revenu_total - $cout_projet;
    $marge_pourcentage = ($revenu_total > 0) ? ($gain_chf / $revenu_total) * 100 : 0;
    
    // Classes de couleur selon la rentabilité
    $marge_class = ($marge_pourcentage < 20) ? 'is-low' : (($marge_pourcentage < 35) ? 'is-average' : 'is-good');

    $output = '<div id="ispag_project_stat" class="ispag-stats-container">';
    $output .= '<h4 class="ispag-stats-title">' . esc_html__('Project Dashboard', $text_domain) . '</h4>';
    
    $output .= '<div class="ispag-stats-grid">';

    // Card : Revenu
    $output .= $instance->render_stat_card(__('Total Sales', $text_domain), number_format_i18n($revenu_total, 2) . ' CHF', 'blue');

    // Card : Coût
    $output .= $instance->render_stat_card(__('Total Cost', $text_domain), number_format_i18n($cout_projet, 2) . ' CHF', 'red');

    // Card : Gain (Marge brute)
    $output .= $instance->render_stat_card(__('Project Gain', $text_domain), number_format_i18n($gain_chf, 2) . ' CHF', 'green');

    // Card : Pourcentage de Marge
    $output .= $instance->render_stat_card(__('Margin %', $text_domain), number_format_i18n($marge_pourcentage, 2) . ' %', $marge_class);

    // Card : Discount
    $output .= $instance->render_stat_card(__('Discount', $text_domain), htmlspecialchars($instance->get_project_discount(null, $deal_id)) . ' %', 'gray');

    // Card : Coef de vente (Sélecteur)
    $output .= '<div class="ispag-stat-card is-coef">';
    $output .= '<span class="stat-label">Coefficient de vente</span>';
    $output .= '<div class="stat-value">' . apply_filters('ispag_render_sales_coef_selector', '', $deal_id) . '</div>';
    $output .= '</div>';

    $output .= '</div>'; // .ispag-stats-grid
    $output .= '</div>'; // #ispag_project_stat

    return $output;
}

// Petite fonction helper pour la propreté (à ajouter dans ta classe)
private function render_stat_card($label, $value, $class = '') {
    return sprintf(
        '<div class="ispag-stat-card %s"><span class="stat-label">%s</span><span class="stat-value">%s</span></div>',
        esc_attr($class),
        esc_html($label),
        esc_html($value)
    );
}

    public function get_project_discount($html, $deal_id = null){
        
        if(empty($deal_id)){ return; }

        global $wpdb;

        $sql_select = $wpdb->prepare(
            "
            SELECT discount 
            FROM {$this->table_details }
            WHERE hubspot_deal_id = %d 
            GROUP BY discount 
            HAVING COUNT(*) = (SELECT COUNT(*) FROM {$this->table_details } WHERE hubspot_deal_id = %d)
            ",
            $deal_id,
            $deal_id 
        );
        
        $result = $wpdb->get_var($sql_select); 

        return $result !== null ? $result : 'Non uniforme';
        

    }
    

}
 