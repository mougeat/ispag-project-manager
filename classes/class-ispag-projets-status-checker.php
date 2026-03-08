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
        $this->table_historique = $wpdb->prefix . 'achats_historique';
        $this->details_commande = $wpdb->prefix . 'achats_details_commande';
        $this->table_liste_commande = $wpdb->prefix . 'achats_liste_commande';
        $this->table_articles_fournisseur = $this->wpdb->prefix . 'achats_articles_cmd_fournisseurs';
        $this->table_suivi = $this->wpdb->prefix . 'achats_suivi_phase_commande';
    }

    public static function init() {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        
        add_action('isag_run_auto_update', [self::$instance, 'run_auto_update'], 10, 1);
        add_action('ispag_delete_suivis_whith_deal_id', [self::$instance, 'delete_suivis_whith_deal_id'], 10, 2);
        
        
        // Hook CRON
        add_action('ispag_check_project_auto_status', [self::$instance, 'run_auto_update']);
        add_action('ispag_check_plans_status', [self::$instance, 'check_plan_delays']);

        // CRON scheduler
        add_action('wp', [self::class, 'project_schedule_cron']);


        


    }

    public function delete_suivis_whith_deal_id($html, $deal_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'achats_suivi_phase_commande';
        $wpdb->delete($table, ['hubspot_deal_id' => $deal_id]);
    } 

    public static function project_schedule_cron() {
        if (!wp_next_scheduled('ispag_check_project_auto_status')) {
            wp_schedule_event(time(), 'fifteenminutes', 'ispag_check_project_auto_status'); // toutes les heures
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
        $timestamp = wp_next_scheduled('ispag_check_project_auto_status');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'ispag_check_project_auto_status');
        }
        $timestamp = wp_next_scheduled('ispag_check_plans_status');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'ispag_check_plans_status');
        }
        
    }
 
    /**
     * Vérifie si une entrée "customer_order" existe pour un hubspot_deal_id
     * @param int $hubspot_deal_id
     * @return bool
     */
    public function has_document_type($hubspot_deal_id, $types = ['customer_order', 'supplier_order']) {
        if (empty($types)) return false;

        $placeholders = implode(',', array_fill(0, count($types), '%s'));
        $sql = $this->wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table_historique} 
            WHERE hubspot_deal_id = %d AND ClassCss IN ($placeholders)",
            array_merge([$hubspot_deal_id], $types)
        );

        $count = $this->wpdb->get_var($sql);
        return ($count > 0);
    }

    /**
     * Vérifie si une tous les articles ont été commandés
     * @param int $hubspot_deal_id
     * @return bool
     */
    public function check_all_articles_ordered($hubspot_deal_id) {
        $total = $this->wpdb->get_var($this->wpdb->prepare("
            SELECT COUNT(*) 
            FROM {$this->details_commande} 
            WHERE hubspot_deal_id = %d
        ", $hubspot_deal_id));

        $validés = $this->wpdb->get_var($this->wpdb->prepare("
            SELECT COUNT(*) 
            FROM {$this->details_commande} 
            WHERE hubspot_deal_id = %d AND DemandeAchatOk = 1
        ", $hubspot_deal_id));

        return ($total > 0 && $total === $validés);
    }

    public function check_drawing_status($hubspot_deal_id) {
        // Récupérer les articles de type 1
        $article_ids = $this->wpdb->get_col($this->wpdb->prepare("
            SELECT Id 
            FROM {$this->details_commande} 
            WHERE hubspot_deal_id = %d AND Type = 1
        ", $hubspot_deal_id));

        if (empty($article_ids)) return [
            'modification_plan' => false,
            'drawings_ok' => false
        ];

        $modification_plan = false;
        $drawings_ok = true;

        foreach ($article_ids as $article_id) {
            // Dernière entrée historique liée à cet article
            $last_entry = $this->wpdb->get_var($this->wpdb->prepare("
                SELECT ClassCss 
                FROM {$this->table_historique} 
                WHERE Historique = %d 
                ORDER BY Id DESC 
                LIMIT 1
            ", $article_id));

            if ($last_entry === 'drawingModification') {
                $modification_plan = true;
            }

            // Vérifie qu'il existe au moins une entrée product_drawing
            $has_drawing = $this->wpdb->get_var($this->wpdb->prepare("
                SELECT COUNT(*) 
                FROM {$this->table_historique} 
                WHERE Historique = %d AND ClassCss = 'product_drawing'
            ", $article_id));

            if (!$has_drawing) {
                $drawings_ok = false;
            }
        }

        return [
            'modification_plan' => $modification_plan,
            'drawings_ok' => $drawings_ok
        ];
    }

    public function check_drawing_approval_status($hubspot_deal_id) {
        $count_total = $this->wpdb->get_var($this->wpdb->prepare("
            SELECT COUNT(*) 
            FROM {$this->details_commande} 
            WHERE hubspot_deal_id = %d AND Type = 1
        ", $hubspot_deal_id));

        $count_approved = $this->wpdb->get_var($this->wpdb->prepare("
            SELECT COUNT(*) 
            FROM {$this->details_commande} 
            WHERE hubspot_deal_id = %d AND Type = 1 AND DrawingApproved = 1
        ", $hubspot_deal_id));

        return ($count_total > 0 && $count_total === $count_approved);
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

        $status = [];

        // Initialise les types même absents dans la requête
        foreach (['Product', 'Isol', 'Welding', 'div'] as $type) {
            $status[$type] = ['total' => 0, 'delivered' => false];
        }

        foreach ($results as $row) {
            $status[$row->type]['total'] = (int)$row->total;
            $status[$row->type]['delivered'] = ((int)$row->total === (int)$row->delivered_count) && ((int)$row->total > 0);
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

        $status = [];

        // Initialise les types même absents dans la requête
        foreach (['Product', 'Isol', 'Welding', 'div'] as $type) {
            $status[$type] = ['total' => 0, 'invoiced' => false];
        }

        foreach ($results as $row) {
            $status[$row->type]['total'] = (int)$row->total;
            $status[$row->type]['invoiced'] = ((int)$row->total === (int)$row->invoiced_count) && ((int)$row->total > 0);
        }

        return $status;
    }

    /**
     * Vérifie s'il existe au moins un article pour un projet donné
     * @param int $hubspot_deal_id
     * @return bool
     */
    public function has_at_least_one_article($hubspot_deal_id) {
        $count = $this->wpdb->get_var($this->wpdb->prepare("
            SELECT COUNT(*) 
            FROM {$this->details_commande} 
            WHERE hubspot_deal_id = %d
        ", $hubspot_deal_id));

        return ($count > 0);
    }
 
    // /**
    //  * Vérifie s'il existe au moins une soumission (ClassCss = 'submission') pour un projet
    //  * @param int $hubspot_deal_id
    //  * @return bool
    //  */
    // public function has_submission_file($hubspot_deal_id) {
    //     $sql = $this->wpdb->prepare("
    //         SELECT COUNT(*) 
    //         FROM {$this->table_historique} 
    //         WHERE hubspot_deal_id = %d 
    //         AND ClassCss = 'submission'
    //     ", $hubspot_deal_id);

    //     $count = $this->wpdb->get_var($sql);
    //     return ($count > 0);
    // }

    public function all_order_items_have_price($hubspot_deal_id) {
        

        // $table_details = $this->wpdb->prefix . 'achats_details_commande';
        // $table_fournisseur = $this->wpdb->prefix . 'achats_articles_cmd_fournisseurs';

        // On récupère tous les articles liés à ce projet
        $articles = $this->wpdb->get_results($this->wpdb->prepare("
            SELECT Id, sales_price, Type
            FROM $this->details_commande
            WHERE hubspot_deal_id = %d
        ", $hubspot_deal_id));

        foreach ($articles as $article) {
            $prix = floatval($article->sales_price);

            if ($prix > 0) {
                continue; // prix OK
            }

            // Si type = 1 (achat) et prix = 0, on va chercher dans la table fournisseur
            if ((int)$article->Type === 1) {
                $prix_fournisseur = $this->wpdb->get_var($this->wpdb->prepare("
                    SELECT UnitPrice
                    FROM $this->table_articles_fournisseur
                    WHERE IdCommandeClient = %d
                    LIMIT 1
                ", $article->Id));

                if (empty($prix_fournisseur) || floatval($prix_fournisseur) <= 0) {
                    return false; // pas de prix trouvé
                }
            } else {
                return false; // type ≠ 1 et prix manquant
            }
        }

        return true; // tous les articles ont un prix
    }


    // public function cron_check_old_envoie_plan($project) {
    //     $relances = [
    //         2  => ['telegram'],
    //         5  => ['telegram'],
    //         10 => ['telegram', 'mail'],
    //         20 => ['telegram', 'mail'],
    //         30 => ['telegram', 'mail'],
    //         50 => ['telegram', 'mail'],
    //     ];

    //     // Vérifie si SignaturePlan est déjà validé ou annulé
    //     $signature_status = $this->wpdb->get_var($this->wpdb->prepare("
    //         SELECT status_id 
    //         FROM $this->table_suivi 
    //         WHERE hubspot_deal_id = %d 
    //         AND slug_phase = 'SignaturePlan' 
    //         ORDER BY date_modification DESC 
    //         LIMIT 1
    //     ", $project['hubspot_deal_id']));

    //     if (in_array((int)$signature_status, [1, 5])) return;

    //     // Récupère la date de la dernière étape EnvoiePlanClient
    //     $date_envoie = $this->wpdb->get_var($this->wpdb->prepare("
    //         SELECT date_modification 
    //         FROM $this->table_suivi 
    //         WHERE hubspot_deal_id = %d 
    //         AND slug_phase = 'EnvoiePlanClient' 
    //         ORDER BY date_modification DESC 
    //         LIMIT 1
    //     ", $project['hubspot_deal_id']));

    //     if (!$date_envoie) return;

    //     $days_diff = (time() - strtotime($date_envoie)) / 86400;

    //     foreach (array_reverse($relances, true) as $delay => $modes) {
    //         if ($days_diff > $delay) {
                
    //             $project['delay'] = $delay;
    //             $project['link'] = "Voir le projet";
    //             foreach ($modes as $mode) {
    //                 if($mode == 'telegram')
    //                     envoie_notification('relance_plan', $project);
    //                 if($mode == 'mail')
    //                     sendReviceMail($project['hubspot_deal_id'], $project['AssociatedContactIDs']);
    //             }
    //             return;
    //         }
    //     }
    // }




 



    public function run_auto_update($hubspot_deal_id = null, $project = null) {
        
        $project_repo = new ISPAG_Projet_Repository();
        $suivis = new ISPAG_Projet_Suivi();

        // Si aucun ID fourni, on récupère tous les projets
        if (empty($hubspot_deal_id)) {
            $response = apply_filters('ispag_get_projects_or_offers', null, false, null, false, null, 0, 200);
            $all_projects = isset($response['results']) ? $response['results'] : [];
            
            foreach ($all_projects as $project) {
                // ON FORCE LE CAST EN INT ICI AUSSI
                $did = 0;
                if (is_object($project->hubspot_deal_id)) {
                    $did = (int) ($project->hubspot_deal_id->hubspot_deal_id ?? 0);
                } else {
                    $did = (int) $project->hubspot_deal_id;
                }

                if ($did > 0) {
                    $this->run_auto_update($did); 
                }
            }
            return;
        }

        // Si on n'a pas passé l'objet $project, on le cherche (le cache statique du repo aidera ici aussi)
        if (null === $project) {
            $project = apply_filters('ispag_get_project_by_deal_id', null, $hubspot_deal_id);
        }

        if (!$project) return;
        
        $isQotation = !empty($project->isQotation);     
        
        $all_statuses  = $suivis->get_current_status($hubspot_deal_id);
        

        
// \1('Update status ' . $hubspot_deal_id);
        foreach ($all_statuses as $status) {

            // On va controler qu\'il y ai au moins 1 article
            $slug_phase = 'no_article';
            if ($status->SlugPhase === $slug_phase) {
                $array_do_not_control = ["NaN"];
                if ($status && !in_array($status->Statut, $array_do_not_control)) {
                    if ($this->has_at_least_one_article($hubspot_deal_id)) {
                        $status_id = 1;
                    }
                    else{
                        $status_id = 10;
                    }
                    $suivis->update_phase_status($hubspot_deal_id, $slug_phase, $status_id);
                }
                // break;
            }

            // On va controler qu\'il y ai une demande client
            $slug_phase = 'customer_request';
            $array_do_not_control = ["Done", "NaN"];
            if ($status->SlugPhase === $slug_phase) {
                if ($status && !in_array($status->Statut, $array_do_not_control)) {
                    if ($this->has_document_type($hubspot_deal_id, ['submission',  'request_supplier_quotation'])) {
                        $status_id = 1;
                    }
                    else{
                        $status_id = 10;
                    }
                    $suivis->update_phase_status($hubspot_deal_id, $slug_phase, $status_id);
                }
                // break;
            }

            // On va controler qu\'il y ai une demande client
            $slug_phase = 'competitor_in_request';
            $array_do_not_control = ["Done", "NaN"];
            if ($status->SlugPhase === $slug_phase) {
                if ($status && !in_array($status->Statut, $array_do_not_control)) {
// \1('Auto status check competitor_in_request : ' . $project->EnSoumission);
                    $status_id = !empty($project->EnSoumission) ? 1 : 10;
                    $suivis->update_phase_status($hubspot_deal_id, $slug_phase, $status_id);
                }
                // break;
            }

            // On va controler le champs get_customer_order
            $slug_phase = 'get_customer_order';
            if ($status->SlugPhase === $slug_phase) {
                $array_do_not_control = ["Done", "NaN"];
                if ($status && !in_array($status->Statut, $array_do_not_control)) {
                    if ($this->has_document_type($hubspot_deal_id, ['customer_order', 'supplier_order'])) {
                        $status_id = 1;
                    }
                    else{
                        $status_id = 10;
                    }
                    $suivis->update_phase_status($hubspot_deal_id, $slug_phase, $status_id);
                }
                // break;
            }

            // On va controler le champs Supplier order
            $slug_phase = 'CmdFournisseur';
            if ($status->SlugPhase === $slug_phase) {
                $array_do_not_control = ["Done", "NaN"];
                if ($status && !in_array($status->Statut, $array_do_not_control)) {
                    if ($this->check_all_articles_ordered($hubspot_deal_id)) {
                        $status_id = 1;
                    }
                    else{
                        $status_id = 10;
                    }
                    $suivis->update_phase_status($hubspot_deal_id, $slug_phase, $status_id);
                }
                // break;
            }

            // On va controler si on a reçu tous les plans signés
            $slug_phase = 'SignaturePlan';
            if ($status->SlugPhase === $slug_phase) {
                $array_do_not_control = ["NaN"];
                if ($status && !in_array($status->Statut, $array_do_not_control)) {
                    if ($this->check_drawing_approval_status($hubspot_deal_id)) {
                        $status_id = 1;
                    }
                    else{
                        $status_id = 10;
                    }
                    $suivis->update_phase_status($hubspot_deal_id, $slug_phase, $status_id);
                }
                // break;
            }
            
            // On va controler si on a reçu tous a été livré
            $slug_phase = 'Delivered';
            // if ($status->SlugPhase === $slug_phase) {
            if (str_ends_with($status->SlugPhase, $slug_phase)) {
                $array_do_not_control = ["NaN"];
                if ($status && !in_array($status->Statut, $array_do_not_control)) {
                    $delivery_status = $this->get_delivery_status_by_type($hubspot_deal_id);

                    foreach ($delivery_status as $type => $status_loop) {
                        if (!isset($status_loop['total']) || $status_loop['total'] == 0) {
                            $status_id = 5;
                        } elseif ($status_loop['delivered']) {
                            $status_id = 1;
                        } else {
                            $status_id = 10;
                        }
                        $suivis->update_phase_status($hubspot_deal_id, $type.$slug_phase, $status_id);
                   }


                }
                // break;
            }

            // On va controler si on a reçu tous a été facturé
            $slug_phase = 'Invoice';
            // if ($status->SlugPhase === $slug_phase) {
            if (str_ends_with($status->SlugPhase, $slug_phase)) {
                $array_do_not_control = ["Done", "NaN"];
                if ($status && !in_array($status->Statut, $array_do_not_control)) {
                    $delivery_status = $this->get_invoice_status_by_type($hubspot_deal_id);

                    foreach ($delivery_status as $type => $status_loop) {
                        if (!isset($status_loop['total']) || $status_loop['total'] == 0) {
                            $status_id = 5;
                        } elseif ($status_loop['invoiced']) {
                            $status_id = 1;
                        } else {
                            $status_id = 10;
                        }
                        $suivis->update_phase_status($hubspot_deal_id, $type.$slug_phase, $status_id);
                   }


                }
                // break;
            }

            // On va controler si on a reçu tous les plans
            $slug_phase = 'PlanFournisseur';
            if ($status->SlugPhase === $slug_phase) {
                $array_do_not_control = ["NaN"];
                if ($status && !in_array($status->Statut, $array_do_not_control)) {
                    $statusCkeck = $this->check_drawing_status($hubspot_deal_id);
                    // echo'<pre>';
                    // var_dump($status);
                    // echo'</pre>';
                    if ($statusCkeck['modification_plan']) {
                        // ⚠️ Une modification de plan est à faire
                        $suivis->update_phase_status($hubspot_deal_id, 'EnvoiePlanClient', 11);
                        $status_id = 11;
                    } elseif ($statusCkeck['drawings_ok']) {
                        // ✅ Tous les dessins sont présents
                        $status_id = 1;
                    } else {
                        // ❌ Des dessins manquent
                        $status_id = 10;
                    }
                    
                    $suivis->update_phase_status($hubspot_deal_id, $slug_phase, $status_id);
                }
                // break;
            }

            // On va controler qu\'il y ai au moins 1 article
            $slug_phase = 'all_items_have_sales_price';
            if ($status->SlugPhase === $slug_phase) {
                $array_do_not_control = ["NaN"];
                if ($status && !in_array($status->Statut, $array_do_not_control)) {
                    if ($this->all_order_items_have_price($hubspot_deal_id)) {
                        $status_id = 1;
                    }
                    else{
                        $status_id = 10;
                    }
                    $suivis->update_phase_status($hubspot_deal_id, $slug_phase, $status_id);
                }
                // break;
            }

            
        
        }    
        
        //Si toutes les étapes sont validées, on clôture le projet
        $suivis = new ISPAG_Projet_Suivi();
        $suivis->close_project_if_all_steps_completed($hubspot_deal_id, $isQotation);
    }

    public function cron_update_all_project_statuses() {
        $results = $this->wpdb->get_col("
            SELECT hubspot_deal_id 
            FROM {$this->table_liste_commande}
            WHERE project_status = 1
        ");

        foreach ($results as $hubspot_deal_id) {
            $this->run_auto_update($hubspot_deal_id);
        }
    }  
    

    public function check_plan_delays() {
        global $wpdb;
        $wpdb->flush();

        $table_projets = $wpdb->prefix . 'achats_liste_commande';
        $table_phase = $wpdb->prefix . 'achats_suivi_phase_commande';
        $table_meta = 'wor9711_achats_project_meta';

        // 1. Récupération des projets actifs
        $projects = $wpdb->get_results("
            SELECT hubspot_deal_id, ObjetCommande 
            FROM $table_projets 
            WHERE (isQotation IS NULL OR isQotation = 0) 
            AND project_status = 1
        ");

        if (empty($projects)) return;

        $today = new DateTime();

        foreach ($projects as $project) {
            $deal_id = (int) $project->hubspot_deal_id;

            // 2. Skip si déjà signé (status 1 ou 5)
            $has_signature = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $table_phase WHERE hubspot_deal_id = %d AND slug_phase = 'SignaturePlan' AND status_id IN (1, 5)",
                $deal_id
            ));
            if ($has_signature > 0) continue;

            // 3. Date du dernier envoi de plan
            $date_sent_str = $wpdb->get_var($wpdb->prepare(
                "SELECT MAX(date_modification) FROM $table_phase WHERE hubspot_deal_id = %d AND slug_phase = 'EnvoiePlanClient' AND status_id = 1",
                $deal_id
            ));

            if (!$date_sent_str) continue;

            try {
                $sent_date = new DateTime($date_sent_str);
                $interval = $today->diff($sent_date);
                $diff_days = (int)$interval->format('%a');

                // Sécurité date futur
                if ($interval->invert == 0 && $diff_days > 0) continue;

                // --- GESTION DE LA RELANCE VIA LA NOUVELLE TABLE META ---
                
                // On récupère la date de la dernière relance
                $last_revive = $wpdb->get_var($wpdb->prepare(
                    "SELECT meta_value FROM $table_meta WHERE post_id = %d AND meta_key = '_ispag_last_plan_revive' LIMIT 1",
                    $deal_id
                ));

                $days_since_last_revive = 999;
                if ($last_revive) {
                    $last_revive_date = new DateTime($last_revive);
                    $days_since_last_revive = (int)$today->diff($last_revive_date)->format('%a');
                }

                // On ne relance que si on a au moins 6 jours d'écart avec la précédente relance
                if ($days_since_last_revive < 6) continue;

                // 4. Seuils de déclenchement
                if ($diff_days >= 7) {
                    
                    // ACTION : TELEGRAM
                    do_action('ispag_send_telegram_notification', null, 'reviveProjectSign', true, true, $deal_id, true);
                    
                    // ACTION : EMAIL (si >= 14 jours)
                    if ($diff_days >= 14) {
                        do_action('ispag_send_mail_from_slug',null,  $deal_id, 'reviveProjectSign');
                    }

                    // 5. ENREGISTREMENT DE LA RELANCE DANS LA TABLE META
                    $this->update_project_specific_meta($deal_id, '_ispag_last_plan_revive', $today->format('Y-m-d'));
                }

            } catch (Exception $e) {
                // error_log("Erreur calcul date deal $deal_id : " . $e->getMessage());
            }
        }
    }

    /**
     * Helper pour mettre à jour la table meta personnalisée
     */
    private function update_project_specific_meta($deal_id, $key, $value) {
        global $wpdb;
        $table_meta = 'wor9711_achats_project_meta';

        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT meta_id FROM $table_meta WHERE post_id = %d AND meta_key = %s",
            $deal_id, $key
        ));

        if ($exists) {
            $wpdb->update(
                $table_meta,
                array('meta_value' => $value),
                array('post_id' => $deal_id, 'meta_key' => $key)
            );
        } else {
            $wpdb->insert(
                $table_meta,
                array(
                    'post_id' => $deal_id,
                    'meta_key' => $key,
                    'meta_value' => $value
                )
            );
        }
    }
}
