<?php
defined('ABSPATH') or die();

class ISPAG_Article_Pricing {
    protected $wpdb;
    protected $table_articles;
    protected $table_articles_fournisseur;
    protected $table_tank_dimensions;
    protected static $instance = null;

    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->table_articles = $wpdb->prefix . 'achats_details_commande';
        $this->table_articles_fournisseur = $wpdb->prefix . 'achats_articles_cmd_fournisseurs';
        $this->table_tank_dimensions = $wpdb->prefix . 'achats_tank_dimensions';
    }
    public static function init() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        add_filter('ispag_calculate_sales_price', [self::$instance, 'calculate_sales_price'], 10, 2);
        add_filter('ispag_calculate_total_sales_price', [self::$instance, 'calculate_total_sales_price'], 10, 2);
        add_filter('ispag_calculate_net_unit_price', [self::$instance, 'calculate_net_unit_price'], 10, 2);

        add_filter('ispag_render_sales_coef_selector', [self::$instance, 'render_sales_coef_selector'], 10, 2);
        add_action('wp_ajax_ispag_get_sales_coef_notice', [self::$instance, 'ajax_get_sales_coef_notice']);

        add_action('wp_ajax_ispag_change_sales_coef', [self::$instance, 'handle_change_sales_coef']);

        wp_enqueue_script('ispag-pricing', plugin_dir_url(__FILE__) . '../assets/js/pricing.js', ['ispag-detail-display'], false, true);
    }

    private function get_coef($type) {
        switch ($type) {
            case 'revendeur':
                return floatval(get_option('wpcb_sales_coef_offre_revendeur'));
            case 'low':
                return floatval(get_option('wpcb_sales_coef_low'));
            default:
                return floatval(get_option('wpcb_sales_coef'));
        }
    }

    public function render_sales_coef_selector($html, $deal_id_post = null) {
        if (!current_user_can('manage_order')) return __('You are not allowed to display this line', 'creation-reservoir');

        global $wpdb;

        // 🔍 Récupérer le deal_id depuis l’URL
        $deal_id = isset($_GET['deal_id']) ? intval($_GET['deal_id']) : null;
        $deal_id = empty($deal_id) && isset($deal_id_post) ? intval($deal_id_post) : 0;

        if (!$deal_id) return __('No deal ID defined', 'creation-reservoir');

        // 🧾 Lire le coef actuel du projet
        $current_coef = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT sales_coef FROM {$wpdb->prefix}achats_liste_commande WHERE hubspot_deal_id = %d",
                $deal_id
            )
        );

        // 📦 Récupérer tous les coefficients
        $results = $wpdb->get_results("SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE 'wpcb_sales_coef%'");
        if (!$results) return __('Error while reading sales coef', 'creation-reservoir');

        // ob_start();

        // 🔔 Message d’avertissement si le coef est différent du standard
        $output = '<div id="ispag-coef-notice">';
        $output .= $this->render_sales_coef_notice();
        $output .= '</div>';

        // $output .= '<label for="ispag-coef-select">' . __('Sales coef', 'creation-reservoir') . ' :</label>';
        $output .= '<select id="ispag-coef-select" class="ispag-coef-selector">';

        foreach ($results as $row) {
            $label = str_replace('wpcb_sales_coef', '', $row->option_name);
            $label = $label === '' ? 'Standard' : ucfirst($label);

            // 👇 Coef en cours ? alors selected
            $selected = ($row->option_value == $current_coef) ? 'selected' : '';

            $output .= '<option value="' . esc_attr($row->option_name) . '" ' . $selected . '>' .
                esc_html($label) . ' (' . esc_html($row->option_value) . ')</option>';
        }

        $output .= '</select>';
        // return ob_get_clean();
        return $output;
    }

    public function ajax_get_sales_coef_notice() {
        $deal_id = $_POST['deal_id'];
        echo $this->render_sales_coef_notice($deal_id);
        wp_die();
    }
    public function render_sales_coef_notice($deal_id_post = null) {
        if (!current_user_can('manage_order')) return '';

        global $wpdb;
        $deal_id = isset($_GET['deal_id']) ? intval($_GET['deal_id']) : null;
        $deal_id = empty($deal_id) && isset($deal_id_post) ? intval($deal_id_post) : 0;
        if (!$deal_id) return '';

        $current_coef = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT sales_coef FROM {$wpdb->prefix}achats_liste_commande WHERE hubspot_deal_id = %d",
                $deal_id
            )
        );

        $standard = get_option('wpcb_sales_coef');

        if (!empty($current_coef) && floatval($current_coef) != floatval($standard)) {
            return '<div class="notice ispag-warning"><span class="dashicons dashicons-warning"></span> Coefficient non standard utilisé : ' . esc_html(number_format($current_coef, 1)) . '</div>';
        }

        return '';
    }


    public function handle_change_sales_coef() {
        if (!current_user_can('manage_order')) wp_send_json_error('Non autorisé');

        $deal_id = intval($_POST['deal_id'] ?? 0);
        $key = sanitize_text_field($_POST['coef_key'] ?? '');
        $coef_new = floatval(get_option($key));

        if (!$deal_id || $coef_new <= 0) wp_send_json_error('Paramètres invalides');

        global $wpdb;
        $table_commandes = $wpdb->prefix . 'achats_liste_commande';
        $table_articles = $wpdb->prefix . 'achats_details_commande';

        // // 1. Récupérer l’ancien coef
        // $coef_initial = floatval(get_option('wpcb_sales_coef'));

        // 2. Sauvegarder le nouveau coef dans la commande
        $wpdb->update(
            $table_commandes,
            ['sales_coef' => $coef_new],
            ['hubspot_deal_id' => $deal_id],
            ['%f'],
            ['%d']
        );

        // // 3. Récupérer tous les articles liés à cette commande
        // $articles = $wpdb->get_results(
        //     $wpdb->prepare(
        //         "SELECT Id, sales_price FROM {$table_articles} WHERE hubspot_deal_id = %d",
        //         $deal_id
        //     )
        // );
        // $details_update = [];

        // foreach ($articles as $article) {
        //     $prix_brut = $article->sales_price / $coef_initial;
        //     $nouveau_prix = round($prix_brut * $coef_new, 2);

        //     $wpdb->update(
        //         $table_articles,
        //         ['sales_price' => $nouveau_prix],
        //         ['Id' => $article->Id],
        //         ['%f'],
        //         ['%d']
        //     );

        //     $details_update[$article->Id] = [
        //         'prix_inital' => $article->sales_price,
        //         'prix_brut' => $prix_brut,
        //         'nouveau_prix' => $nouveau_prix,
        //         'coef_initial' => $coef_initial,
        //         'coef' => $coef_new
        //     ];
        // }

        wp_send_json_success([
            'message' => 'Coefficient mis à jour',
            'coef' => $coef_new
        ]);
    }


    public function calculate_total_sales_price($article_id, $unused = null) {
        // Étape 1 : Récup article + deal_id + sales_price
        $article = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT Id, Qty, sales_price, hubspot_deal_id FROM {$this->table_articles} WHERE Id = %d",
                $article_id
            )
        );

        if (!$article) return null;

        // Étape 2 : lire coef du projet
        $coef = $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT sales_coef FROM {$this->wpdb->prefix}achats_liste_commande WHERE hubspot_deal_id = %d",
                $article->hubspot_deal_id
            )
        );
        // Si vide ou 0 → utiliser le coef standard
        if (empty($coef) || floatval($coef) == 0) {
            $coef = get_option('wpcb_sales_coef');
        }
        $coef_initial = floatval(get_option('wpcb_sales_coef'));

        $total = floatval($article->sales_price);
        // Si le prix de vente = 0 ou null alors on va le calculer
        if($total === 0 || empty($total)){
            $total = apply_filters('ispag_calculate_sales_price', $article_id, $unused);
        }

        // Étape 3 : Ajouter les secondaires
        $secondaires = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT sales_price, Qty, discount FROM {$this->table_articles} WHERE IdArticleMaster = %d",
                $article_id
            )
        );

        foreach ($secondaires as $sous) {
            $pu = floatval($sous->sales_price);
            $qty = intval($sous->Qty);
            $discount = floatval($sous->discount); // en %

            $net = ($pu * $qty) * (1 - $discount / 100);
            $total += $net;
        }

        // Étape 4 : Calculer le prix brut de chaque article principal en appliquant le coef du projet
        $prix_brut = $total / $coef_initial; // 2000
        $nouveau_prix_vente = $prix_brut * $coef; // 3200

        return round($nouveau_prix_vente, 0);
    }

    public function calculate_sales_price($article_id, $coef_type = 'default') {
        $purchase_price = $this->get_purchase_price($article_id);
        // error_log('calculate_sales_price : ' . $purchase_price);
        if ($purchase_price === null OR $purchase_price === '0.00') return null;
         // --- NOUVEAU : 4. Intégration des frais de dédouanement ---
        $customs_fee_percentage = get_option('wpcb_custom_fee');

        $coef = $this->get_coef($coef_type);
        if ($customs_fee_percentage > 0) {
            $fee_rate = $customs_fee_percentage / 100;
            if (1 - $fee_rate > 0) {
                $purchase_price = $purchase_price / (1 - $fee_rate);
            } 
        }
        $sales_price = $purchase_price * $coef;

        // Si le prix est < 6000 CHF, on rajoute 400 CHF par m³
        if ($sales_price < 6000) {
            $volume_m3 = $this->get_tank_volume_m3($article_id);
            if ($volume_m3 !== null) {
                $sales_price += $volume_m3 * 400;
            }
        }

       

        return round($sales_price, 2);
    }

    public function calculate_net_unit_price($article_id, $coef_type = 'default') {
        // Récupérer le discount de l'article principal
        $discount_percent = $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT discount FROM {$this->table_articles} WHERE Id = %d",
                $article_id
            )
        );

        if ($discount_percent === null) return null;

        // Calculer le total brut
        $total_brut = $this->calculate_total_sales_price($article_id, $coef_type);

        // Appliquer le rabais
        $total_net = $total_brut * (1 - floatval($discount_percent) / 100);

        return round($total_net, 2);
    }


    private function get_purchase_price($article_id) {
        return $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT UnitPrice FROM {$this->table_articles_fournisseur} WHERE IdCommandeClient = %d",
                $article_id
            )
        );
    }

    private function get_tank_volume_m3($article_id) {
        $volume_liters = $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT Volume FROM {$this->table_tank_dimensions} WHERE customerTankId = %d",
                $article_id
            )
        );
        return $volume_liters !== null ? floatval($volume_liters) / 1000 : null;
    }



}