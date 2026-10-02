<?php
defined('ABSPATH') || exit;

/**
 * Réglages ISPAG (page d'administration « ISPAG Settings »).
 *
 * Les valeurs restent stockées dans les options WordPress historiques (wpcb_*) : le reste du code
 * (get_option('wpcb_currency'), get_option('wpcb_sales_coef'), …) n'a donc pas à changer.
 *
 *  - Entreprise utilisatrice : nom, adresse, NPA, ville, pays, e-mail, téléphone, site, devise
 *  - Commandes : centre de coût (wpcb_kst), premiers états devis / commande, taxe poids lourd (rplp),
 *    taux de dédouanement (wpcb_custom_fee)
 *  - Coefficients de vente : racine wpcb_sales_coef (obligatoire) + autres wpcb_sales_coef_<nom> libres
 *
 * À l'activation, les valeurs par défaut manquantes sont créées et l'administrateur est envoyé sur la page
 * pour compléter les informations ; un bandeau reste affiché tant que la page n'a pas été enregistrée.
 */
class ISPAG_Settings {

    const PAGE         = 'ispag-settings';
    const OPT_SAVED    = 'ispag_settings_saved';
    const OPT_REDIRECT = 'ispag_settings_redirect';
    const COEF_ROOT    = 'wpcb_sales_coef';
    /** Coefficients lus explicitement par le code (ne peuvent pas être supprimés). */
    const COEF_LOCKED  = ['wpcb_sales_coef_low', 'wpcb_sales_coef_offre_revendeur'];

    /** Champs simples : option => [libellé, type, défaut, aide] */
    public static function company_fields() {
        return [
            'wpcb_companyName'    => ['Company name', 'text', '', ''],
            'wpcb_companyAdress'  => ['Address', 'text', '', ''],
            'wpcb_companyNIP'     => ['Postal code', 'text', '', ''],
            'wpcb_companyCity'    => ['City', 'text', '', ''],
            'wpcb_companyCountry' => ['Country', 'text', '', ''],
            'wpcb_companyMail'    => ['Email', 'email', '', ''],
            'wpcb_companyPhone'   => ['Phone', 'text', '', ''],
            'wpcb_companyWebsite' => ['Website', 'text', '', ''],
            'wpcb_currency'       => ['Currency', 'text', 'CHF', 'Displayed next to prices, e.g. CHF, EUR or €.'],
        ];
    }

    public static function order_fields() {
        return [
            'wpcb_kst'                 => ['Cost center (KST)', 'text', '', 'Prefix of the order reference: <code>KST/order number - project</code>.'],
            'wpcb_first_qotation_state' => ['First state of quotations', 'state', 6, 'Initial state given to supplier requests created from a quotation.'],
            'wpcb_first_order_state'    => ['First state of orders', 'state', 1, 'Initial state given to purchase orders created from a firm order.'],
            'rplp'                     => ['Swiss heavy vehicle fee (RPLP, %)', 'number', 0, 'Percentage added to the transport cost.'],
            'wpcb_custom_fee'          => ['Customs clearance rate (%)', 'number', 10, 'Used to compute the sales price from the purchase price and the customs clearance line (DED) of purchase orders. Also editable in ISPAG Settings → Purchase settings.'],
        ];
    }

    /** Coefficients proposés à la création (modifiables, à vérifier avant usage). */
    public static function default_coefs() {
        return [
            self::COEF_ROOT                          => 1.30,
            self::COEF_ROOT . '_low'                 => 1.15,
            self::COEF_ROOT . '_offre_revendeur'     => 1.20,
        ];
    }

    public static function init() {
        add_action('admin_menu', [self::class, 'menu']);
        add_action('admin_post_ispag_save_settings', [self::class, 'handle_save']);
        add_action('admin_notices', [self::class, 'notice']);
        add_action('admin_init', [self::class, 'maybe_redirect']);
    }

    /** Crée les valeurs par défaut manquantes (n'écrase jamais une valeur existante). */
    public static function ensure_defaults() {
        $all = array_merge(self::company_fields(), self::order_fields());
        foreach ($all as $key => $def) {
            if (get_option($key, null) === null) {
                add_option($key, $def[2]);
            }
        }
        foreach (self::default_coefs() as $key => $value) {
            if (get_option($key, null) === null) {
                add_option($key, $value);
            }
        }
        // Site déjà configuré (ex. informations d'entreprise déjà saisies) : pas de bandeau
        if (get_option(self::OPT_SAVED, null) === null && trim((string) get_option('wpcb_companyName', '')) !== '') {
            update_option(self::OPT_SAVED, 1);
        }
    }

    /** Activation du plugin : valeurs par défaut + une redirection vers la page de réglages. */
    public static function on_activation() {
        self::ensure_defaults();
        if (!get_option(self::OPT_SAVED)) {
            update_option(self::OPT_REDIRECT, 1);
        }
    }

    public static function menu() {
        add_menu_page('ISPAG Settings', 'ISPAG Settings', 'manage_options', self::PAGE, [self::class, 'render'], 'dashicons-admin-generic', 58);
    }

    public static function url() {
        return admin_url('admin.php?page=' . self::PAGE);
    }

    /** Une seule redirection, juste après l'activation. */
    public static function maybe_redirect() {
        if (!get_option(self::OPT_REDIRECT) || wp_doing_ajax() || isset($_GET['activate-multi'])) {
            return;
        }
        delete_option(self::OPT_REDIRECT);
        if (current_user_can('manage_options') && !get_option(self::OPT_SAVED)) {
            wp_safe_redirect(self::url());
            exit;
        }
    }

    public static function notice() {
        if (get_option(self::OPT_SAVED) || !current_user_can('manage_options')) {
            return;
        }
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if ($screen && strpos((string) $screen->id, self::PAGE) !== false) {
            return;
        }
        echo '<div class="notice notice-warning"><p><strong>ISPAG:</strong> please complete your company information and settings. '
            . '<a href="' . esc_url(self::url()) . '">Open ISPAG Settings</a></p></div>';
    }

    /** Coefficients existants : [option_name => valeur], racine en premier. */
    public static function get_coefs() {
        global $wpdb;
        $rows = $wpdb->get_results("SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE 'wpcb\\_sales\\_coef%' ORDER BY option_name");
        $coefs = [];
        foreach ((array) $rows as $r) {
            $coefs[$r->option_name] = $r->option_value;
        }
        if (!isset($coefs[self::COEF_ROOT])) {
            $coefs = [self::COEF_ROOT => ''] + $coefs;
        }
        ksort($coefs);
        // la racine d'abord
        return [self::COEF_ROOT => $coefs[self::COEF_ROOT]] + $coefs;
    }

    /** États proposés : table des états de commande fournisseur (id => libellé). */
    private static function states() {
        global $wpdb;
        $table = $wpdb->prefix . 'achats_etat_commandes_fournisseur';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
            return [];
        }
        $out = [];
        foreach ((array) $wpdb->get_results("SELECT Id, steps, Etat FROM {$table} ORDER BY steps, ordre") as $r) {
            $out[(int) $r->Id] = ($r->steps !== '' ? $r->steps . ' — ' : '') . $r->Etat . ' (#' . $r->Id . ')';
        }
        return $out;
    }

    public static function render() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Access denied', 'creation-reservoir'));
        }
        $states = self::states();
        echo '<div class="wrap"><h1>ISPAG Settings</h1>';
        if (isset($_GET['saved'])) {
            echo '<div class="notice notice-success is-dismissible"><p>Settings saved.</p></div>';
        }
        if (isset($_GET['error'])) {
            echo '<div class="notice notice-error"><p>' . esc_html(wp_unslash($_GET['error'])) . '</p></div>';
        }
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('ispag_save_settings');
        echo '<input type="hidden" name="action" value="ispag_save_settings">';

        echo '<h2>Your company</h2><p>Shown on generated documents (PDF footers) and used for the currency of all prices.</p><table class="form-table">';
        foreach (self::company_fields() as $key => $def) {
            self::row($key, $def, get_option($key, $def[2]), $states);
        }
        echo '</table>';

        echo '<h2>Orders and prices</h2><table class="form-table">';
        foreach (self::order_fields() as $key => $def) {
            self::row($key, $def, get_option($key, $def[2]), $states);
        }
        echo '</table>';

        echo '<h2>Sales coefficients</h2><p>Sales price = purchase price × coefficient. The <strong>Standard</strong> coefficient is required; '
            . 'you can add others (they appear in the coefficient selector of each project).</p>';
        echo '<table class="widefat striped" style="max-width:640px" id="ispag-coef-table"><thead><tr><th>Name</th><th>Option</th><th>Coefficient</th><th></th></tr></thead><tbody>';
        foreach (self::get_coefs() as $opt => $value) {
            $suffix = ltrim(substr($opt, strlen(self::COEF_ROOT)), '_');
            echo '<tr>';
            if ($opt === self::COEF_ROOT) {
                echo '<td><strong>Standard</strong></td><td><code>' . esc_html($opt) . '</code></td>';
                echo '<td><input type="number" step="0.01" min="0.01" required name="coef_root" value="' . esc_attr($value) . '" style="width:110px"></td><td></td>';
            } else {
                echo '<td><input type="text" name="coef_name[]" value="' . esc_attr($suffix) . '" pattern="[a-z0-9_]+" style="width:180px"></td>';
                echo '<td><code>' . esc_html($opt) . '</code></td>';
                echo '<td><input type="number" step="0.01" min="0.01" name="coef_value[]" value="' . esc_attr($value) . '" style="width:110px"></td>';
                echo in_array($opt, self::COEF_LOCKED, true)
                    ? '<td><span class="description">used by the price calculation</span></td>'
                    : '<td><button type="button" class="button ispag-coef-del">Remove</button></td>';
            }
            echo '</tr>';
        }
        echo '</tbody></table><p><button type="button" class="button" id="ispag-coef-add">Add a coefficient</button> '
            . '<span class="description">Name: lowercase letters, digits and underscores (e.g. <code>partner</code>). Leave the name empty to ignore a row.</span></p>';

        submit_button('Save settings');
        echo '</form></div>';
        ?>
        <script>
        (function () {
            var tb = document.querySelector('#ispag-coef-table tbody');
            document.getElementById('ispag-coef-add').addEventListener('click', function () {
                var tr = document.createElement('tr');
                tr.innerHTML = '<td><input type="text" name="coef_name[]" pattern="[a-z0-9_]+" style="width:180px"></td><td></td>' +
                    '<td><input type="number" step="0.01" min="0.01" name="coef_value[]" style="width:110px"></td>' +
                    '<td><button type="button" class="button ispag-coef-del">Remove</button></td>';
                tb.appendChild(tr);
            });
            tb.addEventListener('click', function (e) {
                if (e.target.classList.contains('ispag-coef-del')) { e.target.closest('tr').remove(); }
            });
        })();
        </script>
        <?php
    }

    private static function row($key, $def, $value, $states) {
        list($label, $type, , $help) = $def;
        echo '<tr><th scope="row"><label for="' . esc_attr($key) . '">' . esc_html($label) . '</label></th><td>';
        if ($type === 'state' && $states) {
            echo '<select name="' . esc_attr($key) . '" id="' . esc_attr($key) . '">';
            foreach ($states as $id => $text) {
                echo '<option value="' . (int) $id . '"' . selected((int) $value, $id, false) . '>' . esc_html($text) . '</option>';
            }
            echo '</select>';
        } else {
            $html_type = in_array($type, ['email', 'number'], true) ? $type : 'text';
            if ($type === 'state') { $html_type = 'number'; }
            echo '<input class="regular-text" type="' . esc_attr($html_type) . '"' . ($html_type === 'number' ? ' step="any" style="width:120px"' : '')
                . ' name="' . esc_attr($key) . '" id="' . esc_attr($key) . '" value="' . esc_attr($value) . '">';
        }
        if ($help) {
            echo '<p class="description">' . wp_kses($help, ['code' => []]) . '</p>';
        }
        echo '</td></tr>';
    }

    public static function handle_save() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Access denied', 'creation-reservoir'));
        }
        check_admin_referer('ispag_save_settings');

        foreach (array_merge(self::company_fields(), self::order_fields()) as $key => $def) {
            if (!isset($_POST[$key])) {
                continue;
            }
            $raw = wp_unslash($_POST[$key]);
            switch ($def[1]) {
                case 'email':  $val = sanitize_email($raw); break;
                case 'number': $val = ($raw === '' ? 0 : (float) str_replace(',', '.', $raw)); break;
                case 'state':  $val = (int) $raw; break;
                default:       $val = sanitize_text_field($raw);
            }
            update_option($key, $val);
        }

        // Coefficients : racine obligatoire, les autres recréés d'après le formulaire
        $root = (float) str_replace(',', '.', (string) wp_unslash($_POST['coef_root'] ?? ''));
        if ($root <= 0) {
            wp_safe_redirect(add_query_arg('error', rawurlencode('The Standard coefficient must be greater than 0.'), self::url()));
            exit;
        }
        update_option(self::COEF_ROOT, $root);

        $names  = (array) ($_POST['coef_name'] ?? []);
        $values = (array) ($_POST['coef_value'] ?? []);
        $keep   = [self::COEF_ROOT => true];
        foreach ($names as $i => $name) {
            $name = strtolower(trim(preg_replace('/[^a-z0-9_]/i', '', wp_unslash($name)), '_'));
            $val  = (float) str_replace(',', '.', (string) wp_unslash($values[$i] ?? ''));
            if ($name === '' || $val <= 0) {
                continue;
            }
            $opt = self::COEF_ROOT . '_' . $name;
            update_option($opt, $val);
            $keep[$opt] = true;
        }
        foreach (array_keys(self::get_coefs()) as $opt) {
            if (!isset($keep[$opt]) && !in_array($opt, self::COEF_LOCKED, true)) {
                delete_option($opt);
            }
        }

        update_option(self::OPT_SAVED, 1);
        wp_safe_redirect(add_query_arg('saved', 1, self::url()));
        exit;
    }
}
