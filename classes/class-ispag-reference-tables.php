<?php
defined('ABSPATH') || exit;

/**
 * Éditeur des tables de référence ISPAG (menu ISPAG Settings → Reference tables).
 *
 * Un seul écran, un onglet par table, présentation identique : liste, formulaire d'ajout / modification, suppression.
 * Les tables sont déclarées dans un registre (filtre `ispag_reference_tables`) : chaque plugin déclare les siennes
 * (ce plugin : types de prestations, phases, statuts de phase, types de documents ; le CRM : étapes des deals,
 * statuts des leads, cycle de vie, motifs de refus). Une table absente de la base n'affiche pas d'onglet.
 *
 * Définition d'une table :
 *   'key' => [
 *     'title'       => 'Libellé de l'onglet',
 *     'table'       => 'nom_sans_prefixe',
 *     'pk'          => 'Id',
 *     'description' => 'aide affichée sous le titre',
 *     'order'       => 'colonne de tri',               // facultatif
 *     'columns'     => [ colonne => ['label','type','required','unique','list','help','options','default','readonly'] ],
 *     'usage'       => [ ['table' => 'x', 'column' => 'y', 'value' => 'key'|'pk', 'usermeta' => 'meta_key'?] ], // facultatif
 *     'on_change'   => callable,                        // facultatif : appelé après ajout / modification / suppression
 *   ]
 * Types : text, textarea, key (identifiant technique unique), int, decimal, bool, color, css, select, media, datetime.
 * Droit requis : manage_options.
 */
class ISPAG_Reference_Tables {

    const PAGE  = 'ispag-reference-tables';
    const NONCE = 'ispag_reference_tables';

    public static function init() {
        add_action('admin_menu', [self::class, 'menu'], 30);
        add_action('admin_post_ispag_ref_save', [self::class, 'handle_save']);
        add_action('admin_post_ispag_ref_delete', [self::class, 'handle_delete']);
        add_action('admin_enqueue_scripts', [self::class, 'enqueue']);
        add_action('init', [self::class, 'ensure_columns']);
        add_filter('ispag_default_supplier_for_type', [self::class, 'default_supplier_for_type'], 10, 2);
        add_filter('ispag_first_supplier_for_article', [self::class, 'first_supplier_for_article'], 10, 2);
    }

    /** Colonnes ajoutées aux types d'article : « fournisseur par défaut » et « choisissable par les utilisateurs sans droit de gestion ». */
    public static function ensure_columns() {
        if (get_option('ispag_ref_default_supplier_col') && get_option('ispag_ref_user_selectable_col')) return;
        global $wpdb;
        $table = $wpdb->prefix . 'achats_type_prestations';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) return;
        if (!$wpdb->get_var("SHOW COLUMNS FROM `{$table}` LIKE 'default_supplier_id'")) {
            $wpdb->query("ALTER TABLE `{$table}` ADD COLUMN `default_supplier_id` INT UNSIGNED NOT NULL DEFAULT 0");
        }
        if ($wpdb->get_var("SHOW COLUMNS FROM `{$table}` LIKE 'default_supplier_id'")) {
            update_option('ispag_ref_default_supplier_col', 1, true);
        }
        if (!$wpdb->get_var("SHOW COLUMNS FROM `{$table}` LIKE 'user_selectable'")) {
            $wpdb->query("ALTER TABLE `{$table}` ADD COLUMN `user_selectable` TINYINT(1) NOT NULL DEFAULT 0");
            // Les types existants restent choisissables (comportement actuel) ; l'administrateur décoche ensuite ceux à réserver
            $wpdb->query("UPDATE `{$table}` SET user_selectable = 1");
        }
        if ($wpdb->get_var("SHOW COLUMNS FROM `{$table}` LIKE 'user_selectable'")) {
            update_option('ispag_ref_user_selectable_col', 1, true);
        }
    }

    /**
     * Le type d'article peut-il être choisi par l'utilisateur courant ?
     * manage_order : tous les types ; les autres : seulement ceux cochés « Selectable by all users ».
     */
    public static function type_selectable($type_id) {
        if (current_user_can('manage_order')) return true;
        global $wpdb;
        $table = $wpdb->prefix . 'achats_type_prestations';
        if (!$wpdb->get_var("SHOW COLUMNS FROM `{$table}` LIKE 'user_selectable'")) return true; // colonne pas encore créée : comportement d'origine
        return (int) $wpdb->get_var($wpdb->prepare("SELECT user_selectable FROM `{$table}` WHERE Id = %d", (int) $type_id)) === 1;
    }

    /** Fournisseur par défaut d'un type d'article (Id de achats_type_prestations) ; $fallback si non défini. */
    public static function default_supplier_for_type($fallback, $type_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'achats_type_prestations';
        $supplier = (int) $wpdb->get_var($wpdb->prepare("SELECT default_supplier_id FROM `{$table}` WHERE Id = %d", (int) $type_id));
        return $supplier > 0 ? $supplier : $fallback;
    }

    /**
     * Fournisseur d'une ligne créée à partir d'un article standard : le premier fournisseur qui vend cet article
     * (table achats_articles_purchase, par ordre d'enregistrement) ; $fallback (ex. fournisseur par défaut du type) s'il n'y en a aucun.
     */
    public static function first_supplier_for_article($fallback, $standard_article_id) {
        $standard_article_id = (int) $standard_article_id;
        if ($standard_article_id <= 0) return $fallback;
        global $wpdb;
        $supplier = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT ap.supplier_id FROM {$wpdb->prefix}achats_articles_purchase ap
             INNER JOIN {$wpdb->prefix}ispag_companies c ON c.Id = ap.supplier_id
             WHERE ap.article_id = %d AND ap.supplier_id > 0 ORDER BY ap.Id ASC LIMIT 1",
            $standard_article_id
        ));
        return $supplier > 0 ? $supplier : $fallback;
    }

    /** Fournisseurs (Id => nom) proposés dans les listes. */
    private static function suppliers() {
        global $wpdb;
        $out = [];
        foreach ((array) $wpdb->get_results("SELECT Id, company_name FROM {$wpdb->prefix}ispag_companies WHERE isSupplier = 1 AND company_name <> '' ORDER BY company_name ASC") as $c) {
            $out[(int) $c->Id] = $c->company_name;
        }
        return $out;
    }

    // ------------------------------------------------------------------ Registre

    /** Tables de ce plugin. */
    public static function core_tables() {
        $phase_cache = function () {
            if (class_exists('ISPAG_Project_Phase_Catalog')) ISPAG_Project_Phase_Catalog::clear_cache();
        };
        return [
            'type_prestations' => [
                'title' => 'Article types', 'table' => 'achats_type_prestations', 'pk' => 'Id', 'order' => 'sort',
                'description' => 'Families of articles (product, insulation, welding…). The « service » code is used by the program: change it with care.',
                'columns' => [
                    'type'          => ['label' => 'Name', 'type' => 'text', 'required' => true, 'list' => true],
                    'prestation'    => ['label' => 'Service code', 'type' => 'text', 'list' => true, 'help' => 'Used by the program (e.g. Product, Isol, Welding, div).'],
                    'description'   => ['label' => 'Description', 'type' => 'textarea'],
                    'stockManaged'  => ['label' => 'Stock managed', 'type' => 'bool', 'list' => true],
                    'sort'          => ['label' => 'Order', 'type' => 'int', 'list' => true],
                    'color'         => ['label' => 'Color', 'type' => 'color', 'list' => true],
                    'image'         => ['label' => 'Image', 'type' => 'media', 'list' => true, 'help' => 'Chosen from the media library. Without an image, the icon supplied with the plugin is used.', 'fallback' => ['ISPAG_Type_Icons', 'url']],
                    'delivery_time' => ['label' => 'Delivery time', 'type' => 'text', 'help' => 'Default delivery time shown for this type.'],
                    'user_selectable' => ['label' => 'Selectable by all users', 'type' => 'bool', 'list' => true, 'help' => 'When unchecked, only users who can manage orders can pick this type when adding an article.'],
                    'default_supplier_id' => ['label' => 'Default supplier', 'type' => 'supplier', 'list' => true, 'help' => 'Supplier given to the articles created automatically for this type (e.g. insulation, welding). Not used for tanks.'],
                ],
                'usage' => [
                    ['table' => 'achats_articles', 'column' => 'TypeArticle', 'value' => 'pk'],
                    ['table' => 'achats_details_commande', 'column' => 'Type', 'value' => 'pk'],
                ],
                'on_change' => $phase_cache,
            ],
            'slug_phase' => [
                'title' => 'Project phases', 'table' => 'achats_slug_phase', 'pk' => 'Id', 'order' => 'Ordre',
                'description' => 'Steps of the follow-up of a project. The slug is used by the program to trigger and display phases.',
                'columns' => [
                    'Ordre'                 => ['label' => 'Order', 'type' => 'int', 'list' => true],
                    'SlugPhase'             => ['label' => 'Slug', 'type' => 'key', 'required' => true, 'unique' => true, 'list' => true],
                    'type_prestation'       => ['label' => 'Article type (slug)', 'type' => 'text', 'list' => true],
                    'TitrePhase'            => ['label' => 'Title', 'type' => 'text', 'required' => true, 'list' => true],
                    'TitrePhaseFuture'      => ['label' => 'Title (upcoming)', 'type' => 'text'],
                    'TitrePhaseProgression' => ['label' => 'Title (progress bar)', 'type' => 'text'],
                    'VisuClient'            => ['label' => 'Visible to customer', 'type' => 'bool', 'list' => true, 'default' => 1],
                    'VisuProgression'       => ['label' => 'Shown in progress bar', 'type' => 'bool', 'default' => 1],
                    'display_on_qotation'   => ['label' => 'Shown on quotations', 'type' => 'bool'],
                    'Color'                 => ['label' => 'Color', 'type' => 'css', 'help' => 'CSS color or variable, e.g. var(--ispag-blue).', 'options' => ['var(--ispag-blue)', 'var(--ispag-green)', 'var(--ispag-grey)', 'var(--ispag-orange)', 'var(--ispag-red)']],
                    'ActionText'            => ['label' => 'Action text', 'type' => 'text'],
                    'is_automatic'          => ['label' => 'Automatic', 'type' => 'bool', 'list' => true],
                    'waitNextStep'          => ['label' => 'Wait for next step', 'type' => 'bool'],
                    'CloseProject'          => ['label' => 'Closes the project', 'type' => 'bool'],
                    'Brevo_id'              => ['label' => 'Brevo template ID', 'type' => 'int'],
                    'Brevo_delay_days'      => ['label' => 'Brevo delay (days)', 'type' => 'int'],
                    'JsHook'                => ['label' => 'JS hook', 'type' => 'text'],
                ],
                'usage' => [['table' => 'achats_suivi_phase_commande', 'column' => 'slug_phase', 'value' => 'SlugPhase']],
                'on_change' => $phase_cache,
            ],
            'meta_phase' => [
                'title' => 'Phase statuses', 'table' => 'achats_meta_phase_commande', 'pk' => 'Id', 'order' => 'sort',
                'description' => 'Statuses a phase can take (done, in progress…). The CSS class drives the display.',
                'columns' => [
                    'sort'          => ['label' => 'Order', 'type' => 'int', 'list' => true],
                    'Nom'           => ['label' => 'Name', 'type' => 'text', 'required' => true, 'list' => true],
                    'TacheComplete' => ['label' => 'Task complete', 'type' => 'bool', 'list' => true],
                    'Couleur'       => ['label' => 'Color', 'type' => 'color', 'list' => true],
                    'ClasseCss'     => ['label' => 'CSS class', 'type' => 'text', 'list' => true],
                ],
                'usage' => [['table' => 'achats_suivi_phase_commande', 'column' => 'status_id', 'value' => 'pk']],
                'on_change' => $phase_cache,
            ],
            'doc_types' => [
                'title' => 'Document types', 'table' => 'achats_doc_types', 'pk' => 'id', 'order' => 'sort_order',
                'description' => 'Types of documents attached to projects and articles. The slug is used by the program.',
                'columns' => [
                    'sort_order'       => ['label' => 'Order', 'type' => 'int', 'list' => true],
                    'slug'             => ['label' => 'Slug', 'type' => 'key', 'required' => true, 'unique' => true, 'list' => true],
                    'label'            => ['label' => 'Label', 'type' => 'text', 'required' => true, 'list' => true],
                    'ajax_action'      => ['label' => 'AJAX action', 'type' => 'text', 'help' => 'Action run when a document of this type is added (leave empty for none).'],
                    'badge_class'      => ['label' => 'Badge CSS class', 'type' => 'text', 'list' => true],
                    'restricted'       => ['label' => 'Restricted (team only)', 'type' => 'bool', 'list' => true],
                    'for_article_type' => ['label' => 'For article types', 'type' => 'bool'],
                ],
            ],
        ];
    }

    /** Registre complet, normalisé, limité aux tables existantes. */
    public static function registry() {
        global $wpdb;
        $tables = (array) apply_filters('ispag_reference_tables', self::core_tables());
        $out = [];
        foreach ($tables as $key => $t) {
            $full = $wpdb->prefix . $t['table'];
            if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $full)) !== $full) {
                continue;
            }
            $t['key']  = $key;
            $t['full'] = $full;
            $t['pk']   = $t['pk'] ?? 'Id';
            $out[$key] = $t;
        }
        return $out;
    }

    // ------------------------------------------------------------------ Menu et assets

    public static function menu() {
        if (!class_exists('ISPAG_Settings')) return;
        add_submenu_page(ISPAG_Settings::PAGE, 'Reference tables', 'Reference tables', 'manage_options', self::PAGE, [self::class, 'render']);
    }

    public static function enqueue() {
        if (!isset($_GET['page']) || $_GET['page'] !== self::PAGE) return;
        wp_enqueue_style('wp-color-picker');
        wp_enqueue_script('wp-color-picker');
        wp_enqueue_media();
        $base = dirname(__DIR__);
        wp_enqueue_script('ispag-reference-tables', plugins_url('assets/js/reference-tables.js', __DIR__), ['jquery', 'wp-color-picker'], @filemtime($base . '/assets/js/reference-tables.js') ?: null, true);
        wp_add_inline_style('wp-color-picker', '
            .ispag-ref-swatch{display:inline-block;width:18px;height:18px;border-radius:4px;border:1px solid #c3c4c7;vertical-align:middle}
            .ispag-ref-form{background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:16px 20px;margin:16px 0;max-width:900px}
            .ispag-ref-form .form-table th{width:220px}
            .ispag-ref-key{font-family:monospace}
            .ispag-ref-actions a{margin-right:8px}
            .ispag-ref-off{color:#a7aaad}
        ');
    }

    public static function url($tab = '', array $extra = []) {
        return add_query_arg(array_filter(array_merge(['page' => self::PAGE, 'tab' => $tab], $extra)), admin_url('admin.php'));
    }

    // ------------------------------------------------------------------ Page

    public static function render() {
        if (!current_user_can('manage_options')) wp_die(esc_html__('Access denied'));
        $reg = self::registry();
        echo '<div class="wrap"><h1>Reference tables</h1>';
        if (!$reg) {
            echo '<p>No reference table available.</p></div>';
            return;
        }
        $tab = isset($_GET['tab']) && isset($reg[$_GET['tab']]) ? sanitize_key($_GET['tab']) : (string) array_key_first($reg);
        $t   = $reg[$tab];

        self::notice();

        echo '<nav class="nav-tab-wrapper" style="margin-bottom:12px;">';
        foreach ($reg as $key => $def) {
            printf('<a href="%s" class="nav-tab %s">%s</a>', esc_url(self::url($key)), $key === $tab ? 'nav-tab-active' : '', esc_html($def['title']));
        }
        echo '</nav>';

        if (!empty($t['description'])) {
            echo '<p class="description" style="max-width:900px;">' . esc_html($t['description']) . '</p>';
        }

        $editing = isset($_GET['edit']) ? absint($_GET['edit']) : 0;
        $adding  = !empty($_GET['add']);
        $row     = null;
        if ($editing) {
            global $wpdb;
            $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM `{$t['full']}` WHERE `{$t['pk']}` = %d", $editing));
            if (!$row) { $editing = 0; }
        }

        if ($editing || $adding) {
            self::render_form($t, $row);
        } else {
            printf('<p><a class="button button-primary" href="%s">+ Add</a></p>', esc_url(self::url($tab, ['add' => 1])));
        }
        self::render_list($t, $editing);
        echo '</div>';
    }

    private static function notice() {
        $map = [
            'saved'   => ['success', 'Saved.'],
            'created' => ['success', 'Added.'],
            'deleted' => ['success', 'Deleted.'],
        ];
        if (!empty($_GET['msg']) && isset($map[$_GET['msg']])) {
            [$class, $text] = $map[$_GET['msg']];
            echo '<div class="notice notice-' . esc_attr($class) . ' is-dismissible"><p>' . esc_html($text) . '</p></div>';
        }
        if (!empty($_GET['err'])) {
            echo '<div class="notice notice-error"><p>' . esc_html(sanitize_text_field(wp_unslash($_GET['err']))) . '</p></div>';
        }
    }

    // ------------------------------------------------------------------ Liste

    private static function render_list(array $t, $editing) {
        global $wpdb;
        $order = !empty($t['order']) && isset($t['columns'][$t['order']]) ? "`{$t['order']}` ASC, " : '';
        $rows  = (array) $wpdb->get_results("SELECT * FROM `{$t['full']}` ORDER BY {$order}`{$t['pk']}` ASC");
        $cols  = array_filter($t['columns'], function ($c) { return !empty($c['list']); });
        if (!$cols) $cols = array_slice($t['columns'], 0, 4, true);

        echo '<table class="widefat striped" style="max-width:1100px;margin-top:8px;"><thead><tr><th style="width:50px;">ID</th>';
        foreach ($cols as $c) echo '<th>' . esc_html($c['label']) . '</th>';
        echo '<th style="width:140px;">Actions</th></tr></thead><tbody>';
        if (!$rows) echo '<tr><td colspan="' . (count($cols) + 2) . '">Nothing yet.</td></tr>';

        foreach ($rows as $r) {
            $id = (int) $r->{$t['pk']};
            echo '<tr' . ($id === $editing ? ' style="background:#fff8e5;"' : '') . '><td>' . $id . '</td>';
            foreach ($cols as $name => $c) echo '<td>' . self::cell($c, $r->$name ?? '', $r) . '</td>';
            $del = wp_nonce_url(admin_url('admin-post.php?action=ispag_ref_delete&tab=' . rawurlencode($t['key']) . '&id=' . $id), self::NONCE);
            echo '<td class="ispag-ref-actions"><a href="' . esc_url(self::url($t['key'], ['edit' => $id])) . '">Edit</a>'
               . '<a href="' . esc_url($del) . '" style="color:#b32d2e;" onclick="return confirm(\'Delete this entry?\');">Delete</a></td></tr>';
        }
        echo '</tbody></table>';
    }

    private static function cell(array $c, $value, $row = null) {
        switch ($c['type']) {
            case 'bool':
                return $value ? '✅' : '<span class="ispag-ref-off">—</span>';
            case 'color':
                return preg_match('/^#[0-9a-fA-F]{3,8}$/', (string) $value)
                    ? '<span class="ispag-ref-swatch" style="background:' . esc_attr($value) . ';"></span> <code>' . esc_html($value) . '</code>'
                    : ($value !== '' ? esc_html($value) : '<span class="ispag-ref-off">—</span>');
            case 'supplier':
                $name = (int) $value > 0 ? (self::suppliers()[(int) $value] ?? '#' . (int) $value) : '';
                return $name !== '' ? esc_html($name) : '<span class="ispag-ref-off">—</span>';
            case 'key':
                return '<code class="ispag-ref-key">' . esc_html($value) . '</code>';
            case 'media':
                $u = $value ? wp_get_attachment_image_url((int) $value, 'thumbnail') : '';
                if (!$u && !empty($c['fallback']) && is_callable($c['fallback']) && $row) $u = call_user_func($c['fallback'], $row);
                return $u ? '<img src="' . esc_url($u) . '" alt="" style="height:28px;width:auto;">' : '<span class="ispag-ref-off">—</span>';
            default:
                $s = (string) $value;
                return $s === '' ? '<span class="ispag-ref-off">—</span>' : esc_html(mb_strlen($s) > 90 ? mb_substr($s, 0, 90) . '…' : $s);
        }
    }

    // ------------------------------------------------------------------ Formulaire

    private static function render_form(array $t, $row) {
        $id = $row ? (int) $row->{$t['pk']} : 0;
        echo '<div class="ispag-ref-form"><h2 style="margin-top:0;">' . ($id ? 'Edit #' . $id : 'Add') . '</h2>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field(self::NONCE);
        echo '<input type="hidden" name="action" value="ispag_ref_save"><input type="hidden" name="tab" value="' . esc_attr($t['key']) . '"><input type="hidden" name="id" value="' . $id . '">';
        echo '<table class="form-table" role="presentation">';

        foreach ($t['columns'] as $name => $c) {
            $value = $row ? ($row->$name ?? '') : ($c['default'] ?? '');
            $field = 'f[' . $name . ']';
            $fid   = 'ispag-ref-' . $name;
            echo '<tr><th scope="row"><label for="' . esc_attr($fid) . '">' . esc_html($c['label']) . (!empty($c['required']) ? ' *' : '') . '</label></th><td>';

            switch ($c['type']) {
                case 'textarea':
                    printf('<textarea id="%s" name="%s" rows="3" class="large-text">%s</textarea>', esc_attr($fid), esc_attr($field), esc_textarea($value));
                    break;
                case 'bool':
                    printf('<label><input type="checkbox" id="%s" name="%s" value="1" %s> Yes</label>', esc_attr($fid), esc_attr($field), checked((int) $value, 1, false));
                    break;
                case 'int':
                    printf('<input type="number" step="1" id="%s" name="%s" value="%s" class="small-text">', esc_attr($fid), esc_attr($field), esc_attr($value));
                    break;
                case 'decimal':
                    printf('<input type="number" step="0.01" id="%s" name="%s" value="%s" class="small-text">', esc_attr($fid), esc_attr($field), esc_attr($value));
                    break;
                case 'color':
                    $hex = preg_match('/^#[0-9a-fA-F]{3,8}$/', (string) $value) || $value === '';
                    printf('<input type="text" id="%s" name="%s" value="%s" %s class="%s" data-default-color="#cccccc">', esc_attr($fid), esc_attr($field), esc_attr($value), '', $hex ? 'ispag-ref-color' : 'regular-text');
                    break;
                case 'css':
                    $list = 'ispag-ref-list-' . $name;
                    printf('<input type="text" id="%s" name="%s" value="%s" class="regular-text" list="%s">', esc_attr($fid), esc_attr($field), esc_attr($value), esc_attr($list));
                    echo '<datalist id="' . esc_attr($list) . '">';
                    foreach ((array) ($c['options'] ?? []) as $o) echo '<option value="' . esc_attr($o) . '">';
                    echo '</datalist>';
                    break;
                case 'select':
                    echo '<select id="' . esc_attr($fid) . '" name="' . esc_attr($field) . '">';
                    foreach ((array) $c['options'] as $ov => $ol) {
                        $ov = is_int($ov) ? $ol : $ov;
                        printf('<option value="%s" %s>%s</option>', esc_attr($ov), selected((string) $value, (string) $ov, false), esc_html($ol));
                    }
                    echo '</select>';
                    break;
                case 'supplier':
                    echo '<select id="' . esc_attr($fid) . '" name="' . esc_attr($field) . '"><option value="0">— None —</option>';
                    foreach (self::suppliers() as $sid => $sname) {
                        printf('<option value="%d" %s>%s</option>', $sid, selected((int) $value, $sid, false), esc_html($sname));
                    }
                    echo '</select>';
                    break;
                case 'media':
                    $u = $value ? wp_get_attachment_image_url((int) $value, 'thumbnail') : '';
                    if (!$u && !empty($c['fallback']) && is_callable($c['fallback']) && $row) $u = call_user_func($c['fallback'], $row);
                    printf('<span class="ispag-ref-media"><input type="hidden" id="%s" name="%s" value="%d"><span class="ispag-ref-media-preview">%s</span> <button type="button" class="button ispag-ref-pick">Choose</button> <button type="button" class="button-link ispag-ref-clear">Remove</button></span>',
                        esc_attr($fid), esc_attr($field), (int) $value, $u ? '<img src="' . esc_url($u) . '" alt="" style="height:40px;width:auto;">' : '');
                    break;
                case 'datetime':
                    echo '<code>' . esc_html($value ?: '—') . '</code>';
                    break;
                case 'key':
                    printf('<input type="text" id="%s" name="%s" value="%s" class="regular-text ispag-ref-key" pattern="[A-Za-z0-9_\-]+" required>', esc_attr($fid), esc_attr($field), esc_attr($value));
                    break;
                default:
                    printf('<input type="text" id="%s" name="%s" value="%s" class="regular-text" %s>', esc_attr($fid), esc_attr($field), esc_attr($value), !empty($c['required']) ? 'required' : '');
            }
            if (!empty($c['help'])) echo '<p class="description">' . esc_html($c['help']) . '</p>';
            echo '</td></tr>';
        }
        echo '</table>';
        submit_button($id ? 'Save changes' : 'Add', 'primary', 'submit', false);
        echo ' <a class="button" href="' . esc_url(self::url($t['key'])) . '">Cancel</a></form></div>';
    }

    // ------------------------------------------------------------------ Enregistrement

    private static function get_table_from_request() {
        $reg = self::registry();
        $tab = isset($_REQUEST['tab']) ? sanitize_key($_REQUEST['tab']) : '';
        return isset($reg[$tab]) ? $reg[$tab] : null;
    }

    private static function back($tab, array $args) {
        wp_safe_redirect(self::url($tab, $args));
        exit;
    }

    /** Valeur nettoyée + format wpdb pour une colonne ; null si la colonne n'est pas modifiable. */
    private static function clean(array $c, $raw) {
        switch ($c['type']) {
            case 'datetime': return null;
            case 'bool':     return [!empty($raw) ? 1 : 0, '%d'];
            case 'int':
            case 'supplier':
            case 'media':    return [(int) $raw, '%d'];
            case 'decimal':  return [(float) str_replace(',', '.', (string) $raw), '%f'];
            case 'textarea': return [sanitize_textarea_field(wp_unslash((string) $raw)), '%s'];
            case 'key':      return [preg_replace('/[^A-Za-z0-9_\-]/', '', wp_unslash((string) $raw)), '%s'];
            case 'select':
                $opts = array_map('strval', array_map(function ($k, $v) { return is_int($k) ? $v : $k; }, array_keys((array) $c['options']), (array) $c['options']));
                $v = (string) wp_unslash((string) $raw);
                return [in_array($v, $opts, true) ? $v : (string) reset($opts), '%s'];
            case 'color':
            case 'css':
                $v = trim(sanitize_text_field(wp_unslash((string) $raw)));
                return [mb_substr($v, 0, 50), '%s'];
            default:         return [sanitize_text_field(wp_unslash((string) $raw)), '%s'];
        }
    }

    public static function handle_save() {
        if (!current_user_can('manage_options')) wp_die(esc_html__('Access denied'));
        check_admin_referer(self::NONCE);
        $t = self::get_table_from_request();
        if (!$t) wp_die('Unknown table');
        global $wpdb;

        $id   = isset($_POST['id']) ? absint($_POST['id']) : 0;
        $in   = isset($_POST['f']) && is_array($_POST['f']) ? $_POST['f'] : [];
        $data = [];
        $fmt  = [];
        foreach ($t['columns'] as $name => $c) {
            $res = self::clean($c, $in[$name] ?? '');
            if ($res === null) continue;
            if (!empty($c['required']) && ($res[0] === '' || $res[0] === null)) {
                self::back($t['key'], [$id ? 'edit' : 'add' => $id ?: 1, 'err' => rawurlencode($c['label'] . ' is required.')]);
            }
            if (!empty($c['unique']) && $res[0] !== '') {
                $dup = $wpdb->get_var($wpdb->prepare("SELECT `{$t['pk']}` FROM `{$t['full']}` WHERE `{$name}` = %s AND `{$t['pk']}` <> %d LIMIT 1", $res[0], $id));
                if ($dup) {
                    self::back($t['key'], [$id ? 'edit' : 'add' => $id ?: 1, 'err' => rawurlencode($c['label'] . ' "' . $res[0] . '" already exists.')]);
                }
            }
            $data[$name] = $res[0];
            $fmt[]       = $res[1];
        }

        if ($id) {
            $ok = $wpdb->update($t['full'], $data, [$t['pk'] => $id], $fmt, ['%d']);
            $msg = 'saved';
        } else {
            $ok = $wpdb->insert($t['full'], $data, $fmt);
            $msg = 'created';
        }
        if ($ok === false) {
            self::back($t['key'], [$id ? 'edit' : 'add' => $id ?: 1, 'err' => rawurlencode('Database error: ' . $wpdb->last_error)]);
        }
        if (!empty($t['on_change']) && is_callable($t['on_change'])) call_user_func($t['on_change']);
        self::back($t['key'], ['msg' => $msg]);
    }

    /** Nombre d'utilisations d'une ligne (0 si rien n'est déclaré). */
    private static function usage_count(array $t, $row) {
        global $wpdb;
        $n = 0;
        foreach ((array) ($t['usage'] ?? []) as $u) {
            $value = $u['value'] === 'pk' ? $row->{$t['pk']} : ($row->{$u['value']} ?? null);
            if ($value === null || $value === '') continue;
            if (!empty($u['usermeta'])) {
                $n += (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value = %s", $u['usermeta'], $value));
                continue;
            }
            $full = $wpdb->prefix . $u['table'];
            if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $full)) !== $full) continue;
            $n += (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `{$full}` WHERE `{$u['column']}` = %s", $value));
        }
        return $n;
    }

    public static function handle_delete() {
        if (!current_user_can('manage_options')) wp_die(esc_html__('Access denied'));
        check_admin_referer(self::NONCE);
        $t = self::get_table_from_request();
        if (!$t) wp_die('Unknown table');
        global $wpdb;
        $id  = isset($_GET['id']) ? absint($_GET['id']) : 0;
        $row = $id ? $wpdb->get_row($wpdb->prepare("SELECT * FROM `{$t['full']}` WHERE `{$t['pk']}` = %d", $id)) : null;
        if (!$row) self::back($t['key'], ['err' => rawurlencode('Entry not found.')]);

        $used = self::usage_count($t, $row);
        if ($used > 0) {
            self::back($t['key'], ['err' => rawurlencode(sprintf('This entry is still used %d time(s): it cannot be deleted.', $used))]);
        }
        $wpdb->delete($t['full'], [$t['pk'] => $id], ['%d']);
        if (!empty($t['on_change']) && is_callable($t['on_change'])) call_user_func($t['on_change']);
        self::back($t['key'], ['msg' => 'deleted']);
    }
}
