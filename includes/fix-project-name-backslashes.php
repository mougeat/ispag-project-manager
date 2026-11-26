<?php
defined('ABSPATH') || exit;

/**
 * Nettoyage unique des antislashs dans les noms de projet (« Fleur d\'eau » → « Fleur d'eau »).
 *
 * Cause : WordPress ajoute un antislash devant les apostrophes de $_POST ; le nom était enregistré sans wp_unslash(),
 * et chaque nouvel enregistrement doublait les antislashs déjà présents (d\'eau → d\\\'eau). Un seul stripslashes()
 * à l'affichage n'en retire donc qu'un niveau. L'enregistrement est corrigé ; ce passage nettoie les noms déjà en base.
 *
 * - une seule fois (option), au premier chargement de l'administration par un administrateur ;
 * - seulement la colonne ObjetCommande de la table des projets ;
 * - l'ancien nom de chaque projet modifié est conservé dans le journal ISPAG (action FIX_BACKSLASH) pour pouvoir le retrouver.
 */
add_action('admin_init', function () {
    if (get_option('ispag_pm_backslash_names_fixed') || !current_user_can('manage_options')) {
        return;
    }

    global $wpdb;
    $table = $wpdb->prefix . 'achats_liste_commande';
    $rows  = $wpdb->get_results("SELECT hubspot_deal_id, ObjetCommande FROM {$table} WHERE INSTR(ObjetCommande, CHAR(92)) > 0");
    if ($rows === null || $wpdb->last_error) {
        return;   // table absente ou erreur : on réessaiera au prochain chargement
    }

    $logger = class_exists('ISPAG_Logger') ? ISPAG_Logger::get_instance() : null;
    $failed = 0;
    foreach ($rows as $row) {
        $clean = str_replace('\\', '', (string) $row->ObjetCommande);
        $ok    = $wpdb->update($table, ['ObjetCommande' => $clean], ['hubspot_deal_id' => (int) $row->hubspot_deal_id], ['%s'], ['%d']);
        if ($ok === false) { $failed++; continue; }
        if ($logger) {
            $logger->log_db_change('migration', $table, 'FIX_BACKSLASH', ['deal_id' => (int) $row->hubspot_deal_id, 'old' => $row->ObjetCommande, 'new' => $clean], get_current_user_id());
        }
    }

    if ($failed === 0) {
        update_option('ispag_pm_backslash_names_fixed', current_time('mysql'), false);
    }
});
