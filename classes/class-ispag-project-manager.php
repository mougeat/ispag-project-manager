<?php
defined('ABSPATH') or die();
 
class ISPAG_Project_Manager {
 
    public static function init() {
        add_shortcode('ispag_projets', [self::class, 'shortcode_projets']);
        add_shortcode( "b2b_get_login_url", [self::class, 'get_login_url'] );
        add_action('wp_enqueue_scripts', [self::class, 'enqueue_assets']);

        add_filter('walker_nav_menu_start_el',[self::class, 'wp_gestion_nav_replace'],10,2);

        add_action('wp_enqueue_scripts', function () {
            wp_enqueue_style(
                'ispag-main-style',
                plugins_url('../assets/css/main.css', __FILE__),
                [],
                '1.0'
            );
            wp_enqueue_style('dashicons');
        });



        // Hook CRON
        add_action('ispag_check_daily_delivery', [self::class, 'send_ispag_daily_deliveries']);
        add_action('ispag_check_project_to_invoice', [self::class, 'check_project_to_invoice']);
        add_action('ispag_check_upcoming_deliveries', [self::class, 'check_upcoming_deliveries']);
        // CRON scheduler
        add_action('wp', [self::class, 'ispag_schedul_cron_project']);
    }

    public static function enqueue_assets() {
        
        wp_enqueue_style(
            'ispag-fontawesome', 
            'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css', 
            array(), // dépendances
            '6.4.2'  // version
        );
        wp_enqueue_script(
            'wp_gestion_jquery',
            'https://ajax.googleapis.com/ajax/libs/jquery/3.7.1/jquery.min.js',
            array('jquery')
        );
        wp_enqueue_script(
            'wp_gestion_jquery_ui',
            'https://code.jquery.com/ui/1.14.0/jquery-ui.js',
            array('jquery')
        );

        

        wp_enqueue_script('ispag-scroll', plugin_dir_url(__FILE__) . '../assets/js/infinite-scroll.js', [], false, true);
        wp_localize_script('ispag-scroll', 'ajaxurl', admin_url('admin-ajax.php'));

        wp_localize_script('ispag-scroll', 'ispagVars', [
            'ajaxurl' => admin_url('admin-ajax.php'),
            'loading_text' => __('Loading', 'creation-reservoir'),
            'all_loaded_text' => __('All projects are loaded', 'creation-reservoir'),

        ]);
    }

    public static function ispag_schedul_cron_project() {
        if (!wp_next_scheduled('ispag_check_daily_delivery')) {
            wp_schedule_event(time(), 'daily', 'ispag_check_daily_delivery'); // tous les jours
        }
        if (!wp_next_scheduled('ispag_check_project_to_invoice')) {
            wp_schedule_event(time(), 'weekly', 'ispag_check_project_to_invoice'); // tous les jours
        }
        if (!wp_next_scheduled('ispag_check_upcoming_deliveries')) {
            wp_schedule_event(time(), 'weekly', 'ispag_check_upcoming_deliveries'); // tous les jours
        }
    }

    public static function get_login_url(){
        // return is_user_logged_in() ? get_home_url() . '/liste-des-projets' : get_home_url() . '/wp-login.php';
        if(is_user_logged_in()){
            return '<style>
            .not_logged_in{
                visibility:hidden;
            }
            .logged_in{
                visibility:visible ;
            }   
        </style>';
        }
        else{
            return '<style>
                .not_logged_in{
                    visibility:visible ;
                }
                .logged_in{
                    visibility:hidden;
                }  
            </style>';
        }
    }
    public static function wp_gestion_nav_replace($item_output, $item) {
        //   var_dump($item_output, $item);
        if ('[b2b_profile]' == $item->title) {
        global $my_profile; // no idea what this does?
        if (is_user_logged_in()) { 
            // return '<div class="img" data-key="profile">'.get_avatar( get_current_user_id(), 64 ).'</div>';
            $current_user = get_userdata(get_current_user_id());
            // return '<li class="nmr-administrator nmr-membre_ispag menu-item menu-item-type-custom menu-item-object-custom ">' . $current_user->display_name .'</li>';
            return '<a class="menu-item-has-children">' . $current_user->display_name . ' </a>';
        }
        }
        return $item_output;
    }
    public static function activation_hook() {
        self::init();
        self::ispag_schedul_cron_project();
    }

    public static function deactivation_hook() {
        $timestamp = wp_next_scheduled('ispag_check_daily_delivery');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'ispag_check_daily_delivery');
        }
        $timestamp = wp_next_scheduled('ispag_check_project_to_invoice');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'ispag_check_project_to_invoice');
        }
        $timestamp = wp_next_scheduled('ispag_check_upcoming_deliveries');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'ispag_check_upcoming_deliveries');
        }
    }
    

    public static function check_project_to_invoice(){
        $projects = apply_filters('ispag_get_projects_or_offers', null, false, null, false, null, 0 , 200);
        foreach ($projects as $project) {
            $articles_grouped = apply_filters('ispag_get_articles_by_deal', null, $project->hubspot_deal_id);

            foreach ($articles_grouped as $articles) { // <- ici on entre dans le vrai tableau d'articles
                foreach ($articles as $article) {
                    if ($article->Livre && !$article->invoiced) {
                        // ➤ ici tu peux faire ce que tu veux : envoyer un mail, marquer dans un log, etc.
                        do_action('ispag_send_telegram_notification', null, 'invoice_needed', true, false, $project->hubspot_deal_id, true);
                        // error_log("Projet {$project->hubspot_deal_id} : article livré non facturé ({$article->Article})");
                    }
                }
            }
        }
    }
    public static function send_ispag_daily_deliveries(){
        $today = self::get_deliveries_by_day(time());
        $tomorrow = self::get_deliveries_by_day(strtotime('+1 day'));

        $msg = "";

        if (!empty($today)) {
            $msg .= self::format_telegram_delivery_message($today, time());
        }

        if (!empty($tomorrow)) {
            $msg .= self::format_telegram_delivery_message($today, strtotime('+1 day'));
        }

        // $msg = self::escape_markdown_v2($msg);
        // error_log("MESSAGE FINAL ESCAPÉ :\n" . $msg);
        do_action('ispag_send_telegram_notification', null, $msg, true, false, null, false);
        
    }

    public static function format_telegram_delivery_message($grouped_deliveries, $date_input) {
        $date = is_numeric($date_input) ? $date_input : strtotime($date_input);
        $formatted_date = date('d.m.Y', $date);

        $output = "📦 *Livraisons du jour – {$formatted_date}*\n\n";

        foreach ($grouped_deliveries as $key => $articles) {
            // Échappe les caractères spéciaux pour Telegram MarkdownV2
            $safe_key = $key;
            $output .= "🔧 {$safe_key}\n";
            
            // foreach ($articles as $article) {
            //     $qty = intval($article->Qty);
            //     $desc = trim($article->Article);
            //     $output .= "• {$qty}x - {$desc} \n";
            // }

            // $output .= "\n";
        }

        return trim($output);
    }

 
    public static function shortcode_projets($atts) {
        
        $log_file = WP_CONTENT_DIR . '/ispag_project_manager.log';
//         error_log("--- DEBUT EXECUTION shortcode_projets: " . date('Y-m-d H:i:s') . " ---\n", 3, $log_file);
        
        $atts = shortcode_atts([
            'actif'     => null,
            'qotation'  => null,
            'contact_id' => null,
        ], $atts);

        $can_view_prices = current_user_can('display_sales_prices');
        
        $qotation = $atts['qotation'];
        $only_activ = $atts['actif'];
        $contact_id = absint( $_GET['contact_id'] ?? 0 );

//         error_log('[CONTACT ID] : ' . $contact_id ."\n", 3, $log_file);
        

      
        $search_query = isset($_GET['search']) ? sanitize_text_field($_GET['search']) : null;

         


        $html = '<form method="get" style="margin-bottom: 20px;">
            <input type="text" name="search" placeholder="' . __('Search', 'creation-reservoir') . ' ..." value="' . esc_attr($search_query) . '" style="width: 300px; padding: 5px;" />
            
            <button type="submit" class="ispag-btn">' . __('Search', 'creation-reservoir') . '</button>
        </form>';

        $html .= '<div id="projets-meta" data-qotation="' . esc_attr(is_null($qotation) ? 'all' : ($qotation ? '1' : '0')) . '" data-search="' . esc_attr($search_query) . '" data-onlyactiv="' . esc_attr($only_activ) . '" data-contactid="' . esc_attr($contact_id) . '"></div>';


        $html .= '<div class="ispag-table-wrapper">';
        $html .= '<table class="ispag-project-table">';
        $html .= '<thead><tr>';
        $html .= '<th></th><th>' . __('Project name', 'creation-reservoir') . '</th>';
        $html .= $can_view_prices ? '<th>' . __('Total price', 'creation-reservoir') . '</th>': '';
        $html .= '<th>' . __('Next step', 'creation-reservoir') . '</th>';
        if (!$qotation) {
            $html .= '<th>' . __('Tank', 'creation-reservoir') . '</th><th>' . __('Welding', 'creation-reservoir') . '</th><th>' . __('Insulation', 'creation-reservoir') . '</th>';
        }
        $html .= '<th>' . __('Contact', 'creation-reservoir') . '</th><th>' . __('Company', 'creation-reservoir') . '</th>';
        $html .= '</tr></thead>';
        $html .= '<tbody id="projets-list"></tbody>';
        $html .= '</table>';
        $html .= '</div>';
        $html .= '<div id="scroll-loader" style="height: 40px;"></div>';

        return $html;
//         error_log("--- FIN EXECUTION shortcode_projets: " . date('Y-m-d H:i:s') . " ---\n", 3, $log_file);
    }

    public static function render_project_row($p, $is_quotation, $show_price, $i) {
        $can_view_prices = current_user_can('display_sales_prices');

        $link_qotation = $is_quotation ? '&qotation=1' : null;
        $bgcolor = !empty($p->next_phase->Color) ? esc_attr($p->next_phase->Color) : '#ccc';
        $row_class = ($i % 2 === 1) ? ' style="background-color:#f0f0f0;"' : '';

        $html = '<tr' . $row_class . '>';
        $html .= '<td style="background-color:#D1E7DD;"></td>';
        $html .= '<td><a href="' . esc_url($p->project_url_dev) . '' . $link_qotation . '" target="_blank">' . esc_html(stripslashes($p->ObjetCommande)) . '</a></td>';
        
        $html .= $can_view_prices ? '<td>' . ($show_price ? number_format($p->total_amount, 2, ',', ' ') . ' CHF' : '&mdash;') . '</td>' : '';
        $html .= '<td><span class="ispag-state-badge ' . $bgcolor . '"  opacity: 0.8;">' . esc_html__($p->next_phase->TitrePhase, 'creation-reservoir') . '</span></td>';

        if (!$is_quotation) {
            $html .= '<td>' . $p->bar_product . '</td>';
            $html .= '<td>' . $p->bar_welding . '</td>';
            $html .= '<td>' . $p->bar_isol . '</td>';
        }

        $html .= '<td>' . esc_html($p->contact_name) . '</td>';
        $html .= '<td>' . esc_html($p->nom_entreprise) . '</td>';
        $html .= '</tr>';

        return $html;
    }

    public static function get_deliveries_by_day($date_input) {
        global $wpdb;

        // Si timestamp, on convertit en date
        if (is_numeric($date_input)) {
            $date_str = date('Y-m-d', $date_input);
        } else {
            $date_str = $date_input;
        }

        $start = strtotime($date_str . ' 00:00:00');
        $end = strtotime($date_str . ' 23:59:59');

        $sql = "
            SELECT d.Id, d.Type, d.Description, d.hubspot_deal_id, d.Qty, d.TimestampDateDeLivraisonFin,
                c.ObjetCommande, t.type AS TypeLabel
            FROM {$wpdb->prefix}achats_details_commande d
            LEFT JOIN {$wpdb->prefix}achats_liste_commande c ON d.hubspot_deal_id = c.hubspot_deal_id
            LEFT JOIN {$wpdb->prefix}achats_type_prestations t ON d.Type = t.Id
            WHERE d.TimestampDateDeLivraisonFin BETWEEN %d AND %d
            ORDER BY t.type, c.ObjetCommande
        ";

        $results = $wpdb->get_results($wpdb->prepare($sql, $start, $end));
        
        $grouped = [];
        foreach ($results as $row) {
            $type_label = $row->TypeLabel ?: 'Type inconnu';
            $project_name = $row->ObjetCommande ?: 'Projet inconnu';
            $key = __($type_label, 'creation-reservoir'). " ({$project_name})";
            
            if (!isset($grouped[$key])) {
                $grouped[$key] = [];
            }
            // $grouped[$key][] = $row;
        }

        return $grouped;
    }

    public static function check_upcoming_deliveries() {
        global $wpdb;

        $now = time();
        $jours_avertissement_telegram = 15; // À combien de jours avant livraison on veut être alerté
        $now_plus_x_jour_telegram = $now + $jours_avertissement_telegram * 86400;
        $jours_avertissement_mail = 7; // À combien de jours avant livraison on veut être alerté
        $now_plus_x_jour_mail = $now + $jours_avertissement_mail * 86400;

        // Récupère tous les projets actifs
        $projets = $wpdb->get_results("
            SELECT hubspot_deal_id, ObjetCommande
            FROM {$wpdb->prefix}achats_liste_commande
            WHERE project_status = 1
            AND isQotation IS NULL
        ");

        

        foreach ($projets as $projet) {
            $deal_id = (int) $projet->hubspot_deal_id;

            $query = $wpdb->prepare("
                SELECT d.Id, d.Article, d.Description, d.TimestampDateDeLivraison, info.AdresseDeLivraison
                FROM {$wpdb->prefix}achats_details_commande d
                LEFT JOIN {$wpdb->prefix}achats_info_commande info
                    ON info.hubspot_deal_id = d.hubspot_deal_id
                WHERE d.hubspot_deal_id = %d
                AND d.Type = 1
                AND d.TimestampDateDeLivraison IS NOT NULL
                AND FROM_UNIXTIME(d.TimestampDateDeLivraison) BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
                AND d.Livre IS NULL;
            ", $deal_id, $now, $now_plus_x_jour_telegram);

            $articles = $wpdb->get_results($query);
            



            foreach ($articles as $article) {

                if (!empty(trim((string) $article->AdresseDeLivraison))) {
                    continue;
                }
                $age = floor(($article->TimestampDateDeLivraison - $now) / 86400);
                if($age <= $jours_avertissement_mail){
                    $msg = "📦 Livraison prévue dans $age jours pour l'article {$article->Article} du projet « {$projet->ObjetCommande} »";
                    do_action('ispag_send_mail_from_slug', null, $deal_id, 'UnloadingFacility');
                    do_action('ispag_send_telegram_notification', null, $msg, true, false);
                }
                elseif($age <= $jours_avertissement_telegram){
                    $msg = "📦 Livraison prévue dans $age jours pour l'article {$article->Article} du projet « {$projet->ObjetCommande} »";
                    do_action('ispag_send_telegram_notification', null, $msg, true, false);
                }
            }
        }
    }

}

add_action('wp_ajax_ispag_load_more_projects', 'ispag_load_more_projects');
add_action('wp_ajax_nopriv_ispag_load_more_projects', 'ispag_load_more_projects');

function ispag_load_more_projects() { 
    $offset = intval($_POST['offset']);
    $limit = 20;
    
    $qotation = isset($_POST['qotation']) && $_POST['qotation'] == 1;
    $search_query = sanitize_text_field($_POST['search']);
    
    // On récupère proprement le contact_id
    $contact_id = (isset($_POST['contact_id']) && !empty($_POST['contact_id']) && $_POST['contact_id'] !== '0') ? intval($_POST['contact_id']) : null;

    error_log('ispag_load_more_projects' . $contact_id);
    
    $can_view_all = current_user_can('real_all_orders');
    $current_user_id = get_current_user_id();

    $repo = new ISPAG_Projet_Repository();

    if (!$can_view_all) {
        $user_id_to_filter = $current_user_id;
    } else {
        $user_id_to_filter = $contact_id; // Sera null si on est sur la liste globale
    }

    $data = $repo->get_fast_project_list($qotation, $user_id_to_filter, $search_query, $offset, $limit);
    $paged_projects = $data['results'];

    $html = ''; 
    foreach ($paged_projects as $i => $p) {
        // Correction ici : on passe l'index réel (i + offset)
        $html .= render_fast_project_row($p, $qotation, ($offset + $i));
    }

    wp_send_json_success([
        'html' => $html,
        'has_more' => count($paged_projects) === $limit,
        'limit' => $limit,
        'offset' => $offset,
    ]);
}

function render_fast_project_row($p, $is_quotation, $index = 0) {
    $can_view_prices = current_user_can('display_sales_prices');
    $can_manage      = current_user_can('real_all_orders'); 

    $project_name  = html_entity_decode(stripslashes($p->ObjetCommande), ENT_QUOTES, 'UTF-8');
    $company_name  = html_entity_decode(stripslashes($p->company_name), ENT_QUOTES, 'UTF-8');
    $project_url   = "https://app.ispag-asp.ch/details-du-projet/?deal_id=" . $p->hubspot_deal_id;
    
    $next_step       = $p->next_step_name ?: 'Terminé';
    $next_step_color = $p->next_step_color ?: '#e2e8f0';

    $html = '<tr class="project-row-item">';

    // 1. Index
    $html .= '<td class="td-index">' . ($index + 1) . '</td>';

    // 2. Nom du Projet (On ajoute data-label même si on le cachera en CSS)
    $html .= '<td data-label="'.__('Project name', 'creation-reservoir').'" class="td-title">';
    $html .= '<strong><a href="' . esc_url($project_url) . '" class="project-link" target="_blank">' . esc_html($project_name) . '</a></strong>';
    if ($p->NumCommande) {
        $html .= '<br><small class="project-number">#' . esc_html($p->NumCommande) . '</small>';
    }
    $html .= '</td>';

    // 3. Prix
    if ($can_view_prices) {
        $html .= '<td data-label="'.__('Total price', 'creation-reservoir').'" class="td-price"><strong>-</strong></td>';
    }

    // 4. Prochaine Étape
    $html .= '<td data-label="'.__('Next step', 'creation-reservoir').'" class="td-step">';
    $html .= '<span class="step-badge" style="background-color:' . esc_attr($next_step_color) . '20; color:' . esc_attr($next_step_color) . '; border:1px solid ' . esc_attr($next_step_color) . ';">';
    $html .= esc_html__($next_step, 'creation-reservoir');
    $html .= '</span>';
    $html .= '</td>';

    // 5. Livraisons
    if (!$is_quotation) {
        $html .= '<td data-label="'.__('Tank', 'creation-reservoir').'">' . ispag_render_progress_cell($p->delivery_cuve, $p->is_delivered_cuve) . '</td>';
        $html .= '<td data-label="'.__('Welding', 'creation-reservoir').'">' . ispag_render_progress_cell($p->delivery_soudure, $p->is_delivered_soudure) . '</td>';
        $html .= '<td data-label="'.__('Insulation', 'creation-reservoir').'">' . ispag_render_progress_cell($p->delivery_iso, $p->is_delivered_iso) . '</td>';
    }

    // 6. Contact
    $html .= '<td data-label="'.__('Contact', 'creation-reservoir').'" class="td-contact">';
    $html .= '<strong>' . esc_html($p->contact_name ?: 'N/C') . '</strong>';
    if ($can_manage && !empty($p->creator_name)) {
        $html .= '<br><small class="creator-name">Par: ' . esc_html($p->creator_name) . '</small>';
    }
    $html .= '</td>';

    // 7. Entreprise
    $html .= '<td data-label="'.__('Company', 'creation-reservoir').'" class="td-company">';
    $html .= '<span class="company-name">' . esc_html($company_name) . '</span>';
    if ($p->company_city && $p->company_city !== 'N/C') {
        $html .= '<br><small class="city-name">' . esc_html($p->company_city) . '</small>';
    }
    $html .= '</td>';

    $html .= '</tr>';

    return $html;
}
/**
 * Affiche la cellule de progression avec gestion du retard
 * @param int $timestamp 
 * @param int|bool $is_delivered Valeur de la colonne 'Livre'
 */
function ispag_render_progress_cell($timestamp, $is_delivered = 0) {
    if (!$timestamp || $timestamp <= 0) return '<span style="color:#dcdde1;">—</span>';

    $date = date('d.m.Y', $timestamp);
    $now = current_time('timestamp');
    $is_delivered = (int)$is_delivered === 1;

    // Détermination de l'état
    if ($is_delivered) {
        $color = '#27ae60'; // Vert (Effectué)
        $label = __('Done', 'creation-reservoir');
    } elseif ($timestamp < $now) {
        $color = '#e74c3c'; // Rouge (Retard)
        $label = __('Delayed', 'creation-reservoir');
    } else {
        $color = '#f39c12'; // Orange (Prévu)
        $label = __('Planned', 'creation-reservoir');
    }

    return '
    <div style="min-width:85px;">
        <div style="font-size: 0.85em; font-weight: 600; color:'.$color.';">' . $date . '</div>
        <div style="width: 100%; background: #eee; height: 4px; border-radius: 2px; margin-top: 3px;">
            <div style="width: 100%; background: '.$color.'; height: 4px; border-radius: 2px;"></div>
        </div>
        <div style="font-size: 0.7em; color: #7f8c8d; margin-top: 2px; font-weight: bold; text-transform: uppercase;">' . $label . '</div>
    </div>';
}