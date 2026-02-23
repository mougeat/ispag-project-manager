<?php

class ISPAG_Notes_Manager {
    public static function init() {

        //On initilase le CRM
        if ( class_exists( 'ISPAG_Contact_Note_Manager' ) ) { 
            new ISPAG_Contact_Note_Manager();
        }

        // add_action('wp_enqueue_scripts', [self::class, 'enqueue_assets']);

        // // add_action('wp_ajax_ispag_add_note_ajax', [self::class, 'handle_ajax']);
        // // add_action('ispag_add_note', [self::class, 'add_note'], 10, 6);

        // add_filter('ispag_handle_add_notes_btn', [self::class, 'handle_add_notes_btn'],10 , 4 );
        // // add_action('wp_ajax_ispag_toggle_task_done', [self::class, 'toggle_task_done']);
        // // add_action('wp_ajax_ispag_delete_note', [self::class, 'delete_note']);

        // add_action('ispag_daily_notes_check', [self::class, 'check_pending_tasks']);
        // if (!wp_next_scheduled('ispag_daily_notes_check')) {
        //     wp_schedule_event(time(), 'daily', 'ispag_daily_notes_check');
        // }

 
    }

    public static function enqueue_assets() {
        
        // wp_enqueue_style('ispag-style', plugin_dir_url(__FILE__) . '../assets/css/main.css');

        

        // wp_enqueue_script('ispag-notes', plugin_dir_url(__FILE__) . '../assets/js/notes.js', [], false, true);
        // wp_localize_script('ispag-notes', 'ajaxurl', admin_url('admin-ajax.php'));

        // wp_localize_script('ispag-notes', 'ispagNotes', [
        //     'ajaxurl' => admin_url('admin-ajax.php'),
        //     'loading_text' => __('Loading', 'creation-reservoir'),
        //     'all_loaded_text' => __('All projects are loaded', 'creation-reservoir'),
        //     'doneLabel' => __('Done', 'creation-reservoir'),
        //     'taskLabel' => __('Task', 'creation-reservoir'),
        //     'confirmDeleteNote' => __('Delete this note', 'creation-reservoir'),
        //     'noteSaved' => __('Note saved', 'creation-reservoir'),
        //     'noteError' => __('Error', 'creation-reservoir'),
        //     'notePleaseWrite' => __('Please write a note', 'creation-reservoir'),
        // ]);
    }

    // public static function render_notes($deal_id = 0, $achat_id = 0, $is_log = 0) {
    //     $notes = self::get_notes($deal_id, $achat_id, $is_log);

    //     if (empty($notes)) {
    //         echo '<p>' . __('No notes yet', 'creation-reservoir') . '.</p>';
    //         echo apply_filters('ispag_handle_add_notes_btn', null, null, null, $deal_id);
    //         return;
    //     }

    //     echo '<div class="ispag-notes-grid" style="display: grid; gap: 1rem;">';

    //     foreach ($notes as $note) {
    //         $user_info = get_userdata($note->IdUser);
    //         $user_name = $user_info ? $user_info->display_name : __('Unknown user', 'creation-reservoir');
    //         $avatar = get_avatar($note->IdUser, 32);

    //         $badge = '';
    //         if ($note->is_task) {
    //             $done = intval($note->is_done);
    //             $badge = sprintf(
    //                 '<button class="ispag-toggle-task" data-note-id="%d" data-current="%d" style="background:%s;color:#fff;padding:2px 6px;border:none;border-radius:4px;font-size:12px;cursor:pointer;">
    //                     %s %s
    //                 </button>',
    //                 $note->Id,
    //                 $done,
    //                 $done ? '#4caf50' : '#ff9800',
    //                 $done ? '✓' : '☐',
    //                 $done ? __('Done', 'creation-reservoir') : __('Task', 'creation-reservoir')
    //             );
    //         }


    //         echo '<div class="ispag-note-card" style="border:1px solid #ccc;padding:1rem;border-radius:8px;background:#f9f9f9;">';
    //         echo '<div style="display:flex;align-items:center;gap:0.5rem;margin-bottom:0.5rem;">';
    //         echo $avatar . '<strong>' . esc_html($user_name) . '</strong>';
    //         $local_date = get_date_from_gmt($note->dateReadable); // convertit en timezone WP
    //         $formatted_date = date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($local_date));
    //         echo '<span style="margin-left:auto;font-size:12px;color:#666;">' . esc_html($formatted_date) . '</span>';
    //         echo '</div>';
    //         echo '<div style="margin-bottom:0.5rem;">' . nl2br(esc_html($note->Historique)) . '</div>';
    //         if ($badge) echo $badge;
    //         if (current_user_can('manage_order')) {
    //             echo sprintf(
    //                 '<form class="ispag-delete-note-form" method="post" style="display:inline;">
    //                     <input type="hidden" name="note_id" value="%d">
    //                     <button type="submit" class="ispag-delete-note" style="background:#f44336;color:#fff;border:none;padding:2px 6px;border-radius:4px;font-size:12px;cursor:pointer;margin-left:8px;">
    //                         %s
    //                     </button>
    //                 </form>',
    //                 esc_attr($note->Id),
    //                 esc_html__('Delete', 'creation-reservoir')
    //             );
    //         }

    //         echo '</div>';
    //     }

    //     echo '</div>';

    //     echo apply_filters('ispag_handle_add_notes_btn', null, null, null, $deal_id);
    // }



    // public static function get_notes($deal_id = 0, $achat_id = 0, $is_log = 0) {
    //     global $wpdb;
    //     $classCss = $is_log ? 'log' : 'notes';

    //     $where = ['ClassCss' => 'notes'];
    //     if ($deal_id) $where['hubspot_deal_id'] = $deal_id;
    //     if ($achat_id) $where['purchase_order'] = $achat_id;

    //     return $wpdb->get_results($wpdb->prepare(
    //         "SELECT * FROM {$wpdb->prefix}achats_historique
    //         WHERE ClassCss = %s
    //         AND hubspot_deal_id = %d AND purchase_order = %d
    //         ORDER BY Date DESC",
    //         $classCss, $deal_id, $achat_id
    //     ));
    // }

    // public static function toggle_task_done() {
    //     global $wpdb;

    //     $note_id = intval($_POST['note_id'] ?? 0);
    //     $is_done = intval($_POST['is_done'] ?? 0);

    //     if (!$note_id) {
    //         wp_send_json(['success' => false]);
    //     }

    //     $updated = $wpdb->update("{$wpdb->prefix}achats_historique", [
    //         'is_done' => $is_done
    //     ], [
    //         'Id' => $note_id
    //     ]);

    //     wp_send_json(['success' => $updated !== false]);
    // }


    public static function handle_add_notes_btn($html, $user_id = null, $company_id = null, $deal_id = null){
        return '<button class="ispag-btn ispag-action-btn" data-action="note" data-user-id="' . $user_id .'" data-company-id="' . $company_id .'" data-deal-id="' . $deal_id .'" title="' . __('Add note', 'creation-reservoir') . '">
                    <span class="dashicons dashicons-text-page"></span>
                        ' . __('Note', 'creation-reservoir') . '
                </button>';
        // return '
        // <button id="add-note-btn" class="ispag-btn ispag-btn-secondary-outlined">' . __('Add note', 'creation-reservoir') . '</button>

        // <div id="note-modal" style="display:none;">
        // <textarea id="note-content" placeholder="' . __('Write your note', 'creation-reservoir') . '..."></textarea><br>
        // <label><input type="checkbox" id="note-is-task"> ' . __('This is a task', 'creation-reservoir') . '</label><br>
        // <button id="save-note" class="ispag-btn ispag-btn-red-outlined"><span class="dashicons dashicons-media-archive"></span> ' . __('Save', 'creation-reservoir') . '</button>
        // </div>';

    }

    // public static function handle_ajax() {
    //     $note = sanitize_textarea_field($_POST['note'] ?? '');
    //     $is_task = intval($_POST['is_task'] ?? 0);
    //     $deal_id = intval($_POST['deal_id'] ?? 0);
    //     $achat_id = intval($_POST['achat_id'] ?? 0);

    //     $success = self::add_note(null, $note, $deal_id, $achat_id, $is_task);
    //     wp_send_json(['success' => $success]);
    // }

    // public static function add_note($html, $content, $deal_id = 0, $achat_id = 0, $is_task = 0, $is_log = 0) {
    //     global $wpdb;
    //     $typ = $is_log ? 'log' : 'notes';

    //     $data = [
    //         'hubspot_deal_id' => $deal_id,
    //         'purchase_order'  => $achat_id,
    //         'Date'            => time(),
    //         'dateReadable'    => current_time('mysql'),
    //         'IdUser'          => get_current_user_id(),
    //         'Historique'      => $content,
    //         'IdMedia'         => 0,
    //         'is_task'         => $is_task,
    //         'is_done'         => 0,
    //         'ClassCss'        => $typ
    //     ];

    //     // error_log('📝 [DEBUG ACHAT] Données préparées pour insertion : ' . json_encode($data));

    //     $result = $wpdb->insert("{$wpdb->prefix}achats_historique", $data);

    //     if ($result === false) {
    //         // error_log('❌ [DEBUG ACHAT] Échec de l’insertion dans achats_historique : ' . $wpdb->last_error);
    //         return false;
    //     }

    //     // error_log('✅ [DEBUG ACHAT] Note ajoutée avec succès. ID inséré : ' . $wpdb->insert_id);
    //     return true;
    // }


    // public static function delete_note() {
    //     if (!current_user_can('manage_order')) {
    //         wp_send_json(['success' => false]);
    //     }

    //     global $wpdb;
    //     $note_id = intval($_POST['note_id'] ?? 0);

    //     if (!$note_id) {
    //         wp_send_json(['success' => false]);
    //     }

    //     $deleted = $wpdb->delete("{$wpdb->prefix}achats_historique", ['Id' => $note_id]);
    //     wp_send_json(['success' => $deleted !== false]);
    // }

    public static function check_pending_tasks() {
        global $wpdb;

        $results = $wpdb->get_results("
            SELECT * FROM {$wpdb->prefix}achats_historique
            WHERE ClassCss = 'notes' AND is_task = 1 AND is_done = 0
        ");

        foreach ($results as $note) {
            $deal_id = intval($note->hubspot_deal_id);

            $project = apply_filters('ispag_get_project_by_deal_id', null, $deal_id);
            if (!$project) continue;

            $message = sprintf(
                "Tâche en attente pour le projet %s (%s)\nNote : %s\nVoir le projet : %s",
                $project->nom_entreprise,
                $project->contact_name,
                $note->Historique,
                $project->project_url
            );

            // error_log($message);
            // Optionnel : envoyer un email, Slack ou autre notif ici
            do_action('ispag_send_telegram_notification', null, $message);
        }
    }

}
