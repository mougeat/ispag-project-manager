<?php
defined('ABSPATH') || exit;

/**
 * Réglages du PDF d'offre (ISPAG Settings → Offer PDF) :
 *  - activation du bouton « Offer PDF » dans le groupe d'actions d'une offre ;
 *  - textes du document (introduction, intertitres et codes de position par type d'article, conditions, clôture, signature) ;
 *  - taux RPLP et TVA.
 * Les textes sont en français (langue du document remis au client). Valeurs par défaut fournies, modifiables ici.
 *
 * Balises : voir tags().
 */
class ISPAG_Offer_PDF_Settings {

    const NONCE      = 'ispag_offer_pdf_settings';
    const OPT_ENABLED = 'ispag_offer_pdf_enabled';
    const OPT_PREFIX = 'ispag_offer_pdf_';
    const ACTION     = 'ispag_offer_pdf';

    public static function init() {
        add_action('admin_menu', [self::class, 'admin_menu']);
        add_action('admin_post_' . self::ACTION, [self::class, 'handle']);
    }

    /** Droit et contexte : bouton activé, droit de gérer les projets et de voir les prix de vente. */
    public static function can_use(): bool {
        return self::enabled()
            && current_user_can('manage_order')
            && current_user_can('display_sales_prices')
            && class_exists('ISPAG_Capabilities') && ISPAG_Capabilities::can_manage_project_actions();
    }

    /** Bouton du groupe d'actions d'une offre (vide si désactivé ou sans droit). */
    public static function button_html($deal_id): string {
        if (!self::can_use() || empty($deal_id)) return '';
        $url = wp_nonce_url(add_query_arg(['action' => self::ACTION, 'deal_id' => (int) $deal_id], admin_url('admin-post.php')), self::ACTION . '_' . (int) $deal_id);
        return '<a class="ispag-btn ispag-btn-secondary-outlined ispag-offer-pdf-btn" href="' . esc_url($url) . '" target="_blank" rel="noopener">'
            . '<span class="dashicons dashicons-media-document"></span> ' . esc_html__('Offer PDF', 'creation-reservoir') . '</a>';
    }

    /** Génère et affiche le PDF d'une offre. */
    public static function handle() {
        $deal_id = absint($_GET['deal_id'] ?? 0);
        if (!$deal_id || !wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'] ?? '')), self::ACTION . '_' . $deal_id)) {
            wp_die(esc_html__('Invalid link.', 'creation-reservoir'), '', ['response' => 403]);
        }
        if (!self::can_use()) {
            wp_die(esc_html__('Not authorized', 'creation-reservoir'), '', ['response' => 403]);
        }
        $project = apply_filters('ispag_get_project_by_deal_id', null, $deal_id);
        if (!$project) wp_die(esc_html__('Project not found', 'creation-reservoir'), '', ['response' => 404]);
        if (empty($project->isQotation)) wp_die(esc_html__('The offer PDF is only available for offers.', 'creation-reservoir'), '', ['response' => 400]);

        require_once plugin_dir_path(__FILE__) . 'class-ispag-pdf-generator.php';   // la classe parente avant l'autoload du PDF d'offre
        $grouped = (new ISPAG_Article_Repository())->get_all_articles_by_deal_grouped($deal_id);
        $pdf = new ISPAG_Offer_PDF();
        $pdf->build($project, (array) ($grouped['active'] ?? []));
        $name = sanitize_file_name('offre-' . ($project->NumCommande ?: $deal_id)) . '.pdf';
        if (ob_get_length()) ob_end_clean();
        $pdf->Output('I', $name);
        exit;
    }

    public static function enabled(): bool {
        return get_option(self::OPT_ENABLED, '1') === '1';
    }

    /** Réglages texte : clé => [libellé, type de champ (text|textarea|number), valeur par défaut]. */
    public static function fields(): array {
        return [
            'greeting'      => [__('Greeting', 'creation-reservoir'), 'text', 'Bonjour {PRENOM} {NOM}'],
            'intro'         => [__('Introduction text', 'creation-reservoir'), 'textarea', "Nous vous remercions pour votre demande d'offre et vous la transmettons ci-joint comme suit :"],
            'heading_tank'  => [__('Heading: tank (type 1)', 'creation-reservoir'), 'text', "Fourniture d'un réservoir sur mesure"],
            'code_tank'     => [__('Position code: tank', 'creation-reservoir'), 'text', 'ASP'],
            'heading_insulation' => [__('Heading: insulation (type 2)', 'creation-reservoir'), 'text', "Fourniture et pose d'une isolation sur place"],
            'code_insulation'    => [__('Position code: insulation', 'creation-reservoir'), 'text', 'ISOL'],
            'heading_welding'    => [__('Heading: on-site welding (type 3)', 'creation-reservoir'), 'text', 'Soudure sur site'],
            'code_welding'       => [__('Position code: on-site welding', 'creation-reservoir'), 'text', 'SSP'],
            'code_other'    => [__('Position code: other articles', 'creation-reservoir'), 'text', ''],
            'rplp_rate'     => [__('RPLP rate (%)', 'creation-reservoir'), 'number', '2.10'],
            'vat_rate'      => [__('VAT rate (%)', 'creation-reservoir'), 'number', '8.10'],
            'payment_terms' => [__('Payment terms', 'creation-reservoir'), 'text', '60 jours net'],
            'validity'      => [__('Offer validity', 'creation-reservoir'), 'text', '3 mois'],
            'conditions'    => [__('Conditions (one per line)', 'creation-reservoir'), 'textarea', "Facturation selon métré contradictoire, selon norme ASMI/VSI, ISOL-SUISSE.\nPrix jusqu'à une hauteur de travail de max. 4.5m"],
            'closing'       => [__('Closing text', 'creation-reservoir'), 'text', 'Meilleures salutations'],
            'signature_company' => [__('Signing company (empty = company name of the site settings)', 'creation-reservoir'), 'text', ''],
            'acceptance'    => [__('Acceptance text', 'creation-reservoir'), 'textarea', "Par sa signature, le client accepte définitivement l'offre susmentionnée et donne son accord pour la commande des marchandises et des prestations."],
        ];
    }

    public static function tags(): array {
        return [
            '{PRENOM}' => __('Contact first name', 'creation-reservoir'),
            '{NOM}'    => __('Contact last name', 'creation-reservoir'),
        ];
    }

    /** Valeur enregistrée, sinon valeur par défaut. */
    public static function get(string $key): string {
        $fields = self::fields();
        if (!isset($fields[$key])) return '';
        $v = get_option(self::OPT_PREFIX . $key, null);
        return $v === null ? $fields[$key][2] : (string) $v;   // jamais enregistré : valeur par défaut ; enregistré vide : vide
    }

    public static function admin_menu() {
        $title = __('Offer PDF', 'creation-reservoir');
        if (class_exists('ISPAG_Settings')) {
            add_submenu_page(ISPAG_Settings::PAGE, $title, $title, 'manage_options', 'ispag-offer-pdf-settings', [self::class, 'render']);
        } else {
            add_management_page($title, $title, 'manage_options', 'ispag-offer-pdf-settings', [self::class, 'render']);
        }
    }

    public static function render() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Not authorized', 'creation-reservoir'));
        }

        $notice = '';
        if (!empty($_POST['ispag_offer_pdf_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['ispag_offer_pdf_nonce'])), self::NONCE)) {
            update_option(self::OPT_ENABLED, !empty($_POST['offer_pdf_enabled']) ? '1' : '0', false);
            $reset = !empty($_POST['offer_pdf_reset']);
            foreach (self::fields() as $key => $def) {
                $raw = wp_unslash($_POST[$key] ?? '');
                $val = $def[1] === 'textarea' ? sanitize_textarea_field($raw) : sanitize_text_field($raw);
                update_option(self::OPT_PREFIX . $key, $reset ? $def[2] : $val, false);
            }
            $notice = '<div class="notice notice-success"><p>' . esc_html__('Settings saved.', 'creation-reservoir') . '</p></div>';
        }

        echo '<div class="wrap"><h1>' . esc_html__('Offer PDF', 'creation-reservoir') . '</h1>' . $notice;
        echo '<p>' . esc_html__('Settings of the "Offer PDF" button shown on an offer. The text of the document is in French.', 'creation-reservoir') . '</p>';
        echo '<form method="post">';
        wp_nonce_field(self::NONCE, 'ispag_offer_pdf_nonce');
        echo '<table class="form-table">';
        echo '<tr><th>' . esc_html__('Show the button', 'creation-reservoir') . '</th><td><label><input type="checkbox" name="offer_pdf_enabled" value="1"' . checked(self::enabled(), true, false) . '> '
            . esc_html__('Offer PDF button on offers', 'creation-reservoir') . '</label></td></tr>';
        foreach (self::fields() as $key => $def) {
            echo '<tr><th><label for="' . esc_attr($key) . '">' . esc_html($def[0]) . '</label></th><td>';
            $val = self::get($key);
            if ($def[1] === 'textarea') {
                echo '<textarea class="large-text" rows="4" id="' . esc_attr($key) . '" name="' . esc_attr($key) . '">' . esc_textarea($val) . '</textarea>';
            } elseif ($def[1] === 'number') {
                echo '<input type="number" step="0.01" min="0" id="' . esc_attr($key) . '" name="' . esc_attr($key) . '" value="' . esc_attr($val) . '">';
            } else {
                echo '<input type="text" class="large-text" id="' . esc_attr($key) . '" name="' . esc_attr($key) . '" value="' . esc_attr($val) . '">';
            }
            echo '</td></tr>';
        }
        echo '<tr><th></th><td><label><input type="checkbox" name="offer_pdf_reset" value="1"> ' . esc_html__('Restore the default texts', 'creation-reservoir') . '</label></td></tr>';
        echo '</table>';

        echo '<h2>' . esc_html__('Available tags', 'creation-reservoir') . '</h2><ul>';
        foreach (self::tags() as $tag => $desc) {
            echo '<li><code>' . esc_html($tag) . '</code> — ' . esc_html($desc) . '</li>';
        }
        echo '</ul><p><button type="submit" class="button button-primary">' . esc_html__('Save', 'creation-reservoir') . '</button></p></form></div>';
    }
}
