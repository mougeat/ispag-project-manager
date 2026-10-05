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
// Mise à jour depuis une branche GitHub (jeton + branche : wp-config.php, ou Outils → Updates ISPAG ; « main » par défaut)
ISPAG_GitHub_Updater::plugin(__FILE__, 'mougeat/ispag-project-manager');


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

// Réglages (entreprise, centre de coût, états initiaux, coefficients de vente…) : page « ISPAG Settings »
register_activation_hook(__FILE__, ['ISPAG_Settings', 'on_activation']);
ISPAG_Settings::init();
ISPAG_Pricing_Files::init();
ISPAG_Home_Page::init();

// Édition des tables de référence (menu ISPAG Settings → Reference tables)
ISPAG_Reference_Tables::init();

// Prévient le chef de projet quand une autre personne modifie un article de son projet
ISPAG_Change_Notifier::init();

// Droits et rôles ISPAG (page « ISPAG Rights »)
ISPAG_Capabilities::init();

// Pages réservées : visiteur non connecté -> connexion, puis retour sur la page demandée
ISPAG_Access_Guard::init();

// Pages nécessaires (créées à l'activation, via Outils → Pages ISPAG, ou une fois après une mise à jour qui en ajoute)
require_once plugin_dir_path(__FILE__) . 'classes/class-ispag-page-installer.php';
ISPAG_Page_Installer::register('ISPAG Project Manager', require plugin_dir_path(__FILE__) . 'install/pages.php');
register_activation_hook(__FILE__, function () { ISPAG_Page_Installer::on_activation('ISPAG Project Manager'); });
// Incrémenter le numéro quand install/pages.php reçoit de nouvelles pages (1 = liste + fiche des articles standard)
add_action('init', function () { if (method_exists('ISPAG_Page_Installer', 'ensure_created')) { ISPAG_Page_Installer::ensure_created('ISPAG Project Manager', '3'); } }, 20);




//Fichier dummy pour traduire les textes de la base de donnée
require_once plugin_dir_path(__FILE__) . 'classes/helpers/ispag-translations-support.php';

new ISPAG_URL_Rewrite();


new ISPAG_Projet_Creation();

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
    ISPAG_Telegram_Notifier::init();
    ISPAG_Ajax_Handler::init();
    new ISPAG_Document_Manager();
    new ISPAG_Document_Analyser();
    ISPAG_Mail_Sender::init();
    ISPAG_Purchase_Request_Generator::init();
    ISPAG_Article_Pricing::init();
    ISPAG_Project_status_btn::init();
    ISPAG_Invoice_Settings::init();
    ISPAG_Project_Mail_Draft::init();
    ISPAG_Delivery_Receipt::init();
    ISPAG_Mobile_App::init();
    ISPAG_Guided_Tour::init();
    ISPAG_Sync_Invite::init();
    ISPAG_Plan_Reminders::init();
    ISPAG_Article_Repository::ini();
    ISPAG_Notes_Manager::init();
    ISPAG_Calendar_Livraisons::init();
    ISPAG_Gemini::init();
    ISPAG_Mistral::init();
    ISPAG_Purchase_Price_Import_Admin::init();
    ISPAG_Sales_Price_Import_Admin::init();


    new ISPAG_Baikal_Calendar_Sync();
    ISPAG_Standard_Articles_Pages::init(); // liste + fiche des articles standard (remplace l'ancienne modale)
    

    ISPAG_Cleanup_Old_Projects_Cron::init();
    ISPAG_Cleanup_Orphans_Cron::init();

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
 * Les traductions FR / DE sont actives. Pour les désactiver (tout en anglais) : add_filter('ispag_disable_translations', '__return_true');
 * Le filtre bloque aussi le chargement de fichiers .mo posés ailleurs (wp-content/languages/plugins/…) pour ces domaines.
 */
add_filter('override_load_textdomain', function ($override, $domain) {
    if (in_array($domain, ['creation-reservoir', 'ispag-crm', 'ispag'], true) && apply_filters('ispag_disable_translations', false)) {
        return true;
    }
    return $override;
}, 10, 2);

/**
 * Traductions FR / DE (fichiers dans languages/ : <domaine>-fr_FR.mo, <domaine>-de_DE.mo ; générés par tools/i18n/build.py d'ISPAG Project Manager).
 * Toute variante de langue du site est couverte : fr_CH, fr_BE… utilisent le français ; de_CH, de_DE_formal, de_AT… l'allemand.
 * Les textes de base sont en anglais : sans fichier pour la langue du site, l'anglais est affiché.
 * Le chargement est refait quand la langue change (Polylang la fixe après le chargement des plugins, switch_to_locale…).
 */
if (!function_exists('ispag_i18n_register_dir')) {
    /** Dossiers languages/ enregistrés par les plugins et le thème ISPAG. */
    function ispag_i18n_dirs($add = null) {
        static $dirs = [];
        if ($add !== null && !in_array($add, $dirs, true)) $dirs[] = $add;
        return $dirs;
    }
    function ispag_i18n_register_dir($dir) {
        ispag_i18n_dirs($dir);
        if (!did_action('ispag_i18n_hooked')) {
            do_action('ispag_i18n_hooked');
            add_action('plugins_loaded', 'ispag_i18n_reload', 20);
            add_action('after_setup_theme', 'ispag_i18n_reload', 20);
            add_action('init', 'ispag_i18n_reload', 1);
            add_action('pll_language_defined', 'ispag_i18n_reload', 1);
            add_action('change_locale', 'ispag_i18n_reload', 20);
            add_action('restore_previous_locale', 'ispag_i18n_reload', 20);
        }
    }
    /** (Re)charge les traductions pour la langue courante, uniquement si elle a changé depuis le dernier chargement. */
    function ispag_i18n_reload() {
        static $done = null;
        $locale   = determine_locale();
        $fallback = ['fr' => 'fr_FR', 'de' => 'de_DE'][substr($locale, 0, 2)] ?? '';
        $signature = $locale . '|' . implode(',', ispag_i18n_dirs()); // langue + dossiers connus (le thème s'enregistre après les plugins)
        if ($done === $signature) return;
        if ($done !== null) {
            foreach (ispag_i18n_dirs() as $dir) {
                foreach ((array) glob(rtrim($dir, '/\\') . '/*-{fr_FR,de_DE}.mo', GLOB_BRACE) as $mo) {
                    unload_textdomain(preg_replace('/-(fr_FR|de_DE)\.mo$/', '', basename($mo)), true);
                }
            }
        }
        $done = $signature;
        if ($fallback === '') return;
        foreach (ispag_i18n_dirs() as $dir) {
            foreach ((array) glob(rtrim($dir, '/\\') . '/*-' . $fallback . '.mo') as $mo) {
                $domain = basename($mo, '-' . $fallback . '.mo');
                $exact  = rtrim($dir, '/\\') . '/' . $domain . '-' . $locale . '.mo';
                load_textdomain($domain, is_readable($exact) ? $exact : $mo);
            }
        }
    }
}

/**
 * switch_to_locale() ignore toute langue dont le paquet de langue WordPress n'est pas installé : sur un site en anglais, le passage
 * en français / allemand (e-mail ou PDF au fournisseur, descriptifs de cuves…) échouait silencieusement et le texte restait dans la langue du site.
 * Nos traductions viennent de nos propres fichiers .mo : on déclare ces langues comme disponibles, uniquement le temps que
 * WordPress prépare son sélecteur de langue (le filtre est retiré ensuite : les listes de langues de l'administration ne changent pas).
 */
if (!function_exists('ispag_i18n_available_languages')) {
    function ispag_i18n_available_languages($languages) {
        return array_values(array_unique(array_merge((array) $languages, ['fr_FR', 'de_DE', 'it_IT'])));
    }
    add_filter('get_available_languages', 'ispag_i18n_available_languages');
    add_action('after_setup_theme', function () {
        remove_filter('get_available_languages', 'ispag_i18n_available_languages');
    }, 0);
}

ispag_i18n_register_dir(__DIR__ . '/languages');
require_once __DIR__ . '/includes/js-strings.php';

function ispag_load_textdomain() {
    ispag_i18n_reload();
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

// Confort tablette / mobile : feuille de style et script chargés sur toutes les pages du site (aucun effet sur PC)
add_action('wp_enqueue_scripts', function () {
    $css = __DIR__ . '/assets/css/ispag-responsive.css';
    $js  = __DIR__ . '/assets/js/ispag-responsive.js';
    wp_enqueue_style('ispag-responsive', plugin_dir_url(__FILE__) . 'assets/css/ispag-responsive.css', [], @filemtime($css) ?: '1.0');
    wp_enqueue_script('ispag-responsive', plugin_dir_url(__FILE__) . 'assets/js/ispag-responsive.js', [], @filemtime($js) ?: '1.0', true);
}, 99);
