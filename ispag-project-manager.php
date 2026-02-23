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

require_once plugin_dir_path(__FILE__) . 'classes/class-ispag-plugin-updater.php';
new ISPAG_Plugin_Updater(
    'ispag-project-manager',
    'ispag-project-manager/ispag-project-manager.php',
    'https://raw.githubusercontent.com/mougeat/creation-reservoir/main/update.json'
);

add_action('admin_init', function() {
    delete_site_transient('update_plugins');
});


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

register_activation_hook(__FILE__, ['ISPAG_Installer', 'install']);




//Fichier dummy pour traduire les textes de la base de donnée
require_once plugin_dir_path(__FILE__) . 'classes/helpers/ispag-translations-support.php';

 
add_action('plugins_loaded', 'ispag_load_textdomain');
new ISPAG_Projet_Creation();
new ISPAG_Admin();
add_action('init', function () {
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


    


});

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


add_filter('login_redirect', 'custom_login_redirect', 10, 3);

function custom_login_redirect($redirect_to, $request, $user) {
    // Vérifie que l'utilisateur est bien connecté
    if (is_wp_error($user)) return $redirect_to;

    // Redirige tout le monde vers une page donnée, par exemple /mon-espace
    return home_url('/liste-des-projets-new');
}