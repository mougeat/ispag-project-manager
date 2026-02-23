<?php

class ISPAG_Projet_Creation {
    const META_COMPANY_CITY = 'ispag_company_city';
    const META_STATUS       = 'ispag_account_status';

    private $table;
    private $table_users;
    private $table_clients;

    public function __construct() {
        global $wpdb;
        $this->table         = $wpdb->prefix . 'achats_liste_commande';
        $this->table_users   = $wpdb->prefix . 'users';
        $this->table_clients = $wpdb->prefix . 'ispag_companies';

        add_shortcode('ispag_creation_projet', [$this, 'render_form']);
        add_action('init', [$this, 'handle_form']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_scripts']);

        add_action('wp_ajax_search_ispag_companies', [$this, 'ajax_search_companies']);
        add_action('wp_ajax_search_ispag_contacts', [$this, 'ajax_search_contacts']);
    }

    public function enqueue_scripts() {
        wp_enqueue_style('select2-css', 'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css');
        wp_enqueue_script('select2-js', 'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js', ['jquery'], '4.1.0', true);
        wp_enqueue_script('creation-projets-js', plugin_dir_url(__FILE__) . '../assets/js/creation-projets.js', ['jquery', 'select2-js'], '1.2', true);

        wp_localize_script('creation-projets-js', 'ispag_ajax_object', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('ispag_nonce')
        ]);
    }

    public function ajax_search_companies() {
        check_ajax_referer('ispag_nonce', 'nonce');
        global $wpdb;
        $term = sanitize_text_field($_GET['q'] ?? '');
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT c.viag_id as id, CONCAT(c.company_name, ' (', c.viag_id, ' - ', IFNULL(m.meta_value, 'N/A'), ')') as text
             FROM {$this->table_clients} c
             LEFT JOIN {$wpdb->postmeta} m ON c.viag_id = m.post_id AND m.meta_key = %s
             WHERE c.company_name LIKE %s OR c.viag_id LIKE %s LIMIT 30",
            self::META_COMPANY_CITY, '%' . $wpdb->esc_like($term) . '%', '%' . $wpdb->esc_like($term) . '%'
        ));
        wp_send_json(['results' => $results]);
    }

    public function ajax_search_contacts() {
        check_ajax_referer('ispag_nonce', 'nonce');
        global $wpdb;
        $term = sanitize_text_field($_GET['q'] ?? '');
        $company_id = intval($_GET['company_id'] ?? 0);

        $query = "SELECT u.ID as id, CONCAT(u.display_name, ' (', u.user_email, ')') as text 
                  FROM {$wpdb->users} u 
                  LEFT JOIN {$wpdb->usermeta} m_status ON u.ID = m_status.user_id AND m_status.meta_key = %s ";
        
        $where = ["(m_status.meta_value IS NULL OR m_status.meta_value != 'disabled')"];
        if ($company_id > 0) {
            $query .= " JOIN {$wpdb->usermeta} m_comp ON u.ID = m_comp.user_id ";
            $where[] = $wpdb->prepare("m_comp.meta_key = %s AND m_comp.meta_value = %d", ISPAG_Crm_Contact_Constants::META_COMPANY_ID, $company_id);
        }
        if (!empty($term)) {
            $where[] = $wpdb->prepare("(u.display_name LIKE %s OR u.user_email LIKE %s)", '%' . $wpdb->esc_like($term) . '%', '%' . $wpdb->esc_like($term) . '%');
        }
        $query .= " WHERE " . implode(' AND ', $where) . " LIMIT 30";
        wp_send_json(['results' => $wpdb->get_results($wpdb->prepare($query, self::META_STATUS))]);
    }

    public function handle_form() {
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ispag_create_projet'])) {
            $this->save_project();
        }
    }

    private function save_project() {
        global $wpdb;
        $current_user_id = get_current_user_id();
        $timestamp = time(); 
        $project_url = 'https://app.ispag-asp.ch/details-du-projet/?deal_id=' . $timestamp;

        $data = [
            'ObjetCommande'         => sanitize_text_field($_POST['ObjetCommande']),
            'AssociatedContactIDs'  => intval($_POST['AssociatedContactIDs']),
            'AssociatedCompanyID'   => intval($_POST['AssociatedCompanyID']),
            'ingenieur_id'          => sanitize_text_field($_POST['Ingenieur']), // Stockage direct ou ID selon votre besoin
            'EnSoumission'          => sanitize_text_field($_POST['EnSoumission'] ?? ''),
            'hubspot_deal_id'       => $timestamp,
            'TimestampDateCommande' => $timestamp,
            'created_by'            => $current_user_id,
            'isQotation'            => isset($_POST['isQotation']) ? 1 : 0,
            'project_status'        => 1,
            'Abonne'                => ';0;' . $current_user_id . ';',
        ];

        if (!$data['isQotation']) {
            $data['NumCommande'] = sanitize_text_field($_POST['NumCommande'] ?? '');
            $data['customer_order_id'] = sanitize_text_field($_POST['customer_order_id'] ?? '');
        }

        if ($wpdb->insert($this->table, $data)) {
            wp_redirect($project_url);
            exit;
        }
    }

    public function render_form($atts) {
        global $wpdb;
        
        // 1. Gestion des attributs (Ex: [ispag_creation_projet qotation="1"])
        $atts = shortcode_atts(['qotation' => null], $atts);
        $is_qotation_default = ($atts['qotation'] === '1');

        $current_user_id = get_current_user_id();
        $current_user = get_userdata($current_user_id);
        $current_company_id = get_user_meta($current_user_id, ISPAG_Crm_Contact_Constants::META_COMPANY_ID, true);
        
        $current_company_text = "";
        if ($current_company_id) {
            $current_company_text = $wpdb->get_var($wpdb->prepare("SELECT company_name FROM {$this->table_clients} WHERE viag_id = %d", $current_company_id));
        }

        // 2. Récupération des listes pour Datalist (Ingénieurs et Concurrents)
        $ingenieurs = $wpdb->get_col("SELECT DISTINCT company_name FROM {$this->table_clients} WHERE isIngenieur = 1 ORDER BY company_name ASC");
        $soumissions = $wpdb->get_col("SELECT DISTINCT EnSoumission FROM {$this->table} WHERE EnSoumission IS NOT NULL AND EnSoumission != '' ORDER BY EnSoumission ASC");

        ob_start(); ?>
        <form method="post" id="ispag-project-form" style="max-width:500px; margin:20px auto; font-family:sans-serif; background:#f9f9f9; padding:20px; border-radius:8px; box-shadow:0 2px 10px rgba(0,0,0,0.1);">
            <h3 style="text-align:center; border-bottom:2px solid #ddd; padding-bottom:10px;"><?php _e('New project', 'creation-reservoir'); ?></h3>

            <div style="margin-bottom:15px;">
                <label style="display:block; font-weight:bold; margin-bottom:5px;"><?php _e('Project name', 'creation-reservoir'); ?></label>
                <input type="text" name="ObjetCommande" required style="width:100%; padding:10px; border:1px solid #ccc; border-radius:4px;">
            </div>

            <div style="margin-bottom:15px;">
                <label style="display:block; font-weight:bold; margin-bottom:5px;"><?php _e('Company', 'creation-reservoir'); ?></label>
                <select name="AssociatedCompanyID" id="company-select" style="width:100%;">
                    <?php if ($current_company_id): ?>
                        <option value="<?= $current_company_id ?>" selected><?= esc_html($current_company_text) ?></option>
                    <?php endif; ?>
                </select>
            </div>

            <div style="margin-bottom:15px;">
                <label style="display:block; font-weight:bold; margin-bottom:5px;"><?php _e('Contact', 'creation-reservoir'); ?></label>
                <select name="AssociatedContactIDs" id="contact-select" style="width:100%;">
                    <option value="<?= $current_user_id ?>" selected><?= esc_html($current_user->display_name) ?></option>
                </select>
            </div>

            <div style="margin-bottom:15px;">
                <label style="display:block; font-weight:bold; margin-bottom:5px;"><?php _e('Engineer', 'creation-reservoir'); ?></label>
                <input list="ingenieurs" name="Ingenieur" style="width:100%; padding:10px; border:1px solid #ccc; border-radius:4px;">
                <datalist id="ingenieurs">
                    <?php foreach ($ingenieurs as $eng): ?>
                        <option value="<?= esc_attr($eng) ?>">
                    <?php endforeach; ?>
                </datalist>
            </div>

            <div style="margin-bottom:15px;">
                <label style="display:block; font-weight:bold; margin-bottom:5px;"><?php _e('In submission', 'creation-reservoir'); ?></label>
                <input list="soumissions" name="EnSoumission" style="width:100%; padding:10px; border:1px solid #ccc; border-radius:4px;">
                <datalist id="soumissions">
                    <?php foreach ($soumissions as $sou): ?>
                        <option value="<?= esc_attr($sou) ?>">
                    <?php endforeach; ?>
                </datalist>
            </div>

            <div style="margin-bottom:15px;">
                <input type="checkbox" name="isQotation" id="isQotation" onchange="toggleCommandeFields()" <?= $is_qotation_default ? 'checked' : '' ?>> 
                <label for="isQotation" style="font-weight:bold;"><?php _e('Is submission', 'creation-reservoir'); ?></label>
            </div>

            <div id="commandeFields">
                <div style="margin-bottom:15px;">
                    <label style="display:block; font-weight:bold; margin-bottom:5px;"><?php _e('Project nb', 'creation-reservoir'); ?></label>
                    <input type="text" name="NumCommande" style="width:100%; padding:10px; border:1px solid #ccc; border-radius:4px;">
                </div>
                <div style="margin-bottom:15px;">
                    <label style="display:block; font-weight:bold; margin-bottom:5px;"><?php _e('Order number', 'creation-reservoir'); ?></label>
                    <input type="text" name="customer_order_id" style="width:100%; padding:10px; border:1px solid #ccc; border-radius:4px;">
                </div>
            </div>

            <button type="submit" name="ispag_create_projet" class="button" style="width:100%;  border:none; padding:12px; border-radius:4px; cursor:pointer; font-weight:bold;">
                <span class="dashicons dashicons-media-archive" style="vertical-align:middle;"></span> <?php _e('Save', 'creation-reservoir'); ?>
            </button>
        </form>

        <script>
            function toggleCommandeFields() {
                const isQuote = document.getElementById('isQotation').checked;
                const fields = document.getElementById('commandeFields');
                if(fields) fields.style.display = isQuote ? 'none' : 'block';
            }
            // Exécution immédiate au chargement pour respecter l'état par défaut du shortcode
            document.addEventListener('DOMContentLoaded', toggleCommandeFields);
        </script>
        <?php
        return ob_get_clean();
    }
}