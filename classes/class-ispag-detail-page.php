<?php
defined('ABSPATH') or die();

class ISPAG_Detail_Page {
    public static function init() {

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

        add_filter('ispag_generate_purchase_order_pdf', function($default, $project_header, $project_data, $infos, $table_header, $articles, $title) {
            require_once __DIR__ . '/class-ispag-pdf-generator.php';
            $pdf = new ISPAG_PDF_Generator();
            $pdf->generate_delivery_note($project_header, $project_data, $infos, $table_header, $articles, $title, true);
            return $pdf;
        }, 10, 7);
    }

    public static function enqueue_assets() {
        wp_enqueue_script('ispag-detail-display', plugin_dir_url(__FILE__) . '../assets/js/details.js', [], false, true);
        wp_enqueue_script('ispag-detail-inline-edit', plugin_dir_url(__FILE__) . '../assets/js/inline-edit.js', ['ispag-detail-display'], false, true);
        // wp_enqueue_style('ispag-detail-style', plugin_dir_url(__FILE__) . '../assets/css/detail.css');
        wp_enqueue_script('ispag-detail-tabs', plugin_dir_url(__FILE__) . '../assets/js/tabs.js', ['ispag-detail-display'], false, true);
        wp_enqueue_script('ispag-detail-suivi', plugin_dir_url(__FILE__) . '../assets/js/suivi.js', ['ispag-detail-display'], false, true);
         

        $statuses = (new ISPAG_Projet_Suivi())->get_all_statuses();
        wp_localize_script('ispag-detail-tabs', 'ispagStatusChoices', $statuses);

        wp_localize_script('ispag-detail-suivi', 'ispag_suivis', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('ispag_nonce'),
        ]);

        wp_localize_script('ispag-detail-display', 'ispag_texts', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('ispag_nonce'),
            'confirm_delete_article' => __('Delete this item', 'creation-reservoir'),
            'would_you_copy' => __('Would you really copy this article', 'creation-reservoir'),
            'article_duplicated' => __('Article duplicated', 'creation-reservoir'),
            'modal_unsaved_changes_warning' => __('Some changes were made. Close anyway', 'creation-reservoir'),
            'txt_delete_project' => __('Would you delete this project', 'creation-reservoir'),
            'txt_error_deleting_project' => __('Error while deleting this project', 'creation-reservoir'),
        ]);
        

    }


    public static function ajax_update_associations() {
        check_ajax_referer('ispag_nonce', 'nonce');
        global $wpdb;

        $deal_id = sanitize_text_field($_POST['deal_id']);
        $type    = sanitize_text_field($_POST['type']); // 'company' ou 'contact'
        $value   = intval($_POST['value']);

        $table = $wpdb->prefix . 'achats_liste_commande';
        $column = ($type === 'company') ? 'AssociatedCompanyID' : 'AssociatedContactIDs';

        $updated = $wpdb->update(
            $table,
            [$column => $value],
            ['hubspot_deal_id' => $deal_id]
        );

        if ($updated !== false) {
            wp_send_json_success();
        } else {
            wp_send_json_error();
        }
    }

    public static function ajax_reload_article_list() {
        $deal_id = intval($_POST['deal_id'] ?? 0);
        $isQotation = isset($_POST['isQotation']) ? boolval($_POST['isQotation']) : null;
 
        echo apply_filters('ispag_reload_article_list', $deal_id, $isQotation);
        wp_die();
    }


    public static function reload_article_list($deal_id, $isQotation = null) {
        $can_manage_order = current_user_can('manage_order');
        $articles = new ISPAG_Article_Repository();
        $repo_project = new ISPAG_Projet_Repository();
        $project = $repo_project->get_project_by_deal_id(null, $deal_id);
        // return $articles->get_articles_by_deal($deal_id);
        return self::render_articles_list($articles->get_articles_by_deal($deal_id), $project);

    }

    public static function ajax_update_project_title() {
        check_ajax_referer('ispag_nonce', 'nonce');

        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Permission refusée');
        }

        $project_id = intval($_POST['project_id']);
        $new_title = sanitize_text_field($_POST['new_title']);

        // Ici, insérez votre logique de mise à jour en DB
        // Exemple si c'est une table personnalisée :
        global $wpdb;
        $table_name = $wpdb->prefix . 'achats_liste_commande';
        
        $updated = $wpdb->update(
            $table_name,
            array('ObjetCommande' => $new_title), // Colonne à modifier
            array('hubspot_deal_id' => $project_id),           // Condition
            array('%s'), 
            array('%d')
        );

        if ($updated !== false) {
            wp_send_json_success('Titre mis à jour');
        } else {
            wp_send_json_error('Erreur DB');
        }
    }

    public static function render($atts) {
        $deal_id = isset($_GET['deal_id']) ? sanitize_text_field($_GET['deal_id']) : '';
        // $isQotation = isset($_GET['qotation']) ? ($_GET['qotation'] == 1 ? true : false) : null;
        if (!$deal_id) return '<p>' . __('Project not found', 'creation-reservoir') . '</p>';



        $project_detail = new ISPAG_Projet_Repository();
        
        $details = apply_filters('ispag_get_project_by_deal_id', null, $deal_id );

        // error_log("ISPAG DEBUG render SQL : " . print_r($details, true));

        $isQotation = $details->isQotation ?? false;

        // $auto_status_checker = new ISPAG_Projets_status_checker();
        // $auto_status_checker->run_auto_update($deal_id);
        do_action('isag_run_auto_update', $deal_id);

        $can_manage_order = current_user_can('manage_order'); // corrige la capacité ici
        $can_view_prices = current_user_can('display_sales_prices');

        // $param = 'subscribe_' . $deal_id; // texte après /start
        // $btn_telegram = '<a href="https://t.me/IspagBot?start=' . urlencode($param) . '" class="ispag-btn" target="_blank">' . __('subscribe on Telegram', 'creation-reservoir') . '</a>';
        $btn_telegram = null;

        $title = esc_html(stripslashes($details->ObjetCommande)); // on améliorera avec le vrai nom

        ob_start();
        ?>
        
            <div id="ispag_project_contact_datas" class="ispag-achat-header">

            <?php
            if(current_user_can('manage_order')):
            ?>
                
                <h2 id="editable-project-title" 
                    class="ispag-editable-title" 
                    contenteditable="true" 
                    spellcheck="false"
                    data-project-id="<?php echo $deal_id; ?>"
                    style="margin-top:0; font-size:1.8rem; border-bottom: 1px dashed transparent; cursor: pointer;"
                    onfocus="this.style.borderBottom='1px dashed #ccc'" 
                    onblur="this.style.borderBottom='1px dashed transparent'">
            <?php
            else:
            ?>
                <h2 style="margin-top:0; font-size:1.8rem;">
            <?php
            endif;
            ?>
                    <?php echo $title; ?>
                </h2>
            
                <?php
                echo self::display_project_contact_datas($details);
                ?>
            </div>
        
        <?php echo $btn_telegram; ?>       

        <?php
        if(current_user_can('manage_order')){
            // echo apply_filters('ispag_render_duplicate_button', null, $deal_id);
            ?>
            <a href="<?= esc_url($details->purchase_url) ?>" target="_blank" class="ispag-btn ispag-btn-secondary-outlined"><?= esc_html(__('To purchase', 'creation-reservoir')) ?></a>
            <?php
        }
        

        ?>

        <p>&nbsp;</p>
        <?php

        $activity_tab = apply_filters('ispag_render_activity_tab', null, null, null, $deal_id);
        $action_btn = '<button class="ispag-action-btn" data-action="note" data-user-id="0" data-company-id="0" data-deal-id="' . $deal_id . '" title="'. esc_attr( 'Add Note', 'textdomain' ) .'">
                        <span class="dashicons dashicons-text-page"></span>
                        ' . esc_html( 'Note', 'textdomain' ) .'
                    </button>';
        
        ?>
 
        
        <div class="ispag-tabs">
            <ul class="tab-titles">
                <li class="active" data-tab="postes"><?php echo __('Articles', 'creation-reservoir'); ?></li>
                <li data-tab="details"><?php echo __('Details', 'creation-reservoir'); ?></li>
                <li data-tab="suivi"><?php echo __('Follow up', 'creation-reservoir'); ?></li>
                <li data-tab="docs"><?php echo __('Document flow', 'creation-reservoir'); ?></li>

            </ul>
            <div class="tab-content active" id="postes"><?php display_ispag_project_articles($deal_id, $isQotation); ?></div>
            
            <div class="tab-content" id="details"><?php display_ispag_project_details($deal_id, $details); ?></div>
            <div class="tab-content" id="suivi">
                <?php display_ispag_suivis($deal_id, $isQotation); ?>
            </div>
            <div class="tab-content" id="docs">
                <?php //display_ispag_doc_manger($deal_id);
                apply_filters('ispag_display_doc_manager', $deal_id, false); ?>
                    
            </div>

        </div>
        <?php
        echo "<script>document.title = '" . esc_js($title) . "';</script>";

        echo self::display_modal();
        echo self::display_doc_analyser_modal();
        return ob_get_clean();
    }

    private static function display_project_contact_datas($details){
        if ( ! is_user_logged_in() || ! current_user_can( 'manage_order' ) ) {
            return '';
        }

        $base_url = get_home_url(); 
        $contact_link = trailingslashit( $base_url . '/contact/' . $details->AssociatedContactIDs );
        $company_link = trailingslashit( $base_url . '/company/' . $details->AssociatedCompanyID );
        
        ob_start(); ?>
        <div class="achat-meta" style="display:grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap:1rem; margin-top:1rem; padding: 15px; background: #fdfdfd; border: 1px solid #eee; border-radius: 8px;">
            
            <div class="edit-group">
                <div style="display:flex; justify-content:space-between;">
                    <strong><?php _e('Company', 'creation-reservoir'); ?></strong>
                    <a href="<?= $company_link ?>" target="_blank" style="font-size:12px; opacity:0.7;">🔗 Voir</a>
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

        </div>

        <input type="hidden" id="details-project-deal-id" value="<?= esc_attr($details->hubspot_deal_id) ?>">

        <style>
            .edit-group { background: white; padding: 10px; border-radius: 5px; border: 1px solid #eee; }
            .edit-group strong { display:block; margin-bottom:5px; font-size: 13px; color: #666; }
            .select2-container--default .select2-selection--single { border-color: #ddd !important; height: 38px !important; line-height: 38px !important; }
        </style>
        <?php
        return ob_get_clean();
    }

    public static function render_articles_list($grouped_articles, $project) {
        ob_start();
        echo '<div class="ispag-articles-list">';
        

        foreach ($grouped_articles as $groupe => $articles_principaux) {
            $escaped_group = esc_html(stripslashes($groupe));
            $id = 'group-title-' . md5($groupe);

            echo '<div class="ispag-article-group-header">';
            echo '<h3 id="' . esc_attr($id) . '">' . $escaped_group . '</h3>';
            echo '<button class="ispag-btn-copy-group" data-target="' . esc_attr($id) . '">📋</button>';
            echo '</div>';

            foreach ($articles_principaux as $article) {
                self::render_article_block($article, $project);

                if (current_user_can('manage_order') && !empty($article->secondaires)) {
                    foreach ($article->secondaires as $secondaire) {
                        self::render_article_block($secondaire, $project, true);
                    }
                }
            }
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
        return ob_get_clean();
    }
  
    public static function display_modal(){
        return '<div id="ispag-modal-product" class="ispag-product-modal" style="display:none;">
            <div class="ispag-modal-content">
                <span class="ispag-modal-close">&times;</span>
                <div id="ispag-modal-body">
                    <!-- Le contenu sera injecté ici en JS -->
                </div>
            </div>
        </div>
        ' . apply_filters('ispag_get_modal_fitting', '');

    } 

    private static function display_doc_analyser_modal(){
        return '
        <div id="ispag-analysis-modal" class="ispag-modal-overlay">
            <div class="ispag-modal-content">
                <div class="ispag-modal-header">
                    <h4>Analyse de conformité ISPAG</h4>
                    <span class="ispag-close-modal dashicons dashicons-no-alt"></span>
                </div>
                
                <div id="ispag-analysis-modal-body" class="ispag-modal-body">
                    </div>

                <div class="ispag-modal-footer" style="text-align:right; border-top:1px solid #eee;">
                    <button type="button" class="button ispag-close-modal">Fermer</button>
                </div>
            </div>
        </div>';
    }
    public static function render_article_block($article, $project, $is_secondary = false) {
        $id = (int) $article->Id;
        $checked_attr = ''; // checkbox à cocher en JS si besoin
        $titre = esc_html($article->Article);
        $deal_id = $article->hubspot_deal_id;
        // $status = $article->Livre ? 'Livré' : 'En attente de signature';
        $status = self::getDeliveryStatus($article);
        $facture = $article->invoiced ? __('Invoiced', 'creation-reservoir') : __('Not invoiced', 'creation-reservoir');
        $qty = (int) $article->Qty;
        $prix_brut = number_format((float) $article->prix_total_calculé, 2, '.', ' ');
        $rabais = number_format((float) $article->discount, 2, '.', ' ');
        $prix_net = number_format((float) $article->prix_net_calculé, 2, '.', ' ');
        $user_can_manage_order = current_user_can('manage_order');
        // $user_is_owner = ISPAG_Projet_Repository::is_user_project_owner($article->hubspot_deal_id);
        $user_is_owner = $project->is_project_owner;
        
        $user_can_generate_tank = current_user_can('generate_tank');

        $class_secondary = $is_secondary ? ' ispag-article-secondary' : '';
        if(current_user_can('manage_order') OR $article->customer_visible){
            include plugin_dir_path(__FILE__) . 'templates/render-article-block.php';
        }
         
    }

    private static function getDeliveryStatus($article){
        $status = '';

        // Cas généraux
        $today = strtotime('today');
        $is_delivered = $article->Livre;
        $delivery_date = intval($article->TimestampDateDeLivraison);
        $type_article = intval($article->Type);
        $is_validated = $article->DrawingApproved ?? false; // ou selon ton champ réel
        


        if ($is_delivered) {
            $status = __('Delivered', 'creation-reservoir');
        } elseif ($delivery_date >= $today) {
            $status = __('In delivery', 'creation-reservoir');
        } elseif ($type_article === 1) {
            // Cas cuve
            if(isset($article->last_doc_type['slug']) && $article->last_doc_type['slug'] == 'drawingApproval'){
                $status = __('Waiting drawing modification', 'creation-reservoir');
            }
            elseif (!$is_validated) {
                $status = __('Waiting drawing approval', 'creation-reservoir');
            } else {
                $status = __('In production', 'creation-reservoir');
            }
        } else {
            // Autres articles
            if ($delivery_date < $today) {
                $status = __('To be delivered', 'creation-reservoir');
            } else {
                $status = __('Waiting', 'creation-reservoir'); // fallback
            }
        }

        return $status;

    }


    public static function convert_to_project() {
        global $wpdb;
        $deal_id = intval($_POST['id']);
        // error_log("convert_to_project() called with deal_id = $deal_id");

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

        if ($updated !== false) {
            // error_log("Update successful for deal_id = $deal_id");

            $articles = apply_filters('ispag_get_articles_by_deal', null, $deal_id);
            $project = apply_filters('ispag_get_project_by_deal_id', null, $deal_id);
            
            // error_log("Contenu de \$articles : " . print_r($articles, true));
            // error_log("Found " . count($articles) . " groupes for deal_id = $deal_id");

            foreach ($articles as $grouped_articles) {
                // error_log("Found " . count($grouped_articles) . " articles for deal_id = $deal_id and groupe");
                foreach ($grouped_articles as $article) {
                    // error_log("Processing article ID = {$article->Id}");

                    $achat_article = apply_filters('ispag_get_achat_article_by_project_article_id', null, $article->Id);
                    if ($achat_article && $achat_article->IdCommandeClient === $article->Id) {
                        // error_log("Existing achat_article found for article ID = {$article->Id}");

                        $achat_id = apply_filters('ispag_get_achat_id_by_article_id', null, $achat_article->Id);
                        $first_order_state = get_option('wpcb_first_order_state');
                        $RefCommande = get_option('wpcb_kst') . '/' . $project->NumCommande . ' - ' . $project->ObjetCommande;

                        // error_log("Updating achat_id = $achat_id with RefCommande = $RefCommande and status = $first_order_state");

                        apply_filters('ispag_inline_edit_purchase', null, ['field' => 'RefCommande', 'value' => $RefCommande, 'deal_id' => $achat_id]);
                        do_action('ispag_update_status', null, $achat_id, $first_order_state);
                    } else {
// \1("❌ No achat_article found. Triggering ispag_generate_purchase_requests for deal_id = $deal_id");
                        do_action('ispag_generate_purchase_requests', null, $deal_id);
                    }
                }
            }

            wp_send_json_success();
        } else {
            $last_error = $wpdb->last_error;
// \1("❌ Error updating project for deal_id = $deal_id: $last_error");
            wp_send_json_error(__('Error while updating project', 'creation-reservoir'));
        }
    }


    public static function delete_project_btn($html, $deal_id) {
        if (!$deal_id) return $html;

        $btn = '<button class="ispag-btn ispag-delete-project-btn" data-deal-id="' . esc_attr($deal_id) . '">'
            . __('Delete project', 'creation-reservoir')
            . '</button>';

        return $html . $btn;
    }


    public static function delete_project(){
        // Sécurité basique
        if (!current_user_can('manage_order')) {
            wp_send_json_error(['message' => __('You are not allowed', 'creation-reservoir')]);
        }

        $deal_id = isset($_POST['deal_id']) ? intval($_POST['deal_id']) : 0;

        if (!$deal_id) {
            wp_send_json_error(['message' => __('Project Id missing', 'creation-reservoir')]);
        }

        // TODO : Ajoute ici ta logique de suppression (ex: suppression d’un post, lignes en base, etc.)
        // Exemple : wp_delete_post($deal_id, true);
        do_action('ispag_delete_document_whith_deal_id', null, $deal_id);
        do_action('ispag_delete_articles_whith_deal_id', null, $deal_id);
        do_action('ispag_delete_suivis_whith_deal_id', null, $deal_id);
        do_action('ispag_delete_project_whith_deal_id', null, $deal_id);

        // Si OK
        wp_send_json_success(['message' => __('Project successfully deleted', 'creation-reservoir')]);
    }

    

}


add_action('wp_ajax_ispag_update_phase_status', 'ispag_update_phase_status');
add_action('wp_ajax_ajax_get_generate_po_button', 'ajax_get_generate_po_button');
function ispag_update_phase_status() {
    
    global $wpdb;

    $deal_id = intval($_POST['deal_id']);
    $slug = sanitize_text_field($_POST['slug_phase']);
    $status_id = intval($_POST['status_id']);

    $table = $wpdb->prefix . 'achats_suivi_phase_commande';
    $meta_table = $wpdb->prefix . 'achats_meta_phase_commande';

    // // Update ou insert
    // $existing = $wpdb->get_var($wpdb->prepare(
    //     "SELECT COUNT(*) FROM $table WHERE hubspot_deal_id = %d AND slug_phase = %s",
    //     $deal_id, $slug
    // ));

    // if ($existing) {
    //     $wpdb->update($table, ['status_id' => $status_id], ['hubspot_deal_id' => $deal_id, 'slug_phase' => $slug]);
    // } else {
        $wpdb->insert($table, ['hubspot_deal_id' => $deal_id, 'slug_phase' => $slug, 'status_id' => $status_id]);
    // }
    if($status_id == 1){
        // error_log('[ispag_update_phase_status] do_action Envoi message ');
        do_action('ispag_send_mail_from_slug', null, $deal_id, $slug);
        do_action('ispag_send_telegram_notification', null, $slug, true, true, $deal_id, true);  

        // $notifier = new ISPAG_Telegram_Notifier('8084863181');

        // // // Ajouter un abonné (optionnel pour le moment)
        // // $notifier->add_subscriber(123456789, 'Jean Dupont');

        // // Envoyer une notification
        // $message = $notifier->get_message($slug, $deal_id);
        // $notifier->send_message($message, true, false, $deal_id);
    }

    // Retourner le nom et couleur pour MAJ visuelle
    $meta = $wpdb->get_row($wpdb->prepare("SELECT Nom, Couleur FROM $meta_table WHERE Id = %d", $status_id));
    wp_send_json_success([
        'name' => $meta->Nom,
        'color' => $meta->Couleur
    ]);
}

function display_ispag_suivis($deal_id, $isQuotation = false){
    $phases = (new ISPAG_Phase_Repository())->get_project_phases($deal_id, $isQuotation);

    if ($phases) {
        $can_edit = current_user_can('manage_order');
        $can_view_all = current_user_can('manage_order');

        echo '<div class="ispag-suivi-wrapper">'; // Nouveau wrapper pour centrer sur grand écran
        echo '<div class="ispag-suivi-steps">';

        foreach ($phases as $phase) {
            if (!$can_view_all && (int)$phase->VisuClient !== 1) {
                continue;
            }

            $classes = 'suivi-status-badge editable-status';
            if (!$can_edit) $classes .= ' non-editable';

            echo '<div class="suivi-step-row">';
                
                // Timeline visuelle
                echo '<div class="step-indicator">';
                    echo '<div class="step-dot" style="background-color: ' . esc_attr($phase->statut_couleur) . ';"></div>';
                    echo '<div class="step-line"></div>';
                echo '</div>';

                // Bloc Contenu rapproché
                echo '<div class="step-content-box">';
                    echo '<div class="step-main-info">';
                        echo '<span class="step-title">' . __(esc_html($phase->TitrePhase), 'creation-reservoir') . '</span>';
                        echo '<span class="step-date">' . ($phase->date_modification ? date('d.m.Y', strtotime($phase->date_modification)) : '--.--.--') . '</span>';
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

    } else {
        echo '<div class="ispag-notice">' . __('No phase defined for this project', 'creation-reservoir') . '</div>';
    }
}


function display_ispag_doc_manger($deal_id, $is_purchase = false){
    $docManager = new ISPAG_Document_Manager();
    $docs = $docManager->get_documents_grouped_by_article($deal_id, $is_purchase);
    echo $docManager->render_grouped_documents($docs);
}
 
function display_ispag_project_details($deal_id, $details){
    return (new ISPAG_Project_Details_Renderer())->display($deal_id, $details);
} 

function display_ispag_project_articles($deal_id, $isQotation = false){
    $can_manage_order = current_user_can('manage_order');
    $can_view_prices = current_user_can('display_sales_prices');

    $articles = new ISPAG_Article_Repository();
    
    if($can_view_prices):
        echo '<div id="ispag-bloc-stat-projet">';
        echo apply_filters('ispag_display_deal_stats', null, $deal_id);
        echo '</div>';
        // echo apply_filters('ispag_render_sales_coef_selector', '');
    endif;
    echo '<div id="display_article_page">';
    if($can_manage_order){
        // echo '<div id="ispag-bulk-message" style="display:none; padding:10px; margin-bottom:1rem; border-radius:5px;"></div>';
        echo '<div id="ispag-bulk-message" class="bulk_message"></div>';

        

        
        echo '
        <div class="ispag-article-header-global" style="margin-bottom: 1rem;">
            <input type="checkbox" id="select-all-articles" class="ispag-article-checkbox">
            <label for="select-all-articles">' . __('Select all', 'creation-reservoir') . '</label>
        </div>';
    }
    
    $details_repo = new ISPAG_Project_Details_Repository(); 
    $infos = $details_repo->get_infos_livraison($deal_id);
    // echo (new ISPAG_Detail_Page)->render_articles_list($articles->get_articles_by_deal($deal_id));
    
    // echo '<div class="ispag-articles-list">' . __('Loading', 'creation-reservoir') . ' ...</div>';
    echo '<div class="ispag-articles-content" id="display_ispag_article_list">';
    echo'
    <div class="ispag-article-list-overlay">
        <div id="ispag-loading-spinner"></div>
        
    </div>';
    echo '<div class="ispag-articles-list" data-deal-id=' . $deal_id . '">';
    // // Remplacer "fa-spinner" et "fa-spin" par la classe de l'icône de spinner de votre choix
    //     echo '<i class="fas fa-spinner fa-spin"></i> ' . __('Loading', 'creation-reservoir') . ' ...';
    echo '</div>';
    echo '<button id="ispag-add-article" class="ispag-btn ispag-btn-secondary-outlined" data-deal-id="' . esc_attr($deal_id) . '"><span class="dashicons dashicons-plus-alt"></span> ' . __('Add product', 'creation-reservoir'). '</button>';
    if($can_manage_order){
        echo !$isQotation ? ' ' . get_delivery_btn($infos) : null;
        echo $isQotation ? '<button id="convert-to-project" class="ispag-btn ispag-btn-secondary-outlined" data-id="' . $deal_id . '">' . __('Transform to project', 'creation-reservoir'). '</button>' : null;

        echo get_generate_po_button($deal_id);
        echo display_invoice_btn($deal_id);
        echo apply_filters('ispag_delete_project_btn', null, $deal_id);
        echo bulk_selected_article($deal_id);
    }
    echo '</div>';
    echo '</div>';
}

function ajax_get_generate_po_button() {
    $deal_id = intval($_POST['deal_id'] ?? 0);

    if (!$deal_id) {
        wp_send_json_error('Deal ID manquant');
    }

    $html = get_generate_po_button($deal_id);
    wp_send_json_success($html);
}

function get_generate_po_button($deal_id){
    $repo = new ISPAG_Article_Repository();
    $articles_list = $repo->get_articles_by_deal($deal_id);

    $has_pending_purchase_request = false;

    foreach ($articles_list as $group) {
        foreach ($group as $article) {
            // 1. Vérification de l'article principal
            if (empty($article->DemandeAchatOk)) {
                $has_pending_purchase_request = true;
                break 2; // On a trouvé un manque, on arrête tout
            }

            // 2. Vérification des articles secondaires rattachés
            if (!empty($article->secondaires)) {
                foreach ($article->secondaires as $secondaire) {
                    if (empty($secondaire->DemandeAchatOk)) {
                        $has_pending_purchase_request = true;
                        break 3; // On sort des 3 niveaux (secondaires, principaux, groupes)
                    }
                }
            }
        }
    }

    if ($has_pending_purchase_request) {
        return '<button id="generate-purchase-requests" class="ispag-btn ispag-btn-primary" data-deal-id="' . esc_attr($deal_id) . '">' . __('Generate purchase request', 'creation-reservoir') . '</button>';
    }

    return ''; // Rien à générer, tout est déjà validé
}
// function display_partial_invoice_btn($deal_id)
// {
//     // Affichage du bouton avec les data nécessaires
//     return sprintf(
//         '<button class="ispag-btn ispag-btn-%s project-action-btn" data-deal-id="%d" data-hook="%s">%s</button>',
        
//         'warning',
//         $deal_id,
//         'ispag_send_partial_invoice',
//         __('Send partial invoice request','creation-reservoir')
//     );
// }

function display_invoice_btn($deal_id)
{
    $repo = new ISPAG_Article_Repository();
    $articles_grouped = $repo->get_articles_by_deal($deal_id);

    $all_articles = [];
    foreach ($articles_grouped as $group) {
        foreach ($group as $article) {
            $all_articles[] = $article;
            if (!empty($article->secondaires)) {
                $all_articles = array_merge($all_articles, $article->secondaires);
            }
        }
    }

    $total = count($all_articles);
    $livrés = 0;
    $facturés = 0;

    foreach ($all_articles as $article) {
        $est_livré = !empty($article->Livre);
        $est_facturé = !empty($article->invoiced); // à adapter selon ton champ

        if ($est_livré) $livrés++;
        if ($est_facturé) $facturés++;
    }

    if ($livrés === 0) return ''; // Aucun livré
    if ($livrés === $total && $facturés < $livrés) {
        // Tous livrés → facture finale
        return sprintf(
            '<button class="ispag-btn ispag-btn-success project-action-btn" data-deal-id="%d" data-hook="ispag_send_final_invoice">%s</button>',
            $deal_id,
            __('Send final invoice', 'creation-reservoir')
        );
    }

    if ($livrés > 0 && $facturés < $livrés) {
        // Partiellement livrés, et certains pas encore facturés
        return sprintf(
            '<button class="ispag-btn ispag-btn-warning-outlined project-action-btn" data-deal-id="%d" data-hook="ispag_send_partial_invoice">%s</button>',
            $deal_id,
            __('Send partial invoice request','creation-reservoir')
        );
    }

    return ''; // Déjà facturé ou autre cas
}


function bulk_selected_article($deal_id){
    $can_manage_order = current_user_can('manage_order');
    if(!$can_manage_order){
        return false;
    }

    $discount_value = apply_filters('ispag_get_project_discount', null, $deal_id) ?? null;
    $bulk = '<div class="ispag-bulk-actions" style="border: 1px solid #ccc; padding: 1rem; margin: 1rem 0; display:none;">
        <h4>'. __('Bulk update selected articles', 'creation-reservoir') . '</h4>
        <input type="hidden" id="deal-id" value="'.$deal_id.'">

        <label>' . __('Factory departure date', 'creation-reservoir') . ' :
            <input type="date" id="bulk-date-depart">
        </label>

        <label>' . __('Delivery ETA', 'creation-reservoir') . ' :
            <input type="date" id="bulk-date-eta">
        </label>

        <label><input type="checkbox" id="bulk-demande-ok"> 🛒 ' . __('Purchase request OK', 'creation-reservoir') . '</label>
        <label><input type="checkbox" id="bulk-drawing-ok"> 📝 ' . __('Drawing approved', 'creation-reservoir') . '</label>

        <label>
            📦 ' . __('Delivered on', 'creation-reservoir') .' :
            <input type="date" id="bulk-livre-date">
        </label>

        <label>
            🧾 ' . __('Invoiced on', 'creation-reservoir') .' :
            <input type="date" id="bulk-invoiced-date">
        </label>

        <label>
            🧾 ' . __('Discount', 'creation-reservoir') .' :
            <span class="discount-input-container">
                <input 
                    type="number" 
                    id="bulk-discount" 
                    name="bulk-discount"
                    min="0" 
                    max="100" 
                    step="0.01" 
                    maxlength="5" 
                    style="width: 105px;" 
                    value="' . $discount_value . '"
                >
                <span class="unit">%</span>
            </span>
        </label>


        <button id="apply-bulk-update" class="ispag-btn ispag-btn-green">' . __('Apply changes', 'creation-reservoir') . '</button>
    </div>';
    // $bulk .='
    // <script>
    //     document.addEventListener(\'DOMContentLoaded\', function () {
    //         const cb = document.getElementById(\'bulk-demande-ok\');
    //         const db = document.getElementById(\'bulk-drawing-ok\');
    //         if(cb) {
    //             cb.indeterminate = true; // état par défaut indéterminé
    //             db.indeterminate = true; // état par défaut indéterminé
    //         }
    //     });
    //     document.querySelectorAll(\'.ispag-article-checkbox\').forEach(cb => {
    //         cb.addEventListener(\'change\', () => {
    //             const bulkDiv = document.querySelector(\'.ispag-bulk-actions\');
    //             const anyChecked = [...document.querySelectorAll(\'.ispag-article-checkbox\')].some(cb => cb.checked);
    //             if (anyChecked) {
    //                 bulkDiv.style.display = \'block\';
    //             } else {
    //                 bulkDiv.style.display = \'none\';
    //             }
    //         });
    //     });

    //     document.getElementById(\'apply-bulk-update\').addEventListener(\'click\', function () {
    //         const selectedIds = [...document.querySelectorAll(\'.ispag-article-checkbox:checked\')].map(cb => cb.dataset.articleId);

    //         if (selectedIds.length === 0) {
    //             alert("' .  __('No article selected', 'creation-reservoir') . '");
    //             return;
    //         }

    //         const data = {
    //             action: \'ispag_bulk_update_articles\',
    //             articles: selectedIds,
    //             deal_id: document.getElementById(\'deal-id\').value,
    //             date_depart: document.getElementById(\'bulk-date-depart\').value,
    //             date_eta: document.getElementById(\'bulk-date-eta\').value,
    //             livre_date: document.getElementById(\'bulk-livre-date\').value,
    //             invoiced_date: document.getElementById(\'bulk-invoiced-date\').value,
    //             discount: document.getElementById(\'bulk-discount\').value,
    //             _ajax_nonce: \'' .  wp_create_nonce('ispag_bulk_update') . '\'
    //         };


    //         const demandeOk = document.getElementById(\'bulk-demande-ok\');
    //         if (!demandeOk.indeterminate) {
    //             data.demande_ok = demandeOk.checked ? 1 : 0;
    //         }
    //         const drawingOk = document.getElementById(\'bulk-drawing-ok\');
    //         if (!drawingOk.indeterminate) {
    //             data.drawing_ok = drawingOk.checked ? 1 : 0;
    //         }

    //         fetch(\'' .  admin_url('admin-ajax.php') . '\', {
    //             method: \'POST\',
    //             headers: { \'Content-Type\': \'application/x-www-form-urlencoded\' },
    //             body: new URLSearchParams(data)
    //         })
    //         .then(res => res.json())
    //         .then(response => {
    //             console.log(\'response:\', response);
    //             const msgBox = document.getElementById(\'ispag-bulk-message\');

    //             if (response.success) {
    //                 msgBox.textContent = response.data.message;
    //                 msgBox.style.display = \'block\';
    //                 msgBox.style.backgroundColor = \'#d4edda\';
    //                 msgBox.style.color = \'#155724\';
    //                 msgBox.style.border = \'1px solid #c3e6cb\';

    //                 // Disparait au bout de 3 secondes
    //                 setTimeout(() => {
    //                     msgBox.style.display = \'none\';
    //                     location.reload();
    //                 }, 1000);
    //             } else {
    //                 msgBox.textContent = response.data?.message || \'Erreur inconnue\';
    //                 msgBox.style.display = \'block\';
    //                 msgBox.style.backgroundColor = \'#f8d7da\';
    //                 msgBox.style.color = \'#721c24\';
    //                 msgBox.style.border = \'1px solid #f5c6cb\';
    //             }
    //             // location.reload(); // ou refresh partiel
    //         });
    //     });
    // </script>

    // ';

    return $bulk;

    
} 
 
// function get_delivery_btn(){
//     return '<button id="generate-pdf" class="ispag-btn ispag-btn-secondary-outlined" style="margin-top: 1rem;">
//             📄 ' .  __('Delivery note', 'creation-reservoir') . '
//         </button>
//         <script>
//         document.getElementById( \'generate-pdf\').addEventListener(\'click\', function () {
//             const ids = [...document.querySelectorAll(\'.ispag-article-checkbox:checked\')]
//                 .map(cb => cb.dataset.articleId);

//             if (ids.length === 0) {
//                 alert("' .  __('No items selected', 'creation-reservoir') . '.");
//                 return;
//             }

//             const url = new URL(\'' . admin_url('admin-ajax.php') . '\');
//             url.searchParams.set(\'action\', \'ispag_generate_pdf\');
//             url.searchParams.set(\'deal_id\', getUrlParam(\'deal_id\'));
//             url.searchParams.set(\'ids\', ids.join(\',\'));

//             window.open(url.toString(), \'_blank\');
//         });
//         </script>';
// }
function get_delivery_btn($infos) {
    ob_start(); ?>
    <button id="generate-pdf" class="ispag-btn ispag-btn-secondary-outlined" style="margin-top: 1rem;">
        📄 <?= __('Delivery note', 'creation-reservoir'); ?>
    </button>

    <div id="delivery-modal" class="ispag-product-modal" style="display:none;">
        <div class="ispag-modal-content">
            <h3><?= __('Delivery information', 'creation-reservoir'); ?></h3>
            <form id="delivery-form">
                <?php
                // Ajout de 'delivery_date' dans la liste
                $champs = [
                    'delivery_date'      => __('Delivery date', 'creation-reservoir'), // Nouveau champ
                    'AdresseDeLivraison' => __('Adress', 'creation-reservoir'),
                    'DeliveryAdresse2'   => __('Complement', 'creation-reservoir'),
                    'NIP'                => __('Postal code', 'creation-reservoir'),
                    'City'               => __('City', 'creation-reservoir'),
                    'PersonneContact'    => __('Contact', 'creation-reservoir'),
                    'num_tel_contact'    => __('Phone', 'creation-reservoir'),
                ];
                
                foreach ($champs as $champ => $label): 
                    $val = $infos->$champ ?? '';
                    // Si c'est le champ date, on met la date du jour par défaut si vide
                    if ($champ === 'delivery_date' && empty($val)) {
                        $val = date('Y-m-d');
                    }
                    ?>
                    <p>
                        <label><strong><?= esc_html($label) ?> :</strong></label><br>
                        <input type="<?= ($champ === 'delivery_date') ? 'date' : 'text' ?>" 
                               name="<?= esc_attr($champ) ?>" 
                               value="<?= esc_attr($val) ?>" 
                               style="width:100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px;">
                    </p>
                <?php endforeach; ?>
                
                <div style="margin-top: 20px;">
                    <button type="button" id="confirm-delivery" class="ispag-btn">✅ <?= __('Confirm', 'creation-reservoir'); ?></button>
                    <button type="button" id="cancel-delivery" class="ispag-btn ispag-btn-secondary-outlined">❌ <?= __('Cancel', 'creation-reservoir'); ?></button>
                </div>
            </form>
        </div>
    </div>

    <script>
    const modal_delivery = document.getElementById('delivery-modal');
    const btn = document.getElementById('generate-pdf');
    const confirmBtn = document.getElementById('confirm-delivery');
    const cancelBtn = document.getElementById('cancel-delivery');
    const dealId = <?= intval($_GET['deal_id'] ?? 0); ?>;

    // Fermeture du modal si on clique à l'extérieur
    window.onclick = function(event) {
        if (event.target == modal_delivery) {
            modal_delivery.style.display = "none";
        }
    }

    btn.addEventListener('click', function() {
        const ids = [...document.querySelectorAll('.ispag-article-checkbox:checked')]
            .map(cb => cb.dataset.articleId);

        if (ids.length === 0) {
            alert("<?= __('No items selected', 'creation-reservoir'); ?>.");
            return;
        }
        modal_delivery.style.display = 'block';
    });

    cancelBtn.addEventListener('click', () => {
        modal_delivery.style.display = 'none';
    });

    confirmBtn.addEventListener('click', () => {
        const ids = [...document.querySelectorAll('.ispag-article-checkbox:checked')]
            .map(cb => cb.dataset.articleId);

        const formData = new FormData(document.getElementById('delivery-form'));
        const deliveryData = {};
        formData.forEach((val, key) => deliveryData[key] = val);

        const url = new URL('<?= admin_url('admin-ajax.php'); ?>');
        url.searchParams.set('action', 'ispag_generate_pdf');
        url.searchParams.set('deal_id', dealId);
        url.searchParams.set('ids', ids.join(','));
        // deliveryData contient maintenant delivery_date
        url.searchParams.set('delivery', JSON.stringify(deliveryData));

        window.open(url.toString(), '_blank');
        modal_delivery.style.display = 'none';
    });
    </script>
    <?php
    return ob_get_clean();
}


add_action('wp_ajax_ispag_generate_pdf', 'ispag_generate_pdf');
function ispag_generate_pdf() {
    if (!current_user_can('manage_order')) {
        wp_die('Non autorisé');
    }

    $deal_id = isset($_GET['deal_id']) ? sanitize_text_field($_GET['deal_id']) : '';
    $achat_id = isset($_GET['poid']) ? sanitize_text_field($_GET['poid']) : '';
    $ids_string = isset($_GET['ids']) ? sanitize_text_field($_GET['ids']) : '';
    $ids_string = trim($ids_string, ',');  // supprime les virgules au début et à la fin
    $ids = $ids_string === '' ? [] : explode(',', $ids_string);
    $ids = array_map('intval', $ids);
    $ids = array_filter($ids, fn($id) => $id > 0);

    if (empty($ids)) {
        wp_die('Aucun ID reçu');
    }

    // --- 1. RÉCUPÉRATION DES DONNÉES DE LA MODAL ---
    $final_date = date('d.m.Y'); // Date du jour par défaut
    $temp_delivery = [];

    if (!empty($_GET['delivery'])) {
        $temp_delivery = json_decode(stripslashes($_GET['delivery']), true);
        if (is_array($temp_delivery) && !empty($temp_delivery['delivery_date'])) {
            // Convertit le format HTML (YYYY-MM-DD) en format d'affichage (DD.MM.YYYY)
            $final_date = date('d.m.Y', strtotime($temp_delivery['delivery_date']));
        }
    }

    // Préparation des titres
    $titre_project = __('Project', 'creation-reservoir');
    $titre_ref = __('Project number', 'creation-reservoir');
    $titre_delivery_date = __('Delivery date', 'creation-reservoir');

    $articles = [];
    $table_header = [
        ['label' => __('Reference', 'creation-reservoir'), 'key' => 'ref', 'width' => 40],
        ['label' => __('Description', 'creation-reservoir'), 'key' => 'description', 'width' => 110],
        ['label' => __('Quantity', 'creation-reservoir'), 'key' => 'qty', 'width' => 30, 'align' => 'C'],
    ];

    // --- 2. RÉCUPÉRATION DES DONNÉES DU PROJET OU DE L'ACHAT ---
    if(!empty($deal_id)){
        $article_obj = apply_filters('ispag_get_article_by_id', null, $ids[0]);
        $deal_id_real = $article_obj->hubspot_deal_id;

        $details_repo = new ISPAG_Project_Details_Repository(); 
        $infos = $details_repo->get_infos_livraison($deal_id_real);

        $project_data = apply_filters('ispag_get_project_by_deal_id', null, $deal_id_real);

        $project_header = [
            $titre_project => $project_data->ObjetCommande ?? '',
            $titre_ref => $project_data->NumCommande ?? '',
            $titre_delivery_date => $final_date
        ];

        foreach ($ids as $id) {
            $article = apply_filters('ispag_get_article_by_id', null, $id);
            $articles[] = [
                'ref' => $article->prestation,
                'description' => $article->Article,
                'qty' => $article->Qty
            ];
        }
    } elseif(!empty($achat_id)){
        $details_repo = new ISPAG_Achat_Details_Repository(); 
        $infos = $details_repo->get_infos_livraison($achat_id);
        $project_repo = new ISPAG_Achat_Repository();
        $project_data_list = $project_repo->get_achats(null, null, $achat_id);
        $project_data = $project_data_list[0] ?? null;

        $project_header = [
            $titre_project => $project_data->RefCommande ?? '',
            $titre_ref => $project_data->NrCommande ?? '',
            $titre_delivery_date => $final_date
        ];

        foreach ($ids as $id) {
            $article = apply_filters('ispag_get_article_by_id', null, $id);
            $articles[] = [
                'ref' => $article->Id,
                'description' => $article->RefSurMesure,
                'qty' => $article->Qty
            ];
        }
    } else {
        wp_die('Aucun projet ou achat de defini');
    }

    // --- 3. MISE À JOUR DES INFOS DE LIVRAISON PAR LES SAISIES MODAL ---
    if (!empty($temp_delivery)) {
        foreach ($temp_delivery as $key => $val) {
            // On met à jour l'objet $infos avec les champs modifiés (Adresse, CP, Ville, etc.)
            // Sauf la date qui est déjà injectée dans $project_header
            if ($key !== 'delivery_date') {
                $infos->$key = sanitize_text_field($val);
            }
        }
    }

    // --- 4. GÉNÉRATION DU PDF ---
    $title = __('Delivery note', 'creation-reservoir');

    require_once plugin_dir_path(__FILE__) . '/class-ispag-pdf-generator.php';
    $pdf = new ISPAG_PDF_Generator();
    
    // Appel de la méthode de génération
    $pdf->generate_delivery_note($project_header, $project_data, $infos, $table_header, $articles, $title, false);
    
    $filename = sanitize_title($title);
    $pdf->Output('I', $filename . '.pdf');
    exit;
}

function sanitize_filename(string $filename): string {
    // Convertit les caractères accentués en leur équivalent ASCII
    $filename = iconv('UTF-8', 'ASCII//TRANSLIT', $filename);

    // Remplace les espaces et caractères non alphanumériques par des tirets
    $filename = preg_replace('/[^a-zA-Z0-9\-]/', '-', $filename);

    // Supprime les tirets en double
    $filename = preg_replace('/-+/', '-', $filename);

    // Supprime les tirets au début et à la fin
    $filename = trim($filename, '-');

    // Met en minuscules (optionnel)
    $filename = strtolower($filename);

    return $filename;
}

