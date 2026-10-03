<?php
defined('ABSPATH') or die('No script kiddies please!');

class ISPAG_Security {

    private static $roles_with_2fa = [
        'administrator',
        'vente_ispag',
        'membre_ispag',
        'ispag_commercial'
    ];

    // Liste des shortcodes nécessitant une authentification préalable
    private static $protected_shortcodes = [
        'ispag_detail',
        'ispag_achats',
        'ispag_achat_detail',
        'ispag_company_detail',
        'ispag_contact_list',
        'ispag_articles_table',
        'ispag_calendar_livraisons',
    ];

    public static function init() {
        // Gestion du 2FA
        add_filter('two_factor_enabled_providers', [__CLASS__, 'manage_two_factor_by_role'], 10, 2);
        add_filter('two_factor_force_providers_for_user', [__CLASS__, 'force_2fa_requirement'], 10, 2);
        
        // Redirection après connexion
        add_filter('login_redirect', [__CLASS__, 'custom_login_redirect'], 99, 3);

        // Protection des pages contenant les shortcodes sensibles avant tout rendu HTML
        add_action('template_redirect', [__CLASS__, 'check_protected_shortcodes']);
    }

    /**
     * Intercepte la requête avant le rendu de la page si un shortcode protégé est présent.
     */
    public static function check_protected_shortcodes() {
        if (!is_singular()) {
            return;
        }

        global $post;
        if (!is_a($post, 'WP_Post')) {
            return;
        }

        // Si l'utilisateur est déjà connecté, pas besoin de rediriger
        if (is_user_logged_in()) {
            return;
        }

        // Vérification si au moins un des shortcodes protégés est dans le contenu
        $has_protected_shortcode = false;
        foreach (self::$protected_shortcodes as $shortcode) {
            if (has_shortcode($post->post_content, $shortcode)) {
                $has_protected_shortcode = true;
                break;
            }
        }

        if ($has_protected_shortcode) {
            global $wp;
            $current_url = home_url(add_query_arg($_GET, $wp->request));
            
            wp_safe_redirect(wp_login_url($current_url));
            exit;
        }
    }

    public static function manage_two_factor_by_role($providers, $user_id) {
        $user = get_userdata($user_id);
        if (!$user || empty($user->roles)) {
            return $providers;
        }

        $needs_2fa = !empty(array_intersect(self::$roles_with_2fa, $user->roles));

        if (!$needs_2fa) {
            return [];
        }

        return $providers;
    }

    public static function force_2fa_requirement($forced_providers, $user_id) {
        $user = get_userdata($user_id);
        if (!$user || empty($user->roles)) {
            return $forced_providers;
        }

        if (!empty(array_intersect(self::$roles_with_2fa, $user->roles))) {
            return [
                'Two_Factor_Email',
                'Two_Factor_Totp'
            ];
        }

        return [];
    }

    public static function custom_login_redirect($redirect_to, $request, $user) {
        if (!is_a($user, 'WP_User') || empty($user->roles)) {
            return $redirect_to;
        }

        // Si l'utilisateur a besoin du 2FA mais qu'il est en cours de validation par le plugin Two-Factor
        $needs_2fa = !empty(array_intersect(self::$roles_with_2fa, $user->roles));
        if ($needs_2fa && class_exists('Two_Factor_Core')) {
            $enabled_providers = Two_Factor_Core::get_enabled_providers_for_user($user->ID);
            if (!empty($enabled_providers) && !isset($_GET['action']) && !did_action('two_factor_user_authentication')) {
                return $redirect_to;
            }
        }

        // --- PRIORITÉ À L'URL DE DESTINATION MANUELLE ---
        // Si $request (l'URL demandée via le paramètre HTTP 'redirect_to') est défini
        // et n'est pas la page de profil/admin par défaut de WordPress, on l'utilise en priorité.
        if (!empty($request) && strpos($request, 'wp-admin') === false && $request !== admin_url('profile.php')) {
            return $request;
        }

        // Si le paramètre $redirect_to natif contient déjà une destination spécifique (ex: transmise par un hook tiers)
        if (!empty($redirect_to) && strpos($redirect_to, 'wp-admin') === false && $redirect_to !== admin_url('profile.php')) {
            return $redirect_to;
        }

        // --- REDIRECTION PAR DÉFAUT SI AUCUNE PAGE CIBLE N'ÉTAIT DEMANDÉE ---
        // Table de correspondance [langue][rôle] => url
        $redirect_mapping = [
            'fr' => [
                'administrator'    => '/liste-des-projets-new',
                'vente_ispag'      => '/liste-des-projets-new',
                'membre_ispag'     => '/liste-des-projets-new',
                'ispag_commercial' => '/liste-des-projets-new',
                'customer'         => '/liste-des-projets-new',
                'client'           => '/liste-des-projets-new',
                'ingenieur'        => '/liste-des-offres',
                'chiffreur'        => '/liste-des-offres',
            ],
            'de' => [
                'administrator'    => '/projektliste',
                'vente_ispag'      => '/projektliste',
                'membre_ispag'     => '/projektliste',
                'ispag_commercial' => '/projektliste',
                'customer'         => '/projektliste',
                'client'           => '/projektliste',
                'ingenieur'        => '/angebotsliste',
                'chiffreur'        => '/angebotsliste',
            ],
        ];

        $locale = get_user_locale($user->ID);
        $lang = (strpos($locale, 'de') === 0) ? 'de' : 'fr';

        foreach ($redirect_mapping[$lang] as $role_key => $url) {
            if (in_array($role_key, $user->roles, true)) {
                if ($role_key === 'administrator' && !empty($request) && strpos($request, 'wp-admin') !== false) {
                    return $redirect_to;
                }
                return home_url($url);
            }
        }

        return $redirect_to;
    }
}