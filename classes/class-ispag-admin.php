<?php

class ISPAG_Admin {

    public function __construct() {
        add_action('admin_menu', [$this, 'add_admin_menus']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('wp_ajax_ispag_get_doc_types', [$this, 'ajax_get_doc_types']);
        add_action('wp_ajax_ispag_save_doc_type', [$this, 'ajax_save_doc_type']);
        add_action('wp_ajax_ispag_reorder_doc_types', [$this, 'ajax_reorder_doc_types']);
    }

    public function add_admin_menus() {
        add_menu_page(
            'ISPAG', 
            'ISPAG', 
            'manage_options', 
            'ispag_main_menu', 
            [$this, 'render_main_dashboard'], 
            'dashicons-admin-generic', 
            25
        );
        // Correction ici : méthode au singulier, pas pluriel
        add_submenu_page(
            'ispag_main_menu', 
            'Types de documents', 
            'Types de documents', 
            'manage_options', 
            'ispag_doc_types', 
            [$this, 'render_doc_type_page']
        );
    }

    public function enqueue_assets($hook) {
        // Charge les scripts uniquement sur les pages ISPAG admin
        if ($hook !== 'toplevel_page_ispag_main_menu' && $hook !== 'ispag_page_ispag_doc_types') {
            return;
        }

        wp_enqueue_style('ispag-admin-style', plugin_dir_url(__FILE__) . '../assets/css/admin.css');
        wp_enqueue_script('jquery-ui-sortable');
        wp_enqueue_script(
            'ispag-doc-types-js', 
            plugin_dir_url(__FILE__) . '../assets/js/admin.js', 
            ['jquery'], 
            null, 
            true
        );
        wp_localize_script('ispag-doc-types-js', 'ISPAG_DOC_TYPES', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('ispag_doc_nonce')
        ]);
    }

    public function render_main_dashboard() {
        echo '<div class="wrap"><h1>Bienvenue dans ISPAG</h1></div>';
    }

    public function render_doc_type_page() {
        global $wpdb;
        $table = $wpdb->prefix . 'achats_doc_types';
        $doc_types = $wpdb->get_results("SELECT * FROM $table ORDER BY sort_order ASC");

        echo '<div class="wrap"><h1>Types de documents</h1>';
        echo '<table id="ispag-doc-type-table">';
        echo '<thead><tr><th>Sort</th><th>Classe CSS</th><th>Nom</th><th>Actions</th></tr></thead><tbody>';

        foreach ($doc_types as $doc) {
            echo '<tr data-id="' . intval($doc->id) . '">';
            echo '<td class="sort-handle" style="cursor:move;">☰</td>';
            echo '<td><input type="text" class="doc-slug" value="' . esc_attr($doc->slug) . '"></td>';
            echo '<td><input type="text" class="doc-label" value="' . esc_attr($doc->label) . '"></td>';
            // Checkbox restricted
            $checked = !empty($doc->restricted) ? 'checked' : '';
            echo '<td><input type="checkbox" class="doc-restricted" ' . $checked . '></td>';
            echo '<td><button class="save-doc-type button button-primary"><span class="dashicons dashicons-media-archive"></span> ' . __('Save', 'creation-reservoir') . '</button></td>';
            echo '</tr>';
        }

        echo '</tbody></table>';

        // Formulaire d'ajout d'un nouveau type de document
        echo '<div id="new-doc-type" style="margin-top:20px;">';
        echo '<input type="text" id="new-doc-label" placeholder="' . esc_attr__('New typ name', 'creation-reservoir') . '" style="margin-right:10px;">';
        echo '<input type="text" id="new-doc-slug" placeholder="' . esc_attr__('Classe CSS', 'creation-reservoir') . '" style="margin-right:10px;">';
        echo '<td><input type="checkbox" class="new-doc-restricted"></td>';
        echo '<button id="add-doc-type" class="button button-secondary">' . __('Add', 'creation-reservoir') . '</button>';
        echo '</div>';

        echo '</div>';
    }

    public function ajax_get_doc_types() {
        check_ajax_referer('ispag_doc_nonce');
        global $wpdb;
        $table = $wpdb->prefix . 'achats_doc_types';
        $results = $wpdb->get_results("SELECT * FROM $table ORDER BY sort ASC");
        wp_send_json_success($results);
    }

    public function ajax_save_doc_type() {
        check_ajax_referer('ispag_doc_nonce');
        global $wpdb;
        $table = $wpdb->prefix . 'achats_doc_types';

        $data = [
            'slug'  => sanitize_text_field($_POST['slug'] ?? ''),
            'slug'  => sanitize_text_field($_POST['label'] ?? ''),
            'restricted' => sanitize_text_field($_POST['restricted'] ?? ''),
        ];

        if (!empty($_POST['id'])) {
            $wpdb->update($table, $data, ['Id' => intval($_POST['id'])]);
        } else {
            $max_sort = $wpdb->get_var("SELECT MAX(sort) FROM $table");
            $data['sort'] = intval($max_sort) + 1;
            $wpdb->insert($table, $data);
        }

        if ($wpdb->last_error) {
            wp_send_json_error(['message' => $wpdb->last_error]);
        }

        wp_send_json_success();
    }

    public function ajax_reorder_doc_types() {
        check_ajax_referer('ispag_doc_nonce');
        global $wpdb;
        $table = $wpdb->prefix . 'achats_doc_types';

        $order = $_POST['order'] ?? [];
        foreach ($order as $index => $id) {
            $wpdb->update($table, ['sort' => $index], ['Id' => intval($id)]);
        }

        if ($wpdb->last_error) {
            wp_send_json_error(['message' => $wpdb->last_error]);
        }

        wp_send_json_success();
    }
}
