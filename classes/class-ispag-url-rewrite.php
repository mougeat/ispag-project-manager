<?php
if (!class_exists('ISPAG_URL_Rewrite')) {
    class ISPAG_URL_Rewrite {
        public function __construct() {
            add_action('init', [$this, 'add_rewrite_rules']);
            add_filter('query_vars', [$this, 'add_query_vars']);
        }

        public function add_rewrite_rules() {
            // Règle FR : /project-detail/123456
            add_rewrite_rule(
                '^project-detail/([0-9]+)/?$',
                'index.php?pagename=details-du-projet&deal_id=$matches[1]',
                'top'
            );

            add_rewrite_rule(
                '^projectdetail/([0-9]+)/?$',
                'index.php?pagename=project&deal_id=$matches[1]',
                'top'
            );

            // Règle DE avec le préfixe /de/ dans le regex
            add_rewrite_rule(
                '^de/project-detail/([0-9]+)/?$',
                'index.php?pagename=projektdetails&deal_id=$matches[1]&lang=de',
                'top'
            );

            // Règle DE sans le préfixe /de/ au cas où (ex: domaine dédié ou réglage spécifique)
            add_rewrite_rule(
                '^projekt-detail/([0-9]+)/?$',
                'index.php?pagename=projektdetails&deal_id=$matches[1]&lang=de',
                'top'
            );

            add_rewrite_rule(
                '^ispag-digital-product-twin/([a-zA-Z0-9\-_]+)/?$',
                'index.php?pagename=ispag-digital-product-twin&serial=$matches[1]',
                'top'
            );
            
        }

        public function add_query_vars($vars) {
            $vars[] = 'deal_id';
            $vars[] = 'serial';
            return $vars;
        }
    }
}