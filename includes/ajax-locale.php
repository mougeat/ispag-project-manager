<?php
defined('ABSPATH') || exit;

/**
 * Langue des requêtes AJAX (admin-ajax.php).
 *
 * Une page affichée en allemand (/de/…, Polylang) charge une partie de son contenu par AJAX (tableau de bord du projet,
 * articles, fenêtres modales…). admin-ajax.php ne passe pas par la détection de langue de la page : sans indication,
 * WordPress répond dans la langue du site (français) et ces blocs restent en français au milieu d'une page allemande.
 *
 * La langue de la page d'origine est donc reprise pendant la requête AJAX, dans cet ordre :
 *   1. en-tête X-ISPAG-Lang ou paramètre ispag_lang (envoyés par le JavaScript, qui lit <html lang>) ;
 *   2. à défaut, l'adresse de la page d'origine (Referer) : préfixe /de/ ou /it/, paramètre lang=, pages « projekt-… ».
 * Seules les langues fr / de / it sont acceptées. Hors AJAX, rien ne change.
 * Si une requête change volontairement de langue (switch_to_locale : e-mail ou PDF fournisseur…), cette langue est respectée.
 */
if (!function_exists('ispag_ajax_lang_hint')) {
    /** Langue (fr|de|it) de la page qui a lancé la requête AJAX, ou '' si elle ne peut pas être déterminée. */
    function ispag_ajax_lang_hint() {
        $allowed = ['fr', 'de', 'it'];
        $raw = '';
        if (!empty($_SERVER['HTTP_X_ISPAG_LANG'])) {
            $raw = (string) $_SERVER['HTTP_X_ISPAG_LANG'];
        } elseif (!empty($_REQUEST['ispag_lang'])) {
            $raw = (string) $_REQUEST['ispag_lang'];
        }
        $raw = strtolower(substr(preg_replace('/[^A-Za-z_\-]/', '', wp_unslash($raw)), 0, 2));
        if (in_array($raw, $allowed, true)) {
            return $raw;
        }

        $referer = isset($_SERVER['HTTP_REFERER']) ? (string) wp_unslash($_SERVER['HTTP_REFERER']) : '';
        if ($referer === '') {
            return '';
        }
        $parts = wp_parse_url($referer);
        $path  = isset($parts['path']) ? strtolower((string) $parts['path']) : '';
        if (preg_match('#^/(fr|de|it)(/|$)#', $path, $m)) {
            return $m[1];
        }
        if (!empty($parts['query'])) {
            parse_str($parts['query'], $q);
            $l = isset($q['lang']) ? strtolower(substr((string) $q['lang'], 0, 2)) : '';
            if (in_array($l, $allowed, true)) {
                return $l;
            }
        }
        if (preg_match('#/projekt-?detail#', $path)) {
            return 'de';
        }
        return '';
    }

    add_filter('pre_determine_locale', function ($pre) {
        if (!empty($pre) || !function_exists('wp_doing_ajax') || !wp_doing_ajax()) {
            return $pre;
        }
        // Le gestionnaire de langue de WordPress n'existe pas encore pendant plugins_loaded : l'appeler provoquerait une erreur fatale sur toute requête AJAX
        if (!empty($GLOBALS['wp_locale_switcher']) && function_exists('is_locale_switched') && is_locale_switched()) {
            return $pre; // langue imposée par le code (switch_to_locale) : on ne la remplace pas
        }
        $map  = ['fr' => 'fr_FR', 'de' => 'de_DE', 'it' => 'it_IT'];
        $lang = ispag_ajax_lang_hint();
        return $lang !== '' ? $map[$lang] : $pre;
    });

    /** JavaScript : joint la langue de la page (<html lang>) à chaque requête jQuery vers admin-ajax.php (même domaine uniquement). */
    function ispag_ajax_lang_script() {
        return "(function(){var $=window.jQuery;if(!$||!$.ajaxPrefilter)return;"
            . "var l=((document.documentElement.getAttribute('lang')||'').slice(0,2)).toLowerCase();"
            . "if(['fr','de','it'].indexOf(l)<0)return;"
            . "$.ajaxPrefilter(function(o,orig,xhr){"
            . "if(typeof o.url==='string'&&o.url.indexOf('admin-ajax.php')>-1&&!o.crossDomain){xhr.setRequestHeader('X-ISPAG-Lang',l);}"
            . "});})();";
    }
}
