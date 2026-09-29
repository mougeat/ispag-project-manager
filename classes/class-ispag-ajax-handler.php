<?php
/**
 * Class ISPAG_Ajax_Handler
 * Gère les requêtes AJAX pour les articles et les commandes.
 * Logging : Toutes les actions sont loguées dans ispag_ajax_handler.log.
 */
class ISPAG_Ajax_Handler
{
    /** @var ISPAG_Logger Instance du logger. */
    private static $logger;

    public static function init()
    {
        self::$logger = ISPAG_Logger::get_instance();
        $user_id = get_current_user_id();
        // self::$logger->log_user_action('ajax_handler', 'class_initialized', [], $user_id);

        add_action('wp_ajax_ispag_inline_edit_field', [self::class, 'inline_edit_field']);
        add_action('wp_ajax_ispag_load_article_modal', [self::class, 'load_article_modal']);
        add_action('wp_ajax_ispag_load_article_edit_modal', [self::class, 'load_article_edit_modal']);
        add_action('wp_ajax_ispag_get_standard_article_info', [self::class, 'get_standard_article_info']);
        add_action('wp_ajax_ispag_save_article', [self::class, 'save_article']);
        add_action('wp_ajax_ispag_reload_article_row', [self::class, 'reload_article_row']);
        add_action('wp_ajax_ispag_open_new_article_modal', [self::class, 'open_new_article_modal']);
        add_action('wp_ajax_ispag_load_article_create_modal', [self::class, 'load_article_create_modal']);
        add_action('wp_ajax_ispag_delete_article', [self::class, 'delete_article']);
        add_action('wp_ajax_ispag_bulk_update_articles', [self::class, 'bulk_update_articles']);
        add_action('wp_ajax_ispag_duplicate_article', [self::class, 'ispag_duplicate_article']);

        add_action('ispag_update_delivery_date_from_purchase', [self::class, 'update_delivery_date_from_purchase'], 10, 4);
        add_action('ispag_article_saved_from_project', [self::class, 'handle_saved_article'], 10, 2);
        add_filter('ispag_article_save_pdf', [self::class, 'handle_save_article_from_pdf'], 10, 3);

        add_action('wp_ajax_update_group_name', [self::class, 'ispag_update_group_name_callback']);
    }

    public static function ispag_update_group_name_callback() {
        // Vérification du nonce pour la sécurité
        check_ajax_referer('ispag_ajax_nonce', 'security');

        global $wpdb;
        $table_name = $wpdb->prefix . 'achats_details_commande'; // Ou 'wor9711_achats_details_commande' directement

        $deal_id   = isset($_POST['deal_id']) ? intval($_POST['deal_id']) : 0;
        $old_group = isset($_POST['old_group']) ? trim(sanitize_text_field($_POST['old_group'])) : '';
        $new_group = isset($_POST['new_group']) ? trim(sanitize_text_field($_POST['new_group'])) : '';

        if (!$deal_id || empty($old_group) || empty($new_group)) {
            wp_send_json_error(array('message' => 'Missing parameters.'));
        }

        // Exécution de la mise à jour pour toutes les lignes correspondant au deal_id et à l'ancien groupe
        $updated = $wpdb->update(
            $table_name,
            array('Groupe' => $new_group), // Données à modifier
            array(
                'hubspot_deal_id' => $deal_id,
                'Groupe'          => $old_group
            ), // Where
            array('%s'), // Format de la donnée mise à jour
            array('%d', '%s') // Format du Where
        );

        if ($updated === false) {
            wp_send_json_error(array('message' => 'Error while updating the database.'));
        }

        wp_send_json_success(array('updated_rows' => $updated));
    }



    public static function inline_edit_field()
    {
        $user_id = get_current_user_id();
        self::$logger->log_user_action('ajax_handler', 'inline_edit_field_start', [], $user_id);

        $deal_id = intval($_POST['deal_id']);
        $field = sanitize_text_field($_POST['field']);
        $value = sanitize_text_field($_POST['value']);
        $source = sanitize_text_field($_POST['source'] ?? 'delivery');

        self::$logger->log_user_action('ajax_handler', 'data_received', ['deal_id' => $deal_id, 'field' => $field, 'value' => $value, 'source' => $source], $user_id);

        if ($source === 'project' && !current_user_can('manage_order') && !ISPAG_Projet_Repository::is_user_project_owner($deal_id))
        {
            self::$logger->log('ajax_handler', 'ERROR: User not authorized for project source', $user_id);
            wp_send_json_error('Not authorized');
        }

        if ($source === 'purchase' && !current_user_can('edit_supplier_order'))
        {
            self::$logger->log('ajax_handler', 'ERROR: User not authorized for purchase source', $user_id);
            wp_send_json_error('Not authorized');
        }

        global $wpdb;

        if ($source === 'project')
        {
            $allowed_fields = ['NumCommande', 'customer_order_id', 'Ingenieur', 'EnSoumission', 'ingenieur_projet', 'ingenieur_id', 'ObjetCommande', 'project_manager'];
            $table = $wpdb->prefix . 'achats_liste_commande';
        }
        elseif ($source === 'delivery')
        {
            $allowed_fields = ['City', 'AdresseDeLivraison', 'PersonneContact', 'num_tel_contact', 'DeliveryAdresse2', 'DeliveryAdresse3', 'NIP'];
            $table = $wpdb->prefix . 'achats_info_commande';
        }
        elseif ($source === 'purchase')
        {
            $allowed_fields = ['Fournisseur', 'RefCommande', 'ConfCmdFournisseur', 'TimestampDateCreation'];
            $table = $wpdb->prefix . 'achats_commande_liste_fournisseurs';
        }
        else
        {
            self::$logger->log('ajax_handler', 'ERROR: Unknown source - ' . $source, $user_id);
            wp_send_json_error(__('Unknown source', 'creation-reservoir'));
        }

        self::$logger->log_user_action('ajax_handler', 'table_and_fields_resolved', ['table' => $table, 'allowed_fields' => $allowed_fields], $user_id);

        if (!in_array($field, $allowed_fields))
        {
            self::$logger->log('ajax_handler', 'ERROR: Field not allowed - ' . $field, $user_id);
            wp_send_json_error(__('Field not allowed', 'creation-reservoir'));
        }

        if ($field == 'ingenieur_projet')
        {
            $field = 'ingenieur_id';
            $value = $wpdb->get_var($wpdb->prepare(
                "SELECT Id FROM {$wpdb->prefix}achats_fournisseurs WHERE Fournisseur = %s",
                $value
            ));
            self::$logger->log_db_change('ajax_handler', 'achats_fournisseurs', 'RESOLVE_ID', ['field' => $field, 'value' => $value], $user_id);
        }


        $display_value = null;
        if ($field == 'ingenieur_id')
        {
            $table_name = ISPAG_Crm_Company_Constants::TABLE_NAME;
            $display_value = $wpdb->get_var($wpdb->prepare(
                "SELECT company_name FROM {$table_name} WHERE viag_id = %s",
                $value
            ));
            self::$logger->log_db_change('ajax_handler', $table_name, 'FETCH_COMPANY_NAME', ['viag_id' => $value, 'display_value' => $display_value], $user_id);
        } elseif ($field == 'project_manager')
        {
            $user = get_userdata($value);
            $display_value = $user ? $user->display_name : '';

            self::$logger->log_db_change('ajax_handler', 'wp_users', 'FETCH_USER_DISPLAY_NAME', ['user_id' => $value, 'display_value' => $display_value], $user_id);
        }

        if ($source === 'project')
        {
            $updated = $wpdb->update($table, [$field => $value], ['hubspot_deal_id' => $deal_id]);
            self::$logger->log_db_change('ajax_handler', $table, 'UPDATE', ['field' => $field, 'value' => $value, 'hubspot_deal_id' => $deal_id, 'result' => $updated], $user_id);
        }
        elseif ($source === 'delivery')
        {
            $exists = $wpdb->get_var(
                $wpdb->prepare("SELECT COUNT(*) FROM $table WHERE hubspot_deal_id = %d", $deal_id)
            );
            if ($exists)
            {
                $updated = $wpdb->update($table, [$field => $value], ['hubspot_deal_id' => $deal_id]);
                self::$logger->log_db_change('ajax_handler', $table, 'UPDATE', ['field' => $field, 'value' => $value, 'hubspot_deal_id' => $deal_id, 'result' => $updated], $user_id);
            }
            else
            {
                $updated = $wpdb->insert($table, [
                    'hubspot_deal_id' => $deal_id,
                    $field => $value
                ]);
                self::$logger->log_db_change('ajax_handler', $table, 'INSERT', ['hubspot_deal_id' => $deal_id, 'field' => $field, 'value' => $value, 'result' => $updated], $user_id);
            }
        }
        elseif ($source === 'purchase')
        {
            self::$logger->log_user_action('ajax_handler', 'purchase_filter_applied', ['deal_id' => $deal_id, 'field' => $field, 'value' => $value], $user_id);
            $updated = apply_filters('ispag_inline_edit_purchase', false, [
                'deal_id' => $deal_id,
                'field' => $field,
                'value' => $value,
            ]);
            self::$logger->log_user_action('ajax_handler', 'purchase_filter_result', ['result' => $updated], $user_id);
        }

        if ($updated !== false)
        {
            self::$logger->log_user_action('ajax_handler', 'inline_edit_success', ['display_value' => $display_value, 'value' => $value], $user_id);
            wp_send_json_success([
                'message' => __('Updated', 'creation-reservoir'),
                'display_value' => $display_value ?? $value
            ]);
        }
        else
        {
            self::$logger->log('ajax_handler', 'ERROR: Update failed', $user_id);
            wp_send_json_error(__('Error while saving', 'creation-reservoir'));
        }
    }

    public static function update_delivery_date_from_purchase($null, $purchase_id, $purchase_article_ids, $timestamp)
    {
        $user_id = get_current_user_id();
        self::$logger->log_user_action('ajax_handler', 'update_delivery_date_from_purchase_start', ['purchase_id' => $purchase_id, 'timestamp' => $timestamp], $user_id);

        global $wpdb;

        $poid = isset($purchase_id) ? intval($purchase_id) : 0;
        if (!$poid)
        {
            self::$logger->log('ajax_handler', 'ERROR: Missing purchase_id', $user_id);
            return;
        }

        $supplier_id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT IdFournisseur FROM {$wpdb->prefix}achats_commande_liste_fournisseurs WHERE Id = %d",
                $poid
            )
        );

        self::$logger->log_db_change('ajax_handler', 'achats_commande_liste_fournisseurs', 'FETCH_SUPPLIER_ID', ['purchase_id' => $poid, 'supplier_id' => $supplier_id], $user_id);

        if (!$supplier_id)
        {
            self::$logger->log('ajax_handler', 'ERROR: No supplier found for purchase ' . $poid, $user_id);
            return;
        }

        $delivery_days = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT TransportTime FROM {$wpdb->prefix}achats_fournisseurs WHERE Id = %d",
                $supplier_id
            )
        );

        self::$logger->log_db_change('ajax_handler', 'achats_fournisseurs', 'FETCH_DELIVERY_DAYS', ['supplier_id' => $supplier_id, 'delivery_days' => $delivery_days], $user_id);

        if ($delivery_days === null)
        {
            self::$logger->log('ajax_handler', 'ERROR: No delivery days found for supplier ' . $supplier_id, $user_id);
            return;
        }

        $new_delivery_timestamp = $timestamp + ($delivery_days * DAY_IN_SECONDS);
        self::$logger->log_user_action('ajax_handler', 'delivery_date_calculated', ['timestamp' => $timestamp, 'delivery_days' => $delivery_days, 'new_delivery_timestamp' => $new_delivery_timestamp], $user_id);

        foreach ($purchase_article_ids as $purchase_article_id)
        {
            $commande_client_id = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT IdCommandeClient FROM {$wpdb->prefix}achats_articles_cmd_fournisseurs WHERE id = %d",
                    $purchase_article_id
                )
            );

            self::$logger->log_db_change('ajax_handler', 'achats_articles_cmd_fournisseurs', 'FETCH_COMMAND_CLIENT_ID', ['purchase_article_id' => $purchase_article_id, 'commande_client_id' => $commande_client_id], $user_id);

            if ($commande_client_id)
            {
                $result = $wpdb->update(
                    "{$wpdb->prefix}achats_details_commande",
                    [
                        'TimestampDateDeLivraison' => $timestamp,
                        'TimestampDateDeLivraisonFin' => $new_delivery_timestamp
                    ],
                    ['Id' => $commande_client_id],
                    ['%d', '%d'],
                    ['%d']
                );
                self::$logger->log_db_change('ajax_handler', 'achats_details_commande', 'UPDATE_DELIVERY_DATES', ['commande_client_id' => $commande_client_id, 'TimestampDateDeLivraison' => $timestamp, 'TimestampDateDeLivraisonFin' => $new_delivery_timestamp, 'result' => $result], $user_id);
            }
        }
    }

    public static function load_article_modal()
    {
        $user_id = get_current_user_id();
        $id = intval($_POST['article_id']);
        $source = sanitize_text_field($_POST['source'] ?? 'project');

        self::$logger->log_user_action('ajax_handler', 'load_article_modal_start', ['article_id' => $id, 'source' => $source], $user_id);

        if ($source === 'purchase')
        {
            apply_filters('ispag_render_purchase_article_modal', '', $id);
            self::$logger->log_user_action('ajax_handler', 'purchase_article_modal_rendered', ['article_id' => $id], $user_id);
            wp_die();
        }
        else
        {
            $article = apply_filters('ispag_get_article_by_id', null, $id);

            if (!$article)
            {
                self::$logger->log('ajax_handler', 'ERROR: Article not found - ' . $id, $user_id);
                wp_send_json_error(array('message' => __('Article not found', 'creation-reservoir') ));
            }

            self::$logger->log_db_change('ajax_handler', 'articles', 'FETCH_ARTICLE', ['article_id' => $id], $user_id);

            ob_start();
            include plugin_dir_path(__FILE__) . 'templates/modal-display-datas.php';
            $body_html = ob_get_clean();

            ob_start();
            include plugin_dir_path(__FILE__) . 'templates/modal-display-header.php';
            $header_html = ob_get_clean();

            self::$logger->log_user_action('ajax_handler', 'modal_content_generated', ['article_id' => $id], $user_id);

            wp_send_json_success(array(
                'header' => $header_html,
                'body' => $body_html
            ));
        }
    }

    public static function load_article_edit_modal()
    {
        $user_id = get_current_user_id();
        $id = intval($_POST['article_id']);
        $source = sanitize_text_field($_POST['source'] ?? 'project');
        $deal_id = intval($_POST['deal_id']) ?? null;

        self::$logger->log_user_action('ajax_handler', 'load_article_edit_modal_start', ['article_id' => $id, 'source' => $source], $user_id);

        ob_start();
            ?>
            <div class="ispag-modal-actions" style="margin-top: 30px; padding-bottom: 20px;">
            <button type="submit" class="ispag-btn ispag-btn-red-outlined" form="ispag-edit-article-form">
                <span class="dashicons dashicons-media-archive"></span> <?= __('Save', 'creation-reservoir') ?>
            </button>
            <button type="button" class="ispag-btn ispag-btn-secondary-outlined ispag-btn-cancel" >
                <?= __('Cancel', 'creation-reservoir') ?>
            </button>
        </div>
        <?php
        $btn_html = ob_get_clean();

        if ($source === 'purchase')
        {
            ob_start();
            
            apply_filters('ispag_render_purchase_article_modal_form', '', $id);
            $body_html = ob_get_clean(); 

            self::$logger->log_user_action('ajax_handler', 'purchase_article_edit_modal_rendered', ['article_id' => $id], $user_id);

            wp_send_json_success(array(
                'header' => '<h2>Modifier l\'article d\'achat</h2>',
                'body' => $body_html,
                'btn' => $btn_html,
            ));
        }
        else
        {
            $repo = new ISPAG_Article_Repository();
            $article = apply_filters('ispag_get_article_by_id', null, $id);

            if (!$article)
            {
                self::$logger->log('ajax_handler', 'ERROR: Article not found - ' . $id, $user_id);
                wp_send_json_error(array('message' => __('Article not found', 'creation-reservoir')));
            }

            self::$logger->log_db_change('ajax_handler', 'articles', 'FETCH_ARTICLE', ['article_id' => $id], $user_id);

            $groupes = $repo->get_groupes_by_deal($article->hubspot_deal_id);
            $standard_titles = $repo->get_standard_titles_by_type($article->Type);

            self::$logger->log_user_action('ajax_handler', 'article_data_fetched', ['article_id' => $id, 'groupes_count' => count($groupes), 'standard_titles_count' => count($standard_titles)], $user_id);

            ob_start();
            // include plugin_dir_path(__FILE__) . 'templates/modal-display-article-form-header.php';
            echo  esc_html(stripslashes($article->Article));
            $header_html = ob_get_clean();

            

            ob_start();
            self::render_article_modal_form($deal_id, 'project', $article, $groupes, $standard_titles);
            $body_html = ob_get_clean();

            self::$logger->log_user_action('ajax_handler', 'modal_edit_content_generated', ['article_id' => $id], $user_id);

            wp_send_json_success(array(
                'header' => $header_html,
                'body' => $body_html,
                'btn' => $btn_html,
            ));
        }
    }

    public static function load_article_create_modal() 
    {
        $user_id = get_current_user_id();
        $type_id = intval($_POST['type_id']);
        $deal_id = intval($_POST['deal_id']);
        $achat_id = intval($_POST['poid']);
        $source = sanitize_text_field($_POST['source'] ?? 'project');

        self::$logger->log_user_action('ajax_handler', 'load_article_create_modal_start', ['type_id' => $type_id, 'deal_id' => $deal_id, 'achat_id' => $achat_id, 'source' => $source], $user_id);

        if (!$type_id || (!$deal_id && !$achat_id))
        {
            self::$logger->log('ajax_handler', 'ERROR: Missing parameters (type_id, deal_id, or achat_id)', $user_id);
            echo '<p>' . __('Missing parameters.', 'creation-reservoir') . '</p>';
            wp_die();
        }

        $repo = new ISPAG_Article_Repository();
        $standard_titles = $repo->get_standard_titles_by_type($type_id);
        self::$logger->log_db_change('ajax_handler', 'articles', 'FETCH_STANDARD_TITLES', ['type_id' => $type_id, 'count' => count($standard_titles)], $user_id);

        // Définition du Header et des Boutons (personnalisables selon vos besoins)
        $header_html = __('New article', 'creation-reservoir');
                       
                        
        ob_start();
            ?>
            <div class="ispag-modal-actions" style="margin-top: 30px; padding-bottom: 20px;">
            <button type="submit" class="ispag-btn ispag-btn-red-outlined" form="ispag-edit-article-form">
                <span class="dashicons dashicons-media-archive"></span> <?= __('Save', 'creation-reservoir') ?>
            </button>
            <button type="button" class="ispag-btn ispag-btn-secondary-outlined ispag-btn-cancel" >
                <?= __('Cancel', 'creation-reservoir') ?>
            </button>
        </div>
        <?php
        $buttons_html = ob_get_clean();
        // Capture du contenu du formulaire (Body)
        ob_start();

        if ($source === 'purchase')
        {
            $article = (object) [
                'Id' => 0,
                'IdArticleStandard' => 0,
                'RefSurMesure' => '',
                'DescSurMesure' => '',
                'TimestampDateLivraisonConfirme' => 0,
                'Qty' => 1,
                'UnitPrice' => '',
                'discount' => 0,
                'Type' => '',
            ];

            apply_filters('ispag_render_purchase_article_modal_form', '', null, $article, $standard_titles);
            self::$logger->log_user_action('ajax_handler', 'purchase_article_create_modal_rendered', [], $user_id);
            
        }
        else
        {
            $article = (object) [
                'Id' => 0,
                'Article' => '',
                'Description' => '',
                'Type' => $type_id,
                'fournisseur_nom' => '',
                'TimestampDateDeLivraisonFin' => null,
                'TimestampDateDeLivraison' => null,
                'Groupe' => '',
                'IdArticleMaster' => 0,
                'Qty' => 1,
                'sales_price' => '',
                'discount' => 0,
                'DemandeAchatOk' => false,
                'DrawingApproved' => false,
                'Livre' => false,
                'invoiced' => false,
            ];

            $article->master_articles = $repo->get_article_and_group($deal_id);
            self::$logger->log_db_change('ajax_handler', 'articles', 'FETCH_MASTER_ARTICLES', ['deal_id' => $deal_id, 'count' => count($article->master_articles)], $user_id);

            $groupes = $repo->get_groupes_by_deal($deal_id);
            self::$logger->log_db_change('ajax_handler', 'articles', 'FETCH_GROUPES', ['deal_id' => $deal_id, 'count' => count($groupes)], $user_id);

            self::render_article_modal_form($deal_id, 'project', $article, $groupes, $standard_titles, true);
            self::$logger->log_user_action('ajax_handler', 'article_create_modal_rendered', [], $user_id);

            
        }

        $body_html = ob_get_clean();

        // Envoi de la réponse JSON structurée
        wp_send_json_success([
            'header'  => $header_html,
            'body'    => $body_html,
            'buttons' => $buttons_html
        ]);
    }

    public static function get_standard_article_info()
    {
        $user_id = get_current_user_id();
        $title_id = sanitize_text_field($_POST['id']);
        $type = intval($_POST['type']);

        self::$logger->log_user_action('ajax_handler', 'get_standard_article_info_start', ['title_id' => $title_id, 'type' => $type], $user_id);

        if (empty($title_id) || !$type)
        {
            self::$logger->log('ajax_handler', 'ERROR: Invalid parameters (title_id or type)', $user_id);
            wp_send_json_error(['message' => 'Invalid parameters.']);
        }

        $repo = new ISPAG_Article_Repository();
        $article = $repo->get_standard_article_by_title($title_id, $type);
        self::$logger->log_db_change('ajax_handler', 'articles', 'FETCH_STANDARD_ARTICLE', ['title_id' => $title_id, 'type' => $type, 'article' => $article], $user_id);

        wp_send_json_success([
            'title' => $title_id,
            'type' => $type,
            'article' => $article,
        ]);
    }

    public static function handle_save_article_from_pdf($html, $article_id, $post_data)
    {
        $user_id = get_current_user_id();
        self::$logger->log_user_action('ajax_handler', 'handle_save_article_from_pdf_start', ['article_id' => $article_id, 'post_data' => $post_data], $user_id);
        return self::handle_saved_article($article_id, $post_data);
    }

    public static function handle_saved_article($article_id, $post_data)
    {
        $user_id = get_current_user_id();
        self::$logger->log_user_action('ajax_handler', 'handle_saved_article_start', ['article_id' => $article_id, 'post_data' => $post_data], $user_id);

        global $wpdb;

        $supplier_name = sanitize_text_field($post_data['supplier'] ?? '');
        $supplier_id = null;
        $deal_id = intval($post_data['deal_id'] ?? 0);

        if (!empty($supplier_name))
        {
            $supplier_id = $wpdb->get_var($wpdb->prepare(
                "SELECT Id FROM {$wpdb->prefix}achats_fournisseurs WHERE Fournisseur = %s",
                $supplier_name
            ));
            self::$logger->log_db_change('ajax_handler', 'achats_fournisseurs', 'FETCH_SUPPLIER_ID', ['supplier_name' => $supplier_name, 'supplier_id' => $supplier_id], $user_id);

            if (!$supplier_id)
            {
                self::$logger->log('ajax_handler', 'ERROR: Supplier not found - ' . $supplier_name, $user_id);
                return ['success' => false, 'id' => null, 'message' => 'Fournisseur introuvable'];
            }
        }

        $data = [
            'Article' => sanitize_text_field($post_data['article_title'] ?? ''),
            'Description' => wp_kses_post($post_data['description'] ?? ''),
            'sales_price' => floatval($post_data['sales_price'] ?? 0),
            'discount' => floatval($post_data['discount'] ?? 0),
            'Qty' => intval($post_data['qty'] ?? 1),
            'IdArticleStandard' => intval($post_data['IdArticleStandard'] ?? 0),
            'Groupe' => sanitize_text_field($post_data['group'] ?? ' '),
            'IdArticleMaster' => intval($post_data['master_article'] ?? 0),
            'TimestampDateDeLivraisonFin' => !empty($post_data['date_eta']) ? strtotime($post_data['date_eta']) : null,
            'TimestampDateDeLivraison' => !empty($post_data['date_depart']) ? strtotime($post_data['date_depart']) : null,
            'DemandeAchatOk' => isset($post_data['DemandeAchatOk']) ? 1 : null,
            'DrawingApproved' => isset($post_data['DrawingApproved']) ? 1 : null,
            'Livre' => isset($post_data['Livre']) ? 1 : null,
            'invoiced' => isset($post_data['invoiced']) ? time() : null,
            'is_manual_price' => isset($_POST['is_manual_price']) ? 1 : 0,
        ];

        self::$logger->log_user_action('ajax_handler', 'article_data_prepared', ['data' => $data], $user_id);

        if ($supplier_id !== null)
        {
            $data['IdFournisseur'] = $supplier_id;
        }

        if (!$article_id)
        {
            self::$logger->log_user_action('ajax_handler', 'creating_new_article', [], $user_id);

            $type = intval($post_data['type'] ?? 0);
            $deal_id = intval($post_data['deal_id'] ?? 0);

            if (!$type || !$deal_id)
            {
                self::$logger->log('ajax_handler', 'ERROR: Missing type or deal_id', $user_id);
                return ['success' => false, 'id' => null, 'message' => 'Type ou deal_id manquant'];
            }

            $data['Type'] = $type;
            $data['hubspot_deal_id'] = $deal_id;

            self::$logger->log_user_action('ajax_handler', 'article_data_with_type_and_deal', ['type' => $type, 'deal_id' => $deal_id], $user_id);

            $inserted = $wpdb->insert($wpdb->prefix . 'achats_details_commande', $data);
            self::$logger->log_db_change('ajax_handler', 'achats_details_commande', 'INSERT', ['data' => $data, 'result' => $inserted], $user_id);

            if ($inserted)
            {


                
                self::$logger->log_user_action('ajax_handler', 'article_created_successfully', ['insert_id' => $wpdb->insert_id], $user_id);
                return ['success' => true, 'id' => $wpdb->insert_id];
            }
            else
            {
                self::$logger->log('ajax_handler', 'ERROR: Insert failed', $user_id);
                return ['success' => false, 'id' => null, 'message' => 'Erreur lors de la création'];
            }
        }

        //Si l'article est enregistré, on peut mettre à jour les données de soudure si disponible
        
        if(class_exists('ISPAG_Tank_Welding_Site_Sheet')){

            $welding_sheet = new ISPAG_Tank_Welding_Site_Sheet();
            
            $welding_datas = [
                'room_height' => $post_data['room_height'],
                'door_width' => $post_data['door_width']
            ];
            if(isset($welding_datas)){
                // self::$logger->log_user_action('ajax_handler', 'isset($post_data[\'room_height\'])', ['room_height' => $post_data['room_height']], $user_id);
                $welding_sheet->save_welding_sheet_data($deal_id, $welding_datas);
            }
            // if(isset($post_data['door_width'])){
            //     // self::$logger->log_user_action('ajax_handler', 'isset($post_data[\'door_width\'])', ['door_width' => $post_data['door_width']], $user_id);
            //     $welding_sheet->save_welding_sheet_data($deal_id, $post_data['door_width']);
            // }
        }
        
        $updated = $wpdb->update($wpdb->prefix . 'achats_details_commande', $data, ['Id' => $article_id]);
        self::$logger->log_db_change('ajax_handler', 'achats_details_commande', 'UPDATE', ['article_id' => $article_id, 'data' => $data, 'result' => $updated], $user_id);

        return [
            'success' => ($updated !== false),
            'id' => $article_id
        ];
    }

    public static function save_article()
    {
        $user_id = get_current_user_id();
        self::$logger->log_user_action('ajax_handler', 'save_article_start', [], $user_id);

        global $wpdb;

        $id = isset($_POST['article_id']) ? intval($_POST['article_id']) : 0;

        if (!empty($_POST['deal_id']) && intval($_POST['deal_id']) > 0)
        {
            $result = self::handle_saved_article($id, $_POST);
            self::$logger->log_user_action('ajax_handler', 'article_saved_from_project', ['article_id' => $id, 'result' => $result], $user_id);

            if (empty($result) || !$result['success'])
            {
                self::$logger->log('ajax_handler', 'ERROR: Save failed - ' . ($result['message'] ?? 'Unknown error'), $user_id);
                wp_send_json_error(['message' => $result['message'] ?? 'Erreur lors de la sauvegarde']);
            }

            $message = $id ? 'Article updated' : 'Article created';

            if (!empty($_POST['change_notes']))
            {
                $change_notes = json_decode(stripslashes($_POST['change_notes']), true);

                if (is_array($change_notes))
                {
                    $repository = new ISPAG_Note_Repository();
                    $note_handler = new ISPAG_Note_Ajax_Handler($repository);

                    foreach ($change_notes as $note)
                    {
                        $content = sprintf(
                            "<strong>Modification sur l'article #%d</strong><br>Champ : %s<br>Valeur : %s → %s<br><strong>Raison : %s</strong>",
                            $id,
                            esc_html($note['label']),
                            esc_html($note['old_value']),
                            esc_html($note['new_value']),
                            esc_html($note['reason'])
                        );

                        $data_for_note = array(
                            'deal_id' => $_POST['deal_id'] ?? null,
                            'content' => $content,
                            'title' => '🛠️ Modif. Technique : ' . $note['label'],
                            'type' => 'NOTE',
                            'is_task' => 0,
                            'created_by' => get_current_user_id()
                        );

                        $note_handler->handle_save_note($data_for_note, null, null, true);
                        self::$logger->log_db_change('ajax_handler', ISPAG_Note_Manager::TABLE_NOTE, 'INSERT_CHANGE_NOTE', ['article_id' => $id, 'note' => $data_for_note], $user_id);
                    }
                }
            }

            wp_send_json_success([
                'message' => $message,
                'article_id' => $result['id']
            ]);
        }
        elseif (!empty($_POST['poid']) && intval($_POST['poid']) > 0)
        {
            $result = apply_filters('ispag_article_saved_from_purchase', null, $id, $_POST);
            self::$logger->log_user_action('ajax_handler', 'article_saved_from_purchase', ['article_id' => $id, 'result' => $result], $user_id);

            if (!empty($result) && !$result['success'])
            {
                self::$logger->log('ajax_handler', 'ERROR: Save failed - ' . ($result['message'] ?? 'Unknown error'), $user_id);
                wp_send_json_error(['message' => $result['message']]);
            }

            wp_send_json_success(['message' => $result['message']]);
        }
    }

    public static function reload_article_row()
    {
        $user_id = get_current_user_id();
        $id = intval($_POST['article_id']);
        $is_secondary = isset($_POST['is_secondary']) ? intval($_POST['is_secondary']) : false;
        $is_purchase = !empty($_POST['is_purchase']) && $_POST['is_purchase'] === 'true';

        self::$logger->log_user_action('ajax_handler', 'reload_article_row_start', ['article_id' => $id, 'is_secondary' => $is_secondary, 'is_purchase' => $is_purchase], $user_id);

        if ($is_purchase)
        {
            ob_start();
            echo apply_filters('ispag_render_article_block', '', $id);
            $html = ob_get_clean();
            echo $html;
            self::$logger->log_user_action('ajax_handler', 'purchase_article_row_reloaded', ['article_id' => $id], $user_id);
            wp_die();
        }
        else
        {
            $article = apply_filters('ispag_get_article_by_id', null, $id);
            self::$logger->log_db_change('ajax_handler', 'articles', 'FETCH_ARTICLE', ['article_id' => $id, 'result' => !empty($article)], $user_id);

            if (!$article)
            {
                self::$logger->log('ajax_handler', 'ERROR: Article not found - ' . $id, $user_id);
                wp_send_json_error(['message' => 'Article introuvable']);
            }

            $article_detail = new ISPAG_Detail_Page();
            ob_start();
            echo $article_detail->render_article_block($article, $is_secondary);
            $html = ob_get_clean();
            echo $html;
            self::$logger->log_user_action('ajax_handler', 'article_row_reloaded', ['article_id' => $id], $user_id);
            wp_die();
        }
    }

    public static function open_new_article_modal()
    {
        $user_id = get_current_user_id();
        self::$logger->log_user_action('ajax_handler', 'open_new_article_modal_start', [], $user_id);

        global $wpdb;
        $table_prestation = $wpdb->prefix . 'achats_type_prestations';
        $types = $wpdb->get_results("SELECT Id, type, prestation, color, image FROM $table_prestation ORDER BY sort ASC");
        self::$logger->log_db_change('ajax_handler', $table_prestation, 'SELECT_TYPES', ['count' => count($types)], $user_id);

        $user = wp_get_current_user();
        $roles = $user->roles;
        $isAdmin = in_array('administrator', $roles);

        self::$logger->log_user_action('ajax_handler', 'user_roles_checked', ['is_admin' => $isAdmin], $user_id);

        echo '<p class="ispag-modal-subtitle">' . __('Select article type to continue', 'creation-reservoir') . '</p>';
        if (empty($types)) {
            echo '<p class="ispag-notice">' . esc_html__('No service types defined (table achats_type_prestations is empty).', 'creation-reservoir') . '</p>';
        }
        echo '<div class="ispag-type-grid">';

        foreach ($types as $type)
        {
            $image_attributes = wp_get_attachment_image_src($type->image, 'thumbnail');
            $image_url = $image_attributes ? $image_attributes[0] : '';

            self::$logger->log_user_action('ajax_handler', 'type_card_rendered', ['type_id' => $type->Id, 'type' => $type->type, 'has_image' => !empty($image_url)], $user_id);

            echo '<div class="ispag-type-card" data-id="' . esc_attr($type->Id) . '" data-card-titel="' . esc_html__($type->type, 'creation-reservoir') . '" data-selector-type="product_type" data-is-admin="' . $isAdmin . '">';
            $bg = preg_match('/^#[0-9a-fA-F]{3,8}$/', (string) $type->color) ? ' style="background:' . esc_attr($type->color) . ';"' : '';
            echo '  <div class="ispag-type-image-wrapper"' . $bg . '>';
            if ($image_url)
            {
                echo '    <img src="' . esc_url($image_url) . '" alt="' . esc_attr($type->type) . '" class="ispag-type-img">';
            }
            else
            {
                $icons = ['Product' => 'products', 'Isol' => 'shield', 'Welding' => 'hammer', 'div' => 'archive'];
                echo '    <span class="dashicons dashicons-' . esc_attr($icons[$type->prestation] ?? 'archive') . '"></span>';
            }
            echo '  </div>';
            echo '  <span class="ispag-type-label">' . esc_html__($type->type, 'creation-reservoir') . '</span>';
            echo '</div>';
        }

        echo '</div>';
        echo '<div id="new-article-form-container" style="margin-top:20px;"></div>';

        self::$logger->log_user_action('ajax_handler', 'new_article_modal_rendered', [], $user_id);
        wp_die();
    }

    private static function render_article_modal_form($deal_id = null, $source = 'project', $article = null, $groupes = null, $standard_titles = null, $is_new = false)
    {
        $user_id = get_current_user_id();
        $user_can = current_user_can('manage_order');
        $id_attr = $is_new ? '' : ' data-article-id="' . intval($article->Id) . '"';
        $id_attr .= $deal_id ? ' data-deal-id="' . intval($deal_id) . '"' : '';

        self::$logger = ISPAG_Logger::get_instance();
        self::$logger->log_user_action('ajax_handler', 'render_article_modal_form_start', ['article_id' => $article->Id ?? 0, 'is_new' => $is_new], $user_id);

        include plugin_dir_path(__FILE__) . 'templates/modal-display-article-form.php';
    }

    public static function delete_article()
    {
        $user_id = get_current_user_id();
        self::$logger->log_user_action('ajax_handler', 'delete_article_start', [], $user_id);

        global $wpdb;

        $id = isset($_POST['article_id']) ? intval($_POST['article_id']) : 0;
        $source = sanitize_text_field($_POST['source'] ?? 'project');

        self::$logger->log_user_action('ajax_handler', 'delete_article_params', ['article_id' => $id, 'source' => $source], $user_id);

        if (!$id)
        {
            self::$logger->log('ajax_handler', 'ERROR: Missing or invalid article_id', $user_id);
            wp_send_json_error(['message' => 'ID manquant ou invalide']);
        }

        if ($source == 'purchase')
        {
            $deleted = $wpdb->delete($wpdb->prefix . 'achats_articles_cmd_fournisseurs', ['Id' => $id], ['%d']);
            $deleted && $wpdb->delete($wpdb->prefix . 'achats_historique', ['Historique' => $id], ['%d']);

            self::$logger->log_db_change('ajax_handler', 'achats_articles_cmd_fournisseurs', 'DELETE', ['article_id' => $id, 'result' => $deleted], $user_id);

            if ($deleted === false)
            {
                self::$logger->log('ajax_handler', 'ERROR: Delete failed for purchase article - ' . $id, $user_id);
                wp_send_json_error(['message' => 'Erreur lors de la suppression']);
            }

            self::$logger->log_user_action('ajax_handler', 'purchase_article_deleted', ['article_id' => $id], $user_id);
            wp_send_json_success(['message' => 'Article deleted']);
        } 
        else
        {
            $deleted = $wpdb->delete($wpdb->prefix . 'achats_details_commande', ['Id' => $id], ['%d']);
            $deleted && $wpdb->delete($wpdb->prefix . 'achats_historique', ['Historique' => $id], ['%d']);
            $deleted && do_action('ispag_delete_tank_with_article_id', null, $id);
            $deleted && do_action('ispag_delete_exchanger_data', null, $id);

            self::$logger->log_db_change('ajax_handler', 'achats_details_commande', 'DELETE', ['article_id' => $id, 'result' => $deleted], $user_id);

            if ($deleted === false)
            {
                self::$logger->log('ajax_handler', 'ERROR: Delete failed for project article - ' . $id, $user_id);
                wp_send_json_error(['message' => 'Erreur lors de la suppression']);
            }

            self::$logger->log_user_action('ajax_handler', 'project_article_deleted', ['article_id' => $id], $user_id);
            wp_send_json_success(['message' => 'Article deleted']);
        }
    }

    public static function bulk_update_articles()
    {
        $user_id = get_current_user_id();
        self::$logger->log_user_action('ajax_handler', 'bulk_update_articles_start', [], $user_id);

        check_ajax_referer('ispag_nonce');

        $article_ids = $_POST['articles'] ?? [];
        $deal_id = $_POST['deal_id'] ?? [];

        if (!current_user_can('manage_order') || empty($article_ids))
        {
            self::$logger->log('ajax_handler', 'ERROR: Unauthorized or empty selection', $user_id);
            wp_send_json_error(['message' => __('Unauthorized or empty selection', 'creation-reservoir')]);
        }

        self::$logger->log_user_action('ajax_handler', 'bulk_update_authorized', ['article_ids' => $article_ids, 'deal_id' => $deal_id], $user_id);

        global $wpdb;
        $updates = [];
        $ids_raw = $_POST['articles'] ?? '';
        $ids = array_filter(array_map('intval', explode(',', $ids_raw)));

        self::$logger->log_user_action('ajax_handler', 'articles_parsed', ['ids' => $ids], $user_id);

        $in_clause = implode(',', $ids);

        if ($_POST['date_depart'])
        {
            $timestamp = intval(strtotime($_POST['date_depart']));
            $updates[] = "TimestampDateDeLivraison = '" . $timestamp . "'";
            self::$logger->log_user_action('ajax_handler', 'date_depart_added', ['timestamp' => $timestamp], $user_id);
        }

        if ($_POST['date_eta'])
        {
            $timestamp = intval(strtotime($_POST['date_eta']));
            $updates[] = "TimestampDateDeLivraisonFin = '" . $timestamp . "'";
            self::$logger->log_user_action('ajax_handler', 'date_eta_added', ['timestamp' => $timestamp], $user_id);
        }

        if (!empty($_POST['livre_date']))
        {
            $timestamp = strtotime($_POST['livre_date']);
            if ($timestamp)
            {
                $updates[] = "Livre = " . intval($timestamp);
                $updates[] = "TimestampDateDeLivraisonFin = " . intval($timestamp);
                do_action('ispag_achat_set_article_as_delivered', '', $ids, intval($timestamp));
                self::$logger->log_user_action('ajax_handler', 'livre_date_added_and_delivered', ['timestamp' => $timestamp], $user_id);
            }
        }

        if (!empty($_POST['invoiced_date']))
        {
            $timestamp = strtotime($_POST['invoiced_date']);
            if ($timestamp)
            {
                $updates[] = "invoiced = " . intval($timestamp);
                self::$logger->log_user_action('ajax_handler', 'invoiced_date_added', ['timestamp' => $timestamp], $user_id);
            }
        }

        if (!empty($_POST['discount']))
        {
            $discount_value = floatval($_POST['discount']);
            $applied_discount = number_format($discount_value, 2, '.', '');
            $updates[] = "discount = '" . esc_sql($applied_discount) . "'";
            self::$logger->log_user_action('ajax_handler', 'discount_added', ['discount' => $applied_discount], $user_id);
        }

        if (isset($_POST['demande_ok']))
        {
            $updates[] = "DemandeAchatOk = " . intval($_POST['demande_ok']);
            self::$logger->log_user_action('ajax_handler', 'demande_ok_added', ['demande_ok' => $_POST['demande_ok']], $user_id);
        }

        if (isset($_POST['drawing_ok']))
        {
            $updates[] = "DrawingApproved = " . intval($_POST['drawing_ok']);
            self::$logger->log_user_action('ajax_handler', 'drawing_ok_added', ['drawing_ok' => $_POST['drawing_ok']], $user_id);
        }

        if (!empty($updates))
        {
            $query = "UPDATE {$wpdb->prefix}achats_details_commande SET " . implode(', ', $updates) . " WHERE Id IN ($in_clause)";
            $result = $wpdb->query($query);
            self::$logger->log_db_change('ajax_handler', 'achats_details_commande', 'BULK_UPDATE', ['query' => $query, 'result' => $result], $user_id);
        }

        do_action('isag_run_auto_update', $deal_id);
        self::$logger->log_user_action('ajax_handler', 'auto_update_triggered', ['deal_id' => $deal_id], $user_id);

        $response_data = [
            'message' => __('Bulk update applied successfully', 'creation-reservoir'),
            'discount' => $applied_discount ?? null,
            'datas' => $_POST,
        ];

        if (ob_get_length()) ob_clean();
        wp_send_json_success($response_data);
    }

    public static function ispag_duplicate_article()
    {
        $user_id = get_current_user_id();
        self::$logger->log_user_action('ajax_handler', 'ispag_duplicate_article_start', [], $user_id);

        check_ajax_referer('ispag_nonce', '_ajax_nonce');

        global $wpdb;
        $id = intval($_POST['article_id']);
        $table = $wpdb->prefix . 'achats_details_commande';

        self::$logger->log_user_action('ajax_handler', 'duplicate_article_params', ['article_id' => $id], $user_id);

        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE Id = %d", $id), ARRAY_A);
        self::$logger->log_db_change('ajax_handler', $table, 'FETCH_ARTICLE', ['article_id' => $id, 'result' => !empty($row)], $user_id);

        if (!$row)
        {
            self::$logger->log('ajax_handler', 'ERROR: Article not found - ' . $id, $user_id);
            wp_send_json_error("Article introuvable");
        }

        unset($row['Id']);
        $row['sales_price'] = 0;
        $row['DemandeAchatOk'] = null;

        $inserted = $wpdb->insert($table, $row);
        self::$logger->log_db_change('ajax_handler', $table, 'INSERT_DUPLICATE', ['data' => $row, 'result' => $inserted], $user_id);

        if ($inserted)
        {
            $old_article_id = $id;
            $new_article_id = $wpdb->insert_id;
            
            // Duplication des ballons
            do_action('ispag_duplicate_tank_data', $old_article_id, $new_article_id);
            self::$logger->log_user_action('ajax_handler', 'tank_data_duplicated', ['old_article_id' => $old_article_id, 'new_article_id' => $new_article_id], $user_id);
            
            // Duplication des échangeurs via le hook
            do_action('ispag_duplicate_exchanger_data', $old_article_id, $new_article_id);
            self::$logger->log_user_action('ajax_handler', 'exchanger_data_duplicated', ['old_article_id' => $old_article_id, 'new_article_id' => $new_article_id], $user_id);

            if (ob_get_length()) ob_clean();
            wp_send_json_success($wpdb->insert_id);
        }
        else
        {
            self::$logger->log('ajax_handler', 'ERROR: Duplicate failed', $user_id);
            wp_send_json_error("Erreur lors de la duplication");
        }
    }
}