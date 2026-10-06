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
 *    taux de dédouanement appliqué à la vente (wpcb_custom_fee ; celui des achats est dans Purchase settings)
 *  - Coefficients de vente : racine wpcb_sales_coef (obligatoire) + autres wpcb_sales_coef_<nom> libres
 *
 * À l'activation, les valeurs par défaut manquantes sont créées et l'administrateur est envoyé sur la page
 * pour compléter les informations ; un bandeau reste affiché tant que la page n'a pas été enregistrée.
 */
class ISPAG_Settings {

    const PAGE         = 'ispag-settings';
    const OPT_SAVED    = 'ispag_settings_saved';
    const OPT_REDIRECT = 'ispag_settings_redirect';
    const OPT_LOG_MAILBOX = 'ispag_log_mailbox';
    const OPT_MISTRAL  = 'ispag_mistral_api_key';
    const COEF_ROOT    = 'wpcb_sales_coef';
    const OPT_LAMBDA   = 'ispag_insulation_lambda';   // [ Id du type d'isolant (achats_tank_conception) => λ en W/m·K ]
    const OPT_HOUT     = 'ispag_insulation_hout';     // échange thermique extérieur (convection + rayonnement), W/m²K
    const DEFAULT_LAMBDA = 0.040;
    const DEFAULT_HOUT   = 9.0;
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
            'wpcb_custom_fee'          => ['Customs clearance rate on sales (%)', 'number', 5, 'Used to compute the sales price from the purchase price. The rate for purchase orders is separate: ISPAG Settings → Purchase settings.'],
        ];
    }

    /** E-mails envoyés par la plateforme. */
    public static function mail_fields() {
        return [
            self::OPT_LOG_MAILBOX => ['CRM log mailbox (hidden copy)', 'email', 'log@mg.ispag-asp.com', 'Receives a hidden copy (Bcc) of the customer e-mails sent on project steps; the CRM files each one in its project with the reference at the bottom of the mail. Leave empty to send no copy.'],
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

    /**
     * Clé API Mistral (CRM_MISTRAL_API_KEY) : getenv() / $_ENV / $_SERVER / constante wp-config d'abord
     * (elles priment), puis la valeur saisie dans « ISPAG Settings ».
     */
    /** Types d'isolant du configurateur de réservoirs : [ Id => libellé ]. */
    public static function insulation_types() {
        global $wpdb;
        $table = $wpdb->prefix . 'achats_tank_conception';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) return [];
        $out = [];
        foreach ((array) $wpdb->get_results("SELECT Id, Value, matiere FROM {$table} WHERE SelectType = 'insulationType' ORDER BY Id") as $r) {
            $out[(int) $r->Id] = trim((string) ($r->matiere !== '' && $r->matiere !== null ? $r->matiere : $r->Value));
        }
        return $out;
    }

    /** Conductivité thermique λ (W/m·K) d'un type d'isolant ; 0,040 tant qu'elle n'est pas renseignée. */
    public static function insulation_lambda($type_id) {
        $all = (array) get_option(self::OPT_LAMBDA, []);
        $v = isset($all[(int) $type_id]) ? (float) $all[(int) $type_id] : 0;
        return $v > 0 ? $v : self::DEFAULT_LAMBDA;
    }

    public static function insulation_hout() {
        $v = (float) get_option(self::OPT_HOUT, self::DEFAULT_HOUT);
        return $v > 0 ? $v : self::DEFAULT_HOUT;
    }

    public static function mistral_api_key() {
        $name = 'CRM_MISTRAL_API_KEY';
        $key  = getenv($name);
        if (empty($key) && !empty($_ENV[$name]))    $key = $_ENV[$name];
        if (empty($key) && !empty($_SERVER[$name])) $key = $_SERVER[$name];
        if (empty($key) && defined($name))          $key = constant($name);
        if (empty($key))                            $key = get_option(self::OPT_MISTRAL, '');
        return is_string($key) ? trim($key) : '';
    }

    /** La clé vient-elle de l'environnement / de wp-config (non modifiable ici) ? */
    private static function mistral_key_is_external() {
        $name = 'CRM_MISTRAL_API_KEY';
        return !empty(getenv($name)) || !empty($_ENV[$name]) || !empty($_SERVER[$name]) || defined($name);
    }

    public static function init() {
        add_action('admin_menu', [self::class, 'menu']);
        add_action('admin_post_ispag_save_settings', [self::class, 'handle_save']);
        add_action('admin_notices', [self::class, 'notice']);
        add_action('admin_init', [self::class, 'maybe_redirect']);
    }

    /** Crée les valeurs par défaut manquantes (n'écrase jamais une valeur existante). */
    public static function ensure_defaults() {
        $all = array_merge(self::company_fields(), self::order_fields(), self::mail_fields());
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

        $ins_types = self::insulation_types();
        if ($ins_types) {
            echo '<h2>Tank insulation (thermal conductivity)</h2><p>Used by the stratification simulator to compute the heat losses of a tank from the insulation type and thickness. '
                . 'The values proposed (0.040 W/m·K) are placeholders: take the λ of each product from its data sheet, at the mean operating temperature (λ increases with temperature).</p>';
            echo '<table class="widefat striped" style="max-width:640px"><thead><tr><th>Insulation type</th><th>λ (W/m·K)</th></tr></thead><tbody>';
            foreach ($ins_types as $id => $label) {
                echo '<tr><td>' . esc_html($label) . '</td><td><input type="number" step="0.001" min="0.005" max="1" name="ins_lambda[' . (int) $id . ']" value="' . esc_attr(self::insulation_lambda($id)) . '" style="width:110px"></td></tr>';
            }
            echo '</tbody></table><table class="form-table"><tr><th scope="row"><label for="ispag_insulation_hout">Outer heat transfer (W/m²K)</label></th><td>'
                . '<input type="number" step="0.5" min="1" max="50" name="ispag_insulation_hout" id="ispag_insulation_hout" value="' . esc_attr(self::insulation_hout()) . '" style="width:110px">'
                . '<p class="description">Convection and radiation between the outside of the insulation and the room air. About 8–10 W/m²K for still indoor air.</p></td></tr></table>';
        }

        echo '<h2>E-mails</h2><table class="form-table">';
        foreach (self::mail_fields() as $key => $def) {
            self::row($key, $def, get_option($key, $def[2]), $states);
        }
        echo '</table>';

        echo '<h2>Artificial intelligence (Mistral)</h2><table class="form-table"><tr><th scope="row"><label for="ispag_mistral_api_key">API key (CRM_MISTRAL_API_KEY)</label></th><td>';
        if (self::mistral_key_is_external()) {
            echo '<p><em>The key is defined by the server (environment variable or wp-config.php) and takes priority; it cannot be changed here.</em></p>';
        } else {
            $has = get_option(self::OPT_MISTRAL, '') !== '';
            echo '<input class="regular-text" type="password" id="ispag_mistral_api_key" name="ispag_mistral_api_key" autocomplete="new-password" placeholder="' . ($has ? 'Key saved — leave empty to keep it' : '') . '">';
            if ($has) {
                echo ' <label><input type="checkbox" name="ispag_mistral_remove" value="1"> Remove the saved key</label>';
            }
            echo '<p class="description">Used by the CRM and the project assistant (summaries, document analysis). Stored in the database; never displayed again.</p>';
        }
        echo '</td></tr></table>';

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
        echo '</form>';
        self::render_translations_status();
        echo '</div>';
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

    /** Diagnostic des traductions : langue utilisée, textes chargés, blocage éventuel. */
    private static function render_translations_status() {
        $locale   = determine_locale();
        $lang     = substr($locale, 0, 2);
        $supported = in_array($lang, ['fr', 'de'], true);
        $blocked  = (bool) apply_filters('ispag_disable_translations', false);
        $pll      = function_exists('pll_current_language') ? (string) pll_current_language('locale') : '';
        echo '<h2>Translations</h2><table class="widefat striped" style="max-width:760px"><tbody>';
        echo '<tr><th>Site language (Settings &gt; General)</th><td><code>' . esc_html(get_locale()) . '</code></td></tr>';
        echo '<tr><th>Language used right now</th><td><code>' . esc_html($locale) . '</code>' . ($pll ? ' (Polylang: <code>' . esc_html($pll) . '</code>)' : '')
            . ($supported ? '' : ' — no ISPAG translation file for this language: texts stay in English.') . '</td></tr>';
        echo '<tr><th>Translations enabled</th><td>' . ($blocked ? '<strong style="color:#b91c1c">No</strong> — disabled by the filter <code>ispag_disable_translations</code> (an old plugin version or custom code).' : 'Yes') . '</td></tr>';
        $dirs = function_exists('ispag_i18n_dirs') ? ispag_i18n_dirs() : [];
        echo '<tr><th>Translation folders registered</th><td>' . ($dirs ? esc_html(implode(', ', array_map(function ($d) { return basename(dirname($d)) . '/' . basename($d); }, $dirs))) : '<strong style="color:#b91c1c">none</strong> — the plugins on this site do not contain the translation loader (update them all).') . '</td></tr>';
        foreach (['creation-reservoir', 'ispag-crm', 'ispag'] as $domain) {
            echo '<tr><th>Domain <code>' . esc_html($domain) . '</code></th><td>' . (is_textdomain_loaded($domain) ? 'loaded' : '<strong style="color:#b91c1c">not loaded</strong>') . '</td></tr>';
        }
        $sample = __('Save', 'ispag-crm');
        echo '<tr><th>Test: “Save” becomes</th><td><strong>' . esc_html($sample) . '</strong>' . ($sample === 'Save' && $supported && !$blocked ? ' <span style="color:#b91c1c">— not translated: reload the page; if it persists, the .mo files are missing from the languages/ folders.</span>' : '') . '</td></tr>';
        echo '</tbody></table><p class="description">Texts are written in English and translated to French / German from the files in each plugin\'s <code>languages/</code> folder. The language comes from Settings &gt; General (or from Polylang, or from the user profile in the admin).</p>';
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

        foreach (array_merge(self::company_fields(), self::order_fields(), self::mail_fields()) as $key => $def) {
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

        if (isset($_POST['ins_lambda']) && is_array($_POST['ins_lambda'])) {
            $lam = [];
            foreach ($_POST['ins_lambda'] as $id => $v) {
                $v = (float) str_replace(',', '.', (string) wp_unslash($v));
                if ((int) $id > 0 && $v >= 0.005 && $v <= 1) $lam[(int) $id] = $v;
            }
            update_option(self::OPT_LAMBDA, $lam);
        }
        if (isset($_POST['ispag_insulation_hout'])) {
            $ho = (float) str_replace(',', '.', (string) wp_unslash($_POST['ispag_insulation_hout']));
            if ($ho >= 1 && $ho <= 50) update_option(self::OPT_HOUT, $ho);
        }

        if (!empty($_POST['ispag_mistral_remove'])) {
            delete_option(self::OPT_MISTRAL);
        } elseif (isset($_POST['ispag_mistral_api_key']) && trim((string) wp_unslash($_POST['ispag_mistral_api_key'])) !== '') {
            update_option(self::OPT_MISTRAL, trim(sanitize_text_field(wp_unslash($_POST['ispag_mistral_api_key']))), false);
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
