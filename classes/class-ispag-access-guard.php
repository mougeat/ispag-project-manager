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
