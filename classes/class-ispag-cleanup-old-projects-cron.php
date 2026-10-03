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

        // 1. Vérifier les projets anciens sans articles
        $this->check_old_projects_without_articles($logger, $log_name);

        // 2. Vérifier les articles non liés à un projet
        $this->check_orphaned_articles($logger, $log_name);

        $logger->log($log_name, "--- FIN VÉRIFICATION ---");
    }

    /**
     * Vérifie les projets > 3 mois sans articles, créés par des non-membres ISPAG.
     */
    protected function check_old_projects_without_articles($logger, $log_name) {
        $three_months_ago = strtotime('-3 months');
        $projects = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT p.id, p.hubspot_deal_id, p.ObjetCommande, p.created_by, p.TimestampDateCommande
                FROM {$this->table_projets} p
                WHERE p.isQotation IS NOT NULL
                AND p.TimestampDateCommande < %d
                AND NOT EXISTS (
                    SELECT 1 FROM {$this->table_articles} a
                    WHERE a.hubspot_deal_id = p.hubspot_deal_id
                )",
                $three_months_ago
            )
        );

        if (empty($projects)) {
            $logger->log($log_name, "Aucun projet ancien trouvé.");
            return;
        }

        foreach ($projects as $project) {
            $user = get_userdata($project->created_by);
            if (!$user) continue;

            // Vérifier si l'utilisateur a un rôle interdit
            $has_forbidden_role = false;
            foreach ($this->forbidden_roles as $role) {
                if (in_array($role, $user->roles)) {
                    $has_forbidden_role = true;
                    break;
                }
            }

            // Si l'utilisateur N'A PAS de rôle ISPAG, notifier l'admin
            if (!$has_forbidden_role && class_exists('ISPAG_Notifications_Manager')) {
                $message = sprintf(
                    "The project <strong>%s</strong> (ID: %d, created on %s by %s) has no articles and has been inactive for more than 3 months. Do you want to delete it?",
                    esc_html($project->ObjetCommande),
                    $project->hubspot_deal_id,
                    $project->date_creation,
                    esc_html($user->display_name)
                );

                ISPAG_Notifications_Manager::send(
                    [1], // Admin (ID = 1)
                    'project_cleanup',
                    '🗑️ Project to delete?',
                    $message,
                    'project-detail/' . $project->hubspot_deal_id,
                    $project->hubspot_deal_id
                );
                $logger->log($log_name, sprintf("Notification envoyée pour le projet ID %d.", $project->hubspot_deal_id));
            }
        }
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

        // Notifier l'admin pour les articles toujours orphelins
        if (!empty($articles_to_notify) && class_exists('ISPAG_Notifications_Manager')) {
            $message = sprintf(
                "The following articles are not linked to any existing project: <strong>%s</strong>. Do you want to delete them?",
                esc_html(implode(', ', $articles_to_notify))
            );

            ISPAG_Notifications_Manager::send(
                [1], // Admin (ID = 1)
                'article_cleanup',
                '🗑️ Orphan articles to delete?',
                $message,
                'liste-des-articles/',
                0 // Pas de deal_id pour les articles orphelins
            );
            $logger->log($log_name, "Notification envoyée pour les articles orphelins : " . implode(', ', $articles_to_notify));
        }

        // Log des mises à jour
        if (!empty($articles_to_update)) {
            $logger->log($log_name, sprintf("Articles mis à jour : %s", implode(', ', $articles_to_update)));
        }
    }
}

// Initialiser la classe
