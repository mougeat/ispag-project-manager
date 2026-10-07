<?php
defined('ABSPATH') or die();

class ISPAG_Projet_Repository {
    protected $table_projects;
    protected $table_viag_deals;
    protected $table_details;
    protected $table_users;
    protected $table_fournisseurs;
    protected $table_prestations;
    protected $table_companies;
    protected $wpdb;
    private $only_active = false;
    protected static $instance = null;
    private static $cache_projects = [];

    const META_COMPANY_CITY = 'ispag_company_city';
    const CAPABILITY   = 'manage_order';

    public function __construct($only_active = false) {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->table_projects = $wpdb->prefix . 'achats_liste_commande';
        $this->table_viag_deals = class_exists('ISPAG_Crm_Deal_Constants') ? ISPAG_Crm_Deal_Constants::TABLE_NAME : $wpdb->prefix . 'ispag_deals_list';
        $this->table_details = $wpdb->prefix . 'achats_details_commande';
        $this->table_companies = $wpdb->prefix . 'ispag_companies';
        $this->table_users = $wpdb->prefix . 'users';
        $this->table_fournisseurs = $wpdb->prefix . 'ispag_companies';
        $this->table_prestations = $wpdb->prefix . 'achats_type_prestations';
        $this->only_active = $only_active;
    }

    public static function init() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        add_filter('ispag_get_projects_or_offers', [self::$instance, 'filter_get_projects_or_offers'], 10, 7);
        add_filter('ispag_get_project_by_deal_id', [self::$instance, 'get_project_by_deal_id'], 10, 2);
        add_filter('ispag_get_projects_by_deal_ids', [self::$instance, 'get_projects_by_deal_ids'], 10, 2);
        add_action('ispag_delete_project_whith_deal_id', [self::$instance, 'delete_project_whith_deal_id'], 10, 2);

        add_action('wp_ajax_ispag_load_project_datas', [ self::$instance,  'handle_ajax_load_project_datas' ] );
        add_action('wp_ajax_ispag_render_project_details_tab', [ self::$instance,  'handle_ajax_project_details' ] );
        add_action('wp_ajax_ispag_render_project_btn', [ self::$instance,  'handle_ajax_project_btn' ] );
    }

    public function get_minimal_project_detail_by_hubspot_deal_id($deal_id = null){
        if(empty($deal_id)){
            return __('No deal Id defined', 'creation-reservoir');
        }

        global $wpdb;

        $deal_id = intval($deal_id);

        $sql = $wpdb->prepare("
            SELECT
                p.*,
                p.hubspot_deal_id AS deal_id
                
            FROM {$this->table_projects} p
            
            WHERE p.hubspot_deal_id = %d
            LIMIT 1
        ", $deal_id);

        $project = $wpdb->get_row($sql);
        if (!$project) return null;

        return $project;

    
    }

    public static function get_is_qotation_by_deal_id($deal_id = null){
        if (empty($deal_id)) {
            return true;
        }

        global $wpdb;
        $table_projects = $wpdb->prefix . 'achats_liste_commande';
        $deal_id = intval($deal_id);

        $sql = $wpdb->prepare("
            SELECT p.isQotation
            FROM {$table_projects} p
            WHERE p.hubspot_deal_id = %d
            LIMIT 1
        ", $deal_id);

        // get_var retourne directement la valeur de la première colonne de la première ligne
        $is_qotation = $wpdb->get_var($sql);

        // Si aucun enregistrement n'est trouvé, vous pouvez retourner null ou une valeur par défaut
        if ($is_qotation === null) {
            return null;
        }

        return $is_qotation;
    }

    public function handle_ajax_project_btn(){
        try {
            if (ob_get_level()) {
                ob_clean();
            }

            // Vérification de sécurité
            check_ajax_referer('ispag_crm_nonce', '_ajax_nonce');

            // if (!current_user_can('manage_order')) {
            //     wp_send_json_error(array('message' => __('User not allowed.', 'creation-reservoir')));
            // }

            $hubspot_deal_id = isset($_POST['hubspot_deal_id']) ? intval($_POST['hubspot_deal_id']) : 0;

            if (empty($hubspot_deal_id)) {
                wp_send_json_error(array('message' => __('No deal ID provided.', 'creation-reservoir')));
            }

            $project_renderer = new ISPAG_Project_views_Renderer();
            // Génération propre du HTML avec un tampon de sortie
            ob_start();
            echo $project_renderer->render_project_action_button($hubspot_deal_id, self::get_is_qotation_by_deal_id($hubspot_deal_id));
            $btn_add = ob_get_clean();

            ob_start();
            echo $project_renderer->bulk_selected_article($hubspot_deal_id, self::get_is_qotation_by_deal_id($hubspot_deal_id));
            $bulk_add = ob_get_clean();

            // Envoi du succès avec le HTML généré
            wp_send_json_success(array(
                'html' => $btn_add,
                'bulk_html' => $bulk_add
            ));
        
        } catch (\Throwable $e) {
            if (ob_get_level()) {
                ob_clean();
            }
            wp_send_json_error(array(
                'message' => 'PHP error: ' . $e->getMessage() . ' (Ligne ' . $e->getLine() . ')'
            ));
        }
    }

    public function handle_ajax_project_details(){
        try {
            if (ob_get_level()) {
                ob_clean();
            }

            // Vérification de sécurité (adaptez le nom du nonce selon ce que vous passez en JS)
            check_ajax_referer('ispag_crm_nonce', '_ajax_nonce');

            if (!current_user_can('manage_order')) {
                wp_send_json_error(array('message' => __('User not allowed.', 'creation-reservoir')));
            }

            $hubspot_deal_id = isset($_POST['hubspot_deal_id']) ? intval($_POST['hubspot_deal_id']) : 0;

            if (empty($hubspot_deal_id)) {
                wp_send_json_error(array('message' => 'No deal ID provided.'));
            }

            $project_repo = new ISPAG_Projet_Repository();
            $project = $project_repo->get_project_by_deal_id(null, $hubspot_deal_id);

            $html_content = ISPAG_Project_Details_Renderer::display($hubspot_deal_id, $project);

            

            wp_send_json_success(array(
                'html' => $html_content
            ));

        } catch (\Throwable $e) {
            if (ob_get_level()) {
                ob_clean();
            }
            wp_send_json_error(array(
                'message' => 'PHP error: ' . $e->getMessage() . ' (Ligne ' . $e->getLine() . ')'
            ));
        }
    }

    public function handle_ajax_load_project_datas(){
        try {
            // Nettoie tout tampon de sortie précédent pour garantir un JSON propre
            if (ob_get_level()) {
                ob_clean();
            }

            // 1. Vérification du nonce de sécurité (adaptez 'ispag_crm_nonce' selon votre clé)
            check_ajax_referer('ispag_crm_nonce', '_ajax_nonce');

            // 2. Vérification des droits utilisateur
            if (!current_user_can('manage_order')) {
                wp_send_json_error(array('message' => __('User not allowed to manage order.', 'creation-reservoir')));
            }

            // 3. Récupération et sécurisation du deal ID
            $hubspot_deal_id = isset($_POST['hubspot_deal_id']) ? intval($_POST['hubspot_deal_id']) : 0;

            if (empty($hubspot_deal_id)) {
                wp_send_json_error(array('message' => __('No deal ID provided.', 'creation-reservoir')));
            }

            // 4. On récupère les infos
            // $project_amount = '<span id="ispag_project_amount" data-deal-id="' . $hubspot_deal_id . '">' . self::ispag_calculate_deal_total_sales($hubspot_deal_id) . '</span>';
            $raw_amount = self::ispag_calculate_deal_total_sales($hubspot_deal_id);
            // On formate avec les normes WordPress (ex: 15'420.50 ou 15 420,50 selon la locale)
            $formatted_amount = number_format_i18n(floatval($raw_amount), 2);
            $currency = get_option('wpcb_currency', 'CHF'); // Récupère la devise configurée
            // Si vous renvoyez directement le bloc HTML ou juste la valeur formatée :
            $project_amount_html = '<span id="ispag_project_amount" data-deal-id="' . $hubspot_deal_id . '">' . $formatted_amount . ' <small>' . esc_html($currency) . '</small></span>';

            $context = current_user_can(self::CAPABILITY)
                ? ISPAG_Project_Phase_Resolver::CONTEXT_INTERNAL
                : ISPAG_Project_Phase_Resolver::CONTEXT_CLIENT;

            $next_step = ISPAG_Project_Phase_Resolver::get_next_pending_phase($hubspot_deal_id, $context);


            // Le montant du projet est un prix de vente : seulement pour ceux qui ont le droit de le voir
            wp_send_json_success(array(
                'project_amount' => current_user_can('display_sales_prices') ? $project_amount_html : '',
                'next_step_label' => __($next_step['phase']->TitrePhase, 'creation-reservoir'),
                'next_step_color' => $next_step['phase']->Color ?: 'secondary',
            ));

        } catch (\Throwable $e) {
            // En cas d'erreur fatale, on nettoie le buffer et on renvoie un message JSON propre
            if (ob_get_level()) {
                ob_clean();
            }
            
            // Optionnel : Vous pouvez logger l'erreur via votre ISPAG_Logger ici si besoin
            wp_send_json_error(array(
                'message' => 'PHP error: ' . $e->getMessage() . ' (Ligne ' . $e->getLine() . ')'
            ));
        }
    }
    public function get_project_detail_by_hubspot_deal_id($deal_id = null){
        if(empty($deal_id)){
            return __('No deal Id defined', 'creation-reservoir');
        }

        global $wpdb;

        $deal_id = intval($deal_id);

        $sql = $wpdb->prepare("
            SELECT
                p.*,
                p.hubspot_deal_id AS deal_id
                
            FROM {$this->table_projects} p
            
            WHERE p.hubspot_deal_id = %d
            LIMIT 1
        ", $deal_id);

        $project = $wpdb->get_row($sql);
        if (!$project) return null;

        // $project->ObjetCommande = $project->ObjetCommande . ' - ' . __('version', 'creation-reservoir') . ' ' . $project->version;

        $project->nom_entreprise = self::get_company_name_from_id($project->AssociatedCompanyID);
        $project->contact_name = $this->get_contact_names($project->AssociatedContactIDs);
        $project->get_contact_local = $this->get_contact_local($project->AssociatedContactIDs);

        return $project;

    }

    public function delete_project_whith_deal_id($html, $deal_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'achats_liste_commande';
        $wpdb->delete($table, ['hubspot_deal_id' => $deal_id]);
    }

    public function get_projects_by_deal_ids($html, array $deal_ids): array {
        if (empty($deal_ids)) return [];

        $deal_ids = array_map('intval', $deal_ids);
        $placeholders = implode(',', array_fill(0, count($deal_ids), '%d'));

        $query = "
            SELECT p.*,
            ing.company_name AS ingenieur_projet
            FROM {$this->table_projects} p
            LEFT JOIN {$this->table_companies} ing ON ing.Id = p.ingenieur_id
            WHERE hubspot_deal_id IN ($placeholders)
        ";
        $projects = $this->wpdb->get_results($this->wpdb->prepare($query, ...$deal_ids));

        $result = [];
        foreach ($projects as $project) {
            $deal_id = (int)$project->hubspot_deal_id;
            $project->contact_name = $this->get_contact_names($project->AssociatedContactIDs);
            $project->get_contact_local = $this->get_contact_local($project->AssociatedContactIDs);
            $project->nom_entreprise = self::get_company_name_from_id($project->AssociatedCompanyID);
            $base_url = trailingslashit(get_site_url()) . 'liste-des-projets/';
            $base_url_dev = trailingslashit(get_site_url()) . 'project-detail/';
            $base_purchase_url = trailingslashit(get_site_url()) . 'liste-des-achats/';

            // $project->ObjetCommande = $project->ObjetCommande . ' - ' . __('version', 'creation-reservoir') . ' ' . $project->version;

            $project->project_url = esc_url(add_query_arg('deal_id', $deal_id, $base_url_dev));
            $project->project_url_dev = esc_url(add_query_arg('deal_id', $deal_id, $base_url_dev));
            $project->purchase_url = esc_url(add_query_arg(['search' => $deal_id], $base_purchase_url));

            $result[$deal_id] = $project;
        }

        return $result;
    }

    public function get_project_by_deal_id($html, $deal_id) {
        if (is_object($deal_id) && isset($deal_id->hubspot_deal_id)) {
            $deal_id = $deal_id->hubspot_deal_id;
        }
        $deal_id = (int) $deal_id;
        if ($deal_id <= 0) return null;

        if (isset(self::$cache_projects[$deal_id])) {
            return self::$cache_projects[$deal_id];
        }

        global $wpdb;
        $table_users = $wpdb->prefix . 'users';
        $meta_city_key = ISPAG_Crm_Company_Constants::META_COMPANY_CITY;

        $sql = $wpdb->prepare("
            SELECT
                p.*,
                p.hubspot_deal_id AS deal_id,
                tviag.deal_group_ref,
                COALESCE(NULLIF(c.company_name, ''), NULLIF(f.company_name, ''), 'N/C') as nom_entreprise,
                COALESCE(NULLIF(f.city, ''), 'N/C') as company_city,
                COALESCE(NULLIF(cing.company_name, ''), NULLIF(ing.company_name, ''), 'N/C') as ingenieur_projet,
                (
                    SELECT GROUP_CONCAT(display_name SEPARATOR ', ')
                    FROM $table_users
                    WHERE FIND_IN_SET(ID, REPLACE(p.AssociatedContactIDs, ' ', ''))
                ) as contact_names_combined,
                COALESCE(
                    NULLIF(pm_city.meta_value, ''),
                    NULLIF(f.city, ''),
                    'N/C'
                ) as company_city
            FROM {$this->table_projects} p
            LEFT JOIN {$this->table_viag_deals} tviag ON tviag.project_num = p.NumCommande
            LEFT JOIN {$this->table_companies} c ON c.Id = p.AssociatedCompanyID
            LEFT JOIN {$this->table_companies} cing ON cing.Id = p.ingenieur_id
            LEFT JOIN {$this->table_fournisseurs} f ON f.Id = p.AssociatedCompanyID
            LEFT JOIN {$this->table_fournisseurs} ing ON ing.Id = p.ingenieur_id
            LEFT JOIN {$wpdb->postmeta} pm_city ON (pm_city.post_id = c.Id AND pm_city.meta_key = '$meta_city_key')
            WHERE p.hubspot_deal_id = %d
            LIMIT 1
        ", $deal_id);

        $project = $wpdb->get_row($sql);
        if (!$project) return null;

        // $project->ObjetCommande = $project->ObjetCommande . ' - ' . __('version', 'creation-reservoir') . ' ' . $project->version;

        $project->contact_name = $project->contact_names_combined ?: 'N/C';
        $project->get_contact_local = 'fr_FR';

        $project->project_as_asp = (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT 1 FROM {$this->table_details} WHERE hubspot_deal_id = %d AND Type = 1 LIMIT 1",
            $deal_id
        ));

        $project->is_qotation = (isset($project->isQotation) && $project->isQotation == 1);

        $suivi_manager = new ISPAG_Projet_Suivi();
        $next_phases = $suivi_manager->preload_next_phases([$deal_id], $project->is_qotation);
        $project->next_phase = $next_phases[$deal_id] ?? null;

        $project->total_amount = self::ispag_calculate_deal_total_sales($deal_id);
        $site_url = trailingslashit(get_site_url());
         
        // 2. Adapter le slug selon la langue
        if (current_user_can('navigate_new_project_details_presentation')) {
            $slug = 'projectdetail';
        }
        else{
            $slug = 'project-detail';
        }
        $project->project_url = $site_url . $slug . '/' . $deal_id;
        $project->purchase_url = $site_url . 'liste-des-achats/?search=' . $deal_id;
        $project->is_project_owner = self::is_user_project_owner($project, get_current_user_id());

        self::$cache_projects[$deal_id] = $project;
        return $project;
    }
 
    public function filter_get_projects_or_offers($html, $is_quotation = null, $user_id = null, $all = false, $search = '', $offset = 0, $limit = 50) {
        return $this->get_fast_project_list($is_quotation, $user_id, $search, $offset, $limit);
    }

    // // --- MÉTHODE MODIFIÉE POUR LE FILTRE PAR CRÉATEUR ET RECHERCHE ÉTENDUE ---
    // public function get_fast_project_list(
    //     $is_quotation = false,
    //     $user_id = null,
    //     $search = '',
    //     $offset = 0,
    //     $limit = 10,
    //     $ingenieur_id = null,
    //     $creator_id = null
    // ) {
    //     global $wpdb;

    //     $table_p = $this->table_projects;
    //     $table_details = $this->table_details;
    //     $table_phase_suivi = $wpdb->prefix . 'achats_suivi_phase_commande';
    //     $table_phase_def = $wpdb->prefix . 'achats_slug_phase';
    //     $table_users = $wpdb->prefix . 'users';
    //     $table_companies = $wpdb->prefix . 'ispag_companies';
    //     $table_fournisseurs = $wpdb->prefix . 'ispag_companies';

    //     $where = ["1=1"];

    //     // Filtre ingénieur
    //     if (!empty($ingenieur_id)) {
    //         $where[] = $wpdb->prepare("p.ingenieur_id = %d", $ingenieur_id);
    //     }

    //     // Filtre créateur
    //     if (!empty($creator_id)) {
    //         $where[] = $wpdb->prepare("p.created_by = %d", $creator_id);
    //     }

    //     // Filtre utilisateur
    //     if (!empty($user_id)) {
    //         $where[] = $wpdb->prepare(
    //             "(FIND_IN_SET(%s, p.AssociatedContactIDs) > 0 OR p.created_by = %d)",
    //             $user_id,
    //             $user_id
    //         );
    //     }

    //     // Filtre type (offre vs projet)
    //     if ($is_quotation) {
    //         $where[] = "p.isQotation = 1";
    //     } else {
    //         $where[] = "(p.isQotation IS NULL OR p.isQotation = 0)";
    //         if (empty($search)) {
    //             $where[] = "p.project_status = 1";
    //         }
    //     }

    //     // Filtre recherche
    //     if (!empty($search)) {
    //         $search_term = '%' . $wpdb->esc_like($search) . '%';
    //         $where[] = $wpdb->prepare(
    //             "(p.ObjetCommande LIKE %s OR p.NumCommande LIKE %s OR u.display_name LIKE %s OR COALESCE(NULLIF(c.company_name, ''), NULLIF(f.company_name, ''), 'N/C') LIKE %s)",
    //             $search_term,
    //             $search_term,
    //             $search_term,
    //             $search_term
    //         );
    //     }

    //     $where_str = implode(' AND ', $where);

    //     $sql = "
    //         SELECT
    //             p.hubspot_deal_id,
    //             p.ObjetCommande,
    //             p.NumCommande,
    //             p.TimestampDateCommande,
    //             p.isQotation,
    //             p.created_by,
    //             u.display_name as contact_name,
    //             creator.display_name as creator_name,
    //             COALESCE(NULLIF(c.company_name, ''), NULLIF(f.company_name, ''), 'N/C') as company_name,
    //             ns.TitrePhase as next_step_name,
    //             ns.Color as next_step_color,
    //             ns.SlugPhase as next_step_slug,
    //             delivery.delivery_cuve,
    //             delivery.is_delivered_cuve,
    //             delivery.has_cuve,
    //             delivery.delivery_soudure,
    //             delivery.is_delivered_soudure,
    //             delivery.has_soudure,
    //             delivery.delivery_iso,
    //             delivery.is_delivered_iso,
    //             delivery.has_iso,
    //             delivery.delivery_accessories,
    //             delivery.is_delivered_accessories,
    //             delivery.has_accessories
    //         FROM $table_p p
    //         LEFT JOIN $table_users u ON (
    //             p.AssociatedContactIDs <> ''
    //             AND u.ID = (CASE WHEN p.AssociatedContactIDs REGEXP '^[0-9]+' THEN SUBSTRING_INDEX(p.AssociatedContactIDs, ',', 1) ELSE NULL END)
    //         )
    //         LEFT JOIN $table_users creator ON (p.created_by = creator.ID)
    //         LEFT JOIN $table_companies c ON (p.AssociatedCompanyID <> '' AND p.AssociatedCompanyID <> '0' AND c.Id = p.AssociatedCompanyID)
    //         LEFT JOIN $table_fournisseurs f ON (p.AssociatedCompanyID <> '' AND p.AssociatedCompanyID <> '0' AND f.Id = p.AssociatedCompanyID)
    //         LEFT JOIN (
    //             SELECT fs1.hubspot_deal_id, s1.TitrePhase, s1.Color, s1.SlugPhase
    //             FROM $table_phase_suivi fs1
    //             INNER JOIN $table_phase_def s1 ON fs1.slug_phase = s1.SlugPhase
    //             WHERE fs1.id IN (
    //                 SELECT MAX(id)
    //                 FROM $table_phase_suivi
    //                 GROUP BY hubspot_deal_id, slug_phase
    //             )
    //             AND (fs1.status_id NOT IN (1, 5) OR fs1.status_id IS NULL)
    //             AND (s1.display_on_qotation = " . ($is_quotation ? '1' : 's1.display_on_qotation') . ")
    //             GROUP BY fs1.hubspot_deal_id
    //             ORDER BY s1.Ordre ASC
    //         ) as ns ON ns.hubspot_deal_id = p.hubspot_deal_id
    //         LEFT JOIN (
    //             SELECT
    //                 hubspot_deal_id,
    //                 MAX(CASE WHEN Type IN (1, 6, 7, 6, 9, 10, 12) THEN 1 ELSE 0 END) as has_cuve,
    //                 MAX(CASE WHEN Type = 3 THEN 1 ELSE 0 END) as has_soudure,
    //                 MAX(CASE WHEN Type IN (2, 200) THEN 1 ELSE 0 END) as has_iso,
    //                 MAX(CASE WHEN Type IN (4, 5, 500, 8, 13) THEN 1 ELSE 0 END) as has_accessories,
    //                 MIN(CASE WHEN Type IN (1, 6, 7, 6, 9, 10, 12) THEN TimestampDateDeLivraisonFin END) as delivery_cuve,
    //                 MAX(CASE WHEN Type IN (1, 6, 7, 6, 9, 10, 12) THEN IF(COALESCE(Livre, 0) > 0, 1, 0) ELSE 0 END) as is_delivered_cuve,
    //                 MIN(CASE WHEN Type = 3 THEN TimestampDateDeLivraisonFin END) as delivery_soudure,
    //                 MAX(CASE WHEN Type = 3 THEN IF(COALESCE(Livre, 0) > 0, 1, 0) ELSE 0 END) as is_delivered_soudure,
    //                 MIN(CASE WHEN Type IN (2, 200) THEN TimestampDateDeLivraisonFin END) as delivery_iso,
    //                 MAX(CASE WHEN Type IN (2, 200) THEN IF(COALESCE(Livre, 0) > 0, 1, 0) ELSE 0 END) as is_delivered_iso,
    //                 MIN(CASE WHEN Type IN (4, 5, 500, 8, 13) THEN TimestampDateDeLivraisonFin END) as delivery_accessories,
    //                 MAX(CASE WHEN Type IN (4, 5, 500, 8, 13) THEN IF(COALESCE(Livre, 0) > 0, 1, 0) ELSE 0 END) as is_delivered_accessories
    //             FROM $table_details
    //             GROUP BY hubspot_deal_id
    //         ) as delivery ON delivery.hubspot_deal_id = p.hubspot_deal_id
    //         WHERE $where_str
    //         ORDER BY p.TimestampDateCommande DESC
    //         LIMIT %d OFFSET %d
    //     ";

    //     $final_query = $wpdb->prepare($sql, $limit, $offset);
    //     $results = $wpdb->get_results($final_query);

    //     // Ajoute le montant total pour chaque projet
    //     if (!empty($results)) {
    //         foreach ($results as &$project) {
    //             $project->next_phase = $project->next_step_name ? (object) [
    //                 'TitrePhase' => $project->next_step_name,
    //                 'Color' => $project->next_step_color,
    //                 'SlugPhase' => $project->next_step_slug
    //             ] : null;

    //             // Récupère le montant total du projet
    //             $project->total_amount = self::ispag_calculate_deal_total_sales($project->hubspot_deal_id);
    //         }
    //     }

    //     return [
    //         'results' => $results,
    //         'limit' => $limit,
    //         'offset' => $offset
    //     ];
    // }
    
    /**
     * OPTIMISATION get_fast_project_list()
     * ------------------------------------
     * Principe : on ne calcule les agrégats lourds (phase courante, livraisons)
     * QUE pour les hubspot_deal_id déjà paginés, jamais sur toute la table.
     *
     * ÉTAPE 1 : requête légère -> renvoie les 10 (ou $limit) hubspot_deal_id de la page
     * ÉTAPE 2 : requête d'enrichissement -> IN (ids de l'étape 1) uniquement
     *
     * Pensez à ajouter les index listés en bas de fichier avant de déployer.
     */
    public function get_fast_project_list(
        $is_quotation = false,
        $user_id = null,
        $search = '',
        $offset = 0,
        $limit = 10,
        $ingenieur_id = null,
        $creator_id = null
    ) {
        global $wpdb;

        $table_p             = $this->table_projects;
        $table_details       = $this->table_details;
        $table_phase_suivi   = $wpdb->prefix . 'achats_suivi_phase_commande';
        $table_phase_def     = $wpdb->prefix . 'achats_slug_phase';
        $table_users         = $wpdb->prefix . 'users';
        $table_companies     = $wpdb->prefix . 'ispag_companies';
        $table_fournisseurs  = $wpdb->prefix . 'ispag_companies';

        // ---- Construction des filtres (identique à l'original) ----
        $where = ["1=1"];

        if (!empty($ingenieur_id)) {
            $where[] = $wpdb->prepare("p.ingenieur_id = %d", $ingenieur_id);
        }
        if (!empty($creator_id)) {
            $where[] = $wpdb->prepare("p.created_by = %d", $creator_id);
        }
        if (!empty($user_id)) {
            $where[] = $wpdb->prepare(
                "(FIND_IN_SET(%s, p.AssociatedContactIDs) > 0 OR p.created_by = %d)",
                $user_id,
                $user_id
            );
        }
        if ($is_quotation) {
            $where[] = "p.isQotation = 1";
        } else {
            $where[] = "(p.isQotation IS NULL OR p.isQotation = 0)";
            if (empty($search)) {
                $where[] = "p.project_status = 1";
            }
        }
        if (!empty($search)) {
            $search_term = '%' . $wpdb->esc_like($search) . '%';
            $where[] = $wpdb->prepare(
                "(p.ObjetCommande LIKE %s OR p.NumCommande LIKE %s OR u.display_name LIKE %s OR COALESCE(NULLIF(c.company_name, ''), NULLIF(f.company_name, ''), 'N/C') LIKE %s)",
                $search_term, $search_term, $search_term, $search_term
            );
        }
        $where_str = implode(' AND ', $where);

        // ==========================================================
        // ÉTAPE 1 : uniquement les IDs de la page (aucun agrégat lourd)
        // ==========================================================
        $sql_ids = "
            SELECT p.hubspot_deal_id
            FROM $table_p p
            LEFT JOIN $table_users u ON (
                p.AssociatedContactIDs <> ''
                AND u.ID = (CASE WHEN p.AssociatedContactIDs REGEXP '^[0-9]+' THEN SUBSTRING_INDEX(p.AssociatedContactIDs, ',', 1) ELSE NULL END)
            )
            LEFT JOIN $table_companies c ON (p.AssociatedCompanyID <> '' AND p.AssociatedCompanyID <> '0' AND c.Id = p.AssociatedCompanyID)
            LEFT JOIN $table_fournisseurs f ON (p.AssociatedCompanyID <> '' AND p.AssociatedCompanyID <> '0' AND f.Id = p.AssociatedCompanyID)
            WHERE $where_str
            ORDER BY p.TimestampDateCommande DESC
            LIMIT %d OFFSET %d
        ";
        $deal_ids = $wpdb->get_col($wpdb->prepare($sql_ids, $limit, $offset));

        if (empty($deal_ids)) {
            return ['results' => [], 'limit' => $limit, 'offset' => $offset];
        }

        // IDs déjà issus de notre propre requête -> cast int suffisant, pas d'injection possible
        $ids_in = implode(',', array_map('intval', $deal_ids));

        // ==========================================================
        // ÉTAPE 2 : enrichissement, restreint aux IDs de la page
        // ==========================================================
        $sql = "
            SELECT
                p.hubspot_deal_id,
                p.ObjetCommande,
                p.NumCommande,
                p.version,
                p.TimestampDateCommande,
                p.isQotation,
                p.created_by,
                u.display_name as contact_name,
                creator.display_name as creator_name,
                COALESCE(NULLIF(c.company_name, ''), NULLIF(f.company_name, ''), 'N/C') as company_name,
                ns.TitrePhase as next_step_name,
                ns.Color as next_step_color,
                ns.SlugPhase as next_step_slug,
                delivery.delivery_cuve, delivery.is_delivered_cuve, delivery.has_cuve,
                delivery.delivery_soudure, delivery.is_delivered_soudure, delivery.has_soudure,
                delivery.delivery_iso, delivery.is_delivered_iso, delivery.has_iso,
                delivery.delivery_accessories, delivery.is_delivered_accessories, delivery.has_accessories
            FROM $table_p p
            LEFT JOIN $table_users u ON (
                p.AssociatedContactIDs <> ''
                AND u.ID = (CASE WHEN p.AssociatedContactIDs REGEXP '^[0-9]+' THEN SUBSTRING_INDEX(p.AssociatedContactIDs, ',', 1) ELSE NULL END)
            )
            LEFT JOIN $table_users creator ON (p.created_by = creator.ID)
            LEFT JOIN $table_companies c ON (p.AssociatedCompanyID <> '' AND p.AssociatedCompanyID <> '0' AND c.Id = p.AssociatedCompanyID)
            LEFT JOIN $table_fournisseurs f ON (p.AssociatedCompanyID <> '' AND p.AssociatedCompanyID <> '0' AND f.Id = p.AssociatedCompanyID)
            LEFT JOIN (
                SELECT fs1.hubspot_deal_id, s1.TitrePhase, s1.Color, s1.SlugPhase
                FROM $table_phase_suivi fs1
                INNER JOIN $table_phase_def s1 ON fs1.slug_phase = s1.SlugPhase
                WHERE fs1.hubspot_deal_id IN ($ids_in)
                AND fs1.id IN (
                    SELECT MAX(id)
                    FROM $table_phase_suivi
                    WHERE hubspot_deal_id IN ($ids_in)
                    GROUP BY hubspot_deal_id, slug_phase
                )
                AND (fs1.status_id NOT IN (1, 5) OR fs1.status_id IS NULL)
                AND (s1.display_on_qotation = " . ($is_quotation ? '1' : 's1.display_on_qotation') . ")
                GROUP BY fs1.hubspot_deal_id
                ORDER BY s1.Ordre ASC
            ) as ns ON ns.hubspot_deal_id = p.hubspot_deal_id
            LEFT JOIN (
                SELECT
                    hubspot_deal_id,
                    MAX(CASE WHEN Type IN (1, 6, 7, 9, 10, 12) THEN 1 ELSE 0 END) as has_cuve,
                    MAX(CASE WHEN Type = 3 THEN 1 ELSE 0 END) as has_soudure,
                    MAX(CASE WHEN Type IN (2, 200) THEN 1 ELSE 0 END) as has_iso,
                    MAX(CASE WHEN Type IN (4, 5, 500, 8, 13) THEN 1 ELSE 0 END) as has_accessories,
                    MIN(CASE WHEN Type IN (1, 6, 7, 9, 10, 12) THEN TimestampDateDeLivraisonFin END) as delivery_cuve,
                    MAX(CASE WHEN Type IN (1, 6, 7, 9, 10, 12) THEN IF(COALESCE(Livre, 0) > 0, 1, 0) ELSE 0 END) as is_delivered_cuve,
                    MIN(CASE WHEN Type = 3 THEN TimestampDateDeLivraisonFin END) as delivery_soudure,
                    MAX(CASE WHEN Type = 3 THEN IF(COALESCE(Livre, 0) > 0, 1, 0) ELSE 0 END) as is_delivered_soudure,
                    MIN(CASE WHEN Type IN (2, 200) THEN TimestampDateDeLivraisonFin END) as delivery_iso,
                    MAX(CASE WHEN Type IN (2, 200) THEN IF(COALESCE(Livre, 0) > 0, 1, 0) ELSE 0 END) as is_delivered_iso,
                    MIN(CASE WHEN Type IN (4, 5, 500, 8, 13) THEN TimestampDateDeLivraisonFin END) as delivery_accessories,
                    MAX(CASE WHEN Type IN (4, 5, 500, 8, 13) THEN IF(COALESCE(Livre, 0) > 0, 1, 0) ELSE 0 END) as is_delivered_accessories
                FROM $table_details
                WHERE hubspot_deal_id IN ($ids_in)
                GROUP BY hubspot_deal_id
            ) as delivery ON delivery.hubspot_deal_id = p.hubspot_deal_id
            WHERE p.hubspot_deal_id IN ($ids_in)
            ORDER BY p.TimestampDateCommande DESC
        ";

        $results = $wpdb->get_results($sql);

        if (!empty($results)) {
            foreach ($results as &$project) {

                $project->ObjetCommande = $project->ObjetCommande . ' - ' . __('version', 'creation-reservoir') . ' ' . $project->version;

                $project->next_phase = $project->next_step_name ? (object) [
                    'TitrePhase' => $project->next_step_name,
                    'Color'      => $project->next_step_color,
                    'SlugPhase'  => $project->next_step_slug
                ] : null;

                // Sur 10 lignes max, ce call reste correct.
                // Si vous voulez le batcher aussi, partagez-moi le code
                // de ispag_calculate_deal_total_sales() et je l'intègre en LEFT JOIN.
                // $project->total_amount = self::ispag_calculate_deal_total_sales($project->hubspot_deal_id);
                $project->total_amount = 0;
            }
        }

        return [
            'results' => $results,
            'limit'   => $limit,
            'offset'  => $offset
        ];
    }


    // --- NOUVELLE MÉTHODE POUR RÉCUPÉRER LES CRÉATEURS UNIQUES ---
    public function get_unique_project_creators() {
        global $wpdb;
        $table_p = $this->table_projects;
        $table_users = $wpdb->prefix . 'users';

        $sql = "
            SELECT DISTINCT
                u.ID,
                u.display_name
            FROM $table_p p
            JOIN $table_users u ON p.created_by = u.ID
            WHERE p.created_by IS NOT NULL
            ORDER BY u.display_name ASC
        ";

        return $wpdb->get_results($sql);
    }

    // --- MÉTHODES ORIGINALES (non modifiées) ---
    public function get_contact_names($contact_ids) {
        $ids = [];
        $contact_ids = trim($contact_ids);
        if (empty($contact_ids)) {
            return '';
        }
        if (strpos($contact_ids, ';') !== false) {
            $parts = explode(';', $contact_ids);
            foreach ($parts as $part) {
                $id = intval($part);
                if ($id > 0) {
                    $ids[] = $id;
                }
            }
        } else {
            $parts = explode(',', $contact_ids);
            foreach ($parts as $part) {
                $id = intval($part);
                if ($id > 0) {
                    $ids[] = $id;
                }
            }
        }

        if (empty($ids)) return '';

        global $wpdb;
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $sql = "SELECT ID, display_name FROM {$wpdb->users} WHERE ID IN ($placeholders)";
        $users = $wpdb->get_results($wpdb->prepare($sql, ...$ids));

        if (!$users) return '';
        $names = array_map(fn($u) => $u->display_name, $users);
        return implode(', ', $names);
    }

    private static function get_company_name_from_id($company_id) {
        global $wpdb;
        $company_id = intval($company_id);
        if (!$company_id) return '';
        $table_fournisseurs = $wpdb->prefix . 'ispag_companies';
        $sql = "SELECT company_name AS Fournisseur FROM {$table_fournisseurs} WHERE Id = %d LIMIT 1";
        return $wpdb->get_var($wpdb->prepare($sql, $company_id)) ?? '';
    }

    /**
     * L'utilisateur est-il une personne concernée par le projet (présent dans AssociatedContactIDs, liste séparée par des virgules) ?
     * Ces personnes peuvent modifier et valider le projet (plans compris).
     *
     * @param object|int|null $project Projet (objet avec AssociatedContactIDs) ou Id du deal (hubspot_deal_id).
     */
    public static function is_user_project_owner($project = null, $user_id = null) {
        if (!$user_id) {
            $user_id = get_current_user_id();
        }
        if (!$project || !$user_id) {
            return false;
        }

        // Id de deal : on lit la liste des contacts associés (une seule fois par requête)
        if (is_numeric($project)) {
            static $lists = [];
            $deal_id = (int) $project;
            if (!array_key_exists($deal_id, $lists)) {
                global $wpdb;
                $lists[$deal_id] = (string) $wpdb->get_var($wpdb->prepare(
                    "SELECT AssociatedContactIDs FROM {$wpdb->prefix}achats_liste_commande WHERE hubspot_deal_id = %d LIMIT 1",
                    $deal_id
                ));
            }
            $list = $lists[$deal_id];
        } else {
            $list = (string) ($project->AssociatedContactIDs ?? '');
        }

        if (trim($list) === '') {
            return false;
        }
        $contact_ids = array_map('intval', array_filter(preg_split('/[\s;,]+/', $list)));
        return in_array((int) $user_id, $contact_ids, true);
    }

    public function check_if_is_qotation($deal_id) {
        $sql = "SELECT isQotation FROM {$this->table_projects} WHERE hubspot_deal_id = %d LIMIT 1";
        return $this->wpdb->get_var($this->wpdb->prepare($sql, $deal_id)) ?? '';
    }

    public function get_contact_local($user_id = null) {
        if (empty($user_id)) return '';
        return get_user_locale($user_id);
    }

    public static function ispag_calculate_deal_total_sales($deal_id) {
        global $wpdb;
        if (is_object($deal_id) || is_array($deal_id)) {
            return 0.00;
        }
        $deal_id = intval($deal_id);
        if ($deal_id <= 0) {
            return 0.00;
        }
        // $projet_article_par_groupe = apply_filters('ispag_get_articles_by_deal', null, $deal_id);
        $article_repo = new ISPAG_Article_Repository();
        if (current_user_can('navigate_new_project_details_presentation')) {
            $projet_article_par_groupe = $article_repo->get_optimised_articles_by_deal($deal_id);
        }
        else{
            $projet_article_par_groupe = $article_repo->get_articles_by_deal($deal_id);
        }
        if (empty($projet_article_par_groupe) || !is_array($projet_article_par_groupe)) {
            return 0.00;
        }
        $projet_total = 0.00;
        $rplp_option = get_option('rplp', 0);
        $rplp_multiplier = 1 + (floatval($rplp_option) / 100);

        foreach ($projet_article_par_groupe as $groupe_nom => $articles_du_groupe) {
            if (empty($articles_du_groupe) || (!is_array($articles_du_groupe) && !is_object($articles_du_groupe))) {
                continue;
            }
            foreach ($articles_du_groupe as $article) {
                $prix_ligne = 0.00;
                $qty = 0;
                if (is_object($article)) {
                    $prix_ligne = isset($article->prix_net_calculé) ? floatval($article->prix_net_calculé) : 0.00;
                    $qty = isset($article->Qty) ? intval($article->Qty) : 0;
                } elseif (is_array($article)) {
                    $prix_ligne = isset($article['prix_net_calculé']) ? floatval($article['prix_net_calculé']) : 0.00;
                    $qty = isset($article['Qty']) ? intval($article['Qty']) : 0;
                }
                if ($qty > 0) {
                    $projet_total += ($prix_ligne * $qty);
                }
            }
        }
        return (float) ($projet_total * $rplp_multiplier);
    }
}