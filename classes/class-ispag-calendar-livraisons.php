<?php
/**
 * Classe de gestion du calendrier des livraisons ISPAG
 */
class ISPAG_Calendar_Livraisons {
    
    protected static $instance = null;

    public static function init() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        add_shortcode('ispag_calendar_livraisons', [self::$instance, 'shortcode_calendar']);
    }

    public function shortcode_calendar($atts) {
        $month = isset($_GET['cal_month']) ? (int)$_GET['cal_month'] : (int)date('n');
        $year  = isset($_GET['cal_year'])  ? (int)$_GET['cal_year']  : (int)date('Y');

        if ($month < 1 || $month > 12) $month = date('n');
        if ($year < 2000 || $year > 2100) $year = date('Y');

        // Calcul des mois Précédent / Suivant
        $prev_month = $month - 1; $prev_year = $year;
        if ($prev_month < 1) { $prev_month = 12; $prev_year--; }

        $next_month = $month + 1; $next_year = $year;
        if ($next_month > 12) { $next_month = 1; $next_year++; }

        $current_url = get_permalink();
        
        // URLs de navigation
        $prev_url    = add_query_arg(['cal_month' => $prev_month, 'cal_year' => $prev_year], $current_url);
        $next_url    = add_query_arg(['cal_month' => $next_month, 'cal_year' => $next_year], $current_url);
        $today_url   = add_query_arg(['cal_month' => date('n'), 'cal_year' => date('Y')], $current_url);

        // Construction du HTML de la barre de navigation
        $nav_html  = '<div class="ispag-calendar-nav-container">';
            $nav_html .= '<div class="calendar-nav-left">';
                $nav_html .= '<a href="'.esc_url($today_url).'" class="ispag-btn-today">' . __('Aujourd\'hui', 'creation-reservoir') . '</a>';
            $nav_html .= '</div>';

            $nav_html .= '<div class="calendar-nav-center">';
                $nav_html .= '<a href="'.esc_url($prev_url).'" class="nav-arrow" title="'.__('Précédent', 'creation-reservoir').'"><span class="dashicons dashicons-arrow-left-alt2"></span></a>';
                $nav_html .= '<h2>' . date_i18n('F Y', mktime(0, 0, 0, $month, 1, $year)) . '</h2>';
                $nav_html .= '<a href="'.esc_url($next_url).'" class="nav-arrow" title="'.__('Suivant', 'creation-reservoir').'"><span class="dashicons dashicons-arrow-right-alt2"></span></a>';
            $nav_html .= '</div>';
            
            $nav_html .= '<div class="calendar-nav-right"></div>'; // Espace pour équilibrer ou filtres futurs
        $nav_html .= '</div>';

        return '<div class="ispag-calendar-wrapper">' . $nav_html . $this->render_monthly_calendar($month, $year) . '</div>';
    }

    public function render_monthly_calendar($month, $year) {
        if (!current_user_can('manage_options') && !current_user_can('manage_order')) {
            return '<div class="ispag-notice error">' . __('Accès refusé.', 'creation-reservoir') . '</div>';
        }

        global $wpdb;
        $first_day_ts = mktime(0, 0, 0, $month, 1, $year);
        $daysInMonth  = date('t', $first_day_ts);
        $last_day_ts   = mktime(23, 59, 59, $month, $daysInMonth, $year);
        $today_str    = date('Y-m-d');

        // On récupère les livraisons avec les infos de prestation (couleurs et noms)
        $results = $wpdb->get_results($wpdb->prepare("
            SELECT d.Id, d.TimestampDateDeLivraison, d.TimestampDateDeLivraisonFin, d.hubspot_deal_id, 
                t.color, t.prestation
            FROM {$wpdb->prefix}achats_details_commande d
            LEFT JOIN {$wpdb->prefix}achats_type_prestations t ON d.Type = t.Id
            WHERE (d.TimestampDateDeLivraison <= %d AND d.TimestampDateDeLivraisonFin >= %d)
            OR (d.TimestampDateDeLivraison BETWEEN %d AND %d)
            ORDER BY d.TimestampDateDeLivraison ASC
        ", $last_day_ts, $first_day_ts, $first_day_ts, $last_day_ts));

        $events_by_day = [];
        foreach ($results as $ev) {
            $start = max($ev->TimestampDateDeLivraison, $first_day_ts);
            $end   = min($ev->TimestampDateDeLivraisonFin ?: $ev->TimestampDateDeLivraison, $last_day_ts);
            for ($ts = $start; $ts <= $end; $ts += 86400) {
                $events_by_day[date('Y-m-d', $ts)][] = $ev;
            }
        }

        $html = '<div class="ispag-calendar-container">';
        $html .= '<table class="ispag-calendar-table">';
        $html .= '<thead><tr>';
        foreach (['Lun','Mar','Mer','Jeu','Ven','Sam','Dim'] as $dow) {
            $html .= "<th>$dow</th>";
        }
        $html .= '</tr></thead><tbody><tr>';

        $start_week_day = (int)date('N', $first_day_ts);
        for ($i = 1; $i < $start_week_day; $i++) {
            $html .= '<td class="empty-day"></td>';
        }

        $current_week_day = $start_week_day;
        for ($day = 1; $day <= $daysInMonth; $day++) {
            if ($current_week_day > 7) {
                $html .= '</tr><tr>';
                $current_week_day = 1;
            }

            $current_day_str = date('Y-m-d', mktime(0, 0, 0, $month, $day, $year));
            $is_today = ($current_day_str === $today_str) ? 'is-today' : '';

            $html .= '<td class="calendar-day '.$is_today.'">';
            $html .= '<div class="day-header">' . $day . '</div>';

            if (!empty($events_by_day[$current_day_str])) {
                $seen_deals = [];
                foreach ($events_by_day[$current_day_str] as $ev) {
                    if (in_array($ev->hubspot_deal_id, $seen_deals)) continue;
                    $seen_deals[] = $ev->hubspot_deal_id;

                    $project = apply_filters('ispag_get_project_by_deal_id', null, $ev->hubspot_deal_id);
                    $project_name = !empty($project->ObjetCommande) ? $project->ObjetCommande : 'Projet #' . $ev->hubspot_deal_id;
                    $event_color = !empty($ev->color) ? $ev->color : '#e2e2e2';

                    $html .= sprintf(
                        '<a href="%s" class="delivery-event" style="--evt-color: %s" title="%s">
                            <span class="evt-prestation">%s</span>
                            <span class="evt-name">%s</span>
                        </a>',
                        esc_url($project->project_url ?? '#'),
                        esc_attr($event_color),
                        esc_attr($ev->prestation . ' : ' . $project_name),
                        esc_html($ev->prestation),
                        esc_html($project_name)
                    );
                }
            }
            $html .= '</td>';
            $current_week_day++;
        }

        while ($current_week_day <= 7) {
            $html .= '<td class="empty-day"></td>';
            $current_week_day++;
        }

        $html .= '</tr></tbody></table></div>';
        return $html;
    }
}

ISPAG_Calendar_Livraisons::init();