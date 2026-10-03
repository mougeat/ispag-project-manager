<?php
defined('ABSPATH') || exit;

/**
 * Pages ISPAG réservées aux utilisateurs connectés : un visiteur non connecté est envoyé vers la page de connexion,
 * avec l'adresse demandée mémorisée (redirect_to) : une fois connecté, WordPress l'y ramène.
 *
 * Sont protégées les pages qui contiennent un shortcode [ispag_…] ou utilisent un modèle de page ISPAG
 * (page-*.php, ispag-kanban-viewer.php) — sauf les pages publiques (formulaire de demande d'offre, fiche technique,
 * validation de plan), modifiables par les filtres ispag_public_shortcodes et ispag_public_templates.
 */
class ISPAG_Access_Guard {

    public static function init() {
        add_action('template_redirect', [self::class, 'maybe_redirect_to_login'], 1);
        add_action('template_redirect', [self::class, 'maybe_deny_without_rights'], 2);
        add_action('admin_init', [self::class, 'maybe_deny_ajax_without_rights'], 1);
    }

    /** Droit exigé par action AJAX (les gestionnaires propres à chaque action peuvent en demander davantage). */
    public static function ajax_rights() {
        return (array) apply_filters('ispag_ajax_rights', [
            // CRM : création
            'ispag_create_company'                => 'add_company',
            'ispag_create_contact'                => 'add_contact',
            'save_company_field'                  => 'edit_company',
            // CRM : offres (deals) en lecture
            'ispag_kanban_load_more'              => 'real_all_orders',
            'ispag_export_deals'                  => 'real_all_orders',
            'ispag_search_deals_select2'          => 'real_all_orders',
            // CRM : offres (deals) en écriture
            'ispag_bulk_update_deals'             => 'manage_order',
            'ispag_update_deal_stage'             => 'manage_order',
            'ispag_remove_deal_contact_association' => 'manage_order',
            // Modèles
            'ispag_save_template'                 => 'manage_templates',
            'ispag_delete_template'               => 'manage_templates',
            'ispag_save_folder'                   => 'manage_templates',
            // Duplication
            'ispag_duplicate_article'             => 'manage_order',
            'ispag_duplicate_project'             => 'manage_order',
        ]);
    }

    public static function maybe_deny_ajax_without_rights() {
        if (!wp_doing_ajax() || !is_user_logged_in()) return;
        $action = isset($_REQUEST['action']) ? sanitize_key(wp_unslash($_REQUEST['action'])) : '';
        $map = self::ajax_rights();
        if ($action !== '' && isset($map[$action]) && !current_user_can($map[$action])) {
            wp_send_json_error(['message' => __('You do not have the necessary rights for this action.', 'creation-reservoir')], 403);
        }
    }

    /** Droit exigé par modèle de page ISPAG (le visiteur doit être connecté ET avoir ce droit). */
    public static function template_rights() {
        return (array) apply_filters('ispag_template_rights', [
            'page-list-companies.php'             => 'view_company',
            'page-company-detail.php'             => 'view_company',
            'page-list-contacts.php'              => 'view_contact',
            'page-contact-detail.php'             => 'view_contact',
            'page-contact-detail-responsive.php'  => 'view_contact',
            'ispag-kanban-viewer.php'             => 'real_all_orders',
            'page-list-deals.php'                 => 'real_all_orders',
            'page-deal-detail-viewer.php'         => 'real_all_orders',
            'page-template-table.php'             => 'manage_templates',
        ]);
    }

    /** Droit exigé par shortcode [ispag_…]. */
    public static function shortcode_rights() {
        return (array) apply_filters('ispag_shortcode_rights', [
            'ispag_creation_projet'    => 'create_offer',
            'ispag_contact_list'       => 'view_contact',
            'ispag_standard_articles'  => 'view_standard_articles',
            'ispag_standard_article'   => 'view_standard_articles',
        ]);
    }

    /** @return string[] droits exigés par la page (tous). */
    public static function required_rights($post) {
        $caps = [];
        if (!$post || $post->post_type !== 'page') return $caps;
        $template = (string) get_page_template_slug($post);
        $map = self::template_rights();
        if ($template !== '' && isset($map[$template])) $caps[] = $map[$template];
        if (preg_match_all('/\[(ispag_[a-z0-9_]+)/i', (string) $post->post_content, $m)) {
            $smap = self::shortcode_rights();
            foreach ($m[1] as $tag) {
                $tag = strtolower($tag);
                if (isset($smap[$tag])) $caps[] = $smap[$tag];
            }
        }
        return array_values(array_unique($caps));
    }

    /** Utilisateur connecté mais sans le droit exigé par la page : accès refusé. */
    public static function maybe_deny_without_rights() {
        if (!is_user_logged_in() || is_admin() || wp_doing_ajax() || (defined('REST_REQUEST') && REST_REQUEST) || !is_page()) {
            return;
        }
        foreach (self::required_rights(get_queried_object()) as $cap) {
            if (!current_user_can($cap)) {
                nocache_headers();
                wp_die(
                    '<p>' . esc_html__('You do not have the necessary rights to view this page.', 'creation-reservoir') . '</p>',
                    esc_html__('Restricted access', 'creation-reservoir'),
                    ['response' => 403, 'back_link' => true]
                );
            }
        }
    }

    public static function is_protected_page($post) {
        if (!$post || $post->post_type !== 'page') {
            return false;
        }
        $public_shortcodes = (array) apply_filters('ispag_public_shortcodes', ['ispag_plan_viewer']);
        $public_templates  = (array) apply_filters('ispag_public_templates', ['page-formulaire-cuve.php', 'page-ispag-fiche-technique.php']);

        $template = (string) get_page_template_slug($post);
        if ($template !== '') {
            if (in_array($template, $public_templates, true)) {
                return false;
            }
            if (preg_match('/^(page-.+|ispag-.+)\.php$/', $template)) {
                return true;
            }
        }
        if (preg_match_all('/\[(ispag_[a-z0-9_]+)/i', (string) $post->post_content, $m)) {
            foreach ($m[1] as $tag) {
                if (!in_array(strtolower($tag), $public_shortcodes, true)) {
                    return true;
                }
            }
        }
        return false;
    }

    public static function maybe_redirect_to_login() {
        if (is_user_logged_in() || is_admin() || wp_doing_ajax() || (defined('REST_REQUEST') && REST_REQUEST)) {
            return;
        }
        if (!is_page() || !self::is_protected_page(get_queried_object())) {
            return;
        }
        $scheme = is_ssl() ? 'https' : 'http';
        $host   = isset($_SERVER['HTTP_HOST']) ? wp_unslash($_SERVER['HTTP_HOST']) : wp_parse_url(home_url(), PHP_URL_HOST);
        $uri    = isset($_SERVER['REQUEST_URI']) ? wp_unslash($_SERVER['REQUEST_URI']) : '/';
        $target = esc_url_raw($scheme . '://' . $host . $uri);

        nocache_headers();
        wp_safe_redirect(wp_login_url($target));
        exit;
    }
}
