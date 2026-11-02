<?php
defined('ABSPATH') || exit;

/**
 * Réglages du PDF d'offre (ISPAG Settings → Offer PDF) :
 *  - activation du bouton « Offer PDF » dans le groupe d'actions d'une offre ;
 *  - textes du document (introduction, intertitres et codes de position par type d'article, conditions, clôture, signature) ;
  * Taux RPLP (option « rplp ») et TVA (option « wpcb_vat ») : ISPAG Settings → Orders.
 * Les textes sont en français (langue du document remis au client). Valeurs par défaut fournies, modifiables ici.
 *
 * Balises : voir tags().
 */
class ISPAG_Offer_PDF_Settings {

    const NONCE      = 'ispag_offer_pdf_settings';
    const OPT_ENABLED = 'ispag_offer_pdf_enabled';
    const OPT_PREFIX = 'ispag_offer_pdf_';
    const ACTION     = 'ispag_offer_pdf';
    const META_VALIDITY = 'ispag_offer_validity';   // achats_project_meta : date de validité de l'offre (Y-m-d)

    public static function init() {
        add_action('admin_menu', [self::class, 'admin_menu']);
        add_action('admin_post_' . self::ACTION, [self::class, 'handle']);
        add_action('wp_ajax_' . self::ACTION . '_validity', [self::class, 'ajax_save_validity']);
    }

    /** Droit et contexte : bouton activé, droit de gérer les projets et de voir les prix de vente. */
    public static function can_use(): bool {
        return self::enabled()
            && current_user_can('manage_order')
            && current_user_can('display_sales_prices')
            && class_exists('ISPAG_Capabilities') && ISPAG_Capabilities::can_manage_project_actions();
    }

    /** Date de validité (Y-m-d) enregistrée pour l'offre, sinon aujourd'hui + délai par défaut des réglages. */
    public static function validity_date($deal_id): string {
        global $wpdb;
        $v = (string) $wpdb->get_var($wpdb->prepare(
            "SELECT meta_value FROM {$wpdb->prefix}achats_project_meta WHERE post_id = %d AND meta_key = %s ORDER BY meta_id DESC LIMIT 1", (int) $deal_id, self::META_VALIDITY
        ));
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) return $v;
        $days = max(0, (int) self::get('validity_days'));
        return wp_date('Y-m-d', strtotime('+' . $days . ' days', current_time('timestamp')));
    }

    public static function ajax_save_validity() {
        $deal_id = absint($_POST['deal_id'] ?? 0);
        $date    = sanitize_text_field(wp_unslash($_POST['date'] ?? ''));
        if (!$deal_id || !self::can_use() || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'] ?? '')), self::ACTION . '_validity_' . $deal_id)) {
            wp_send_json_error('Forbidden', 403);
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !strtotime($date)) wp_send_json_error('Invalid date', 400);
        global $wpdb;
        $t = $wpdb->prefix . 'achats_project_meta';
        $type = $wpdb->get_var($wpdb->prepare("SELECT DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'post_id'", $t));
        if ($type && strtolower($type) !== 'bigint') $wpdb->query("ALTER TABLE `{$t}` MODIFY `post_id` BIGINT UNSIGNED NOT NULL DEFAULT 0");   // identifiants de projet très grands
        $wpdb->delete($t, ['post_id' => $deal_id, 'meta_key' => self::META_VALIDITY]);
        $ok = $wpdb->insert($t, ['post_id' => $deal_id, 'meta_key' => self::META_VALIDITY, 'meta_value' => $date]);
        if ($ok === false) {   // table ancienne sans AUTO_INCREMENT sur meta_id
            $next = (int) $wpdb->get_var("SELECT COALESCE(MAX(meta_id),0)+1 FROM `{$t}`");
            $ok = $wpdb->insert($t, ['meta_id' => $next, 'post_id' => $deal_id, 'meta_key' => self::META_VALIDITY, 'meta_value' => $date]);
        }
        if ($ok === false) wp_send_json_error('Not saved: ' . $wpdb->last_error, 500);
        wp_send_json_success(['date' => $date]);
    }

    /** Bouton du groupe d'actions d'une offre + date de validité modifiable (vide si désactivé ou sans droit). */
    public static function button_html($deal_id): string {
        if (!self::can_use() || empty($deal_id)) return '';
        $deal_id = (int) $deal_id;
        $url = wp_nonce_url(add_query_arg(['action' => self::ACTION, 'deal_id' => $deal_id], admin_url('admin-post.php')), self::ACTION . '_' . $deal_id);
        $nonce = wp_create_nonce(self::ACTION . '_validity_' . $deal_id);
        $html  = '<a class="ispag-btn ispag-btn-secondary-outlined ispag-offer-pdf-btn" href="' . esc_url($url) . '" target="_blank" rel="noopener">'
            . '<span class="dashicons dashicons-media-document"></span> ' . esc_html__('Offer PDF', 'creation-reservoir') . '</a>';
        $html .= '<label class="ispag-offer-validity" style="display:inline-flex;align-items:center;gap:6px;font-size:13px;color:#555;">'
            . esc_html__('Valid until', 'creation-reservoir')
            . '<input type="date" class="ispag-offer-validity-input" value="' . esc_attr(self::validity_date($deal_id)) . '" data-deal="' . $deal_id . '" data-nonce="' . esc_attr($nonce) . '" data-url="' . esc_url(admin_url('admin-ajax.php')) . '"></label>';
        static $js = false;
        if (!$js) {
            $js = true;
            $html .= '<script>document.addEventListener("change",function(e){var i=e.target;if(!i.classList||!i.classList.contains("ispag-offer-validity-input"))return;'
                . 'var f=new FormData();f.append("action","' . self::ACTION . '_validity");f.append("deal_id",i.dataset.deal);f.append("nonce",i.dataset.nonce);f.append("date",i.value);'
                . 'fetch(i.dataset.url,{method:"POST",body:f,credentials:"same-origin"}).then(function(r){return r.json();}).then(function(d){i.style.outline=d&&d.success?"2px solid #2e7d32":"2px solid #c62828";setTimeout(function(){i.style.outline="";},1200);}).catch(function(){i.style.outline="2px solid #c62828";});});</script>';
        }
        return $html;
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
            'rplp_note'     => [__('RPLP notice (tag {RPLP} = rate of the "rplp" option; hidden when the rate is 0)', 'creation-reservoir'), 'textarea', "Veuillez tenir compte que toute marchandise livrée est assujettie à la redevance fédérale RPLP, fixée à {RPLP}% de sa valeur, et appliquée sur nos factures."],
            'payment_terms' => [__('Default payment terms (a company can have its own)', 'creation-reservoir'), 'text', '60 jours net'],
            'validity_days' => [__('Default offer validity (days from today; editable on each offer)', 'creation-reservoir'), 'number', '30'],
            'delivery'      => [__('Delivery', 'creation-reservoir'), 'text', 'DDP'],
            'closing_paragraph' => [__('Closing paragraph', 'creation-reservoir'), 'textarea', "Livraison et garantie selon nos conditions générales de vente. Nous espérons que notre offre vous convienne et restons à votre entière disposition pour tout renseignement complémentaire."],
            'closing'       => [__('Closing text', 'creation-reservoir'), 'text', 'Avec nos salutations distinguées'],
            'signature_company' => [__('Signing company (empty = company name of the site settings)', 'creation-reservoir'), 'text', 'ISPAG'],
            'acceptance'    => [__('Acceptance text (empty = no acceptance and signature block)', 'creation-reservoir'), 'textarea', ''],
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
