<?php
defined('ABSPATH') || exit;
/**
 * Class ISPAG_Projet_Creation
 * Gère la création des projets ISPAG.
 * Logging : Toutes les actions sont loguées dans ispag_projet_creation.log.
 */
class ISPAG_Projet_Creation
{
    const META_COMPANY_CITY = 'ispag_company_city';
    const META_STATUS       = 'ispag_account_status';

    private $table;
    private $table_users;
    private $table_clients;
    private $logger;
    private static $shortcode_called = false; // 👈 Flag global
    private const LOG_NAME = 'projet_creation';

    public function __construct() {
        global $wpdb;
        $this->table    = $wpdb->prefix . 'achats_liste_commande';
        $this->table_users  = $wpdb->prefix . 'users';
        $this->table_clients = $wpdb->prefix . 'ispag_companies';

        $this->logger = ISPAG_Logger::get_instance();

        add_shortcode('ispag_creation_projet', [$this, 'render_form']);
        add_action('init', [$this, 'handle_form']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_scripts']);

        add_action('wp_ajax_search_ispag_companies', [$this, 'ajax_search_companies']);
        add_action('wp_ajax_search_ispag_ingenieurs', [$this, 'ajax_search_ingenieurs']);
        add_action('wp_ajax_search_ispag_concurrents', [$this, 'ajax_search_ispag_concurrents']);
        add_action('wp_ajax_search_ispag_contacts', [$this, 'ajax_search_contacts']);
        add_action('wp_ajax_search_ispag_contacts_for_subscribers', [$this, 'ajax_search_contacts_for_subscribers']);
        add_action('wp_ajax_get_contact_names_by_ids', [$this, 'get_contact_names_by_ids']);

        // Nouveau formulaire : doublons, projets à copier
        add_action('wp_ajax_ispag_pc_duplicates', [$this, 'ajax_pc_duplicates']);
        add_action('wp_ajax_ispag_pc_search_projects', [$this, 'ajax_pc_search_projects']);
        add_action('wp_ajax_ispag_pc_prefill', [$this, 'ajax_pc_prefill']);
    }

    public function enqueue_scripts() {
        wp_enqueue_style('select2-css', 'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css');
        wp_enqueue_script('select2-js', 'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js', ['jquery'], '4.1.0', true);
        wp_enqueue_script('creation-projets-js', plugin_dir_url(__FILE__) . '../assets/js/creation-projets.js', ['jquery', 'select2-js'], '1.2', true);

        wp_localize_script('creation-projets-js', 'ispag_ajax_object', [
            'ajax_url'  => admin_url('admin-ajax.php'),
            'nonce'     => wp_create_nonce('ispag_nonce')
        ]);

        // Formulaire « Nouveau projet / Nouvelle offre » : styles et script propres, uniquement sur la page qui contient le shortcode
        $post = get_post();
        if ($post && has_shortcode((string) $post->post_content, 'ispag_creation_projet')) {
            $dir = plugin_dir_path(__FILE__) . '../assets/';
            $url = plugin_dir_url(__FILE__) . '../assets/';
            wp_enqueue_style('ispag-project-creation', $url . 'css/project-creation.css', [], (string) @filemtime($dir . 'css/project-creation.css'));
            wp_enqueue_script('ispag-project-creation', $url . 'js/project-creation.js', ['jquery', 'select2-js'], (string) @filemtime($dir . 'js/project-creation.js'), true);

            $can_manage = current_user_can('manage_order');
            $can_company = $can_manage && class_exists('ISPAG_Crm_Company_Creator') && ISPAG_Crm_Company_Creator::can_create();
            $can_contact = $can_manage && (current_user_can('add_contact') || current_user_can('manage_options'));
            wp_localize_script('ispag-project-creation', 'ispag_pc', [
                'ajax_url'       => admin_url('admin-ajax.php'),
                'nonce'          => wp_create_nonce('ispag_nonce'),
                'company_nonce'  => $can_company ? wp_create_nonce('ispag_new_company_nonce') : '',
                'contact_nonce'  => $can_contact ? wp_create_nonce('ispag_new_contact_nonce') : '',
                'user_id'        => get_current_user_id(),
                'can_manage'     => $can_manage,
                'i18n'           => [
                    'search_company'   => __('Type the company name…', 'creation-reservoir'),
                    'search_contact'   => __('Search a contact…', 'creation-reservoir'),
                    'search_engineer'  => __('Search an engineering office…', 'creation-reservoir'),
                    'search_project'   => __('Search a project to start from…', 'creation-reservoir'),
                    'type_more'        => __('Type at least 2 characters', 'creation-reservoir'),
                    'no_results'       => __('No results', 'creation-reservoir'),
                    'name_required'    => __('Give the project a name.', 'creation-reservoir'),
                    'creating'         => __('Creating…', 'creation-reservoir'),
                    'saving'           => __('Saving…', 'creation-reservoir'),
                    'error'            => __('Something went wrong, please try again.', 'creation-reservoir'),
                    'company_exists'   => __('A company with this website already exists.', 'creation-reservoir'),
                    'open_company'     => __('Open it', 'creation-reservoir'),
                    'similar_title'    => __('Similar projects already exist:', 'creation-reservoir'),
                    'copy_done'        => __('Details copied from the project. Check them, then save.', 'creation-reservoir'),
                    'copy_suffix'      => __('(copy)', 'creation-reservoir'),
                    'contact_exists'   => __('This contact already exists: it has been selected.', 'creation-reservoir'),
                    'contact_of'       => __('Contacts of this company are suggested first.', 'creation-reservoir'),
                ],
            ]);
        }
    }

    /** Adresse d'un projet (même règle de langue / de vue que la redirection après création). */
    private function project_url($timestamp) {
        $current_lang = function_exists('pll_current_language') ? pll_current_language() : (defined('ICL_LANGUAGE_CODE') ? ICL_LANGUAGE_CODE : 'fr');
        if (current_user_can('navigate_new_project_details_presentation')) {
            $slug = ($current_lang === 'de') ? 'de/project-detail' : 'projectdetail';
        } else {
            $slug = ($current_lang === 'de') ? 'de/project-detail' : 'project-detail';
        }
        return home_url($slug . '/' . $timestamp);
    }

    /** Projets proches (même nom ou même numéro) pour prévenir les doublons ; réservé à la gestion des commandes. */
    public function ajax_pc_duplicates() {
        check_ajax_referer('ispag_nonce', 'nonce');
        if (!current_user_can('manage_order')) wp_send_json_success(['items' => []]);
        global $wpdb;
        $name = sanitize_text_field(wp_unslash($_GET['name'] ?? ''));
        $company_id = absint($_GET['company_id'] ?? 0);
        $num = sanitize_text_field(wp_unslash($_GET['num'] ?? ''));
        $where = []; $args = [];
        if (mb_strlen($name) >= 4) {
            $where[] = 'p.ObjetCommande LIKE %s';
            $args[] = '%' . $wpdb->esc_like($name) . '%';
        }
        if (mb_strlen($num) >= 3) {
            $where[] = '(p.NumCommande = %s OR p.customer_order_id = %s)';
            $args[] = $num; $args[] = $num;
        }
        if (!$where) wp_send_json_success(['items' => []]);
        $sql = "SELECT p.ObjetCommande AS name, p.hubspot_deal_id AS deal, p.TimestampDateCommande AS ts, p.isQotation AS q, p.AssociatedCompanyID AS cid, c.company_name AS company
                FROM {$this->table} p LEFT JOIN {$this->table_clients} c ON c.Id = p.AssociatedCompanyID
                WHERE (" . implode(' OR ', $where) . ")
                ORDER BY (p.AssociatedCompanyID = %d) DESC, p.TimestampDateCommande DESC LIMIT 4";
        $args[] = $company_id;
        $rows = $wpdb->get_results($wpdb->prepare($sql, $args));
        $items = [];
        foreach ((array) $rows as $r) {
            $items[] = [
                'name'    => stripslashes((string) $r->name),
                'company' => (string) $r->company,
                'date'    => $r->ts ? date_i18n(get_option('date_format'), (int) $r->ts) : '',
                'quote'   => !empty($r->q),
                'url'     => $this->project_url($r->deal),
            ];
        }
        wp_send_json_success(['items' => $items]);
    }

    /** Recherche d'un projet existant à copier (liste de recherche). */
    public function ajax_pc_search_projects() {
        check_ajax_referer('ispag_nonce', 'nonce');
        if (!current_user_can('manage_order')) wp_send_json(['results' => []]);
        global $wpdb;
        $term = sanitize_text_field(wp_unslash($_GET['q'] ?? ''));
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT p.hubspot_deal_id AS id, CONCAT(p.ObjetCommande, ' — ', IFNULL(c.company_name, '')) AS text
             FROM {$this->table} p LEFT JOIN {$this->table_clients} c ON c.Id = p.AssociatedCompanyID
             WHERE p.ObjetCommande LIKE %s OR p.NumCommande LIKE %s OR c.company_name LIKE %s
             ORDER BY p.TimestampDateCommande DESC LIMIT 20",
            '%' . $wpdb->esc_like($term) . '%', '%' . $wpdb->esc_like($term) . '%', '%' . $wpdb->esc_like($term) . '%'
        ));
        foreach ((array) $rows as $r) { $r->text = stripslashes((string) $r->text); }
        wp_send_json(['results' => $rows]);
    }

    /** Données d'un projet existant pour pré-remplir le formulaire (rien n'est copié en base : le projet est créé à l'enregistrement). */
    public function ajax_pc_prefill() {
        check_ajax_referer('ispag_nonce', 'nonce');
        if (!current_user_can('manage_order')) wp_send_json_error(['message' => 'forbidden'], 403);
        global $wpdb;
        $deal = sanitize_text_field(wp_unslash($_GET['deal_id'] ?? ''));
        $p = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table} WHERE hubspot_deal_id = %s LIMIT 1", $deal));
        if (!$p) wp_send_json_error(['message' => 'not found'], 404);
        $company = $p->AssociatedCompanyID ? $wpdb->get_row($wpdb->prepare("SELECT Id AS id, company_name AS text FROM {$this->table_clients} WHERE Id = %d", $p->AssociatedCompanyID)) : null;
        $engineer = ($p->ingenieur_id !== null && $p->ingenieur_id !== '' && ctype_digit((string) $p->ingenieur_id))
            ? $wpdb->get_row($wpdb->prepare("SELECT Id AS id, company_name AS text FROM {$this->table_clients} WHERE Id = %d", $p->ingenieur_id)) : null;
        $contact = null;
        if (!empty($p->AssociatedContactIDs) && ctype_digit((string) $p->AssociatedContactIDs)) {
            $u = get_userdata((int) $p->AssociatedContactIDs);
            if ($u) $contact = ['id' => (int) $u->ID, 'text' => $u->display_name . ' (' . $u->user_email . ')'];
        }
        wp_send_json_success([
            'quote'    => !empty($p->isQotation),
            'name'     => stripslashes((string) $p->ObjetCommande),
            'company'  => $company,
            'contact'  => $contact,
            'engineer' => $engineer,
            'submission' => (string) ($p->EnSoumission ?? ''),
        ]);
    }

    public function ajax_search_companies()
    {
        $user_id = get_current_user_id();
        $this->logger->log_user_action(self::LOG_NAME, 'ajax_search_companies_start', [], $user_id);

        check_ajax_referer('ispag_nonce', 'nonce');
        $this->logger->log_user_action(self::LOG_NAME, 'nonce_verified', [], $user_id);

        global $wpdb;
        $term = sanitize_text_field($_GET['q'] ?? '');
        $this->logger->log_user_action(self::LOG_NAME, 'search_term_received', ['term' => $term], $user_id);

        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT c.Id as id, CONCAT(c.company_name, ' (', c.Id, ' - ', IFNULL(NULLIF(c.city, ''), 'N/A'), ')') as text
             FROM {$this->table_clients} c
             WHERE (c.company_name LIKE %s OR c.Id LIKE %s) AND c.is_active = 1 LIMIT 30",
            '%' . $wpdb->esc_like($term) . '%',
            '%' . $wpdb->esc_like($term) . '%'
        ));

        $this->logger->log_db_change(self::LOG_NAME, $this->table_clients, 'SEARCH_COMPANIES', ['term' => $term, 'count' => count($results)], $user_id);
        wp_send_json(['results' => $results]);
    }

    public function ajax_search_ingenieurs()
    {
        $user_id = get_current_user_id();
        $this->logger->log_user_action(self::LOG_NAME, 'ajax_search_ingenieurs_start', [], $user_id);

        check_ajax_referer('ispag_nonce', 'nonce');
        $this->logger->log_user_action(self::LOG_NAME, 'nonce_verified', [], $user_id);

        global $wpdb;
        $term = sanitize_text_field($_GET['q'] ?? '');
        $this->logger->log_user_action(self::LOG_NAME, 'search_term_received', ['term' => $term], $user_id);

        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT c.Id as id,
                    CONCAT(c.company_name, ' (', c.Id, ' - ', IFNULL(NULLIF(c.city, ''), 'N/A'), ')') as text
            FROM {$this->table_clients} c
            WHERE (c.company_name LIKE %s OR c.Id LIKE %s)
            AND c.isIngenieur = 1
            AND c.is_active = 1
            LIMIT 30",
            '%' . $wpdb->esc_like($term) . '%',
            '%' . $wpdb->esc_like($term) . '%'
        ));

        $this->logger->log_db_change(self::LOG_NAME, $this->table_clients, 'SEARCH_INGENIEURS', ['term' => $term, 'count' => count($results)], $user_id);
        wp_send_json(['results' => $results]);
    }

    public function ajax_search_ispag_concurrents()
    {
        $user_id = get_current_user_id();
        $this->logger->log_user_action(self::LOG_NAME, 'ajax_search_ispag_concurrents_start', [], $user_id);

        check_ajax_referer('ispag_nonce', 'nonce');
        $this->logger->log_user_action(self::LOG_NAME, 'nonce_verified', [], $user_id);

        global $wpdb;
        $term = sanitize_text_field($_GET['q'] ?? '');
        $this->logger->log_user_action(self::LOG_NAME, 'search_term_received', ['term' => $term], $user_id);

        $table = $wpdb->prefix . 'achats_liste_commande';
        $this->logger->log_user_action(self::LOG_NAME, 'using_table_for_search', ['table' => $table], $user_id);

        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT DISTINCT EnSoumission AS id, EnSoumission AS text
            FROM $table
            WHERE EnSoumission LIKE %s
            AND EnSoumission != ''
            LIMIT 30",
            "%{$term}%"
        ));

        $this->logger->log_db_change(self::LOG_NAME, $table, 'SEARCH_CONCURRENTS', ['term' => $term, 'count' => count($results)], $user_id);

        $results = array_filter($results, function($item)
        {
            $user_id = get_current_user_id();
            $this->logger->log_user_action(self::LOG_NAME, 'filtering_concurrent_results', ['item_id' => $item->id], $user_id);
            return !empty($item->id);
        });

        $this->logger->log_user_action(self::LOG_NAME, 'concurrent_results_filtered', ['count' => count($results)], $user_id);
        wp_send_json(['results' => $results]);
    }

    public function ajax_search_contacts()
    {
        $user_id = get_current_user_id();
        $this->logger->log_user_action(self::LOG_NAME, 'ajax_search_contacts_start', [], $user_id);

        check_ajax_referer('ispag_nonce', 'nonce');
        $this->logger->log_user_action(self::LOG_NAME, 'nonce_verified', [], $user_id);

        global $wpdb;
        $term = sanitize_text_field($_GET['q'] ?? '');
        $company_id = intval($_GET['company_id'] ?? 0);

        $this->logger->log_user_action(self::LOG_NAME, 'search_params_received', ['term' => $term, 'company_id' => $company_id], $user_id);

        $query = "SELECT u.ID as id, CONCAT(u.display_name, ' (', u.user_email, ')') as text
                FROM {$wpdb->users} u
                LEFT JOIN {$wpdb->usermeta} m_status ON u.ID = m_status.user_id AND m_status.meta_key = %s";

        $where = ["(m_status.meta_value IS NULL OR m_status.meta_value != 'disabled')"];

        if ($company_id > 0)
        {
            $query .= " JOIN {$wpdb->usermeta} m_comp ON u.ID = m_comp.user_id";
            $where[] = $wpdb->prepare(
                "m_comp.meta_key = %s AND m_comp.meta_value LIKE %s",
                ISPAG_Crm_Contact_Constants::META_COMPANY_ID,
                '%' . $wpdb->esc_like((string)$company_id) . '%'
            );
            $this->logger->log_user_action(self::LOG_NAME, 'company_filter_added', ['company_id' => $company_id], $user_id);
        }

        if (!empty($term))
        {
            $where[] = $wpdb->prepare(
                "(u.display_name LIKE %s OR u.user_email LIKE %s)",
                '%' . $wpdb->esc_like($term) . '%',
                '%' . $wpdb->esc_like($term) . '%'
            );
            $this->logger->log_user_action(self::LOG_NAME, 'term_filter_added', ['term' => $term], $user_id);
        }

        $query .= " WHERE " . implode(' AND ', $where) . " LIMIT 30";
        $final_query = $wpdb->prepare($query, ISPAG_Crm_Contact_Constants::ACCOUNT_STATUS);

        $this->logger->log_db_change(self::LOG_NAME, $wpdb->users, 'SEARCH_CONTACTS', ['query' => $final_query], $user_id);

        wp_send_json(['results' => $wpdb->get_results($final_query)]);
    }

    public function ajax_search_contacts_for_subscribers()
    {
        $user_id = get_current_user_id();
        $this->logger->log_user_action(self::LOG_NAME, 'ajax_search_contacts_for_subscribers_start', [], $user_id);

        check_ajax_referer('ispag_nonce', 'nonce');
        $this->logger->log_user_action(self::LOG_NAME, 'nonce_verified', [], $user_id);

        global $wpdb;
        $term = sanitize_text_field($_GET['q'] ?? '');
        $this->logger->log_user_action(self::LOG_NAME, 'search_term_received', ['term' => $term], $user_id);

        $query = "SELECT u.ID as id, CONCAT(u.display_name, ' (', u.user_email, ')') as text
                FROM {$wpdb->users} u
                LEFT JOIN {$wpdb->usermeta} m_status ON u.ID = m_status.user_id AND m_status.meta_key = %s
                WHERE (m_status.meta_value IS NULL OR m_status.meta_value != 'disabled')";

        if (!empty($term))
        {
            $query .= $wpdb->prepare(
                " AND (u.display_name LIKE %s OR u.user_email LIKE %s)",
                '%' . $wpdb->esc_like($term) . '%',
                '%' . $wpdb->esc_like($term) . '%'
            );
            $this->logger->log_user_action(self::LOG_NAME, 'term_filter_added', ['term' => $term], $user_id);
        }

        $query .= " LIMIT 30";
        $final_query = $wpdb->prepare($query, ISPAG_Crm_Contact_Constants::ACCOUNT_STATUS);

        $this->logger->log_db_change(self::LOG_NAME, $wpdb->users, 'SEARCH_SUBSCRIBER_CONTACTS', ['query' => $final_query], $user_id);

        wp_send_json(['results' => $wpdb->get_results($final_query)]);
    }

    public function get_contact_names_by_ids()
    {
        $user_id = get_current_user_id();
        $this->logger->log_user_action(self::LOG_NAME, 'get_contact_names_by_ids_start', [], $user_id);

        check_ajax_referer('ispag_nonce', 'nonce');
        $this->logger->log_user_action(self::LOG_NAME, 'nonce_verified', [], $user_id);

        global $wpdb;
        $ids = isset($_POST['ids']) ? array_map('intval', $_POST['ids']) : [];

        if (empty($ids))
        {
            $this->logger->log_user_action(self::LOG_NAME, 'no_ids_provided', [], $user_id);
            wp_send_json_success(['data' => []]);
        }

        $this->logger->log_user_action(self::LOG_NAME, 'ids_received', ['ids' => $ids], $user_id);

        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $query = $wpdb->prepare(
            "SELECT ID as id, CONCAT(display_name, ' (', user_email, ')') as text
            FROM {$wpdb->users}
            WHERE ID IN ($placeholders)",
            $ids
        );

        $this->logger->log_db_change(self::LOG_NAME, $wpdb->users, 'FETCH_CONTACT_NAMES', ['ids' => $ids], $user_id);

        $results = $wpdb->get_results($query);
        $this->logger->log_user_action(self::LOG_NAME, 'contact_names_retrieved', ['count' => count($results)], $user_id);

        wp_send_json_success(['data' => $results]);
    }

    public function handle_form()
    {
        $user_id = get_current_user_id();
        // $this->logger->log_user_action(self::LOG_NAME, 'handle_form_start', [], $user_id);

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ispag_create_projet']))
        {
            $this->logger->log_user_action(self::LOG_NAME, 'form_submission_detected', [], $user_id);
            $this->save_project();
        }
    }

    private function save_project()
    {
        $user_id = get_current_user_id();
        $this->logger->log_user_action(self::LOG_NAME, 'save_project_start', [], $user_id);

        global $wpdb;
        $current_user_id = get_current_user_id();
        $timestamp = time();
        

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

        // $project_url = home_url('project-detail/' . $timestamp);
        $project_url = home_url($slug . '/' . $timestamp);


        $this->logger->log_user_action(self::LOG_NAME, 'project_data_prepared', ['timestamp' => $timestamp, 'project_url' => $project_url], $user_id);

        $data = [
            'ObjetCommande' => sanitize_text_field($_POST['ObjetCommande']),
            'AssociatedContactIDs' => intval($_POST['AssociatedContactIDs']),
            'AssociatedCompanyID' => intval($_POST['AssociatedCompanyID']),
            'ingenieur_id' => sanitize_text_field($_POST['Ingenieur'] ?? ''),
            'EnSoumission' => sanitize_text_field($_POST['EnSoumission'] ?? ''),
            'hubspot_deal_id' => $timestamp,
            'TimestampDateCommande' => $timestamp,
            'created_by' => $current_user_id,
            'project_manager' => $current_user_id,
            'isQotation' => (isset($_POST['isQotation']) && $_POST['isQotation'] === '1') ? 1 : NULL,
            'project_status' => 1,
            'Abonne' => '',
        ]; 

        $sales_coef = floatval( get_option( 'wpcb_sales_coef' ) );
        $company_id = isset( $data['AssociatedCompanyID'] ) ? absint( $data['AssociatedCompanyID'] ) : 0; // Id de ispag_companies

        if ( $company_id > 0 ) {
            global $wpdb;

            
            // $table_companies = ISPAG_Crm_Company_Constants::TABLE_NAME;
            // $company_id = $wpdb->get_var(
            //     $wpdb->prepare(
            //         "SELECT Id FROM {$table_companies} WHERE viag_id = %d LIMIT 1",
            //         $viag_id
            //     )
            // );


            // 2. Récupération du coefficient avec l'Id interne
            if ( $company_id && class_exists( 'ISPAG_Crm_Discount_Manager' ) ) {
                $discount_manager   = new ISPAG_Crm_Discount_Manager();
                $sales_coef         = $discount_manager->get_coef_by_company_id( $company_id );
                
                $this->logger->log_user_action(self::LOG_NAME, 'ISPAG_Crm_Discount_Manager sales_coef', ['sales_coef' => $sales_coef], $user_id);
            }
        }

        $data['sales_coef'] = $sales_coef;

        $this->logger->log_user_action(self::LOG_NAME, 'base_project_data_prepared', ['data' => $data], $user_id);

        if (!$data['isQotation'])
        {
            $data['NumCommande'] = sanitize_text_field($_POST['NumCommande'] ?? '');
            $data['customer_order_id'] = sanitize_text_field($_POST['customer_order_id'] ?? '');
            $this->logger->log_user_action(self::LOG_NAME, 'additional_fields_added', ['NumCommande' => $data['NumCommande'], 'customer_order_id' => $data['customer_order_id']], $user_id);
        }

        // Insérer le projet
        $inserted = $wpdb->insert($this->table, $data);
        $this->logger->log_db_change(self::LOG_NAME, $this->table, 'INSERT_PROJECT', ['data' => $data, 'result' => $inserted], $user_id);

        if ($inserted)
        {
            $project_id = $timestamp; // hubspot_deal_id utilisé comme ID
            $this->logger->log_user_action(self::LOG_NAME, 'project_inserted_successfully', ['project_id' => $project_id], $user_id);

            // NOTIFICATION À L'ADMIN ET AU PROPRIÉTAIRE DU CONTACT SI L'UTILISATEUR N'EST PAS MEMBRE ISPAG
            $user = wp_get_current_user();
            $forbidden_roles = ['achat_ispag', 'vente_ispag', 'administrator', 'membre_ispag'];
            $has_forbidden_role = false;

            $this->logger->log_user_action(self::LOG_NAME, 'checking_user_roles', ['user_id' => $current_user_id, 'roles' => $user->roles], $user_id);

            // Vérifier si l'utilisateur a l'un des rôles interdits
            foreach ($forbidden_roles as $role)
            {
                if (in_array($role, $user->roles))
                {
                    $has_forbidden_role = true;
                    $this->logger->log_user_action(self::LOG_NAME, 'forbidden_role_detected', ['role' => $role], $user_id);
                    break;
                }
            }

            // Si l'utilisateur N'A PAS de rôle ISPAG, notifier l'admin et le propriétaire du contact
            if (!$has_forbidden_role && class_exists('ISPAG_Notifications_Manager'))
            {
                $this->logger->log_user_action(self::LOG_NAME, 'preparing_notification_for_non_ispag_user', [], $user_id);

                $company_name = '';
                if (!empty($data['AssociatedCompanyID']))
                {
                    $company_name = $wpdb->get_var($wpdb->prepare(
                        "SELECT company_name FROM {$this->table_clients} WHERE Id = %d",
                        $data['AssociatedCompanyID']
                    ));
                    $this->logger->log_db_change(self::LOG_NAME, $this->table_clients, 'FETCH_COMPANY_NAME', ['company_id' => $data['AssociatedCompanyID'], 'company_name' => $company_name], $user_id);
                }

                $contact_name = '';
                $contact_user = null;
                if (!empty($data['AssociatedContactIDs']))
                {
                    $contact_user = get_userdata($data['AssociatedContactIDs']);
                    $contact_name = $contact_user ? $contact_user->display_name : '';
                    $this->logger->log_db_change(self::LOG_NAME, $wpdb->users, 'FETCH_CONTACT_NAME', ['contact_id' => $data['AssociatedContactIDs'], 'contact_name' => $contact_name], $user_id);
                }

                // Récupérer le propriétaire du contact depuis la table wor9711_ispag_contacts_owners
                $contact_owner_id = null;
                if (!empty($data['AssociatedContactIDs']))
                {
                    $table_contacts_owners = $wpdb->prefix . 'ispag_contacts_owners';
                    $contact_owner = $wpdb->get_row($wpdb->prepare(
                        "SELECT user_id FROM $table_contacts_owners
                        WHERE contact_id = %d AND status = 'active'
                        ORDER BY assigned_at DESC LIMIT 1",
                        $data['AssociatedContactIDs']
                    ));
                    $contact_owner_id = $contact_owner ? $contact_owner->user_id : null;
                    $this->logger->log_db_change(self::LOG_NAME, $table_contacts_owners, 'FETCH_CONTACT_OWNER', ['contact_id' => $data['AssociatedContactIDs'], 'contact_owner_id' => $contact_owner_id], $user_id);
                }

                // Titre et message traduisibles
                $title = sprintf(
                    esc_html(__('📋 New project created: %s', 'creation-reservoir')),
                    esc_html($data['ObjetCommande'])
                );

                $message = sprintf(
                    esc_html(__(
                        'A new project has been created by <strong>%1$s</strong>:<br>
                        - <strong>Project name</strong>: %2$s<br>
                        - <strong>Company</strong>: %3$s<br>
                        - <strong>Contact</strong>: %4$s<br>
                        - <strong>Type</strong>: %5$s',
                        'creation-reservoir'
                    )),
                    esc_html($user->display_name),
                    esc_html($data['ObjetCommande']),
                    esc_html($company_name),
                    esc_html($contact_name),
                    $data['isQotation'] ? esc_html(__('Quotation', 'creation-reservoir')) : esc_html(__('Project', 'creation-reservoir'))
                );

                $this->logger->log_user_action(self::LOG_NAME, 'notification_content_prepared', ['title' => $title, 'message' => $message], $user_id);

                // Destinataires : Admin (ID = 1) + propriétaire du contact (si différent et valide)
                $recipients = [1]; // Admin par défaut
                if ($contact_owner_id && $contact_owner_id != 1 && $contact_owner_id != $current_user_id)
                {
                    $recipients[] = $contact_owner_id;
                    $this->logger->log_user_action(self::LOG_NAME, 'contact_owner_added_to_recipients', ['contact_owner_id' => $contact_owner_id], $user_id);
                }

                $this->logger->log_user_action(self::LOG_NAME, 'sending_notification_to_recipients', ['recipients' => $recipients], $user_id);

                // Envoyer la notification
                $result = ISPAG_Notifications_Manager::send(
                    $recipients, // Destinataires
                    'deal_manager', // Type de notification
                    $title,
                    $message,
                    $project_url, // URL vers le projet
                    $project_id // ID du projet (hubspot_deal_id)
                );

                $this->logger->log_user_action(self::LOG_NAME, 'notification_sent_result', ['result' => $result], $user_id);
            }

            $this->logger->log_user_action(self::LOG_NAME, 'redirecting_to_project_url', ['url' => $project_url], $user_id);
            wp_redirect($project_url);
            exit;
        }
        else
        {
            $this->logger->log(self::LOG_NAME, 'ERROR: Failed to insert project', $user_id, ['wpdb_error' => $wpdb->last_error]);
        }
    }

    public function render_form($atts)
    {
        $user_id = get_current_user_id();
        $this->logger->log_user_action(self::LOG_NAME, 'render_form_start', [], $user_id);

        self::$shortcode_called = true;
        $this->logger->log_user_action(self::LOG_NAME, 'shortcode_called_set_to_true', [], $user_id);

        global $wpdb;

        // 1. Attributs (Ex: [ispag_creation_projet qotation="1"])
        $atts = shortcode_atts(['qotation' => null], $atts);
        $is_qotation_default = ($atts['qotation'] === '1');
        $this->logger->log_user_action(self::LOG_NAME, 'form_attributes_parsed', ['is_qotation_default' => $is_qotation_default], $user_id);

        $can_manage_order = current_user_can('manage_order');
        $this->logger->log_user_action(self::LOG_NAME, 'user_capability_checked', ['can_manage_order' => $can_manage_order], $user_id);

        $current_user_id = get_current_user_id();
        $current_user = get_userdata($current_user_id);
        $current_company_id = get_user_meta($current_user_id, ISPAG_Crm_Contact_Constants::META_COMPANY_ID, true);

        $this->logger->log_db_change(self::LOG_NAME, $wpdb->usermeta, 'FETCH_COMPANY_ID', ['user_id' => $current_user_id, 'company_id' => $current_company_id], $user_id);

        $current_company_text = "";
        if ($current_company_id)
        {
            $current_company_text = $wpdb->get_var($wpdb->prepare("SELECT company_name FROM {$this->table_clients} WHERE Id = %d", $current_company_id));
            $this->logger->log_db_change(self::LOG_NAME, $this->table_clients, 'FETCH_COMPANY_TEXT', ['company_id' => $current_company_id, 'company_text' => $current_company_text], $user_id);
        }

        $ingenieurs = $wpdb->get_col("SELECT DISTINCT company_name FROM {$this->table_clients} WHERE isIngenieur = 1 ORDER BY company_name ASC");
        $this->logger->log_db_change(self::LOG_NAME, $this->table_clients, 'FETCH_INGENIEURS', ['count' => count($ingenieurs)], $user_id);

        $soumissions = $wpdb->get_col("SELECT DISTINCT EnSoumission FROM {$this->table} WHERE EnSoumission IS NOT NULL AND EnSoumission != '' ORDER BY EnSoumission ASC");
        $this->logger->log_db_change(self::LOG_NAME, $this->table, 'FETCH_SOUMISSIONS', ['count' => count($soumissions)], $user_id);

        $title = $is_qotation_default ? __('New quotation request', 'creation-reservoir') : __('New project', 'creation-reservoir');
        $this->logger->log_user_action(self::LOG_NAME, 'form_title_set', ['title' => $title], $user_id);

        ob_start();
        ?>
        <form method="post" id="ispag-project-form" class="pc-form<?php echo $is_qotation_default ? ' is-quote' : ''; ?>" novalidate>
            <header class="pc-head">
                <h3><?php echo esc_html($title); ?></h3>
            </header>

            <?php if ($can_manage_order): ?>
                <div class="pc-type" role="group" aria-label="<?php esc_attr_e('Type', 'creation-reservoir'); ?>">
                    <button type="button" class="pc-type-btn<?php echo $is_qotation_default ? '' : ' active'; ?>" data-quote="0"><?php _e('Project', 'creation-reservoir'); ?></button>
                    <button type="button" class="pc-type-btn<?php echo $is_qotation_default ? ' active' : ''; ?>" data-quote="1"><?php _e('Quotation', 'creation-reservoir'); ?></button>
                </div>
            <?php endif; ?>
            <input type="hidden" name="isQotation" id="pc-isqotation" value="<?php echo $is_qotation_default ? '1' : '0'; ?>">

            <?php if ($can_manage_order): ?>
                <section class="pc-card pc-copy">
                    <label for="pc-copy"><?php _e('Start from an existing project', 'creation-reservoir'); ?> <span class="pc-opt"><?php _e('(optional)', 'creation-reservoir'); ?></span></label>
                    <select id="pc-copy" style="width:100%;"><option value=""></option></select>
                </section>
            <?php endif; ?>

            <section class="pc-card">
                <div class="pc-field">
                    <label for="pc-name"><?php _e('Project name', 'creation-reservoir'); ?> <span class="pc-req">*</span></label>
                    <input type="text" name="ObjetCommande" id="pc-name" required autocomplete="off">
                    <div class="pc-error" id="pc-name-error" hidden></div>
                    <div class="pc-dupes" id="pc-dupes" hidden></div>
                </div>
            </section>

            <?php if (!$can_manage_order): ?>
                <!-- Utilisateur standard : seuls le nom est visible ; entreprise, contact et type sont transmis sans être affichés -->
                <input type="hidden" name="AssociatedCompanyID" value="<?php echo esc_attr($current_company_id); ?>">
                <input type="hidden" name="AssociatedContactIDs" value="<?php echo esc_attr($current_user_id); ?>">
            <?php else: ?>
            <section class="pc-card">
                <div class="pc-field">
                    <div class="pc-label-row">
                        <label for="pc-company"><?php _e('Company', 'creation-reservoir'); ?></label>
                        <?php if ($can_manage_order): ?><button type="button" class="pc-link" data-pc-open="company">+ <?php _e('New company', 'creation-reservoir'); ?></button><?php endif; ?>
                    </div>
                    <?php if ($can_manage_order): ?>
                        <select name="AssociatedCompanyID" id="pc-company" style="width:100%;">
                            <?php if ($current_company_id): ?>
                                <option value="<?php echo esc_attr($current_company_id); ?>" selected><?php echo esc_html($current_company_text); ?></option>
                            <?php endif; ?>
                        </select>
                    <?php else: ?>
                        <input type="text" value="<?php echo esc_attr($current_company_text); ?>" disabled>
                        <input type="hidden" name="AssociatedCompanyID" id="pc-company" value="<?php echo esc_attr($current_company_id); ?>">
                    <?php endif; ?>
                </div>

                <?php if ($can_manage_order): ?>
                <div class="pc-inline" id="pc-new-company" hidden>
                    <strong><?php _e('New company', 'creation-reservoir'); ?></strong>
                    <input type="text" data-f="company_name" placeholder="<?php esc_attr_e('Company name', 'creation-reservoir'); ?> *">
                    <input type="text" data-f="city" placeholder="<?php esc_attr_e('City', 'creation-reservoir'); ?>">
                    <input type="text" data-f="phone" placeholder="<?php esc_attr_e('Phone', 'creation-reservoir'); ?>">
                    <input type="email" data-f="email" placeholder="<?php esc_attr_e('Email', 'creation-reservoir'); ?>">
                    <div class="pc-error" hidden></div>
                    <div class="pc-inline-actions">
                        <button type="button" class="pc-btn pc-btn-ghost" data-pc-cancel><?php _e('Cancel', 'creation-reservoir'); ?></button>
                        <button type="button" class="pc-btn" data-pc-submit="company"><?php _e('Create', 'creation-reservoir'); ?></button>
                    </div>
                </div>
                <?php endif; ?>

                <div class="pc-field">
                    <div class="pc-label-row">
                        <label for="pc-contact"><?php _e('Contact', 'creation-reservoir'); ?></label>
                        <?php if ($can_manage_order): ?><button type="button" class="pc-link" data-pc-open="contact">+ <?php _e('New contact', 'creation-reservoir'); ?></button><?php endif; ?>
                    </div>
                    <?php if ($can_manage_order): ?>
                        <select name="AssociatedContactIDs" id="pc-contact" style="width:100%;">
                            <option value="<?php echo esc_attr($current_user_id); ?>" selected><?php echo esc_html($current_user->display_name); ?></option>
                        </select>
                    <?php else: ?>
                        <input type="text" value="<?php echo esc_attr($current_user->display_name); ?>" disabled>
                        <input type="hidden" name="AssociatedContactIDs" id="pc-contact" value="<?php echo esc_attr($current_user_id); ?>">
                    <?php endif; ?>
                </div>

                <?php if ($can_manage_order): ?>
                <div class="pc-inline" id="pc-new-contact" hidden>
                    <strong><?php _e('New contact', 'creation-reservoir'); ?></strong>
                    <input type="email" data-f="email" placeholder="<?php esc_attr_e('Email', 'creation-reservoir'); ?> *">
                    <input type="text" data-f="first_name" placeholder="<?php esc_attr_e('First name', 'creation-reservoir'); ?>">
                    <input type="text" data-f="last_name" placeholder="<?php esc_attr_e('Last name', 'creation-reservoir'); ?>">
                    <input type="text" data-f="phone" placeholder="<?php esc_attr_e('Phone', 'creation-reservoir'); ?>">
                    <div class="pc-error" hidden></div>
                    <div class="pc-inline-actions">
                        <button type="button" class="pc-btn pc-btn-ghost" data-pc-cancel><?php _e('Cancel', 'creation-reservoir'); ?></button>
                        <button type="button" class="pc-btn" data-pc-submit="contact"><?php _e('Create', 'creation-reservoir'); ?></button>
                    </div>
                </div>
                <?php endif; ?>
            </section>
            <?php endif; ?>

            <?php if ($can_manage_order): ?>
                <section class="pc-card pc-only-quote">
                    <div class="pc-field">
                        <label for="pc-engineer"><?php _e('Engineer', 'creation-reservoir'); ?></label>
                        <select name="Ingenieur" id="pc-engineer" style="width:100%;"><option value=""></option></select>
                    </div>
                    <div class="pc-field">
                        <label for="pc-submission"><?php _e('In submission', 'creation-reservoir'); ?></label>
                        <input list="soumissions" name="EnSoumission" id="pc-submission">
                        <datalist id="soumissions">
                            <?php foreach ($soumissions as $sou): ?>
                                <option value="<?php echo esc_attr($sou); ?>">
                            <?php endforeach; ?>
                        </datalist>
                    </div>
                </section>
            <?php endif; ?>

            <?php if ($can_manage_order): ?>
            <section class="pc-card pc-only-project">
                <div class="pc-field">
                    <label for="pc-num"><?php _e('Project number', 'creation-reservoir'); ?></label>
                    <input type="text" name="NumCommande" id="pc-num" autocomplete="off">
                </div>
                <div class="pc-field">
                    <label for="pc-order"><?php _e('Order number', 'creation-reservoir'); ?></label>
                    <input type="text" name="customer_order_id" id="pc-order" autocomplete="off">
                </div>
            </section>
            <?php endif; ?>

            <div class="pc-actions">
                <button type="submit" name="ispag_create_projet" class="pc-btn pc-btn-primary" id="pc-save">
                    <span class="dashicons dashicons-media-archive"></span> <?php _e('Save', 'creation-reservoir'); ?>
                </button>
            </div>
        </form>
        <?php
        $html = ob_get_clean();
        $this->logger->log_user_action(self::LOG_NAME, 'form_rendered', [], $user_id);
        return $html;
    }
}