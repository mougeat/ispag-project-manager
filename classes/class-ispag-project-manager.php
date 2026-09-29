<?php
defined('ABSPATH') or die();

class ISPAG_Project_Manager {

    public static function init() {
        add_shortcode('ispag_projets', [self::class, 'shortcode_projets']);
        add_shortcode("b2b_get_login_url", [self::class, 'get_login_url']);
        add_action('wp_enqueue_scripts', [self::class, 'enqueue_assets']);
        add_filter('walker_nav_menu_start_el', [self::class, 'wp_gestion_nav_replace'], 10, 2);

        // Hook CRON
        add_action('ispag_check_daily_delivery', [self::class, 'send_ispag_daily_deliveries']);
        add_action('ispag_check_project_to_invoice', [self::class, 'check_project_to_invoice']);
        add_action('ispag_check_upcoming_deliveries', [self::class, 'check_upcoming_deliveries']);
        // CRON scheduler
        add_action('wp', [self::class, 'ispag_schedul_cron_project']);

        // Actions AJAX
        add_action('wp_ajax_ispag_load_more_projects', 'ispag_load_more_projects');
        add_action('wp_ajax_nopriv_ispag_load_more_projects', 'ispag_load_more_projects');
    }

    public static function enqueue_assets() {
        wp_enqueue_style(
            'ispag-fontawesome',
            'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css',
            [],
            '6.4.2'
        );

        wp_enqueue_style(
            'ispag-main-style',
            plugins_url('../assets/css/main.css', __FILE__),
            [],
            '1.0'
        );

        wp_enqueue_style('dashicons');

        wp_enqueue_script(
            'wp_gestion_jquery',
            'https://ajax.googleapis.com/ajax/libs/jquery/3.7.1/jquery.min.js',
            ['jquery']
        );

        wp_enqueue_script(
            'wp_gestion_jquery_ui',
            'https://code.jquery.com/ui/1.14.0/jquery-ui.js',
            ['jquery']
        );

        wp_enqueue_script('ispag-scroll', plugin_dir_url(__FILE__) . '../assets/js/infinite-scroll.js', [], false, true);
        wp_localize_script('ispag-scroll', 'ajaxurl', admin_url('admin-ajax.php'));

        wp_localize_script('ispag-scroll', 'ispagVars', [
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('ispag_ajax_nonce'),
            'loading_text' => __('Loading', 'creation-reservoir'),
            'all_loaded_text' => __('All projects are loaded', 'creation-reservoir'),
        ]);


    }

    public static function ispag_schedul_cron_project() {
        if (!wp_next_scheduled('ispag_check_daily_delivery')) {
            wp_schedule_event(time(), 'daily', 'ispag_check_daily_delivery');
        }
        if (!wp_next_scheduled('ispag_check_project_to_invoice')) {
            wp_schedule_event(time(), 'weekly', 'ispag_check_project_to_invoice');
        }
        if (!wp_next_scheduled('ispag_check_upcoming_deliveries')) {
            wp_schedule_event(time(), 'weekly', 'ispag_check_upcoming_deliveries');
        }
    }

    public static function get_login_url() {
        if (is_user_logged_in()) {
            return '<style>
                .not_logged_in { visibility: hidden; }
                .logged_in { visibility: visible; }
            </style>';
        } else {
            return '<style>
                .not_logged_in { visibility: visible; }
                .logged_in { visibility: hidden; }
            </style>';
        }
    }

    public static function wp_gestion_nav_replace($item_output, $item) {
        if ('[b2b_profile]' == $item->title) {
            if (is_user_logged_in()) {
                $current_user = get_userdata(get_current_user_id());
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

    // --- NOUVELLE VERSION DU SHORTCODE AVEC FILTRES ---
    public static function shortcode_projets($atts) {
        if (!current_user_can('read_orders')) {
            return '<div class="ispag-alert ispag-alert-danger">
                <i class="dashicons dashicons-lock"></i>
                <strong>' . esc_html__('Restricted access', 'ispag-crm') . ' :</strong> ' .
                esc_html__('You do not have the necessary rights to view this order.', 'ispag-crm') . '<br/>
                <a href="' . home_url('/wp-login.php') . '">' . esc_html__('To login page', 'ispag-crm') . '</a>
            </div>';
        }

        $atts = shortcode_atts([
            'actif' => null,
            'qotation' => null,
            'contact_id' => null,
        ], $atts);

        $can_view_prices = current_user_can('display_sales_prices');
        $qotation = $atts['qotation'];
        $only_activ = $atts['actif'];
        $contact_id = absint($_GET['contact_id'] ?? 0);
        $search_query = isset($_GET['search']) ? sanitize_text_field($_GET['search']) : null;
        $filter_creator = isset($_GET['filter_creator']) ? sanitize_text_field($_GET['filter_creator']) : 'all';

        $repo = new ISPAG_Projet_Repository();
        $creators = $repo->get_unique_project_creators();

        $html = '<div class="ispag-toolbar" >';
        // $html .= '<form method="get" id="ispag-projects-filter-form">';

        // Champ de recherche étendue
        $html .= '<input type="text"
            name="search"
            id="ispag-projects-search"
            class="ispag-search-field"
            placeholder="' . __('Search by project name, number, company or contact...', 'creation-reservoir') . '"
            value="' . esc_attr($search_query) . '" />';

        // Filtre par créateur
        if(current_user_can('manage_order')){
            $html .='<span class="ispag-kanban-filter-wrapper">';
            $html .= '<select name="filter_creator" id="ispag-projects-creator-filter">';
            $html .= '<option value="all">' . __('All creators', 'creation-reservoir') . '</option>';
            foreach ($creators as $creator) {
                $selected = (isset($_GET['filter_creator']) && $_GET['filter_creator'] == $creator->ID) ? 'selected' : '';
                $html .= '<option value="' . esc_attr($creator->ID) . '" ' . $selected . '>' . esc_html($creator->display_name) . '</option>';
            }
            $html .= '</select>';
            $html .= '</span>';
        }

        // Bouton de soumission
        $html .= '<button type="button" class="ispag-btn ispag-btn-grey" onclick="loadProjects(true)">' . __('Filter / Search', 'creation-reservoir') . '</button>';
        
 
        // Bouton de réinitialisation
        if (!empty($search_query) || (isset($_GET['filter_creator']) && $_GET['filter_creator'] !== 'all')) {
            $html .= '<a href="' . esc_url(remove_query_arg(['orderby', 'order', 'search', 'filter_creator', 'paged'])) . '"
                class="ispag-btn ispag-btn-grey"
                style="margin-left: 10px;">' .
                __('Reset filters', 'creation-reservoir') . '</a>';
        }

        // $html .= '</form>';
        $html .= '</div>';

        // Données meta pour AJAX
        $html .= '<div id="projets-meta"
            data-qotation="' . esc_attr(is_null($qotation) ? 'all' : ($qotation ? '1' : '0')) . '"
            data-search="' . esc_attr($search_query) . '"
            data-onlyactiv="' . esc_attr($only_activ) . '"
            data-contactid="' . esc_attr($contact_id) . '"
            data-creator="' . esc_attr($filter_creator) . '">
        </div>';

        // Tableau des projets
        $html .= '<div class="ispag-table-wrapper ispag-card">';
        $html .= '<table class="ispag-project-table">';
        $html .= '<thead><tr>';
        $html .= '<th>' . __('Project name', 'creation-reservoir') . '</th>';
        // $html .= $can_view_prices ? '<th>' . __('Total price', 'creation-reservoir') . '</th>' : '';
        $html .= '<th>' . __('Next step', 'creation-reservoir') . '</th>';
        if (!$qotation) {
            $html .= '<th>' . __('Tank', 'creation-reservoir') . '</th><th>' . __('Welding', 'creation-reservoir') . '</th><th>' . __('Insulation', 'creation-reservoir') . '</th><th>' . __('Accessories', 'creation-reservoir') . '</th>';
        }
        $html .= '<th>' . __('Contact', 'creation-reservoir') . '</th>';
        // $html .= '<th>' . __('Company', 'creation-reservoir') . '</th>';
        $html .= '</tr></thead>';
        // $html .= '<tbody id="projets-list"></tbody>';
        $html .= '<tbody id="projets-list">'; 
        for ($i=0; $i < 15 ; $i++) { 
            $html .= '
            <tr class="ispag-skeleton-wrapper">
                
                <td><span class="ispag-skeleton-line ispag-w-60"></span></td>
                <td><span class="ispag-skeleton-line ispag-w-30"></span></td>
                ';
                if (!$qotation) {
                $html .= '
                <td><span class="ispag-skeleton-line ispag-w-20"></span></td>
                <td><span class="ispag-skeleton-line ispag-w-20"></span></td>
                <td><span class="ispag-skeleton-line ispag-w-20"></span></td>
                <td><span class="ispag-skeleton-line ispag-w-20"></span></td>';
                }

                $html .= '
                <td><span class="ispag-skeleton-line ispag-w-40"></span></td>
            </tr>';
        }
        $html .= '</tbody>';
        $html .= '</table>';
        $html .= '<div id="scroll-loader" style="height: 40px;"></div>';
        $html .= '</div>';
        

        return $html;
    }

    // --- MÉTHODES ORIGINALES (CRON, EMAILS, ETC.) ---
    public static function check_project_to_invoice() {
        global $wpdb;
        $response = apply_filters('ispag_get_projects_or_offers', null, false, null, false, null, 0, 200);
        $projects = $response['results'] ?? [];

        foreach ($projects as $project) {
            $deal_id = is_object($project->hubspot_deal_id)
                ? (int)($project->hubspot_deal_id->hubspot_deal_id ?? 0)
                : (int)$project->hubspot_deal_id;

            if (!$deal_id) continue;

            // $articles_grouped = apply_filters('ispag_get_articles_by_deal', null, $deal_id);
            $article_repo = new ISPAG_Article_Repository();
            if (current_user_can('navigate_new_project_details_presentation')) {
                $articles_grouped = $article_repo->get_optimised_articles_by_deal($deal_id);
            }
            else{
                $articles_grouped = $article_repo->get_articles_by_deal($deal_id);
            }
            if (empty($articles_grouped)) continue;

            $has_uninvoiced = false;
            foreach ($articles_grouped as $group) {
                $items = is_array($group) ? $group : [$group];
                foreach ($items as $article) {
                    if (!empty($article->Livre) && empty($article->invoiced)) {
                        $has_uninvoiced = true;
                        break 2;
                    }
                }
            }

            if ($has_uninvoiced) {
                // Si votre action Telegram gère déjà son propre envoi, vous pouvez la laisser
                // do_action('ispag_send_telegram_notification', null, 'invoice_needed', true, false, $deal_id, true);

                if (class_exists('ISPAG_Notifications_Manager')) {
                    // 1. Récupération des destinataires de base (Créateur + Admin)
                    $recipients = [$project->created_by, 1];

                    // 2. Récupération des AssociatedContactIDs stockés dans les métas du projet
                    $table_meta = 'wor9711_achats_project_meta';
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

                    // 3. Nettoyage : conversion en entiers, suppression des doublons et valeurs vides
                    $recipients = array_unique(array_filter(array_map('intval', $recipients)));

                    // Envoi unifié à tous les destinataires cibles
                    ISPAG_Notifications_Manager::send(
                        $recipients, // Tableau complet (Admin, Owner, Contacts associés)
                        'deal_billing', // Type de notification lié à la facturation 
                        __( '⏳ Project to be billed', 'ispag-crm' ),
                        sprintf(
                            /* translators: %s: Project order object/name */
                            __( 'ATTENTION, the project %s is to be billed', 'ispag-crm' ),
                            $project->ObjetCommande
                        ),
                        'project-detail/' . $deal_id, // URL de redirection
                        $deal_id // ID du deal lié
                    );
                }
            }
        }
    }

    // public static function send_ispag_daily_deliveries() {
    //     $today = self::get_deliveries_by_day(time(), null);
    //     $tomorrow = self::get_deliveries_by_day(strtotime('+1 day'), null);

    //     $msg_admin = "";
    //     if (!empty($today)) {
    //         $msg_admin .= self::format_telegram_delivery_message($today, time());
    //     }
    //     if (!empty($tomorrow)) {
    //         $msg_admin .= self::format_telegram_delivery_message($tomorrow, strtotime('+1 day'));
    //     }

    //     if (!empty($msg_admin) && class_exists('ISPAG_Notifications_Manager')) {
    //         ISPAG_Notifications_Manager::send(
    //             1, // ID de l'administrateur destinataire
    //             'project_followup', // Type de notification (ou 'purchase_followup')
    //             '⏳ Livraisons du jour',
    //             $msg_admin,
    //             'planning-des-livraisons/' // URL de redirection cible
    //         );
    //     }

    //     $commercials = get_users(['role__in' => ['vente_ispag'], 'exclude' => [1]]);
    //     $deliveries_by_user = [];

    //     foreach ($commercials as $commercial) {
    //         $user_id = $commercial->ID;
    //         $today_user = self::get_deliveries_by_day(time(), $user_id);
    //         $tomorrow_user = self::get_deliveries_by_day(strtotime('+1 day'), $user_id);

    //         if (!empty($today_user) || !empty($tomorrow_user)) {
    //             $deliveries_by_user[$user_id] = [
    //                 date('Y-m-d', time()) => $today_user,
    //                 date('Y-m-d', strtotime('+1 day')) => $tomorrow_user,
    //             ];
    //         }
    //     }

    //     $recipients = [];
    //     foreach ($commercials as $commercial) {
    //         $user_id = $commercial->ID;
    //         if (empty($deliveries_by_user[$user_id])) {
    //             continue;
    //         }

    //         $deliveries = $deliveries_by_user[$user_id];
    //         $email = $commercial->user_email;
    //         $name = trim($commercial->first_name . ' ' . $commercial->last_name) ?: $commercial->display_name;
    //         $subject = "📦 Vos livraisons du jour - " . date('d.m.Y');
    //         $content = self::format_delivery_email($deliveries, $commercial);

    //         $recipients[] = [
    //             'email' => $email,
    //             'name' => $name,
    //             'subject' => $subject,
    //             'content' => $content,
    //         ];
    //     }

    //     ISPAG_Mail_Service::send_bulk_mails($recipients, false);
    // }
    public static function send_ispag_daily_deliveries() {
        global $wpdb;

        $today = self::get_deliveries_by_day(time(), null);
        $tomorrow = self::get_deliveries_by_day(strtotime('+1 day'), null);

        // 1. Préparer le message pour l'administrateur (ID 1) et les owners
        $msg_admin = "";
        if (!empty($today)) {
            $msg_admin .= self::format_telegram_delivery_message($today, time());
        }
        if (!empty($tomorrow)) {
            $msg_admin .= self::format_telegram_delivery_message($tomorrow, strtotime('+1 day'));
        }

        // Récupérer les owners et admins à notifier
        $destinataires_admin = [1]; // Admin par défaut (ID 1)

        // Récupérer les owners des commandes du jour et de demain
        $owners_today = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT c.created_by
            FROM {$wpdb->prefix}achats_details_commande d
            JOIN {$wpdb->prefix}achats_liste_commande c ON d.hubspot_deal_id = c.hubspot_deal_id
            WHERE d.TimestampDateDeLivraisonFin BETWEEN %d AND %d
            AND c.created_by IS NOT NULL",
            strtotime(date('Y-m-d 00:00:00')),
            strtotime(date('Y-m-d 23:59:59'))
        ));

        $owners_tomorrow = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT c.created_by
            FROM {$wpdb->prefix}achats_details_commande d
            JOIN {$wpdb->prefix}achats_liste_commande c ON d.hubspot_deal_id = c.hubspot_deal_id
            WHERE d.TimestampDateDeLivraisonFin BETWEEN %d AND %d
            AND c.created_by IS NOT NULL",
            strtotime(date('Y-m-d +1 day 00:00:00')),
            strtotime(date('Y-m-d +1 day 23:59:59'))
        ));

        // Fusionner les owners uniques
        $owners = array_unique(array_merge($owners_today, $owners_tomorrow));

        // Ajouter les owners aux destinataires (en excluant l'admin s'il est déjà présent)
        foreach ($owners as $owner_id) {
            if (!in_array($owner_id, $destinataires_admin)) {
                $destinataires_admin[] = (int)$owner_id;
            }
        }

        // Envoyer la notification aux admins et owners
        if (!empty($msg_admin) && class_exists('ISPAG_Notifications_Manager')) {
            ISPAG_Notifications_Manager::send(
                $destinataires_admin, // Tableau des IDs utilisateurs (admin + owners)
                'deal_followup',
                __( '⏳ Today\'s deliveries', 'ispag-crm' ),
                $msg_admin,
                'planning-des-livraisons/'
            );
        }

        // 2. Notifications pour les Commerciaux
        $commercials = get_users(['role__in' => ['vente_ispag'], 'exclude' => [1]]);

        foreach ($commercials as $commercial) {
            $user_id = $commercial->ID;
            $today_user = self::get_deliveries_by_day(time(), $user_id);
            $tomorrow_user = self::get_deliveries_by_day(strtotime('+1 day'), $user_id);

            if (empty($today_user) && empty($tomorrow_user)) {
                continue;
            }

            $deliveries = [
                date('Y-m-d', time()) => $today_user,
                date('Y-m-d', strtotime('+1 day')) => $tomorrow_user,
            ];

            // Formatage du contenu pour la notification
            $subject = sprintf(
                /* translators: %s: formatted current date */
                __( '📦 Your deliveries for today - %s', 'ispag-crm' ),
                date('d.m.Y')
            );
            $content = self::format_delivery_email($deliveries, $commercial);

            // Envoi via le manager unifié
            if (class_exists('ISPAG_Notifications_Manager')) {
                ISPAG_Notifications_Manager::send(
                    $user_id,
                    'project_manager',
                    $subject,
                    $content,
                    'planning-des-livraisons/'
                );
            }
        }
    }

    private static function format_delivery_email(array $deliveries, WP_User $commercial): string {
        $html = "<h2>Bonjour " . esc_html($commercial->first_name) . ",</h2>";
        $html .= "<p>Voici vos livraisons prévues pour aujourd'hui et demain :</p>";

        foreach ($deliveries as $date => $grouped_deliveries) {
            $formatted_date = date('d.m.Y', strtotime($date));
            $html .= "<h3>📅 Livraisons du $formatted_date</h3>";
            $html .= "<ul>";

            foreach ($grouped_deliveries as $key => $articles) {
                $html .= "<li><strong>$key</strong></li>";
            }
            $html .= "</ul>";
        }

        $html .= "<p>Cordialement,<br>L'équipe ISPAG</p>";
        return $html;
    }

    public static function format_telegram_delivery_message($grouped_deliveries, $date_input) {
        $date = is_numeric($date_input) ? $date_input : strtotime($date_input);
        $formatted_date = date('d.m.Y', $date);

        $output = "📦 *Livraisons du jour – {$formatted_date}*\n\n";

        foreach ($grouped_deliveries as $key => $articles) {
            $safe_key = $key;
            $output .= "🔧 {$safe_key}\n";
        }

        return trim($output);
    }

    public static function get_deliveries_by_day($date_input, $created_by = null) {
        global $wpdb;

        if (is_numeric($date_input)) {
            $date_str = date('Y-m-d', $date_input);
        } else {
            $date_str = $date_input;
        }

        $start = strtotime($date_str . ' 00:00:00');
        $end = strtotime($date_str . ' 23:59:59');

        $sql = "
            SELECT d.Id, d.Type, d.Description, d.hubspot_deal_id, d.Qty, d.TimestampDateDeLivraisonFin,
                c.ObjetCommande, t.type AS TypeLabel,
                c.created_by
            FROM {$wpdb->prefix}achats_details_commande d
            LEFT JOIN {$wpdb->prefix}achats_liste_commande c ON d.hubspot_deal_id = c.hubspot_deal_id
            LEFT JOIN {$wpdb->prefix}achats_type_prestations t ON d.Type = t.Id
            WHERE d.TimestampDateDeLivraisonFin BETWEEN %d AND %d
        ";

        $params = [$start, $end];
        if ($created_by !== null) {
            $sql .= " AND c.created_by = %d";
            $params[] = $created_by;
        }

        $sql .= " ORDER BY t.type, c.ObjetCommande";

        $results = $wpdb->get_results($wpdb->prepare($sql, $params));

        $grouped = [];
        foreach ($results as $row) {
            $type_label = $row->TypeLabel ?: 'Type inconnu';
            $project_name = $row->ObjetCommande ?: 'Projet inconnu';
            $key = __($type_label, 'creation-reservoir') . " ({$project_name})";

            if (!isset($grouped[$key])) {
                $grouped[$key] = [];
            }
        }

        return $grouped;
    }

    public static function check_upcoming_deliveries() {
        global $wpdb;
        $logger = ISPAG_Logger::get_instance();
        $log_name = 'cron_deliveries';

        $logger->log($log_name, "--- DÉBUT DE LA VÉRIFICATION DES LIVRAISONS EN ATTENTE ---");

        $now = time();
        $today_str = date('Y-m-d');
        $jours_telegram = 15;
        $jours_mail = 7;

        // Récupération des projets actifs
        $projets = $wpdb->get_results("
            SELECT hubspot_deal_id, ObjetCommande
            FROM {$wpdb->prefix}achats_liste_commande
            WHERE project_status = 1
            AND isQotation IS NULL
        ");

        $logger->log($log_name, sprintf("Nombre de projets actifs trouvés : %d", count($projets)));

        foreach ($projets as $projet) {
            $deal_id = (int) $projet->hubspot_deal_id;

            // Récupération des articles à livrer dans les 15 jours (incluant les retards)
            $query = $wpdb->prepare("
                SELECT d.Id, d.Article, d.Description, d.TimestampDateDeLivraisonFin,
                    d.last_warning_sent, info.AdresseDeLivraison
                FROM {$wpdb->prefix}achats_details_commande d
                LEFT JOIN {$wpdb->prefix}achats_info_commande info
                    ON info.hubspot_deal_id = d.hubspot_deal_id
                WHERE d.hubspot_deal_id = %d
                AND d.Type = 1
                AND d.TimestampDateDeLivraisonFin IS NOT NULL
                AND FROM_UNIXTIME(d.TimestampDateDeLivraisonFin) <= DATE_ADD(CURDATE(), INTERVAL %d DAY)
                AND d.Livre IS NULL
            ", $deal_id, $jours_telegram);

            $articles = $wpdb->get_results($query);

            if (!empty($articles)) {
                $logger->log($log_name, sprintf(
                    "Projet '%s' (Deal ID: %d) -> %d articles trouvés avec date de livraison proche.",
                    $projet->ObjetCommande, $deal_id, count($articles)
                ));
            }

            foreach ($articles as $article) {
                // Vérification de l'adresse de livraison (on s'intéresse à l'absence d'adresse)
                $has_address = !empty(trim((string) $article->AdresseDeLivraison));

                $age = (int) floor(($article->TimestampDateDeLivraisonFin - $now) / 86400);
                $last_sent_date = $article->last_warning_sent ? date('Y-m-d', strtotime($article->last_warning_sent)) : null;

                // Ignorer si un warning a déjà été envoyé aujourd'hui
                if ($last_sent_date === $today_str) {
                    $logger->log($log_name, sprintf(
                        "   [-] Article ID %d ('%s') ignoré : déjà alerté aujourd'hui (%s).",
                        $article->Id, $article->Article, $last_sent_date
                    ));
                    continue;
                }

                // Déterminer le type d'alerte et le message (adapté selon la présence ou non de l'adresse)
                $should_send = false;
                $type_alerte = '';
                $msg = '';

                $address_status_text = $has_address 
                    ? __( 'Delivery address provided', 'ispag-crm' ) 
                    : __( 'MISSING delivery address', 'ispag-crm' );

                if ($age < 0) {
                    // RETARD
                    $msg = sprintf(
                        /* translators: 1: Days absolute, 2: Article name, 3: Project name, 4: Address status */
                        __( '⚠️ DELAY of %1$d day(s) for article %2$s (Project: %3$s) - Status: %4$s', 'ispag-crm' ),
                        abs($age),
                        $article->Article,
                        $projet->ObjetCommande,
                        $address_status_text
                    );
                    $should_send = true;
                    $type_alerte = 'retard';
                } elseif ($age <= $jours_mail) {
                    // LIVRAISON IMMINENTE (J-7 à J0)
                    $msg = sprintf(
                        /* translators: 1: Days remaining, 2: Article name, 3: Project name, 4: Address status */
                        __( '📦 J-%1$d: Imminent delivery for %2$s (Project: %3$s) - Status: %4$s', 'ispag-crm' ),
                        $age,
                        $article->Article,
                        $projet->ObjetCommande,
                        $address_status_text
                    );
                    $should_send = true;
                    $type_alerte = 'mail_7j';
                } elseif ($age <= $jours_telegram) {
                    // RAPPEL (J-15 à J-8)
                    $msg = sprintf(
                        /* translators: 1: Days remaining, 2: Article name, 3: Project name, 4: Address status */
                        __( '📢 J-%1$d: Delivery reminder for %2$s (Project: %3$s) - Status: %4$s', 'ispag-crm' ),
                        $age,
                        $article->Article,
                        $projet->ObjetCommande,
                        $address_status_text
                    );
                    $should_send = true;
                    $type_alerte = 'telegram_15j';
                }

                if ($should_send) {
                    $logger->log($log_name, sprintf(
                        "   [+] Action requise pour l'article ID %d (%s) : Type [%s], Age [%d jours].",
                        $article->Id, $article->Article, $type_alerte, $age
                    ));

                    // --- ENVOI UNIFIÉ VIA ISPAG_Notifications_Manager ---
                    if (class_exists('ISPAG_Notifications_Manager')) {
                        // Destinataires : Admin (ID 1) + créateur du deal si disponible
                        $destinataires = [1]; // Admin par défaut

                        // Récupérer le créateur du deal
                        $created_by = $wpdb->get_var($wpdb->prepare(
                            "SELECT created_by FROM {$wpdb->prefix}achats_liste_commande WHERE hubspot_deal_id = %d",
                            $deal_id
                        ));

                        if (!empty($created_by)) {
                            $destinataires[] = (int)$created_by;
                        }

                        // Si c'est une alerte de type mail_7j, ajouter les AssociatedContactIDs et Abonne
                        if ($type_alerte === 'mail_7j') {
                            // Récupérer AssociatedContactIDs et Abonne depuis la table achats_liste_commande
                            $commande_info = $wpdb->get_row($wpdb->prepare(
                                "SELECT AssociatedContactIDs, Abonne FROM {$wpdb->prefix}achats_liste_commande WHERE hubspot_deal_id = %d",
                                $deal_id
                            ));

                            if (!empty($commande_info)) {
                                // Ajouter les AssociatedContactIDs (séparés par des virgules)
                                if (!empty($commande_info->AssociatedContactIDs)) {
                                    $contact_ids = array_map('intval', explode(',', $commande_info->AssociatedContactIDs));
                                    $destinataires = array_merge($destinataires, $contact_ids);
                                }

                                // Ajouter les Abonne (séparés par des points-virgules)
                                if (!empty($commande_info->Abonne)) {
                                    $abonne_ids = array_map('intval', explode(';', $commande_info->Abonne));
                                    $destinataires = array_merge($destinataires, $abonne_ids);
                                }
                            }
                        }

                        // Supprimer les doublons dans les destinataires
                        $destinataires = array_unique($destinataires);

                        // URL vers le planning des livraisons (avec filtre sur le deal_id)
                        $url = add_query_arg('deal_id', $deal_id, 'planning-des-livraisons/');

                        // Envoi de la notification
                        ISPAG_Notifications_Manager::send(
                            $destinataires, // Tableau des IDs utilisateurs
                            'deal_followup', // Type de notification
                            sprintf(
                                /* translators: %s: Project order name */
                                __( '⏳ Delivery: %s', 'ispag-crm' ),
                                $projet->ObjetCommande
                            ), // Titre
                            $msg, // Contenu
                            $url, // URL
                            $deal_id // ID de l'entité (deal)
                        );

                        $logger->log($log_name, sprintf(
                            "       -> Notification envoyée aux utilisateurs : %s",
                            implode(', ', $destinataires)
                        ));
                    }

                    // Mise à jour de la date d'envoi en BDD
                    $update_result = $wpdb->update(
                        "{$wpdb->prefix}achats_details_commande",
                        array('last_warning_sent' => current_time('mysql')),
                        array('Id' => $article->Id),
                        array('%s'),
                        array('%d')
                    );

                    if ($update_result !== false) {
                        $logger->log($log_name, sprintf(
                            "       [OK] Date d'alerte mise à jour en BDD pour l'article ID %d.",
                            $article->Id
                        ));
                    } else {
                        $logger->log($log_name, sprintf(
                            "       [ERREUR] Impossible de mettre à jour la BDD pour l'article ID %d.",
                            $article->Id
                        ));
                    }
                }
            }
        }

        $logger->log($log_name, "--- FIN DE LA VÉRIFICATION DES LIVRAISONS EN ATTENTE ---" . PHP_EOL);
    }

    // --- MÉTHODES POUR LE RENDU DES PROJETS ---
    public static function render_project_row($p, $is_quotation, $show_price, $i) {
        $can_view_prices = current_user_can('display_sales_prices');
        $link_qotation = $is_quotation ? '&qotation=1' : null;
        $bgcolor = !empty($p->next_phase->Color) ? esc_attr($p->next_phase->Color) : '#ccc';
        $row_class = ($i % 2 === 1) ? ' style="background-color:#f0f0f0;"' : '';

        $html = '<tr' . $row_class . '>';
        // $html .= '<td style="background-color:#D1E7DD;"></td>';
        $html .= '<td><a href="' . esc_url($p->project_url_dev) . '' . $link_qotation . '" target="_blank">' . esc_html(stripslashes($p->ObjetCommande)) . '</a></td>';
        // $html .= $can_view_prices ? '<td>' . ($show_price ? number_format($p->total_amount, 2, ',', ' ') . ' CHF' : '&mdash;') . '</td>' : '';
        $html .= '<td><span class="ispag-state-badge ' . $bgcolor . '"  opacity: 0.8;">' . esc_html__($p->next_phase->TitrePhase, 'creation-reservoir') . '</span></td>';

        if (!$is_quotation) {
            $html .= '<td>' . $p->bar_product . '</td>';
            $html .= '<td>' . $p->bar_welding . '</td>';
            $html .= '<td>' . $p->bar_isol . '</td>';
            $html .= '<td>' . $p->bar_accessories . '</td>';
        }

        $html .= '<td>' . esc_html($p->contact_name) . '</td>';
        $html .= '<td>' . esc_html($p->nom_entreprise) . '</td>';
        $html .= '</tr>';

        return $html;
    }
}

// --- FONCTIONS AJAX (hors classe) ---
function ispag_load_more_projects() {
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'ispag_ajax_nonce')) {
        wp_send_json_error(['message' => 'Invalid nonce.']);
        wp_die();
    }

    $offset = intval($_POST['offset']);
    $limit = intval($_POST['limit']) ?: 20;
    $qotation = isset($_POST['qotation']) && $_POST['qotation'] == 1;
    $search_query = sanitize_text_field($_POST['search']);
    $creator_id = (isset($_POST['filter_creator']) && $_POST['filter_creator'] !== 'all') ? intval($_POST['filter_creator']) : null;
    $ingenieur_id = isset($_POST['ingenieur_id']) ? intval($_POST['ingenieur_id']) : null;
    $contact_id = (isset($_POST['contact_id']) && !empty($_POST['contact_id']) && $_POST['contact_id'] !== '0') ? intval($_POST['contact_id']) : null;

    $can_view_all = current_user_can('real_all_orders');
    $current_user_id = get_current_user_id();

    $repo = new ISPAG_Projet_Repository();

    if (!$can_view_all) {
        $user_id_to_filter = $current_user_id;
    } else {
        $user_id_to_filter = $contact_id;
    }

    $data = $repo->get_fast_project_list($qotation, $user_id_to_filter, $search_query, $offset, $limit, $ingenieur_id, $creator_id);
    $paged_projects = $data['results'];

    $html = '';
    foreach ($paged_projects as $i => $p) {
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
    $can_manage = current_user_can('real_all_orders');

    $project_name = html_entity_decode(stripslashes($p->ObjetCommande), ENT_QUOTES, 'UTF-8');
    $company_name = html_entity_decode(stripslashes($p->company_name), ENT_QUOTES, 'UTF-8');
    // $project_url = "https://app.ispag-asp.ch/project-detail/" . $p->hubspot_deal_id;

    $current_lang = function_exists('pll_current_language') 
    ? pll_current_language() 
    : (defined('ICL_LANGUAGE_CODE') ? ICL_LANGUAGE_CODE : 'fr');

    // 2. Adapter le slug selon la langue
    if (current_user_can('navigate_new_project_details_presentation')) {
        $slug = ($current_lang === 'de') ? 'de/project-detail' : 'projectdetail';
    }
    else{
        $slug = ($current_lang === 'de') ? 'de/project-detail' : 'project-detail';
    }

    //-----------------------------------------------------------------------
    // Création du badge prochaine étape du projet
    //-----------------------------------------------------------------------
    if(current_user_can('manage_order')){
        $context = ISPAG_Project_Phase_Resolver::CONTEXT_INTERNAL;
    }
    else{
        $context = ISPAG_Project_Phase_Resolver::CONTEXT_CLIENT;
    }
    $next = ISPAG_Project_Phase_Resolver::get_next_pending_phase($p->hubspot_deal_id, $context);

    if ($next)
    {
        $phase_title = __($next['phase']->TitrePhase, 'creation-reservoir');
        $badge_color = $next['phase']->Color ?: '#ccc';
    }
    else
    {
        $phase_title = __('Completed', 'creation-reservoir');
        $badge_color = '#00C875';
    }

    // $bgcolor = !empty($project->next_phase->Color) ? esc_attr($project->next_phase->Color) : '#ccc';
    $next_step_badge = '<span class="ispag-next-step-badge step-badge" style="color:' . $badge_color . '; border:1px solid ' . $badge_color . ';">' . esc_html($phase_title ?? 'Non défini') . '</span>';
    

    // 3. Générer l'URL dynamique complète
    $project_url = home_url("/{$slug}/" . $p->hubspot_deal_id);

    $next_step = $p->next_step_name ?: 'Terminé';
    $next_step_color = $p->next_step_color ?: '#e2e8f0';

    $html = '<tr class="project-row-item">';

    // Index
    // $html .= '<td class="td-index">' . ($index + 1) . '</td>';

    // Nom du projet
    $html .= '<td data-label="' . __('Project name', 'creation-reservoir') . '" class="td-title">';
    $html .= '<strong><a href="' . esc_url($project_url) . '" class="project-link" target="_blank">' . esc_html($project_name) . '</a></strong>';
    $html .= '<br>';
    if ($p->NumCommande) {
        $html .= '<small class="project-number">#' . esc_html($p->NumCommande) . '</small>';
        $html .= ' | ';
    }
    if ($can_manage && !empty($p->creator_name)) {
        $html .= '<small class="creator-name">' . __('by', 'creation-reservoir') . ' : ' . esc_html($p->creator_name) . '</small>';
    }
    $html .= '</td>';

    // Prix
    // if ($can_view_prices) {
    //     $total_amount = isset($p->total_amount) ? number_format($p->total_amount, 2, ',', ' ') . ' CHF' : '-';
    //     $html .= '<td data-label="' . __('Total price', 'creation-reservoir') . '" class="td-price"><strong>' . $total_amount . '</strong></td>';
    // }

    // Prochaine étape
    $html .= '<td data-label="' . __('Next step', 'creation-reservoir') . '" class="td-step">' . $next_step_badge . '</td>';
    // $html .= '<span class="step-badge" style="background-color:' . esc_attr($next_step_color) . '20; color:' . esc_attr($next_step_color) . '; border:1px solid ' . esc_attr($next_step_color) . ';">';
    // $html .= esc_html__($next_step, 'creation-reservoir');
    // $html .= '</span>';
    // $html .= '</td>';

    // Livraisons
    if (!$is_quotation) {
        $html .= '<td data-label="' . __('Tank', 'creation-reservoir') . '">'
                . ispag_render_progress_cell($p->delivery_cuve, $p->is_delivered_cuve, $p->has_cuve ?? false)
                . '</td>';
        $html .= '<td data-label="' . __('Welding', 'creation-reservoir') . '">'
                . ispag_render_progress_cell($p->delivery_soudure, $p->is_delivered_soudure, $p->has_soudure ?? false)
                . '</td>';
        $html .= '<td data-label="' . __('Insulation', 'creation-reservoir') . '">'
                . ispag_render_progress_cell($p->delivery_iso, $p->is_delivered_iso, $p->has_iso ?? false)
                . '</td>';
        $html .= '<td data-label="' . __('Accessories', 'creation-reservoir') . '">'
                . ispag_render_progress_cell($p->delivery_accessories, $p->is_delivered_accessories, $p->has_accessories ?? false)
                . '</td>';
    }

    // Entreprise
    $html .= '<td data-label="' . __('Contact', 'creation-reservoir') . '" class="td-contact">';
    $html .= '<span class="company-name">' . esc_html($company_name) . '</span>';
    if (isset($p->company_city) && $p->company_city && $p->company_city !== 'N/C') {
        $html .= '<br><small class="city-name">' . esc_html($p->company_city) . '</small>';
    }
    // $html .= '</td>';

    $html .= '</br>';

    // Contact
    // $html .= '<td data-label="' . __('Contact', 'creation-reservoir') . '" class="td-contact">';
    $html .= '<span class="contact-name">' . esc_html($p->contact_name ?: 'N/C') . '</span>';
    $html .= '</td>';

    $html .= '</tr>';

    return $html;
}

function ispag_render_progress_cell($timestamp, $is_delivered = 0, $has_article = false) {
    if (!$has_article) {
        return '<span style="color:#7f8c8d; font-style: italic;">' . __('N/A', 'creation-reservoir') . '</span>';
    }
    if (!$timestamp || $timestamp <= 0) {
        return '<span style="color:#e74c3c; font-style: italic;">' . __('Not defined', 'creation-reservoir') . '</span>';
    }

    $date = date('d.m-d', $timestamp);
    $now = current_time('timestamp');
    $is_delivered = (int)$is_delivered === 1;

    if ($is_delivered) {
        $color = '#27ae60';
        $label = __('Done', 'creation-reservoir');
    } elseif ($timestamp < $now) {
        $color = '#e74c3c';
        $label = __('Delayed', 'creation-reservoir');
    } else {
        $color = '#f39c12';
        $label = __('Planned', 'creation-reservoir');
    }

    return '
    <div style="min-width:85px;">
        <div style="font-size: 0.85em; font-weight: 600; color:' . $color . ';">' . $date . '</div>
        <div style="width: 100%; background: #eee; height: 4px; border-radius: 2px; margin-top: 3px;">
            <div style="width: 100%; background: ' . $color . '; height: 4px; border-radius: 2px;"></div>
        </div>
        <div style="font-size: 0.7em; color: #7f8c8d; margin-top: 2px; font-weight: bold; text-transform: uppercase;">' . $label . '</div>
    </div>';
}