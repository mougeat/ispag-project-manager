<?php
defined('ABSPATH') or die();

/**
 * Class ISPAG_Detail_Page
 * Gère l'affichage et les actions de la page de détail des projets/achats.
 * Logging : Toutes les actions sont loguées dans ispag_detail_page.log.
 */
class ISPAG_Detail_Page
{
    /** @var ISPAG_Logger Instance du logger. */
    private static $logger;

    public static function init()
    {
        self::$logger = ISPAG_Logger::get_instance();
        $user_id = get_current_user_id();
        // self::$logger->log_user_action('detail_page', 'class_initialized', [], $user_id);

        new ISPAG_Projet_Suivi();

        add_shortcode('ispag_detail', [self::class, 'render']);
        add_action('wp_enqueue_scripts', [self::class, 'enqueue_assets'], 5);
        add_action('wp_ajax_ispag_convert_to_project', [self::class, 'convert_to_project']);
        add_filter('ispag_delete_project_btn', [self::class, 'delete_project_btn'], 10, 2);
        add_action('wp_ajax_ispag_delete_project', [self::class, 'delete_project']);

        add_filter('ispag_reload_article_list', [self::class, 'reload_article_list'], 10, 2);
        add_action('wp_ajax_ispag_reload_article_list', [self::class, 'ajax_reload_article_list']);

        add_action('wp_ajax_update_project_title', [self::class, 'ajax_update_project_title']);
        add_action('wp_ajax_update_project_associations', [self::class, 'ajax_update_associations']);

        add_filter('ispag_generate_purchase_order_pdf', function($default, $project_header, $project_data, $infos, $table_header, $articles, $title)
        {
            $user_id = get_current_user_id();
            self::$logger->log_user_action('detail_page', 'generate_purchase_order_pdf_filter', ['title' => $title], $user_id);

            require_once __DIR__ . '/class-ispag-pdf-generator.php';
            $pdf = new ISPAG_PDF_Generator();
            $pdf->generate_delivery_note($project_header, $project_data, $infos, $table_header, $articles, $title, true);
            return $pdf;
        }, 10, 7);

        // Notifier l'admin des modifications sur une offre par un client
        add_action('wp_ajax_ispag_notify_admin_quotation_changes', [self::class, 'ispag_notify_admin_quotation_changes']);


        add_action( 'wp_footer', [self::class, 'display_modal'] );

        // add_action('wp_footer', function() {
        //     echo self::display_doc_analyser_modal();
        // });

        add_action('wp_footer', function() {
            // Vérifiez que la fonction existe avant de l'appeler, ou collez directement le HTML ci-dessous
            if (class_exists('Votre_Classe_Principale') && method_exists('Votre_Classe_Principale', 'display_doc_analyser_modal')) {
                echo Votre_Classe_Principale::display_doc_analyser_modal();
            } else {
                // HTML de secours direct injecté dans le footer si la méthode est ailleurs
                echo '
                <div id="ispag-drawing-comparison-modal" class="ispag-modal-overlay">
                    <div class="ispag-modal-content">
                        <div class="ispag-modal-header">
                            <h4>ISPAG Compliance Analysis</h4>
                            <span class="ispag-close-modal ispag-close-croix">&times;</span>
                        </div>
                        <div id="ispag-drawing-comparison-modal-body" class="ispag-modal-body"></div>
                        <div class="ispag-modal-footer" style="text-align:right; border-top:1px solid #eee;">
                            <button type="button" class="button ispag-close-modal">Close</button>
                        </div>
                    </div>
                </div>';
            }
        });
        
    }

    public static function enqueue_assets()
    {
        $user_id = get_current_user_id();
        // self::$logger->log_user_action('detail_page', 'enqueue_assets_start', [], $user_id);

        wp_enqueue_script('ispag-detail-display', plugin_dir_url(__FILE__) . '../assets/js/details.js', [], @filemtime(plugin_dir_path(__FILE__) . '../assets/js/details.js') ?: false, true);
        wp_enqueue_script('ispag-project-datas-loader', plugin_dir_url(__FILE__) . '../assets/js/ispag-project-datas-loader.js', [], false, true);
        wp_enqueue_script('ispag-text-copy', plugin_dir_url(__FILE__) . '../assets/js/text_copy.js', [], false, true);

        if (current_user_can('display_sales_prices'))
        {
            wp_enqueue_script('ispag-price-visibility', plugin_dir_url(__FILE__) . '../assets/js/price-visibility.js', ['ispag-detail-display'], false, true);
            // self::$logger->log_user_action('detail_page', 'gps_position_script_enqueued', [], $user_id);
        }

        wp_enqueue_script('ispag-detail-inline-edit', plugin_dir_url(__FILE__) . '../assets/js/inline-edit.js', ['ispag-detail-display'], false, true);
        wp_enqueue_script('ispag-detail-delivery', plugin_dir_url(__FILE__) . '../assets/js/delivery-edit.js', ['jquery'], false, true);
        wp_enqueue_script('ispag-detail-tabs', plugin_dir_url(__FILE__) . '../assets/js/tabs.js', ['ispag-detail-display'], false, true);
        wp_enqueue_script('ispag-detail-suivi', plugin_dir_url(__FILE__) . '../assets/js/suivi.js', ['ispag-detail-display'], @filemtime(plugin_dir_path(__FILE__) . '../assets/js/suivi.js') ?: false, true);
        wp_enqueue_script('ispag-change-tracker', plugin_dir_url(__FILE__) . '../assets/js/change-tracker.js', ['ispag-detail-display'], false, true);
        wp_enqueue_script('ispag-fittings-change-tracker', plugin_dir_url(__FILE__) . '../assets/js/change-fittings-tracker.js', ['ispag-detail-display'], false, true);

        // Sans manage_order : pas d'onglet Activités (le modèle de page du thème peut l'afficher en dur)
        if (!current_user_can('manage_order'))
        {
            wp_add_inline_script('ispag-detail-display', "document.addEventListener('DOMContentLoaded',function(){document.querySelectorAll('[data-tab=\"activities\"],[data-tab=\"activity\"],#activities').forEach(function(e){e.remove();});document.querySelectorAll('.tab-titles li,.ispag-tabs li,.ispag-tab-btn,[role=\"tab\"]').forEach(function(e){if(e.textContent.trim().toLowerCase()==='activities'){e.remove();}});});");
        }

        $statuses = (new ISPAG_Projet_Suivi())->get_all_statuses();
        wp_localize_script('ispag-detail-tabs', 'ispagStatusChoices', $statuses);
        // self::$logger->log_user_action('detail_page', 'status_choices_localized', ['count' => count($statuses)], $user_id);

        $allowed_roles = ['administrator', 'vente_ispag', 'membre_ispag', 'achat_ispag', 'ispag_commercial'];
        // $allowed_roles = ['administrator'];

        $current_user = wp_get_current_user();
        $is_client = empty(array_intersect((array) $current_user->roles, $allowed_roles));

        wp_localize_script('ispag-detail-suivi', 'ispag_suivis', [
            'ajax_url'  => admin_url('admin-ajax.php'),
            'nonce'     => wp_create_nonce('ispag_nonce'),
            'deal_id'   => get_query_var('deal_id') ?: null,
            'is_client' => $is_client,
        ]);

        wp_localize_script('ispag-detail-display', 'ispag_texts', [
            'ajax_url'                      => admin_url('admin-ajax.php'),
            'nonce'                         => wp_create_nonce('ispag_nonce'),
            'fallow_up_squeleton'           => ispag_get_template( 'ispag-fallow-up-squeleton', [  ] ),
            'activity_squeleton'            => ispag_get_template( 'ispag-activity-squeleton', [  ] ),
            'yes'                           => __('Yes', 'creation-reservoir'),
            'valid'                         => __('Apply', 'creation-reservoir'),
            'save'                          => __('Save', 'creation-reservoir'),
            'confirm'                       => __('Confirm', 'creation-reservoir'),
            'previous'                      => __('Previous ', 'creation-reservoir'),
            'cancel'                        => __('Cancel', 'creation-reservoir'),
            'delete'                        => __('Delete', 'creation-reservoir'),
            'replicate'                     => __('Replicate', 'creation-reservoir'),
            'consult'                       => __('Open', 'creation-reservoir'),
            'converting'                    => __('Converting', 'creation-reservoir'),
            'quit_without_saving'           => __('Quit without saving', 'creation-reservoir'),
            'confirm_delete_article'        => __('Delete this item', 'creation-reservoir'),
            'would_you_copy'                => __('Would you really copy this article', 'creation-reservoir'),
            'would_you_replicate_project'   => __('Would you really replicate this project', 'creation-reservoir'),
            'article_duplicated'            => __('Article duplicated', 'creation-reservoir'),
            'modal_unsaved_changes_warning' => __('Some changes were made. Close anyway', 'creation-reservoir'),
            'txt_delete_project'            => __('Would you delete this project', 'creation-reservoir'),
            'txt_error_deleting_project'    => __('Error while deleting this project', 'creation-reservoir'),
            'generate_purchase'             => __('Would you generate purchase request ?', 'creation-reservoir'),
            'confirm_delete_purchase'       => __('Would you really delete this purchase ?', 'creation-reservoir'),
            'continue'                      => __('Continue', 'creation-reservoir'),
            'warning_nb_welding'            => __('Warning: The software cannot automatically add more than %d welds!', 'creation-reservoir'),
            'warning_tipping_height'        => __('Warning: The tipping height (%d mm) exceeds or is too close to the room height!', 'creation-reservoir'),
            'warning_nb_welding_number'     => __('Warning: Incorrect number of welds for %d section(s) (Recommended: %d welds)', 'creation-reservoir'),
            'convert'                       => __('Convert', 'creation-reservoir'),
            'modal_missing_fields_warning'  => __('The Diameter, Volume, and Height fields are mandatory. Do you want to leave without filling them in?', 'creation-reservoir'),
            'would_you_convert'             => __('Would you convert this project into an order ?', 'creation-reservoir'),
            'need_order_number'             => __('Please note: The ISPAG order number and customer reference are mandatory prior to conversion.', 'creation-reservoir'),
            'transform_to_project'          => __('Transform to project', 'creation-reservoir'),
            'new_article'                   => __('New article', 'creation-reservoir'),
            'later'                         => __('Later', 'creation-reservoir'),
            'start_analyse'                 => __('Start analyses', 'creation-reservoir'),
            'ai_start_analyses'             => __('The AI will analyze the document in the background. You can continue navigating the CRM; a push notification will inform you once processing is complete.', 'creation-reservoir'),
            'pending_ai_analyse'            => __('AI Processing', 'creation-reservoir'),
            'generating'                    => __('Generating', 'creation-reservoir'),
            'tank_sketch'                   => __('Sketch', 'creation-reservoir'),
            'tank'                          => __('Tank', 'creation-reservoir'),
            'download'                      => __('Download', 'creation-reservoir'),
            'archive'                       => __('Archive', 'creation-reservoir'),
            'unarchive'                     => __('Unarchive', 'creation-reservoir'),
            'calculation_progress'          => __('Calculation in progress', 'creation-reservoir'),
            'error'                         => __('Error', 'creation-reservoir'),
            'invlaid_server_response'       => __('Invalid server response.', 'creation-reservoir'),
            'net_price_missing'             => __('Net price missing from the response.', 'creation-reservoir'),
            'calculation_error'             => __('Calculation errors', 'creation-reservoir'),
            'critical_error'                => __('Critical error', 'creation-reservoir'),
            'unable_generate_report'        => __('Unable to generate the report: missing data.', 'creation-reservoir'),
            'report_generation_progress'    => __('Report generation in progress', 'creation-reservoir'),
            'display_preice_calculation'    => __('Display price calculation', 'creation-reservoir'),
            'add_subscriber'                => __('Add subscriber', 'creation-reservoir'),
            'modification_detected'         => __('Modification detected', 'creation-reservoir'),
            'modification_reason'           => __('Reason for the modification', 'creation-reservoir'),
            'modification_reason_exemple'   => __('Correction following the supplier\'s feedback...', 'creation-reservoir'),
            'modification_pls_enter_reason' => __('Please enter a reason before confirming.', 'creation-reservoir'),
            'you_are_modifying'             => __('you are modifying', 'creation-reservoir'),
            'loading'                       => __('Loading', 'creation-reservoir'),
        ]); 

        // self::$logger->log_user_action('detail_page', 'scripts_and_localizations_complete', [], $user_id);
    }

    public static function ajax_update_associations()
    {
        $user_id = get_current_user_id();
        self::$logger->log_user_action('detail_page', 'ajax_update_associations_start', [], $user_id);

        check_ajax_referer('ispag_nonce', 'nonce');
        global $wpdb;

        $deal_id = sanitize_text_field($_POST['deal_id']);
        $type = sanitize_text_field($_POST['type']);
        $value = sanitize_text_field($_POST['value']);

        self::$logger->log_user_action('detail_page', 'association_params_received', ['deal_id' => $deal_id, 'type' => $type, 'value' => $value], $user_id);

        $table = $wpdb->prefix . 'achats_liste_commande';

        $column = match ($type)
        {
            'company' => 'AssociatedCompanyID',
            'contact' => 'AssociatedContactIDs',
            'abonne' => 'Abonne',
            default => null,
        };

        if (!$column)
        {
            self::$logger->log('detail_page', 'ERROR: Invalid type - ' . $type, $user_id);
            wp_send_json_error(['message' => 'Type invalide']);
        }

        self::$logger->log_user_action('detail_page', 'column_resolved', ['type' => $type, 'column' => $column], $user_id);

        if ($type === 'abonne')
        {
            $ids = array_filter(array_map('trim', explode(';', $value)));
            $value = ';' . implode(';', $ids) . ';';
            self::$logger->log_user_action('detail_page', 'abonne_value_formatted', ['original' => $_POST['value'], 'formatted' => $value], $user_id);
        }

        $updated = $wpdb->update(
            $table,
            [$column => $value],
            ['hubspot_deal_id' => $deal_id],
            ['%s'],
            ['%d']
        );

        self::$logger->log_db_change('detail_page', $table, 'UPDATE_ASSOCIATION', ['column' => $column, 'value' => $value, 'deal_id' => $deal_id, 'result' => $updated], $user_id);

        if ($updated !== false)
        {
            self::$logger->log_user_action('detail_page', 'association_updated_successfully', [], $user_id);
            wp_send_json_success(['message' => 'Update successful']);
        }
        else
        {
            $error = $wpdb->last_error;
            self::$logger->log('detail_page', 'ERROR: Update failed - ' . $error, $user_id);
            wp_send_json_error(['message' => 'Error during update']);
        }
    }

    public static function ajax_reload_article_list()
    {
        $user_id = get_current_user_id();
        $t0 = microtime(true);
        self::$logger->log_user_action('detail_page', 'ajax_reload_article_list_start', [], $user_id);

        $deal_id = intval($_POST['deal_id'] ?? 0);
        $isQotation = isset($_POST['isQotation']) ? boolval($_POST['isQotation']) : null;

        self::$logger->log_user_action('detail_page', 'reload_params_received', ['deal_id' => $deal_id, 'isQotation' => $isQotation], $user_id);

        echo apply_filters('ispag_reload_article_list', $deal_id, $isQotation);
        self::$logger->log_user_action('detail_page', 'reload_article_list_filter_applied', [], $user_id);
        self::$logger->timing('detail_page', 'ajax_reload_article_list (deal ' . $deal_id . ')', $t0, $user_id);
        wp_die();
    }

    // public static function reload_article_list($deal_id, $isQotation = null)
    // {
    //     $user_id = get_current_user_id();
    //     self::$logger->log_user_action('detail_page', 'reload_article_list_start', ['deal_id' => $deal_id, 'isQotation' => $isQotation], $user_id);

    //     $can_manage_order = current_user_can('manage_order');
    //     $articles = new ISPAG_Article_Repository();
    //     $repo_project = new ISPAG_Projet_Repository();
    //     $project = $repo_project->get_project_by_deal_id(null, $deal_id);

        

    //     if (current_user_can('navigate_new_project_details_presentation')) {
    //         $articles_list = $articles->get_optimised_articles_by_deal($deal_id);
    //         $archived_articles = $articles->get_optimised_articles_by_deal($deal_id, true);
    //     }
    //     else{
    //         $articles_list = $articles->get_articles_by_deal($deal_id);
    //         $archived_articles = $articles->get_articles_by_deal($deal_id, true);
    //     }
    //     self::$logger->log_db_change('detail_page', 'articles', 'FETCH_BY_DEAL', ['deal_id' => $deal_id, 'count' => count($articles_list)], $user_id);

    //     $return = self::render_articles_list($articles_list, $project, false);

    //     // Vérifier si l'utilisateur peut gérer les commandes ET s'il existe des articles archivés
        
    //     if ($can_manage_order && !empty($archived_articles)) {
    //         $return .= '<h3>' . __('Archived articles', 'creation-reservoir') . '</h3>';
    //         $return .= '<small class="creator-name">' . __('not included in total sales', 'creation-reservoir') . '</small>';
    //         $return .= self::render_articles_list($archived_articles, $project, true);
    //     }

    //     return $return;
    // }
    public static function reload_article_list($deal_id, $isQotation = null)
    {
        $user_id = get_current_user_id();
        self::$logger->log_user_action('detail_page', 'reload_article_list_start', ['deal_id' => $deal_id, 'isQotation' => $isQotation], $user_id);

        $can_manage_order = current_user_can('manage_order');
        $articles_repo = new ISPAG_Article_Repository();
        $repo_project = new ISPAG_Projet_Repository();
        $project = $repo_project->get_project_by_deal_id(null, $deal_id);

        // Un seul appel pour récupérer actifs et archivés déjà groupés !
        $all_articles = $articles_repo->get_all_articles_by_deal_grouped($deal_id);
        
        $articles_list = $all_articles['active'];
        $archived_articles = $all_articles['archived'];

        self::$logger->log_db_change('detail_page', 'articles', 'FETCH_BY_DEAL', ['deal_id' => $deal_id, 'count' => count($articles_list)], $user_id);

        // Rendu des articles actifs
        $return = self::render_articles_list($articles_list, $project, false);

        // Rendu des articles archivés si nécessaire
        if ($can_manage_order && !empty($archived_articles)) {
            $return .= '<h3>' . __('Archived articles', 'creation-reservoir') . '</h3>';
            $return .= '<small class="creator-name">' . __('not included in total sales', 'creation-reservoir') . '</small>';
            $return .= self::render_articles_list($archived_articles, $project, true);
        }

        return $return;
    }

    public static function ajax_update_project_title()
    {
        $user_id = get_current_user_id();
        self::$logger->log_user_action('detail_page', 'ajax_update_project_title_start', [], $user_id);

        check_ajax_referer('ispag_nonce', 'nonce');

        if (!current_user_can('edit_posts'))
        {
            self::$logger->log('detail_page', 'ERROR: User cannot edit posts', $user_id);
            wp_send_json_error('Permission denied');
        }

        $project_id = intval($_POST['project_id']);
        $new_title = sanitize_text_field($_POST['new_title']);

        self::$logger->log_user_action('detail_page', 'title_update_params_received', ['project_id' => $project_id, 'new_title' => $new_title], $user_id);

        if (empty(trim($new_title)))
        {
            self::$logger->log('detail_page', 'ERROR: Empty title', $user_id);
            wp_send_json_error('The title cannot be empty');
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'achats_liste_commande';

        $updated = $wpdb->update(
            $table_name,
            ['ObjetCommande' => $new_title],
            ['hubspot_deal_id' => $project_id],
            ['%s'],
            ['%d']
        );

        self::$logger->log_db_change('detail_page', $table_name, 'UPDATE_TITLE', ['project_id' => $project_id, 'new_title' => $new_title, 'result' => $updated], $user_id);

        if ($updated !== false)
        {
            self::$logger->log_user_action('detail_page', 'title_updated_successfully', [], $user_id);
            wp_send_json_success('Title updated');
        }
        else
        {
            $error = $wpdb->last_error;
            self::$logger->log('detail_page', 'ERROR: Title update failed - ' . $error, $user_id);
            wp_send_json_error('DB error');
        }
    }

    public static function render($atts)
    {
        // 1. Récupération de l'URL exacte en cours (avec les arguments de requête s'il y en a)
        global $wp;
        // Reconstruction propre de l'URL
        $current_url = home_url( add_query_arg( $_GET, $wp->request ) );

        // 1. Redirection si non connecté
        if ( ! is_user_logged_in() ) {
            $login_url = wp_login_url( $current_url );
            
            // Si les en-têtes PHP ne sont pas encore envoyés
            if ( ! headers_sent() ) {
                wp_safe_redirect( $login_url );
                exit;
            }
            
            // Fallback JavaScript si le HTML a déjà commencé à s'afficher
            return '<script type="text/javascript">window.location.href = "' . esc_url_raw( $login_url ) . '";</script>';
        }

        $user_id = get_current_user_id();
        self::$logger->log_user_action('detail_page', 'render_start', [], $user_id);

        if (!current_user_can('read_orders'))
        {
            self::$logger->log('detail_page', 'ERROR: User cannot read orders', $user_id);
            return '<div class="ispag-alert ispag-alert-danger">
                        <i class="dashicons dashicons-lock"></i>
                        <strong>' . esc_html__('Restricted access', 'ispag-crm') . ' :</strong> ' .
                        esc_html__('You do not have the necessary rights to view this order.', 'ispag-crm') . '<br/>
                        <a href="' . wp_login_url( get_permalink() ) . '">' . esc_html__('To login page', 'ispag-crm') . '</a>
                    </div>';
        }

        $deal_id = get_query_var('deal_id') ?: ($_GET['deal_id'] ?? null);
        if (!$deal_id)
        {
            self::$logger->log('detail_page', 'ERROR: Missing deal_id', $user_id);
            return '<div class="ispag-alert ispag-alert-danger">
                        <i class="dashicons dashicons-lock"></i>
                        ' . __('Project not found. Deal ID is missing', 'creation-reservoir') . '<br>
                         <a href="' . home_url() . '">' . esc_html__('To home', 'ispag-crm') . '</a>
                    </div>';
        }

        self::$logger->log_user_action('detail_page', 'deal_id_received', ['deal_id' => $deal_id], $user_id);

        $project_detail = new ISPAG_Projet_Repository();
        $details = apply_filters('ispag_get_project_by_deal_id', null, $deal_id);

        if (!$details)
        {
            self::$logger->log('detail_page', 'ERROR: Project details not found', $user_id);
            return '<div class="ispag-alert ispag-alert-danger">
                        <i class="dashicons dashicons-lock"></i>
                        ' . __('Unable to load project details.', 'creation-reservoir'). '<br>
                         <a href="' . home_url() . '">' . esc_html__('To home', 'ispag-crm') . '</a>
                    </div>';
        }

        self::$logger->log_db_change('detail_page', 'projects', 'FETCH_PROJECT', ['deal_id' => $deal_id], $user_id);

        $isQotation = $details->isQotation ?? false;
        do_action('isag_run_auto_update', $deal_id);
        self::$logger->log_user_action('detail_page', 'auto_update_triggered', ['deal_id' => $deal_id], $user_id);

        $can_manage_order = current_user_can('manage_order');
        $can_view_prices = current_user_can('display_sales_prices');
        $title = esc_html(stripslashes($details->ObjetCommande ?? 'Projet sans titre'));

        $notes_list_full = '<p>' . __('No registered activity', 'ispag-crm') . '</p>';
        $deal = null;

        if ($can_manage_order && class_exists('ISPAG_Note_Manager'))
        {
            $note_repository = new ISPAG_Note_Repository();
            $note_renderer = new ISPAG_Note_Renderer();
            $deal_repo = new ISPAG_Crm_Deals_Repository();

            $deal = $deal_repo->get_project_by_project_num($details->NumCommande);
            self::$logger->log_db_change('detail_page', 'deals', 'FETCH_DEAL', ['project_num' => $details->NumCommande], $user_id);

            $all_deal_identifiers = [];
            if (!empty($deal_id))
            {
                $all_deal_identifiers[] = $deal_id;
            }
            if ($deal && isset($deal->deal_group_ref))
            {
                $all_deal_identifiers[] = $deal->deal_group_ref;
            }

            if (!empty($all_deal_identifiers))
            {
                $activity_detail = $note_repository->get_activities_for_entity('deal', $all_deal_identifiers);
                $notes_list_full = $note_renderer->render_activities_list($activity_detail);
                self::$logger->log_user_action('detail_page', 'activities_rendered', ['count' => count($activity_detail)], $user_id);
            }
            else
            {
                $notes_list_full = "No activity found.";
                self::$logger->log_user_action('detail_page', 'no_activities_found', [], $user_id);
            }
        }

        ob_start();
        ?>

        <div id="ispag_project_contact_datas" class="ispag-achat-header">
            <?php if ($can_manage_order): ?>
                <h2 id="editable-project-title"
                    class="ispag-editable-title"
                    contenteditable="true"
                    spellcheck="false"
                    data-name="ObjetCommande"
                    data-value="<?php echo $title; ?>"
                    data-project-id="<?php echo esc_attr($deal_id); ?>"
                    data-deal="<?php echo esc_attr($deal_id); ?>"
                    data-source="project"
                    data-is-quotation="<?php echo $isQotation; ?>"
                    style="margin-top:0; font-size:1.8rem; border-bottom: 1px dashed transparent; cursor: pointer;">
                    <?php echo $title; ?>
                </h2>
            <?php else: ?>
                <h2 style="margin-top:0; font-size:1.8rem;"><?php echo $title; ?></h2>
            <?php endif; ?>

            <?php echo self::display_project_contact_datas($details); ?>
        </div>

        <?php if ($can_manage_order && isset($details->purchase_url)): ?>
            <a href="<?= esc_url($details->purchase_url) ?>" target="_blank" class="ispag-btn ispag-btn-secondary-outlined"><?= esc_html(__('To purchase', 'creation-reservoir')) ?></a>
        <?php endif; ?>

        <?php echo ISPAG_Publishable_Projects::render_toggle($deal_id); // visible uniquement avec le droit export_publishable_projects ?>

        <?php if ($can_view_prices): ?>
            <button id="ispag-force-show-prices" class="button button-primary">
                👁️ <?php _e('Force price display', 'creation-reservoir'); ?>
            </button>
            <button id="ispag-force-hide-prices" class="button button-secondary">
                🔒 <?php _e('Hide prices', 'creation-reservoir'); ?>
            </button>
        <?php endif; ?>

        <p>&nbsp;</p>

        <div class="ispag-tabs">
            <ul class="tab-titles">
                <li class="active" data-tab="postes"><?php echo __('Articles', 'creation-reservoir'); ?></li>
                <?php if ($can_manage_order): ?>
                    <li data-tab="activities"><?php esc_html_e('Activities', 'ispag-crm'); ?></li>
                <?php endif; ?>
                <li data-tab="details"><?php echo __('Details', 'creation-reservoir'); ?></li>
                <li data-tab="suivi"><?php echo __('Follow up', 'creation-reservoir'); ?></li>
                <li data-tab="docs"><?php echo __('Document flow', 'creation-reservoir'); ?></li>
            </ul>

            <?php $renderer = new ISPAG_Project_views_Renderer(); ?>
            <div class="tab-content active" id="postes">
                <?php $renderer->render_project_stat($deal_id, $can_manage_order && $can_view_prices); ?>
                <?php $renderer->display_ispag_project_articles($deal_id, $isQotation); ?>
                <?php echo $renderer->render_project_action_button($deal_id, $isQotation); ?>
                <?php echo $renderer->bulk_selected_article($deal_id, $isQotation); ?>
            </div>

            <?php if ($can_manage_order): // onglet Activités : réservé à manage_order (contenu non rendu sinon) ?>
            <div class="tab-content" id="activities">
                <div class="ispag-actions-bar">
                <?php
                if ($deal)
                {
                    $user_id_log = get_current_user_id();
                    $company_ids_arr = [];
                    $company_names_arr = [];

                    if (!empty($deal->AssociatedCompanyID) && is_array($deal->AssociatedCompanyID))
                    {
                        foreach ($deal->AssociatedCompanyID as $company_obj)
                        {
                            if (is_object($company_obj) && !empty($company_obj->company_name))
                            {
                                $company_ids_arr[] = $company_obj->Id;
                                $company_names_arr[] = trim(str_replace([",", "\r", "\n"], " ", $company_obj->company_name));
                            }
                        }
                    }

                    $contact_ids_arr = [];
                    $contact_names_arr = [];
                    if (!empty($deal->AssociatedContactIDs) && is_array($deal->AssociatedContactIDs))
                    {
                        foreach ($deal->AssociatedContactIDs as $contact_obj)
                        {
                            if (is_object($contact_obj))
                            {
                                $id = $contact_obj->ID ?? ($contact_obj->Id ?? 0);
                                $contact_ids_arr[] = $id;
                                $contact_names_arr[] = str_replace(',', ' ', $contact_obj->display_name ?? 'Inconnu');
                            }
                        }
                    }

                    $actions = [
                        'user_id' => $user_id_log,
                        'company_ids' => implode(',', $company_ids_arr),
                        'company_names' => implode(',', $company_names_arr),
                        'contact_ids' => implode(',', $contact_ids_arr),
                        'deal_ids' => $deal_id,
                        'deal_names' => $deal->project_name ?? '',
                        'offer_num' => $deal->deal_group_ref ?? '',
                        'project_nums' => $details->NumCommande ?? '',
                        'closing_date' => $deal->closing_date ?? '',
                        'total_excl_vat' => $deal->total_excl_vat ?? 0,
                    ];

                    self::$logger->log_user_action('detail_page', 'action_bar_rendered', ['actions' => $actions], $user_id_log);
                    echo ispag_get_template('action-bar', ['actions' => $actions]);
                }
                else
                {
                    self::$logger->log_user_action('detail_page', 'crm_details_unavailable', [], $user_id);
                    echo '<p>CRM details unavailable.</p>';
                }
                ?>
                </div>
                <?php echo $notes_list_full; ?>
            </div>
            <?php endif; ?>

            <div class="tab-content" id="details"><?php echo $renderer->display_ispag_project_details($deal_id, $details); ?></div>
            <div class="tab-content" id="suivi"><?php display_ispag_suivis($deal_id, $isQotation); ?></div>
            <div class="tab-content" id="docs"><?php echo ISPAG_Document_Manager::display_ispag_doc_manger($deal_id); ?></div>
        </div>

        <?php
        // echo "<script>document.title = '" . esc_js($title) . "';</script>";
        echo "<script>
            document.addEventListener('DOMContentLoaded', function() {
                // Récupère le titre actuel de la page (ex: 'Mon Compte - Mon Site')
                var originalTitle = document.title;
                var refCommande = '" . esc_js($title) . "';
                
                // Concatène selon vos préférences
                document.title = refCommande + ' - ' + originalTitle;
            });
        </script>";
        // echo self::display_modal();
        // echo self::display_doc_analyser_modal();
        self::$logger->log_user_action('detail_page', 'render_complete', [], $user_id);
        return ob_get_clean();
    }

    private static function display_project_contact_datas($details)
    {
        $user_id = get_current_user_id();
        self::$logger->log_user_action('detail_page', 'display_project_contact_datas_start', [], $user_id);

        if (!is_user_logged_in() || !current_user_can('manage_order'))
        {
            self::$logger->log('detail_page', 'ERROR: User cannot manage order or not logged in', $user_id);
            return '';
        }

        $base_url = get_home_url();
        $contact_ids = explode(',', $details->AssociatedContactIDs);
        $first_contact_id = trim($contact_ids[0]);
        $company_ids = explode(',', $details->AssociatedCompanyID);
        $first_company_id = trim($company_ids[0]);

        $contact_link = trailingslashit($base_url . '/contact/' . $first_contact_id);
        $company_link = trailingslashit($base_url . '/company/' . $first_company_id);

        $abonne_ids = !empty($details->Abonne) ? array_filter(array_map('trim', explode(';', $details->Abonne))) : [];
        self::$logger->log_user_action('detail_page', 'abonne_ids_parsed', ['count' => count($abonne_ids)], $user_id);

        ob_start();
        ?>
        <div class="achat-meta" style="display:grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap:1rem; margin-top:1rem; padding: 15px; background: #fdfdfd; border: 1px solid #eee; border-radius: var(--ispag-badge-border-radius);">

            <div class="edit-group">
                <div style="display:flex; justify-content:space-between;">
                    <strong><?php _e('Company', 'creation-reservoir'); ?></strong>
                    <?php if ($first_company_id !== ''): ?>
                        <a href="<?= esc_url($company_link) ?>" class="ispag-link" title="<?php esc_attr_e('Open company page', 'creation-reservoir'); ?>">
                            <?= esc_html($details->nom_entreprise) ?> <i class="fas fa-external-link-alt"></i>
                        </a>
                    <?php endif; ?>
                </div>
                <select id="edit-project-company" class="ispag-select2-ajax" data-type="company" style="width:100%;">
                    <option value="<?= $details->AssociatedCompanyID ?>" selected>
                        <?= esc_html($details->nom_entreprise) ?> (<?= esc_html($details->company_city) ?>)
                    </option>
                </select>
            </div>

            <div class="edit-group">
                <div style="display:flex; justify-content:space-between;">
                    <strong><?php _e('Contact', 'creation-reservoir'); ?></strong>
                    <a href="<?= $contact_link ?>" target="_blank" style="font-size:12px; opacity:0.7;">🔗 Voir</a>
                </div>
                <select id="edit-project-contact" class="ispag-select2-ajax" data-type="contact" style="width:100%;">
                    <option value="<?= $details->AssociatedContactIDs ?>" selected>
                        <?= esc_html($details->contact_name) ?>
                    </option>
                </select>
            </div>

            <?php if (current_user_can('administrator')): ?>
                <div class="edit-group">
                    <div style="display:flex; justify-content:space-between;">
                        <strong><?php _e('Subscribers', 'creation-reservoir'); ?></strong>
                    </div>
                    <select id="edit-project-abonnes" class="ispag-select2-ajax" data-type="abonne" multiple="multiple" style="width:100%;">
                        <?php if (!empty($abonne_ids)): ?>
                            <?php foreach ($abonne_ids as $id): ?>
                                <option value="<?= esc_attr(trim($id)) ?>" selected>
                                    <?= esc_html(self::get_contact_name_by_id(trim($id))) ?>
                                </option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </select>
                </div>
            <?php endif; ?>

        </div>

        <input type="hidden" id="details-project-deal-id" value="<?= esc_attr($details->hubspot_deal_id) ?>">
        <input type="hidden" id="details-project-abonnes" value="<?= esc_attr($details->Abonne ?? '') ?>">

        <style>
            .edit-group { background: white; padding: 10px; border-radius: 5px; border: 1px solid #eee; }
            .edit-group strong { display:block; margin-bottom:5px; font-size: 13px; color: #666; }
            .select2-container--default .select2-selection--single { border-color: #ddd !important; height: 38px !important; line-height: 38px !important; }
            .select2-container--default .select2-selection--multiple { border-color: #ddd !important; min-height: 38px !important; }
        </style>
        <?php
        self::$logger->log_user_action('detail_page', 'display_project_contact_datas_complete', [], $user_id);
        return ob_get_clean();
    }

    private static function get_contact_name_by_id($id)
    {
        $user_id = get_current_user_id();
        self::$logger = ISPAG_Logger::get_instance();

        $user = get_user_by('ID', $id);
        if ($user)
        {
            $name = $user->display_name . ' (' . $user->user_email . ')';
            self::$logger->log_user_action('detail_page', 'contact_name_resolved', ['id' => $id, 'name' => $name], $user_id);
            return $name;
        }

        self::$logger->log('detail_page', 'ERROR: Contact not found - ' . $id, $user_id);
        return $id;
    }

    public static function render_articles_list($grouped_articles, $project, $is_archive = false)
    {
        $user_id = get_current_user_id();
        self::$logger->log_user_action('detail_page', 'render_articles_list_start', [], $user_id);

        ob_start();
        echo '<div class="ispag-articles-list' . ($is_archive ? ' ispag-list-archived' : '') . '">';

        foreach ($grouped_articles as $groupe => $articles_principaux)
        {
            $escaped_group = esc_html(stripslashes($groupe));
            $id = 'group-title-' . md5($groupe);

            self::$logger->log_user_action('detail_page', 'rendering_group', ['group' => $escaped_group, 'article_count' => count($articles_principaux)], $user_id);

            $group_total = 0;
            foreach ($articles_principaux as $g_article) {
                $group_total += (float) ($g_article->prix_net_calculé ?? 0) * (int) $g_article->Qty;
            }

            echo '<div class="ispag-article-group-wrapper">';
            echo '<div class="ispag-article-group-header">';
            echo '<button type="button" class="ispag-group-toggle" aria-expanded="true" title="' . esc_attr__('Collapse / expand', 'creation-reservoir') . '"><i class="fas fa-chevron-down"></i></button>';
            echo '<h3
                    id="' . esc_attr($id) . '"
                    class="ispag-editable-title"
                    contenteditable="true"
                    spellcheck="false"
                    data-value="' . $escaped_group . '"
                    data-deal-id="'. $project->deal_id . '"
                    style="margin:0; border-bottom: 1px dashed transparent; cursor: pointer;"
                    >' . $escaped_group . '</h3>';
            echo '<span class="ispag-group-count">' . count($articles_principaux) . '</span>';
            if (current_user_can('display_sales_prices')) {
                echo '<span class="ispag-group-total">' . number_format($group_total, 2, '.', ' ') . ' ' . esc_html(get_option('wpcb_currency')) . '</span>';
            }
            echo '<button type="button" class="ispag-btn-copy-group" data-target="' . esc_attr($id) . '">📋</button>';
            echo '</div>';
            echo '<div class="ispag-article-card-container">';

            foreach ($articles_principaux as $article)
            {
                // Statut archivé de l'article
                $article_is_archived = $is_archive || (!empty($article->archive) && $article->archive == 1);
                
                // Transmettez l'information à render_article_block (ajustez selon votre signature de fonction)
                self::render_article_block($article, $project, false, $article_is_archived);
                
                self::$logger->log_user_action('detail_page', 'rendering_article', ['article_id' => $article->Id], $user_id);

                if (current_user_can('manage_order') && !empty($article->secondaires))
                {
                    foreach ($article->secondaires as $secondaire)
                    {
                        $secondary_is_archived = $is_archive || (!empty($secondaire->archive) && $secondaire->archive == 1);
                        self::render_article_block($secondaire, $project, true, $secondary_is_archived);
                        self::$logger->log_user_action('detail_page', 'rendering_secondary_article', ['article_id' => $secondaire->Id], $user_id);
                    }
                }
            }
            echo '</div></div>'; // .ispag-article-card-container + .ispag-article-group-wrapper
        }

        echo '</div>';
        ?>
        <script>
        document.querySelectorAll('.ispag-btn-copy-group').forEach(btn => {
            btn.addEventListener('click', () => {
                const targetId = btn.getAttribute('data-target');
                const text = document.getElementById(targetId)?.innerText;
                if (text) {
                    navigator.clipboard.writeText(text).then(() => {
                        btn.innerText = '✅';
                        setTimeout(() => btn.innerText = '📋', 1000);
                    });
                }
            });
        });
        </script>
        <?php
        self::$logger->log_user_action('detail_page', 'render_articles_list_complete', [], $user_id);
        return ob_get_clean();
    }
 
    public static function display_modal()
    {
        $user_id = get_current_user_id();
        self::$logger->log_user_action('detail_page', 'display_modal_rendered', [], $user_id);
 
        echo '<div id="ispag-modal-product" class="ispag-product-modal" style="display:none;">
            <div class="ispag-modal-content">
                <div class="ispag-modal-header">
                    <div id="ispag-modal-header-content">
                        <h2>&nbsp;</h2>
                    </div>
                    <span class="ispag-modal-close ispag-btn ispag-btn-red-outlined ispag-close-croix">&times;</span>
                </div>
                
                <div id="ispag-product-modal-content">
                    <div id="ispag-modal-body" class="ispag-modal-body-scroll">
                        <!-- Contenu long ici -->
                    </div>
                </div>
                <div class="ispag-modal-footer">
                    <!-- Boutons ici -->
                </div>
            </div>
        </div>
        ' . apply_filters('ispag_get_modal_fitting', '');
    }

    public static function display_doc_analyser_modal()
    {
        $user_id = get_current_user_id();
        self::$logger->log_user_action('detail_page', 'display_doc_analyser_modal_rendered', [], $user_id);

        return '
        <div id="ispag-analysis-modal" class="ispag-modal-overlay">
            <div class="ispag-modal-content">
                <div class="ispag-modal-header">
                    <h4>' . __('ISPAG Compliance Analysis', 'creation-reservoir') .'</h4>
                    <span class="ispag-close-modal ispag-btn ispag-btn-red-outlined ispag-close-croix">&times;</span>
                </div>

                <div id="ispag-analysis-modal-body" class="ispag-modal-body">
                </div>

                <div class="ispag-modal-footer" style="text-align:right; border-top:1px solid #eee;">
                    <button type="button" class="button ispag-close-modal ispag-close-croix">' . __('Close', 'creation-reservoir') .'</button>
                </div>
            </div>
        </div>';
    }

    // public static function render_article_block($article, $project, $is_secondary = false, $is_archived = false)
    // {
    //     $user_id = get_current_user_id();
    //     $id = (int) $article->Id;
    //     $checked_attr = '';
    //     $titre = esc_html($article->Article);
    //     $deal_id = $article->hubspot_deal_id;
    //     $status = self::getDeliveryStatus($article);
    //     $facture = $article->invoiced ? __('Invoiced', 'creation-reservoir') : __('Not invoiced', 'creation-reservoir');
    //     $qty = (int) $article->Qty;
    //     $prix_brut = number_format((float) $article->prix_total_calculé, 2, '.', ' ');
    //     $rabais = number_format((float) $article->discount, 2, '.', ' ');
    //     $prix_net = number_format((float) $article->prix_net_calculé, 2, '.', ' ');
    //     $user_can_manage_order = current_user_can('manage_order');
    //     $user_is_owner = $project->is_project_owner;
    //     $user_can_generate_tank = current_user_can('generate_tank');

    //     $class_secondary = $is_secondary ? ' ispag-article-secondary' : '';
    //     $class_archived = $is_archived ? ' ispag-archived' : '';

    //     self::$logger->log_user_action('detail_page', 'render_article_block_start', ['article_id' => $id, 'is_secondary' => $is_secondary], $user_id);

    //     if (current_user_can('manage_order') || $article->customer_visible)
    //     {
    //         include plugin_dir_path(__FILE__) . 'templates/render-article-block.php';
    //         self::$logger->log_user_action('detail_page', 'article_block_rendered', ['article_id' => $id], $user_id);
    //     }
    // }
    public static function render_article_block($article, $project, $is_secondary = false, $is_archived = false)
    {
        if (!current_user_can('manage_order') && !$article->customer_visible) {
            return;
        }

        $user_id = get_current_user_id();
        $id = (int) $article->Id;
        $checked_attr = '';
        $titre = esc_html($article->Article);
        $deal_id = $article->hubspot_deal_id;
        $status = self::getDeliveryStatus($article);
        $facture = $article->invoiced ? __('Invoiced', 'creation-reservoir') : __('Not invoiced', 'creation-reservoir');
        $qty = (int) $article->Qty;
        $prix_brut = number_format((float) $article->prix_total_calculé, 2, '.', ' ');
        $rabais = number_format((float) $article->discount, 2, '.', ' ');
        $prix_net = number_format((float) $article->prix_net_calculé, 2, '.', ' ');
        $user_can_manage_order = current_user_can('manage_order');
        $user_is_owner = $project->is_project_owner ?? ISPAG_Projet_Repository::is_user_project_owner($deal_id);
        $user_can_generate_tank = current_user_can('generate_tank');

        $class_secondary = $is_secondary ? ' ispag-article-secondary' : '';
        $class_archived = $is_archived ? ' ispag-archived' : '';

        // Préparation des variables pour le bouton d'archive (évite les warnings PHP dans le template)
        $button_class = $is_archived ? 'ispag-btn-unarchive' : 'ispag-btn-archive';
        $button_text  = $is_archived ? __('Unarchive', 'creation-reservoir') : __('Archive', 'creation-reservoir');
        $button_icon  = $is_archived ? '<i class="fas fa-undo"></i>' : '<i class="fas fa-archive"></i>';

        self::$logger->log_user_action('detail_page', 'render_article_block_start', ['article_id' => $id, 'is_secondary' => $is_secondary], $user_id);

        include plugin_dir_path(__FILE__) . 'templates/render-article-block.php';

        self::$logger->log_user_action('detail_page', 'article_block_rendered', ['article_id' => $id], $user_id);
    }

    private static function getDeliveryStatus($article)
    {
        $user_id = get_current_user_id();
        self::$logger->log_user_action('detail_page', 'getDeliveryStatus_start', ['article_id' => $article->Id], $user_id);

        $status = '';
        $today = strtotime('today');
        $is_delivered = $article->Livre;
        $delivery_date = intval($article->TimestampDateDeLivraison);
        $type_article = intval($article->Type);
        $is_validated = $article->DrawingApproved ?? false;

        self::$logger->log_user_action('detail_page', 'delivery_status_params', ['is_delivered' => $is_delivered, 'delivery_date' => $delivery_date, 'type_article' => $type_article, 'is_validated' => $is_validated], $user_id);

        if ($is_delivered)
        {
            $status = __('Delivered', 'creation-reservoir');
            self::$logger->log_user_action('detail_page', 'delivery_status_delivered', ['article_id' => $article->Id], $user_id);
        }
        elseif ($delivery_date >= $today)
        {
            $status = __('In delivery', 'creation-reservoir');
            self::$logger->log_user_action('detail_page', 'delivery_status_in_delivery', ['article_id' => $article->Id], $user_id);
        }
        elseif ($type_article === 1)
        {
            if (isset($article->last_doc_type['slug']) && $article->last_doc_type['slug'] == 'drawingApproval')
            {
                $status = __('Waiting drawing modification', 'creation-reservoir');
                self::$logger->log_user_action('detail_page', 'delivery_status_waiting_modification', ['article_id' => $article->Id], $user_id);
            }
            elseif (!$is_validated)
            {
                $status = __('Waiting drawing approval', 'creation-reservoir');
                self::$logger->log_user_action('detail_page', 'delivery_status_waiting_approval', ['article_id' => $article->Id], $user_id);
            }
            else
            {
                $status = __('In production', 'creation-reservoir');
                self::$logger->log_user_action('detail_page', 'delivery_status_in_production', ['article_id' => $article->Id], $user_id);
            }
        }
        else
        {
            if ($delivery_date < $today)
            {
                $status = __('To be delivered', 'creation-reservoir');
                self::$logger->log_user_action('detail_page', 'delivery_status_to_be_delivered', ['article_id' => $article->Id], $user_id);
            }
            else
            {
                $status = __('Waiting', 'creation-reservoir');
                self::$logger->log_user_action('detail_page', 'delivery_status_waiting', ['article_id' => $article->Id], $user_id);
            }
        }

        return $status;
    }

    /**
     * À la transformation d'une offre en commande : TOUTES les demandes d'achat du projet sont converties.
     *
     *  1. les articles qui n'ont encore aucune demande d'achat en reçoivent une (génération, une seule fois) ;
     *  2. chaque demande d'achat du projet (table des commandes fournisseurs, quel que soit son état actuel)
     *     reçoit : le nom de la commande (KST/n° de commande - projet), le premier état « commande » (réglage
     *     wpcb_first_order_state) et la date de commande du jour.
     *
     * L'ancienne boucle passait par ispag_update_status, dont la réponse JSON mettait fin à la requête dès la
     * première demande traitée : les autres restaient inchangées. Ici les mises à jour se font sans réponse JSON.
     */
    private static function convert_purchase_requests_to_orders($deal_id, $project, $user_id)
    {
        global $wpdb;
        $deal_id = (int) $deal_id;
        $orders_table = $wpdb->prefix . 'achats_commande_liste_fournisseurs';
        $lines_table  = $wpdb->prefix . 'achats_articles_cmd_fournisseurs';
        $articles_table = $wpdb->prefix . 'achats_details_commande';

        // 1. Articles du projet sans aucune ligne d'achat : on génère les demandes manquantes (une seule fois)
        $missing = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$articles_table} a
             WHERE a.hubspot_deal_id = %d AND (a.archive IS NULL OR a.archive = 0)
               AND NOT EXISTS (SELECT 1 FROM {$lines_table} l WHERE l.IdCommandeClient = a.Id)",
            $deal_id
        ));
        if ($missing > 0)
        {
            self::$logger->log_user_action('detail_page', 'generating_missing_purchase_requests', ['deal_id' => $deal_id, 'missing' => $missing], $user_id);
            do_action('ispag_generate_purchase_requests', null, $deal_id);
        }

        // 2. Toutes les demandes d'achat du projet : nom, statut, date de commande
        $etat_id = (int) get_option('wpcb_first_order_state');
        $ref     = get_option('wpcb_kst') . '/' . ($project->NumCommande ?? '') . ' - ' . ($project->ObjetCommande ?? '');
        $slug    = $etat_id ? $wpdb->get_var($wpdb->prepare(
            "SELECT ClassCss FROM {$wpdb->prefix}achats_etat_commandes_fournisseur WHERE Id = %d", $etat_id
        )) : null;

        $order_ids = $wpdb->get_col($wpdb->prepare("SELECT Id FROM {$orders_table} WHERE hubspot_deal_id = %s", (string) $deal_id));
        self::$logger->log_user_action('detail_page', 'converting_purchase_requests', ['deal_id' => $deal_id, 'orders' => $order_ids, 'etat' => $etat_id], $user_id);

        foreach ($order_ids as $order_id)
        {
            $data = ['RefCommande' => $ref, 'TimestampDateCreation' => time(), 'is_manual' => 0];
            $format = ['%s', '%d', '%d'];
            if ($etat_id)
            {
                $data['EtatCommande'] = $etat_id;
                $format[] = '%d';
            }
            $res = $wpdb->update($orders_table, $data, ['Id' => (int) $order_id], $format, ['%d']);
            self::$logger->log_db_change('detail_page', $orders_table, 'CONVERT_TO_ORDER', ['order_id' => $order_id, 'result' => $res], $user_id);

            if ($etat_id && $res !== false)
            {
                do_action('ispag_save_status_changes', (int) $order_id, $slug, $etat_id); // mêmes suites qu'un changement de statut manuel
            }
        }
    }

    public static function convert_to_project()
    {
        $user_id = get_current_user_id();
        if (!ISPAG_Capabilities::can_manage_project_actions()) {
            wp_send_json_error(['message' => __('You are not allowed', 'creation-reservoir')]);
        }
        self::$logger->log_user_action('detail_page', 'convert_to_project_start', [], $user_id);

        global $wpdb;
        $deal_id = intval($_POST['id']);
        self::$logger->log_user_action('detail_page', 'convert_to_project_params', ['deal_id' => $deal_id], $user_id);

        $updated = $wpdb->update(
            $wpdb->prefix . 'achats_liste_commande',
            [
                'isQotation' => null,
                'TimestampDateCommande' => time(),
                'project_status' => 1
            ],
            ['hubspot_deal_id' => $deal_id],
            ['%s', '%d', '%d'],
            ['%d']
        );

        self::$logger->log_db_change('detail_page', 'achats_liste_commande', 'UPDATE_CONVERT_TO_PROJECT', ['deal_id' => $deal_id, 'result' => $updated], $user_id);

        if ($updated !== false)
        {
            self::$logger->log_user_action('detail_page', 'convert_to_project_db_success', [], $user_id);

            // Étape « Save order » (CmdViag) : faite automatiquement à la transformation de l'offre en commande,
            // ce qui envoie aussi l'e-mail de confirmation au client et la notification Telegram (ISPAG_Project_Phase_Tracker).
            // Faite tout de suite après l'enregistrement de la commande, avant le reste : une erreur plus bas ne doit pas la bloquer.
            try
            {
                $last_cmd_status = $wpdb->get_var($wpdb->prepare(
                    "SELECT status_id FROM {$wpdb->prefix}achats_suivi_phase_commande WHERE hubspot_deal_id = %d AND slug_phase = 'CmdViag' ORDER BY id DESC LIMIT 1",
                    $deal_id
                ));
                if ((int) $last_cmd_status !== 1)
                {
                    $suivi_id = ISPAG_Project_Phase_Tracker::record_status_change($deal_id, 'CmdViag', 1, [
                        'modified_by' => $user_id ?: null,
                        'source'      => ISPAG_Project_Phase_Tracker::SOURCE_AUTOMATIC,
                        'comment'     => 'Offer converted to order',
                    ]);
                    self::$logger->log_user_action('detail_page', 'phase_cmdviag_recorded', ['deal_id' => $deal_id, 'suivi_id' => $suivi_id, 'db_error' => $wpdb->last_error], $user_id);
                }
            }
            catch (Throwable $e)
            {
                self::$logger->log_error('detail_page', 'CmdViag step failed: ' . $e->getMessage(), ['deal_id' => $deal_id], $user_id);
            }

            $articles = apply_filters('ispag_get_articles_by_deal', null, $deal_id);
            $project = apply_filters('ispag_get_project_by_deal_id', null, $deal_id);

            if (!is_array($articles)) { $articles = []; }
            self::$logger->log_user_action('detail_page', 'articles_and_project_fetched', ['articles_count' => count($articles)], $user_id);

            // $table_details = $wpdb->prefix . 'achats_details_commande';
            // $wpdb->update(
            //     $table_details,
            //     ['is_manual_price' => 1],
            //     ['hubspot_deal_id' => $project->Id],
            //     ['%d'],
            //     ['%d']
            // );
            self::fix_sales_price($deal_id);

            

            // Toutes les demandes d'achat du projet passent en commande (nom, statut, date de commande)
            try
            {
                self::convert_purchase_requests_to_orders($deal_id, $project, $user_id);
            }
            catch (Throwable $e)
            {
                self::$logger->log_error('detail_page', 'Purchase requests conversion failed: ' . $e->getMessage(), ['deal_id' => $deal_id], $user_id);
            }

            wp_send_json_success();
        }
        else
        {
            $last_error = $wpdb->last_error;
            self::$logger->log('detail_page', 'ERROR: Convert to project failed - ' . $last_error, $user_id);
            wp_send_json_error(__('Error while updating project', 'creation-reservoir'));
        }
    }

    private static function fix_sales_price($deal_id = null){
        if(empty($deal_id)){
            return;
        }
        global $wpdb;
        $user_id = get_current_user_id();

        $table_details = $wpdb->prefix . 'achats_details_commande';

        $article_repo = new ISPAG_Article_Repository();
        $articles = $article_repo->get_articles_by_deal($deal_id);
        if (current_user_can('navigate_new_project_details_presentation')) {
            $articles = $article_repo->get_optimised_articles_by_deal($deal_id);
            
        }
        else{
            $articles = $article_repo->get_articles_by_deal($deal_id);
            
        }

        // Log des articles récupérés pour le deal
        self::$logger->log_db_change('detail_page', $table_details, 'FETCH_ARTICLES_BY_DEAL', [
            'deal_id' => $deal_id,
            'articles_retrieved' => $articles
        ], $user_id);

        if (empty($articles)) {
            return;
        }

        // On parcourt chaque groupe (ex: la clé "" ou d'autres groupes potentiels)
        foreach ($articles as $group_key => $group_articles) {
            if (!is_array($group_articles)) {
                continue;
            }

            // On parcourt les articles à l'intérieur du groupe
            foreach ($group_articles as $article) {
                // S'assurer qu'on a bien un objet avec un ID valide
                if (empty($article->Id)) {
                    continue;
                }

                $wpdb->update(
                    $table_details,
                    [
                        'is_manual_price' => 1,
                        'sales_price'     => $article->prix_total_calculé
                    ],
                    ['Id' => $article->Id],
                    ['%d', '%f'],
                    ['%d']
                );

                // Log de la mise à jour avec le bon ID et le bon prix
                self::$logger->log_db_change('detail_page', $table_details, 'UPDATE_MANUAL_PRICE', [
                    'article_id'      => $article->Id, 
                    'is_manual_price' => 1,
                    'sales_price'     => $article->prix_total_calculé
                ], $user_id);
            }
        }
    }

    public static function delete_project_btn($html, $deal_id)
    {
        $user_id = get_current_user_id();
        self::$logger->log_user_action('detail_page', 'delete_project_btn_rendered', ['deal_id' => $deal_id], $user_id);

        if (!$deal_id) return $html;

        if(! ISPAG_Capabilities::can_manage_project_actions()) return $html;

        if(! ISPAG_Projet_Repository::get_is_qotation_by_deal_id($deal_id)) return $html;

        $btn = '<button class="ispag-btn ispag-delete-project-btn" data-deal-id="' . esc_attr($deal_id) . '"><span class="dashicons dashicons-trash"></span>'
            . __('Delete project', 'creation-reservoir')
            . '</button>';

        return $html . $btn;
    }

    public static function delete_project()
    {
        $user_id = get_current_user_id();
        self::$logger->log_user_action('detail_page', 'delete_project_start', [], $user_id);

        if (!ISPAG_Capabilities::can_manage_project_actions())
        {
            self::$logger->log('detail_page', 'ERROR: User cannot manage order', $user_id);
            wp_send_json_error(['message' => __('You are not allowed', 'creation-reservoir')]);
        }

        $deal_id = isset($_POST['deal_id']) ? intval($_POST['deal_id']) : 0;
        if (!$deal_id)
        {
            self::$logger->log('detail_page', 'ERROR: Missing deal_id', $user_id);
            wp_send_json_error(['message' => __('Project Id missing', 'creation-reservoir')]);
        }

        self::$logger->log_user_action('detail_page', 'delete_project_validated', ['deal_id' => $deal_id], $user_id);

        do_action('ispag_delete_document_whith_deal_id', null, $deal_id);
        do_action('ispag_delete_articles_whith_deal_id', null, $deal_id);
        do_action('ispag_delete_suivis_whith_deal_id', null, $deal_id);
        do_action('ispag_delete_project_whith_deal_id', null, $deal_id);

        self::$logger->log_user_action('detail_page', 'delete_actions_triggered', ['deal_id' => $deal_id], $user_id);
        wp_send_json_success(['message' => __('Project successfully deleted', 'creation-reservoir')]);
    }


    public static function ispag_notify_admin_quotation_changes() {
        check_ajax_referer('ispag_nonce', '_ajax_nonce');

        $article_id   = intval($_POST['article_id'] ?? 0);
        $deal_id      = intval($_POST['deal_id'] ?? 0);
        $deal_name     = $_POST['deal_name'] ?? 0;
        $change_notes = json_decode(stripslashes($_POST['change_notes'] ?? '[]'), true);

        if (empty($change_notes)) {
            wp_send_json_error(['message' => 'No change to notify.']);
        }

        // 1. Récupérer l'utilisateur actuel (client)
        $current_user = wp_get_current_user();
        $client_name  = $current_user->exists() ? $current_user->display_name : __('Anonymous', 'creation-reservoir');

        // 2. Récupérer les identifiants des utilisateurs destinataires (ex: Admins et Vente/Commercial)
        $recipient_ids = get_users([
            'role__in' => ['administrator'],
            'fields'   => 'ID',
        ]);

        if (empty($recipient_ids)) {
            wp_send_json_error(['message' => 'No recipient found.']);
        }

        // 3. Préparer le titre et le contenu de la notification
        $title = sprintf(
            __('Modifications to Article #%d by %s (Project: %s)', 'creation-reservoir'),
            $article_id,
            $client_name,
            $deal_name
        );

        $content = sprintf(
            __('%s has modified an article (Article #%d, Deal #%s).%sHere are the changes:%s', 'creation-reservoir'),
            $client_name,
            $article_id,
            $deal_name,
            "\n",
            "\n\n"
        );


        foreach ($change_notes as $note) {
            $content .= sprintf(
                "• %s : %s ➔ %s\n",
                esc_html($note['label']),
                esc_html($note['old_value']),
                esc_html($note['new_value'])
            );
        }

        // Lien vers l'offre/le deal dans le CRM ISPAG
        $url = trailingslashit(get_site_url() . '/project-detail/' . $deal_id );

        // 3. Envoi centralisé via le Notifier ISPAG
        $sent = ISPAG_Notifications_Manager::send(
            $recipient_ids,            // Identifiants des destinataires (tableau de User IDs)
            'product_modifications',         // Type de notification enregistré à l'étape 1
            $title,                    // Titre
            $content,                  // Contenu texte/liste des champs
            $url,                      // URL de redirection
            $article_id,               // entity_id (Article ID)
            [                          // Données supplémentaires
                'deal_id'      => $deal_id,
                'change_notes' => $change_notes,
            ]
        );

        if ($sent) {
            wp_send_json_success(['message' => 'Notification transmise au gestionnaire de notifications.']);
        } else {
            wp_send_json_error(['message' => 'Failed to handle the notification.']);
        }
    }
}

// ----------------------------------------------------------------------------
// Fonctions externes (hors classe)
// ----------------------------------------------------------------------------

add_action('wp_ajax_ispag_update_phase_status', 'ajax_ispag_update_phase_status');
add_action('wp_ajax_ajax_get_generate_po_button', 'ajax_get_generate_po_button');

function ajax_ispag_update_phase_status()
{
    $user_id = get_current_user_id();
    $logger = ISPAG_Logger::get_instance();
    $logger->log_user_action('detail_page', 'ajax_ispag_update_phase_status_start', [], $user_id);

    $deal_id = intval($_POST['deal_id']);
    $slug = sanitize_text_field($_POST['slug_phase']);
    $status_id = intval($_POST['status_id']);

    $logger->log_user_action('detail_page', 'phase_status_params_received', ['deal_id' => $deal_id, 'slug' => $slug, 'status_id' => $status_id], $user_id);

    ispag_update_phase_status($deal_id, $slug, $status_id);
}

function ispag_update_phase_status($deal_id, $slug, $status_id) {

    $user_id = get_current_user_id();
    
    $logger = ISPAG_Logger::get_instance();
    
    $logger->log_user_action('detail_page', 'ispag_update_phase_status_start', [
        'deal_id' => $deal_id,
        'slug' => $slug,
        'status_id' => $status_id
    ], $user_id);

    global $wpdb;

    $table = $wpdb->prefix . 'achats_suivi_phase_commande';
    $meta_table = $wpdb->prefix . 'achats_meta_phase_commande';

    // Insertion du statut de la phase
    $inserted = $wpdb->insert($table, [
        'hubspot_deal_id' => $deal_id,
        'purchase_id' => 0,
        'slug_phase' => $slug,
        'status_id' => $status_id
    ]);

    $logger->log_db_change('detail_page', $table, 'INSERT_PHASE_STATUS', [
        'deal_id' => $deal_id,
        'slug' => $slug,
        'status_id' => $status_id,
        'result' => $inserted
    ], $user_id);

    if ($inserted) {
        $logger->log_user_action('detail_page', 'phase_status_inserted', [], $user_id);
    } else {
        $logger->log('detail_page', 'ERROR: Phase status insertion failed', $user_id);
    }

    if ($status_id == 1) {
        // 1. Projet (destinataires de la notification)
        $project = apply_filters('ispag_get_project_by_deal_id', null, $deal_id);


        //Destinataire
        $recipient_ids = []; // ID de l'admin par défaut
        $cc_ids = [1];

        // Ajouter AssociatedContactIDs
        if (!empty($project->AssociatedContactIDs)) {
            $contact_ids = explode(',', $project->AssociatedContactIDs);
            foreach ($contact_ids as $contact_id) {
                $contact_id = trim($contact_id);
                if (!empty($contact_id) && !in_array($contact_id, $recipient_ids)) {
                    $recipient_ids[] = (int)$contact_id;
                }
            }
        }

        // Ajouter created_by
        if (!empty($project->created_by) && !in_array($project->created_by, $recipient_ids)) {
            $cc_ids[] = (int)$project->created_by;
        }

        // Log des destinataires préparés
        $logger->log_user_action('detail_page', 'recipients_prepared_for_notification', [
            'recipient_ids' => $recipient_ids,
            'project_associated_contacts' => $project->AssociatedContactIDs ?? '',
            'project_created_by' => $project->created_by ?? ''
        ], $user_id);

        // 2. Récupérer le message Telegram
        $raw_message = null;
        if (class_exists('ISPAG_Telegram_Notifier')) {
            $telegram_notifier = new ISPAG_Telegram_Notifier();
            $raw_message = $telegram_notifier->get_message($slug, $deal_id);
            $logger->log_user_action('detail_page', 'telegram_message_retrieved', [
                'slug' => $slug,
                'deal_id' => $deal_id,
                'raw_message' => $raw_message
            ], $user_id);
        }

        // 3. Nettoyer le message
        $clean_content = str_replace(['<br/>', '<br>', '<br />'], "\n", $raw_message);
        $clean_content = html_entity_decode($clean_content, ENT_QUOTES, 'UTF-8');

        // Log du message nettoyé
        $logger->log_user_action('detail_page', 'cleaned_notification_content', [
            'clean_content' => $clean_content
        ], $user_id);

        // 4. Titre de la notification
        $titre_notification = sprintf(__('Project tracking: %s', 'creation-reservoir'), ucfirst(str_replace('_', ' ', $slug)));
        $logger->log_user_action('detail_page', 'notification_title_prepared', [
            'title' => $titre_notification,
            'slug' => $slug
        ], $user_id);

        // 5. Envoyer la notification avec les données nécessaires pour Brevo
        if (class_exists('ISPAG_Notifications_Manager') && !empty($recipient_ids)) {
            $logger->log_user_action('detail_page', 'sending_notification_via_manager', [
                'recipient_ids' => $recipient_ids,
                'type' => 'project_followup',
                'title' => $titre_notification,
                'content' => $clean_content,
                'url' => 'project-detail/' . $deal_id,
                'entity_id' => $deal_id,
                'extra_data' => [
                    'deal_id'       => $deal_id,
                    'slug'          => $slug,
                    'cc_ids'        => $cc_ids,
                    'phase_slug'    => $slug
                ]
            ], $user_id);

            

            ISPAG_Notifications_Manager::send(
                $recipient_ids, // Tableau de tous les IDs
                'deal_status_change', // Type de notification
                $titre_notification,
                $clean_content,
                'project-detail/' . $deal_id,
                $deal_id,
                [ 
                    'deal_id'       => $deal_id,
                    'cc_ids'        => $cc_ids,
                    // e-mail client de l'étape : rendu et envoyé par ISPAG_Phase_Mail (templates achats_template_mail)
                    'phase_slug'    => $slug
                ]
            );

            $logger->log_user_action('detail_page', 'notification_sent_successfully', [
                'recipient_ids' => $recipient_ids,
                'type' => 'deal_status_change'
            ], $user_id);
        } else {
            $logger->log('detail_page', 'ERROR: Notifications not sent - ISPAG_Notifications_Manager not available or no recipients', $user_id);
        }
    }

    // Récupération des métadonnées du statut
    $meta = $wpdb->get_row($wpdb->prepare("SELECT Nom, Couleur FROM $meta_table WHERE Id = %d", $status_id));
    $logger->log_db_change('detail_page', $meta_table, 'FETCH_PHASE_META', [
        'status_id' => $status_id,
        'meta' => $meta
    ], $user_id);

    wp_send_json_success([
        'name' => $meta->Nom,
        'color' => $meta->Couleur
    ]);
}

add_action('wp_ajax_ispag_load_suivis', 'ispag_ajax_load_suivis');

function ispag_ajax_load_suivis()
{
    $user_id = get_current_user_id();
    $logger = ISPAG_Logger::get_instance();
    $logger->log_user_action('detail_page', 'ispag_ajax_load_suivis_start', [], $user_id);

    check_ajax_referer('ispag_nonce', 'nonce');

    if (!current_user_can('read_orders'))
    {
        $logger->log('detail_page', 'ERROR: User cannot read orders', $user_id);
        wp_send_json_error(['message' => __('Restricted access', 'ispag-crm')]);
    }

    $deal_id = isset($_POST['deal_id']) ? sanitize_text_field($_POST['deal_id']) : '';
    $isQuotation = isset($_POST['is_quotation']) ? $_POST['is_quotation'] === 'true' : false;

    $logger->log_user_action('detail_page', 'suivis_params_received', ['deal_id' => $deal_id, 'isQuotation' => $isQuotation], $user_id);

    if (!$deal_id)
    {
        $logger->log('detail_page', 'ERROR: Missing deal_id', $user_id);
        wp_send_json_error(['message' => __('Project not found', 'creation-reservoir')]);
    }

    ob_start();
    display_ispag_suivis($deal_id, $isQuotation);
    $html = ob_get_clean();

    $logger->log_user_action('detail_page', 'ispag_ajax_load_suivis_complete', [], $user_id);
    wp_send_json_success(['html' => $html]);
}

function display_ispag_suivis($deal_id, $isQuotation = false)
{
    $user_id = get_current_user_id();
    $logger = ISPAG_Logger::get_instance();
    $logger->log_user_action('detail_page', 'display_ispag_suivis_start', ['deal_id' => $deal_id, 'isQuotation' => $isQuotation], $user_id);

    do_action('isag_run_auto_update', $deal_id);
    $logger->log_user_action('detail_page', 'auto_update_triggered', ['deal_id' => $deal_id], $user_id);

    $phases = (new ISPAG_Phase_Repository())->get_project_phases($deal_id, $isQuotation);
    $logger->log_db_change('detail_page', 'phases', 'FETCH_PROJECT_PHASES', ['deal_id' => $deal_id, 'count' => count($phases)], $user_id);

    if ($phases)
    {
        $can_edit = current_user_can('manage_order');
        $can_view_all = current_user_can('manage_order');

        $logger->log_user_action('detail_page', 'suivis_permissions_checked', ['can_edit' => $can_edit, 'can_view_all' => $can_view_all], $user_id);

        echo '<div class="ispag-suivi-wrapper">';
        echo '<div class="ispag-suivi-steps">';

        foreach ($phases as $phase)
        {
            if (!$can_view_all && (int)$phase->VisuClient !== 1)
            {
                $logger->log_user_action('detail_page', 'phase_skipped_client_view', ['phase_id' => $phase->Id], $user_id);
                continue;
            }

            $classes = 'suivi-status-badge editable-status';
            if (!$can_edit) $classes .= ' non-editable';


            // Sur la ligne 1012, remplacez par exemple :
            $item_id = isset($phase->id) ? $phase->id : (isset($phase->Id) ? $phase->Id : 0);
            $logger->log_user_action('detail_page', 'rendering_phase', ['phase_id' => $item_id, 'title' => $phase->TitrePhase], $user_id);

            echo '<div class="suivi-step-row">';

            echo '<div class="step-indicator">';
                echo '<div class="step-dot" style="background-color: ' . esc_attr($phase->statut_couleur) . ';"></div>';
                echo '<div class="step-line"></div>';
            echo '</div>';

            echo '<div class="step-content-box">';
                echo '<div class="step-main-info">';
                    echo '<span class="step-title">';
                        echo esc_html(__($phase->TitrePhase, 'creation-reservoir'));
                        if (isset($phase->Brevo_id) && $phase->Brevo_id != 0)
                        {
                            echo ' <span class="dashicons dashicons-email" title="' . esc_attr__('Triggers email sending', 'creation-reservoir') . '"></span>';
                        }
                        if (isset($phase->is_automatic) && $phase->is_automatic != 0)
                        {
                            echo ' <span class="dashicons dashicons-controls-repeat" title="' . esc_attr__('Automatic step', 'creation-reservoir') . '"></span>';
                        }
                    echo '</span>';
                    echo '<span class="step-date"> ' . __('Realized on: ', 'creation-reservoir') . ($phase->date_modification ? date('d.m.Y', strtotime($phase->date_modification)) : '--.--.--') . '</span>';
                echo '</div>';

                echo '<div class="step-status-area">';
                    echo '<span class="' . esc_attr($classes) . '"
                        data-deal="' . esc_attr($deal_id) . '"
                        data-phase="' . esc_attr($phase->SlugPhase) . '"
                        data-current="' . esc_attr($phase->status_id ?? '') . '"
                        style="border-left: 4px solid ' . esc_attr($phase->statut_couleur) . ';">'
                        . esc_html__($phase->statut_nom, 'creation-reservoir') .
                    '</span>';
                echo '</div>';
            echo '</div>';

            echo '</div>';
        }

        echo '</div>';
        echo '</div>';
    }
    else
    {
        $logger->log_user_action('detail_page', 'no_phases_found', [], $user_id);
        echo '<div class="ispag-notice">' . __('No phase defined for this project', 'creation-reservoir') . '</div>';
    }
}

// function display_ispag_doc_manger($deal_id, $is_purchase = false)
// {
//     $user_id = get_current_user_id();
//     $logger = ISPAG_Logger::get_instance();
//     $logger->log_user_action('detail_page', 'display_ispag_doc_manager_start', ['deal_id' => $deal_id, 'is_purchase' => $is_purchase], $user_id);

//     $docManager = new ISPAG_Document_Manager();
//     $docs = $docManager->get_documents_grouped_by_article($deal_id, $is_purchase);
//     $logger->log_db_change('detail_page', 'documents', 'FETCH_GROUPED_BY_ARTICLE', ['deal_id' => $deal_id, 'count' => count($docs)], $user_id);

//     echo $docManager->render_grouped_documents($docs);
// }

// function display_ispag_project_details($deal_id, $details)
// {
//     $user_id = get_current_user_id();
//     $logger = ISPAG_Logger::get_instance();
//     $logger->log_user_action('detail_page', 'display_ispag_project_details_start', ['deal_id' => $deal_id], $user_id);

//     return (new ISPAG_Project_views_Renderer())->display($deal_id, $details);
// }

// function display_ispag_project_articles($deal_id, $isQotation = false)
// {
//     $user_id = get_current_user_id();
//     $logger = ISPAG_Logger::get_instance();
//     $logger->log_user_action('detail_page', 'display_ispag_project_articles_start', ['deal_id' => $deal_id, 'isQotation' => $isQotation], $user_id);

//     if (!$deal_id)
//     {
//         $logger->log('detail_page', 'ERROR: Missing deal_id', $user_id);
//         return '<p>' . __('Project not found', 'creation-reservoir') . '</p>';
//     }

//     $can_manage_order = current_user_can('manage_order');
//     $can_view_prices = current_user_can('display_sales_prices');

//     $logger->log_user_action('detail_page', 'permissions_checked', ['can_manage_order' => $can_manage_order, 'can_view_prices' => $can_view_prices], $user_id);

//     if ($can_view_prices)
//     {
//         echo '<div id="ispag-bloc-stat-projet" class="fields-prices">';
//         echo apply_filters('ispag_display_deal_stats', null, $deal_id);
//         echo '</div>';

//         echo '<div id="ispag-coef-notice" data-deal-id="' . $deal_id . '">';
//         $notice = new ISPAG_Article_Pricing();
//         echo $notice->render_sales_coef_notice($deal_id);
//         echo '</div>';
//     }

//     echo '<div id="display_article_page">';
//     if ($can_manage_order)
//     {
//         // echo '<div id="ispag-bulk-message" class="bulk_message"></div>';

//         echo '
//         <div class="ispag-article-header-global" style="margin-bottom: 1rem;">
//             <input type="checkbox" id="select-all-articles" class="ispag-article-checkbox">
//             <label for="select-all-articles">' . __('Select all', 'creation-reservoir') . '</label>
//         </div>';
//     }

//     $details_repo = new ISPAG_Project_Details_Repository();
//     $infos = $details_repo->get_infos_livraison($deal_id);
//     $logger->log_db_change('detail_page', 'project_details', 'FETCH_DELIVERY_INFO', ['deal_id' => $deal_id], $user_id);

//     echo '<div class="ispag-articles-content" id="display_ispag_article_list">';
//     echo '
//     <div class="ispag-article-list-overlay">
//         <div id="ispag-loading-spinner" style="text-align:center;"><span class="dashicons dashicons-update" style="animation: spin 2s linear infinite;"></span> ' . __('Loading', 'creation-reservoir') . '</div>
//     </div>';
//     echo '<div class="ispag-articles-list" data-deal-id=' . $deal_id . '>';
//     echo '</div>';
//     echo '<button id="ispag-add-article" class="ispag-btn ispag-btn-secondary-outlined" data-deal-id="' . esc_attr($deal_id) . '"><span class="dashicons dashicons-plus-alt"></span> ' . __('Add product', 'creation-reservoir'). '</button>';

//     if ($can_manage_order)
//     {
//         echo !$isQotation ? ' ' . get_delivery_btn($infos) : null;
//         echo $isQotation ? '<button id="convert-to-project" class="ispag-btn ispag-btn-secondary-outlined" data-id="' . $deal_id . '"><span class="dashicons dashicons-random-alt"></span>' . __('Transform to project', 'creation-reservoir'). '</button>' : null;

//         echo get_generate_po_button($deal_id);
//         echo display_invoice_btn($deal_id);
//         echo apply_filters('ispag_delete_project_btn', null, $deal_id);
//         echo bulk_selected_article($deal_id);
//     }
//     echo '</div>';
//     echo '</div>';
// }

function ajax_get_generate_po_button()
{
    $user_id = get_current_user_id();
    $logger = ISPAG_Logger::get_instance();
    $logger->log_user_action('detail_page', 'ajax_get_generate_po_button_start', [], $user_id);

    ob_start();

    $deal_id = intval($_POST['deal_id'] ?? 0);
    if (!$deal_id)
    {
        $logger->log('detail_page', 'ERROR: Missing deal_id', $user_id);
        ob_end_clean();
        wp_send_json_error('Deal ID manquant');
    }

    $html = get_generate_po_button($deal_id);
    if (ob_get_length()) ob_clean();

    $logger->log_user_action('detail_page', 'ajax_get_generate_po_button_complete', [], $user_id);
    wp_send_json_success($html);
}

function get_generate_po_button($deal_id)
{
    if(! ISPAG_Capabilities::can_manage_project_actions())
        {
            return;
        }
    $user_id = get_current_user_id();
    $logger = ISPAG_Logger::get_instance();
    $logger->log_user_action('detail_page', 'get_generate_po_button_start', ['deal_id' => $deal_id], $user_id);

    $repo = new ISPAG_Article_Repository();
    // $articles_list = $repo->get_articles_by_deal($deal_id);
    if (current_user_can('navigate_new_project_details_presentation')) {
        $articles_list = $repo->get_optimised_articles_by_deal($deal_id);
        
    }
    else{
        $articles_list = $repo->get_articles_by_deal($deal_id);
        
    }
    $logger->log_db_change('detail_page', 'articles', 'FETCH_BY_DEAL', ['deal_id' => $deal_id, 'count' => count($articles_list)], $user_id);

    $has_pending_purchase_request = false;

    foreach ($articles_list as $group_id => $group)
    {
        foreach ($group as $article)
        {
            if (empty($article->DemandeAchatOk))
            {
                $logger->log_user_action('detail_page', 'pending_purchase_request_detected', ['article_id' => $article->Id], $user_id);
                $has_pending_purchase_request = true;
                break 2;
            }

            if (!empty($article->secondaires))
            {
                foreach ($article->secondaires as $secondaire)
                {
                    if (empty($secondaire->DemandeAchatOk))
                    {
                        $logger->log_user_action('detail_page', 'pending_secondary_purchase_request_detected', ['article_id' => $secondaire->Id], $user_id);
                        $has_pending_purchase_request = true;
                        break 3;
                    }
                }
            }
        }
    }

    if ($has_pending_purchase_request)
    {
        $logger->log_user_action('detail_page', 'generate_po_button_displayed', [], $user_id);
        return '<button id="generate-purchase-requests" class="ispag-btn ispag-btn-red-outlined" data-deal-id="' . esc_attr($deal_id) . '"><span class="dashicons dashicons-cart"></span>' . __('Generate purchase request', 'creation-reservoir') . '</button>';
    }

    $logger->log_user_action('detail_page', 'generate_po_button_not_displayed', [], $user_id);
    return '';
}

function display_invoice_btn($deal_id)
{
    $user_id = get_current_user_id();
    $logger = ISPAG_Logger::get_instance();
    $logger->log_user_action('detail_page', 'display_invoice_btn_start', ['deal_id' => $deal_id], $user_id);

    $repo = new ISPAG_Article_Repository();
    // $articles_grouped = $repo->get_articles_by_deal($deal_id);
    if (current_user_can('navigate_new_project_details_presentation')) {
        $articles_grouped = $repo->get_optimised_articles_by_deal($deal_id);
        
    }
    else{
        $articles_grouped = $repo->get_articles_by_deal($deal_id);
        
    }
    $logger->log_db_change('detail_page', 'articles', 'FETCH_GROUPED_BY_DEAL', ['deal_id' => $deal_id, 'count' => count($articles_grouped)], $user_id);

    $all_articles = [];
    foreach ($articles_grouped as $group)
    {
        foreach ($group as $article)
        {
            $all_articles[] = $article;
            if (!empty($article->secondaires))
            {
                $all_articles = array_merge($all_articles, $article->secondaires);
            }
        }
    }

    $total = count($all_articles);
    $livrés = 0;
    $facturés = 0;

    foreach ($all_articles as $article)
    {
        $est_livré = !empty($article->Livre);
        $est_facturé = !empty($article->invoiced);

        if ($est_livré) $livrés++;
        if ($est_facturé) $facturés++;

        $logger->log_user_action('detail_page', 'article_delivery_invoice_status', ['article_id' => $article->Id, 'livré' => $est_livré, 'facturé' => $est_facturé], $user_id);
    }

    if ($livrés === 0)
    {
        $logger->log_user_action('detail_page', 'invoice_btn_not_displayed_no_delivered', [], $user_id);
        return '';
    }

    if ($livrés === $total && $facturés < $livrés)
    {
        $logger->log_user_action('detail_page', 'final_invoice_btn_displayed', [], $user_id);
        return sprintf(
            '<button class="ispag-btn ispag-btn-success project-action-btn" data-deal-id="%d" data-hook="ispag_send_final_invoice"><span class="dashicons dashicons-money-alt"></span>%s</button>',
            $deal_id,
            __('Send final invoice', 'creation-reservoir')
        );
    }

    if ($livrés > 0 && $facturés < $livrés)
    {
        $logger->log_user_action('detail_page', 'partial_invoice_btn_displayed', [], $user_id);
        return sprintf(
            '<button class="ispag-btn ispag-btn-warning-outlined project-action-btn" data-deal-id="%d" data-hook="ispag_send_partial_invoice">%s</button>',
            $deal_id,
            __('Send partial invoice request', 'creation-reservoir')
        );
    }

    $logger->log_user_action('detail_page', 'invoice_btn_not_displayed_already_invoiced', [], $user_id);
    return '';
}

// function bulk_selected_article($deal_id)
// {
//     $user_id = get_current_user_id();
//     $logger = ISPAG_Logger::get_instance();
//     $logger->log_user_action('detail_page', 'bulk_selected_article_start', ['deal_id' => $deal_id], $user_id);

//     $can_manage_order = current_user_can('manage_order');
//     if (!$can_manage_order)
//     {
//         $logger->log('detail_page', 'ERROR: User cannot manage order', $user_id);
//         return false;
//     }

//     $discount_value = apply_filters('ispag_get_project_discount', null, $deal_id) ?? null;
//     $logger->log_user_action('detail_page', 'project_discount_fetched', ['deal_id' => $deal_id, 'discount' => $discount_value], $user_id);

//     $bulk = '<div class="ispag-bulk-actions" style="border: 1px solid #ccc; padding: 1rem; margin: 1rem 0; display:none;">
//         <h4>' . __('Bulk update selected articles', 'creation-reservoir') . '</h4>
//         <input type="hidden" id="deal-id" value="' . $deal_id . '">

//         <label>' . __('Factory departure date', 'creation-reservoir') . ' :
//             <input type="date" id="bulk-date-depart">
//         </label>

//         <label>' . __('Delivery ETA', 'creation-reservoir') . ' :
//             <input type="date" id="bulk-date-eta">
//         </label>

//         <label><input type="checkbox" id="bulk-demande-ok"> 🛒 ' . __('Purchase request OK', 'creation-reservoir') . '</label>
//         <label><input type="checkbox" id="bulk-drawing-ok"> 📝 ' . __('Drawing approved', 'creation-reservoir') . '</label>

//         <label>
//             📦 ' . __('Delivered on', 'creation-reservoir') . ' :
//             <input type="date" id="bulk-livre-date">
//         </label>

//         <label>
//             🧾 ' . __('Invoiced on', 'creation-reservoir') . ' :
//             <input type="date" id="bulk-invoiced-date">
//         </label>

//         <label>
//             🧾 ' . __('Discount', 'creation-reservoir') . ' :
//             <span class="discount-input-container">
//                 <input
//                     type="number"
//                     id="bulk-discount"
//                     name="bulk-discount"
//                     min="0"
//                     max="100"
//                     step="0.01"
//                     maxlength="5"
//                     style="width: 105px;"
//                     value="' . $discount_value . '"
//                 >
//                 <span class="unit">%</span>
//             </span>
//         </label>

//         <button id="apply-bulk-update" class="ispag-btn ispag-btn-green">' . __('Apply changes', 'creation-reservoir') . '</button>
//     </div>';

//     $logger->log_user_action('detail_page', 'bulk_actions_html_prepared', [], $user_id);
//     return $bulk;
// }

// function bulk_selected_article($deal_id)
// {
//     $user_id = get_current_user_id();
//     $logger = ISPAG_Logger::get_instance();
//     $logger->log_user_action('detail_page', 'bulk_selected_article_start', ['deal_id' => $deal_id], $user_id);

//     $can_manage_order = current_user_can('manage_order');
//     if (!$can_manage_order)
//     {
//         $logger->log('detail_page', 'ERROR: User cannot manage order', $user_id);
//         return false;
//     }

//     $discount_value = apply_filters('ispag_get_project_discount', null, $deal_id) ?? null;
//     $logger->log_user_action('detail_page', 'project_discount_fetched', ['deal_id' => $deal_id, 'discount' => $discount_value], $user_id);

//     $bulk = '<div class="ispag-bulk-actions ispag-card" style="display:none;">
//         <div class="ispag-bulk-header">
//             <h4>🛠️ ' . __('Bulk update selected articles', 'creation-reservoir') . '</h4>
//         </div>
//         <input type="hidden" id="deal-id" value="' . esc_attr($deal_id) . '">

//         <div class="ispag-bulk-grid">

//             <div class="ispag-bulk-field">
//                 <label for="bulk-date-depart">' . __('Factory departure date', 'creation-reservoir') . '</label>
//                 <input type="date" id="bulk-date-depart">
//             </div>

//             <div class="ispag-bulk-field">
//                 <label for="bulk-date-eta">' . __('Delivery ETA', 'creation-reservoir') . '</label>
//                 <input type="date" id="bulk-date-eta">
//             </div>

//             <div class="ispag-bulk-field">
//                 <label for="bulk-livre-date">📦 ' . __('Delivered on', 'creation-reservoir') . '</label>
//                 <input type="date" id="bulk-livre-date">
//             </div>

//             <div class="ispag-bulk-field">
//                 <label for="bulk-invoiced-date">🧾 ' . __('Invoiced on', 'creation-reservoir') . '</label>
//                 <input type="date" id="bulk-invoiced-date">
//             </div>

//             <div class="ispag-bulk-field">
//                 <label for="bulk-discount">' . __('Discount', 'creation-reservoir') . '</label>
//                 <div class="ispag-input-suffix">
//                     <input type="number" id="bulk-discount" name="bulk-discount" min="0" max="100" step="0.01" maxlength="5" value="' . esc_attr($discount_value) . '">
//                     <span class="suffix">%</span>
//                 </div>
//             </div>

//             <div class="ispag-bulk-field ispag-bulk-toggles">
//                 <label class="ispag-toggle-chip">
//                     <input type="checkbox" id="bulk-demande-ok">
//                     <span>🛒 ' . __('Purchase request OK', 'creation-reservoir') . '</span>
//                 </label>
//                 <label class="ispag-toggle-chip">
//                     <input type="checkbox" id="bulk-drawing-ok">
//                     <span>📝 ' . __('Drawing approved', 'creation-reservoir') . '</span>
//                 </label>
//             </div>

//         </div>

//         <div class="ispag-bulk-footer">
//             <button id="apply-bulk-update" class="ispag-btn ispag-btn-green">✅ ' . __('Apply changes', 'creation-reservoir') . '</button>
//         </div>
//     </div>';

//     $logger->log_user_action('detail_page', 'bulk_actions_html_prepared', [], $user_id);
//     return $bulk;
// }

function get_delivery_btn($infos, int $achat_id = 0)
{
    $user_id = get_current_user_id();
    $logger = ISPAG_Logger::get_instance();
    $logger->log_user_action('detail_page', 'get_delivery_btn_start', [], $user_id);

    // Ce bloc est souvent rendu en AJAX (pas de variable d'URL) : le deal vient d'abord des infos de livraison
    $deal_id = intval($infos->hubspot_deal_id ?? 0) ?: intval(get_query_var('deal_id') ?: ($_GET['deal_id'] ?? null));
    $val = function ($k) use ($infos) { return trim(stripslashes((string) ($infos->$k ?? ''))); };

    // Champs du formulaire : nom => [libellé, type, pleine largeur ?]
    $fields = [
        'delivery_date'      => [__('Delivery date', 'creation-reservoir'), 'date', true],
        'AdresseDeLivraison' => [__('Adress', 'creation-reservoir'), 'text', true],
        'DeliveryAdresse2'   => [__('Complement', 'creation-reservoir'), 'text', true],
        'DeliveryAdresse3'   => [__('Complement 2', 'creation-reservoir'), 'text', true],
        'NIP'                => [__('Postal code', 'creation-reservoir'), 'text', false],
        'City'               => [__('City', 'creation-reservoir'), 'text', false],
        'PersonneContact'    => [__('Contact', 'creation-reservoir'), 'text', false],
        'num_tel_contact'    => [__('Phone number', 'creation-reservoir'), 'tel', false],
    ];

    ob_start();
    ?>
    <button type="button" id="generate-pdf" class="ispag-btn ispag-btn-secondary-outlined" style="margin-top: 1rem;"
            data-deal-id="<?= esc_attr($deal_id); ?>"
            data-poid="<?= esc_attr($achat_id ?: ''); ?>"
            data-ajax-url="<?= esc_url(admin_url('admin-ajax.php')); ?>"
            data-none-selected="<?= esc_attr__('No items selected', 'creation-reservoir'); ?>.">
        📄 <?= esc_html($achat_id && ISPAG_Delivery_Receipt::is_work_order_purchase($achat_id) ? __('Work order', 'creation-reservoir') : __('Delivery note', 'creation-reservoir')); ?>
    </button>

    <?php /* Modèle de la fenêtre : déplacée dans <body> à l'ouverture pour passer au premier plan (au-dessus de tout le reste) */ ?>
    <template id="ispag-dn-template">
        <div class="ispag-dn-overlay" role="dialog" aria-modal="true" aria-labelledby="ispag-dn-title">
            <div class="ispag-dn-modal">
                <header class="ispag-dn-head">
                    <div>
                        <h3 id="ispag-dn-title">📄 <?= esc_html($achat_id && ISPAG_Delivery_Receipt::is_work_order_purchase($achat_id) ? __('Work order', 'creation-reservoir') : __('Delivery note', 'creation-reservoir')); ?></h3>
                        <p class="ispag-dn-sub"><?= esc_html__('Check the delivery information: it is used for this document only.', 'creation-reservoir'); ?></p>
                    </div>
                    <button type="button" class="ispag-dn-close" aria-label="<?= esc_attr__('Close', 'creation-reservoir'); ?>">&times;</button>
                </header>

                <form id="delivery-form" class="ispag-dn-body" novalidate>
                    <div class="ispag-dn-items">
                        <strong class="ispag-dn-items-count"></strong>
                        <ul class="ispag-dn-items-list"></ul>
                    </div>

                    <div class="ispag-dn-grid">
                        <?php foreach ($fields as $name => [$label, $type, $wide]):
                            $value = $val($name);
                            if ($name === 'delivery_date' && $value === '') $value = date('Y-m-d');
                            ?>
                            <label class="ispag-dn-field<?= $wide ? ' is-wide' : ''; ?>">
                                <span><?= esc_html($label); ?></span>
                                <input type="<?= esc_attr($type); ?>" name="<?= esc_attr($name); ?>" value="<?= esc_attr($value); ?>"
                                    <?= $name === 'NIP' ? 'inputmode="numeric" autocomplete="postal-code"' : ''; ?>
                                    <?= $name === 'delivery_date' ? 'required' : ''; ?>>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </form>

                <footer class="ispag-dn-foot">
                    <span class="ispag-dn-status" aria-live="polite"></span>
                    <button type="button" class="ispag-btn ispag-btn-secondary-outlined ispag-dn-cancel"><?= esc_html__('Cancel', 'creation-reservoir'); ?></button>
                    <button type="button" class="ispag-btn ispag-btn-green ispag-dn-confirm">📄 <?= esc_html__('Generate the delivery note', 'creation-reservoir'); ?></button>
                </footer>
            </div>
        </div>
    </template>

    <script>
    (function () {
        if (window.__ispagDeliveryNoteBound) return; // un seul branchement, même si le bloc est rechargé
        window.__ispagDeliveryNoteBound = true;

        let overlay = null;

        function selectedArticles() {
            return Array.from(document.querySelectorAll('.ispag-article-checkbox:checked'))
                .map(function (cb) {
                    const row = cb.closest('.ispag-article');
                    const title = row ? row.querySelector('.ispag-article-title') : null;
                    return { id: cb.dataset.articleId, title: title ? title.textContent.trim() : '' };
                })
                .filter(function (a) { return a.id; });
        }

        function close() {
            if (!overlay) return;
            overlay.classList.remove('is-open');
            document.body.classList.remove('ispag-dn-lock');
            document.removeEventListener('keydown', onKey);
        }

        function onKey(e) {
            if (e.key === 'Escape') close();
        }

        function build() {
            if (overlay) return overlay;
            const tpl = document.getElementById('ispag-dn-template');
            overlay = tpl.content.firstElementChild.cloneNode(true);
            document.body.appendChild(overlay);

            overlay.addEventListener('mousedown', function (e) { if (e.target === overlay) close(); });
            overlay.querySelector('.ispag-dn-close').addEventListener('click', close);
            overlay.querySelector('.ispag-dn-cancel').addEventListener('click', close);
            overlay.querySelector('.ispag-dn-confirm').addEventListener('click', generate);
            overlay.querySelector('#delivery-form').addEventListener('submit', function (e) { e.preventDefault(); generate(); });
            overlay.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' && e.target.tagName === 'INPUT') { e.preventDefault(); generate(); }
            });

            // Code postal -> ville (si la ville est vide)
            overlay.querySelector('input[name="NIP"]').addEventListener('blur', function () {
                const zip = this.value.trim();
                const city = overlay.querySelector('input[name="City"]');
                if (!zip || city.value.trim()) return;
                fetch('https://api.zippopotam.us/' + (zip.length <= 4 ? 'CH' : 'FR') + '/' + encodeURIComponent(zip))
                    .then(function (r) { return r.ok ? r.json() : null; })
                    .then(function (d) {
                        const name = d && d.places && d.places[0] && d.places[0]['place name'];
                        if (name && !city.value.trim()) city.value = name;
                    }).catch(function () {});
            });
            return overlay;
        }

        // Téléphone : sélecteur de pays + formatage (intl-tel-input, comme le CRM ; chargé par le thème)
        function initPhone() {
            const input = overlay.querySelector('input[name="num_tel_contact"]');
            if (!input || input._iti || typeof window.intlTelInput === 'undefined') return;
            const utils = (window.ispag_params && ispag_params.utils_url) || 'https://cdn.jsdelivr.net/npm/intl-tel-input@20.0.5/build/js/utils.js';
            input._iti = window.intlTelInput(input, {
                initialCountry: 'ch',
                preferredCountries: ['ch', 'fr', 'be', 'de'],
                separateDialCode: true,
                allowDropdown: true,
                dropdownContainer: document.body,
                utilsScript: utils
            });
        }

        function open(btn) {
            const items = selectedArticles();
            if (!items.length) {
                if (typeof ispagConfirm === 'function') ispagConfirm(btn.dataset.noneSelected);
                else alert(btn.dataset.noneSelected);
                return;
            }
            build();
            overlay.dataset.dealId = btn.dataset.dealId;
            overlay.dataset.poid = btn.dataset.poid || '';
            overlay.dataset.ajaxUrl = btn.dataset.ajaxUrl;

            // Résumé des articles retenus
            const count = overlay.querySelector('.ispag-dn-items-count');
            const list = overlay.querySelector('.ispag-dn-items-list');
            count.textContent = items.length + (items.length > 1 ? ' articles' : ' article');
            list.innerHTML = '';
            items.slice(0, 6).forEach(function (a) {
                const li = document.createElement('li');
                li.textContent = a.title || ('#' + a.id);
                list.appendChild(li);
            });
            if (items.length > 6) {
                const li = document.createElement('li');
                li.className = 'is-more';
                li.textContent = '+ ' + (items.length - 6) + ' …';
                list.appendChild(li);
            }

            overlay.querySelector('.ispag-dn-status').textContent = '';
            overlay.querySelector('.ispag-dn-confirm').disabled = false;
            document.body.classList.add('ispag-dn-lock');
            overlay.classList.add('is-open');
            initPhone();
            document.addEventListener('keydown', onKey);
            const first = overlay.querySelector('input[name="delivery_date"]');
            setTimeout(function () { first.focus(); }, 50);
        }

        function generate() {
            const form = overlay.querySelector('#delivery-form');
            const date = form.querySelector('input[name="delivery_date"]');
            const status = overlay.querySelector('.ispag-dn-status');
            if (!date.value) {
                date.classList.add('is-invalid');
                date.focus();
                status.textContent = '⚠️ ' + date.closest('label').firstElementChild.textContent;
                return;
            }
            date.classList.remove('is-invalid');

            // Téléphone : validation + format international lisible (+41 79 123 45 67)
            const phone = form.querySelector('input[name="num_tel_contact"]');
            if (phone && phone._iti) {
                if (phone.value.trim()) {
                    if (!phone._iti.isValidNumber()) {
                        phone.classList.add('is-invalid');
                        phone.focus();
                        status.textContent = '⚠️ ' + (window.ispag_texts && ispag_texts.invalid_phone ? ispag_texts.invalid_phone : 'Invalid phone number');
                        return;
                    }
                    const fmt = (window.intlTelInputUtils && intlTelInputUtils.numberFormat) ? intlTelInputUtils.numberFormat.INTERNATIONAL : undefined;
                    phone.value = fmt !== undefined ? phone._iti.getNumber(fmt) : phone._iti.getNumber();
                } else {
                    phone.value = '';
                }
                phone.classList.remove('is-invalid');
            }
            status.textContent = '';

            const ids = selectedArticles().map(function (a) { return a.id; });
            const data = {};
            new FormData(form).forEach(function (v, k) { data[k] = v; });

            const url = new URL(overlay.dataset.ajaxUrl);
            url.searchParams.set('action', 'ispag_generate_pdf');
            if (overlay.dataset.poid) url.searchParams.set('poid', overlay.dataset.poid);
            else url.searchParams.set('deal_id', overlay.dataset.dealId);
            url.searchParams.set('ids', ids.join(','));
            url.searchParams.set('delivery', JSON.stringify(data));

            window.open(url.toString(), '_blank');
            close();
        }

        document.addEventListener('click', function (e) {
            const btn = e.target.closest('#generate-pdf');
            if (btn) { e.preventDefault(); open(btn); }
        });
    })();
    </script>
    <?php
    $logger->log_user_action('detail_page', 'get_delivery_btn_complete', [], $user_id);
    return ob_get_clean();
}

add_action('wp_ajax_ispag_generate_pdf', 'ispag_generate_pdf');

function ispag_generate_pdf()
{
    $user_id = get_current_user_id();
    $logger = ISPAG_Logger::get_instance();
    $logger->log_user_action('detail_page', 'ispag_generate_pdf_start', [], $user_id);

    if (!current_user_can('manage_order'))
    {
        $logger->log('detail_page', 'ERROR: User cannot manage order', $user_id);
        wp_die('Not authorized');
    }

    $deal_id = get_query_var('deal_id') ?: ($_GET['deal_id'] ?? null);;
    $achat_id = get_query_var('poid');
    if (empty($achat_id) && isset($_GET['poid']))
    {
        $achat_id = sanitize_text_field($_GET['poid']);
    }
    $achat_id = absint($achat_id);

    $logger->log_user_action('detail_page', 'pdf_params_received', ['deal_id' => $deal_id, 'achat_id' => $achat_id], $user_id);

    // Pas de projet dans la requête : on le déduit du premier article sélectionné
    if (!$achat_id && !$deal_id && !empty($_GET['ids']))
    {
        $first_id = intval(explode(',', trim((string) $_GET['ids'], ','))[0]);
        $first_article = $first_id ? apply_filters('ispag_get_article_by_id', null, $first_id) : null;
        $deal_id = $first_article->hubspot_deal_id ?? null;
    }

    if (!$achat_id && !$deal_id)
    {
        $logger->log('detail_page', 'ERROR: Missing achat_id and deal_id', $user_id);
        echo "ID d'achat ou de projet manquant.";
        return;
    }

    $ids_string = isset($_GET['ids']) ? sanitize_text_field($_GET['ids']) : '';
    $ids_string = trim($ids_string, ',');
    $ids = $ids_string === '' ? [] : explode(',', $ids_string);
    $ids = array_map('intval', $ids);
    $ids = array_filter($ids, fn($id) => $id > 0);

    if (empty($ids))
    {
        $logger->log('detail_page', 'ERROR: No valid IDs received', $user_id);
        wp_die('No ID received');
    }

    $logger->log_user_action('detail_page', 'ids_parsed', ['ids' => $ids], $user_id);

    $final_date = date('d.m.Y');
    $temp_delivery = [];

    if (!empty($_GET['delivery']))
    {
        $temp_delivery = json_decode(stripslashes($_GET['delivery']), true);
        if (is_array($temp_delivery) && !empty($temp_delivery['delivery_date']))
        {
            $final_date = date('d.m.Y', strtotime($temp_delivery['delivery_date']));
            $logger->log_user_action('detail_page', 'delivery_date_set', ['final_date' => $final_date], $user_id);
        }
    }

    $titre_project = __('Project', 'creation-reservoir');
    $titre_ref = __('Project number', 'creation-reservoir');
    $titre_delivery_date = __('Delivery date', 'creation-reservoir');

    $articles = [];
    $linked_project_ids = [];
    $purchase_line_ids  = [];
    $table_header = [
        ['label' => __('Reference', 'creation-reservoir'), 'key' => 'ref', 'width' => 40],
        ['label' => __('Description', 'creation-reservoir'), 'key' => 'description', 'width' => 110],
        ['label' => __('Quantity', 'creation-reservoir'), 'key' => 'qty', 'width' => 30, 'align' => 'C'],
    ];

    if (!empty($deal_id))
    {
        $logger->log_user_action('detail_page', 'processing_deal_id', ['deal_id' => $deal_id], $user_id);

        $article_obj = apply_filters('ispag_get_article_by_id', null, $ids[0]);
        $deal_id_real = $article_obj->hubspot_deal_id;

        $details_repo = new ISPAG_Project_Details_Repository();
        $infos = $details_repo->get_infos_livraison($deal_id_real);
        $logger->log_db_change('detail_page', 'project_details', 'FETCH_DELIVERY_INFO', ['deal_id_real' => $deal_id_real], $user_id);

        $project_data = apply_filters('ispag_get_project_by_deal_id', null, $deal_id_real);
        $logger->log_db_change('detail_page', 'projects', 'FETCH_PROJECT', ['deal_id_real' => $deal_id_real], $user_id);

        $project_header = [
            $titre_project => $project_data->ObjetCommande ?? '',
            $titre_ref => $project_data->NumCommande ?? '',
            $titre_delivery_date => $final_date
        ];

        foreach ($ids as $id)
        {
            $article = apply_filters('ispag_get_article_by_id', null, $id);
            $articles[] = [
                'ref' => $article->serial_no,
                'description' => $article->Article,
                'qty' => $article->Qty
            ];
            $logger->log_user_action('detail_page', 'article_added_to_pdf', ['article_id' => $id], $user_id);
        }
    }
    elseif (!empty($achat_id))
    {
        $logger->log_user_action('detail_page', 'processing_achat_id', ['achat_id' => $achat_id], $user_id);

        // Adresse de livraison = celle de la commande d'achat (achats_info_commande) ; modifiable dans la fenêtre avant génération
        $details_repo = new ISPAG_Achat_Details_Repository();
        $infos = $details_repo->get_infos_livraison($achat_id);
        $logger->log_db_change('detail_page', 'achat_details', 'FETCH_DELIVERY_INFO', ['achat_id' => $achat_id], $user_id);

        $project_data = apply_filters('ispag_get_achat_by_id', null, $achat_id);
        $logger->log_db_change('detail_page', 'achats', 'FETCH_PURCHASE', ['achat_id' => $achat_id], $user_id);
        if (empty($project_data))
        {
            wp_die('Purchase order not found');
        }

        $project_header = [
            $titre_project => $project_data->RefCommande ?? '',
            $titre_ref => $project_data->NrCommande ?? '',
            $titre_delivery_date => $final_date
        ];

        foreach ($ids as $id)
        {
            $line = apply_filters('ispag_get_purchse_article_by_id', null, $id);
            if (empty($line) || intval($line->IdCommande ?? 0) !== $achat_id)
            {
                continue; // ligne inconnue ou d'une autre commande
            }
            $purchase_line_ids[] = intval($line->Id);
            if (!empty($line->IdCommandeClient))
            {
                $linked_project_ids[] = intval($line->IdCommandeClient);
            }
            $articles[] = [
                'ref' => $line->serial_no ?: $line->Id,
                'description' => $line->RefSurMesure,
                'qty' => $line->Qty
            ];
            $logger->log_user_action('detail_page', 'purchase_article_added_to_pdf', ['article_id' => $id], $user_id);
        }
        if (!$articles)
        {
            wp_die('No valid article for this purchase order');
        }
    }
    else
    {
        $logger->log('detail_page', 'ERROR: No project or purchase defined', $user_id);
        wp_die('No project or purchase defined');
    }

    if (!empty($temp_delivery))
    {
        foreach ($temp_delivery as $key => $val)
        {
            if ($key !== 'delivery_date')
            {
                $infos->$key = sanitize_text_field($val);
                $logger->log_user_action('detail_page', 'delivery_info_updated', ['key' => $key, 'value' => $val], $user_id);
            }
        }
    }

    // Commande d'isolation ou de soudure : le document remis au sous-traitant est un « bon de travail »
    $work_order = !empty($achat_id) && ISPAG_Delivery_Receipt::is_work_order_purchase((int) $achat_id);
    $title = $work_order ? __('Work order', 'creation-reservoir') : __('Delivery note', 'creation-reservoir');
    require_once plugin_dir_path(__FILE__) . '/class-ispag-pdf-generator.php';
    // Réception par QR code : le contenu du bulletin est conservé pour régénérer la version signée
    $receipt_payload = [
        'kind'           => $work_order ? 'work_order' : 'delivery_note',
        'title'          => $title,
        'company'        => $project_data->nom_entreprise ?? '',
        'project_header' => $project_header,
        'infos'          => array_intersect_key((array) $infos, array_flip(['AdresseDeLivraison', 'DeliveryAdresse2', 'DeliveryAdresse3', 'NIP', 'City', 'PersonneContact', 'num_tel_contact'])),
        'table_header'   => $table_header,
        'articles'       => $articles,
        // Articles du projet à marquer « livrés » quand le bulletin est signé (bulletin projet : les articles choisis ;
        // bulletin d'achat : les articles projet liés aux lignes de la commande)
        'article_ids'    => !empty($deal_id) ? array_values($ids) : array_values(array_unique($linked_project_ids)),
        'purchase_line_ids' => array_values($purchase_line_ids),
    ];
    $receipt_deal = !empty($deal_id) ? intval($deal_id_real ?? $deal_id) : intval($project_data->hubspot_deal_id ?? 0);
    $qr_url = ISPAG_Delivery_Receipt::create($receipt_payload, $receipt_deal, !empty($achat_id) ? intval($achat_id) : 0);

    $pdf = new ISPAG_Delivery_Note_PDF();

    $pdf->generate($project_header, $project_data, $infos, $table_header, $articles, $title, ['qr_url' => $qr_url, 'work_order' => $work_order]);
    $logger->log_user_action('detail_page', 'pdf_generated', [], $user_id);

    $filename = sanitize_title($title);
    $pdf->Output('I', $filename . '.pdf');
    $logger->log_user_action('detail_page', 'pdf_output_complete', [], $user_id);
    exit;
}

function sanitize_filename(string $filename): string
{
    $user_id = get_current_user_id();
    $logger = ISPAG_Logger::get_instance();
    $logger->log_user_action('detail_page', 'sanitize_filename_start', ['original' => $filename], $user_id);

    $filename = iconv('UTF-8', 'ASCII//TRANSLIT', $filename);
    $filename = preg_replace('/[^a-zA-Z0-9\-]/', '-', $filename);
    $filename = preg_replace('/-+/', '-', $filename);
    $filename = trim($filename, '-');
    $filename = strtolower($filename);

    $logger->log_user_action('detail_page', 'filename_sanitized', ['sanitized' => $filename], $user_id);
    return $filename;
}