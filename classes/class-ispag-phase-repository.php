<?php

class ISPAG_Phase_Repository {
    private $wpdb;
    private $table_phases;
    private $table_status;
    private $table_meta;

    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->table_phases = $wpdb->prefix . 'achats_slug_phase';
        $this->table_status = $wpdb->prefix . 'achats_suivi_phase_commande';
        $this->table_meta = $wpdb->prefix . 'achats_meta_phase_commande';
    }

    public function get_project_phases($hubspot_deal_id, $isQuotation = false) {

        $filter_quotation = $isQuotation ? "WHERE p.display_on_qotation = 1" : "";
        
        $query = "
            SELECT 
                p.SlugPhase,
                p.TitrePhase,
                p.VisuClient,
                IFNULL(m.Nom, def.Nom) AS statut_nom, 
                IFNULL(m.Couleur, def.Couleur) AS statut_couleur,
                IFNULL(suivi.date_modification, '') AS date_modification,
                p.Ordre,
                p.Color,
                suivi.status_id,
                
                
                m.ClasseCss as statut_css
                
            FROM {$this->table_phases} p
            
            LEFT JOIN (
                SELECT s1.slug_phase, s1.status_id, s1.date_modification
                FROM {$this->table_status} s1
                INNER JOIN (
                    SELECT slug_phase, MAX(date_modification) AS max_date
                    FROM {$this->table_status}
                    WHERE hubspot_deal_id = %d
                    GROUP BY slug_phase
                ) s2 ON s1.slug_phase = s2.slug_phase AND s1.date_modification = s2.max_date
                WHERE s1.hubspot_deal_id = %d
            ) AS suivi ON suivi.slug_phase = p.SlugPhase

            LEFT JOIN {$this->table_meta} m ON m.Id = suivi.status_id
            LEFT JOIN {$this->table_meta} AS def ON def.Id = 10
            $filter_quotation
            ORDER BY p.Ordre ASC
        ";
        return $this->wpdb->get_results($this->wpdb->prepare($query, $hubspot_deal_id, $hubspot_deal_id));
    }
}

// LEFT JOIN {$this->table_status} suivi ON suivi.slug_phase = p.SlugPhase AND suivi.hubspot_deal_id = %d
        // $filter_quotation = $isQuotation ? "WHERE phase.display_on_qotation = 1" : "";


        // $query = $this->wpdb->get_results($this->wpdb->prepare("
        //     SELECT 
        //         phase.SlugPhase, 
        //         phase.TitrePhase, 
        //         phase.VisuClient,
        //         IFNULL(status.Nom, def.Nom) AS Statut, 
        //         IFNULL(status.Couleur, def.Couleur) AS Couleur,
        //         IFNULL(suivi.date_modification, '') AS date_modification
        //     FROM {$this->table_phases} AS phase
        //     LEFT JOIN (
        //         SELECT s1.slug_phase, s1.status_id, s1.date_modification
        //         FROM {$this->table_status} s1
        //         INNER JOIN (
        //             SELECT slug_phase, MAX(date_modification) AS max_date
        //             FROM {$this->table_status}
        //             WHERE hubspot_deal_id = %d
        //             GROUP BY slug_phase
        //         ) s2 ON s1.slug_phase = s2.slug_phase AND s1.date_modification = s2.max_date
        //         WHERE s1.hubspot_deal_id = %d
        //     ) AS suivi ON suivi.slug_phase = phase.SlugPhase
        //     LEFT JOIN {$this->table_meta} AS status ON status.Id = suivi.status_id
        //     LEFT JOIN {$this->table_meta} AS def ON def.Id = 10
        //     $filter_quotation
        //     ORDER BY phase.Ordre ASC
        // ", $hubspot_deal_id, $hubspot_deal_id));

        
        // return $this->wpdb->get_results($this->wpdb->prepare($query, $hubspot_deal_id));