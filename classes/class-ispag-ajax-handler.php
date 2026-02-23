<?php


class ISPAG_Ajax_Handler {
    

    public static function init() {
        

        add_action('wp_ajax_ispag_inline_edit_field', [self::class, 'inline_edit_field']);
        add_action('wp_ajax_ispag_load_article_modal', [self::class, 'load_article_modal']);
        add_action('wp_ajax_ispag_load_article_edit_modal', [self::class, 'load_article_edit_modal']);
        add_action('wp_ajax_ispag_get_standard_article_data', [self::class, 'get_standard_article_data']);
        add_action('wp_ajax_ispag_get_standard_article_info', [self::class, 'get_standard_article_info']);
        add_action('wp_ajax_ispag_save_article', [self::class, 'save_article']);
        add_action('wp_ajax_ispag_reload_article_row', [self::class, 'reload_article_row']);
        add_action('wp_ajax_ispag_open_new_article_modal', [self::class, 'open_new_article_modal']);
        add_action('wp_ajax_ispag_load_article_create_modal', [self::class, 'load_article_create_modal']);
        add_action('wp_ajax_ispag_delete_article', [self::class, 'delete_article']);
        add_action('wp_ajax_ispag_bulk_update_articles', [self::class, 'bulk_update_articles']);
        add_action('wp_ajax_ispag_duplicate_article', [self::class, 'ispag_duplicate_article']);

        add_action('ispag_article_saved_from_project', [self::class, 'handle_saved_article'], 10, 2);
        add_filter('ispag_article_save_pdf', [self::class, 'handle_save_article_from_pdf'], 10, 3);
        
    }

    public static function inline_edit_field() {
        $deal_id = intval($_POST['deal_id']);
        $field   = sanitize_text_field($_POST['field']);
        $value   = sanitize_text_field($_POST['value']);
        $source  = sanitize_text_field($_POST['source'] ?? 'delivery');

        if (
            $source === 'project' && 
            !current_user_can('manage_order') &&
            !ISPAG_Projet_Repository::is_user_project_owner($deal_id)
        ) {
            wp_send_json_error('Non autorisé');
        }

        if (
            $source === 'purchase' &&
            !current_user_can('edit_supplier_order')
        ) {
            wp_send_json_error('Non autorisé');
        }


        global $wpdb;

        // Définir selon la source
        if ($source === 'project') {
            $allowed_fields = ['NumCommande', 'customer_order_id', 'Ingenieur', 'EnSoumission', 'ingenieur_projet'];
            $table = $wpdb->prefix . 'achats_liste_commande';
        } elseif ($source === 'delivery') {
            $allowed_fields = ['City', 'AdresseDeLivraison', 'PersonneContact', 'num_tel_contact', 'DeliveryAdresse2', 'Postal code'];
            $table = $wpdb->prefix . 'achats_info_commande';
        } elseif ($source === 'purchase') {
            $allowed_fields = ['Fournisseur', 'RefCommande', 'ConfCmdFournisseur', 'TimestampDateCreation'];
            $table = $wpdb->prefix . 'achats_commande_liste_fournisseurs';

        } else {
            wp_send_json_error(__('Unknown source', 'creation-reservoir'));
        }

        if (!in_array($field, $allowed_fields)) {
            wp_send_json_error(__('Field not allowed', 'creation-reservoir'));
        }
        if($field == 'ingenieur_projet'){
            $field = 'ingenieur_id';
            $value = $wpdb->get_var($wpdb->prepare(
                "SELECT Id FROM {$wpdb->prefix}achats_fournisseurs WHERE Fournisseur = %s",
                $value
            ));
            // error_log('inline_edit_field : ' . $value);
        }

        // UPDATE ou INSERT selon source
        if ($source === 'project') {
            $updated = $wpdb->update($table, [$field => $value], ['hubspot_deal_id' => $deal_id]);
        } elseif ($source === 'delivery') {
            $exists = $wpdb->get_var(
                $wpdb->prepare("SELECT COUNT(*) FROM $table WHERE hubspot_deal_id = %d", $deal_id)
            );

            if ($exists) {
                $updated = $wpdb->update($table, [$field => $value], ['hubspot_deal_id' => $deal_id]);
            } else {
                $updated = $wpdb->insert($table, [
                    'hubspot_deal_id' => $deal_id,
                    $field => $value
                ]);
            }
        } elseif ($source === 'purchase') {
            $updated = apply_filters('ispag_inline_edit_purchase', false, [
                'deal_id' => $deal_id,
                'field'   => $field,
                'value'   => $value,
            ]);
        }

        if ($updated !== false) {
            wp_send_json_success(['message' => __('Updated', 'creation-reservoir')]);
        } else {
            wp_send_json_error(__('Error while saving', 'creation-reservoir'));
        }
    }

    public static function load_article_modal() {
        $id = intval($_POST['article_id']);
        $source = sanitize_text_field($_POST['source'] ?? 'project');
        if($source === 'purchase'){
            
            apply_filters('ispag_render_purchase_article_modal', '', $id);
            wp_die();
        } else {
            // $repo = new ISPAG_Article_Repository();
            // $article = $repo->get_article_by_id($id);
        $article = apply_filters('ispag_get_article_by_id', null, $id);

            if (!$article) {
                echo '<p>Article introuvable.</p>';
                wp_die();
            }
            include plugin_dir_path(__FILE__) . 'templates/modal-display-datas.php';
            wp_die();
        }
        
        
    }

    public static function load_article_edit_modal() {
        $id = intval($_POST['article_id']);
        $source = sanitize_text_field($_POST['source'] ?? 'project');
        if($source === 'purchase'){
            apply_filters('ispag_render_purchase_article_modal_form', '', $id);
            wp_die();
        } else {
            $repo = new ISPAG_Article_Repository();
            // $article = $repo->get_article_by_id($id);

            $article = apply_filters('ispag_get_article_by_id', null, $id);

            if (!$article) {
                echo '<p>Article introuvable.</p>';
                wp_die();
            }
 
            $groupes = $repo->get_groupes_by_deal($article->hubspot_deal_id);
            $standard_titles = $repo->get_standard_titles_by_type($article->Type);

            self::render_article_modal_form($article, $groupes, $standard_titles);
            wp_die();
        }
    }
    public static function load_article_create_modal() { 
        $type_id = intval($_POST['type_id']);
        $deal_id = intval($_POST['deal_id']);
        $achat_id = intval($_POST['poid']);
        $source = sanitize_text_field($_POST['source'] ?? 'project');

        if (!$type_id || (!$deal_id && !$achat_id)) {
            echo '<p>' . __('Missing parameters.', 'creation-reservoir') . '</p>';
            wp_die();
        }

        $repo = new ISPAG_Article_Repository();
        $standard_titles = $repo->get_standard_titles_by_type($type_id);

        if($source === 'purchase'){
            // Créer un "article vide" avec les valeurs par défaut
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
            wp_die();
        } else {
            

            // Créer un "article vide" avec les valeurs par défaut
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

            
            $groupes = $repo->get_groupes_by_deal($deal_id);

            self::render_article_modal_form($article, $groupes, $standard_titles, true);

            wp_die();
        }
    }




    public static function get_standard_article_data() {
        $titre = sanitize_text_field($_POST['titre']);
        $type = intval($_POST['type']);

        if (!$titre || !$type) wp_send_json_error('Paramètres manquants');

        global $wpdb;
        $table_standard = $wpdb->prefix . 'achats_articles';

        $data = $wpdb->get_row(
            $wpdb->prepare("SELECT description_ispag, sales_price, IdFournisseur FROM $table_standard WHERE TitreArticle = %s AND TypeArticle = %d LIMIT 1", $titre, $type),
            ARRAY_A
        );

        if (!$data) {
            wp_send_json_error('Article non trouvé');
        }

        wp_send_json_success($data);
    }

    public static function get_standard_article_info() {
        $title_id = sanitize_text_field($_POST['id']);
        $type = intval($_POST['type']);

        if (empty($title_id) || !$type) {
            wp_send_json_error(['message' => 'Paramètres invalides.']);
        }

        // // 🔄 Convertit les caractères spéciaux en entités HTML
        // $encoded_title = htmlentities($title, ENT_NOQUOTES, 'UTF-8');

        $repo = new ISPAG_Article_Repository();
        $article = $repo->get_standard_article_by_title($title_id, $type);

        wp_send_json_success([
            'title' => $title_id, // debug
            'type' => $type,
            'article' => $article,
            
        ]);
    }
    public static function handle_save_article_from_pdf($html, $article_id, $post_data){
        // error_log("in handle_save_article_from_pdf : " . json_encode($post_data));
        return self::handle_saved_article($article_id, $post_data);
    }

    public static function handle_saved_article($article_id, $post_data) {
        global $wpdb;
        // error_log("in handle_saved_article : " . json_encode($post_data));

        $supplier_name = sanitize_text_field($post_data['supplier'] ?? '');
        $supplier_id = null;

        if (!empty($supplier_name)) {
            $supplier_id = $wpdb->get_var($wpdb->prepare(
                "SELECT Id FROM {$wpdb->prefix}achats_fournisseurs WHERE Fournisseur = %s",
                $supplier_name
            ));

            if (!$supplier_id) {
                // error_log("Fournisseur '$supplier_name' introuvable.");
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
        ];

        if ($supplier_id !== null) {
            $data['IdFournisseur'] = $supplier_id;
        }

        if (!$article_id) {
            // Création
            $type = intval($post_data['type'] ?? 0);
            $deal_id = intval($post_data['deal_id'] ?? 0);

            if (!$type || !$deal_id) {
                return ['success' => false, 'id' => null, 'message' => 'Type ou deal_id manquant'];
            }

            $data['Type'] = $type;
            $data['hubspot_deal_id'] = $deal_id;

            $inserted = $wpdb->insert($wpdb->prefix . 'achats_details_commande', $data);

            if ($inserted) {
                return ['success' => true, 'id' => $wpdb->insert_id];
            } else {
                return ['success' => false, 'id' => null, 'message' => 'Erreur lors de la création'];
            }
        }

        // Mise à jour
        $updated = $wpdb->update($wpdb->prefix . 'achats_details_commande', $data, ['Id' => $article_id]);

        return [
            'success' => ($updated !== false),
            'id' => $article_id
        ];
    }



    public static function save_article() {
        global $wpdb;

        $id = isset($_POST['article_id']) ? intval($_POST['article_id']) : 0;

        // Détection contexte projet ou achat
        if (!empty($_POST['deal_id']) && intval($_POST['deal_id']) > 0) {
            // On prépare les données et on fait la création ou mise à jour
            $result = self::handle_saved_article($id, $_POST);
            if (empty($result) || !$result['success']) {
                wp_send_json_error(['message' => $result['message'] ?? 'Erreur lors de la sauvegarde']);
            }
            $message = $id ? 'Article mis à jour' : 'Article créé';
            wp_send_json_success([
                'message' => $message,
                'article_id' => $result['id']
            ]);
        } elseif (!empty($_POST['poid']) && intval($_POST['poid']) > 0) {
            $result = apply_filters('ispag_article_saved_from_purchase', null, $id, $_POST);
            if (!empty($result) && !$result['success']) {
                wp_send_json_error(['message' => $result['message']]);
            }
            wp_send_json_success(['message' => $result['message']]);
        }

        
    }




    public static function reload_article_row() {
        $id = intval($_POST['article_id']);
        $is_secondary = isset($_POST['is_secondary']) ? intval($_POST['is_secondary']) : false; 
        $is_purchase = !empty($_POST['is_purchase']) && $_POST['is_purchase'] === 'true';
        

        

        // Tu réutilises ici le même HTML que celui généré dans la liste initiale
        if($is_purchase){
            apply_filters('ispag_render_article_block', '', $id);
            wp_die();
        } else{
            // $repo = new ISPAG_Article_Repository();
            // $article = $repo->get_article_by_id($id);

            $article = apply_filters('ispag_get_article_by_id', null, $id);

            if (!$article) { 
                wp_send_json_error(['message' => 'Article introuvable']);
            }

            $article_detail = new ISPAG_Detail_Page();
            ob_start();
            echo $article_detail->render_article_block($article, $is_secondary);
            
            $html = ob_get_clean();
            echo $html;
            wp_die();
        }
    }

    public static function open_new_article_modal() {
        global $wpdb;

        $table_prestation = $wpdb->prefix . 'achats_type_prestations';
        $types = $wpdb->get_results("SELECT Id, type FROM $table_prestation ORDER BY sort ASC");

        echo '<div id="ispag-product-type-selector">';
        echo '<h2>' . __('Add product', 'creation-reservoir') . '</h2>';
        echo '<div class="ispag-modal-select-type">';
        echo '<label>' . __('Choose article typ', 'creation-reservoir') . ' :<br>';
        echo '<select id="new-article-type">';
        echo '<option value="">-- ' . __('Choose', 'creation-reservoir') . ' --</option>';
        foreach ($types as $type) {
            echo '<option value="' . esc_attr($type->Id) . '">' . esc_html__($type->type, 'creation-reservoir') . '</option>';
        }
        echo '</select></label>';
        echo '</div>';
        echo '</div>';

        echo '<div id="new-article-form-container"></div>'; // Contenu dynamique ensuite

        wp_die();
    }

    private static function render_article_modal_form($article, $groupes, $standard_titles, $is_new = false) {
        $user_can = current_user_can('manage_order');
        $id_attr = $is_new ? '' : ' data-article-id="' . intval($article->Id) . '"';
        include plugin_dir_path(__FILE__) . 'templates/modal-display-article-form.php';

    }


    public static function delete_article() {
        global $wpdb;

        $id = isset($_POST['article_id']) ? intval($_POST['article_id']) : 0;
        $source = sanitize_text_field($_POST['source'] ?? 'project');
        if (!$id) {
            wp_send_json_error(['message' => 'ID manquant ou invalide']);
        }
        if($source == 'purchase'){
            $deleted = $wpdb->delete($wpdb->prefix . 'achats_articles_cmd_fournisseurs', ['Id' => $id], ['%d']);
            $deleted && $wpdb->delete($wpdb->prefix . 'achats_historique', ['Historique' => $id], ['%d']);
            


            if ($deleted === false) {
                wp_send_json_error(['message' => 'Erreur lors de la suppression']);
            }

            wp_send_json_success(['message' => 'Article supprimé']);

        }
        else{

            $deleted = $wpdb->delete($wpdb->prefix . 'achats_details_commande', ['Id' => $id], ['%d']);
            $deleted && $wpdb->delete($wpdb->prefix . 'achats_historique', ['Historique' => $id], ['%d']);
            $deleted && do_action('ispag_delete_tank_with_article_id', null, $id);


            if ($deleted === false) {
                wp_send_json_error(['message' => 'Erreur lors de la suppression']);
            }

            wp_send_json_success(['message' => 'Article supprimé']);
        }
    }

    public static function bulk_update_articles () {
        check_ajax_referer('ispag_nonce');

        $article_ids = $_POST['articles'] ?? [];
        $deal_id = $_POST['deal_id'] ?? [];
        if (!current_user_can('manage_order') || empty($article_ids)) {
            wp_send_json_error(['message' => __('Unauthorized or empty selection', 'creation-reservoir')]);
        }

        global $wpdb;
        $updates = [];
        $ids_raw = $_POST['articles'] ?? '';
        $ids = array_filter(array_map('intval', explode(',', $ids_raw)));

        $in_clause = implode(',', $ids);
        if ($_POST['date_depart']) {
            $updates[] = "TimestampDateDeLivraison = '" . intval(strtotime($_POST['date_depart'])) . "'";
        }
        if ($_POST['date_eta']) {
            $updates[] = "TimestampDateDeLivraisonFin = '" . intval(strtotime($_POST['date_eta'])) . "'";
        }
        if (!empty($_POST['livre_date'])) {
            $timestamp = strtotime($_POST['livre_date']);
            if ($timestamp) {
                $updates[] = "Livre = " . intval($timestamp);
                $updates[] = "TimestampDateDeLivraisonFin = " . intval($timestamp);
                do_action('ispag_achat_set_article_as_delivered', '',  $ids, intval($timestamp));
                
            }
        }

        if (!empty($_POST['invoiced_date'])) {
            $timestamp = strtotime($_POST['invoiced_date']);
            if ($timestamp) {
                $updates[] = "invoiced = " . intval($timestamp);
            }
        }
        // 🌟 PARTIE MISE À JOUR DU DISCOUNT ET CAPTURE DE LA VALEUR
        if (!empty($_POST['discount']) && isset($_POST['discount']) ) {
            // Nettoyage et formatage de la valeur du discount
            $discount_value = floatval($_POST['discount']);
            // Formatage pour l'insertion SQL (ex: 15.50)
            $applied_discount = number_format($discount_value, 2, '.', '');
            
            $updates[] = "discount = '" . esc_sql($applied_discount) . "'";
        }


        // demande d'achat : on ne met à jour que si le champ est reçu (sinon on garde ce qui est en base)
        if (isset($_POST['demande_ok'])) {
            $updates[] = "DemandeAchatOk = " . intval($_POST['demande_ok']);
        }

        // dessin approuvé
        if (isset($_POST['drawing_ok'])) {
            $updates[] = "DrawingApproved = " . intval($_POST['drawing_ok']);
        }
        // $updates[] = "Livre = " . intval($_POST['livre']);
        // $updates[] = "invoiced = " . intval($_POST['invoiced']);

        if (!empty($updates)) {
            $query = "UPDATE {$wpdb->prefix}achats_details_commande SET " . implode(', ', $updates) . " WHERE Id IN ($in_clause)";
            $wpdb->query($query);
        }
        do_action('isag_run_auto_update', $deal_id);
        // 🌟 INCLUSION DE LA VALEUR DU DISCOUNT DANS LA RÉPONSE JSON
        $response_data = [
            'message'   => __('Bulk update applied successfully', 'creation-reservoir'),
            'discount'  => $applied_discount, // Retourne la valeur formatée (ex: "15.50") ou null
            'datas'     => $_POST,
        ];
        
        wp_send_json_success($response_data);
    }

    public static function ispag_duplicate_article() {
        check_ajax_referer('ispag_nonce', '_ajax_nonce');

        global $wpdb;
        $id = intval($_POST['article_id']);
        $table = $wpdb->prefix . 'achats_details_commande';

        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE Id = %d", $id), ARRAY_A);
        if (!$row) wp_send_json_error("Article introuvable");

        unset($row['Id']);
        $row['sales_price'] = 0;
        $row['DemandeAchatOk'] = null;
        $wpdb->insert($table, $row);

        if ($wpdb->insert_id) {
            $old_article_id = $id;
            $new_article_id = $wpdb->insert_id;
            do_action('ispag_duplicate_tank_data', $old_article_id, $new_article_id);
            wp_send_json_success($wpdb->insert_id);
        } else {
            wp_send_json_error("Erreur lors de la duplication");
        }
    }
}
