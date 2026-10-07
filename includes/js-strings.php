<?php
defined('ABSPATH') || exit;

/**
 * Textes affichés par le JavaScript de ce paquet (messages d'erreur, confirmations, libellés).
 * Le JavaScript les appelle avec ispagT('Texte anglais') ; ce fichier fournit leur traduction dans la langue du site
 * (fichiers languages/ de ce paquet). Un texte absent de la liste reste tel quel (anglais).
 * Après avoir ajouté un texte dans un fichier JS, ajoutez-le ici puis lancez tools/i18n/build.py (ISPAG Project Manager).
 */
if (!function_exists('ispag_pm_js_strings')) {
    function ispag_pm_js_strings() {
        return [
        'A connection error occurred.' => __('A connection error occurred.', 'creation-reservoir'),
        'A network error occurred.' => __('A network error occurred.', 'creation-reservoir'),
        'AJAX Error' => __('AJAX Error', 'creation-reservoir'),
        'AJAX connection error: ' => __('AJAX connection error: ', 'creation-reservoir'),
        'AJAX error: ' => __('AJAX error: ', 'creation-reservoir'),
        'An error occurred while loading.' => __('An error occurred while loading.', 'creation-reservoir'),
        'An error occurred: ' => __('An error occurred: ', 'creation-reservoir'),
        'Chercher un concurrent...' => __('Chercher un concurrent...', 'creation-reservoir'),
        'Chercher un contact actif...' => __('Chercher un contact actif...', 'creation-reservoir'),
        'Choose an image' => __('Choose an image', 'creation-reservoir'),
        'Données incorrectes' => __('Données incorrectes', 'creation-reservoir'),
        'Drawing analysis' => __('Drawing analysis', 'creation-reservoir'),
        'Duplication in progress...' => __('Duplication in progress...', 'creation-reservoir'),
        'Dupliquer le Projet 🔄' => __('Dupliquer le Projet 🔄', 'creation-reservoir'),
        'Error during update: ' => __('Error during update: ', 'creation-reservoir'),
        'Error lors du changement de statut' => __('Error lors du changement de statut', 'creation-reservoir'),
        'Delete the %d selected articles? Their sub-articles are deleted with them. This cannot be undone.' => __('Delete the %d selected articles? Their sub-articles are deleted with them. This cannot be undone.', 'creation-reservoir'),
        'Error while deleting' => __('Error while deleting', 'creation-reservoir'),
        'Error while loading data.' => __('Error while loading data.', 'creation-reservoir'),
        'Error while loading the form.' => __('Error while loading the form.', 'creation-reservoir'),
        'Error while loading the modal.' => __('Error while loading the modal.', 'creation-reservoir'),
        'Error while saving' => __('Error while saving', 'creation-reservoir'),
        'Error while saving the technical data' => __('Error while saving the technical data', 'creation-reservoir'),
        'Error while updating the status: ' => __('Error while updating the status: ', 'creation-reservoir'),
        'Error: ' => __('Error: ', 'creation-reservoir'),
        'Error: Invalid server response.' => __('Error: Invalid server response.', 'creation-reservoir'),
        'Error: Missing article ID.' => __('Error: Missing article ID.', 'creation-reservoir'),
        'Error: Missing project ID.' => __('Error: Missing project ID.', 'creation-reservoir'),
        'Invalid phone number' => __('Invalid phone number', 'creation-reservoir'),
        'Invalid server response.' => __('Invalid server response.', 'creation-reservoir'),
        'Loading error.' => __('Loading error.', 'creation-reservoir'),
        'Network error during update.' => __('Network error during update.', 'creation-reservoir'),
        'Network error. Please try again.' => __('Network error. Please try again.', 'creation-reservoir'),
        'Network or server error.' => __('Network or server error.', 'creation-reservoir'),
        'No article selected' => __('No article selected', 'creation-reservoir'),
        'Please select at least one parameter to update.' => __('Please select at least one parameter to update.', 'creation-reservoir'),
        'Please wait...' => __('Please wait...', 'creation-reservoir'),
        'Project duplicated ✔️' => __('Project duplicated ✔️', 'creation-reservoir'),
        'Search for an engineering office...' => __('Search for an engineering office...', 'creation-reservoir'),
        'Select' => __('Select', 'creation-reservoir'),
        'Server error' => __('Server error', 'creation-reservoir'),
        'Tank updated successfully!' => __('Tank updated successfully!', 'creation-reservoir'),
        'Taper le nom de l\'entreprise ou l\'ID...' => __('Taper le nom de l\'entreprise ou l\'ID...', 'creation-reservoir'),
        'Unknown error' => __('Unknown error', 'creation-reservoir'),
        '❌ Network error' => __('❌ Network error', 'creation-reservoir'),
        ];
    }

    /** Dictionnaire {texte anglais → texte traduit} injecté dans la page ; seuls les textes réellement traduits sont envoyés. */
    function ispag_pm_print_js_i18n() {
        $map = array_filter(ispag_pm_js_strings(), function ($translated, $english) { return $translated !== $english; }, ARRAY_FILTER_USE_BOTH);
        echo '<script>window.ISPAG_JS_I18N=Object.assign(window.ISPAG_JS_I18N||{},' . wp_json_encode($map, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) . ');'
            . 'window.ispagT=function(s){var d=window.ISPAG_JS_I18N||{};return Object.prototype.hasOwnProperty.call(d,s)?d[s]:s};</script>' . "\n";
    }
    add_action('wp_head', 'ispag_pm_print_js_i18n', 1);
    add_action('admin_head', 'ispag_pm_print_js_i18n', 1);
}
