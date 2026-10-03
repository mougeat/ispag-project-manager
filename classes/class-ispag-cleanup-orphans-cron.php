<?php
defined('ABSPATH') or die();

/**
 * CRON hebdomadaire : détecte les lignes orphelines dans les tables achats_*.
 *
 * Rien n'est supprimé automatiquement : pour chaque nettoyage qui a des lignes à supprimer,
 * une notification (ISPAG_Notifications_Manager) est envoyée à l'admin. Le lien de la
 * notification (signé, valable 14 jours, réservé aux manage_options) exécute la suppression.
 */
class ISPAG_Cleanup_Orphans_Cron {
    const EVENT      = 'ispag_cleanup_orphans_event';
    const QUERY_VAR  = 'ispag_cleanup_run';
    const LOG_NAME   = 'cleanup_orphans';
    const LINK_TTL   = 14 * DAY_IN_SECONDS;

    protected $wpdb;
    public static $instance = null;

    public static function init() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;

        add_action(self::EVENT, [$this, 'check_and_notify']);
        add_action('init', [$this, 'maybe_execute_from_link']);

        if (!wp_next_scheduled(self::EVENT)) {
            wp_schedule_event(strtotime('next monday 01:00:00'), 'weekly', self::EVENT);
        }
    }

    /**
     * Définition des nettoyages. Chaque entrée : label, table cible, et la condition
     * SQL (alias t) qui désigne les lignes orphelines.
     */
    protected function jobs() {
        $p = $this->wpdb->prefix;
        return [
            'info_commande' => [
                'label' => 'achats_info_commande sans deal HubSpot ni commande d\'achat',
                'table' => $p . 'achats_info_commande',
                'where' => "(t.hubspot_deal_id IS NULL OR t.hubspot_deal_id = 0)
                            AND (t.purchase_order IS NULL OR t.purchase_order = 0)",
            ],
            'details_commande' => [
                'label' => 'achats_details_commande dont le projet n\'existe plus',
                'table' => $p . 'achats_details_commande',
                // On ne supprime pas les articles que le CRON historique peut rattacher via IdArticleMaster.
                'where' => "NOT EXISTS (SELECT 1 FROM {$p}achats_liste_commande pr WHERE pr.hubspot_deal_id = t.hubspot_deal_id)
                            AND NOT EXISTS (
                                SELECT 1 FROM {$p}achats_details_commande m
                                JOIN {$p}achats_liste_commande pm ON pm.hubspot_deal_id = m.hubspot_deal_id
                                WHERE m.Id = t.IdArticleMaster
                            )",
            ],
            'tank_dimensions' => [
                'label' => 'achats_tank_dimensions liées à un article inexistant',
                'table' => $p . 'achats_tank_dimensions',
                'where' => "t.customerTankId IS NOT NULL
                            AND NOT EXISTS (SELECT 1 FROM {$p}achats_details_commande a WHERE a.Id = t.customerTankId)",
            ],
            'tank_heat_exchanger' => [
                'label' => 'achats_tank_heat_exchanger sans cuve',
                'table' => $p . 'achats_tank_heat_exchanger',
                'where' => "NOT EXISTS (SELECT 1 FROM {$p}achats_tank_dimensions d WHERE d.Id = t.tank_id)",
            ],
            'tank_connection' => [
                'label' => 'achats_tank_connection sans cuve',
                'table' => $p . 'achats_tank_connection',
                'where' => "NOT EXISTS (SELECT 1 FROM {$p}achats_tank_dimensions d WHERE d.Id = t.TankId)",
            ],
        ];
    }

    protected function table_exists($table) {
        return $this->wpdb->get_var($this->wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
    }

    protected function count_orphans(array $job) {
        if (!$this->table_exists($job['table'])) {
            return 0;
        }
        return (int) $this->wpdb->get_var("SELECT COUNT(*) FROM {$job['table']} t WHERE {$job['where']}");
    }

    /**
     * CRON : compte les orphelins et envoie une notification par nettoyage concerné.
     */
    public function check_and_notify() {
        $logger = ISPAG_Logger::get_instance();
        $logger->log(self::LOG_NAME, '--- DÉBUT VÉRIFICATION ORPHELINS ---');

        foreach ($this->jobs() as $key => $job) {
            $count = $this->count_orphans($job);
            if ($count === 0) {
                $logger->log(self::LOG_NAME, "[$key] Rien à nettoyer.");
                continue;
            }

            if (!class_exists('ISPAG_Notifications_Manager')) {
                $logger->log(self::LOG_NAME, "[$key] $count ligne(s) orphelines mais ISPAG_Notifications_Manager indisponible.");
                continue;
            }

            ISPAG_Notifications_Manager::send(
                [1], // Admin (ID = 1)
                'article_cleanup',
                '🗑️ Weekly cleanup: ' . $key,
                sprintf(
                    '%d row(s) to delete: %s. Open this notification to accept and run the deletion.',
                    $count,
                    $job['label']
                ),
                $this->build_link($key),
                0
            );
            $logger->log(self::LOG_NAME, "[$key] Notification envoyée ($count ligne(s)).");
        }

        $logger->log(self::LOG_NAME, '--- FIN VÉRIFICATION ORPHELINS ---');
    }

    protected function sign($key, $expires) {
        return hash_hmac('sha256', $key . '|' . $expires, wp_salt('auth'));
    }

    protected function build_link($key) {
        $expires = time() + self::LINK_TTL;
        return add_query_arg([
            self::QUERY_VAR => $key,
            'exp'           => $expires,
            'sig'           => $this->sign($key, $expires),
        ], home_url('/'));
    }

    /**
     * Lien de notification cliqué : vérifie la signature + les droits, puis supprime.
     */
    public function maybe_execute_from_link() {
        if (empty($_GET[self::QUERY_VAR])) {
            return;
        }

        $key     = sanitize_key(wp_unslash($_GET[self::QUERY_VAR]));
        $expires = isset($_GET['exp']) ? (int) $_GET['exp'] : 0;
        $sig     = isset($_GET['sig']) ? (string) wp_unslash($_GET['sig']) : '';

        $jobs = $this->jobs();
        if (!isset($jobs[$key]) || !hash_equals($this->sign($key, $expires), $sig)) {
            wp_die('Invalid cleanup link.', 'Cleanup', ['response' => 403]);
        }
        if ($expires < time()) {
            wp_die('This cleanup link has expired.', 'Cleanup', ['response' => 410]);
        }
        if (!is_user_logged_in()) {
            auth_redirect();
        }
        if (!current_user_can('manage_options')) {
            wp_die('You are not allowed to run this cleanup.', 'Cleanup', ['response' => 403]);
        }

        $deleted = $this->execute($key);

        wp_die(
            sprintf('Cleanup <strong>%s</strong> done: %d row(s) deleted.', esc_html($key), $deleted)
            . '<br><a href="' . esc_url(home_url('/')) . '">Back to site</a>',
            'Cleanup',
            ['response' => 200]
        );
    }

    /**
     * Supprime les orphelins d'un nettoyage (recalculés au moment de l'exécution).
     */
    protected function execute($key) {
        $jobs = $this->jobs();
        $job  = $jobs[$key];
        $logger = ISPAG_Logger::get_instance();

        if (!$this->table_exists($job['table'])) {
            return 0;
        }

        // SELECT puis DELETE par Id : évite l'erreur MySQL 1093 (sous-requête sur la table cible).
        $ids = array_map('intval', $this->wpdb->get_col("SELECT t.Id FROM {$job['table']} t WHERE {$job['where']}"));
        $deleted = 0;
        foreach (array_chunk($ids, 500) as $chunk) {
            $affected = $this->wpdb->query("DELETE FROM {$job['table']} WHERE Id IN (" . implode(',', $chunk) . ')');
            $deleted += $affected === false ? 0 : (int) $affected;
        }

        $logger->log(self::LOG_NAME, "[$key] $deleted ligne(s) supprimée(s) par l'utilisateur " . get_current_user_id() . '.', get_current_user_id());
        return $deleted;
    }
}
