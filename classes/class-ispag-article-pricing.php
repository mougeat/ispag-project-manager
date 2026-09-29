<?php
defined('ABSPATH') or die();

class ISPAG_Article_Pricing {
    protected $wpdb;
    protected $table_articles_standard;
    protected $table_articles;
    protected $table_articles_fournisseur;
    protected $table_tank_dimensions;
    protected $table_price_history;
    protected static $instance = null;
    private const LOG_NAME = 'article_pricing';

    /** @var ISPAG_Logger Instance du logger. */
    private $logger;

    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->logger = ISPAG_Logger::get_instance();
        $user_id = get_current_user_id();

        $this->table_articles_standard = $wpdb->prefix . 'achats_articles';
        $this->table_articles = $wpdb->prefix . 'achats_details_commande';
        $this->table_articles_fournisseur = $wpdb->prefix . 'achats_articles_cmd_fournisseurs';
        $this->table_tank_dimensions = $wpdb->prefix . 'achats_tank_dimensions';
        $this->table_price_history = $wpdb->prefix . 'achats_articles_price_history';

        // $this->logger->log_user_action(self::LOG_NAME, 'class_constructed', [], $user_id);
    }

    public static function init() {
        $user_id = get_current_user_id();
        $logger = ISPAG_Logger::get_instance();

        if (self::$instance === null) {
            self::$instance = new self();
            // $logger->log_user_action(self::LOG_NAME, 'instance_initialized', [], $user_id);
        }

        add_filter('ispag_calculate_sales_price', [self::$instance, 'calculate_sales_price'], 10, 2);
        add_filter('ispag_calculate_total_sales_price', [self::$instance, 'calculate_total_sales_price'], 10, 2);
        add_filter('ispag_calculate_net_unit_price', [self::$instance, 'calculate_net_unit_price'], 10, 2);
        add_filter('ispag_render_sales_coef_selector', [self::$instance, 'render_sales_coef_selector'], 10, 2);
        add_action('wp_ajax_ispag_get_sales_coef_notice', [self::$instance, 'ajax_get_sales_coef_notice']);
        add_action('wp_ajax_ispag_change_sales_coef', [self::$instance, 'handle_change_sales_coef']);

        wp_enqueue_script('ispag-pricing', plugin_dir_url(__FILE__) . '../assets/js/pricing.js', ['ispag-detail-display'], false, true);
        // $logger->log_user_action(self::LOG_NAME, 'script_enqueued', ['script' => 'ispag-pricing'], $user_id);
    }

    private function get_coef($type) {
        $user_id = get_current_user_id();
        $coef = 0;
        switch ($type) {
            case 'revendeur':
                $coef = floatval(get_option('wpcb_sales_coef_offre_revendeur'));
                break;
            case 'low':
                $coef = floatval(get_option('wpcb_sales_coef_low'));
                break;
            default:
                $coef = floatval(get_option('wpcb_sales_coef'));
        }
        $this->logger->log_user_action(self::LOG_NAME, 'coef_retrieved', ['type' => $type, 'coef' => $coef], $user_id);
        return $coef;
    }

    public function render_sales_coef_selector($html, $deal_id_post = null) {
        $user_id = get_current_user_id();
        $this->logger->log_user_action(self::LOG_NAME, 'render_sales_coef_selector_start', [], $user_id);

        if (!current_user_can('manage_order')) {
            $this->logger->log(self::LOG_NAME, 'ERROR: User not allowed to display sales coef selector', $user_id);
            return __('You are not allowed to display this line', 'creation-reservoir');
        }

        global $wpdb;
        $deal_id = get_query_var('deal_id') ?: ($_GET['deal_id'] ?? null);
        $deal_id = empty($deal_id) && isset($deal_id_post) ? intval($deal_id_post) : 0;

        if (!$deal_id) {
            $this->logger->log(self::LOG_NAME, 'ERROR: No deal_id defined for sales coef selector', $user_id);
            return __('No deal ID defined', 'creation-reservoir');
        }

        $current_coef = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT sales_coef FROM {$wpdb->prefix}achats_liste_commande WHERE hubspot_deal_id = %d",
                $deal_id
            )
        );
        $this->logger->log_db_change(self::LOG_NAME, $wpdb->prefix . 'achats_liste_commande', 'FETCH_SALES_COEF', ['deal_id' => $deal_id, 'current_coef' => $current_coef], $user_id);

        $results = $wpdb->get_results("SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE 'wpcb_sales_coef%'");
        if (!$results) {
            $this->logger->log(self::LOG_NAME, 'ERROR: Failed to read sales coef options', $user_id);
            return __('Error while reading sales coef', 'creation-reservoir');
        }

        $output = '<select id="ispag-coef-select" class="ispag-coef-selector" data-deal-id="' . $deal_id . '">';
        foreach ($results as $row) {
            $label = str_replace('wpcb_sales_coef', '', $row->option_name);
            $label = $label === '' ? 'Standard' : ucfirst($label);
            $selected = ($row->option_value == $current_coef) ? 'selected' : '';
            $output .= '<option value="' . esc_attr($row->option_name) . '" ' . $selected . '>'
                . esc_html($label) . ' (' . esc_html($row->option_value) . ')</option>';
        }
        $output .= '</select>';
        $this->logger->log_user_action(self::LOG_NAME, 'sales_coef_selector_rendered', [], $user_id);
        return $output;
    }

    public function ajax_get_sales_coef_notice() {
        $user_id = get_current_user_id();
        $this->logger->log_user_action(self::LOG_NAME, 'ajax_get_sales_coef_notice_start', [], $user_id);

        $deal_id = get_query_var('deal_id') ?: ($_GET['deal_id'] ?? null);
        $this->logger->log_user_action(self::LOG_NAME, 'deal_id_received', ['deal_id' => $deal_id], $user_id);

        echo $this->render_sales_coef_notice($deal_id);
        wp_die();
    }

    /**
     * Affiche un message d'avertissement si le coefficient de vente est différent du standard
     */
    public function render_sales_coef_notice($deal_id_post = null) {
        $user_id = get_current_user_id();
        $this->logger->log_user_action(self::LOG_NAME, 'render_sales_coef_notice_start', [], $user_id);

        if (!current_user_can('manage_order')) {
            $this->logger->log(self::LOG_NAME, 'ERROR: User not allowed to display sales coef notice', $user_id);
            return '';
        }

        global $wpdb;
        $deal_id = empty($deal_id_post) ? (get_query_var('deal_id') ?: ($_GET['deal_id'] ?? null)) : intval($deal_id_post);
        if (!$deal_id) {
            $this->logger->log(self::LOG_NAME, 'ERROR: No deal_id defined for sales coef notice', $user_id);
            return '';
        }

        $current_coef = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT sales_coef FROM {$wpdb->prefix}achats_liste_commande WHERE hubspot_deal_id = %d",
                $deal_id
            )
        );
        $standard = get_option('wpcb_sales_coef');

        $this->logger->log_db_change(
            self::LOG_NAME,
            $wpdb->prefix . 'achats_liste_commande',
            'FETCH_SALES_COEF',
            ['deal_id' => $deal_id, 'current_coef' => $current_coef, 'standard' => $standard],
            $user_id
        );

        if (!empty($current_coef) && floatval($current_coef) != floatval($standard)) {
            $this->logger->log_user_action(
                self::LOG_NAME,
                'non_standard_coef_detected',
                ['current_coef' => $current_coef, 'standard' => $standard],
                $user_id
            );

            // Calculer la différence en pourcentage
            $difference = (floatval($current_coef) - floatval($standard)) / floatval($standard) * 100;
            $difference_formatted = number_format(abs($difference), 2, '.', '');

            // Déterminer le type d'alerte en fonction de la différence
            $alert_type = ($difference > 0) ? 'ispag-alert-up' : 'ispag-alert-down';
            $icon = ($difference > 0) ? 'dashicons-arrow-up-alt' : 'dashicons-arrow-down-alt';
            $color = ($difference > 0) ? '#2271b1' : '#d63638';

            // Formater le coefficient pour l'affichage (ex: 1.25 au lieu de 1.25000)
            $current_coef_formatted = number_format(floatval($current_coef), 2, '.', '');

            return sprintf(
                '<div class="ispag-custom-alert %s">
                    <div class="ispag-alert-icon">
                        <span class="dashicons %s" style="color: %s;"></span>
                    </div>
                    <div class="ispag-alert-content">
                        <strong>%s</strong> %s
                        <p>&nbsp;</p>
                        <p>%s</p>
                    </div>
                </div>',
                $alert_type,
                $icon,
                $color,
                __('Warning:', 'creation-reservoir'),
                __('The selected coefficient differs from the standard. Please copy the following notice in the "Internal comment" field in Swissisol (VIAG) : ', 'creation-reservoir'),
                sprintf(
                    __('Please note that the project was calculated using a %s coefficient. Please take this into account for the discount.', 'creation-reservoir'),
                    $current_coef_formatted
                )
            );
        }

        return '';
    }

    public function handle_change_sales_coef() {
        $user_id = get_current_user_id();
        $this->logger->log_user_action(self::LOG_NAME, 'handle_change_sales_coef_start', [], $user_id);

        if (!current_user_can('manage_order')) {
            $this->logger->log(self::LOG_NAME, 'ERROR: User not allowed to change sales coef', $user_id);
            wp_send_json_error('Non autorisé');
        }

        $deal_id = intval($_POST['deal_id'] ?? 0);
        $key = sanitize_text_field($_POST['coef_key'] ?? '');
        $coef_new = floatval(get_option($key));

        if (!$deal_id || $coef_new <= 0) {
            $this->logger->log(self::LOG_NAME, 'ERROR: Invalid parameters for changing sales coef', $user_id, ['deal_id' => $deal_id, 'coef_new' => $coef_new]);
            wp_send_json_error('Paramètres invalides');
        }

        global $wpdb;
        $table_commandes = $wpdb->prefix . 'achats_liste_commande';
        $wpdb->update(
            $table_commandes,
            ['sales_coef' => $coef_new],
            ['hubspot_deal_id' => $deal_id],
            ['%f'],
            ['%d']
        );

        $this->logger->log_db_change(self::LOG_NAME, $table_commandes, 'UPDATE_SALES_COEF', ['deal_id' => $deal_id, 'new_coef' => $coef_new], $user_id);
        $this->logger->log_user_action(self::LOG_NAME, 'sales_coef_updated', ['deal_id' => $deal_id, 'new_coef' => $coef_new], $user_id);

        wp_send_json_success([
            'message' => 'Coefficient mis à jour',
            'coef' => $coef_new
        ]);
    }

    private function get_standard_price($article_id) {
        $user_id = get_current_user_id();
        $this->logger->log_user_action(self::LOG_NAME, 'get_standard_price_start', ['article_id' => $article_id], $user_id);

        $today = current_time('Y-m-d');
        $sales_price = $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT sales_price
                FROM {$this->table_price_history}
                WHERE article_id = %d
                AND valid_from <= %s
                AND (valid_to IS NULL OR valid_to >= %s)
                ORDER BY valid_from DESC
                LIMIT 1",
                $article_id, $today, $today
            )
        );
        $sales_price = $sales_price !== null ? floatval($sales_price) : 0;
        $this->logger->log_db_change(self::LOG_NAME, $this->table_price_history, 'FETCH_STANDARD_PRICE', ['article_id' => $article_id, 'sales_price' => $sales_price], $user_id);
        return $sales_price;
    }

    private function get_purchase_price($article_id) {
        $user_id = get_current_user_id();
        $this->logger->log_user_action(self::LOG_NAME, 'get_purchase_price_start', ['article_id' => $article_id], $user_id);

        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT UnitPrice, Discount FROM {$this->table_articles_fournisseur} WHERE IdCommandeClient = %d",
                $article_id
            )
        );

        if (!$row) {
            $this->logger->log(self::LOG_NAME, 'ERROR: No purchase price found for article ' . $article_id, $user_id);
            return 0;
        }

        $unit_price = (float)$row->UnitPrice;
        $discount = (float)$row->Discount;
        $final_price = $discount > 0 ? $unit_price * (1 - ($discount / 100)) : $unit_price;
        $this->logger->log_user_action(self::LOG_NAME, 'purchase_price_calculated', ['article_id' => $article_id, 'UnitPrice' => $unit_price, 'Discount' => $discount, 'final_price' => $final_price], $user_id);
        return $final_price;
    }

    private function get_tank_volume_m3($article_id) {
        $user_id = get_current_user_id();
        $this->logger->log_user_action(self::LOG_NAME, 'get_tank_volume_m3_start', ['article_id' => $article_id], $user_id);

        $volume_liters = $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT Volume FROM {$this->table_tank_dimensions} WHERE customerTankId = %d",
                $article_id
            )
        );
        $volume_m3 = $volume_liters !== null ? floatval($volume_liters) / 1000 : null;
        $this->logger->log_db_change(self::LOG_NAME, $this->table_tank_dimensions, 'FETCH_VOLUME', ['article_id' => $article_id, 'volume_m3' => $volume_m3], $user_id);
        return $volume_m3;
    }

    public function calculate_sales_price($article_id, $coef_type = 'default') {
        $user_id = get_current_user_id();
        $this->logger->log_user_action(self::LOG_NAME, 'calculate_sales_price_start', ['article_id' => $article_id, 'coef_type' => $coef_type], $user_id);

        $purchase_price = $this->get_purchase_price($article_id);
        if ($purchase_price === 0) {
            $this->logger->log(self::LOG_NAME, 'INFO: Purchase price is 0 for article ' . $article_id . ' → Sales price = 0', $user_id);
            return 0;
        }

        $customs_fee_percentage = get_option('wpcb_custom_fee');
        $coef = $this->get_coef($coef_type);

        if ($customs_fee_percentage > 0) {
            $fee_rate = $customs_fee_percentage / 100;
            if (1 - $fee_rate > 0) {
                $purchase_price = $purchase_price / (1 - $fee_rate);
                $this->logger->log_user_action(self::LOG_NAME, 'customs_fee_applied', ['fee_rate' => $fee_rate, 'new_purchase_price' => $purchase_price], $user_id);
            }
        }

        $sales_price = $purchase_price * $coef;
        $this->logger->log_user_action(self::LOG_NAME, 'sales_price_before_transport', ['sales_price' => $sales_price], $user_id);

        if ($sales_price < 6000) {
            $volume_m3 = $this->get_tank_volume_m3($article_id);
            if ($volume_m3 !== null) {
                $transport = $volume_m3 * 400;
                $sales_price += $transport;
                $this->logger->log_user_action(self::LOG_NAME, 'transport_added_for_small_tank', ['volume_m3' => $volume_m3, 'transport' => $transport], $user_id);
            }
        }

        $final_price = round($sales_price, 2);
        $this->logger->log_user_action(self::LOG_NAME, 'final_sales_price_calculated', ['article_id' => $article_id, 'final_price' => $final_price], $user_id);
        return $final_price;
    }

    /**
     * Calcule le prix de vente à partir des données fournies
     */
    public function calculate_sales_price_from_data($purchase_price, $volume_litres, $coef_type = 'default') {
        $user_id = get_current_user_id();
        ISPAG_Logger::get_instance()->log_user_action(self::LOG_NAME, 'calculate_sales_price_from_data_start', [
            'purchase_price' => $purchase_price,
            'volume_litres' => $volume_litres,
            'coef_type' => $coef_type
        ], $user_id);

        if ($purchase_price === 0) {
            ISPAG_Logger::get_instance()->log(self::LOG_NAME, 'INFO: Purchase price is 0 → Sales price = 0', $user_id);
            return 0;
        }

        // Récupérer les options nécessaires
        $customs_fee_percentage = get_option('wpcb_custom_fee');
        $coef = $this->get_coef($coef_type);

        // Appliquer les droits de douane si nécessaire
        if ($customs_fee_percentage > 0) {
            $fee_rate = $customs_fee_percentage / 100;
            if (1 - $fee_rate > 0) {
                $purchase_price = $purchase_price / (1 - $fee_rate);
                ISPAG_Logger::get_instance()->log_user_action(self::LOG_NAME, 'customs_fee_applied', [
                    'fee_rate' => $fee_rate,
                    'new_purchase_price' => $purchase_price
                ], $user_id);
            }
        }

        // Calculer le prix de vente de base
        $sales_price = $purchase_price * $coef;
        ISPAG_Logger::get_instance()->log_user_action(self::LOG_NAME, 'sales_price_before_transport', [
            'sales_price' => $sales_price
        ], $user_id);

        // Ajouter les frais de transport si le prix est inférieur à 6000€
        if ($sales_price < 6000) {
            $volume_m3 = $volume_litres / 1000; // Conversion de litres en m³
            if ($volume_m3 > 0) {
                $transport = $volume_m3 * 400;
                $sales_price += $transport;
                ISPAG_Logger::get_instance()->log_user_action(self::LOG_NAME, 'transport_added_for_small_tank', [
                    'volume_m3' => $volume_m3,
                    'transport' => $transport
                ], $user_id);
            }
        }

        $final_price = round($sales_price, 2);
        ISPAG_Logger::get_instance()->log_user_action(self::LOG_NAME, 'final_sales_price_calculated', [
            'final_price' => $final_price
        ], $user_id);

        return $final_price;
    }


    public function calculate_total_sales_price($article_id, $unused = null) {
        $user_id = get_current_user_id();
        $this->logger->log_user_action(self::LOG_NAME, 'calculate_total_sales_price_start', ['article_id' => $article_id], $user_id);

        // 1. Récupérer l'article principal
        $article = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT Id, Qty, sales_price, hubspot_deal_id, is_manual_price, IdArticleStandard FROM {$this->table_articles} WHERE Id = %d",
                $article_id
            )
        );

        if (!$article) {
            $this->logger->log(self::LOG_NAME, 'ERROR: Article not found for ID ' . $article_id, $user_id);
            return 0;
        }
        $this->logger->log_db_change(self::LOG_NAME, $this->table_articles, 'FETCH_ARTICLE', ['article_id' => $article_id, 'article' => $article], $user_id);

        // 2. Récupérer le coefficient du projet
        $coef = $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT sales_coef FROM {$this->wpdb->prefix}achats_liste_commande WHERE hubspot_deal_id = %d",
                $article->hubspot_deal_id
            )
        );
        if (empty($coef) || floatval($coef) == 0) {
            $coef = get_option('wpcb_sales_coef');
            $this->logger->log_user_action(self::LOG_NAME, 'project_coef_empty_or_zero_using_standard', ['coef' => $coef], $user_id);
        }
        $coef_standard = floatval(get_option('wpcb_sales_coef'));

        // 3. Si prix manuel, retourner directement
        if ($article->is_manual_price == 1) {
            $this->logger->log_user_action(self::LOG_NAME, 'manual_price_for_article', ['article_id' => $article_id, 'sales_price' => $article->sales_price], $user_id);
            return round(floatval($article->sales_price), 0);
        }

        // 4. Calculer le prix de l'article principal
        if ($article->IdArticleStandard && $article->IdArticleStandard != 595) {
            $total = $this->get_standard_price($article->IdArticleStandard);
            $this->logger->log_user_action(self::LOG_NAME, 'standard_price_used', ['article_id' => $article_id, 'IdArticleStandard' => $article->IdArticleStandard, 'total' => $total], $user_id);
        } else {
            $total = $this->calculate_sales_price($article_id, $unused);
            $this->logger->log_user_action(self::LOG_NAME, 'price_calculated_for_non_standard_article', ['article_id' => $article_id, 'total' => $total], $user_id);
        }

        // 5. Ajouter les articles secondaires
        $secondaires = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT Id, sales_price, Qty, discount, IdArticleStandard FROM {$this->table_articles} WHERE IdArticleMaster = %d",
                $article_id
            )
        );

        foreach ($secondaires as $sous) {
            if ($sous->IdArticleStandard && $sous->IdArticleStandard != 595) {
                // Article secondaire standard : prix depuis achats_articles_price_history
                $sous_price = $this->get_standard_price($sous->IdArticleStandard);
                $this->logger->log_user_action(self::LOG_NAME, 'secondary_standard_price_used', ['sous_article' => $sous->Id, 'sous_price' => $sous_price], $user_id);
            } else {
                // Article secondaire non standard : calculer depuis le prix d'achat
                $sous_price = $this->calculate_sales_price($sous->Id, $unused);
                $this->logger->log_user_action(self::LOG_NAME, 'secondary_non_standard_price_calculated', ['sous_article' => $sous->Id, 'sous_price' => $sous_price], $user_id);
            }

            // Appliquer quantité et rabais
            $net = $sous_price * intval($sous->Qty) * (1 - floatval($sous->discount) / 100);
            $total += $net;
            $this->logger->log_user_action(self::LOG_NAME, 'secondary_article_added', ['IdArticleMaster' => $article_id, 'sous_article' => $sous->Id, 'net' => $net], $user_id);
        }

        // 6. Appliquer le coefficient du projet UNE SEULE FOIS
        $nouveau_prix_vente = $total * ($coef / $coef_standard);
        $this->logger->log_user_action(self::LOG_NAME, 'project_coef_applied', ['total_before_coef' => $total, 'coef' => $coef, 'nouveau_prix_vente' => $nouveau_prix_vente], $user_id);

        return round($nouveau_prix_vente, 0);
    }

    public function calculate_net_unit_price($article_id, $coef_type = 'default') {
        $user_id = get_current_user_id();
        $this->logger->log_user_action(self::LOG_NAME, 'calculate_net_unit_price_start', ['article_id' => $article_id], $user_id);

        $discount_percent = $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT discount FROM {$this->table_articles} WHERE Id = %d",
                $article_id
            )
        );

        if ($discount_percent === null) {
            $this->logger->log(self::LOG_NAME, 'WARNING: No discount found for article ' . $article_id . ' → returning 0', $user_id);
            return 0;
        }

        $total_brut = $this->calculate_total_sales_price($article_id, $coef_type);
        if ($total_brut === null) {
            $this->logger->log(self::LOG_NAME, 'WARNING: Total brut price is null for article ' . $article_id . ' → returning 0', $user_id);
            return 0;
        }

        $total_net = $total_brut * (1 - floatval($discount_percent) / 100);
        $this->logger->log_user_action(self::LOG_NAME, 'net_price_calculated', ['article_id' => $article_id, 'total_brut' => $total_brut, 'discount_percent' => $discount_percent, 'total_net' => $total_net], $user_id);

        return round($total_net, 2);
    }
}