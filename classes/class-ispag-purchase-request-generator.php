<?php
/**
 * ISPAG_Purchase_Request_Generator
 *
 * Génère les demandes d'achat (commandes fournisseurs) à partir des articles
 * d'un projet (deal) qui n'ont pas encore été envoyés en achat.
 *
 * v2 — optimisé et simplifié :
 *  - Une seule requête pour récupérer les lignes déjà existantes (fini le N+1)
 *  - Une seule mise à jour d'entête par commande (au lieu d'une par article)
 *  - Notification envoyée au moment de la création de chaque commande
 *    (fini les variables utilisées après la boucle)
 *  - Constantes nommées à la place des "magic numbers"
 *  - Transaction SQL pour la cohérence en cas d'erreur partielle
 */
class ISPAG_Purchase_Request_Generator {

    protected $wpdb;
    protected $deal_id;

    protected $table_articles;         // achats_details_commande (articles du projet)
    protected $table_commandes;        // achats_articles_cmd_fournisseurs (lignes d'achat)
    protected $table_liste_commandes;  // achats_commande_liste_fournisseurs (entêtes de commande)
    protected $table_fournisseurs;     // ispag_companies (fournisseurs : isSupplier = 1)

    protected static $instance = null;

    // État "achat" (table achats_etat_commandes_fournisseur) : tous les articles
    // sont catalogue -> on saute directement à "prêt à commander"
    private const ETAT_STANDARD_OK = 18;

    public function __construct($deal_id = 0) {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->deal_id = intval($deal_id);

        $this->table_articles        = $wpdb->prefix . 'achats_details_commande';
        $this->table_commandes       = $wpdb->prefix . 'achats_articles_cmd_fournisseurs';
        $this->table_liste_commandes = $wpdb->prefix . 'achats_commande_liste_fournisseurs';
        $this->table_fournisseurs    = $wpdb->prefix . 'ispag_companies';
    }

    public static function init() {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        add_action('wp_ajax_ispag_generate_purchase_requests', [self::$instance, 'ajax_generate_purchase_requests']);
        add_action('ispag_generate_purchase_requests', [self::$instance, 'action_generate_purchase_requests'], 10, 2);
    }

    /* ------------------------------------------------------------------ *
     * Points d'entrée (AJAX / action WordPress)
     * ------------------------------------------------------------------ */

    public function ajax_generate_purchase_requests() {
        if (!current_user_can('manage_order')) {
            wp_send_json_error(['message' => __('Unauthorized', 'creation-reservoir')]);
        }

        $deal_id = isset($_POST['deal_id']) ? intval($_POST['deal_id']) : 0;
        if (!$deal_id) {
            wp_send_json_error(['message' => __('Deal ID missing', 'creation-reservoir')]);
        }

        try {
            $result = $this->generate_for_deal($deal_id);
            wp_send_json_success([
                'message'     => __('Order generated', 'creation-reservoir'),
                'generate_po' => $result,
            ]);
        } catch (Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    public function action_generate_purchase_requests($html, $deal_id) {
        if (!current_user_can('manage_order') || !$deal_id) {
            return false;
        }

        $result = $this->generate_for_deal($deal_id);
        $articles_list = apply_filters('ispag_reload_article_list', $deal_id, null);

        return ['generate_po' => $result, 'articles_list' => $articles_list];
    }

    /* ------------------------------------------------------------------ *
     * Logique principale
     * ------------------------------------------------------------------ */

    /**
     * Génère (ou met à jour) les demandes d'achat pour un deal donné.
     *
     * @param int $deal_id
     * @return array Commandes créées/mises à jour, indexées par IdFournisseur
     */
    public function generate_for_deal($deal_id) {
        $this->deal_id = intval($deal_id);
        $logger  = ISPAG_Logger::get_instance();
        $user_id = get_current_user_id();

        $articles = $this->get_pending_articles();
        if (empty($articles)) {
            $logger->log_user_action('purchase_requests', 'no_articles_to_process', ['deal_id' => $this->deal_id], $user_id);
            return [];
        }

        $project = apply_filters('ispag_get_project_by_deal_id', null, $this->deal_id);
        if (!$project) {
            $logger->log('purchase_requests', 'ERROR: Project not found for deal_id ' . $this->deal_id, $user_id);
            return [];
        }

        $etat = $this->resolve_initial_state($project, $articles);
        $ref  = $this->build_reference($project);

        // Une seule requête pour toutes les lignes déjà existantes (évite le N+1)
        $article_ids    = wp_list_pluck($articles, 'Id');
        $existing_lines = $this->get_existing_lines($article_ids);

        $this->wpdb->query('START TRANSACTION');

        try {
            $result = $this->process_articles($articles, $existing_lines, $project, $ref, $etat, $user_id);
            $this->wpdb->query('COMMIT');
        } catch (Exception $e) {
            $this->wpdb->query('ROLLBACK');
            $logger->log('purchase_requests', 'ERROR: ' . $e->getMessage(), $user_id);
            throw $e;
        }

        $logger->log_user_action('purchase_requests', 'generate_purchase_requests_complete', [
            'deal_id' => $this->deal_id,
            'result'  => $result,
        ], $user_id);

        return $result;
    }

    /* ------------------------------------------------------------------ *
     * Étapes internes
     * ------------------------------------------------------------------ */

    /** Articles du projet pas encore envoyés en demande d'achat. */
    private function get_pending_articles() {
        return $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->table_articles}
                 WHERE hubspot_deal_id = %d
                   AND (DemandeAchatOk IS NULL OR DemandeAchatOk != 1)
                 ORDER BY IdFournisseur",
                $this->deal_id
            )
        );
    }

    /** Récupère en une seule requête les lignes d'achat déjà existantes pour ces articles. */
    private function get_existing_lines(array $article_ids) {
        if (empty($article_ids)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($article_ids), '%d'));
        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->table_commandes} WHERE IdCommandeClient IN ($placeholders)",
                ...$article_ids
            )
        );

        // Indexé par IdCommandeClient pour un accès direct dans la boucle
        $by_article_id = [];
        foreach ($rows as $row) {
            $by_article_id[$row->IdCommandeClient] = $row;
        }
        return $by_article_id;
    }

    /** Vrai si tous les articles du lot sont des articles catalogue (standard). */
    private function all_articles_are_standard(array $articles) {
        foreach ($articles as $article) {
            if (empty($article->IdArticleStandard)) {
                return false;
            }
        }
        return true;
    }

    /** Calcule l'état initial de la commande selon devis/commande ferme + catalogue. */
    private function resolve_initial_state($project, array $articles) {
        $is_quotation = (intval($project->isQotation) === 1);

        if ($is_quotation) {
            return $this->all_articles_are_standard($articles)
                ? self::ETAT_STANDARD_OK
                : intval(get_option('wpcb_first_qotation_state'));
        }

        return intval(get_option('wpcb_first_order_state'));
    }

    /** Construit la référence de commande (devis vs commande ferme). */
    private function build_reference($project) {
        if (intval($project->isQotation) === 1) {
            return $project->ObjetCommande;
        }
        return get_option('wpcb_kst') . '/' . $project->NumCommande . ' - ' . $project->ObjetCommande;
    }

    /**
     * Boucle métier : met à jour les lignes existantes, crée les nouvelles commandes
     * par fournisseur, et notifie une fois par commande nouvellement créée.
     */
    private function process_articles(array $articles, array $existing_lines, $project, $ref, $etat, $user_id) {
        $commandes_par_fournisseur = [];
        $commandes_deja_rafraichies = []; // IdCommande => true (dédoublonnage des UPDATE d'entête)
        $result = ['Project' => $project];

        foreach ($articles as $article) {
            $existing = $existing_lines[$article->Id] ?? null;

            if ($existing) {
                $result[$article->IdFournisseur] = ['exist'];
                $this->refresh_existing_line($existing, $article, $etat, $commandes_deja_rafraichies);
            } else {
                $commande_id = $this->get_or_create_order(
                    $article, $commandes_par_fournisseur, $project, $ref, $etat, $user_id, $result
                );
                $this->insert_order_line($commande_id, $article);
            }

            $this->mark_article_as_processed($article->Id);
        }

        return $result;
    }

    /** Met à jour le prix/remise d'une ligne existante et rafraîchit l'entête (une seule fois par commande). */
    private function refresh_existing_line($existing_line, $article, $etat, array &$commandes_deja_rafraichies) {
        $update_data = ['UnitPrice' => 0];
        $format      = ['%f'];

        // Si l'article est standard, on récupère son prix et sa remise via le repository
        if (!empty($article->IdArticleStandard) && !empty($article->IdFournisseur)) {
            $price_data = ISPAG_Article_Repository::get_standard_article_purchase_price(
                $article->IdArticleStandard, 
                $article->IdFournisseur
            );

            if (!empty($price_data)) {
                if (isset($price_data['purchase_price'])) {
                    $update_data['UnitPrice'] = $price_data['purchase_price'];
                }
                if (isset($price_data['discount'])) {
                    $update_data['discount'] = $price_data['discount'];
                    $format[] = '%f';
                }
            }
        }

        $this->wpdb->update(
            $this->table_commandes,
            $update_data,
            ['Id' => $existing_line->Id],
            $format,
            ['%d']
        );

        if (!isset($commandes_deja_rafraichies[$existing_line->IdCommande])) {
            $this->wpdb->update(
                $this->table_liste_commandes,
                [
                    'EtatCommande'          => $etat,
                    'TimestampDateCreation' => time(),
                ],
                ['Id' => $existing_line->IdCommande],
                ['%d', '%d'],
                ['%d']
            );
            $commandes_deja_rafraichies[$existing_line->IdCommande] = true;
        }
    }

    /** Retourne l'ID de commande pour ce fournisseur, en la créant (+ notification) si besoin. */
    private function get_or_create_order($article, array &$commandes_par_fournisseur, $project, $ref, $etat, $user_id, array &$result) {
        $fournisseur_id = $article->IdFournisseur;

        if (isset($commandes_par_fournisseur[$fournisseur_id])) {
            return $commandes_par_fournisseur[$fournisseur_id];
        }

        $this->wpdb->insert(
            $this->table_liste_commandes,
            [
                'hubspot_deal_id'       => $this->deal_id,
                'IdFournisseur'         => $fournisseur_id,
                'TimestampDateCreation' => time(),
                'EtatCommande'          => $etat,
                'RefCommande'           => $ref,
                'Abonne'                => ';1;',
                'created_by'            => $user_id,
            ],
            ['%d', '%d', '%d', '%d', '%s', '%s', '%d']
        );

        $commande_id = $this->wpdb->insert_id;
        $commandes_par_fournisseur[$fournisseur_id] = $commande_id;
        $result[$fournisseur_id] = ['created', $commande_id];

        $this->send_purchase_order_notification($commande_id, $fournisseur_id, $project, $ref, $user_id);

        return $commande_id;
    }

    private function insert_order_line($commande_id, $article) {
        $line_data = [
            'IdCommande'        => $commande_id,
            'IdArticleStandard' => $article->IdArticleStandard,
            'IdCommandeClient'  => $article->Id,
            'RefSurMesure'      => $article->Article,
            'DescSurMesure'     => $article->Description,
            'Qty'               => $article->Qty,
            'UnitPrice'         => 0,
        ];
        $format = ['%d', '%d', '%d', '%s', '%s', '%d', '%f'];

        // Si l'article est standard, on récupère son prix et sa remise via le repository
        if (!empty($article->IdArticleStandard) && !empty($article->IdFournisseur)) {
            $price_data = ISPAG_Article_Repository::get_standard_article_purchase_price(
                $article->IdArticleStandard, 
                $article->IdFournisseur
            );

            if (!empty($price_data)) {
                if (isset($price_data['purchase_price'])) {
                    $line_data['UnitPrice'] = $price_data['purchase_price'];
                }
                if (isset($price_data['discount'])) {
                    $line_data['discount'] = $price_data['discount'];
                    $format[] = '%f';
                }
            }
        }

        $this->wpdb->insert(
            $this->table_commandes,
            $line_data,
            $format
        );
    }

    private function mark_article_as_processed($article_id) {
        $this->wpdb->update(
            $this->table_articles,
            ['DemandeAchatOk' => 1],
            ['Id' => $article_id],
            ['%d'],
            ['%d']
        );
    }

    /* ------------------------------------------------------------------ *
     * Notifications
     * ------------------------------------------------------------------ */

    protected function send_purchase_order_notification($commande_id, $fournisseur_id, $project, $ref, $user_id) {
        if (!class_exists('ISPAG_Notifications_Manager')) {
            return;
        }

        $fournisseur_name = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT company_name FROM {$this->table_fournisseurs} WHERE Id = %d",
            $fournisseur_id
        )) ?: sprintf(__('Supplier #%d', 'creation-reservoir'), $fournisseur_id);

        $title = '📦 ' . __('New purchase request', 'creation-reservoir');
        $message = sprintf(
            __('A new purchase request has been generated for project <strong>%1$s</strong> (Ref: %2$s) with supplier <strong>%3$s</strong>.', 'creation-reservoir'),
            esc_html($project->ObjetCommande),
            esc_html($ref),
            esc_html($fournisseur_name)
        );

        $url = trailingslashit(home_url('purchase')) . $commande_id;

        $destinataires = array_values(array_unique(array_filter([1, $user_id])));

        ISPAG_Notifications_Manager::send(
            $destinataires,
            'purchase_order_creation',
            $title,
            $message,
            $url,
            $commande_id
        );
    }
}