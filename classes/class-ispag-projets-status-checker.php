<?php

class ISPAG_Projets_status_checker {
    private $wpdb;
    private $table_historique;
    private $details_commande;
    private $table_liste_commande;
    private $table_articles_fournisseur;
    private $table_suivi;
    protected static $instance = null;

    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->table_historique          = $wpdb->prefix . 'achats_historique';
        $this->details_commande          = $wpdb->prefix . 'achats_details_commande';
        $this->table_liste_commande      = $wpdb->prefix . 'achats_liste_commande';
        $this->table_articles_fournisseur = $wpdb->prefix . 'achats_articles_cmd_fournisseurs';
        $this->table_suivi               = $wpdb->prefix . 'achats_suivi_phase_commande';
    }

    public static function init() {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        // add_action('isag_run_auto_update',                  [self::$instance, 'run_auto_update'], 10, 1);
        add_action('ispag_delete_suivis_whith_deal_id',     [self::$instance, 'delete_suivis_whith_deal_id'], 10, 2);
        // add_action('ispag_check_project_auto_status',       [self::$instance, 'run_auto_update']);
        add_action('ispag_check_plans_status',              [self::$instance, 'check_plan_delays']);
        add_action('wp',                                    [self::class, 'project_schedule_cron']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // CRON
    // ─────────────────────────────────────────────────────────────────────────

    public static function project_schedule_cron() {
        if (!wp_next_scheduled('ispag_check_project_auto_status')) {
            wp_schedule_event(time(), 'fifteenminutes', 'ispag_check_project_auto_status');
        }
        if (!wp_next_scheduled('ispag_check_plans_status')) {
            wp_schedule_event(time(), 'weekly', 'ispag_check_plans_status');
        }
    }

    public static function activation_hook() {
        self::init();
        self::project_schedule_cron();
    }

    public static function deactivation_hook() {
        foreach (['ispag_check_project_auto_status', 'ispag_check_plans_status'] as $hook) {
            $timestamp = wp_next_scheduled($hook);
            if ($timestamp) wp_unschedule_event($timestamp, $hook);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // REGISTRE DÉCLARATIF DES PHASES
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Retourne la liste des phases à contrôler.
     *
     * Chaque entrée :
     *   'slug'         => string    – identifiant de la phase en base
     *   'skip'         => string[]  – statuts pour lesquels on ne fait rien
     *   'match'        => 'exact' | 'ends_with'  (défaut : 'exact')
     *   'resolver'     => callable () → int|array
     *                     int   = status_id direct
     *                     array = [ type => status_id ] pour les phases multi-types (Delivered, Invoice)
     *   'side_effects' => callable (int $status_id) → void  (optionnel)
     */
    private function get_phase_registry(int $deal_id, object $project): array {
        $s = $this;

        return [

            [
                'slug'     => 'no_article',
                'skip'     => ['NaN', 'N/A'],
                'resolver' => fn() => $s->has_at_least_one_article($deal_id) ? 1 : 10,
            ],

            [
                'slug'     => 'customer_request',
                'skip'     => ['Done', 'NaN', 'N/A'],
                'resolver' => fn() => $s->has_document_type($deal_id, ['submission', 'request_supplier_quotation']) ? 1 : 10,
            ],

            [
                'slug'     => 'competitor_in_request',
                'skip'     => ['Done', 'NaN', 'N/A'],
                'resolver' => fn() => !empty($project->EnSoumission) ? 1 : 10,
            ],

            [
                'slug'     => 'get_customer_order',
                'skip'     => ['Done', 'NaN', 'N/A'],
                'resolver' => fn() => $s->has_document_type($deal_id, ['customer_order', 'supplier_order']) ? 1 : 10,
            ],

            [
                'slug'     => 'CmdFournisseur',
                'skip'     => ['Done', 'NaN', 'N/A'],
                'resolver' => fn() => $s->check_all_articles_ordered($deal_id) ? 1 : 10,
            ],

            [
                'slug'     => 'SignaturePlan',
                'skip'     => ['NaN', 'N/A'],
                'resolver' => fn() => $s->check_drawing_approval_status($deal_id) ? 1 : 10,
            ],

            [
                'slug'     => 'all_items_have_sales_price',
                'skip'     => ['NaN', 'N/A'],
                'resolver' => fn() => $s->all_order_items_have_price($deal_id) ? 1 : 10,
            ],

            [
                'slug'     => 'PlanFournisseur',
                'skip'     => ['NaN', 'N/A'],
                'resolver' => function() use ($s, $deal_id) {
                    $check = $s->check_drawing_status($deal_id);
                    if ($check['modification_plan']) return 11;
                    return $check['drawings_ok'] ? 1 : 10;
                },
                // Si modification → on repasse aussi EnvoiePlanClient à 11
                'side_effects' => function(int $status_id) use ($deal_id) {
                    if ($status_id === 11) {
                        (new ISPAG_Projet_Suivi())->update_phase_status($deal_id, 'EnvoiePlanClient', 11);
                    }
                },
            ],

            // ── Phases multi-types : le resolver retourne [ type => status_id ] ──

            [
                'slug'     => 'Delivered',
                'match'    => 'ends_with',
                'skip'     => ['NaN'],
                'resolver' => fn() => $s->resolve_multi_type_status(
                    $s->get_delivery_status_by_type($deal_id),
                    'delivered'
                ),
            ],

            [
                'slug'     => 'Invoice',
                'match'    => 'ends_with',
                'skip'     => ['Done', 'NaN'],
                'resolver' => fn() => $s->resolve_multi_type_status(
                    $s->get_invoice_status_by_type($deal_id),
                    'invoiced'
                ),
            ],
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // ORCHESTRATEUR
    // ─────────────────────────────────────────────────────────────────────────

    public function run_auto_update($hubspot_deal_id = null, $project = null): void {
        // Récursion sur tous les projets si aucun ID fourni
        if (empty($hubspot_deal_id)) {
            $response     = apply_filters('ispag_get_projects_or_offers', null, false, null, false, null, 0, 200);
            $all_projects = $response['results'] ?? [];

            foreach ($all_projects as $p) {
                $did = is_object($p->hubspot_deal_id)
                    ? (int)($p->hubspot_deal_id->hubspot_deal_id ?? 0)
                    : (int)$p->hubspot_deal_id;

                if ($did > 0) $this->run_auto_update($did);
            }
            return;
        }

        $project ??= apply_filters('ispag_get_project_by_deal_id', null, $hubspot_deal_id);
        if (!$project) return;

        $suivis       = new ISPAG_Projet_Suivi();
        $all_statuses = $suivis->get_current_status($hubspot_deal_id);
        $registry     = $this->get_phase_registry($hubspot_deal_id, $project);

        foreach ($all_statuses as $current_status) {
            foreach ($registry as $phase) {
                $slug  = $phase['slug'];
                $match = $phase['match'] ?? 'exact';

                if (!$this->phase_matches($current_status->SlugPhase, $slug, $match)) continue;
                if (in_array($current_status->Statut, $phase['skip'], true)) continue;

                $resolved = ($phase['resolver'])();

                // Phase multi-types : le resolver a retourné un tableau
                if (is_array($resolved)) {
                    foreach ($resolved as $type => $status_id) {
                        $suivis->update_phase_status($hubspot_deal_id, $type . $slug, $status_id);
                    }
                } else {
                    $suivis->update_phase_status($hubspot_deal_id, $slug, $resolved);

                    if (isset($phase['side_effects'])) {
                        ($phase['side_effects'])($resolved);
                    }
                }

                break; // un slug courant = une seule règle à appliquer
            }
        }

        // Clôture automatique si toutes les étapes sont validées
        $suivis->close_project_if_all_steps_completed($hubspot_deal_id, !empty($project->isQotation));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // HELPERS ORCHESTRATEUR
    // ─────────────────────────────────────────────────────────────────────────

    /** Vérifie si le slug courant correspond à la phase selon le mode de matching */
    private function phase_matches(string $current_slug, string $phase_slug, string $match): bool {
        return $match === 'ends_with'
            ? str_ends_with($current_slug, $phase_slug)
            : $current_slug === $phase_slug;
    }

    /**
     * Convertit le résultat de get_delivery_status_by_type / get_invoice_status_by_type
     * en tableau [ type => status_id ] pour les phases multi-types.
     */
    public function resolve_multi_type_status(array $status_by_type, string $key): array {
        $result = [];
        foreach ($status_by_type as $type => $row) {
            if (empty($row['total'])) {
                $result[$type] = 5;
            } elseif (!empty($row[$key])) {
                $result[$type] = 1;
            } else {
                $result[$type] = 10;
            }
        }
        return $result;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // VÉRIFICATIONS MÉTIER
    // ─────────────────────────────────────────────────────────────────────────

    public function delete_suivis_whith_deal_id($html, $deal_id) {
        global $wpdb;
        $wpdb->delete($wpdb->prefix . 'achats_suivi_phase_commande', ['hubspot_deal_id' => $deal_id]);
    }

    /**
     * Vérifie si un type de document existe pour un projet
     */
    public function has_document_type($hubspot_deal_id, $types = ['customer_order', 'supplier_order']) {
        if (empty($types)) return false;

        $placeholders = implode(',', array_fill(0, count($types), '%s'));
        $sql = $this->wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table_historique}
             WHERE hubspot_deal_id = %d AND ClassCss IN ($placeholders)",
            array_merge([$hubspot_deal_id], $types)
        );

        return (int)$this->wpdb->get_var($sql) > 0;
    }

    /**
     * Vérifie si tous les articles ont été commandés
     */
    public function check_all_articles_ordered($hubspot_deal_id) {
        $total = (int)$this->wpdb->get_var($this->wpdb->prepare("
            SELECT COUNT(*) FROM {$this->details_commande} WHERE hubspot_deal_id = %d
        ", $hubspot_deal_id));

        $valides = (int)$this->wpdb->get_var($this->wpdb->prepare("
            SELECT COUNT(*) FROM {$this->details_commande}
            WHERE hubspot_deal_id = %d AND DemandeAchatOk = 1
        ", $hubspot_deal_id));

        return $total > 0 && $total === $valides;
    }

    public function check_drawing_status($hubspot_deal_id) {
        $article_ids = $this->wpdb->get_col($this->wpdb->prepare("
            SELECT Id FROM {$this->details_commande}
            WHERE hubspot_deal_id = %d AND Type = 1
        ", $hubspot_deal_id));

        if (empty($article_ids)) return ['modification_plan' => false, 'drawings_ok' => false];

        $modification_plan = false;
        $drawings_ok       = true;

        foreach ($article_ids as $article_id) {
            $last_entry = $this->wpdb->get_var($this->wpdb->prepare("
                SELECT ClassCss FROM {$this->table_historique}
                WHERE Historique = %d ORDER BY Id DESC LIMIT 1
            ", $article_id));

            if ($last_entry === 'drawingModification') {
                $modification_plan = true;
            }

            $has_drawing = (int)$this->wpdb->get_var($this->wpdb->prepare("
                SELECT COUNT(*) FROM {$this->table_historique}
                WHERE Historique = %d AND ClassCss = 'product_drawing'
            ", $article_id));

            if (!$has_drawing) {
                $drawings_ok = false;
            }
        }

        return ['modification_plan' => $modification_plan, 'drawings_ok' => $drawings_ok];
    }

    public function check_drawing_approval_status($hubspot_deal_id) {
        $total = (int)$this->wpdb->get_var($this->wpdb->prepare("
            SELECT COUNT(*) FROM {$this->details_commande}
            WHERE hubspot_deal_id = %d AND Type = 1
        ", $hubspot_deal_id));

        $approved = (int)$this->wpdb->get_var($this->wpdb->prepare("
            SELECT COUNT(*) FROM {$this->details_commande}
            WHERE hubspot_deal_id = %d AND Type = 1 AND DrawingApproved = 1
        ", $hubspot_deal_id));

        return $total > 0 && $total === $approved;
    }

    public function get_delivery_status_by_type($hubspot_deal_id) {
        $sql = $this->wpdb->prepare("
            SELECT tp.prestation AS type,
                COUNT(dc.Id) AS total,
                SUM(CASE WHEN dc.Livre IS NOT NULL THEN 1 ELSE 0 END) AS delivered_count
            FROM {$this->details_commande} dc
            INNER JOIN wor9711_achats_type_prestations tp ON dc.Type = tp.Id
            WHERE dc.hubspot_deal_id = %d
            AND tp.prestation IN ('Product', 'Isol', 'Welding', 'div')
            GROUP BY tp.prestation
        ", $hubspot_deal_id);

        $results = $this->wpdb->get_results($sql);
        $status  = [];

        foreach (['Product', 'Isol', 'Welding', 'div'] as $type) {
            $status[$type] = ['total' => 0, 'delivered' => false];
        }

        foreach ($results as $row) {
            $status[$row->type]['total']     = (int)$row->total;
            $status[$row->type]['delivered'] = (int)$row->total === (int)$row->delivered_count
                                               && (int)$row->total > 0;
        }

        return $status;
    }

    public function get_invoice_status_by_type($hubspot_deal_id) {
        $sql = $this->wpdb->prepare("
            SELECT tp.prestation AS type,
                COUNT(dc.Id) AS total,
                SUM(CASE WHEN dc.invoiced IS NOT NULL THEN 1 ELSE 0 END) AS invoiced_count
            FROM {$this->details_commande} dc
            INNER JOIN wor9711_achats_type_prestations tp ON dc.Type = tp.Id
            WHERE dc.hubspot_deal_id = %d
            AND tp.prestation IN ('Product', 'Isol', 'Welding', 'div')
            GROUP BY tp.prestation
        ", $hubspot_deal_id);

        $results = $this->wpdb->get_results($sql);
        $status  = [];

        foreach (['Product', 'Isol', 'Welding', 'div'] as $type) {
            $status[$type] = ['total' => 0, 'invoiced' => false];
        }

        foreach ($results as $row) {
            $status[$row->type]['total']    = (int)$row->total;
            $status[$row->type]['invoiced'] = (int)$row->total === (int)$row->invoiced_count
                                              && (int)$row->total > 0;
        }

        return $status;
    }

    /**
     * Vérifie s'il existe au moins un article pour un projet donné
     */
    public function has_at_least_one_article($hubspot_deal_id) {
        $count = (int)$this->wpdb->get_var($this->wpdb->prepare("
            SELECT COUNT(*) FROM {$this->details_commande} WHERE hubspot_deal_id = %d
        ", $hubspot_deal_id));

        return $count > 0;
    }

    public function all_order_items_have_price($hubspot_deal_id) {
        $articles = $this->wpdb->get_results($this->wpdb->prepare("
            SELECT Id, sales_price, Type
            FROM {$this->details_commande}
            WHERE hubspot_deal_id = %d
        ", $hubspot_deal_id));

        foreach ($articles as $article) {
            if (floatval($article->sales_price) > 0) continue;

            if ((int)$article->Type === 1) {
                $prix_fournisseur = $this->wpdb->get_var($this->wpdb->prepare("
                    SELECT UnitPrice FROM {$this->table_articles_fournisseur}
                    WHERE IdCommandeClient = %d LIMIT 1
                ", $article->Id));

                if (empty($prix_fournisseur) || floatval($prix_fournisseur) <= 0) return false;
            } else {
                return false;
            }
        }

        return true;
    }

    public function cron_update_all_project_statuses() {
        $results = $this->wpdb->get_col("
            SELECT hubspot_deal_id FROM {$this->table_liste_commande} WHERE project_status = 1
        ");

        foreach ($results as $hubspot_deal_id) {
            $this->run_auto_update($hubspot_deal_id);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // RELANCES PLANS
    // ─────────────────────────────────────────────────────────────────────────

    public function check_plan_delays() {
        global $wpdb;
        $wpdb->flush();

        $table_projets = $wpdb->prefix . 'achats_liste_commande';
        $table_phase   = $wpdb->prefix . 'achats_suivi_phase_commande';
        $table_meta    = 'wor9711_achats_project_meta';

        $projects = $wpdb->get_results("
            SELECT hubspot_deal_id, ObjetCommande, created_by
            FROM $table_projets
            WHERE (isQotation IS NULL OR isQotation = 0) AND project_status = 1
        ");

        if (empty($projects)) return;

        $today = new DateTime();

        foreach ($projects as $project) {
            $deal_id   = (int)$project->hubspot_deal_id;

            // Skip si déjà signé
            $has_signature = (int)$wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $table_phase
                 WHERE hubspot_deal_id = %d AND slug_phase = 'SignaturePlan' AND status_id IN (1, 5)",
                $deal_id
            ));
            if ($has_signature > 0) continue;

            // Date du dernier envoi de plan validé
            $date_sent_str = $wpdb->get_var($wpdb->prepare(
                "SELECT MAX(date_modification) FROM $table_phase
                 WHERE hubspot_deal_id = %d AND slug_phase = 'EnvoiePlanClient' AND status_id = 1",
                $deal_id
            ));
            if (!$date_sent_str) continue;

            try {
                $sent_date = new DateTime($date_sent_str);
                $interval  = $today->diff($sent_date);
                $diff_days = (int)$interval->format('%a');

                // Sécurité : date future
                if ($interval->invert == 0 && $diff_days > 0) continue;

                // Vérification du délai depuis la dernière relance
                $last_revive = $wpdb->get_var($wpdb->prepare(
                    "SELECT meta_value FROM $table_meta
                     WHERE post_id = %d AND meta_key = '_ispag_last_plan_revive' LIMIT 1",
                    $deal_id
                ));

                $days_since_last_revive = 999;
                if ($last_revive) {
                    $days_since_last_revive = (int)$today->diff(new DateTime($last_revive))->format('%a');
                }

                // On ne relance que si au moins 6 jours depuis la précédente
                if ($days_since_last_revive < 6) continue;

                if ($diff_days >= 7) {
                    // 1. Récupération des destinataires de base (Admin + Owner)
                    $recipients = [$project->created_by, 1];

                    // 2. Récupération des AssociatedContactIDs (stockés généralement en méta ou table liée)
                    // Adaptez cette requête selon la façon dont vous stockez les contacts associés au deal
                    $associated_contacts = $wpdb->get_col($wpdb->prepare(
                        "SELECT meta_value FROM $table_meta WHERE post_id = %d AND meta_key = '_ispag_associated_contact_ids'",
                        $deal_id
                    ));

                    if (!empty($associated_contacts)) {
                        foreach ($associated_contacts as $contact_ids_json) {
                            $decoded_ids = json_decode($contact_ids_json, true);
                            if (is_array($decoded_ids)) {
                                $recipients = array_merge($recipients, $decoded_ids);
                            }
                        }
                    }

                    // Nettoyage : convertir en entiers, supprimer les doublons et les valeurs vides/nulles
                    $recipients = array_unique(array_filter(array_map('intval', $recipients)));

                    // Notification unifiée via ISPAG_Notifications_Manager
                    if (class_exists('ISPAG_Notifications_Manager')) {
                        ISPAG_Notifications_Manager::send(
                            $recipients, // Tableau d'IDs destinataires (Admin, Owner, Contacts associés)
                            'product_manager',
                            __( '⚠️ Drawing to be validated', 'ispag-crm' ),
                            sprintf(
                                /* translators: %s: Project order object/name */
                                __( 'ATTENTION, drawings need to be validated on project %s!', 'ispag-crm' ),
                                $project->ObjetCommande
                            ),
                            'project-detail/' . $deal_id,
                            $deal_id
                        );
                    }

                    if ($diff_days >= 14) {
                        do_action('ispag_send_mail_from_slug', null, $deal_id, 'reviveProjectSign');
                    }

                    $this->update_project_specific_meta($deal_id, '_ispag_last_plan_revive', $today->format('Y-m-d'));
                }
            } catch (Exception $e) {
                // error_log("Erreur calcul date deal $deal_id : " . $e->getMessage());
            }
        }
    }

    /**
     * Met à jour la table meta personnalisée (upsert)
     */
    private function update_project_specific_meta($deal_id, $key, $value) {
        global $wpdb;
        $table_meta = 'wor9711_achats_project_meta';

        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT meta_id FROM $table_meta WHERE post_id = %d AND meta_key = %s",
            $deal_id, $key
        ));

        if ($exists) {
            $wpdb->update($table_meta, ['meta_value' => $value], ['post_id' => $deal_id, 'meta_key' => $key]);
        } else {
            $wpdb->insert($table_meta, ['post_id' => $deal_id, 'meta_key' => $key, 'meta_value' => $value]);
        }
    }
}