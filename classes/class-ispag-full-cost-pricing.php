<?php
defined('ABSPATH') || exit;

/**
 * Prix de vente « coût complet » : calcul de TEST, visible des administrateurs seulement.
 *
 * Le prix officiel (clients, offres, PDF, tableau de bord) reste l'ancien calcul de ISPAG_Article_Pricing.
 * Ce calcul n'est jamais enregistré : il n'est exécuté que sur demande (bouton du bloc « Project Dashboard »),
 * le temps d'une requête AJAX, en activant un drapeau que ISPAG_Article_Pricing::calculate_sales_price() lit.
 *
 * Coût complet d'un article = prix d'achat (remise fournisseur déduite)
 *                           + dédouanement estimé (commande en EUR : taux × achat)
 *                           + transport estimé (fournisseur à transport automatique : volume de la cuve / 1000 × tarif)
 * Prix de vente = coût complet × coefficient « coût complet » (2,5 = 60 % de marge sur le prix de vente).
 *
 * Taux de dédouanement et tarif de transport : ceux de ISPAG Achats (Purchase settings) tant que les réglages
 * « coût complet » valent 0. Les articles à prix standard (liste de prix) et les prix manuels ne changent pas.
 * Aucune conversion de devise n'est appliquée (comme l'ancien calcul).
 */
class ISPAG_Full_Cost_Pricing {

    const OPT_COEF      = 'ispag_full_cost_coef';
    const OPT_CUSTOMS   = 'ispag_full_cost_customs';
    const OPT_TRANSPORT = 'ispag_full_cost_transport';
    const DEFAULT_COEF  = 2.5;
    const NONCE         = 'ispag_full_cost_preview';

    /** Vrai seulement pendant le calcul de comparaison. */
    private static $active = false;

    public static function init() {
        add_action('wp_ajax_ispag_full_cost_preview', [self::class, 'ajax_preview']);
        add_action('wp_enqueue_scripts', [self::class, 'enqueue_script']);
    }

    public static function enqueue_script() {
        if (!self::can_preview()) {
            return;
        }
        $file = plugin_dir_path(__FILE__) . '../assets/js/full-cost-preview.js';
        wp_enqueue_script('ispag-full-cost-preview', plugin_dir_url(__FILE__) . '../assets/js/full-cost-preview.js', [], file_exists($file) ? filemtime($file) : false, true);
    }

    public static function is_active() {
        return self::$active;
    }

    /** Administrateurs seulement (filtre ispag_full_cost_preview_allowed pour changer). */
    public static function can_preview() {
        return (bool) apply_filters('ispag_full_cost_preview_allowed', current_user_can('manage_options'));
    }

    /** Réglages affichés dans ISPAG Settings : option => [libellé, type, défaut, aide]. */
    public static function fields() {
        return [
            self::OPT_COEF => ['Full-cost coefficient', 'number', self::DEFAULT_COEF, 'Test calculation, administrators only. Sales price = full cost × this coefficient; 2.5 gives a 60 % margin on the sales price (2.8 gives 64 %).'],
            self::OPT_CUSTOMS => ['Full-cost customs rate (%)', 'number', 0, '0 = use the rate of the purchase orders (ISPAG Settings → Purchase settings). Applied to purchases in EUR only.'],
            self::OPT_TRANSPORT => ['Full-cost transport per 1000 L', 'number', 0, '0 = use the transport rate of the purchase orders (Purchase settings). Spread over the tanks by volume; only for the suppliers with automatic transport.'],
        ];
    }

    public static function coef() {
        $v = (float) get_option(self::OPT_COEF, self::DEFAULT_COEF);
        return $v > 0 ? $v : self::DEFAULT_COEF;
    }

    public static function customs_rate() {
        $v = (float) get_option(self::OPT_CUSTOMS, 0);
        if ($v > 0) {
            return $v;
        }
        return class_exists('ISPAG_Achat_Settings') ? (float) ISPAG_Achat_Settings::customs_rate() : 10.0;
    }

    public static function transport_rate() {
        $v = (float) get_option(self::OPT_TRANSPORT, 0);
        if ($v > 0) {
            return $v;
        }
        return class_exists('ISPAG_Achat_Settings') ? (float) ISPAG_Achat_Settings::transport_rate() : 250.0;
    }

    private static function transport_supplier_ids() {
        return class_exists('ISPAG_Achat_Settings') ? array_map('intval', (array) ISPAG_Achat_Settings::transport_supplier_ids()) : [];
    }

    /**
     * Coût complet unitaire d'un article du projet.
     * @return array{purchase:float,customs:float,transport:float,full:float,currency:string,volume:float}
     */
    public static function breakdown($article_id) {
        global $wpdb;
        $out = ['purchase' => 0.0, 'customs' => 0.0, 'transport' => 0.0, 'full' => 0.0, 'currency' => '', 'volume' => 0.0];

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT f.UnitPrice, f.Discount, c.IdFournisseur, c.Devise
             FROM {$wpdb->prefix}achats_articles_cmd_fournisseurs f
             LEFT JOIN {$wpdb->prefix}achats_commande_liste_fournisseurs c ON c.Id = f.IdCommande
             WHERE f.IdCommandeClient = %d",
            (int) $article_id
        ));
        if (!$row) {
            return $out;
        }

        $unit     = (float) $row->UnitPrice;
        $discount = (float) $row->Discount;
        $purchase = $discount > 0 ? $unit * (1 - $discount / 100) : $unit;
        if ($purchase <= 0) {
            return $out;
        }
        $currency = strtoupper(trim((string) ($row->Devise ?? '')));
        if ($currency === '') {
            $currency = 'CHF';
        }

        // Dédouanement : comme les achats, seulement pour une commande en EUR
        $customs = $currency === 'EUR' ? $purchase * self::customs_rate() / 100 : 0.0;

        // Transport : au prorata du volume de la cuve, seulement pour les fournisseurs à transport automatique
        $volume    = (float) $wpdb->get_var($wpdb->prepare(
            "SELECT Volume FROM {$wpdb->prefix}achats_tank_dimensions WHERE customerTankId = %d",
            (int) $article_id
        ));
        $transport = 0.0;
        if ($volume > 0 && in_array((int) $row->IdFournisseur, self::transport_supplier_ids(), true)) {
            $transport = $volume / 1000 * self::transport_rate();
        }

        $out['purchase']  = $purchase;
        $out['customs']   = $customs;
        $out['transport'] = $transport;
        $out['full']      = $purchase + $customs + $transport;
        $out['currency']  = $currency;
        $out['volume']    = $volume;
        return $out;
    }

    /**
     * Prix de vente unitaire d'un article non standard (appelé par ISPAG_Article_Pricing::calculate_sales_price
     * pendant le calcul de comparaison seulement).
     */
    public static function price($article_id, $coef_type = 'default') {
        $b = self::breakdown($article_id);
        if ($b['full'] <= 0) {
            return 0;
        }
        $coef = self::coef();

        // Variantes de coefficient (low, revendeur) : même rapport au coefficient standard que dans l'ancien calcul
        $variants = ['low' => 'wpcb_sales_coef_low', 'revendeur' => 'wpcb_sales_coef_offre_revendeur'];
        $std      = (float) get_option('wpcb_sales_coef');
        if (isset($variants[$coef_type]) && $std > 0) {
            $variant = (float) get_option($variants[$coef_type]);
            if ($variant > 0) {
                $coef *= $variant / $std;
            }
        }
        return round($b['full'] * $coef, 2);
    }

    /** Exécute $fn avec le calcul « coût complet » (true) ou l'ancien (false), puis remet l'état d'origine. */
    private static function run($full, callable $fn) {
        $previous = self::$active;
        self::$active = (bool) $full;
        ISPAG_Article_Pricing::forget_last_total();
        try {
            return $fn();
        } finally {
            self::$active = $previous;
            ISPAG_Article_Pricing::forget_last_total();
        }
    }

    /** Lignes du projet avec leur prix net unitaire, comme la liste servant au total officiel. */
    private static function lines($deal_id) {
        $repo   = new ISPAG_Article_Repository();
        $groups = current_user_can('navigate_new_project_details_presentation')
            ? $repo->get_optimised_articles_by_deal($deal_id)
            : $repo->get_articles_by_deal($deal_id);
        $lines = [];
        foreach ((array) $groups as $articles) {
            foreach ((array) $articles as $art) {
                if (!is_object($art) || !empty($art->is_variant)) {
                    continue;
                }
                $title = trim(wp_strip_all_tags((string) ($art->Article ?? '')));
                $lines[(int) $art->Id] = [
                    'title' => $title !== '' ? mb_strimwidth($title, 0, 60, '…') : ('#' . (int) $art->Id),
                    'qty'   => (int) ($art->Qty ?? 0),
                    'net'   => (float) ($art->prix_net_calculé ?? 0),
                ];
            }
        }
        return $lines;
    }

    /** Montant réel des lignes transport (TRANS) et dédouanement (DED) des commandes du projet. */
    private static function real_charges($deal_id) {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT
                SUM(CASE WHEN f.RefSurMesure = 'TRANS' THEN f.UnitPrice * (1 - COALESCE(f.Discount, 0) / 100) * f.Qty ELSE 0 END) AS transport,
                SUM(CASE WHEN f.RefSurMesure = 'DED'   THEN f.UnitPrice * (1 - COALESCE(f.Discount, 0) / 100) * f.Qty ELSE 0 END) AS customs
             FROM {$wpdb->prefix}achats_articles_cmd_fournisseurs f
             INNER JOIN {$wpdb->prefix}achats_commande_liste_fournisseurs c ON c.Id = f.IdCommande
             WHERE c.hubspot_deal_id = %d",
            (int) $deal_id
        ));
        return ['transport' => (float) ($row->transport ?? 0), 'customs' => (float) ($row->customs ?? 0)];
    }

    /** Compare l'ancien calcul et le calcul « coût complet » sur un projet. */
    public static function compare($deal_id) {
        $deal_id = (int) $deal_id;

        $old_total = self::run(false, function () use ($deal_id) { return (float) ISPAG_Projet_Repository::ispag_calculate_deal_total_sales($deal_id); });
        $old_lines = self::run(false, function () use ($deal_id) { return self::lines($deal_id); });
        $new_total = self::run(true,  function () use ($deal_id) { return (float) ISPAG_Projet_Repository::ispag_calculate_deal_total_sales($deal_id); });
        $new_lines = self::run(true,  function () use ($deal_id) { return self::lines($deal_id); });

        $achat_repo = new ISPAG_Achat_Repository();
        $cost       = (float) $achat_repo->get_purchase_total_by_deal_id(null, $deal_id);

        $rows = [];
        $est_customs = 0.0;
        $est_transport = 0.0;
        foreach ($old_lines as $id => $old) {
            $new = $new_lines[$id] ?? null;
            $b   = self::breakdown($id);
            $est_customs   += $b['customs'] * $old['qty'];
            $est_transport += $b['transport'] * $old['qty'];
            $rows[] = [
                'title'     => $old['title'],
                'qty'       => $old['qty'],
                'purchase'  => $b['purchase'],
                'customs'   => $b['customs'],
                'transport' => $b['transport'],
                'currency'  => $b['currency'],
                'old'       => $old['net'],
                'new'       => $new ? $new['net'] : 0.0,
            ];
        }

        return [
            'old_total'     => $old_total,
            'new_total'     => $new_total,
            'cost'          => $cost,
            'est_customs'   => $est_customs,
            'est_transport' => $est_transport,
            'real'          => self::real_charges($deal_id),
            'rows'          => $rows,
        ];
    }

    private static function money($v) {
        return esc_html(number_format_i18n((float) $v, 2));
    }

    private static function percent($num, $den) {
        return $den > 0 ? esc_html(number_format_i18n($num / $den * 100, 1)) . ' %' : '–';
    }

    /** HTML du résultat de la comparaison. */
    public static function render_result($deal_id) {
        $d = self::compare($deal_id);

        $delta     = $d['new_total'] - $d['old_total'];
        $old_gain  = $d['old_total'] - $d['cost'];
        $new_gain  = $d['new_total'] - $d['cost'];
        $delta_pct = $d['old_total'] > 0 ? ($delta / $d['old_total']) * 100 : 0;

        $h  = '<table class="widefat striped" style="max-width:640px;margin:8px 0">';
        $h .= '<thead><tr><th></th><th>' . esc_html__('Current calculation', 'creation-reservoir') . '</th><th>' . esc_html__('Full-cost calculation', 'creation-reservoir') . '</th></tr></thead><tbody>';
        $h .= '<tr><td>' . esc_html__('Total Sales', 'creation-reservoir') . '</td><td>' . self::money($d['old_total']) . '</td><td>' . self::money($d['new_total']) . ' (' . ($delta >= 0 ? '+' : '') . esc_html(number_format_i18n($delta_pct, 1)) . ' %)</td></tr>';
        $h .= '<tr><td>' . esc_html__('Total Cost', 'creation-reservoir') . '</td><td>' . self::money($d['cost']) . '</td><td>' . self::money($d['cost']) . '</td></tr>';
        $h .= '<tr><td>' . esc_html__('Project Gain', 'creation-reservoir') . '</td><td>' . self::money($old_gain) . '</td><td>' . self::money($new_gain) . '</td></tr>';
        $h .= '<tr><td>' . esc_html__('Margin %', 'creation-reservoir') . '</td><td>' . self::percent($old_gain, $d['old_total']) . '</td><td>' . self::percent($new_gain, $d['new_total']) . '</td></tr>';
        $h .= '</tbody></table>';

        $h .= '<table class="widefat striped" style="max-width:640px;margin:8px 0">';
        $h .= '<thead><tr><th></th><th>' . esc_html__('Estimated (full-cost calculation)', 'creation-reservoir') . '</th><th>' . esc_html__('Real (purchase order lines)', 'creation-reservoir') . '</th></tr></thead><tbody>';
        $h .= '<tr><td>' . esc_html__('Transport', 'creation-reservoir') . '</td><td>' . self::money($d['est_transport']) . '</td><td>' . self::money($d['real']['transport']) . '</td></tr>';
        $h .= '<tr><td>' . esc_html__('Customs clearance', 'creation-reservoir') . '</td><td>' . self::money($d['est_customs']) . '</td><td>' . self::money($d['real']['customs']) . '</td></tr>';
        $h .= '</tbody></table>';

        $h .= '<table class="widefat striped" style="margin:8px 0"><thead><tr>'
            . '<th>' . esc_html__('Article', 'creation-reservoir') . '</th>'
            . '<th>' . esc_html__('Qty', 'creation-reservoir') . '</th>'
            . '<th>' . esc_html__('Purchase', 'creation-reservoir') . '</th>'
            . '<th>' . esc_html__('Customs clearance', 'creation-reservoir') . '</th>'
            . '<th>' . esc_html__('Transport', 'creation-reservoir') . '</th>'
            . '<th>' . esc_html__('Current price', 'creation-reservoir') . '</th>'
            . '<th>' . esc_html__('Full-cost price', 'creation-reservoir') . '</th>'
            . '<th>%</th></tr></thead><tbody>';
        foreach ($d['rows'] as $r) {
            $pct = $r['old'] > 0 ? (($r['new'] - $r['old']) / $r['old']) * 100 : null;
            $h .= '<tr><td>' . esc_html($r['title']) . '</td><td>' . (int) $r['qty'] . '</td>'
                . '<td>' . self::money($r['purchase']) . ($r['currency'] !== '' ? ' ' . esc_html($r['currency']) : '') . '</td>'
                . '<td>' . self::money($r['customs']) . '</td><td>' . self::money($r['transport']) . '</td>'
                . '<td>' . self::money($r['old']) . '</td><td>' . self::money($r['new']) . '</td>'
                . '<td>' . ($pct === null ? '–' : esc_html(number_format_i18n($pct, 1)) . ' %') . '</td></tr>';
        }
        $h .= '</tbody></table>';

        $h .= '<p class="description" style="margin:4px 0">'
            . sprintf(
                /* translators: 1: coefficient, 2: customs rate in %, 3: transport rate per 1000 L */
                esc_html__('Parameters used: coefficient %1$s, customs %2$s %%, transport %3$s per 1000 L. No currency conversion is applied (same as the current calculation). Standard-price and manual-price articles are identical in both calculations.', 'creation-reservoir'),
                esc_html(number_format_i18n(self::coef(), 2)),
                esc_html(number_format_i18n(self::customs_rate(), 2)),
                esc_html(number_format_i18n(self::transport_rate(), 2))
            ) . '</p>';
        return $h;
    }

    /** Bloc affiché dans le « Project Dashboard » (administrateurs seulement), résultat chargé sur demande. */
    public static function render_block($deal_id) {
        if (!self::can_preview() || (int) $deal_id <= 0) {
            return '';
        }
        return '<div class="ispag-fullcost-preview" style="grid-column:1/-1;border-top:1px dashed #ccc;padding-top:8px">'
            . '<strong>' . esc_html__('Full-cost price (test, administrators only)', 'creation-reservoir') . '</strong> '
            . '<button type="button" class="button ispag-fullcost-run" data-deal="' . (int) $deal_id . '" data-nonce="' . esc_attr(wp_create_nonce(self::NONCE)) . '" data-url="' . esc_url(admin_url('admin-ajax.php')) . '">'
            . esc_html__('Compare with the current calculation', 'creation-reservoir') . '</button>'
            . '<div class="ispag-fullcost-result"></div></div>';
    }

    public static function ajax_preview() {
        check_ajax_referer(self::NONCE, 'security');
        if (!self::can_preview()) {
            wp_send_json_error(['message' => __('You do not have the necessary rights for this action.', 'creation-reservoir')], 403);
        }
        $deal_id = absint($_POST['deal_id'] ?? 0);
        if ($deal_id <= 0) {
            wp_send_json_error(['message' => __('No project specified.', 'creation-reservoir')]);
        }
        wp_send_json_success(['html' => self::render_result($deal_id)]);
    }
}
