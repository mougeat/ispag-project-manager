<?php

class ISPAG_Projet_Suivi {
    private $wpdb;
    private $table_suivi;
    private $table_phase;
    private $table_status;
    private $table_liste_commande;
    

    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
        $prefix = $wpdb->prefix;
        $this->table_suivi  = $wpdb->prefix . 'achats_suivi_phase_commande';
        $this->table_phase  = $wpdb->prefix . 'achats_slug_phase';
        $this->table_status = $wpdb->prefix . 'achats_meta_phase_commande';
        $this->table_liste_commande = "{$prefix}achats_liste_commande";

        add_action('wp_ajax_check_project_problems', array($this, 'ispag_ajax_check_project_problems'));
    }

    

    // Récupère le statut actuel par phase
    public function get_current_status($hubspot_deal_id, $isQuotation = false) {

        $filter_quotation = $isQuotation ? "WHERE phase.display_on_qotation = 1" : "";


        $results = $this->wpdb->get_results($this->wpdb->prepare("
            SELECT 
                phase.SlugPhase, 
                phase.TitrePhase, 
                phase.VisuClient,
                IFNULL(status.Nom, def.Nom) AS Statut, 
                IFNULL(status.Couleur, def.Couleur) AS Couleur,
                IFNULL(suivi.date_modification, '') AS date_modification
            FROM {$this->table_phase} AS phase
            LEFT JOIN (
                SELECT s1.slug_phase, s1.status_id, s1.date_modification
                FROM {$this->table_suivi} s1
                INNER JOIN (
                    SELECT slug_phase, MAX(date_modification) AS max_date
                    FROM {$this->table_suivi}
                    WHERE hubspot_deal_id = %d
                    GROUP BY slug_phase
                ) s2 ON s1.slug_phase = s2.slug_phase AND s1.date_modification = s2.max_date
                WHERE s1.hubspot_deal_id = %d
            ) AS suivi ON suivi.slug_phase = phase.SlugPhase
            LEFT JOIN {$this->table_status} AS status ON status.Id = suivi.status_id
            LEFT JOIN {$this->table_status} AS def ON def.Id = 10
            $filter_quotation
            ORDER BY phase.Ordre ASC
        ", $hubspot_deal_id, $hubspot_deal_id));

        return $results;
    }

    public function is_quotation($hubspot_deal_id) {
        return (bool) $this->wpdb->get_var($this->wpdb->prepare("
            SELECT isQotation FROM {$this->table_liste_commande} WHERE hubspot_deal_id = %d
        ", $hubspot_deal_id));
    }

    

    public function get_all_statuses() {
        global $wpdb;
        $results = $wpdb->get_results("SELECT Id, Nom, Couleur FROM {$wpdb->prefix}achats_meta_phase_commande ORDER BY sort ASC");
        $statuses = [];
        foreach ($results as $row) {
            $statuses[] = [
                'id' => $row->Id,
                'name' => __($row->Nom, 'creation-reservoir'),
                'color' => $row->Couleur,
            ];
        }
        return $statuses;
    }

    

    public function preload_next_phases(array $hubspot_ids, $is_quotation = false) {
        global $wpdb;

        if (empty($hubspot_ids)) return [];

        $ids_placeholder = implode(',', array_fill(0, count($hubspot_ids), '%d'));
        
        $excluded_status = [1, 5];
        $excluded_placeholder = implode(',', array_fill(0, count($excluded_status), '%d'));

        $phase_condition = current_user_can('manage_order') ? '1=1' : 'phase.VisuClient = 1';
        $quotation_condition = $is_quotation ? 'AND phase.display_on_qotation = 1' : '';

        $args = array_merge($hubspot_ids, $hubspot_ids, $excluded_status, $hubspot_ids, $hubspot_ids, $excluded_status);

        // Base pour les sous-requêtes avec dernières modifs
        $latest_update_join = "
            LEFT JOIN (
                SELECT s1.hubspot_deal_id, s1.slug_phase, s1.status_id
                FROM {$this->table_suivi} s1
                INNER JOIN (
                    SELECT hubspot_deal_id, slug_phase, MAX(date_modification) AS max_date
                    FROM {$this->table_suivi}
                    WHERE hubspot_deal_id IN ($ids_placeholder)
                    GROUP BY hubspot_deal_id, slug_phase
                ) s2 
                ON s1.hubspot_deal_id = s2.hubspot_deal_id 
                AND s1.slug_phase = s2.slug_phase 
                AND s1.date_modification = s2.max_date
            ) last_update 
            ON last_update.slug_phase = phase.SlugPhase 
            AND last_update.hubspot_deal_id = ps.hubspot_deal_id
        ";

        // Requête finale
        $query = "
            SELECT sub.hubspot_deal_id, sub.SlugPhase, sub.TitrePhase, sub.Statut, sub.Color
            FROM (
                SELECT 
                    phase.SlugPhase, phase.Ordre, phase.TitrePhase, phase.Color, 
                    ps.hubspot_deal_id, status.Nom AS Statut
                FROM {$this->table_phase} AS phase
                CROSS JOIN (SELECT DISTINCT hubspot_deal_id FROM {$this->table_suivi} WHERE hubspot_deal_id IN ($ids_placeholder)) ps
                $latest_update_join
                LEFT JOIN {$this->table_status} status ON status.Id = last_update.status_id
                WHERE ($phase_condition) $quotation_condition
                AND (IFNULL(status.Id, 0) = 0 OR status.Id NOT IN ($excluded_placeholder))
            ) sub
            INNER JOIN (
                SELECT hubspot_deal_id, MIN(Ordre) AS min_ordre
                FROM (
                    SELECT 
                        phase.SlugPhase, phase.Ordre, ps.hubspot_deal_id
                    FROM {$this->table_phase} AS phase
                    CROSS JOIN (SELECT DISTINCT hubspot_deal_id FROM {$this->table_suivi} WHERE hubspot_deal_id IN ($ids_placeholder)) ps
                    $latest_update_join
                    LEFT JOIN {$this->table_status} status ON status.Id = last_update.status_id
                    WHERE ($phase_condition) $quotation_condition
                    AND (IFNULL(status.Id, 0) = 0 OR status.Id NOT IN ($excluded_placeholder))
                ) filtered
                GROUP BY hubspot_deal_id
            ) min_ordres 
            ON sub.hubspot_deal_id = min_ordres.hubspot_deal_id AND sub.Ordre = min_ordres.min_ordre
        ";

        // Préparation des paramètres
        


        $prepared = $wpdb->prepare($query, ...$args);
        $rows = $wpdb->get_results($prepared);
        // if (!is_array($rows)) {
        //     error_log('[ISPAG] preload_next_phases() → Résultat SQL invalide.');
        //     $rows = [];
        // }

        // Résultat par défaut (projet terminé)
        $next_phases = [];
        foreach ($hubspot_ids as $id) {
            $next_phases[$id] = (object)[
                'SlugPhase' => 'done',
                'TitrePhase' => __('Closed', 'creation-reservoir'),
                'Statut'    => '',
                'Color'     => 'success',
            ];
        }

        // Remplir avec les phases réelles si dispo
        foreach ($rows as $row) {
            $next_phases[$row->hubspot_deal_id] = $row;
        }

        return $next_phases;
    }
    


    public function close_project_if_all_steps_completed($hubspot_deal_id, $isQotation = false) {
        $statuses = $this->get_current_status($hubspot_deal_id, $isQotation);


        foreach ($statuses as $status) {
            if (!in_array($status->Statut, ["Done", "NaN"])) {
                return false; // Une étape n'est pas terminée
            }
        }

        // Toutes les étapes sont complètes, on clôture le projet
        $updated = $this->wpdb->update(
            $this->table_liste_commande,
            // Data: Définir le nouveau statut à 0 (Closed)
            ['project_status' => 0],
            // WHERE: Le projet doit correspondre au hubspot_deal_id ET son statut actuel doit être 1
            [
                'hubspot_deal_id' => $hubspot_deal_id,
                'project_status' => 1 // <-- NOUVELLE CONDITION AJOUTÉE
            ],
            // Data Format: %d pour le nouveau statut
            ['%d'],
            // WHERE Format: %d pour l'ID et %d pour l'ancien statut (1)
            ['%d', '%d'] // <-- NOUVEAU FORMAT AJOUTÉ POUR LE SECOND ÉLÉMENT WHERE
        );

        return $updated !== false;
    }

    public function get_signature_plan_age($hubspot_deal_id) {
        $result = $this->wpdb->get_var($this->wpdb->prepare("
            SELECT MAX(date_modification)
            FROM {$this->table_suivi}
            WHERE hubspot_deal_id = %d
            AND slug_phase = 'SignaturePlan'
        ", $hubspot_deal_id));

        if (!$result) {
            return null; // Aucun enregistrement trouvé
        }

        $date_modif = new \DateTime($result);
        $now = new \DateTime();
        $interval = $now->diff($date_modif);

        return $interval->days; // Retourne l'âge en jours
    }


    public function update_phase_status($deal_id, $slug_phase, $status_id){
        // Vérifie le statut actuel
        $current = $this->wpdb->get_var($this->wpdb->prepare("
            SELECT status_id 
            FROM {$this->table_suivi} 
            WHERE hubspot_deal_id = %d 
            AND slug_phase = %s 
            ORDER BY date_modification DESC 
            LIMIT 1
        ", $deal_id, $slug_phase));

        // Si le statut est le même, on ne fait rien
        if ((int)$current === (int)$status_id) {
            return false;
        }

        //Si le status est == 1, on envoie le mail Brevo
        if($status_id == 1){
            // $Mail = new ISPAG_Mail_Sender();
            // $array['template_id'] = $Mail->getBrevoTemplateId($slug_phase);
            // $array['Brevo_delay_days'] = $Mail->getBrevoDelayDays($slug_phase);
            // $array['brevo'] = ($status_id == 1 AND !empty($hubspot_deal_id)) ? brevo_send_email_with_pdf($deal_id, $array['template_id'], $array['Brevo_delay_days']) : null;
 
            do_action('ispag_send_mail_from_slug', null,  $deal_id, $slug_phase); 
            do_action('ispag_send_telegram_notification', null, $slug_phase, true, true, $deal_id, true);
        }
        
        return $this->wpdb->insert(
            $this->table_suivi,
            [
                'hubspot_deal_id' => $deal_id,
                'slug_phase' => $slug_phase,
                'status_id' => $status_id,
                'date_modification' => current_time('mysql')
            ],
            ['%d', '%s', '%d', '%s']
        );
    }

    public function ispag_ajax_check_project_problems() {
        // 1. Sécurité
        if (!current_user_can('manage_order')) {
            wp_send_json_error('Accès refusé');
        }

        $deal_id = isset($_POST['deal_id']) ? intval($_POST['deal_id']) : 0;
        if (!$deal_id) {
            wp_send_json_error('ID du projet manquant');
        }

        // 2. Requête SQL (Note l'utilisation des variables $this->table_... directement)
        // On s'assure qu'il y a bien 2 %d pour les 2 arguments à la fin
        $query = $this->wpdb->prepare("
            SELECT 
                s.slug_phase,
                p.TitrePhase,
                m.Nom as status_name,
                s.date_modification
            FROM {$this->table_suivi} s
            INNER JOIN (
                SELECT slug_phase, MAX(date_modification) as max_date
                FROM {$this->table_suivi}
                WHERE hubspot_deal_id = %d
                GROUP BY slug_phase
            ) latest ON s.slug_phase = latest.slug_phase AND s.date_modification = latest.max_date
            INNER JOIN {$this->table_phase} p ON s.slug_phase = p.SlugPhase
            INNER JOIN {$this->table_status} m ON s.status_id = m.Id
            WHERE s.hubspot_deal_id = %d 
            AND s.status_id = 6
        ", $deal_id, $deal_id);

        $problems = $this->wpdb->get_results($query);

        if (!empty($problems)) {
            $html = '<p style="color:#D21034; font-weight:bold;"><span class="dashicons dashicons-warning"></span> Attention : Des problèmes bloquants existent :</p>';
            $html .= '<ul style="margin-top:10px;">';
            foreach ($problems as $prob) {
                $html .= sprintf(
                    '<li><strong>%s</strong> (Statut: %s)</li>',
                    esc_html__($prob->TitrePhase, 'creation-reservoir'),
                    esc_html__($prob->status_name, 'creation-reservoir')
                );
            }
            $html .= '</ul>';
            wp_send_json_success(['has_problem' => true, 'html' => $html]);
        }

        wp_send_json_success(['has_problem' => false]);
    }

}