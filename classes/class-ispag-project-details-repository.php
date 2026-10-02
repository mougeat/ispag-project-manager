<?php
/**
 * Class ISPAG_Project_Details_Repository
 * Gère les détails et statistiques des projets ISPAG.
 * Logging : Toutes les actions sont loguées dans ispag_project_details_repository.log.
 */
class ISPAG_Project_Details_Repository
{
    private $table;
    private $table_details;
    private $wpdb;
    protected static $instance = null;
    private const LOG_NAME = 'project_details_repository';

    /** @var ISPAG_Logger Instance du logger. */
    private $logger;

    public function __construct($wpdb = null)
    {
        global $wpdb;
        $this->wpdb = $wpdb ?: $wpdb;
        $this->logger = ISPAG_Logger::get_instance();
        $user_id = get_current_user_id();

        $this->table = $this->wpdb->prefix . 'achats_info_commande';
        $this->table_details = $this->wpdb->prefix . 'achats_details_commande';

        // $this->logger->log_user_action(self::LOG_NAME, 'class_constructed', [], $user_id);
    }

    public static function init()
    {
        $user_id = get_current_user_id();
        $logger = ISPAG_Logger::get_instance();

        if (self::$instance === null)
        {
            self::$instance = new self();
            // $logger->log_user_action(self::LOG_NAME, 'instance_initialized', [], $user_id);
        }

        add_filter('ispag_display_deal_stats', [self::class, 'build_deal_stats_html'], 10, 2);
        add_filter('wp_ajax_ispag_display_deal_stats', [self::class, 'ispag_handle_ajax_deal_stats'], 1);
        add_filter('ispag_get_project_discount', [self::$instance, 'get_project_discount'], 2);

        // $logger->log_user_action(self::LOG_NAME, 'hooks_and_filters_registered', [], $user_id);
    }

    public function get_infos_livraison($deal_id)
    {
        $user_id = get_current_user_id();
        $this->logger->log_user_action(self::LOG_NAME, 'get_infos_livraison_start', ['deal_id' => $deal_id], $user_id);

        $row = $this->wpdb->get_row(
            $this->wpdb->prepare("SELECT * FROM {$this->table} WHERE hubspot_deal_id = %d", $deal_id)
        );

        $this->logger->log_db_change(self::LOG_NAME, $this->table, 'FETCH_DELIVERY_INFO', ['deal_id' => $deal_id, 'result' => !empty($row)], $user_id);

        if ($row)
        {
            $this->logger->log_user_action(self::LOG_NAME, 'delivery_info_found', ['deal_id' => $deal_id], $user_id);
            return $row;
        }

        $this->logger->log_user_action(self::LOG_NAME, 'no_delivery_info_found_using_defaults', ['deal_id' => $deal_id], $user_id);

        // Retourne un objet par défaut si rien trouvé
        return (object)[
            'hubspot_deal_id' => $deal_id,
            'AdresseDeLivraison' => '',
            'DeliveryAdresse2' => '',
            'DeliveryAdresse3' => '',
            'NIP' => '',
            'City' => '',
            'Comment' => '',
            'PersonneContact' => '',
            'num_tel_contact' => '',
            'ConfCommande' => '',
            'unloadingFacilities' => 0,
        ];
    }

    public static function ispag_handle_ajax_deal_stats()
    {
        $user_id = get_current_user_id();
        $logger = ISPAG_Logger::get_instance();
        $logger->log_user_action(self::LOG_NAME, 'ispag_handle_ajax_deal_stats_start', [], $user_id);

        if (!current_user_can('manage_order') || !current_user_can('display_sales_prices'))
        {
            $logger->log(self::LOG_NAME, 'ERROR: User not allowed to see project statistics', $user_id);
            wp_send_json_error(['message' => 'Access denied.']);
        }

        // Récupération du deal_id depuis POST ou GET
        $deal_id = get_query_var('deal_id') ?: ($_GET['deal_id'] ?? null);
        $deal_id = (empty($deal_id) && isset($_POST['deal_id'])) ? intval($_POST['deal_id']) : null;

        if (!$deal_id)
        {
            $logger->log(self::LOG_NAME, 'ERROR: Missing deal_id in AJAX request', $user_id);
            $output = '<div id="ispag_project_stat" class="isp-stats-box error">' . __('Project ID missing from the AJAX request.', 'creation-reservoir') . '</div>';
            wp_send_json_success(['html' => $output]);
        }

        $logger->log_user_action(self::LOG_NAME, 'deal_id_received', ['deal_id' => $deal_id], $user_id);

        // On appelle la fonction de construction du HTML
        $output = self::build_deal_stats_html(null, $deal_id);

        $logger->log_user_action(self::LOG_NAME, 'ispag_handle_ajax_deal_stats_complete', [], $user_id);

        // On renvoie la réponse au format JSON (avec 'html' à l'intérieur de 'data')
        wp_send_json_success(['html' => $output]);
    }

    /**
     * Calcule la rentabilité complète d'un projet (Deal)
     * @param int $deal_id
     * @return array
     */
    public function get_project_profitability($deal_id)
    {
        $user_id = get_current_user_id();
        $this->logger->log_user_action(self::LOG_NAME, 'get_project_profitability_start', ['deal_id' => $deal_id], $user_id);

        $deal_id = intval($deal_id);

        // 1. Revenu (Vente)
        $revenu_total = floatval(ISPAG_Projet_Repository::ispag_calculate_deal_total_sales($deal_id));
        $this->logger->log_db_change(self::LOG_NAME, 'projects', 'FETCH_PROJECT', ['deal_id' => $deal_id, 'revenu_total' => $revenu_total], $user_id);

        // 2. Coûts (Achats) — Appel direct de la méthode optimisée (SQL SUM)
        $achat_repo = new ISPAG_Achat_Repository;
        $project_coast = $achat_repo->get_purchase_total_by_deal_id(null, $deal_id);
        $cout_projet = floatval($project_coast);
         
        // Si la méthode est dans la même classe/repository, vous pouvez aussi l'appeler directement :
        // $cout_projet = $this->get_purchase_total_by_deal_id(null, $deal_id);

        $this->logger->log_db_change(self::LOG_NAME, 'achats', 'FETCH_TOTAL_ACHATS', ['deal_id' => $deal_id, 'cout_projet' => $cout_projet], $user_id);

        // 3. Calculs des indicateurs
        $gain_chf = $revenu_total - $cout_projet;
        $marge_pourcentage = ($revenu_total > 0) ? ($gain_chf / $revenu_total) * 100 : 0;

        $this->logger->log_user_action(self::LOG_NAME, 'profitability_calculated', [
            'revenu' => $revenu_total,
            'cout'   => $cout_projet,
            'gain'   => $gain_chf,
            'marge'  => $marge_pourcentage
        ], $user_id);

        return [
            'revenu' => $revenu_total,
            'cout'   => $cout_projet,
            'gain'   => $gain_chf,
            'marge'  => $marge_pourcentage,
            'status' => ($marge_pourcentage < 20) ? 'is-low' : (($marge_pourcentage < 35) ? 'is-average' : 'is-good')
        ];
    }

    /**
     * Récupère et calcule toutes les données statistiques d'un deal.
     * Idéal pour les appels AJAX ou les traitements métiers.
     */
    public static function get_deal_stats_data($deal_id)
    {
        $user_id = get_current_user_id();
        $logger = ISPAG_Logger::get_instance();
        $logger->log_user_action(self::LOG_NAME, 'get_deal_stats_data_start', ['deal_id' => $deal_id], $user_id);

        global $wpdb;
        $instance = new self($wpdb);

        if (!current_user_can('manage_order') || !current_user_can('display_sales_prices'))
        {
            $logger->log(self::LOG_NAME, 'ERROR: User not allowed to see project statistics', $user_id);
            return ['error' => 'not_allowed', 'message' => __('User not allowed to manage order.', 'creation-reservoir')];
        }

        if ($deal_id === 0)
        {
            $logger->log(self::LOG_NAME, 'ERROR: No project specified', $user_id);
            return ['error' => 'no_project', 'message' => __('No project specified.', 'creation-reservoir')];
        }

        $stats = $instance->get_project_profitability($deal_id);
        $logger->log_user_action(self::LOG_NAME, 'stats_calculated', ['stats' => $stats], $user_id);

        // Classes de couleur selon la rentabilité
        $marge_class = ($stats['marge'] < 20) ? 'is-low' : (($stats['marge'] < 35) ? 'is-average' : 'is-good');
        $logger->log_user_action(self::LOG_NAME, 'margin_class_determined', ['marge_class' => $marge_class], $user_id);

        $discount = $instance->get_project_discount(null, $deal_id);
        $logger->log_user_action(self::LOG_NAME, 'discount_retrieved', ['discount' => $discount], $user_id);

        return [
            'stats'       => $stats,
            'marge_class' => $marge_class,
            'discount'    => $discount,
            'deal_id'     => $deal_id,
        ];
    }

    /**
     * Génère le HTML du dashboard de statistiques du deal.
     */
    public static function build_deal_stats_html($html, $deal_id)
    {
        $user_id = get_current_user_id();
        $logger = ISPAG_Logger::get_instance();
        $logger->log_user_action(self::LOG_NAME, 'build_deal_stats_html_start', ['deal_id' => $deal_id], $user_id);

        global $wpdb;
        $instance = new self($wpdb);

        // Récupération des données via notre nouvelle méthode mutualisée
        $data = self::get_deal_stats_data($deal_id);

        // Gestion des erreurs renvoyées par la méthode de calcul
        if (isset($data['error'])) {
            if ($data['error'] === 'not_allowed') {
                return '';
            }
            if ($data['error'] === 'no_project') {
                return '<div class="ispag-stats-alert error">' . esc_html__('No project specified.', 'creation-reservoir') . '</div>';
            }
        }

        $stats       = $data['stats'];
        $marge_class = $data['marge_class'];
        $discount    = $data['discount'];

        $output = '<div id="ispag_project_stat" class="ispag-stats-container">';
        // En-tête repliable : le résumé (gain / marge) reste visible quand la carte est repliée
        $output .= '<div class="ispag-stats-head" role="button" tabindex="0" aria-expanded="true">';
        $output .= '<h4 class="ispag-stats-title">' . esc_html__('Project Dashboard', 'creation-reservoir') . '</h4>';
        $output .= '<span class="ispag-stats-summary ' . esc_attr($marge_class) . '">'
            . esc_html(number_format_i18n($stats['gain'], 2)) . ' CHF · ' . esc_html(number_format_i18n($stats['marge'], 1)) . ' %</span>';
        $output .= '<span class="ispag-stats-caret" aria-hidden="true">&#9662;</span>';
        $output .= '</div>';

        $output .= '<div class="ispag-stats-grid">';

        // Card : Revenu
        $output .= $instance->render_stat_card(__('Total Sales', 'creation-reservoir'), number_format_i18n($stats['revenu'], 2) . ' CHF', 'blue');
        $logger->log_user_action(self::LOG_NAME, 'stat_card_rendered', ['label' => __('Total Sales', 'creation-reservoir'), 'value' => $stats['revenu']], $user_id);

        // Card : Coût
        $output .= $instance->render_stat_card(__('Total Cost', 'creation-reservoir'), number_format_i18n($stats['cout'], 2) . ' CHF', 'red');
        $logger->log_user_action(self::LOG_NAME, 'stat_card_rendered', ['label' => __('Total Cost', 'creation-reservoir'), 'value' => $stats['cout']], $user_id);

        // Card : Gain (Marge brute)
        $output .= $instance->render_stat_card(__('Project Gain', 'creation-reservoir'), number_format_i18n($stats['gain'], 2) . ' CHF', 'green');
        $logger->log_user_action(self::LOG_NAME, 'stat_card_rendered', ['label' => __('Project Gain', 'creation-reservoir'), 'value' => $stats['gain']], $user_id);

        // Card : Pourcentage de Marge
        $output .= $instance->render_stat_card(__('Margin %', 'creation-reservoir'), number_format_i18n($stats['marge'], 2) . ' %', $marge_class);
        $logger->log_user_action(self::LOG_NAME, 'stat_card_rendered', ['label' => __('Margin %', 'creation-reservoir'), 'value' => $stats['marge']], $user_id);

        // Card : Discount
        $output .= $instance->render_stat_card(__('Discount', 'creation-reservoir'), round(htmlspecialchars($discount), 4) . ' %', 'project_discount gray');
        $logger->log_user_action(self::LOG_NAME, 'stat_card_rendered', ['label' => 'Discount', 'value' => $discount], $user_id);

        // Card : Coef de vente (Sélecteur)
        $output .= '<div class="ispag-stat-card is-coef">';
        $output .= '<span class="stat-label">' . __('Sales Coef.', 'creation-reservoir') . '</span>';
        $output .= '<div class="stat-value">' . apply_filters('ispag_render_sales_coef_selector', '', $deal_id) . '</div>';
        $output .= '</div>';
        $logger->log_user_action(self::LOG_NAME, 'sales_coef_selector_rendered', ['deal_id' => $deal_id], $user_id);

        $output .= '</div>'; // .ispag-stats-grid
        $output .= '</div>'; // #ispag_project_stat

        $logger->log_user_action(self::LOG_NAME, 'build_deal_stats_html_complete', [], $user_id);
        return $output;
    }
    // public static function build_deal_stats_html($html, $deal_id)
    // {
    //     $user_id = get_current_user_id();
    //     $logger = ISPAG_Logger::get_instance();
    //     $logger->log_user_action(self::LOG_NAME, 'build_deal_stats_html_start', ['deal_id' => $deal_id], $user_id);


    //     global $wpdb;
    //     $instance = new self($wpdb);

    //     if (!current_user_can('manage_order'))
    //     {
    //         $logger->log(self::LOG_NAME, 'ERROR: User not allowed to manage order', $user_id);
    //         return '';
    //     }

    //     if ($deal_id === 0)
    //     {
    //         $logger->log(self::LOG_NAME, 'ERROR: No project specified', $user_id);
    //         return '<div class="ispag-stats-alert error">' . esc_html__('No project specified.', 'creation-reservoir') . '</div>';
    //     }
    //     $output = '';

    //     $stats = $instance->get_project_profitability($deal_id);
    //     $logger->log_user_action(self::LOG_NAME, 'stats_calculated', ['stats' => $stats], $user_id);

    //     // Classes de couleur selon la rentabilité
    //     $marge_class = ($stats['marge'] < 20) ? 'is-low' : (($stats['marge'] < 35) ? 'is-average' : 'is-good');
    //     $logger->log_user_action(self::LOG_NAME, 'margin_class_determined', ['marge_class' => $marge_class], $user_id);

    //     $output = '<div id="ispag_project_stat" class="ispag-stats-container">';
    //     $output .= '<h4 class="ispag-stats-title">' . esc_html__('Project Dashboard', 'creation-reservoir') . '</h4>';

    //     $output .= '<div class="ispag-stats-grid">';

    //     // Card : Revenu
    //     $output .= $instance->render_stat_card(__('Total Sales', 'creation-reservoir'), number_format_i18n($stats['revenu'], 2) . ' CHF', 'blue');
    //     $logger->log_user_action(self::LOG_NAME, 'stat_card_rendered', ['label' => __('Total Sales', 'creation-reservoir'), 'value' => $stats['revenu']], $user_id);

    //     // Card : Coût
    //     $output .= $instance->render_stat_card(__('Total Cost', 'creation-reservoir'), number_format_i18n($stats['cout'], 2) . ' CHF', 'red');
    //     $logger->log_user_action(self::LOG_NAME, 'stat_card_rendered', ['label' => __('Total Cost', 'creation-reservoir'), 'value' => $stats['cout']], $user_id);

    //     // Card : Gain (Marge brute)
    //     $output .= $instance->render_stat_card(__('Project Gain', 'creation-reservoir'), number_format_i18n($stats['gain'], 2) . ' CHF', 'green');
    //     $logger->log_user_action(self::LOG_NAME, 'stat_card_rendered', ['label' => __('Project Gain', 'creation-reservoir'), 'value' => $stats['gain']], $user_id);

    //     // Card : Pourcentage de Marge
    //     $output .= $instance->render_stat_card(__('Margin %', 'creation-reservoir'), number_format_i18n($stats['marge'], 2) . ' %', $marge_class);
    //     $logger->log_user_action(self::LOG_NAME, 'stat_card_rendered', ['label' => __('Margin %', 'creation-reservoir'), 'value' => $stats['marge']], $user_id);

    //     // Card : Discount
    //     $discount = $instance->get_project_discount(null, $deal_id);
    //     $output .= $instance->render_stat_card(__('Discount', 'creation-reservoir'), round(htmlspecialchars($discount), 4) . ' %', 'project_discount gray');
    //     $logger->log_user_action(self::LOG_NAME, 'stat_card_rendered', ['label' => 'Discount', 'value' => $discount], $user_id);

    //     // Card : Coef de vente (Sélecteur)
    //     $output .= '<div class="ispag-stat-card is-coef">';
    //     $output .= '<span class="stat-label">' . __('Sales Coef.', 'creation-reservoir') . '</span>';
    //     $output .= '<div class="stat-value">' . apply_filters('ispag_render_sales_coef_selector', '', $deal_id) . '</div>';
    //     $output .= '</div>';
    //     $logger->log_user_action(self::LOG_NAME, 'sales_coef_selector_rendered', ['deal_id' => $deal_id], $user_id);

    //     $output .= '</div>'; // .ispag-stats-grid
    //     $output .= '</div>'; // #ispag_project_stat

    //     $logger->log_user_action(self::LOG_NAME, 'build_deal_stats_html_complete', [], $user_id);
    //     return $output;
    // }

    // Petite fonction helper pour la propreté
    private function render_stat_card($label, $value, $class = '')
    {
        $user_id = get_current_user_id();
        $this->logger->log_user_action(self::LOG_NAME, 'render_stat_card', ['label' => $label, 'value' => $value, 'class' => $class], $user_id);

        return sprintf(
            '<div class="ispag-stat-card %s"><span class="stat-label">%s</span><span class="stat-value">%s</span></div>',
            esc_attr($class),
            esc_html($label),
            esc_html($value)
        );
    }

    public function get_project_discount($html, $deal_id = null)
    {
        $user_id = get_current_user_id();
        $discount = 0;
        $this->logger->log_user_action(self::LOG_NAME, 'get_project_discount_start', ['deal_id' => $deal_id], $user_id);

        if (empty($deal_id))
        {
            $this->logger->log(self::LOG_NAME, 'ERROR: Empty deal_id', $user_id);
            return;
        }

        global $wpdb;

        $table_name = $wpdb->prefix . 'achats_liste_commande';
        $company_pk_raw = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT AssociatedCompanyID FROM {$table_name} WHERE hubspot_deal_id = %s",
                $deal_id
            )
        );

        if ( null !== $company_pk_raw ) {
           
        } else {
            $this->logger->log(self::LOG_NAME, 'ERROR: Empty company ID', $user_id);
            return;
        }

        // On ajoute le filtre IdArticleMaster = 0 pour ne pas compter les accessoires/fittings
        $sql_select = $this->wpdb->prepare(
            "
            SELECT discount
            FROM {$this->table_details}
            WHERE hubspot_deal_id = %d
            AND IdArticleMaster = 0
            GROUP BY discount
            HAVING COUNT(*) = (
                SELECT COUNT(*)
                FROM {$this->table_details}
                WHERE hubspot_deal_id = %d
                AND IdArticleMaster = 0
            )
            ",
            $deal_id,
            $deal_id
        );

        $this->logger->log_db_change(self::LOG_NAME, $this->table_details, 'FETCH_DISCOUNT', ['deal_id' => $deal_id, 'sql' => $sql_select], $user_id);

        $result = $this->wpdb->get_var($sql_select);

        $this->logger->log_user_action(self::LOG_NAME, 'discount_result', ['deal_id' => $deal_id, 'result' => $result], $user_id);

        // Si la requête ne retourne rien (car discounts différents), on affiche "Non uniforme"
        // $discount = $result !== null ? $result : 'Non uniforme'; 
        if($result !== null){
            $discount = $result;
        }
        // else{
        //         $discount = 0;
        // }
        else{       
            if(class_exists('ISPAG_Crm_Discount_Manager') AND class_exists('ISPAG_Crm_Company_Repository')){
                $company_id = (int) $company_pk_raw; // AssociatedCompanyID = Id de ispag_companies
                $discount_manager = new ISPAG_Crm_Discount_Manager();
                $discount = $discount_manager->get_current_discount_by_company_id($company_id,'rabais')->discount_value ?? 0;
            }
            else{
                $discount = 0;
            }
        }
        $this->logger->log_user_action(self::LOG_NAME, 'get_project_discount_complete', ['discount' => $discount], $user_id);

        return $discount;
    }

    public function get_deal_created_by($deal_id = null){
        global $wpdb;

        $table_name = $wpdb->prefix . 'achats_liste_commande';

        $created_by = $this->wpdb->get_var($this->wpdb->prepare(
            "
            SELECT AssociatedCompanyID
            FROM {$table_name}
            WHERE hubspot_deal_id = %d",
            $deal_id
        ));

        return $created_by;
    }
}