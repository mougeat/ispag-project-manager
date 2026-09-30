<?php
defined('ABSPATH') or die();

/**
 * Class ISPAG_Document_Manager
 * Gère les documents associés aux projets et achats ISPAG.
 * Logging : Toutes les actions sont loguées dans ispag_document_manager.log.
 */
class ISPAG_Document_Manager
{
    private $wpdb;
    private $table_historique;
    private $table_media;
    private $table_projet_articles;
    private $table_achat_articles;
    private $table_prestations;
    private $project_array_document_type;
    private $purchase_array_document_type;
    private $table_doc_type;
    private const LOG_NAME = 'document_manager';

    /** @var ISPAG_Logger Instance du logger. */
    private $logger;

    public function __construct()
    {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->logger = ISPAG_Logger::get_instance();
        $user_id = get_current_user_id();

        $this->table_historique = $wpdb->prefix . 'achats_historique';
        $this->table_media = $wpdb->prefix . 'posts';
        $this->table_projet_articles = $wpdb->prefix . 'achats_details_commande';
        $this->table_achat_articles = $wpdb->prefix . 'achats_articles_cmd_fournisseurs';
        $this->table_prestations = $wpdb->prefix . 'achats_type_prestations';
        $this->table_doc_type = $wpdb->prefix . 'achats_doc_types';

        // $this->logger->log_user_action(self::LOG_NAME, 'class_constructed', [], $user_id);

        // Actions AJAX existantes
        add_action('wp_ajax_ispag_upload_document', [$this, 'upload_document']);
        add_action('wp_ajax_ispag_delete_document', [$this, 'delete_document']);
        add_action('ispag_delete_document_whith_deal_id', [$this, 'delete_document_whith_deal_id'], 10, 2);
        add_action('wp_ajax_ispag_get_documents_list', [$this, 'ajax_get_documents_list']);
        add_action('wp_ajax_ispag_save_historique_views', [$this, 'save_historique_views']);
        // $this->logger->log_user_action(self::LOG_NAME, 'ajax_hooks_registered', [], $user_id);

        // Nouveaux hooks pour l'upload asynchrone
        add_action('wp_ajax_ispag_start_async_upload', [$this, 'start_async_upload']);
        add_action('wp_ajax_ispag_check_upload_status', [$this, 'check_upload_status']);
        add_action('ispag_process_async_upload', [$this, 'process_async_upload'], 10, 1);
        // $this->logger->log_user_action(self::LOG_NAME, 'async_upload_hooks_registered', [], $user_id);

        // Enqueue scripts
        add_action('wp_enqueue_scripts', [$this, 'enqueue_scripts']);
        add_filter('ispag_display_doc_manager', [self::class, 'display_ispag_doc_manger'], 10, 2);
        // $this->logger->log_user_action(self::LOG_NAME, 'filters_registered', [], $user_id);

        $this->enqueue_scripts();
    }

    public static function display_ispag_doc_manger($deal_id, $is_purchase = false)
    {
        $user_id = get_current_user_id();
        $source = $is_purchase ? 'purchase' : 'project';
        
        global $wpdb;
        ob_start();

        if(class_exists('ISPAG_Attachments_Repository') AND class_exists('ISPAG_Attachments_Card_Renderer')){
            $repository = new ISPAG_Attachments_Repository($wpdb);
            $renderer   = new ISPAG_Attachments_Card_Renderer($repository);
            $docTypesRepo   = new ISPAG_Attachments_Doc_Types_Repository($wpdb);
            $modal_renderer = new ISPAG_Attachments_Modal_Renderer($docTypesRepo);
        ?>
            <div class="ispag-detail-container ispag-company-detail">
                <!-- Contenu principal -->
                <div class="ispag-main-content" data-panel="main">
                    <div class="ispag-card ispag-docu-card"
                        data-view="list"
                        data-entity-type="<?php echo esc_attr($source); ?>"
                        data-entity-id="<?php echo esc_attr($deal_id); ?>">
                        
                        <?php
                        if (class_exists('ISPAG_Attachments_Repository') && class_exists('ISPAG_Attachments_Card_Renderer')) {
                            $repository = new ISPAG_Attachments_Repository($wpdb);
                            $renderer   = new ISPAG_Attachments_Card_Renderer($repository);

                            echo $renderer->render_doc_list(esc_attr($source), $deal_id, -1, true);
                        }
                        ?>
                    </div>
                </div>

                <!-- Colonne de droite -->
                <!-- Wrapper qui porte la largeur flex + le bouton -->
                <div class="ispag-right-panel-wrapper" data-panel="right-wrapper">
                    <!-- Bouton collé au bord gauche du wrapper : il suit le panneau -->
                    <button id="toggle-right-panel" class="ispag-panel-toggle-right" type="button"
                            aria-label="<?php esc_attr_e('Display / Mask panel', 'ispag-crm'); ?>"
                            title="<?php esc_attr_e('Mask panel', 'ispag-crm'); ?>">
                        <img
                            src="<?php echo esc_url(get_stylesheet_directory_uri() . '/assets/img/ios-sidebar-hide.png'); ?>"
                            data-icon-hide="<?php echo esc_url(get_stylesheet_directory_uri() . '/assets/img/ios-sidebar-hide.png'); ?>"
                            data-icon-show="<?php echo esc_url(get_stylesheet_directory_uri() . '/assets/img/ios-sidebar-display.png'); ?>"
                            alt=""
                            class="ispag-panel-toggle-icon">
                    </button>
                    <div class="ispag-right-panel" data-panel="right">
                        <div class="ispag-card ispag-company-card">
                            <h5><?php _e('Add attachments', 'ispag-crm'); ?></h5>
                                <?php echo $modal_renderer->render_dropzone($source, $deal_id, 'ispag-upload-modal-dropzone'); ?>
                        </div>
                    </div>
                </div>
            </div>
            <?php
            }
        else {
            echo '<p>Error: The document management classes are not loaded.</p>';
                
        }
        
        return ob_get_clean();

        // $logger = ISPAG_Logger::get_instance();
        // $logger->log_user_action(self::LOG_NAME, 'display_ispag_doc_manager_start', ['deal_id' => $deal_id, 'is_purchase' => $is_purchase], $user_id);

        // echo self::get_confirmation_modal();
        // echo self::get_drawing_analysis_modal();
        // $docManager = new ISPAG_Document_Manager();
        // $docs = $docManager->get_documents_grouped_by_article($deal_id, $is_purchase);
        // $logger->log_db_change(self::LOG_NAME, $docManager->table_historique, 'FETCH_DOCUMENTS', ['deal_id' => $deal_id, 'count' => count($docs)], $user_id);

        // echo $docManager->render_grouped_documents($docs);
        // $logger->log_user_action(self::LOG_NAME, 'display_ispag_doc_manager_complete', [], $user_id);
    }

    public static function get_confirmation_modal()
    {
        $user_id = get_current_user_id();
        $logger = ISPAG_Logger::get_instance();
        $logger->log_user_action(self::LOG_NAME, 'get_confirmation_modal_rendered', [], $user_id);

        return '
        <div id="confirmationModal" class="ispag-product-modal" style="display:none;">
            <div class="ispag-modal-content">
                <span class="ispag-modal-close ispag-btn ispag-btn-red-outlined ispag-close-croix">&times;</span>
                <div id="ispag-confirmation-modal-body">
                </div>
                <div class="ispag-modal-footer">
                    <p><button id="confirmButton" class="ispag-btn">' . __('Confirm', 'creation-reservoir') . '</button></p>
                </div>
            </div>
        </div>';
    }

    public static function get_drawing_analysis_modal()
    {
        $user_id = get_current_user_id();
        $logger = ISPAG_Logger::get_instance();
        $logger->log_user_action(self::LOG_NAME, 'get_drawing_analysis_modal_rendered', [], $user_id);

        return '
        <div id="ispag-drawing-analysis-modal" class="ispag-task-modal-overlay" style="display: none;">
            <div class="ispag-task-modal-content">
                <div class="ispag-modal-header">
                    <h4>' . __('Drawing analysis', 'creation-reservoir') . ' : ' . __('discrepancies detected', 'creation-reservoir') . '</h4>
                    <button class="ispag-close-modal ispag-btn ispag-btn-red-outlined ispag-close-croix">&times;</button>
                </div>
                <div class="ispag-modal-body">
                    <div id="ispag-drawing-analysis-summary" style="margin-bottom: 20px;"></div>
                    <div id="ispag-drawing-analysis-results"></div>
                </div>
                <div class="ispag-modal-footer">
                    <button id="ispag-close-analysis-modal" class="ispag-btn ispag-btn-secondary-outlined">
                        ' . __('Close', 'creation-reservoir') . '
                    </button>
                </div>
            </div>
        </div>';
    }

    public function ajax_get_documents_list()
    {
        global $wpdb;    
        $user_id = get_current_user_id();
        $html = '';
        $this->logger->log_user_action(self::LOG_NAME, 'ajax_get_documents_list_start', [], $user_id);

        $deal_id = intval($_POST['deal_id'] ?? 0);
        $poid = intval($_POST['poid'] ?? 0);

        if (!$deal_id && !$poid)
        {
            $this->logger->log(self::LOG_NAME, 'ERROR: Missing deal_id and poid', $user_id);
            wp_send_json_error("Missing deal_id and poid");
        }

        // $this->logger->log_user_action(self::LOG_NAME, 'documents_list_params_received', ['deal_id' => $deal_id, 'poid' => $poid], $user_id);

        // $docs = $this->get_documents_grouped_by_article($deal_id ? $deal_id : $poid, $poid ? true : false);
        // $this->logger->log_db_change(self::LOG_NAME, $this->table_historique, 'FETCH_DOCUMENTS', ['deal_id' => $deal_id, 'poid' => $poid, 'count' => count($docs)], $user_id);

        // ob_start();
        // echo $this->render_doc_list($docs);
        // $html = ob_get_clean();

        // $this->logger->log_user_action(self::LOG_NAME, 'ajax_get_documents_list_complete', [], $user_id);
        if(class_exists('ISPAG_Attachments_Repository') AND class_exists('ISPAG_Attachments_Card_Renderer')){
            $repository = new ISPAG_Attachments_Repository($wpdb);
            $renderer   = new ISPAG_Attachments_Card_Renderer($repository);

            // --- Sur une fiche Deal / Projet ---

            $html = $renderer->render_doc_list('project', $deal_id, -1, true);
        }
        wp_send_json_success($html);
    }

    public function enqueue_scripts()
    {
        $user_id = get_current_user_id();
        // $this->logger->log_user_action(self::LOG_NAME, 'enqueue_scripts_start', [], $user_id);

        wp_enqueue_script('ispag-upload-document', plugin_dir_url(__FILE__) . '../assets/js/dropzone.js', ['jquery'], false, true);

        $nonce = wp_create_nonce('ispag_ajax_nonce');
        wp_localize_script(
            'ispag-upload-document',
            'ispag_ajax_obj',
            [
                'ajaxurl' => admin_url('admin-ajax.php'),
                'nonce' => $nonce,
                'selected' => __('selected', 'creation-reservoir'),
                'Upload_in_progress' => __('Upload in progress', 'creation-reservoir'),
                'Upload_all_files' => __('Upload all files', 'creation-reservoir'),
                'File_added_successfully' => __('File added successfully', 'creation-reservoir'),
                'really_dele_doc' => __('Are you sure you want to delete this document', 'creation-reservoir'),
                'drag_files_here' => __('Drag one or more files here', 'creation-reservoir'),
                'or' => __('or', 'creation-reservoir'),
                'browse' => __('browse', 'creation-reservoir'),
                'please_select_file' => __('Please choose a file and type', 'creation-reservoir'),
                'drawing_analysis' => [
                    'title' => __('Drawing analysis', 'creation-reservoir'),
                    'discrepancies_detected' => __('Discrepancies detected', 'creation-reservoir'),
                    'tank_id' => __('Tank ID', 'creation-reservoir'),
                    'status' => __('Status', 'creation-reservoir'),
                    'perfect_match' => __('Perfect match', 'creation-reservoir'),
                    'discrepancies' => __('Discrepancies detected', 'creation-reservoir'),
                    'cached_results' => __('Cached results', 'creation-reservoir'),
                    'summary' => __('Summary', 'creation-reservoir'),
                    'no_critical_discrepancies' => __('No critical discrepancies detected.', 'creation-reservoir'),
                    'type' => __('Type', 'creation-reservoir'),
                    'expected' => __('Expected', 'creation-reservoir'),
                    'found' => __('Found', 'creation-reservoir'),
                    'ok' => __('OK', 'creation-reservoir'),
                    'missing' => __('Missing', 'creation-reservoir'),
                    'discrepancy' => __('Discrepancy', 'creation-reservoir'),
                    'unknown' => __('Unknown', 'creation-reservoir'),
                    'not_specified' => __('Not specified', 'creation-reservoir'),
                    'close' => __('Close', 'creation-reservoir'),
                ],
            ]
        );

        // $this->logger->log_user_action(self::LOG_NAME, 'scripts_and_localizations_complete', [], $user_id);
    }

    public function get_documents_grouped_by_article($deal_id, $is_purchase = false)
    {
        $user_id = get_current_user_id();
        $this->logger->log_user_action(self::LOG_NAME, 'get_documents_grouped_by_article_start', ['deal_id' => $deal_id, 'is_purchase' => $is_purchase], $user_id);

        $where = $is_purchase ? 'h.purchase_order = %d' : 'h.hubspot_deal_id = %d';
        $sql = "
            SELECT h.*, p.guid AS file_url, p.post_title, p.post_mime_type, u.display_name, dt.label, dt.ajax_action
            FROM {$this->table_historique} h
            LEFT JOIN {$this->wpdb->users} u ON u.ID = h.IdUser
            LEFT JOIN {$this->table_media} p ON p.ID = h.IdMedia
            LEFT JOIN {$this->table_doc_type} dt ON h.Classcss = dt.slug
            WHERE {$where} AND h.IdMedia > 0
            ORDER BY h.dateReadable ASC
        ";

        $docs = $this->wpdb->get_results($this->wpdb->prepare($sql, $deal_id));
        $this->logger->log_db_change(self::LOG_NAME, $this->table_historique, 'FETCH_DOCUMENTS_GROUPED', ['deal_id' => $deal_id, 'count' => count($docs)], $user_id);

        if (!$docs)
        {
            $this->logger->log_user_action(self::LOG_NAME, 'no_documents_found', [], $user_id);
            return [];
        }

        $grouped = [];
        foreach ($docs as $doc)
        {
            $is_article = is_numeric($doc->Historique);
            $key = ($is_article && $doc->Historique != 0)
                ? ($this->get_groupe_from_article(intval($doc->Historique), $is_purchase) ?: "Article " . intval($doc->Historique))
                : __('General', 'creation-reservoir');

            if (!isset($grouped[$key]))
            {
                $grouped[$key] = [];
            }
            $grouped[$key][] = $doc;
            $this->logger->log_user_action(self::LOG_NAME, 'document_grouped', ['key' => $key, 'doc_id' => $doc->Id], $user_id);
        }

        $this->logger->log_user_action(self::LOG_NAME, 'documents_grouped_by_article_complete', ['group_count' => count($grouped)], $user_id);
        return $grouped;
    }

    private function get_groupe_from_article($article_id, $is_purchase = false)
    {
        $user_id = get_current_user_id();
        $this->logger->log_user_action(self::LOG_NAME, 'get_groupe_from_article_start', ['article_id' => $article_id, 'is_purchase' => $is_purchase], $user_id);

        $query = $is_purchase
            ? "SELECT IdCommandeClient FROM {$this->table_achat_articles} WHERE Id = %d"
            : "SELECT groupe FROM {$this->table_projet_articles} WHERE id = %d";

        $groupe = $this->wpdb->get_var($this->wpdb->prepare($query, $article_id));
        $this->logger->log_db_change(self::LOG_NAME, $is_purchase ? $this->table_achat_articles : $this->table_projet_articles, 'FETCH_GROUPE', ['article_id' => $article_id, 'groupe' => $groupe], $user_id);

        if (!$groupe)
        {
            $this->logger->log_user_action(self::LOG_NAME, 'no_groupe_found', ['article_id' => $article_id], $user_id);
        }

        return $groupe ?: null;
    }

    private function render_doc_list($deal_id = null)
    {
        $user_id = get_current_user_id();
        global $wpdb;
        // $this->logger->log_user_action(self::LOG_NAME, 'render_doc_list_start', [], $user_id);

        // if (empty($grouped_docs))
        // {
        //     $this->logger->log_user_action(self::LOG_NAME, 'no_documents_to_render', [], $user_id);
        //     echo '<p>' . __('No documents found', 'creation-reservoir') . '.</p>';
        // }
        // else
        // {
        //     $this->logger->log_user_action(self::LOG_NAME, 'rendering_documents', ['group_count' => count($grouped_docs)], $user_id);

        //     foreach ($grouped_docs as $groupe => $docs)
        //     {
        //         $this->logger->log_user_action(self::LOG_NAME, 'rendering_group', ['groupe' => $groupe, 'doc_count' => count($docs)], $user_id);
        //         echo "<h4 class='doc-group-title'>{$groupe}</h4><ul class='ispag-documents-ul'>";

        //         foreach ($docs as $doc)
        //         {
        //             $this->logger->log_user_action(self::LOG_NAME, 'rendering_document', ['doc_id' => $doc->Id, 'post_mime_type' => $doc->post_mime_type], $user_id);

        //             $icon = str_contains($doc->post_mime_type, 'pdf') ? '📃' :
        //                    (str_contains($doc->post_mime_type, 'image') ? '🖼️' : '📄');
        //             include plugin_dir_path(__FILE__) . 'templates/render-document-display.php';
        //         }

        //         echo '</ul>';
        //     }
        // }

        // $this->logger->log_user_action(self::LOG_NAME, 'render_doc_list_complete', [], $user_id);
        if(class_exists('ISPAG_Attachments_Repository') AND class_exists('ISPAG_Attachments_Card_Renderer')){
            $repository = new ISPAG_Attachments_Repository($wpdb);
            $renderer   = new ISPAG_Attachments_Card_Renderer($repository);

            // --- Sur une fiche Deal / Projet ---

            echo $renderer->render_doc_list('project', $deal_id, -1, true);
        }
    }

    public function render_grouped_documents($grouped_docs)
    {
        $user_id = get_current_user_id();
        $this->logger->log_user_action(self::LOG_NAME, 'render_grouped_documents_start', [], $user_id);

        ob_start();
        echo '<div class="ispag-documents-wrapper">';
        echo '<div class="ispag-documents-list">';
        $this->render_doc_list($grouped_docs);
        echo '</div>';
        echo '<div class="ispag-documents-upload">';
        echo $this->create_dropzone();
        echo '</div>';
        echo '</div>';

        $this->logger->log_user_action(self::LOG_NAME, 'render_grouped_documents_complete', [], $user_id);
        return ob_get_clean();
    }

    private function create_dropzone()
    {
        $user_id = get_current_user_id();
        $this->logger->log_user_action(self::LOG_NAME, 'create_dropzone_start', [], $user_id);

        $deal_id = esc_attr(get_query_var('deal_id') ?: ($_GET['deal_id'] ?? null));
        $purchase_id = esc_attr(get_query_var('poid') ?: ($_GET['poid'] ?? null));
        $this->logger->log_user_action(self::LOG_NAME, 'dropzone_deal_id_set', ['deal_id' => $deal_id], $user_id);

        $html = '
            <div class="ispag-upload-docs">
                <h4 style="margin-top:0;">' . __('Add document', 'creation-reservoir') . '</h4>
                <form id="ispag-upload-form">
                    <div class="ispag-form-group">
                        <label for="doc_type"><strong>' . __('Document type', 'creation-reservoir') . '</strong></label>
                        <select name="doc_type" id="doc_type" required>
                            <option value="">-- ' . __('Select', 'creation-reservoir') . ' --</option>
                            ' . $this->document_type_selector_project() . '
                        </select>
                    </div>
                    <div id="dropzone" class="dropzone-area">
                        <p>' . __('Drag one or more files here', 'creation-reservoir') . '</p>
                        <p>' . __('or', 'creation-reservoir') . '</p>
                        <button type="button" id="browse-file" class="ispag-btn ispag-btn-secondary-outlined">' . __('Browse', 'creation-reservoir') . '</button>
                        <input type="file" id="file_input" name="files[]" multiple style="display:none;"/>
                    </div>
                    <input type="hidden" name="deal_id" value="' . $deal_id . '">
                    <input type="hidden" name="purchase_id" value="' . $purchase_id . '">
                    <button type="submit" class="ispag-btn ispag-btn-red">' . __('Upload all files', 'creation-reservoir') . '</button>
                </form>
                <div id="upload-status" style="margin-top:10px;"></div>
            </div>';

        $this->logger->log_user_action(self::LOG_NAME, 'dropzone_html_prepared', [], $user_id);
        return $html;
    }

    public function start_async_upload()
    {
        $user_id = get_current_user_id();
        $this->logger->log_user_action(self::LOG_NAME, 'start_async_upload_start', [], $user_id);

        check_ajax_referer('ispag_ajax_nonce', '_ajax_nonce');

        if (!current_user_can('upload_files') || empty($_FILES['files']))
        {
            $this->logger->log(self::LOG_NAME, 'ERROR: User not allowed or no files', $user_id);
            wp_send_json_error('Access denied or missing files.');
        }

        $deal_id = intval($_POST['deal_id'] ?? 0);
        $poid = intval($_POST['poid'] ?? 0);
        $article_id = intval($_POST['article_id'] ?? 0);
        $doc_type = sanitize_text_field($_POST['doc_type'] ?? '');

        $this->logger->log_user_action(self::LOG_NAME, 'async_upload_params_received', [
            'deal_id' => $deal_id,
            'poid' => $poid,
            'article_id' => $article_id,
            'doc_type' => $doc_type,
            'file_count' => count($_FILES['files']['name'])
        ], $user_id);

        $temp_dir = wp_upload_dir()['basedir'] . '/temp/';
        wp_mkdir_p($temp_dir);
        $this->logger->log_user_action(self::LOG_NAME, 'temp_dir_created', ['temp_dir' => $temp_dir], $user_id);

        $task_id = 'upload_' . $user_id . '_' . time();
        $this->logger->log_user_action(self::LOG_NAME, 'task_id_generated', ['task_id' => $task_id], $user_id);

        $uploaded_files = [];
        foreach ($_FILES['files']['tmp_name'] as $key => $tmp_name)
        {
            $file_name = sanitize_file_name($_FILES['files']['name'][$key]);
            $temp_path = $temp_dir . $task_id . '_' . $file_name;

            if (move_uploaded_file($tmp_name, $temp_path))
            {
                $uploaded_files[] = [
                    'name' => $file_name,
                    'temp_path' => $temp_path,
                ];
                $this->logger->log_user_action(self::LOG_NAME, 'file_moved_to_temp', ['file_name' => $file_name, 'temp_path' => $temp_path], $user_id);
            }
            else
            {
                $this->logger->log(self::LOG_NAME, 'ERROR: Failed to move file to temp - ' . $_FILES['files']['error'][$key], $user_id);
            }
        }

        set_transient(
            'ispag_async_upload_' . $task_id,
            [
                'deal_id' => $deal_id,
                'poid' => $poid,
                'article_id' => $article_id,
                'doc_type' => $doc_type,
                'files' => $uploaded_files,
                'user_id' => $user_id,
                'status' => 'pending',
                'created_at' => current_time('mysql'),
            ],
            HOUR_IN_SECONDS
        );
        $this->logger->log_user_action(self::LOG_NAME, 'transient_set_for_task', ['task_id' => $task_id], $user_id);

        wp_schedule_single_event(time() + 1, 'ispag_process_async_upload', [$task_id]);
        $this->logger->log_user_action(self::LOG_NAME, 'cron_event_scheduled', ['task_id' => $task_id, 'time' => time() + 1], $user_id);

        wp_send_json_success([
            'task_id' => $task_id,
            'message' => 'Upload started in the background.',
        ]);
    }

    public function check_upload_status()
    {
        $user_id = get_current_user_id();
        $this->logger->log_user_action(self::LOG_NAME, 'check_upload_status_start', [], $user_id);

        check_ajax_referer('ispag_ajax_nonce', '_ajax_nonce');

        $task_id = sanitize_text_field($_POST['task_id'] ?? '');
        if (!$task_id)
        {
            $this->logger->log(self::LOG_NAME, 'ERROR: Missing task_id', $user_id);
            wp_send_json_error('ID de tâche manquant.');
        }

        $this->logger->log_user_action(self::LOG_NAME, 'checking_task_status', ['task_id' => $task_id], $user_id);

        $task_data = get_transient('ispag_async_upload_' . $task_id);
        if ($task_data === false)
        {
            $this->logger->log(self::LOG_NAME, 'ERROR: Task not found or expired', $user_id, ['task_id' => $task_id]);
            wp_send_json_error('Task not found or expired.');
        }

        $this->logger->log_user_action(self::LOG_NAME, 'task_data_retrieved', ['status' => $task_data['status']], $user_id);

        if (isset($task_data['status']))
        {
            if ($task_data['status'] === 'completed')
            {
                $this->logger->log_user_action(self::LOG_NAME, 'task_completed', ['result' => $task_data['result']], $user_id);
                wp_send_json_success([
                    'status' => 'completed',
                    'result' => $task_data['result'] ?? null,
                ]);
            }
            elseif ($task_data['status'] === 'failed')
            {
                $this->logger->log(self::LOG_NAME, 'ERROR: Task failed', $user_id, ['error' => $task_data['error']]);
                wp_send_json_error([
                    'status' => 'failed',
                    'error' => $task_data['error'] ?? 'Unknown error.',
                ]);
            }
            else
            {
                $this->logger->log_user_action(self::LOG_NAME, 'task_status_pending', ['status' => $task_data['status']], $user_id);
                wp_send_json_success(['status' => $task_data['status']]);
            }
        }
        else
        {
            $this->logger->log(self::LOG_NAME, 'ERROR: Invalid task data', $user_id);
            wp_send_json_error('Invalid task data.');
        }
    }

    /**
     * Traite l'upload asynchrone des documents.
     * Utilise un système de verrouillage pour éviter les conflits et log toutes les actions.
     */
    public function process_async_upload($task_id) {
        $user_id = get_current_user_id();
        $this->logger->log_user_action(self::LOG_NAME, 'process_async_upload_start', ['task_id' => $task_id], $user_id);

        // --- VÉRIFIER QUE LA TÂCHE EST TOUJOURS PENDING ---
        $task_data = get_transient('ispag_async_upload_' . $task_id);
        if ($task_data === false || !isset($task_data['status']) || $task_data['status'] !== 'pending') {
            $this->logger->log(self::LOG_NAME, 'ERROR: Invalid or non-pending task', $user_id, [
                'task_id' => $task_id,
                'task_status' => $task_data['status'] ?? 'not_found',
                'current_user' => $user_id
            ]);
            return; // ❌ Sortir si la tâche n'est pas pending
        }

        // --- VÉROU POUR ÉVITER LES CONFLITS ---
        $lock_name = 'ispag_async_upload_lock_' . $task_id;
        if (get_transient($lock_name)) {
            $this->logger->log(self::LOG_NAME, 'WARNING: Task already being processed by another process', $user_id, [
                'task_id' => $task_id
            ]);
            return; // ❌ Sortir si la tâche est déjà en cours de traitement
        }
        set_transient($lock_name, true, 30); // Verrou pour 30 secondes

        try {
            $this->logger->log_user_action(self::LOG_NAME, 'processing_task', ['task_id' => $task_id], $user_id);

            // --- FORCER L'UTILISATEUR ORIGINAL ---
            $original_user = wp_get_current_user();
            wp_set_current_user($task_data['user_id']);
            $user_id = $task_data['user_id'];

            if (!current_user_can('upload_files')) {
                wp_set_current_user($original_user->ID);
                $this->logger->log_error(self::LOG_NAME, 'User cannot upload files', [
                    'user_id' => $user_id,
                    'task_id' => $task_id
                ], $user_id);
                $task_data['status'] = 'failed';
                $task_data['error'] = __('The user does not have the required permissions.', 'creation-reservoir');
                set_transient('ispag_async_upload_' . $task_id, $task_data, HOUR_IN_SECONDS);
                return;
            }

            $task_data['status'] = 'processing';
            set_transient('ispag_async_upload_' . $task_id, $task_data, HOUR_IN_SECONDS);
            $this->logger->log_user_action(self::LOG_NAME, 'task_status_updated_to_processing', [], $user_id);

            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';

            $uploaded_ids = [];
            foreach ($task_data['files'] as $file) {
                $this->logger->log_user_action(self::LOG_NAME, 'processing_file', [
                    'file_name' => $file['name'],
                    'temp_path' => $file['temp_path'],
                    'file_exists' => file_exists($file['temp_path']),
                    'is_readable' => is_readable($file['temp_path']),
                    'current_user' => wp_get_current_user()->ID,
                ], $user_id);

                if (!file_exists($file['temp_path'])) {
                    throw new Exception(__('Fichier temporaire introuvable : ', 'creation-reservoir') . $file['temp_path']);
                }
                if (!is_readable($file['temp_path'])) {
                    throw new Exception(__('Temporary file not readable: ', 'creation-reservoir') . $file['temp_path']);
                }

                $file_array = [
                    'name' => $file['name'],
                    'tmp_name' => $file['temp_path'],
                ];

                $upload = wp_handle_sideload($file_array, ['test_form' => false]);
                if (isset($upload['error'])) {
                    throw new Exception($upload['error']);
                }

                $this->logger->log_user_action(self::LOG_NAME, 'file_uploaded_via_sideload', [
                    'file_name' => $file['name'],
                    'upload' => $upload,
                ], $user_id);

                $mime_type = $this->get_mime_type($file['name']);
                $attachment = [
                    'post_title' => sanitize_file_name($file['name']),
                    'post_mime_type' => $mime_type,
                    'post_status' => 'inherit',
                    'guid' => $upload['url'],
                ];

                $attach_id = wp_insert_attachment($attachment, $upload['file']);
                if (is_wp_error($attach_id)) {
                    throw new Exception($attach_id->get_error_message());
                }

                $this->logger->log_db_change(self::LOG_NAME, $this->table_media, 'INSERT_ATTACHMENT', [
                    'attach_id' => $attach_id,
                    'file' => $upload['file'],
                    'mime_type' => $mime_type,
                ], $user_id);

                wp_generate_attachment_metadata($attach_id, $upload['file']);

                // --- INSERTION AVEC VÉRIFICATION DE ClassCss ---
                $success = $this->wpdb->insert(
                    $this->table_historique,
                    [
                        'hubspot_deal_id' => $task_data['deal_id'],
                        'purchase_order' => $task_data['poid'],
                        'Date' => current_time('timestamp'),
                        'dateReadable' => current_time('mysql'),
                        'IdUser' => $task_data['user_id'],
                        'Historique' => $task_data['article_id'],
                        'IdMedia' => $attach_id,
                        'is_task' => 0,
                        'is_done' => 0,
                        'ClassCss' => $task_data['doc_type'], // Toujours une chaîne
                    ],
                    ['%d', '%d', '%d', '%s', '%d', '%d', '%d', '%d', '%d', '%s']
                );

                if ($success === false) {
                    throw new Exception($this->wpdb->last_error);
                }

                $this->logger->log_db_change(self::LOG_NAME, $this->table_historique, 'INSERT_DOCUMENT_HISTORY', [
                    'deal_id' => $task_data['deal_id'],
                    'poid' => $task_data['poid'],
                    'article_id' => $task_data['article_id'],
                    'attach_id' => $attach_id,
                    'doc_type' => $task_data['doc_type'],
                ], $user_id);

                // Vérification post-insertion
                $inserted_id = $this->wpdb->insert_id;
                $check = $this->wpdb->get_var(
                    $this->wpdb->prepare("SELECT ClassCss FROM {$this->table_historique} WHERE Id = %d", $inserted_id)
                );

                $this->logger->log_db_change(self::LOG_NAME, $this->table_historique, 'VERIFY_CLASSCSS', [
                    'inserted_id' => $inserted_id,
                    'doc_type_sent' => $task_data['doc_type'],
                    'ClassCss_in_db' => $check,
                    'match' => ($check === $task_data['doc_type']) ? 'YES' : 'NO'
                ], $user_id);

                // Vérifier que ClassCss a bien été inséré
                if ($check !== $task_data['doc_type']) {
                    $this->logger->log_error(self::LOG_NAME, 'ClassCss mismatch after insert', [
                        'expected' => $task_data['doc_type'],
                        'actual' => $check,
                        'inserted_id' => $inserted_id,
                        'table_charset' => $this->wpdb->get_charset($this->table_historique)
                    ], $user_id);

                    // Tentative de correction
                    $corrected = $this->wpdb->update(
                        $this->table_historique,
                        ['ClassCss' => $task_data['doc_type']],
                        ['Id' => $inserted_id],
                        ['%s'],
                        ['%d']
                    );

                    if ($corrected !== false) {
                        $check = $this->wpdb->get_var(
                            $this->wpdb->prepare("SELECT ClassCss FROM {$this->table_historique} WHERE Id = %d", $inserted_id)
                        );
                        $this->logger->log_user_action(self::LOG_NAME, 'ClassCss_corrected', [
                            'inserted_id' => $inserted_id,
                            'new_ClassCss' => $check,
                            'correction_success' => ($check === $task_data['doc_type']) ? 'YES' : 'NO'
                        ], $user_id);
                    } else {
                        $this->logger->log_error(self::LOG_NAME, 'Failed to correct ClassCss', [
                            'inserted_id' => $inserted_id,
                            'wpdb_error' => $this->wpdb->last_error
                        ], $user_id);
                    }
                }

                $uploaded_ids[] = $attach_id;

                // Déclencher des actions spécifiques selon le type de document
                if ($task_data['doc_type'] == 'drawingApproval') {
                    do_action('ispag_validate_drawing', '', $task_data['article_id'], $attach_id, $task_data['user_id'], $task_data['doc_type']);
                    $this->logger->log_user_action(self::LOG_NAME, 'drawing_validation_triggered', [
                        'article_id' => $task_data['article_id'],
                        'attach_id' => $attach_id
                    ], $user_id);
                } elseif (in_array($task_data['doc_type'], ['drawingModification', 'sketch', 'product_drawing'])) {
                    do_action('ispag_save_drawing', '', $task_data['article_id'], $attach_id, $task_data['user_id'], $task_data['doc_type']);
                    $this->logger->log_user_action(self::LOG_NAME, 'drawing_save_triggered', [
                        'article_id' => $task_data['article_id'],
                        'attach_id' => $attach_id
                    ], $user_id);
                }

                wp_delete_file($file['temp_path']);
                $this->logger->log_user_action(self::LOG_NAME, 'temp_file_deleted', ['temp_path' => $file['temp_path']], $user_id);
            }

            // --- NOTIFICATION CENTRALISÉE VIA ISPAG_Notifications_Manager ---
            if (class_exists('ISPAG_Notifications_Manager')) {
                $target_user_id = $task_data['user_id'];

                // Nettoyage si l'ID contient déjà le préfixe 'WP_'
                if (is_string($target_user_id) && strpos($target_user_id, 'WP_') === 0) {
                    $target_user_id = intval(str_replace('WP_', '', $target_user_id));
                }

                $this->logger->log_user_action(self::LOG_NAME, 'notification_preparation_start', [
                    'user_id' => $target_user_id,
                    'class_exists' => class_exists('ISPAG_Notifications_Manager')
                ], $user_id);

                try {
                    $this->logger->log_user_action(self::LOG_NAME, 'notification_sending', [
                        'user_id' => $target_user_id,
                        'title' => "✅ " . __('Upload successful', 'creation-reservoir'),
                        'message' => __('Your documents have been uploaded successfully!', 'creation-reservoir'),
                        'deal_id' => $task_data['deal_id']
                    ], $user_id);

                    // Envoi de la notification via le gestionnaire central
                    $result = ISPAG_Notifications_Manager::send(
                        [$target_user_id], // Destinataires : utilisateur + admin
                        'document_upload', // Type de notification
                        "✅ " . __('Upload successful', 'creation-reservoir'),
                        __('Your documents have been uploaded successfully!', 'creation-reservoir'),
                        "project-detail/" . $task_data['deal_id'], // URL
                        $task_data['deal_id'] // ID de l'entité
                    );

                    $this->logger->log_user_action(self::LOG_NAME, 'notification_sent_success', [
                        'result' => $result,
                        'user_id' => $target_user_id,
                        'deal_id' => $task_data['deal_id']
                    ], $user_id);

                } catch (Exception $e) {
                    $this->logger->log_critical(self::LOG_NAME, $e, [
                        'context' => 'notification_failed',
                        'user_id' => $target_user_id,
                        'deal_id' => $task_data['deal_id']
                    ], $user_id);
                }
            }

            $task_data['status'] = 'completed';
            $task_data['result'] = [
                'uploaded_ids' => $uploaded_ids,
                'articles_list' => apply_filters('ispag_reload_article_list', $task_data['deal_id'], null),
            ];
            set_transient('ispag_async_upload_' . $task_id, $task_data, HOUR_IN_SECONDS);
            $this->logger->log_user_action(self::LOG_NAME, 'task_completed', ['uploaded_ids' => $uploaded_ids], $user_id);

        } catch (Exception $e) {
            $this->logger->log_error(self::LOG_NAME, 'Async upload failed', [
                'error' => $e->getMessage(),
                'task_id' => $task_id,
                'file' => $file['name'] ?? 'unknown',
                'current_user' => wp_get_current_user()->ID,
            ], $user_id);

            $task_data['status'] = 'failed';
            $task_data['error'] = $e->getMessage();
            set_transient('ispag_async_upload_' . $task_id, $task_data, HOUR_IN_SECONDS);
        } finally {
            // Libérer le verrou
            delete_transient($lock_name);
            // Restaurer l'utilisateur original
            wp_set_current_user($original_user->ID);
        }
    }
    /**
     * Détermine le type MIME en fonction de l'extension du fichier.
     */
    private function get_mime_type($filename) {
        $extension = pathinfo($filename, PATHINFO_EXTENSION);
        $mimes = [
            'pdf' => 'application/pdf',
            'msg' => 'application/vnd.ms-outlook',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'doc' => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xls' => 'application/vnd.ms-excel',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ];
        return $mimes[strtolower($extension)] ?? 'application/octet-stream';
    }

    private function document_type_selector_project()
    {
        $user_id = get_current_user_id();
        $this->logger->log_user_action(self::LOG_NAME, 'document_type_selector_project_start', [], $user_id);

        $selector = '';
        $deal_id = intval(get_query_var('deal_id') ?: ($_GET['deal_id'] ?? null));
        $can_manage_order = current_user_can('manage_order');
        $this->logger->log_user_action(self::LOG_NAME, 'selector_params_set', ['deal_id' => $deal_id, 'can_manage_order' => $can_manage_order], $user_id);

        $docs = $this->get_documents_grouped_by_article($deal_id, false);
        $article_order = [];
        foreach ($docs as $group => $group_docs)
        {
            foreach ($group_docs as $doc)
            {
                if (is_numeric($doc->Historique) && $doc->Historique != 0)
                {
                    $article_id = intval($doc->Historique);
                    if (!in_array($article_id, $article_order))
                    {
                        $article_order[] = $article_id;
                        $this->logger->log_user_action(self::LOG_NAME, 'article_added_to_order', ['article_id' => $article_id], $user_id);
                    }
                }
            }
        }

        $repo_article = new ISPAG_Article_Repository();
        // $all_articles = $repo_article->get_articles_by_deal($deal_id);
        if (current_user_can('navigate_new_project_details_presentation')) {
            $all_articles = $repo_article->get_optimised_articles_by_deal($deal_id);
        }
        else{
            $all_articles = $repo_article->get_articles_by_deal($deal_id);
        }
        $this->logger->log_db_change(self::LOG_NAME, 'articles', 'FETCH_BY_DEAL', ['deal_id' => $deal_id, 'count' => count($all_articles)], $user_id);

        $restriction_condition = !$can_manage_order ? " AND dt.restricted = 0" : "";
        $sql_general_docs = "
            SELECT dt.*
            FROM {$this->table_doc_type} dt
            WHERE dt.for_article_type = 0
            {$restriction_condition}
            ORDER BY dt.sort_order ASC
        ";
        $general_doc_types = $this->wpdb->get_results($this->wpdb->prepare($sql_general_docs));
        $this->logger->log_db_change(self::LOG_NAME, $this->table_doc_type, 'FETCH_GENERAL_DOC_TYPES', ['count' => count($general_doc_types)], $user_id);

        $sql_article_docs = "
            SELECT dt.*
            FROM {$this->table_doc_type} dt
            WHERE dt.for_article_type = 1
            ORDER BY dt.sort_order ASC
        ";
        $article_doc_types = $this->wpdb->get_results($this->wpdb->prepare($sql_article_docs));
        $this->logger->log_db_change(self::LOG_NAME, $this->table_doc_type, 'FETCH_ARTICLE_DOC_TYPES', ['count' => count($article_doc_types)], $user_id);

        foreach ($general_doc_types as $doc)
        {
            $selector .= '<option value="' . esc_attr($doc->slug) . '"
                                data-class="' . esc_attr($doc->slug) . '">'
                . esc_html__($doc->label, 'creation-reservoir') .
                '</option>';
            $this->logger->log_user_action(self::LOG_NAME, 'general_doc_type_added', ['slug' => $doc->slug], $user_id);
        }

        foreach ($all_articles as $group => $group_articles)
        {
            foreach ($group_articles as $article)
            {
                if (!in_array(intval($article->Type), [1, 4, 5]))
                {
                    $this->logger->log_user_action(self::LOG_NAME, 'article_type_skipped', ['article_id' => $article->Id, 'type' => $article->Type], $user_id);
                    continue;
                }

                $article_id = intval($article->Id);
                $groupe = $group ?: __('No group', 'creation-reservoir');
                $title = $article->Article ?: __('No title', 'creation-reservoir');
                $approved = $article->DrawingApproved;

                if (in_array($article_id, $article_order))
                {
                    $key = array_search($article_id, $article_order);
                    unset($article_order[$key]);
                    $this->logger->log_user_action(self::LOG_NAME, 'article_removed_from_order', ['article_id' => $article_id], $user_id);
                }

                $selector .= '<optgroup label="' . esc_html(stripslashes($groupe)) . '" class="header_group">';
                $selector .= '<option
                                value="product_drawing"
                                data-class="product_drawing"
                                data-product-id="' . $article_id . '">'
                    . esc_html(stripslashes($title)) .
                    '</option>';

                foreach ($article_doc_types as $doc)
                {
                    if ($can_manage_order || $doc->restricted == 0)
                    {
                        $selector .= '<option value="' . esc_attr($doc->slug) . '"
                                    data-class="' . esc_attr($doc->slug) . '"
                                    data-product-id="' . $article_id . '">
                                    📃 ' . esc_html__(stripslashes($doc->label), 'creation-reservoir') . ' ' . esc_html(stripslashes($title)) .
                                    '</option>';
                        $this->logger->log_user_action(self::LOG_NAME, 'article_doc_type_added', ['article_id' => $article_id, 'slug' => $doc->slug], $user_id);
                    }
                }

                $selector .= '</optgroup>';
            }
        }

        $this->logger->log_user_action(self::LOG_NAME, 'document_type_selector_complete', [], $user_id);
        return $selector;
    }

    public function delete_document_whith_deal_id($html, $deal_id = null)
    {
        $user_id = get_current_user_id();
        $this->logger->log_user_action(self::LOG_NAME, 'delete_document_whith_deal_id_start', ['deal_id' => $deal_id], $user_id);

        if (!current_user_can('upload_files'))
        {
            $this->logger->log(self::LOG_NAME, 'ERROR: User not allowed to delete documents', $user_id);
            wp_send_json_error(__('You are not allowed', 'creation-reservoir'));
        }

        if (!$deal_id)
        {
            $this->logger->log(self::LOG_NAME, 'ERROR: Missing deal_id', $user_id);
            wp_send_json_error(__('No deal Id defined', 'creation-reservoir'));
        }

        $media_ids = $this->wpdb->get_col($this->wpdb->prepare(
            "SELECT IdMedia FROM $this->table_historique WHERE hubspot_deal_id = %d AND IdMedia > 0",
            $deal_id
        ));
        $this->logger->log_db_change(self::LOG_NAME, $this->table_historique, 'FETCH_MEDIA_IDS', ['deal_id' => $deal_id, 'count' => count($media_ids)], $user_id);

        foreach ($media_ids as $media_id)
        {
            $result = wp_delete_attachment($media_id, true);
            $this->logger->log_db_change(self::LOG_NAME, $this->table_media, 'DELETE_ATTACHMENT', ['media_id' => $media_id, 'result' => $result], $user_id);
        }

        $deleted = $this->wpdb->delete($this->table_historique, ['hubspot_deal_id' => $deal_id]);
        $this->logger->log_db_change(self::LOG_NAME, $this->table_historique, 'DELETE_DOCUMENTS', ['deal_id' => $deal_id, 'result' => $deleted], $user_id);

        $this->logger->log_user_action(self::LOG_NAME, 'delete_document_whith_deal_id_complete', [], $user_id);
    }

    public function delete_document()
    {
        $user_id = get_current_user_id();
        $this->logger->log_user_action(self::LOG_NAME, 'delete_document_start', [], $user_id);

        check_ajax_referer('ispag_ajax_nonce', '_ajax_nonce');

        if (!current_user_can('upload_files'))
        {
            $this->logger->log(self::LOG_NAME, 'ERROR: User not allowed to delete document', $user_id);
            wp_send_json_error(__('You are not allowed', 'creation-reservoir'));
        }

        $doc_id = intval($_POST['document_id'] ?? 0);
        if (!$doc_id)
        {
            $this->logger->log(self::LOG_NAME, 'ERROR: Missing document_id', $user_id);
            wp_send_json_error(__('No Id defined', 'creation-reservoir'));
        }

        $this->logger->log_user_action(self::LOG_NAME, 'deleting_document', ['doc_id' => $doc_id], $user_id);

        $deleted = wp_delete_attachment($doc_id, true);
        $this->logger->log_db_change(self::LOG_NAME, $this->table_media, 'DELETE_ATTACHMENT', ['doc_id' => $doc_id, 'result' => $deleted], $user_id);

        if ($deleted)
        {
            $this->wpdb->delete($this->table_historique, ['IdMedia' => $doc_id]);
            $this->logger->log_db_change(self::LOG_NAME, $this->table_historique, 'DELETE_DOCUMENT_HISTORY', ['doc_id' => $doc_id], $user_id);

            $this->logger->log_user_action(self::LOG_NAME, 'delete_document_complete', [], $user_id);
            wp_send_json_success();
        }
        else
        {
            $this->logger->log(self::LOG_NAME, 'ERROR: Failed to delete document', $user_id);
            wp_send_json_error('Unable to delete the document');
        }
    }

    public function save_historique_views()
    {
        $user_id = get_current_user_id();
        $this->logger->log_user_action(self::LOG_NAME, 'save_historique_views_start', [], $user_id);

        global $wpdb;
        $table_name = $wpdb->prefix . 'achats_historique_views';

        check_ajax_referer('ispag_ajax_nonce', '_ajax_nonce');

        $media_id = isset($_POST['media_id']) ? intval($_POST['media_id']) : 0;
        $this->logger->log_user_action(self::LOG_NAME, 'media_id_received', ['media_id' => $media_id], $user_id);

        if ($media_id > 0 && $user_id > 0)
        {
            $data = ['user_id' => $user_id, 'document_id' => $media_id, 'note_id' => 0];
            $format = ['%d', '%d', '%d'];

            $result = $wpdb->insert($table_name, $data, $format);
            $this->logger->log_db_change(self::LOG_NAME, $table_name, 'INSERT_VIEW', ['media_id' => $media_id, 'user_id' => $user_id, 'result' => $result], $user_id);

            if ($result)
            {
                $this->logger->log_user_action(self::LOG_NAME, 'view_saved_successfully', [], $user_id);
                wp_send_json_success('Reading saved successfully.');
            }
            else
            {
                $this->logger->log(self::LOG_NAME, 'ERROR: Failed to save view', $user_id);
                wp_send_json_error('Error lors de l\'enregistrement de la lecture.');
            }
        }
        else
        {
            $this->logger->log(self::LOG_NAME, 'ERROR: Invalid media_id or user_id', $user_id);
            wp_send_json_error('Invalid data.');
        }

        wp_die();
    }

    /**
     * Upload un ou plusieurs documents et les associe à un deal ou une commande d'achat.
     * Utilise wp_handle_sideload pour contourner les restrictions de WordPress.
     * Logging : Toutes les actions sont loguées dans ispag_document_manager.log.
     */
    public function upload_document()
    {
        $user_id = get_current_user_id();
        $this->logger->log_user_action(self::LOG_NAME, 'upload_document_start', [], $user_id);

        // Vérification des permissions et des fichiers
        if (!current_user_can('upload_files') || empty($_FILES['files'])) {
            $this->logger->log_error(self::LOG_NAME, 'User not allowed or no files', [
                'current_user_can_upload' => current_user_can('upload_files'),
                'files_count' => empty($_FILES['files']) ? 0 : count($_FILES['files']['name'])
            ], $user_id);
            wp_send_json_error('Access denied or missing files');
        }

        // Charger les fichiers nécessaires pour l'upload
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        // Récupérer les paramètres depuis $_POST
        $deal_id = intval($_POST['deal_id'] ?? 0);
        $poid = intval($_POST['poid'] ?? 0);
        $article_id = intval($_POST['article_id'] ?? 0);
        $doc_type = sanitize_text_field($_POST['doc_type'] ?? '');

        // --- GESTION CRITIQUE DE $doc_type ---
        if (empty($doc_type)) {
            $doc_type = 'general'; // Valeur par défaut
            $this->logger->log_error(self::LOG_NAME, 'doc_type was empty, set to default', [
                'post_doc_type' => $_POST['doc_type'] ?? 'NOT_SET',
                'default_used' => 'general'
            ], $user_id);
        }

        if (!is_string($doc_type)) {
            $doc_type = (string) $doc_type;
            $this->logger->log_error(self::LOG_NAME, 'doc_type was not a string, cast to string', [
                'original_type' => gettype($_POST['doc_type'] ?? null),
                'new_value' => $doc_type
            ], $user_id);
        }

        $this->logger->log_user_action(self::LOG_NAME, 'upload_params_received', [
            'deal_id' => $deal_id,
            'poid' => $poid,
            'article_id' => $article_id,
            'doc_type' => $doc_type,
            'file_count' => count($_FILES['files']['name'])
        ], $user_id);

        $uploaded_ids = [];
        $file_count = count($_FILES['files']['name']);
        $timestamp = current_time('timestamp');
        $now = current_time('mysql');

        for ($i = 0; $i < $file_count; $i++) {
            // Vérifier les erreurs d'upload pour chaque fichier
            if ($_FILES['files']['error'][$i] !== UPLOAD_ERR_OK) {
                $this->logger->log_error(self::LOG_NAME, 'File upload error', [
                    'file_index' => $i,
                    'file_name' => $_FILES['files']['name'][$i] ?? 'unknown',
                    'error_code' => $_FILES['files']['error'][$i],
                    'error_message' => $this->getUploadErrorMessage($_FILES['files']['error'][$i])
                ], $user_id);
                continue;
            }

            // Préparer les données du fichier
            $file = [
                'name' => $_FILES['files']['name'][$i],
                'type' => $_FILES['files']['type'][$i],
                'tmp_name' => $_FILES['files']['tmp_name'][$i],
                'error' => $_FILES['files']['error'][$i],
                'size' => $_FILES['files']['size'][$i],
            ];

            $this->logger->log_user_action(self::LOG_NAME, 'processing_file', [
                'file_name' => $file['name'],
                'file_size' => $file['size'],
                'file_type' => $file['type']
            ], $user_id);

            // Utiliser wp_handle_sideload pour contourner les restrictions de WordPress
            try {
                $upload = wp_handle_sideload($file, ['test_form' => false]);
                if (isset($upload['error'])) {
                    $this->logger->log_error(self::LOG_NAME, 'wp_handle_sideload failed', [
                        'error' => $upload['error'],
                        'file_name' => $file['name'],
                        'file_tmp_name' => $file['tmp_name'],
                        'file_exists' => file_exists($file['tmp_name'])
                    ], $user_id);
                    continue;
                }

                $this->logger->log_user_action(self::LOG_NAME, 'file_uploaded_via_sideload', [
                    'file_name' => $file['name'],
                    'upload_path' => $upload['file'],
                    'upload_url' => $upload['url'],
                    'upload_type' => $upload['type']
                ], $user_id);

                // Créer l'attachement dans WordPress
                $attachment = [
                    'post_title' => sanitize_file_name($file['name']),
                    'post_mime_type' => $upload['type'],
                    'post_status' => 'inherit',
                    'guid' => $upload['url'],
                ];

                $attach_id = wp_insert_attachment($attachment, $upload['file']);
                if (is_wp_error($attach_id) || !$attach_id) {
                    $this->logger->log_error(self::LOG_NAME, 'Failed to insert attachment', [
                        'file' => $file['name'],
                        'wp_error' => is_wp_error($attach_id) ? $attach_id->get_error_message() : 'Unknown error',
                        'upload_file' => $upload['file']
                    ], $user_id);
                    continue;
                }

                $this->logger->log_db_change(self::LOG_NAME, $this->table_media, 'INSERT_ATTACHMENT', [
                    'attach_id' => $attach_id,
                    'file' => $upload['file'],
                    'mime_type' => $upload['type']
                ], $user_id);

                // Générer les métadonnées de l'attachement
                wp_generate_attachment_metadata($attach_id, $upload['file']);

                // --- INSERTION DANS L'HISTORIQUE AVEC VÉRIFICATION ---
                $success = $this->wpdb->query(
                    $this->wpdb->prepare(
                        "INSERT INTO {$this->table_historique}
                        (hubspot_deal_id, purchase_order, Date, dateReadable, IdUser, Historique, IdMedia, is_task, is_done, ClassCss)
                        VALUES (%d, %d, %d, %s, %d, %d, %d, %d, %d, %s)",
                        $deal_id,
                        $poid,
                        $timestamp,
                        $now,
                        $user_id,
                        $article_id,
                        $attach_id,
                        0,
                        0,
                        $doc_type  // %s pour ClassCss (chaîne)
                    )
                );

                if ($success === false) {
                    $this->logger->log_error(self::LOG_NAME, 'Insert into historique failed', [
                        'wpdb_error' => $this->wpdb->last_error,
                        'doc_type' => $doc_type,
                        'sql' => $this->wpdb->last_query,
                        'table' => $this->table_historique
                    ], $user_id);
                    continue;
                }

                // Vérification post-insertion
                $inserted_id = $this->wpdb->insert_id;
                $check = $this->wpdb->get_var(
                    $this->wpdb->prepare("SELECT ClassCss FROM {$this->table_historique} WHERE Id = %d", $inserted_id)
                );

                $this->logger->log_db_change(self::LOG_NAME, $this->table_historique, 'INSERT_DOCUMENT_HISTORY', [
                    'deal_id' => $deal_id,
                    'poid' => $poid,
                    'article_id' => $article_id,
                    'attach_id' => $attach_id,
                    'doc_type_sent' => $doc_type,
                    'ClassCss_in_db' => $check,
                    'match' => ($check === $doc_type) ? 'YES' : 'NO'
                ], $user_id);

                // Vérifier que ClassCss a bien été inséré
                if ($check !== $doc_type) {
                    $this->logger->log_error(self::LOG_NAME, 'ClassCss mismatch after insert', [
                        'expected' => $doc_type,
                        'actual' => $check,
                        'inserted_id' => $inserted_id,
                        'table_charset' => $this->wpdb->get_charset($this->table_historique)
                    ], $user_id);

                    // Tentative de correction
                    $corrected = $this->wpdb->update(
                        $this->table_historique,
                        ['ClassCss' => $doc_type],
                        ['Id' => $inserted_id],
                        ['%s'],
                        ['%d']
                    );

                    if ($corrected !== false) {
                        $check = $this->wpdb->get_var(
                            $this->wpdb->prepare("SELECT ClassCss FROM {$this->table_historique} WHERE Id = %d", $inserted_id)
                        );
                        $this->logger->log_user_action(self::LOG_NAME, 'ClassCss_corrected', [
                            'inserted_id' => $inserted_id,
                            'new_ClassCss' => $check,
                            'correction_success' => ($check === $doc_type) ? 'YES' : 'NO'
                        ], $user_id);
                    } else {
                        $this->logger->log_error(self::LOG_NAME, 'Failed to correct ClassCss', [
                            'inserted_id' => $inserted_id,
                            'wpdb_error' => $this->wpdb->last_error
                        ], $user_id);
                    }
                }

                $uploaded_ids[] = $attach_id;

                // Déclencher des actions spécifiques selon le type de document
                if ($doc_type == 'drawingApproval') {
                    do_action('ispag_validate_drawing', '', $article_id, $attach_id, $user_id, $doc_type);
                    $this->logger->log_user_action(self::LOG_NAME, 'drawing_validation_triggered', [
                        'article_id' => $article_id,
                        'attach_id' => $attach_id
                    ], $user_id);

                    if (!current_user_can('manage_order')) {
                        do_action('ispag_send_telegram_notification', null, 'drawing_validated', true, true, $deal_id, true);
                        $this->logger->log_user_action(self::LOG_NAME, 'telegram_notification_sent', [
                            'notification' => 'drawing_validated'
                        ], $user_id);
                    }
                } elseif (in_array($doc_type, ['drawingModification', 'sketch', 'product_drawing'])) {
                    do_action('ispag_save_drawing', '', $article_id, $attach_id, $user_id, $doc_type);
                    $this->logger->log_user_action(self::LOG_NAME, 'drawing_save_triggered', [
                        'article_id' => $article_id,
                        'attach_id' => $attach_id
                    ], $user_id);

                    if (!current_user_can('manage_order')) {
                        do_action('ispag_send_telegram_notification', null, 'newDocUploaded', true, true, $deal_id, true);
                        $this->logger->log_user_action(self::LOG_NAME, 'telegram_notification_sent', [
                            'notification' => 'newDocUploaded'
                        ], $user_id);
                    }
                }

            } catch (Exception $e) {
                $this->logger->log_critical(self::LOG_NAME, $e, [
                    'file_index' => $i,
                    'file_name' => $file['name'] ?? 'unknown',
                    'doc_type' => $doc_type
                ], $user_id);
                continue;
            }
        }

        if (empty($uploaded_ids)) {
            $this->logger->log_error(self::LOG_NAME, 'No valid files uploaded', [
                'file_count' => $file_count,
                'doc_type' => $doc_type
            ], $user_id);
            wp_send_json_error('No valid file uploaded.');
        }

        $this->logger->log_user_action(self::LOG_NAME, 'upload_document_complete', [
            'uploaded_ids' => $uploaded_ids,
            'file_count' => $file_count
        ], $user_id);

        $articles_list = apply_filters('ispag_reload_article_list', $deal_id, null);
        wp_send_json_success([
            'uploaded_ids' => $uploaded_ids,
            'articles_list' => $articles_list,
        ]);
    }
    /**
     * Retourne un message d'erreur lisible pour les codes d'erreur d'upload PHP
     * @param int $error_code Code d'erreur UPLOAD_ERR_*
     * @return string Message d'erreur lisible
     */
    private function getUploadErrorMessage($error_code) {
        switch ($error_code) {
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                return 'Fichier trop volumineux';
            case UPLOAD_ERR_PARTIAL:
                return 'File partially uploaded';
            case UPLOAD_ERR_NO_FILE:
                return 'No file uploaded';
            case UPLOAD_ERR_NO_TMP_DIR:
                return 'Dossier temporaire manquant';
            case UPLOAD_ERR_CANT_WRITE:
                return 'Failed to write the file to disk';
            case UPLOAD_ERR_EXTENSION:
                return 'File extension not allowed';
            default:
                return 'Unknown error';
        }
    }
}