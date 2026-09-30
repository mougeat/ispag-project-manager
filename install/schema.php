<?php
/**
 * Schéma de base de données — ISPAG Project Manager
 *
 * Généré à partir de la structure de production (export phpMyAdmin du 2026-09-29).
 * Chaque entrée est un CREATE TABLE IF NOT EXISTS : exécuté sans risque sur un site existant
 * (rien n'est modifié si la table existe déjà). {prefix} = $wpdb->prefix, {charset} = $wpdb->get_charset_collate().
 * Les tables sont classées pour que les clés étrangères pointent vers des tables déjà créées.
 */
defined('ABSPATH') || exit;

return [
    'achats_articles' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `{prefix}achats_articles` (
  `Id` int NOT NULL AUTO_INCREMENT,
  `TypeArticle` int NOT NULL,
  `ref_article_ispag` text NOT NULL,
  `CodeBarre` text NOT NULL,
  `TitreArticle` text NOT NULL,
  `description_ispag` text NOT NULL,
  `conception` json NOT NULL,
  `drawing` int NOT NULL,
  `documentation` int NOT NULL,
  `sales_price` decimal(10,2) NOT NULL,
  `delivery_time` mediumint NOT NULL,
  `Poids` decimal(10,2) NOT NULL,
  `UnitePoids` text NOT NULL,
  `image` int NOT NULL,
  PRIMARY KEY (`Id`),
  KEY `idx_id` (`Id`)
) ENGINE=InnoDB {charset}
SQL
    ,
    'achats_articles_price_history' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `{prefix}achats_articles_price_history` (
  `Id` int NOT NULL AUTO_INCREMENT,
  `article_id` int NOT NULL,
  `sales_price` decimal(10,2) NOT NULL,
  `valid_from` date NOT NULL,
  `valid_to` date DEFAULT NULL,
  `changed_by` int DEFAULT NULL,
  `note` text,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`Id`),
  KEY `idx_purchase_id` (`article_id`),
  KEY `idx_valid_range` (`article_id`,`valid_from`,`valid_to`)
) ENGINE=InnoDB {charset}
SQL
    ,
    'achats_details_commande' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `{prefix}achats_details_commande` (
  `Id` int NOT NULL AUTO_INCREMENT,
  `hubspot_deal_id` bigint NOT NULL,
  `tri` int NOT NULL,
  `IdArticleStandard` int NOT NULL,
  `IdArticleMaster` int NOT NULL,
  `Groupe` text NOT NULL,
  `Type` int NOT NULL,
  `Article` text NOT NULL,
  `Description` text NOT NULL,
  `serial_no` varchar(30) DEFAULT NULL COMMENT 'Serial number — format e.g. 99.02191017-26N',
  `linked_tank` int NOT NULL,
  `CuveRaccordee` tinyint(1) NOT NULL,
  `Qty` decimal(10,2) NOT NULL,
  `sales_price` decimal(10,2) NOT NULL,
  `discount` decimal(10,2) NOT NULL,
  `is_manual_price` tinyint(1) NOT NULL DEFAULT '0',
  `IdFournisseur` int NOT NULL DEFAULT '18',
  `Plan` tinyint(1) DEFAULT '0',
  `DrawingApproved` tinyint(1) DEFAULT '0',
  `DemandeAchatOk` tinyint(1) DEFAULT '0',
  `Livre` tinyint(1) DEFAULT '0',
  `invoiced` bigint DEFAULT NULL,
  `TimestampDateDeLivraison` int DEFAULT NULL,
  `TimestampDateDeLivraisonFin` int DEFAULT NULL,
  `customer_visible` tinyint(1) NOT NULL DEFAULT '1',
  `created_by` int NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_warning_sent` datetime DEFAULT NULL,
  `archive` tinyint(1) NOT NULL DEFAULT '0',
  PRIMARY KEY (`Id`),
  KEY `idx_id` (`Id`),
  KEY `idx_type` (`Type`),
  KEY `idx_deal_type` (`hubspot_deal_id`,`Type`)
) ENGINE=InnoDB {charset}
SQL
    ,
    'achats_doc_types' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `{prefix}achats_doc_types` (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `slug` varchar(50) NOT NULL,
  `label` varchar(100) NOT NULL,
  `ajax_action` text NOT NULL,
  `badge_class` text NOT NULL,
  `sort_order` int DEFAULT '0',
  `restricted` tinyint(1) DEFAULT '0',
  `for_article_type` tinyint(1) DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`)
) ENGINE=InnoDB {charset}
SQL
    ,
    'achats_historique' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `{prefix}achats_historique` (
  `Id` int NOT NULL AUTO_INCREMENT,
  `hubspot_deal_id` bigint NOT NULL DEFAULT '0',
  `purchase_order` int NOT NULL DEFAULT '0',
  `Date` bigint NOT NULL,
  `dateReadable` datetime NOT NULL,
  `IdUser` int NOT NULL,
  `Historique` mediumtext NOT NULL,
  `IdMedia` int NOT NULL,
  `is_task` tinyint(1) NOT NULL,
  `is_done` tinyint(1) NOT NULL,
  `ClassCss` varchar(255) NOT NULL,
  PRIMARY KEY (`Id`)
) ENGINE=InnoDB {charset}
SQL
    ,
    'achats_historique_views' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `{prefix}achats_historique_views` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` bigint UNSIGNED NOT NULL,
  `document_id` bigint UNSIGNED NOT NULL,
  `note_id` bigint UNSIGNED NOT NULL,
  `viewed_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `{prefix}achats_historique_views_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `{prefix}users` (`ID`) ON DELETE CASCADE
) ENGINE=InnoDB {charset}
SQL
    ,
    'achats_info_commande' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `{prefix}achats_info_commande` (
  `Id` int NOT NULL AUTO_INCREMENT,
  `hubspot_deal_id` bigint NOT NULL DEFAULT '0',
  `purchase_order` int NOT NULL DEFAULT '0',
  `AdresseDeLivraison` mediumtext NOT NULL,
  `DeliveryAdresse2` text NOT NULL,
  `DeliveryAdresse3` text NOT NULL,
  `NIP` text NOT NULL,
  `City` text NOT NULL,
  `Comment` text NOT NULL,
  `PersonneContact` mediumtext NOT NULL,
  `num_tel_contact` mediumtext NOT NULL,
  `ConfCommande` mediumtext,
  `unloadingFacilities` int NOT NULL,
  `corridor_width` varchar(100) DEFAULT '',
  `door_width` varchar(100) DEFAULT '',
  `number_doors` varchar(100) DEFAULT '',
  `other_obstacles` text,
  `room_size` varchar(100) DEFAULT '',
  `room_height` varchar(100) DEFAULT '',
  `ceiling_type` varchar(100) DEFAULT '',
  `hoist_allowed` varchar(100) DEFAULT '',
  `floor_covering` varchar(100) DEFAULT '',
  `ventilation` varchar(100) DEFAULT '',
  `electricity_available` varchar(100) DEFAULT '',
  `parking_address` text,
  `observations` text,
  PRIMARY KEY (`Id`)
) ENGINE=InnoDB {charset}
SQL
    ,
    'achats_liste_commande' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `{prefix}achats_liste_commande` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `hubspot_deal_id` bigint NOT NULL,
  `NumCommande` varchar(100) DEFAULT NULL,
  `customer_order_id` varchar(100) DEFAULT NULL,
  `AssociatedCompanyID` int NOT NULL DEFAULT '0',
  `AssociatedContactIDs` text NOT NULL,
  `ObjetCommande` text NOT NULL,
  `project_status` int NOT NULL,
  `isQotation` int DEFAULT NULL,
  `EnSoumission` varchar(255) NOT NULL,
  `offreRevendeur` tinyint(1) NOT NULL DEFAULT '0',
  `sales_coef` decimal(5,2) DEFAULT NULL,
  `CustomerVisible` tinyint(1) NOT NULL DEFAULT '1',
  `created_by` int NOT NULL,
  `project_manager` int DEFAULT NULL,
  `ingenieur_id` int DEFAULT NULL,
  `Ingenieur` varchar(255) DEFAULT NULL,
  `Abonne` text NOT NULL,
  `TimestampDateCommande` int NOT NULL,
  `date_creation` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `modified_at` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  `version` int NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `idx_id` (`hubspot_deal_id`)
) ENGINE=InnoDB {charset}
SQL
    ,
    'achats_meta_phase_commande' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `{prefix}achats_meta_phase_commande` (
  `Id` int NOT NULL AUTO_INCREMENT,
  `sort` int NOT NULL,
  `Nom` text NOT NULL,
  `TacheComplete` int NOT NULL,
  `Couleur` text NOT NULL,
  `ClasseCss` text NOT NULL,
  PRIMARY KEY (`Id`)
) ENGINE=InnoDB {charset}
SQL
    ,
    'achats_project_meta' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `{prefix}achats_project_meta` (
  `meta_id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `post_id` bigint UNSIGNED NOT NULL DEFAULT '0',
  `meta_key` varchar(255) DEFAULT NULL,
  `meta_value` longtext,
  PRIMARY KEY (`meta_id`),
  KEY `post_id` (`post_id`),
  KEY `meta_key` (`meta_key`(191))
) ENGINE=InnoDB {charset}
SQL
    ,
    'achats_slug_phase' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `{prefix}achats_slug_phase` (
  `Id` int NOT NULL AUTO_INCREMENT,
  `Ordre` int NOT NULL,
  `SlugPhase` varchar(100) NOT NULL,
  `type_prestation` varchar(100) NOT NULL,
  `TitrePhase` varchar(255) NOT NULL,
  `TitrePhaseFuture` varchar(255) NOT NULL,
  `TitrePhaseProgression` varchar(255) NOT NULL,
  `VisuClient` tinyint(1) NOT NULL DEFAULT '1',
  `VisuProgression` tinyint(1) NOT NULL DEFAULT '1',
  `display_on_qotation` tinyint(1) NOT NULL DEFAULT '0',
  `Color` varchar(50) NOT NULL,
  `ActionText` varchar(255) DEFAULT NULL,
  `is_automatic` tinyint(1) NOT NULL DEFAULT '0',
  `waitNextStep` tinyint(1) NOT NULL DEFAULT '0',
  `CloseProject` tinyint(1) NOT NULL DEFAULT '0',
  `Brevo_id` int NOT NULL DEFAULT '0',
  `Brevo_delay_days` int NOT NULL DEFAULT '0',
  `JsHook` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`Id`)
) ENGINE=InnoDB {charset}
SQL
    ,
    'achats_suivi_phase_commande' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `{prefix}achats_suivi_phase_commande` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `hubspot_deal_id` bigint NOT NULL,
  `purchase_id` int NOT NULL,
  `slug_phase` varchar(100) DEFAULT NULL,
  `status_id` int NOT NULL,
  `modified_by` bigint UNSIGNED DEFAULT NULL,
  `comment` text,
  `source` varchar(20) NOT NULL DEFAULT 'manual',
  `date_modification` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `hubspot_deal_id` (`hubspot_deal_id`),
  KEY `slug_phase` (`slug_phase`),
  KEY `idx_deal_slug_id` (`hubspot_deal_id`,`slug_phase`,`id`)
) ENGINE=InnoDB {charset}
SQL
    ,
    'achats_telegram_subscribers' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `{prefix}achats_telegram_subscribers` (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `deal_id` int NOT NULL,
  `chat_id` bigint NOT NULL,
  `user_id` int NOT NULL,
  `date` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `is_admin` tinyint(1) DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_deal_chat` (`deal_id`,`chat_id`),
  KEY `idx_deal` (`deal_id`),
  KEY `idx_chat` (`chat_id`)
) ENGINE=InnoDB {charset}
SQL
    ,
    'achats_template_mail' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `{prefix}achats_template_mail` (
  `Id` int NOT NULL AUTO_INCREMENT,
  `Brevo_id` int NOT NULL,
  `lang` text NOT NULL,
  `subject` text NOT NULL,
  `message` text NOT NULL,
  `telegram` text,
  `message_type` text NOT NULL,
  `message_family` mediumtext NOT NULL,
  `prompt` text NOT NULL,
  `join_doc_typ` text NOT NULL,
  `selectionnable` int NOT NULL,
  `created_by` int NOT NULL,
  PRIMARY KEY (`Id`)
) ENGINE=InnoDB {charset}
SQL
    ,
    'achats_type_prestations' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `{prefix}achats_type_prestations` (
  `Id` int NOT NULL AUTO_INCREMENT,
  `type` text NOT NULL,
  `prestation` text NOT NULL,
  `description` text NOT NULL,
  `stockManaged` int NOT NULL,
  `sort` int NOT NULL,
  `color` text NOT NULL,
  `image` int NOT NULL,
  `delivery_time` text NOT NULL,
  PRIMARY KEY (`Id`)
) ENGINE=InnoDB {charset}
SQL
    ,
];
