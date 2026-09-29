<?php
/*
Plugin Name: ISPAG, project manager
Description: Gestion des projets et offres pour ISPAG. Liste l'ensemble des projets / offres en cours
Plugin URI: https://github.com/mougeat/creation-reservoir
Version: 1.0.3
Author: Cyril Barthel
Author URI: https://cyrilbarthel.com/
Text Domain: creation-reservoir
Domain Path: /languages
*/

defined('ABSPATH') or die('No script kiddies please!');

// Dossier du plugin : les autres plugins ISPAG (tank-builder, achats) s'en servent pour trouver FPDF/FPDI/pdfparser
if (!defined('ISPAG_PROJECT_MANAGER_DIR')) {
    define('ISPAG_PROJECT_MANAGER_DIR', plugin_dir_path(__FILE__));
}
// Chemin d'ISPAG Project Manager, quel que soit le nom de son dossier (un ZIP GitHub donne « ispag-project-manager-<branche> »)
if (!function_exists('ispag_project_manager_dir')) {
    function ispag_project_manager_dir() {
        return defined('ISPAG_PROJECT_MANAGER_DIR') ? ISPAG_PROJECT_MANAGER_DIR : WP_PLUGIN_DIR . '/ispag-project-manager/';
    }
}

require_once plugin_dir_path(__FILE__) . 'classes/class-ispag-github-updater.php';
if (ISPAG_GitHub_Updater::is_configured()) {
    // Site de test : suit la branche GitHub définie dans wp-config.php (ISPAG_GITHUB_TOKEN / ISPAG_UPDATE_BRANCH)
    ISPAG_GitHub_Updater::plugin(__FILE__, 'mougeat/ispag-project-manager');
} else {
    // Comportement historique (production)
    require_once plugin_dir_path(__FILE__) . 'classes/class-ispag-plugin-updater.php';
    new ISPAG_Plugin_Updater(
        'ispag-project-manager',
        'ispag-project-manager/ispag-project-manager.php',
        'https://raw.githubusercontent.com/mougeat/creation-reservoir/main/update.json'
    );

    add_action('admin_init', function() {
        delete_site_transient('update_plugins');
    });
}


spl_autoload_register(function ($class) {
    $prefix = 'ISPAG_';
    $base_dir = __DIR__ . '/classes/';
    if (strpos($class, $prefix) === 0) {
        $class_name = strtolower(str_replace('_', '-', $class));
        $file = $base_dir . 'class-' . $class_name . '.php';
        if (file_exists($file)) {
            require $file;
        }
    }
});

// Schéma de base de données : créé à l'activation, et re-vérifié à chaque chargement si la version change
register_activation_hook(__FILE__, ['ISPAG_Installer', 'install']);
ISPAG_Installer::init();

// Pages nécessaires (créées à l'activation ou via Outils → Pages ISPAG ; jamais automatiquement)
require_once plugin_dir_path(__FILE__) . 'classes/class-ispag-page-installer.php';
ISPAG_Page_Installer::register('ISPAG Project Manager', require plugin_dir_path(__FILE__) . 'install/pages.php');
register_activation_hook(__FILE__, function () { ISPAG_Page_Installer::on_activation('ISPAG Project Manager'); });




//Fichier dummy pour traduire les textes de la base de donnée
require_once plugin_dir_path(__FILE__) . 'classes/helpers/ispag-translations-support.php';

new ISPAG_URL_Rewrite();


new ISPAG_Projet_Creation();
new ISPAG_Admin();

// add_action('plugins_loaded', function () {
//     if (class_exists('ISPAG_Project_Phase_Display')) {
//         ISPAG_Project_Phase_Display::init();
//     }
// });

add_action('init', function () {
    ISPAG_Security::init();
    new ISPAG_Project_Manager();
    ISPAG_Project_Manager::init();
    ISPAG_Replicate_Project::init();
    ISPAG_Projet_Repository::init();
    ISPAG_Projets_status_checker::init();
    // ISPAG_Achat_Manager::init();
    ISPAG_Detail_Page::init();
    ISPAG_Project_Details_Repository::init();
    ISPAG_Telegram_Admin::init();
    ISPAG_Telegram_Notifier::init();
    ISPAG_Ajax_Handler::init();
    new ISPAG_Document_Manager();
    new ISPAG_Document_Analyser();
    ISPAG_Mail_Sender::init();
    ISPAG_Purchase_Request_Generator::init();
    ISPAG_Article_Pricing::init();
    ISPAG_Project_status_btn::init();
    ISPAG_Article_Repository::ini();
    ISPAG_Notes_Manager::init();
    ISPAG_Calendar_Livraisons::init();
    ISPAG_Gemini::init();
    ISPAG_Mistral::init();
    ISPAG_Purchase_Price_Import_Admin::init();
    ISPAG_Sales_Price_Import_Admin::init();


    new ISPAG_Baikal_Calendar_Sync();
    new ISPAG_Achats_Articles_Manager();
    

    ISPAG_Cleanup_Old_Projects_Cron::init();

    ISPAG_Project_Phase_Automation::init();
    ISPAG_Project_Phase_Display::init();


    

    // new ISPAG_Document_Manager_Core();
    // new ISPAG_Document_Manager_Secondary();

    
    


});

// On utilise cet action pour lancer la synchro manuelle 
// uniquement quand on en a besoin (ex: activation ou via un bouton)
add_action('admin_head', function() {
    if (isset($_GET['baikal_ispag_calendar_sync'])) {
        if (class_exists('ISPAG_Baikal_Calendar_Sync')) {
            (new ISPAG_Baikal_Calendar_Sync())->sync_all_deliveries_now();
            echo '<div class="notice notice-success"><p>Baïkal sync completed (see logs).</p></div>';
        }
    }
});

add_action('init', 'ispag_load_textdomain');

/**
 * Traductions désactivées pour l'instant : tous les textes de base sont en anglais.
 * Empêche aussi le chargement de fichiers .mo posés ailleurs (wp-content/languages/plugins/…).
 * Pour réactiver plus tard : add_filter('ispag_disable_translations', '__return_false');
 */
add_filter('override_load_textdomain', function ($override, $domain) {
    if (in_array($domain, ['creation-reservoir', 'ispag-crm', 'ispag'], true) && apply_filters('ispag_disable_translations', true)) {
        return true;
    }
    return $override;
}, 10, 2);

function ispag_load_textdomain() {
    load_plugin_textdomain('creation-reservoir', false, dirname(plugin_basename(__FILE__)) . '/languages/');
}

function ispag_load_env($path) {
    if (!file_exists($path)) return;

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;

        list($name, $value) = explode('=', $line, 2);
        $name = trim($name);
        $value = trim($value);

        if (!getenv($name)) {
            putenv("$name=$value");
            $_ENV[$name] = $value;
        }
    }
}

// Charge au démarrage du plugin
ispag_load_env(plugin_dir_path(__FILE__) . '.env');


// add_filter('login_redirect', 'custom_login_redirect', 10, 3);

// function custom_login_redirect($redirect_to, $request, $user) {
//     // Vérifie que l'utilisateur est bien connecté et possède des rôles
//     if (!is_a($user, 'WP_User') || empty($user->roles)) {
//         return $redirect_to;
//     }

//     // Table de correspondance [langue][rôle] => url
//     $redirect_mapping = [
//         'fr' => [
//             'administrator'     => '/liste-des-projets-new',
//             'vente_ispag'       => '/liste-des-projets-new',
//             'membre_ispag'      => '/liste-des-projets-new',
//             'ispag_commercial'  => '/liste-des-projets-new',
//             'customer'          => '/liste-des-projets-new',
//             'client'            => '/liste-des-projets-new',
//             'ingenieur'         => '/liste-des-offres',
//             'chiffreur'         => '/liste-des-offres',
//         ],
//         'de' => [
//             'administrator'     => '/projektliste',
//             'vente_ispag'       => '/projektliste',
//             'membre_ispag'      => '/projektliste',
//             'ispag_commercial'  => '/projektliste',
//             'customer'          => '/projektliste',
//             'client'            => '/projektliste',
//             'ingenieur'         => '/angebotsliste',
//             'chiffreur'         => '/angebotsliste',
//         ],
//     ];

//     // Détermine la langue de l'utilisateur
//     $locale = get_user_locale($user->ID);
//     $lang = (strpos($locale, 'de') === 0) ? 'de' : 'fr';

//     // Parcours de notre mapping par ordre de priorité
//     foreach ($redirect_mapping[$lang] as $role_key => $url) {
//         if (in_array($role_key, $user->roles, true)) {
//             // Optionnel : Si un admin souhaite explicitement aller sur le wp-admin via l'URL de connexion
//             if ($role_key === 'administrator' && !empty($request) && strpos($request, 'wp-admin') !== false) {
//                 return $redirect_to;
//             }
//             return home_url($url);
//         }
//     }

//     return $redirect_to;
// }



// register_activation_hook(__FILE__, function() {
//     $rewrite = new ISPAG_URL_Rewrite();
//     $rewrite->add_rewrite_rules();
//     flush_rewrite_rules();
// });




// add_action( 'init', function() {
//     add_rewrite_rule(
//         '^project-detail/([0-9]+)/?$',
//         'index.php?pagename=details-du-projet&deal_id=$matches[1]',
//         'top'
//     );
// } );

// add_filter( 'query_vars', function( $vars ) {
//     $vars[] = 'deal_id';
//     return $vars;
// } );

// À exécuter une seule fois (dans functions.php ou un plugin personnalisé)
// function ajouter_capability_utilisateur_specifique() {
//     $user = get_user_by('id', 6048);
//     if ($user && ! $user->has_cap('navigate_new_project_details_presentation')) {
//         $user->add_cap('navigate_new_project_details_presentation');
//     }
// }
// add_action('admin_init', 'ajouter_capability_utilisateur_specifique');


// add_action( 'template_redirect', 'ispag_check_user_auth' );

// function ispag_check_user_auth() {
//     // 1. S'assurer qu'on est sur une page/article singulier
//     if ( is_singular() ) {
//         global $post;

//         // 2. Vérifier si le contenu de la page contient ton shortcode
//         // Remplace 'ispag_detail_projet' par le nom exact de ton shortcode
//         if ( is_a( $post, 'WP_Post' ) && has_shortcode( $post->post_content, 'ispag_detail' ) ) {
            
//             // 3. Si l'utilisateur n'est pas connecté, redirection propre avant tout HTML
//             if ( ! is_user_logged_in() ) {
//                 global $wp;
//                 $current_url = home_url( add_query_arg( $_GET, $wp->request ) );
                
//                 wp_safe_redirect( wp_login_url( $current_url ) );
//                 exit;
//             }
//         }
//     }
// }