<?php
defined('ABSPATH') || exit;
/**
 * Classe de gestion du calendrier des livraisons ISPAG
 *
 * - Navigation AJAX (sans rechargement de page, URL mise à jour via pushState)
 * - Filtres par type de prestation + recherche instantanée (côté client)
 * - Vue Mois / Vue Liste
 */
class ISPAG_Calendar_Livraisons {

    protected static $instance = null;
    protected $project_cache = [];

    public static function init() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        add_shortcode('ispag_calendar_livraisons', [self::$instance, 'shortcode_calendar']);
        add_action('wp_ajax_ispag_calendar_month', [self::$instance, 'ajax_month']);
    }

    protected function user_can_view() {
        return current_user_can('manage_options') || current_user_can('manage_order');
    }

    protected function sanitize_period($month, $year) {
        $month = (int) $month;
        $year  = (int) $year;
        if ($month < 1 || $month > 12)     $month = (int) date('n');
        if ($year < 2000 || $year > 2100)  $year  = (int) date('Y');
        return [$month, $year];
    }

    /* ------------------------------------------------------------------ */
    /*  Shortcode : coque de la page (le contenu du mois est chargé/AJAX)   */
    /* ------------------------------------------------------------------ */
    public function shortcode_calendar($atts) {
        if ( ! current_user_can( 'read_orders' ) ) {
            return '<div class="ispag-alert ispag-alert-danger">
                        <i class="dashicons dashicons-lock"></i> 
                        <strong>' . esc_html__( 'Restricted access', 'ispag-crm' ) . ' :</strong> ' . 
                         esc_html__( 'You do not have the necessary rights to view this order.', 'ispag-crm' ) . '<br/>
                        <a href ="'. wp_login_url( get_permalink() ) . '">' . esc_html__( 'To login page', 'ispag-crm' ) . '</a>
                    </div>';
        }

        if (!$this->user_can_view()) {
            return '<div class="ispag-notice error">' . __('restricted access.', 'creation-reservoir') . '</div>';
        }

        list($month, $year) = $this->sanitize_period(
            $_GET['cal_month'] ?? date('n'),
            $_GET['cal_year']  ?? date('Y')
        );

        $this->enqueue_assets();

        $nav  = '<div class="ispag-calendar-nav-container">';
        $nav .= '<div class="calendar-nav-left">';
        $nav .= '<button type="button" class="ispag-btn-today" data-cal-today>' . esc_html__('Today', 'creation-reservoir') . '</button>';
        $nav .= '</div>';
        $nav .= '<div class="calendar-nav-center">';
        $nav .= '<button type="button" class="nav-arrow" data-cal-prev title="' . esc_attr__('Previous', 'creation-reservoir') . '"><span class="dashicons dashicons-arrow-left-alt2"></span></button>';
        $nav .= '<h2 data-cal-title>' . esc_html(date_i18n('F Y', mktime(0, 0, 0, $month, 1, $year))) . '</h2>';
        $nav .= '<button type="button" class="nav-arrow" data-cal-next title="' . esc_attr__('Next', 'creation-reservoir') . '"><span class="dashicons dashicons-arrow-right-alt2"></span></button>';
        $nav .= '</div>';
        $nav .= '<div class="calendar-nav-right">';
        $nav .= '<div class="ispag-cal-viewswitch" role="group">';
        $nav .= '<button type="button" class="is-active" data-cal-view="month">' . esc_html__('Mois', 'creation-reservoir') . '</button>';
        $nav .= '<button type="button" data-cal-view="list">' . esc_html__('Liste', 'creation-reservoir') . '</button>';
        $nav .= '</div>';
        $nav .= '</div>';
        $nav .= '</div>';

        $toolbar  = '<div class="ispag-cal-toolbar">';
        $toolbar .= '<input type="search" class="ispag-cal-search" data-cal-search placeholder="' . esc_attr__('Rechercher un projet…', 'creation-reservoir') . '" autocomplete="off">';
        $toolbar .= '<div class="ispag-cal-legend" data-cal-legend></div>';
        $toolbar .= '</div>';

        return sprintf(
            '<div class="ispag-calendar-wrapper" data-month="%d" data-year="%d" data-base="%s">%s%s<div class="ispag-cal-body" aria-live="polite">%s</div></div>',
            $month,
            $year,
            esc_url(get_permalink()),
            $nav,
            $toolbar,
            $this->render_month_body($month, $year)
        );
    }

    protected function enqueue_assets() {
        $rel = '../assets/js/calendar-livraisons.js';
        wp_enqueue_script(
            'ispag-calendar-livraisons',
            plugin_dir_url(__FILE__) . $rel,
            [],
            filemtime(plugin_dir_path(__FILE__) . $rel),
            true
        );
        wp_localize_script('ispag-calendar-livraisons', 'ispagCalendar', [
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('ispag_calendar'),
            'i18n'    => [
                'error'  => __('Impossible de charger le calendrier.', 'creation-reservoir'),
                'more'   => __('autres', 'creation-reservoir'),
                'none'   => __('Aucune livraison ne correspond à vos filtres.', 'creation-reservoir'),
                'close'  => __('Fermer', 'creation-reservoir'),
            ],
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  AJAX                                                               */
    /* ------------------------------------------------------------------ */
    public function ajax_month() {
        check_ajax_referer('ispag_calendar', 'nonce');
        if (!$this->user_can_view()) {
            wp_send_json_error(['message' => 'forbidden'], 403);
        }

        list($month, $year) = $this->sanitize_period($_POST['month'] ?? 0, $_POST['year'] ?? 0);

        wp_send_json_success([
            'month' => $month,
            'year'  => $year,
            'title' => date_i18n('F Y', mktime(0, 0, 0, $month, 1, $year)),
            'html'  => $this->render_month_body($month, $year),
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  Données                                                            */
    /* ------------------------------------------------------------------ */
    protected function get_project($deal_id) {
        if (!array_key_exists($deal_id, $this->project_cache)) {
            $this->project_cache[$deal_id] = apply_filters('ispag_get_project_by_deal_id', null, $deal_id);
        }
        return $this->project_cache[$deal_id];
    }

    /** URL de l'icône du type (image choisie, sinon icône du plugin), ou ''. */
    protected function type_icon($ev) {
        if (!class_exists('ISPAG_Type_Icons')) return '';
        static $cache = [];
        $id = (int) $ev->type_id;
        if (!isset($cache[$id])) {
            $cache[$id] = (string) ISPAG_Type_Icons::image_url((object) [
                'Id'         => $id,
                'prestation' => $ev->prestation,
                'image'      => $ev->type_image,
            ], 'thumbnail');
        }
        return $cache[$id];
    }

    /** Pastille ronde avec l'icône de la prestation. */
    protected function icon_html($url) {
        return $url ? '<span class="evt-icon"><img src="' . esc_url($url) . '" alt="" loading="lazy"></span>' : '';
    }

    /**
     * Retourne les événements du mois indexés par jour (Y-m-d).
     * Un événement = une prestation d'un projet (dédoublonné par jour).
     */
    protected function get_events_by_day($month, $year) {
        global $wpdb;

        $first_day_ts = mktime(0, 0, 0, $month, 1, $year);
        $days_in_month = (int) date('t', $first_day_ts);
        $last_day_ts  = mktime(23, 59, 59, $month, $days_in_month, $year);

        $results = $wpdb->get_results($wpdb->prepare("
            SELECT d.Id, d.TimestampDateDeLivraison, d.TimestampDateDeLivraisonFin, d.hubspot_deal_id,
                   d.Type AS type_id, t.color, t.prestation, t.image AS type_image
            FROM {$wpdb->prefix}achats_details_commande d
            LEFT JOIN {$wpdb->prefix}achats_type_prestations t ON d.Type = t.Id
            WHERE (d.TimestampDateDeLivraison <= %d AND d.TimestampDateDeLivraisonFin >= %d)
               OR (d.TimestampDateDeLivraison BETWEEN %d AND %d)
            ORDER BY d.TimestampDateDeLivraison ASC
        ", $last_day_ts, $first_day_ts, $first_day_ts, $last_day_ts));

        $events_by_day = [];
        $seen = [];

        foreach ($results as $ev) {
            if (empty($ev->TimestampDateDeLivraison)) continue;

            $start = max((int) $ev->TimestampDateDeLivraison, $first_day_ts);
            $end   = min((int) ($ev->TimestampDateDeLivraisonFin ?: $ev->TimestampDateDeLivraison), $last_day_ts);

            // Itération par jour calendaire (robuste aux changements d'heure)
            $cursor = new DateTime('@' . $start);
            $cursor->setTimezone(wp_timezone());
            $cursor->setTime(0, 0, 0);
            $limit = (new DateTime('@' . $end))->setTimezone(wp_timezone())->format('Y-m-d');

            while ($cursor->format('Y-m-d') <= $limit) {
                $day = $cursor->format('Y-m-d');
                $key = $day . '|' . $ev->hubspot_deal_id . '|' . $ev->type_id;
                if (!isset($seen[$key])) {
                    $seen[$key] = true;

                    $project = $this->get_project($ev->hubspot_deal_id);
                    $name    = !empty($project->ObjetCommande) ? $project->ObjetCommande : 'Projet #' . $ev->hubspot_deal_id;

                    $events_by_day[$day][] = [
                        'deal'       => $ev->hubspot_deal_id,
                        'type_id'    => (int) $ev->type_id,
                        'prestation' => $ev->prestation ?: __('Livraison', 'creation-reservoir'),
                        'color'      => !empty($ev->color) ? $ev->color : '#c3c4c7',
                        'icon'       => $this->type_icon($ev),
                        'name'       => $name,
                        'url'        => $project->project_url ?? '#',
                        'multi'      => ($start !== $end),
                    ];
                }
                $cursor->modify('+1 day');
            }
        }

        return $events_by_day;
    }

    /* ------------------------------------------------------------------ */
    /*  Rendu                                                              */
    /* ------------------------------------------------------------------ */
    protected function render_event($e, $compact = false) {
        return sprintf(
            '<a href="%s" class="delivery-event%s" style="--evt-color: %s" title="%s" data-type="%d" data-search="%s">
                %s
                <span class="evt-text">
                    <span class="evt-prestation">%s</span>
                    <span class="evt-name">%s</span>
                </span>
            </a>',
            esc_url($e['url']),
            $e['multi'] ? ' is-multiday' : '',
            esc_attr($e['color']),
            esc_attr($e['prestation'] . ' : ' . $e['name']),
            $e['type_id'],
            esc_attr(mb_strtolower($e['prestation'] . ' ' . $e['name'])),
            $this->icon_html($e['icon']),
            esc_html($e['prestation']),
            esc_html($e['name'])
        );
    }

    public function render_month_body($month, $year) {
        if (!$this->user_can_view()) {
            return '<div class="ispag-notice error">' . __('restricted access.', 'creation-reservoir') . '</div>';
        }

        $first_day_ts  = mktime(0, 0, 0, $month, 1, $year);
        $days_in_month = (int) date('t', $first_day_ts);
        $today_str     = date('Y-m-d');
        $events_by_day = $this->get_events_by_day($month, $year);

        // Légende (types réellement présents ce mois-ci)
        $legend = [];
        foreach ($events_by_day as $evs) {
            foreach ($evs as $e) {
                $legend[$e['type_id']] = ['label' => $e['prestation'], 'color' => $e['color'], 'icon' => $e['icon']];
            }
        }
        $legend_html = '';
        foreach ($legend as $id => $l) {
            $legend_html .= sprintf(
                '<button type="button" class="ispag-cal-chip" data-type="%d" style="--evt-color:%s" aria-pressed="true">%s%s</button>',
                $id, esc_attr($l['color']), $this->icon_html($l['icon']), esc_html($l['label'])
            );
        }

        $dows = ['Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam', 'Dim'];

        /* ---- Vue mois ---- */
        $html  = '<template data-cal-legend-tpl>' . $legend_html . '</template>';
        $html .= '<div class="ispag-calendar-container ispag-cal-view-month">';
        $html .= '<table class="ispag-calendar-table"><thead><tr>';
        foreach ($dows as $dow) $html .= "<th>$dow</th>";
        $html .= '</tr></thead><tbody><tr>';

        $start_week_day = (int) date('N', $first_day_ts);
        for ($i = 1; $i < $start_week_day; $i++) $html .= '<td class="empty-day"></td>';

        $current_week_day = $start_week_day;
        for ($day = 1; $day <= $days_in_month; $day++) {
            if ($current_week_day > 7) {
                $html .= '</tr><tr>';
                $current_week_day = 1;
            }
            $day_str = sprintf('%04d-%02d-%02d', $year, $month, $day);
            $classes = 'calendar-day';
            if ($day_str === $today_str) $classes .= ' is-today';
            if ($current_week_day >= 6)  $classes .= ' is-weekend';

            $html .= '<td class="' . $classes . '" data-date="' . $day_str . '" data-dow="' . $dows[$current_week_day - 1] . '">';
            $html .= '<div class="day-header"><span class="day-num">' . $day . '</span><span class="day-dow">' . $dows[$current_week_day - 1] . '</span></div>';
            $html .= '<div class="day-events">';
            foreach ($events_by_day[$day_str] ?? [] as $e) {
                $html .= $this->render_event($e);
            }
            $html .= '</div></td>';
            $current_week_day++;
        }
        while ($current_week_day <= 7) {
            $html .= '<td class="empty-day"></td>';
            $current_week_day++;
        }
        $html .= '</tr></tbody></table></div>';

        /* ---- Vue liste (jours avec livraisons uniquement) ---- */
        $html .= '<div class="ispag-cal-view-list" hidden>';
        if (empty($events_by_day)) {
            $html .= '<p class="ispag-cal-empty">' . esc_html__('Aucune livraison ce mois-ci.', 'creation-reservoir') . '</p>';
        }
        ksort($events_by_day);
        foreach ($events_by_day as $day_str => $evs) {
            $ts = strtotime($day_str);
            $is_today = ($day_str === $today_str) ? ' is-today' : '';
            $html .= '<section class="ispag-cal-list-day' . $is_today . '" data-date="' . $day_str . '">';
            $html .= '<h3>' . esc_html(date_i18n('l j F', $ts)) . '</h3><div class="day-events">';
            foreach ($evs as $e) $html .= $this->render_event($e);
            $html .= '</div></section>';
        }
        $html .= '<p class="ispag-cal-empty ispag-cal-nomatch" hidden>' . esc_html__('Aucune livraison ne correspond à vos filtres.', 'creation-reservoir') . '</p>';
        $html .= '</div>';

        return $html;
    }

    /** Conservé pour compatibilité avec d'éventuels appels externes. */
    public function render_monthly_calendar($month, $year) {
        return $this->render_month_body($month, $year);
    }
}

ISPAG_Calendar_Livraisons::init();
