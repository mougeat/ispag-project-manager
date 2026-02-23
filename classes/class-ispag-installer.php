<?php

defined('ABSPATH') || exit;

class ISPAG_Installer {
    public static function install() {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset_collate = $wpdb->get_charset_collate();
        $prefix = $wpdb->prefix;

        // Table abonnés Telegram
        $table_telegram = $prefix . 'achats_telegram_subscribers';
        $sql_telegram = "
            CREATE TABLE IF NOT EXISTS `$table_telegram` (
                `id` INT NOT NULL AUTO_INCREMENT,
                `chat_id` BIGINT NOT NULL,
                `display_name` VARCHAR(100) DEFAULT NULL,
                `is_admin` TINYINT(1) DEFAULT 0,
                PRIMARY KEY (`id`),
                UNIQUE KEY (`chat_id`)
            ) $charset_collate;
        ";
        dbDelta($sql_telegram);

        // Ajoute ici d'autres tables si besoin plus tard…
    }
}
