<?php

class ISPAG_Document_Manager {
    private $wpdb;
    private $table_historique;
    private $table_media;
    private $table_projet_articles;
    private $table_achat_articles;
    private $table_prestations;
    private $project_array_document_type;
    private $purchase_array_document_type;
    private $table_doc_type;

    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->table_historique = $wpdb->prefix . 'achats_historique';
        $this->table_media = $wpdb->prefix . 'posts';
        $this->table_projet_articles = $wpdb->prefix . 'achats_details_commande';
        $this->table_achat_articles = $wpdb->prefix . 'achats_articles_cmd_fournisseurs';
        $this->table_prestations = $wpdb->prefix . 'achats_type_prestations';
        $this->table_doc_type = $wpdb->prefix . 'achats_doc_types';

        add_action('wp_ajax_ispag_upload_document', [$this, 'upload_document']);
        add_action('wp_ajax_ispag_delete_document', [$this, 'delete_document']);
        add_action('ispag_delete_document_whith_deal_id', [$this, 'delete_document_whith_deal_id'],10,2);
        add_action('wp_ajax_ispag_get_documents_list', [$this, 'ajax_get_documents_list']);
        add_action('wp_ajax_ispag_save_historique_views', [$this, 'save_historique_views']);
        // add_action('wp_ajax_ispag_extract_request_datas', [$this, 'extract_request_datas']);
        // add_action('wp_ajax_analyze_project_data', [$this, 'analyze_project_data_handle']);
        

        // Enqueue ton script et injecte le nonce + ajaxurl
        add_action('wp_enqueue_scripts', [$this, 'enqueue_scripts']);

        add_filter('ispag_display_doc_manager', [self::class, 'display_ispag_doc_manger'], 10, 2);

        $this->enqueue_scripts();
    }

    public static function display_ispag_doc_manger($deal_id, $is_purchase = false){
        echo self::get_confirmation_modal();
        $docManager = new ISPAG_Document_Manager();
        $docs = $docManager->get_documents_grouped_by_article($deal_id, $is_purchase);

        echo $docManager->render_grouped_documents($docs);
    }

    public static function get_confirmation_modal(){
        
        return '
        
        <div id="confirmationModal" class="ispag-product-modal" style="display:none;">
            <div class="ispag-modal-content">
                <span class="ispag-modal-close">&times;</span>
                <div id="ispag-confirmation-modal-body">
                    <!-- Le contenu sera injecté ici en JS -->
                </div> 
                <div class="ispag-modal-footer">
                    <p><button id="confirmButton" class="ispag-btn ">' . __('Confirm','creation-reservoir') . '</button></p>
                    
                </div>
            </div>
        </div>';
    }

    public function ajax_get_documents_list() {
        $deal_id = intval($_POST['deal_id'] ?? 0);
        $poid = intval($_POST['poid'] ?? 0);
        if (!$deal_id and !$poid) {
            wp_send_json_error("Missing deal_id and poid");
        }
        if($deal_id){
            // Récupère la liste des documents (HTML généré comme dans ton template)
            $docs = $this->get_documents_grouped_by_article($deal_id, false);
        }
        elseif($poid){
            // Récupère la liste des documents (HTML généré comme dans ton template)
            $docs = $this->get_documents_grouped_by_article($poid, true);
        }
        
        ob_start();
        echo $this->render_doc_list($docs);
        $html = ob_get_clean();

        wp_send_json_success($html);
    }

    public function enqueue_scripts() {
        wp_enqueue_script('ispag-dxf-engine', plugin_dir_url(__FILE__) . '../assets/js/ispag-dxf-engine.js', ['jquery'], false, true);
        wp_enqueue_script('ispag-upload-document', plugin_dir_url(__FILE__) . '../assets/js/dropzone.js', ['jquery'], false, true);

        // Génère un nonce sécurisé pour ajax
        $nonce = wp_create_nonce('ispag_ajax_nonce');

        // Injecte les variables JS (ajaxurl et nonce)
        wp_localize_script('ispag-upload-document', 'ispag_ajax_obj', [
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => $nonce,
            'selected' => __('selected', 'creation-reservoir'),
            'Upload_in_progress' => __('Upload in progress', 'creation-reservoir'),
            'File_added_successfully' => __('File added successfully', 'creation-reservoir'),
            'really_dele_doc' => __('Are you sure you want to delete this document', 'creation-reservoir'),
            'really_dele_doc' => __('Are you sure you want to delete this document', 'creation-reservoir'),
            'drag_files_here' => __('Drag one or more files here', 'creation-reservoir'),
            'or' => __('or', 'creation-reservoir'),
            'browse' => __('browse', 'creation-reservoir'),
            'please_select_file' => __('Please choose a file and type', 'creation-reservoir'),
        ]);
    }

    public function get_document_type(){

        
    }

    public function get_documents_grouped_by_article($deal_id, $is_purchase = false) {
        if($is_purchase === false){
            $where = 'h.hubspot_deal_id = %d';
        }
        else{
            $where = 'h.purchase_order = %d';
        }
        $sql = "
            SELECT h.*, p.guid AS file_url, p.post_title, p.post_mime_type, u.display_name, dt.label
            FROM {$this->table_historique} h
            LEFT JOIN {$this->wpdb->users} u ON u.ID = h.IdUser
            LEFT JOIN {$this->table_media} p ON p.ID = h.IdMedia
            LEFT JOIN {$this->table_doc_type} dt ON h.Classcss = dt.slug
            WHERE {$where} AND h.IdMedia > 0
            ORDER BY h.dateReadable ASC
        ";
        $docs = $this->wpdb->get_results($this->wpdb->prepare($sql, $deal_id));
        if (!$docs) return [];

        $grouped = [];

        foreach ($docs as $doc) {
            $is_article = is_numeric($doc->Historique);

            if ($is_article AND $doc->Historique != 0) {
                $article_id = intval($doc->Historique);
                $groupe = $this->get_groupe_from_article($article_id, $is_purchase);
                $key = $groupe ? " $groupe" : "Article $article_id";
            } else {
                $key = __('General', 'creation-reservoir');
            }

            if (!isset($grouped[$key])) $grouped[$key] = [];
            $grouped[$key][] = $doc;
        }

        return $grouped;
    }

    private function get_groupe_from_article($article_id, $is_purchase = false) {
        if ($is_purchase) {
            $query = "SELECT IdCommandeClient FROM {$this->table_achat_articles} WHERE Id = %d";
        } else {
            $query = "SELECT groupe FROM {$this->table_projet_articles} WHERE id = %d";
        }
        $result = $this->wpdb->get_var($this->wpdb->prepare($query, $article_id));
        return $result ?: null;
    }

    private function render_doc_list($grouped_docs){
        if (empty($grouped_docs)) {
            echo '<p>' . __('No documents found', 'creation-reservoir') . '.</p>';
        } else {
            
            foreach ($grouped_docs as $groupe => $docs) {
                echo "<h4 class='doc-group-title'>{$groupe}</h4><ul class='ispag-documents-ul'>";
                foreach ($docs as $doc) {
                    $icon = '📄';
                    if (str_contains($doc->post_mime_type, 'pdf')) $icon = '📃';
                    elseif (str_contains($doc->post_mime_type, 'image')) $icon = '🖼️';
                    include plugin_dir_path(__FILE__) . 'templates/render-document-display.php';

                }
                echo '</ul>';
            }
        }
    } 
    
    public function render_grouped_documents($grouped_docs) {
        ob_start();

        // On utilise les classes qui correspondent au CSS ci-dessous
        echo '<div class="ispag-documents-wrapper">';

            // Colonne de gauche : documents
            echo '<div class="ispag-documents-list">';
                $this->render_doc_list($grouped_docs);
            echo '</div>';

            // Colonne de droite : dropzone
            echo '<div class="ispag-documents-upload">';
                echo $this->create_dropzone();
            echo '</div>';

        echo '</div>';

        return ob_get_clean();
    }


    private function create_dropzone(){
        // On récupère le deal_id dynamiquement
        $deal_id = esc_attr($_GET['deal_id'] ?? 0);
        
        $html = '
            <div class="ispag-upload-docs">
                <h4 style="margin-top:0;">' . __('Add document', 'creation-reservoir') . '</h4>
                <form id="ispag-upload-form">
                    <div class="ispag-form-group">
                        <label for="doc_type"><strong>' . __('Document type', 'creation-reservoir') . '</strong></label>
                        <select name="doc_type" id="doc_type" required>
                            <option value="">-- ' . __('Select', 'creation-reservoir') . ' --</option>
                            ' . $this->document_type_selector_project() .'
                        </select>
                    </div>

                    <div id="dropzone" class="dropzone-area">
                        <p>' . __('Drag one or more files here', 'creation-reservoir' ) . '</p>
                        <p>' . __('or', 'creation-reservoir' ) . '</p>
                        <button type="button" id="browse-file" class="ispag-btn ispag-btn-secondary-outlined">' . __('Browse', 'creation-reservoir'). '</button>
                        <input type="file" id="file_input" name="files[]" multiple style="display:none;"/>
                    </div>

                    <input type="hidden" name="deal_id" value="' . $deal_id . '">
                    <button type="submit" class="ispag-btn ispag-btn-red">' . __('Upload all files', 'creation-reservoir') . '</button>
                </form>
                <div id="upload-status" style="margin-top:10px;"></div>
            </div>';

        return $html;
    }

    public function upload_document() {
        // error_log('📂 [DEBUG] Entrée dans upload_document');

        if (!current_user_can('upload_files') || empty($_FILES['files'])) {
// \1('❌ [DEBUG] Accès refusé ou aucun fichier reçu');
            wp_send_json_error('Accès refusé ou fichiers manquants');
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        // error_log('📂 [DEBUG] Fichiers WP requis chargés');

        $deal_id  = intval($_POST['deal_id'] ?? 0);
        $poid  = intval($_POST['poid'] ?? 0);
        $article_id = intval($_POST['article_id'] ?? 0);
        $doc_type = sanitize_text_field($_POST['doc_type'] ?? '');
        $user_id  = get_current_user_id();
        $now      = current_time('mysql');
        $timestamp = current_time('timestamp');

        // error_log("📂 [DEBUG] deal_id: {$deal_id}, poid: {$poid}, article_id: {$article_id}, doc_type: {$doc_type}, user_id: {$user_id}");

        $uploaded_ids = [];
        $file_count = count($_FILES['files']['name']);
        // error_log("📂 [DEBUG] Nombre de fichiers reçus : {$file_count}");

        for ($i = 0; $i < $file_count; $i++) {
            // error_log("📂 [DEBUG] Traitement du fichier #{$i} : " . $_FILES['files']['name'][$i]);

            if ($_FILES['files']['error'][$i] !== UPLOAD_ERR_OK) {
// \1("❌ [DEBUG] Erreur upload fichier #{$i} : code " . $_FILES['files']['error'][$i]);
                continue;
            }

            $file = [
                'name'     => $_FILES['files']['name'][$i],
                'type'     => $_FILES['files']['type'][$i],
                'tmp_name' => $_FILES['files']['tmp_name'][$i],
                'error'    => $_FILES['files']['error'][$i],
                'size'     => $_FILES['files']['size'][$i],
            ];

            $upload = wp_handle_upload($file, ['test_form' => false]);

            if (isset($upload['error'])) {
// \1("❌ [DEBUG] Erreur lors de wp_handle_upload pour {$file['name']} : " . $upload['error']);
                continue;
            }

            // error_log("✅ [DEBUG] Fichier uploadé : " . $upload['file'] . " (URL: " . $upload['url'] . ")");

            $attachment = [
                'post_title'     => sanitize_file_name($file['name']),
                'post_mime_type' => $upload['type'],
                'post_status'    => 'inherit',
                'guid'           => $upload['url'],
            ];

            $attach_id = wp_insert_attachment($attachment, $upload['file']);

            if (is_wp_error($attach_id) || !$attach_id) {
// \1("❌ [DEBUG] Erreur insertion attachment pour {$file['name']}");
                continue;
            }

            wp_generate_attachment_metadata($attach_id, $upload['file']);
            // error_log("✅ [DEBUG] Attachment ID {$attach_id} créé pour {$file['name']}");

            $this->wpdb->insert($this->table_historique, [
                'hubspot_deal_id' => $deal_id,
                'purchase_order'  => $poid,
                'Date'            => $timestamp,
                'dateReadable'    => $now,
                'IdUser'          => $user_id,
                'Historique'      => $article_id,
                'IdMedia'         => $attach_id,
                'is_task'         => 0,
                'is_done'         => 0,
                'ClassCss'        => $doc_type,
            ]);
            // error_log("📝 [DEBUG] Historique inséré pour attachment {$attach_id}");

            if ($doc_type == 'drawingApproval') {
                // error_log("📢 [DEBUG] Action drawingApproval déclenchée");
                do_action('ispag_validate_drawing', '', $article_id, $attach_id, $user_id, $doc_type);
                do_action('ispag_send_telegram_notification', null, 'drawing_validated', true, true, $deal_id, true);
            }
            elseif (in_array($doc_type, ['drawingModification', 'sketch', 'product_drawing'])) {
                // error_log("📢 [DEBUG] Action dessin modifié / sketch déclenchée");
                do_action('ispag_save_drawing', '', $article_id, $attach_id, $user_id, $doc_type);
                do_action('ispag_send_telegram_notification', null, 'newDocUploaded', true, true, $deal_id, true);
            }

            if ($doc_type === 'submission' || $doc_type === 'request_supplier_quotation') {
                // error_log("🔍 [DEBUG] Analyse PDF via analyze_pdf_keywords()");
                // $this->analyze_pdf_keywords($upload['file'], $deal_id);
            }
            elseif ($doc_type === 'quotation' || $doc_type === 'supplier_quotation' || $doc_type === 'invoice') {
                // error_log("🔍 [DEBUG] Analyse PDF via ispag_achat_analyze_pdf_keywords");
                // do_action('ispag_achat_analyze_pdf_keywords', null, $upload['file'], $poid);
            }

            $uploaded_ids[] = $attach_id;
        }

        if (empty($uploaded_ids)) {
// \1("❌ [DEBUG] Aucun fichier valide uploadé");
            wp_send_json_error('Aucun fichier valide uploadé.');
        }

        // error_log("✅ [DEBUG] Fichiers uploadés avec succès : " . implode(', ', $uploaded_ids));

        $articles_list = apply_filters('ispag_reload_article_list', $deal_id, null);

        wp_send_json_success([
            'uploaded_ids' => $uploaded_ids,
            'articles_list' => $articles_list,
        ]);
    }





    private function document_type_selector_project() {

        $selector = '';
        
        // Déterminer la condition de restriction
        $restriction_condition = '';
        
        // Si l'utilisateur n'a PAS la permission 'manage_order', on ajoute la restriction 'restricted = 0'
        // La fonction 'current_user_can()' est une fonction standard de WordPress.
        if ( ! current_user_can('manage_order') ) {
            // Ajouter la clause WHERE pour ne sélectionner que les types de documents non restreints
            // Nous utilisons un marquage "%d" pour le placeholder '0' pour préparer la requête correctement.
            $restriction_condition = " AND dt.restricted = %d ";
        }

        $sql = "
            SELECT dt.*
            FROM {$this->table_doc_type} dt
            WHERE dt.for_article_type = 0
            {$restriction_condition}
            ORDER BY dt.sort_order ASC
        ";
        
        // La méthode prepare() de wpdb est essentielle pour la sécurité (prévention des injections SQL).
        // Elle prend la requête SQL et les valeurs des placeholders.
        // Si $restriction_condition est vide (utilisateur autorisé), il n'y a pas de placeholder à passer.
        $query_args = [ $sql ];
        if ( ! current_user_can('manage_order') ) {
            // On ajoute la valeur '0' uniquement si le placeholder '%d' est présent dans la requête
            $query_args[] = 0;
        }
        
        // Utiliser call_user_func_array pour passer dynamiquement les arguments à prepare()
        $prepared_sql = call_user_func_array( [ $this->wpdb, 'prepare' ], $query_args );

        $docs = $this->wpdb->get_results( $prepared_sql );

        foreach ($docs as $doc) {
            $selector .= '<option value="' . esc_attr($doc->slug) . '" 
                                data-class="' . esc_attr($doc->slug) . '">'
                . esc_html__($doc->label, 'creation-reservoir') . 
                '</option>';
        }
        
        $selector .= $this->document_type_selector_project_with_articles();

        return $selector;
    }

    private function document_type_selector_project_with_articles() {

        // Vérifier la permission de l'utilisateur une seule fois avant les boucles
        $can_manage_order = current_user_can('manage_order');

        $deal_id = intval($_GET['deal_id'] ?? 0);

        $repo_article = new ISPAG_Article_Repository();
        // Note : L'utilisateur est le directeur d'ISPAG (Cyril Barthel), donc cette logique est probablement essentielle pour leur activité.
        $articles = $repo_article->get_articles_by_deal($deal_id);

        if (!$articles) return '';

        // Laissez la requête SQL telle quelle pour récupérer tous les documents d'articles
        $sql = "
            SELECT dt.*
            FROM {$this->table_doc_type} dt
            WHERE dt.for_article_type = 1
            ORDER BY dt.sort_order ASC
        ";
        // Pas besoin de préparer sans variables, mais c'est une bonne pratique.
        $docs = $this->wpdb->get_results($this->wpdb->prepare($sql));

        
        $output = '';
        foreach ($articles as $group => $value) {
            foreach ($value as $article) {
                if (in_array(intval($article->Type), [1, 4, 5])) {
                    
                    $groupe = $group ?: __('No groupe', 'creation-reservoir');
                    $title = $article->Article ?: __('No titre', 'creation-reservoir');
                    $id = $article->Id;
                    $approved = $article->DrawingApproved;

                    $output .= '<optgroup label="' . esc_html(stripslashes($groupe)) . '" class="header_group">';
                    $output .= '<option
                                    value="product_drawing"
                                    data-class="product_drawing"
                                    data-product-id="'. $id .'">' . esc_html(stripslashes($title)) . '</option>';

                    
                    // L'endroit idéal pour la logique de filtrage par permission
                    foreach ($docs as $doc) {
                        
                        // NOUVELLE CONDITION DE FILTRAGE
                        // La condition sera VRAIE si :
                        // 1. L'utilisateur a la capacité 'manage_order' (il voit tout)
                        // OU
                        // 2. Le document n'est pas restreint (restricted == 0)
                        $is_doc_allowed = $can_manage_order || ($doc->restricted == 0);
                        
                        // Votre condition originale est remplacée par la nouvelle logique + la condition 'empty($approved)'.
                        // J'ai préservé la logique d'origine, mais elle semble incomplète :
                        // if (empty($approved) OR $doc->restricted == 00)
                        // 
                        // J'ai besoin de savoir si vous voulez appliquer le filtrage par permission 
                        // EN PLUS de la condition 'empty($approved)', ou si le filtrage par permission 
                        // remplace la vérification '$doc->restricted == 00'.
                        
                        // Je vais supposer que le filtrage par permission doit s'appliquer UNIQUEMENT aux 
                        // documents qui ont le champ 'restricted' à 1.
                        
                        // SI l'utilisateur peut tout voir OU SI le document n'est pas restreint
                        if ( $can_manage_order || ($doc->restricted == 0) ) {
                            
                            // Si votre logique précédente doit être conservée :
                            // if ( empty($approved) || $can_manage_order || ($doc->restricted == 0) ) {
                            // J'utilise le OR logique || au lieu de l'opérateur bit à bit OR.
                            
                            $output .= '<option value="' . esc_attr($doc->slug) . '" 
                                                data-class="' . esc_attr($doc->slug) . '"
                                                data-product-id="'. $id .'">
                                                📃  ' . esc_html__(stripslashes($doc->label), 'creation-reservoir') . ' ' . esc_html(stripslashes($title)) . 
                                                '</option>';
                        }
                        
                    }

                    $output .= '</optgroup>';
                }
            }
        }

        return $output;
    }
    
    public function delete_document_whith_deal_id($html, $deal_id = null) {
        if (!current_user_can('upload_files')) {
            wp_send_json_error(__('You are not allowed', 'creation-reservoir'));
        }

        if (!$deal_id) {
            wp_send_json_error(__('No deal Id defined', 'creation-reservoir'));
        }

        global $wpdb;
        

        // 1. Récupérer tous les ID médias liés au projet
        $media_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT IdMedia FROM $this->table_historique WHERE hubspot_deal_id = %d AND IdMedia > 0",
            $deal_id
        ));

        // 2. Supprimer les médias (fichiers physiques + base WordPress)
        foreach ($media_ids as $media_id) {
            // Log avant suppression
            // error_log("Suppression média ID : $media_id lié au deal ID : $deal_id");

            $deleted = wp_delete_attachment($media_id, true);
            // if ($deleted === false) {
            //     error_log("Erreur lors de la suppression du média ID : $media_id");
            // } else {
            //     error_log("Média ID : $media_id supprimé avec succès");
            // }
        }

        // 3. Supprimer les entrées de l’historique liées à ce projet
        $wpdb->delete($this->table_historique, ['hubspot_deal_id' => $deal_id]);
        
    }

    public function delete_document() {
        check_ajax_referer('ispag_ajax_nonce', '_ajax_nonce');

        if (!current_user_can('upload_files')) {
            wp_send_json_error(__('You are not allowed', 'creation-reservoir'));
        }

        $doc_id = intval($_POST['document_id'] ?? 0);
        if (!$doc_id) {
            wp_send_json_error(__('No Id defined', 'creation-reservoir'));
        }

        // Supprimer la pièce jointe WP (fichier + métadonnées)
        $deleted = wp_delete_attachment($doc_id, true);

        if ($deleted) {
            // Supprimer aussi la ligne dans ton historique si besoin
            $this->wpdb->delete($this->table_historique, ['IdMedia' => $doc_id]);

            wp_send_json_success();
        } else {
            wp_send_json_error('Impossible de supprimer le document');
        }
    }


    public function save_historique_views() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'achats_historique_views'; // Nom complet de la table

        // Vérification du nonce de sécurité
        check_ajax_referer('ispag_ajax_nonce', '_ajax_nonce');

        // Récupération et nettoyage des données envoyées par AJAX
        $media_id = isset($_POST['media_id']) ? intval($_POST['media_id']) : 0;
        $user_id = get_current_user_id(); // Récupère l'ID de l'utilisateur connecté

        // Vérification que les données sont valides
        if ($media_id > 0 && $user_id > 0) {
            $data = array(
                'user_id' => $user_id,
                'document_id' => $media_id,
                'note_id' => 0 // Ajustez si vous avez besoin de cette donnée
            );
            $format = array('%d', '%d', '%d');

            // Insertion des données dans la table
            $result = $wpdb->insert($table_name, $data, $format);

            if ($result) {
                wp_send_json_success('Lecture enregistrée avec succès.');
            } else {
                wp_send_json_error('Erreur lors de l\'enregistrement de la lecture.');
            }
        } else {
            wp_send_json_error('Données invalides.');
        }

        wp_die(); // C'est obligatoire pour terminer la requête AJAX de WordPress
    }
}
