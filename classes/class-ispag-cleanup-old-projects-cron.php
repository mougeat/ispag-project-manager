<?php
defined('ABSPATH') or die();

class ISPAG_Cleanup_Old_Projects_Cron {
    protected $wpdb;
    protected $table_projets;
    protected $table_articles;
    protected $forbidden_roles = ['achat_ispag', 'vente_ispag', 'administrator', 'membre_ispag'];

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
        $this->table_projets = $wpdb->prefix . 'achats_liste_commande';
        $this->table_articles = $wpdb->prefix . 'achats_details_commande';

        // Planifier l'événement CRON (1 fois par semaine)
        add_action('ispag_cleanup_old_projects_event', [$this, 'check_and_notify']);
        if (!wp_next_scheduled('ispag_cleanup_old_projects_event')) {
            wp_schedule_event(strtotime('next monday 00:00:00'), 'weekly', 'ispag_cleanup_old_projects_event');
        }
    }

    /**
     * Vérifie les projets anciens sans articles et les articles non liés.
     */
    public function check_and_notify() {
        $logger = ISPAG_Logger::get_instance();
        $log_name = 'cleanup_old_projects';
        $logger->log($log_name, "--- DÉBUT VÉRIFICATION PROJETS/ARTICLES À SUPPRIMER ---");

        // Les projets anciens sans articles et les articles orphelins sont désormais proposés à la suppression par
        // ISPAG_Cleanup_Orphans_Cron (une notification par nettoyage, lien vers la page de contrôle avec cases à cocher).
        // Ici : uniquement la réparation automatique des articles rattachables à un projet via leur article maître.
        $this->check_orphaned_articles($logger, $log_name);

        $logger->log($log_name, "--- FIN VÉRIFICATION ---");
    }

    /**
     * Vérifie les articles non liés à un projet existant.
     * Si un article a un IdArticleMaster lié à un projet, on met à jour son hubspot_deal_id.
     */
    protected function check_orphaned_articles($logger, $log_name) {
        // 1. Trouver les articles non liés à un projet via hubspot_deal_id
        $orphaned_articles = $this->wpdb->get_results(
            "SELECT a.Id, a.Article, a.hubspot_deal_id, a.IdArticleMaster
            FROM {$this->table_articles} a
            LEFT JOIN {$this->table_projets} p ON a.hubspot_deal_id = p.hubspot_deal_id
            WHERE p.hubspot_deal_id IS NULL"
        );

        if (empty($orphaned_articles)) {
            $logger->log($log_name, "Aucun article non lié à un projet trouvé.");
            return;
        }

        $articles_to_notify = [];
        $articles_to_update = [];

        foreach ($orphaned_articles as $article) {
            // Vérifier si l'article a un IdArticleMaster lié à un projet existant
            $master_article_project = $this->wpdb->get_row(
                $this->wpdb->prepare(
                    "SELECT p.hubspot_deal_id
                    FROM {$this->table_articles} a2
                    JOIN {$this->table_projets} p ON a2.hubspot_deal_id = p.hubspot_deal_id
                    WHERE a2.Id = %d",
                    $article->IdArticleMaster
                )
            );

            if ($master_article_project) {
                // Mettre à jour le hubspot_deal_id de l'article avec celui du projet lié à son IdArticleMaster
                $this->wpdb->update(
                    $this->table_articles,
                    ['hubspot_deal_id' => $master_article_project->hubspot_deal_id],
                    ['Id' => $article->Id]
                );
                $articles_to_update[] = $article->Id;
                $logger->log($log_name, sprintf("Article ID %d : hubspot_deal_id mis à jour avec %d (via IdArticleMaster %d).", $article->Id, $master_article_project->hubspot_deal_id, $article->IdArticleMaster));
            } else {
                // Sinon, ajouter à la liste des articles à notifier pour suppression
                $articles_to_notify[] = $article->Id;
                $logger->log($log_name, sprintf("Article orphelin trouvé : ID %d (%s).", $article->Id, $article->Article));
            }
        }

        if (!empty($articles_to_notify)) {
            $logger->log($log_name, "Articles toujours orphelins (proposés à la suppression par le nettoyage hebdomadaire) : " . implode(', ', $articles_to_notify));
        }

        // Log des mises à jour
        if (!empty($articles_to_update)) {
            $logger->log($log_name, sprintf("Articles mis à jour : %s", implode(', ', $articles_to_update)));
        }
    }
}

// Initialiser la classe
