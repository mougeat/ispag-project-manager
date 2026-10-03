<?php
/**
 * Pages nécessaires — ISPAG Project Manager
 * Générées d'après l'export des pages de production (2026-09-29). Voir ISPAG_Page_Installer.
 */
defined('ABSPATH') || exit;

return [
    ['key' => 'home', 'slug' => 'accueil', 'title' => 'Accueil', 'content' => '[ispag_home]', 'group' => 'home'],
    ['key' => 'home_de', 'slug' => 'willkommen', 'title' => 'Willkommen', 'content' => '[ispag_home]', 'lang' => 'de', 'group' => 'home'],
    ['key' => 'projects_list', 'slug' => 'liste-des-projets-new', 'title' => 'Project list', 'content' => '[ispag_projets actif="1" qotation="0"]', 'group' => 'projects_list'],
    ['key' => 'projects_list_de', 'slug' => 'projektliste', 'title' => 'Projektliste', 'content' => '[ispag_projets actif="1" qotation="0"]', 'lang' => 'de', 'group' => 'projects_list'],
    ['key' => 'offers_list', 'slug' => 'liste-des-offres', 'title' => 'Offer list', 'content' => '[ispag_projets qotation="1" actif="0"]', 'group' => 'offers_list'],
    ['key' => 'offers_list_de', 'slug' => 'angebotsliste', 'title' => 'Angebotsliste', 'content' => '[ispag_projets qotation="1" actif="0"]', 'lang' => 'de', 'group' => 'offers_list'],
    ['key' => 'project_new', 'slug' => 'nouveau-projet', 'title' => 'Nouveau projet', 'content' => '[ispag_creation_projet]', 'group' => 'project_new'],
    ['key' => 'project_new_de', 'slug' => 'neues-projekt', 'title' => 'Neues Projekt', 'content' => '[ispag_creation_projet]', 'lang' => 'de', 'group' => 'project_new'],
    ['key' => 'offer_new', 'slug' => 'nouvelle-selection', 'title' => 'New selection', 'content' => '[ispag_creation_projet qotation="1"]', 'group' => 'offer_new'],
    ['key' => 'offer_new_de', 'slug' => 'neue-auswahl', 'title' => 'Neue Auswahl', 'content' => '[ispag_creation_projet qotation="1"]', 'lang' => 'de', 'group' => 'offer_new'],
    ['key' => 'project_detail', 'slug' => 'details-du-projet', 'title' => 'Project details', 'content' => '[ispag_detail]', 'template' => 'page-project-detail-viewer.php', 'group' => 'project_detail'],
    ['key' => 'project_detail_de', 'slug' => 'projektdetails', 'title' => 'Projektdetails', 'content' => '[ispag_detail]', 'template' => 'page-project-detail-viewer.php', 'lang' => 'de', 'group' => 'project_detail'],
    ['key' => 'deliveries', 'slug' => 'planning-des-livraisons', 'title' => 'Delivery schedule', 'content' => '[ispag_calendar_livraisons]', 'group' => 'deliveries'],
    ['key' => 'deliveries_de', 'slug' => 'lieferungs', 'title' => 'Lieferungs', 'content' => '[ispag_calendar_livraisons]', 'lang' => 'de', 'group' => 'deliveries'],
    ['key' => 'standard_articles', 'slug' => 'articles-standard', 'title' => 'Standard articles', 'content' => '[ispag_standard_articles]', 'group' => 'standard_articles'],
    ['key' => 'standard_article', 'slug' => 'article-standard', 'title' => 'Standard article', 'content' => '[ispag_standard_article]', 'group' => 'standard_article'],
];
