<?php

class ISPAG_Projet_Progress_Bar {
    private $wpdb;
    private $table_details;
    private $table_prestations;

    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->table_details = $wpdb->prefix . 'achats_details_commande';
        $this->table_prestations = $wpdb->prefix . 'achats_type_prestations';
    }

    public function preload_delivery_data($hubspot_deal_ids) {
        global $wpdb;

        $placeholders = implode(',', array_fill(0, count($hubspot_deal_ids), '%d'));

        $results = $wpdb->get_results(
            $wpdb->prepare("
                SELECT 
                    td.hubspot_deal_id,
                    td.TimestampDateDeLivraison,
                    td.TimestampDateDeLivraisonFin,
                    td.Livre,
                    ttp.prestation
                FROM {$this->table_details} td
                LEFT JOIN {$this->table_prestations} ttp ON ttp.Id = td.Type
                WHERE td.hubspot_deal_id IN ($placeholders)
            ", ...$hubspot_deal_ids),
            ARRAY_A
        );

        // Réorganiser les données pour un accès facile
        $grouped = [];
        foreach ($results as $row) {
            $deal_id = $row['hubspot_deal_id'];
            $type = $row['prestation'];
            $grouped[$deal_id][$type][] = $row;
        }

        return $grouped;
    }


    public function get_delivery_progress_bar_from_data($deal_id, $type, $data) {
        if (!isset($data[$deal_id][$type])) {
            return '<div class="progress-bar-wrapper"><em>&mdash;</em></div>';
        }

        $rows = $data[$deal_id][$type];
        $results = array_filter($rows, fn($r) => !empty($r['TimestampDateDeLivraisonFin']) && $r['TimestampDateDeLivraisonFin'] != 0);

        if (empty($results)) {
            return '<div class="progress-bar-wrapper"><em>' . __('No delivery date', 'creation-reservoir') . '</em></div>';
        }

        // Tout le reste reste quasiment identique à ta méthode actuelle
        
        // Cas "no delivery date" : type n’existe pas ou pas trouvé
        if (empty($results)) {
            return '<div class="progress-bar-wrapper"><em>&mdash;</em></div>';
        }

        $dates_fin = array_column($results, 'TimestampDateDeLivraisonFin');
        sort($dates_fin);
        $dates_debut = array_column($results, 'TimestampDateDeLivraison');
        sort($dates_debut);
        $first = reset($dates_debut);
        $last  = end($dates_fin);
        $year_first = date('Y', $first);
        $year_last = date('Y', $last);
        $current_year = date('Y');
        $now   = time();

        $total = $last - $first;
        $elapsed = $now - $first;

        if ($total === 0) {
            $progress = ($now >= $last) ? 100 : 0;
        } else {
            $progress = min(100, max(0, round(($elapsed / $total) * 100)));
        }

        // Vérifie combien d'articles sont livrés (avec ou sans date)
        $total_rows = $this->wpdb->get_results(
            $this->wpdb->prepare("
                SELECT td.Livre
                FROM {$this->table_details} td
                LEFT JOIN {$this->table_prestations} ttp ON ttp.Id = td.Type
                WHERE td.hubspot_deal_id = %d AND ttp.prestation = %s
            ", $deal_id, $type),
            ARRAY_A
        );

        $nb_total = count($total_rows);
        $nb_livres = 0;

        foreach ($total_rows as $row) {
            if (!empty($row['Livre'])) {
                $nb_livres++;
            }
        }

        // Vérifie s'il y a du retard
        $is_late = false;
        foreach ($results as $row) {
            if (empty($row['Livre']) && $row['TimestampDateDeLivraisonFin'] < $now) {
                $is_late = true;
            }
        }

        // Détermine la classe de couleur
        if ($nb_livres === $nb_total) {
            $progress_class = 'progress-fill';  // tout livré → vert
        } elseif ($nb_livres > 0) {
            $progress_class = 'progress-fill-orange';  // partiellement livré → orange
        } else {
            $progress_class = $is_late ? 'progress-fill-late' : 'progress-fill'; // rien livré → rouge si en retard
        }

        $progress = min(100, $progress);

        $day_first = date('d', $first);
        $month_first = date('m', $first);
        $day_last = date('d', $last);
        $month_last = date('m', $last);

        if ($first === $last) {
            $date_display = "<span>{$day_last}.{$month_last}</span>";
        } elseif ($month_first === $month_last) {
            $date_display = "<span>{$day_first}</span> - <span>{$day_last}.{$month_last}</span>";
        } else {
            $date_display = "<span>{$day_first}.{$month_first}</span> - <span>{$day_last}.{$month_last}</span>";
        }

        if ($year_last != $current_year) {
            $date_display .= " <small>({$year_last})</small>";
        }

        return "
            <div class='progress-bar-wrapper'>
                <div class='progress-bar'>
                    <div class='{$progress_class}' style='width: {$progress}%;'></div>
                </div>
                <div class='progress-bar-date'>{$date_display}</div>
            </div>
        ";
    }



/******************************* */

    public function get_delivery_progress_bar($hubspot_deal_id, $type_produit) {
        // Vérifie s’il existe des articles de ce type (avec ou sans date)
        $type_exists = $this->wpdb->get_var(
            $this->wpdb->prepare("
                SELECT COUNT(*)
                FROM {$this->table_details} td
                LEFT JOIN {$this->table_prestations} ttp ON ttp.Id = td.Type
                WHERE td.hubspot_deal_id = %d AND ttp.prestation = %s
            ", $hubspot_deal_id, $type_produit)
        );

        // Récupère uniquement les résultats avec date non nulle
        $results = $this->wpdb->get_results(
            $this->wpdb->prepare("
                SELECT td.TimestampDateDeLivraison, td.TimestampDateDeLivraisonFin, td.Livre
                FROM {$this->table_details} td
                LEFT JOIN {$this->table_prestations} ttp ON ttp.Id = td.Type
                WHERE td.hubspot_deal_id = %d AND ttp.prestation = %s
                AND td.TimestampDateDeLivraisonFin IS NOT NULL
                AND td.TimestampDateDeLivraisonFin != 0
                ORDER BY td.TimestampDateDeLivraisonFin ASC
            ", $hubspot_deal_id, $type_produit),
            ARRAY_A
        );

        // Cas "not concerned" : type existe mais aucune date dispo
        if ($type_exists > 0 && empty($results)) {
            return '<div class="progress-bar-wrapper"><em>' . __('No delivery date', 'creation-reservoir') . '</em></div>';
        }

        // Cas "no delivery date" : type n’existe pas ou pas trouvé
        if (empty($results)) {
            return '<div class="progress-bar-wrapper"><em>&mdash;</em></div>';
        }

        $dates = array_column($results, 'TimestampDateDeLivraisonFin');
        $dates_debut = array_column($results, 'TimestampDateDeLivraison');
        $first = reset($dates_debut);
        $last  = end($dates);
        $year_first = date('Y', $first);
        $year_last = date('Y', $last);
        $current_year = date('Y');
        $now   = time();

        $total = $last - $first;
        $elapsed = $now - $first;

        if ($total === 0) {
            $progress = ($now >= $last) ? 100 : 0;
        } else {
            $progress = min(100, max(0, round(($elapsed / $total) * 100)));
        }

        // Vérifie combien d'articles sont livrés (avec ou sans date)
        $total_rows = $this->wpdb->get_results(
            $this->wpdb->prepare("
                SELECT td.Livre
                FROM {$this->table_details} td
                LEFT JOIN {$this->table_prestations} ttp ON ttp.Id = td.Type
                WHERE td.hubspot_deal_id = %d AND ttp.prestation = %s
            ", $hubspot_deal_id, $type_produit),
            ARRAY_A
        );

        $nb_total = count($total_rows);
        $nb_livres = 0;

        foreach ($total_rows as $row) {
            if (!empty($row['Livre'])) {
                $nb_livres++;
            }
        }

        // Vérifie s'il y a du retard
        $is_late = false;
        foreach ($results as $row) {
            if (empty($row['Livre']) && $row['TimestampDateDeLivraisonFin'] < $now) {
                $is_late = true;
            }
        }

        // Détermine la classe de couleur
        if ($nb_livres === $nb_total) {
            $progress_class = 'progress-fill';  // tout livré → vert
        } elseif ($nb_livres > 0) {
            $progress_class = 'progress-fill-orange';  // partiellement livré → orange
        } else {
            $progress_class = $is_late ? 'progress-fill-late' : 'progress-fill'; // rien livré → rouge si en retard
        }

        $progress = min(100, $progress);

        $day_first = date('d', $first);
        $month_first = date('m', $first);
        $day_last = date('d', $last);
        $month_last = date('m', $last);

        if ($first === $last) {
            $date_display = "<span>{$day_last}.{$month_last}</span>";
        } elseif ($month_first === $month_last) {
            $date_display = "<span>{$day_first}</span> - <span>{$day_last}.{$month_last}</span>";
        } else {
            $date_display = "<span>{$day_first}.{$month_first}</span> - <span>{$day_last}.{$month_last}</span>";
        }

        if ($year_last != $current_year) {
            $date_display .= " <small>({$year_last})</small>";
        }

        return "
            <div class='progress-bar-wrapper'>
                <div class='progress-bar'>
                    <div class='{$progress_class}' style='width: {$progress}%;'>{$date_display}</div>
                </div>
            </div>
        ";
    }



}
