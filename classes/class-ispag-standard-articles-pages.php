<?php
defined('ABSPATH') || exit;

/**
 * Pages de gestion des articles standard :
 *  - [ispag_standard_articles] : liste filtrable (type, recherche, fournisseur, articles sans prix d'achat) ;
 *  - [ispag_standard_article]  : fiche d'un article (adresse /article-standard/<id>/) avec deux onglets :
 *        Ventes  (titre, référence, description, prix de vente historisé…) et
 *        Achats  (un fournisseur par ligne, prix d'achat historisé) ;
 *  - [ispag_articles_table]    : ancien shortcode, conservé et redirigé vers la liste.
 *
 * Droits :
 *  - voir la liste et les fiches : manage_order ou edit_supplier_order ;
 *  - modifier la partie Ventes : manage_order ;
 *  - voir et modifier la partie Achats (prix d'achat, fournisseurs) : edit_supplier_order.
 */
class ISPAG_Standard_Articles_Pages {

    const NONCE = 'ispag_std_articles';

    public static function init() {
        ISPAG_Standard_Article_Service::ensure_active_column();
        add_shortcode('ispag_standard_articles', [self::class, 'shortcode_list']);
        add_shortcode('ispag_standard_article', [self::class, 'shortcode_sheet']);
        add_shortcode('ispag_articles_table', [self::class, 'shortcode_list']); // ancien nom

        // init() est appelé pendant l'action init : on enregistre la règle tout de suite dans ce cas
        doing_action('init') ? self::add_rewrite_rules() : add_action('init', [self::class, 'add_rewrite_rules']);
        add_filter('query_vars', function ($vars) { $vars[] = 'std_article_id'; return $vars; });

        foreach ([
            'create'               => 'ajax_create',
            'save_sales_field'     => 'ajax_save_sales_field',
            'save_sales_price'     => 'ajax_save_sales_price',
            'delete'               => 'ajax_delete',
            'add_purchase'         => 'ajax_add_purchase',
            'save_purchase_field'  => 'ajax_save_purchase_field',
            'save_purchase_price'  => 'ajax_save_purchase_price',
            'delete_purchase'      => 'ajax_delete_purchase',
            'history'              => 'ajax_history',
            'add_documents'        => 'ajax_add_documents',
            'remove_document'      => 'ajax_remove_document',
            'export'               => 'ajax_export',
        ] as $action => $method) {
            add_action('wp_ajax_ispag_std_' . $action, [self::class, $method]);
        }
    }

    public static function add_rewrite_rules() {
        add_rewrite_rule('^article-standard/([0-9]+)/?$', 'index.php?pagename=article-standard&std_article_id=$matches[1]', 'top');
    }

    // ------------------------------------------------------------------ Droits

    public static function can_view()          { return current_user_can('view_standard_articles') || current_user_can('edit_standard_articles'); }
    public static function can_edit_sales()    { return current_user_can('edit_standard_articles'); }
    public static function can_purchase()      { return current_user_can('edit_supplier_order'); }

    private static function guard($cap_check) {
        if (!check_ajax_referer(self::NONCE, 'nonce', false)) {
            wp_send_json_error(['message' => __('Security check failed. Please reload the page.', 'creation-reservoir')], 403);
        }
        if (!call_user_func([self::class, $cap_check])) {
            wp_send_json_error(['message' => __('Unauthorized', 'creation-reservoir')], 403);
        }
    }

    // ------------------------------------------------------------------ Assets

    private static function enqueue() {
        $base = dirname(__DIR__);
        wp_enqueue_style('dashicons');
        wp_enqueue_style('ispag-standard-articles', plugins_url('assets/css/standard-articles.css', __DIR__), [], @filemtime($base . '/assets/css/standard-articles.css') ?: null);
        wp_enqueue_script('ispag-standard-articles', plugins_url('assets/js/standard-articles.js', __DIR__), ['jquery'], @filemtime($base . '/assets/js/standard-articles.js') ?: null, true);
        // Sélecteur de la médiathèque (demande le droit d'ajouter des fichiers)
        $can_media = current_user_can('upload_files') && (self::can_edit_sales());
        if ($can_media) {
            wp_enqueue_media();
        }
        wp_localize_script('ispag-standard-articles', 'ispagStd', [
            'can_media' => $can_media,
            'media_image_title' => __('Choose the article image', 'creation-reservoir'),
            'media_docs_title'  => __('Choose documents', 'creation-reservoir'),
            'media_select'      => __('Select', 'creation-reservoir'),
            'confirm_remove_document' => __('Remove this document from the article? The file stays in the media library.', 'creation-reservoir'),
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce(self::NONCE),
            'saved'    => __('Saved', 'creation-reservoir'),
            'error'    => __('An error occurred. Please try again.', 'creation-reservoir'),
            'confirm_delete_article'  => __('Delete this article? This cannot be undone.', 'creation-reservoir'),
            'confirm_delete_purchase' => __('Remove this supplier from the article?', 'creation-reservoir'),
        ]);
    }

    // ------------------------------------------------------------------ Shortcodes

    public static function shortcode_list() {
        if (!is_user_logged_in() || !self::can_view()) {
            return '<p class="ispag-notice">' . esc_html__('You do not have permission to view this page.', 'creation-reservoir') . '</p>';
        }
        self::enqueue();

        $type     = isset($_GET['type']) ? absint($_GET['type']) : 0;
        $search   = isset($_GET['q']) ? sanitize_text_field(wp_unslash($_GET['q'])) : '';
        $supplier = isset($_GET['supplier']) ? absint($_GET['supplier']) : 0;
        $no_purch = !empty($_GET['no_purchase']);
        $outdated = self::can_purchase() && !empty($_GET['outdated']);
        $page     = isset($_GET['pg']) ? max(1, absint($_GET['pg'])) : 1;
        $status   = isset($_GET['status']) && in_array($_GET['status'], ['active', 'inactive'], true) ? $_GET['status'] : '';

        $result    = ISPAG_Standard_Article_Service::search(compact('type', 'search', 'supplier', 'page', 'outdated', 'status') + ['no_purchase' => $no_purch]);
        $types     = ISPAG_Standard_Article_Service::types();
        $type_name = ISPAG_Standard_Article_Service::type_names();
        $suppliers = self::can_purchase() ? ISPAG_Standard_Article_Service::suppliers() : [];
        $can_edit  = self::can_edit_sales();
        $can_purch = self::can_purchase();
        $currency  = get_option('wpcb_currency', '€');
        $filters   = compact('type', 'search', 'supplier', 'no_purch', 'outdated', 'status');
        $outdated_months = ISPAG_Standard_Article_Service::outdated_months();
        $export_url = add_query_arg(array_filter([
            'action' => 'ispag_std_export', 'nonce' => wp_create_nonce(self::NONCE),
            'type' => $type, 'q' => $search, 'supplier' => $supplier, 'no_purchase' => $no_purch ? 1 : 0, 'outdated' => $outdated ? 1 : 0,
        ]), admin_url('admin-ajax.php'));

        ob_start();
        include __DIR__ . '/templates/standard-articles-list.php';
        return ob_get_clean();
    }

    public static function shortcode_sheet() {
        if (!is_user_logged_in() || !self::can_view()) {
            return '<p class="ispag-notice">' . esc_html__('You do not have permission to view this page.', 'creation-reservoir') . '</p>';
        }
        $id = absint(get_query_var('std_article_id')) ?: (isset($_GET['article']) ? absint($_GET['article']) : 0);
        $article = $id ? ISPAG_Standard_Article_Service::get($id) : null;
        if (!$article) {
            return '<p class="ispag-notice">' . esc_html__('Article not found', 'creation-reservoir') . '</p>';
        }
        self::enqueue();

        $types       = ISPAG_Standard_Article_Service::types();
        $type_name   = ISPAG_Standard_Article_Service::type_names();
        $can_edit    = self::can_edit_sales();
        $can_purch   = self::can_purchase();
        $currency    = get_option('wpcb_currency', '€');
        $sales_price = ISPAG_Standard_Article_Service::current_sales_price($id);
        $usage       = ISPAG_Standard_Article_Service::usage_count($id);
        $purchases   = $can_purch ? ISPAG_Standard_Article_Service::purchases($id) : [];
        $suppliers   = $can_purch ? ISPAG_Standard_Article_Service::suppliers() : [];
        $list_url    = self::list_url();
        $documents   = ISPAG_Standard_Article_Service::documents($id);
        $outdated_months = ISPAG_Standard_Article_Service::outdated_months();
        $nb_outdated = count(array_filter($purchases, function ($p) { return $p->is_outdated; }));

        ob_start();
        include __DIR__ . '/templates/standard-article-sheet.php';
        return ob_get_clean();
    }

    /** Adresse de la liste (page « articles-standard » si elle existe, sinon la page courante). */
    public static function list_url() {
        $page = get_page_by_path('articles-standard');
        return $page ? get_permalink($page) : home_url('/articles-standard/');
    }

    // ------------------------------------------------------------------ Aides d'affichage

    public static function money($value) {
        return number_format((float) $value, 2, '.', "'");
    }

    /** Image de l'article (vignette) ou icône neutre. */
    public static function thumb($image_id, $size = 'thumbnail') {
        $url = $image_id ? wp_get_attachment_image_url((int) $image_id, $size) : '';
        if ($url) {
            return '<img src="' . esc_url($url) . '" alt="" class="ispag-std-thumb" onerror="this.outerHTML=\'&lt;span class=&quot;dashicons dashicons-format-image ispag-std-thumb-empty&quot;&gt;&lt;/span&gt;\'">';
        }
        return '<span class="dashicons dashicons-format-image ispag-std-thumb-empty"></span>';
    }

    // ------------------------------------------------------------------ AJAX : ventes

    public static function ajax_create() {
        self::guard('can_edit_sales');
        $title = sanitize_text_field(wp_unslash($_POST['title'] ?? ''));
        $type  = absint($_POST['type'] ?? 0);
        if ($title === '' || !$type) {
            wp_send_json_error(['message' => __('Invalid data', 'creation-reservoir')]);
        }
        $id = ISPAG_Standard_Article_Service::create($title, $type);
        if (!$id) {
            wp_send_json_error(['message' => __('Failed to create article', 'creation-reservoir')]);
        }
        wp_send_json_success(['url' => ISPAG_Standard_Article_Service::article_url($id)]);
    }

    public static function ajax_save_sales_field() {
        self::guard('can_edit_sales');
        $id    = absint($_POST['id'] ?? 0);
        $column = sanitize_text_field(wp_unslash($_POST['field'] ?? ''));
        if (!$id || !array_key_exists($column, ISPAG_Standard_Article_Service::sales_fields()) || !ISPAG_Standard_Article_Service::get($id)) {
            wp_send_json_error(['message' => __('Invalid data', 'creation-reservoir')]);
        }
        $value = ISPAG_Standard_Article_Service::update_sales_field($id, $column, $_POST['value'] ?? '');
        if ($value === null) {
            wp_send_json_error(['message' => __('Failed to update article', 'creation-reservoir')]);
        }
        wp_send_json_success(['value' => $value]);
    }

    private static function clean_date($raw) {
        $d = sanitize_text_field(wp_unslash($raw));
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) ? $d : date('Y-m-d');
    }

    public static function ajax_save_sales_price() {
        self::guard('can_edit_sales');
        $id    = absint($_POST['id'] ?? 0);
        $price = (float) str_replace(',', '.', (string) ($_POST['price'] ?? ''));
        if (!$id || !ISPAG_Standard_Article_Service::get($id) || $price < 0) {
            wp_send_json_error(['message' => __('Invalid data', 'creation-reservoir')]);
        }
        $ok = ISPAG_Standard_Article_Service::apply_sales_price($id, $price, self::clean_date($_POST['valid_from'] ?? ''), sanitize_text_field(wp_unslash($_POST['note'] ?? '')));
        $ok ? wp_send_json_success() : wp_send_json_error(['message' => __('Failed to update article', 'creation-reservoir')]);
    }

    public static function ajax_delete() {
        self::guard('can_edit_sales');
        $id = absint($_POST['id'] ?? 0);
        if (!$id || ISPAG_Standard_Article_Service::usage_count($id) > 0) {
            wp_send_json_error(['message' => __('This article is used in projects and cannot be deleted.', 'creation-reservoir')]);
        }
        ISPAG_Standard_Article_Service::delete($id)
            ? wp_send_json_success(['url' => self::list_url()])
            : wp_send_json_error(['message' => __('Failed to delete article', 'creation-reservoir')]);
    }

    // ------------------------------------------------------------------ AJAX : achats

    public static function ajax_add_purchase() {
        self::guard('can_purchase');
        $article_id  = absint($_POST['article_id'] ?? 0);
        $supplier_id = absint($_POST['supplier_id'] ?? 0);
        if (!$article_id || !$supplier_id || !ISPAG_Standard_Article_Service::get($article_id)) {
            wp_send_json_error(['message' => __('Invalid data', 'creation-reservoir')]);
        }
        $id = ISPAG_Standard_Article_Service::add_purchase(
            $article_id,
            $supplier_id,
            (float) str_replace(',', '.', (string) ($_POST['price'] ?? 0)),
            (float) str_replace(',', '.', (string) ($_POST['discount'] ?? 0)),
            sanitize_text_field(wp_unslash($_POST['currency'] ?? 'CHF')) ?: 'CHF',
            sanitize_text_field(wp_unslash($_POST['reference'] ?? '')),
            sanitize_textarea_field(wp_unslash($_POST['description'] ?? '')),
            absint($_POST['delivery_days'] ?? 0)
        );
        $id ? wp_send_json_success() : wp_send_json_error(['message' => __('This supplier is already linked to this article.', 'creation-reservoir')]);
    }

    public static function ajax_save_purchase_field() {
        self::guard('can_purchase');
        $id    = absint($_POST['id'] ?? 0);
        $field = sanitize_text_field(wp_unslash($_POST['field'] ?? ''));
        if (!$id || !ISPAG_Standard_Article_Service::get_purchase($id)) {
            wp_send_json_error(['message' => __('Invalid data', 'creation-reservoir')]);
        }
        $value = ISPAG_Standard_Article_Service::update_purchase_field($id, $field, $_POST['value'] ?? '');
        $value === null ? wp_send_json_error(['message' => __('Failed to update article', 'creation-reservoir')]) : wp_send_json_success(['value' => $value]);
    }

    public static function ajax_save_purchase_price() {
        self::guard('can_purchase');
        $id    = absint($_POST['id'] ?? 0);
        $price = (float) str_replace(',', '.', (string) ($_POST['price'] ?? ''));
        $disc  = (float) str_replace(',', '.', (string) ($_POST['discount'] ?? 0));
        if (!$id || !ISPAG_Standard_Article_Service::get_purchase($id) || $price < 0 || $disc < 0 || $disc > 100) {
            wp_send_json_error(['message' => __('Invalid data', 'creation-reservoir')]);
        }
        $ok = ISPAG_Standard_Article_Service::apply_purchase_price(
            $id, $price, $disc,
            sanitize_text_field(wp_unslash($_POST['currency'] ?? 'CHF')) ?: 'CHF',
            self::clean_date($_POST['valid_from'] ?? ''),
            sanitize_text_field(wp_unslash($_POST['note'] ?? ''))
        );
        $ok ? wp_send_json_success() : wp_send_json_error(['message' => __('Failed to update article', 'creation-reservoir')]);
    }

    public static function ajax_delete_purchase() {
        self::guard('can_purchase');
        $id = absint($_POST['id'] ?? 0);
        if (!$id || !ISPAG_Standard_Article_Service::get_purchase($id)) {
            wp_send_json_error(['message' => __('Invalid data', 'creation-reservoir')]);
        }
        ISPAG_Standard_Article_Service::delete_purchase($id) ? wp_send_json_success() : wp_send_json_error(['message' => __('Failed to delete article', 'creation-reservoir')]);
    }

    // ------------------------------------------------------------------ AJAX : historique des prix

    public static function ajax_history() {
        $kind = sanitize_key($_POST['kind'] ?? '');
        self::guard($kind === 'purchase' ? 'can_purchase' : 'can_view');
        $id = absint($_POST['id'] ?? 0);
        if (!$id) {
            wp_send_json_error(['message' => __('Invalid data', 'creation-reservoir')]);
        }
        $rows = $kind === 'purchase' ? ISPAG_Standard_Article_Service::purchase_history($id) : ISPAG_Standard_Article_Service::sales_history($id);

        ob_start();
        echo '<table class="ispag-project-table ispag-std-history"><thead><tr>';
        $heads = [__('From', 'creation-reservoir'), __('To', 'creation-reservoir'), __('Price', 'creation-reservoir')];
        if ($kind === 'purchase') {
            $heads[] = __('Discount', 'creation-reservoir') . ' %';
            $heads[] = __('Currency', 'creation-reservoir');
        }
        $heads[] = __('By', 'creation-reservoir');
        $heads[] = __('Note', 'creation-reservoir');
        foreach ($heads as $h) {
            echo '<th>' . esc_html($h) . '</th>';
        }
        echo '</tr></thead><tbody>';
        if (!$rows) {
            echo '<tr><td colspan="' . count($heads) . '">' . esc_html__('No history', 'creation-reservoir') . '</td></tr>';
        }
        foreach ($rows as $r) {
            $price = $kind === 'purchase' ? $r->purchase_price : $r->sales_price;
            echo '<tr' . ($r->valid_to === null ? ' class="is-current"' : '') . '>';
            echo '<td>' . esc_html($r->valid_from) . '</td><td>' . ($r->valid_to === null ? '<em>' . esc_html__('current', 'creation-reservoir') . '</em>' : esc_html($r->valid_to)) . '</td>';
            echo '<td>' . esc_html(self::money($price)) . '</td>';
            if ($kind === 'purchase') {
                echo '<td>' . esc_html(rtrim(rtrim(number_format((float) $r->discount, 2, '.', ''), '0'), '.')) . '</td><td>' . esc_html($r->currency) . '</td>';
            }
            echo '<td>' . esc_html($r->display_name ?: '—') . '</td><td>' . esc_html($r->note) . '</td></tr>';
        }
        echo '</tbody></table>';
        wp_send_json_success(['html' => ob_get_clean()]);
    }

    // ------------------------------------------------------------------ AJAX : documents

    public static function ajax_add_documents() {
        self::guard('can_edit_sales');
        if (!current_user_can('upload_files')) {
            wp_send_json_error(['message' => __('Unauthorized', 'creation-reservoir')], 403);
        }
        $id  = absint($_POST['id'] ?? 0);
        $ids = isset($_POST['attachment_ids']) ? array_map('absint', (array) $_POST['attachment_ids']) : [];
        if (!$id || !$ids || !ISPAG_Standard_Article_Service::get($id)) {
            wp_send_json_error(['message' => __('Invalid data', 'creation-reservoir')]);
        }
        wp_send_json_success(['added' => ISPAG_Standard_Article_Service::attach_documents($id, $ids)]);
    }

    public static function ajax_remove_document() {
        self::guard('can_edit_sales');
        $id  = absint($_POST['id'] ?? 0);
        $aid = absint($_POST['attachment_id'] ?? 0);
        ISPAG_Standard_Article_Service::detach_document($id, $aid)
            ? wp_send_json_success()
            : wp_send_json_error(['message' => __('Invalid data', 'creation-reservoir')]);
    }

    // ------------------------------------------------------------------ Export CSV

    /** Télécharge la liste (mêmes filtres que l'écran) : séparateur « ; », UTF-8 avec BOM pour Excel. */
    public static function ajax_export() {
        if (!isset($_GET['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['nonce'])), self::NONCE)) {
            wp_die(esc_html__('Security check failed. Please reload the page.', 'creation-reservoir'), '', ['response' => 403]);
        }
        if (!self::can_view()) {
            wp_die(esc_html__('Unauthorized', 'creation-reservoir'), '', ['response' => 403]);
        }
        $with_purchase = self::can_purchase();
        $rows = ISPAG_Standard_Article_Service::export_rows([
            'type'        => absint($_GET['type'] ?? 0),
            'search'      => sanitize_text_field(wp_unslash($_GET['q'] ?? '')),
            'supplier'    => $with_purchase ? absint($_GET['supplier'] ?? 0) : 0,
            'no_purchase' => $with_purchase && !empty($_GET['no_purchase']),
            'outdated'    => $with_purchase && !empty($_GET['outdated']),
        ], $with_purchase);

        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="standard-articles-' . date('Y-m-d') . '.csv"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        foreach ($rows as $row) {
            // Neutralise l'injection de formules dans Excel (=, +, -, @ en début de cellule texte)
            $row = array_map(function ($v) {
                return is_string($v) && $v !== '' && strpbrk($v[0], "=+-@\t\r") !== false ? "'" . $v : $v;
            }, $row);
            fputcsv($out, $row, ';', '"', '\\');
        }
        fclose($out);
        exit;
    }
}
