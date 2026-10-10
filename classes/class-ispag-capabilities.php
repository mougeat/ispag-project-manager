<?php
defined('ABSPATH') || exit;

/**
 * Droits (capabilities) et rôles ISPAG.
 *
 *  - registry() : liste unique des droits utilisés par les plugins ISPAG et le thème (libellé, plugin, description).
 *  - roles()    : rôles utilisateurs ISPAG, créés à l'installation (ils servent aussi de « rôle » dans la fiche contact).
 *  - install()  : crée les rôles manquants et donne à l'administrateur les droits du registre (une seule fois par droit :
 *                 seuls les droits ajoutés depuis la dernière exécution sont accordés, un droit retiré à la main n'est pas redonné).
 *  - page « ISPAG Rights » : matrice droits × rôles modifiable, et diagnostic du compte connecté.
 *
 * L'attribution des droits aux rôles (hors administrateur) se fait dans cette page.
 */
class ISPAG_Capabilities {

    const REGISTRY_VERSION = 9;
    const OPT_VERSION      = 'ispag_caps_registry_version';
    const PAGE             = 'ispag-rights';

    /** droit => [libellé, plugin, description, ajouté à la version, rôles qui le reçoivent à l'ajout (facultatif), droit dont les rôles héritent à l'ajout (facultatif)] */
    public static function registry() {
        return [
            'manage_order'                              => ['Manage projects and orders', 'Project Manager', 'Create and edit projects, articles and prices; access to project actions.', 1],
            'display_sales_prices'                      => ['See sales prices', 'Project Manager', 'Display sales prices, totals and margins.', 1],
            'real_all_orders'                           => ['See all projects', 'Project Manager', 'Project and offer lists show every project, not only the user\'s own.', 1],
            'navigate_new_project_details_presentation' => ['New project view', 'Project Manager', 'Use the 3-column project details view and its links.', 1],
            'read_orders'                               => ['See own purchase orders', 'Purchasing', 'Purchase list limited to the orders assigned to the user.', 1],
            'view_supplier_order'                        => ['See all purchase orders', 'Purchasing', 'Access to the purchase list and to purchase order details.', 1],
            'edit_supplier_order'                        => ['Edit purchase orders', 'Purchasing', 'Edit purchase orders, generate and send supplier documents.', 1],
            'generate_tank'                              => ['Design tanks', 'Tank Builder', 'Create and modify tank designs and drawings.', 1],
            'manage_site_welding_datas'                  => ['Manage on-site welding sheets', 'Tank Builder', 'Fill in and export the on-site welding data sheets.', 1],
            'view_tank_price'                            => ['See tank prices', 'Tank Builder', 'Show the indicative tank price in the tank design / edit window (hidden entirely without this right).', 3, ['vente_ispag', 'membre_ispag', 'chiffreur']],
            'view_tank_list'                             => ['See tank list', 'Tank Builder', 'Page listing the tanks designed in the projects, with a detail page per tank (specifications and description). Prices only with the right below or the right to see sales prices.', 8, ['vente_ispag', 'membre_ispag', 'chiffreur']],
            'view_tank_gross_price'                      => ['See tank prices without discounts', 'Tank Builder', 'For engineering offices: the tank and insulation prices before any discount, in the tank list, the tank detail page and the project page (lines of tank and insulation only, no totals, no margins). Given person by person (user profile) or to a role.', 8],
            'display_beta'                               => ['See beta features', 'Tank Builder', 'Show features still in testing (e.g. 3D view).', 1],
            'edit_company'                               => ['Edit companies', 'CRM', 'Edit companies in the CRM (admin menu and company page).', 1],
            'add_company'                                => ['Add companies', 'CRM', 'Create companies from the CRM.', 1],
            'create_offer'                               => ['Create offers', 'Project Manager', 'Create offers and projects (creation pages).', 2, ['vente_ispag', 'achat_ispag', 'membre_ispag', 'chiffreur', 'ingenieur']],
            'view_company'                               => ['See companies', 'CRM', 'Company list and company detail pages.', 2, ['vente_ispag', 'achat_ispag', 'membre_ispag', 'chiffreur']],
            'view_contact'                               => ['See contacts', 'CRM', 'Contact list and contact detail pages.', 2, ['vente_ispag', 'achat_ispag', 'membre_ispag', 'chiffreur']],
            'add_contact'                                => ['Add contacts', 'CRM', 'Create contacts from the CRM.', 2, ['vente_ispag', 'achat_ispag', 'membre_ispag', 'chiffreur']],
            'manage_templates'                           => ['Manage templates', 'CRM', 'List and edit message / comment templates.', 2, ['vente_ispag', 'membre_ispag']],
            'view_standard_articles'                     => ['See standard articles', 'Project Manager', 'Standard article list and detail pages.', 2, ['vente_ispag', 'achat_ispag', 'membre_ispag', 'chiffreur', 'ingenieur', 'purchase']],
            'edit_standard_articles'                     => ['Edit standard articles', 'Project Manager', 'Modify and delete standard articles.', 2],
            'view_stock'                                 => ['See stock', 'Stock', 'Stock by location and movement log (page with [ispag_stock]).', 4, ['vente_ispag', 'achat_ispag', 'membre_ispag']],
            'manage_stock'                               => ['Manage stock', 'Stock', 'Enter stock movements: receipts, transfers between locations, deliveries to customers.', 4, ['achat_ispag', 'membre_ispag']],
            'view_stats'                                 => ['See statistics', 'Dashboard', 'ISPAG stats menu: supplier statistics, project follow-up and monthly report (amounts also need the right to see sales prices).', 5],
            'manage_suppliers'                           => ['Manage suppliers', 'CRM', 'Mark a company as supplier and edit the Supplier tab of its company page (purchasing information, contacts). Roles that could edit purchase orders receive it when it is added.', 6, [], 'edit_supplier_order'],
            'manage_supplier_payments'                   => ['Manage supplier payments', 'Purchasing', 'Enter the amount (proforma invoice) and the dates of the payments that suppliers require before delivery. Purchasing and sales can see them but not change them.', 7],
            'export_publishable_projects'                => ['Read publishable projects (API)', 'Project Manager', 'Read-only API returning an anonymised summary and the photos of the projects marked “Publishable” (for drafting posts). Grant it to a dedicated account only.', 9, [], null],
            'edit_stats'                                 => ['Edit statistics goals', 'Dashboard', 'Type the annual goals and the credit notes of the monthly report.', 5],
        ];
    }

    /** slug => libellé (les slugs sont ceux utilisés dans la fiche contact et le code existant). */
    public static function roles() {
        return [
            'vente_ispag'   => 'Sales ISPAG',
            'client'        => 'Customer',
            'membre_ispag'  => 'ISPAG Member',
            'achat_ispag'   => 'Purchasing ISPAG',
            'ingenieur'     => 'Engineer',
            'chiffreur'     => 'Estimator',
            'purchase'      => 'Buyer',
        ];
    }

    public static function init() {
        add_action('init', [self::class, 'maybe_install'], 5);
        add_action('admin_menu', [self::class, 'menu'], 20);
        add_action('admin_post_ispag_save_rights', [self::class, 'handle_save']);
        // Droits individuels : section dans la fiche utilisateur (en plus des droits de son rôle)
        add_action('show_user_profile', [self::class, 'render_user_rights']);
        add_action('edit_user_profile', [self::class, 'render_user_rights']);
        add_action('personal_options_update', [self::class, 'save_user_rights']);
        add_action('edit_user_profile_update', [self::class, 'save_user_rights']);
    }

    /**
     * Actions de gestion d'un projet (achats, demande d'achat, transformer en commande, répliquer, supprimer, facture) :
     * personnel ISPAG avec manage_order. Un compte « Engineer » ou « Customer » ne les a jamais, même s'il a manage_order
     * (les ingénieurs ajoutent seulement des articles) ; un administrateur les a toujours.
     */
    public static function can_manage_project_actions($user_id = 0) {
        $user_id = (int) ($user_id ?: get_current_user_id());
        if (!$user_id) return false;
        if (user_can($user_id, 'manage_options')) return true;
        if (!user_can($user_id, 'manage_order')) return false;
        $u = get_userdata($user_id);
        return !($u && array_intersect(['ingenieur', 'client'], (array) $u->roles));
    }

    /** Un des rôles de l'utilisateur accorde-t-il ce droit ? */
    private static function role_grants($user, $cap) {
        foreach ((array) $user->roles as $slug) {
            $role = get_role($slug);
            if ($role && $role->has_cap($cap)) return true;
        }
        return false;
    }

    /** Section « Droits ISPAG individuels » de la fiche utilisateur : visible et modifiable par un administrateur seulement. */
    public static function render_user_rights($user) {
        if (!current_user_can('manage_options') || !($user instanceof WP_User) || in_array('administrator', (array) $user->roles, true)) {
            return;
        }
        $by_plugin = [];
        foreach (self::registry() as $cap => $def) $by_plugin[$def[1]][$cap] = $def;
        wp_nonce_field('ispag_user_rights_' . $user->ID, 'ispag_user_rights_nonce');
        echo '<h2>ISPAG individual rights</h2><p class="description">Rights granted to this person in addition to those of their role. A ticked and greyed right already comes from the role (change it in ISPAG Settings → Rights).</p>';
        echo '<table class="form-table" role="presentation"><tbody>';
        foreach ($by_plugin as $plugin => $caps) {
            echo '<tr><th scope="row">' . esc_html($plugin) . '</th><td>';
            foreach ($caps as $cap => $def) {
                $via_role   = self::role_grants($user, $cap);
                $individual = !empty($user->caps[$cap]);
                echo '<label style="display:block;margin-bottom:4px;"><input type="checkbox" name="ispag_user_caps[]" value="' . esc_attr($cap) . '"'
                    . checked($via_role || $individual, true, false) . disabled($via_role, true, false) . '> '
                    . esc_html($def[0]) . ($via_role ? ' <em>(role)</em>' : '') . ' <span class="description">— ' . esc_html($def[2]) . '</span></label>';
            }
            echo '</td></tr>';
        }
        echo '</tbody></table>';
    }

    public static function save_user_rights($user_id) {
        if (!current_user_can('manage_options') || empty($_POST['ispag_user_rights_nonce'])
            || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['ispag_user_rights_nonce'])), 'ispag_user_rights_' . (int) $user_id)) {
            return;
        }
        $user = get_userdata((int) $user_id);
        if (!$user || in_array('administrator', (array) $user->roles, true)) {
            return;
        }
        $posted = isset($_POST['ispag_user_caps']) && is_array($_POST['ispag_user_caps']) ? array_map('sanitize_key', wp_unslash($_POST['ispag_user_caps'])) : [];
        foreach (array_keys(self::registry()) as $cap) {
            if (self::role_grants($user, $cap)) continue; // vient du rôle : on n'y touche pas
            $has = !empty($user->caps[$cap]);
            if (in_array($cap, $posted, true)) {
                if (!$has) $user->add_cap($cap);
            } elseif ($has) {
                $user->remove_cap($cap);
            }
        }
    }

    /** Rattrapage : après une mise à jour du registre, sans attendre une réactivation des plugins. */
    public static function maybe_install() {
        if ((int) get_option(self::OPT_VERSION, 0) < self::REGISTRY_VERSION) {
            self::install();
        }
    }

    /** Rôles + droits de l'administrateur. Idempotent. */
    public static function install() {
        foreach (self::roles() as $slug => $label) {
            if (!get_role($slug)) {
                add_role($slug, $label, ['read' => true]);
            }
        }
        $done  = (int) get_option(self::OPT_VERSION, 0);
        $admin = get_role('administrator');
        if ($done < self::REGISTRY_VERSION) {
            foreach (self::registry() as $cap => $def) {
                if ($def[3] <= $done) continue; // droit déjà connu : jamais redonné
                if ($admin && !$admin->has_cap($cap)) {
                    $admin->add_cap($cap);
                }
                // Attribution initiale aux rôles prévus (une seule fois, modifiable ensuite dans la page « Rights »)
                foreach ((array) ($def[4] ?? []) as $slug) {
                    $role = get_role($slug);
                    if ($role && !$role->has_cap($cap)) {
                        $role->add_cap($cap);
                    }
                }
                // Droit qui en remplace un autre pour une partie de ses usages : les rôles qui avaient celui-là gardent leurs possibilités
                if (!empty($def[5])) {
                    foreach (wp_roles()->role_objects as $role) {
                        if ($role->has_cap($def[5]) && !$role->has_cap($cap)) {
                            $role->add_cap($cap);
                        }
                    }
                }
            }
        }
        update_option(self::OPT_VERSION, self::REGISTRY_VERSION);
    }

    // ── Page d'administration ────────────────────────────────────────────────

    public static function menu() {
        add_submenu_page(ISPAG_Settings::PAGE, 'ISPAG Rights', 'Rights', 'manage_options', self::PAGE, [self::class, 'render']);
    }

    public static function render() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Access denied', 'creation-reservoir'));
        }
        $registry = self::registry();
        $columns  = ['administrator' => 'Administrator'] + self::roles();
        $user     = wp_get_current_user();
        $missing  = [];
        foreach ($registry as $cap => $def) {
            if (!user_can($user, $cap)) {
                $missing[] = $cap;
            }
        }

        echo '<div class="wrap"><h1>ISPAG Rights</h1>';
        if (isset($_GET['saved'])) {
            echo '<div class="notice notice-success is-dismissible"><p>Rights saved.</p></div>';
        }
        echo '<div class="notice notice-info inline"><p><strong>Your account</strong> (' . esc_html($user->user_login) . ') — roles: <code>'
            . esc_html(implode(', ', (array) $user->roles)) . '</code>. ';
        echo $missing
            ? 'Missing rights: <code>' . esc_html(implode('</code>, <code>', $missing)) . '</code>.'
            : 'You have all ISPAG rights.';
        echo '</p></div>';

        echo '<p>Tick the rights each role must have. The Administrator column is locked so that you cannot lock yourself out.</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('ispag_save_rights');
        echo '<input type="hidden" name="action" value="ispag_save_rights">';
        echo '<table class="widefat striped" style="max-width:1100px"><thead><tr><th>Right</th><th>Used by</th>';
        foreach ($columns as $slug => $label) {
            echo '<th style="text-align:center">' . esc_html($label) . '<br><code style="font-weight:normal">' . esc_html($slug) . '</code></th>';
        }
        echo '</tr></thead><tbody>';
        foreach ($registry as $cap => $def) {
            echo '<tr><td><strong>' . esc_html($def[0]) . '</strong><br><code>' . esc_html($cap) . '</code><br><span class="description">' . esc_html($def[2]) . '</span></td>';
            echo '<td>' . esc_html($def[1]) . '</td>';
            foreach ($columns as $slug => $label) {
                $role = get_role($slug);
                $has  = $role && $role->has_cap($cap);
                if ($slug === 'administrator') {
                    echo '<td style="text-align:center"><input type="checkbox" checked disabled></td>';
                } else {
                    echo '<td style="text-align:center"><input type="checkbox" name="rights[' . esc_attr($slug) . '][' . esc_attr($cap) . ']" value="1"' . checked($has, true, false) . ($role ? '' : ' disabled') . '></td>';
                }
            }
            echo '</tr>';
        }
        echo '</tbody></table>';
        submit_button('Save rights');
        echo '<p><button type="submit" name="ispag_repair_admin" value="1" class="button">Repair administrator rights</button> '
            . '<span class="description">Gives the Administrator role every right above (use it if an administrator sees “Restricted access”).</span></p>';
        echo '</form></div>';
    }

    public static function handle_save() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Access denied', 'creation-reservoir'));
        }
        check_admin_referer('ispag_save_rights');

        $registry = self::registry();
        if (!empty($_POST['ispag_repair_admin'])) {
            $admin = get_role('administrator');
            foreach (array_keys($registry) as $cap) {
                if ($admin && !$admin->has_cap($cap)) {
                    $admin->add_cap($cap);
                }
            }
        }

        $posted = isset($_POST['rights']) && is_array($_POST['rights']) ? wp_unslash($_POST['rights']) : [];
        foreach (array_keys(self::roles()) as $slug) {
            $role = get_role($slug);
            if (!$role) {
                continue;
            }
            foreach (array_keys($registry) as $cap) {
                $want = !empty($posted[$slug][$cap]);
                if ($want && !$role->has_cap($cap)) {
                    $role->add_cap($cap);
                } elseif (!$want && $role->has_cap($cap)) {
                    $role->remove_cap($cap);
                }
            }
        }
        wp_safe_redirect(add_query_arg('saved', 1, admin_url('admin.php?page=' . self::PAGE)));
        exit;
    }
}
