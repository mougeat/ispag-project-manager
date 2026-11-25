<?php
defined('ABSPATH') || exit;

/**
 * Demande d'offre faite par un ingénieur depuis la page d'une offre.
 *
 * - Bouton « Request an offer » dans le bloc de boutons de la page, pour les ingénieurs seulement (rôle « ingenieur », jamais un membre ISPAG)
 *   et seulement sur une offre.
 * - Au clic : fenêtre avec un message libre (facultatif), puis envoi AJAX.
 * - Destinataire : le ou les owners ISPAG actifs du contact de l'ingénieur (CRM, table ispag_contacts_owners) ;
 *   à défaut le chef de projet de l'offre, puis l'administrateur.
 * - Notification à l'owner sur les trois canaux (cloche, push appli, e-mail), imposés : ils ne dépendent pas de ses préférences.
 * - L'ingénieur voit la confirmation à l'écran ; le bouton passe à « Request sent » (une demande par offre et par ingénieur).
 */
class ISPAG_Offer_Request {

    const ACTION = 'ispag_request_offer';
    const NONCE  = 'ispag_request_offer';
    const MAX_MESSAGE_LENGTH = 2000;

    public static function init() {
        add_action('wp_ajax_' . self::ACTION, [self::class, 'ajax_request']);
        add_action('wp_enqueue_scripts', [self::class, 'enqueue']);
    }

    /** Compte « ingénieur » : rôle ingenieur, sans être un membre ISPAG (qui garde ses propres boutons de gestion). */
    public static function is_engineer($user_id = 0) {
        $user_id = (int) ($user_id ?: get_current_user_id());
        if (!$user_id) return false;
        $user = get_userdata($user_id);
        if (!$user || !in_array('ingenieur', (array) $user->roles, true)) return false;
        return !ISPAG_Capabilities::can_manage_project_actions($user_id);
    }

    /** Le bouton est proposé à un ingénieur qui a accès à cette offre (et seulement tant que c'est une offre). */
    public static function can_request($deal_id, $is_qotation, $user_id = 0) {
        $user_id = (int) ($user_id ?: get_current_user_id());
        if (!$deal_id || !$is_qotation || !self::is_engineer($user_id)) return false;
        return ISPAG_Capabilities::can_access_project($deal_id, $user_id);
    }

    private static function option_key($deal_id, $user_id) {
        return 'ispag_offer_request_' . (int) $deal_id . '_' . (int) $user_id;
    }

    /** Demande déjà envoyée par cet ingénieur pour cette offre : ['time' => timestamp WP] ou null. */
    private static function get_sent($deal_id, $user_id) {
        $data = get_option(self::option_key($deal_id, $user_id), null);
        return (is_array($data) && !empty($data['time'])) ? $data : null;
    }

    /** HTML du bouton (chaîne vide si l'utilisateur n'y a pas droit). */
    public static function button_html($deal_id, $is_qotation) {
        $user_id = get_current_user_id();
        if (!self::can_request($deal_id, $is_qotation, $user_id)) return '';

        $sent = self::get_sent($deal_id, $user_id);
        if ($sent) {
            return sprintf(
                '<button type="button" id="ispag-request-offer" class="ispag-btn ispag-btn-secondary-outlined" data-deal-id="%d" disabled title="%s"><span class="dashicons dashicons-yes-alt"></span> %s</button>',
                (int) $deal_id,
                esc_attr(sprintf(__('Request sent on %s', 'creation-reservoir'), date_i18n(get_option('date_format') . ' ' . get_option('time_format'), (int) $sent['time']))),
                esc_html__('Request sent', 'creation-reservoir')
            );
        }

        return sprintf(
            '<button type="button" id="ispag-request-offer" class="ispag-btn ispag-btn-secondary-outlined" data-deal-id="%d"><span class="dashicons dashicons-email-alt"></span> %s</button>',
            (int) $deal_id,
            esc_html__('Request an offer', 'creation-reservoir')
        );
    }

    public static function enqueue() {
        if (!is_user_logged_in() || !self::is_engineer()) return;
        $rel = '../assets/js/offer-request.js';
        wp_enqueue_script(
            'ispag-offer-request',
            plugin_dir_url(__FILE__) . $rel,
            ['jquery'],
            (int) @filemtime(plugin_dir_path(__FILE__) . $rel),
            true
        );
        wp_localize_script('ispag-offer-request', 'ispag_offer_request', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce(self::NONCE),
            'max'      => self::MAX_MESSAGE_LENGTH,
        ]);
    }

    /** Owners ISPAG actifs du contact (l'ingénieur) ; sinon chef de projet de l'offre ; sinon administrateur. */
    private static function recipient_ids($engineer_id, $purchase) {
        global $wpdb;
        $ids = [];

        $table = $wpdb->prefix . 'ispag_contacts_owners';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table) {
            $ids = array_map('intval', (array) $wpdb->get_col($wpdb->prepare(
                "SELECT DISTINCT user_id FROM {$table} WHERE contact_id = %d AND status = 'active'",
                (int) $engineer_id
            )));
        }

        if (!$ids && $purchase) {
            $pm = ISPAG_Project_Phase_Resolver::get_project_manager_id($purchase);
            if ($pm) $ids[] = (int) $pm;
        }
        if (!$ids) $ids[] = 1;

        // Seulement des comptes qui existent, et jamais l'ingénieur lui-même
        return array_values(array_filter(array_unique($ids), function ($id) use ($engineer_id) {
            return $id && (int) $id !== (int) $engineer_id && get_userdata($id);
        }));
    }

    public static function ajax_request() {
        check_ajax_referer(self::NONCE, 'security');

        $user_id = get_current_user_id();
        $deal_id = isset($_POST['deal_id']) ? (int) $_POST['deal_id'] : 0;
        $message = isset($_POST['message']) ? sanitize_textarea_field(wp_unslash($_POST['message'])) : '';
        $message = function_exists('mb_substr') ? mb_substr($message, 0, self::MAX_MESSAGE_LENGTH) : substr($message, 0, self::MAX_MESSAGE_LENGTH);

        if (!$user_id || !$deal_id) {
            wp_send_json_error(['message' => __('Invalid request.', 'creation-reservoir')]);
        }

        $is_qotation = ISPAG_Projet_Repository::get_is_qotation_by_deal_id($deal_id);
        if (!self::can_request($deal_id, $is_qotation, $user_id)) {
            wp_send_json_error(['message' => __('You are not allowed to request an offer here.', 'creation-reservoir')], 403);
        }

        $already = self::get_sent($deal_id, $user_id);
        if ($already) {
            wp_send_json_error(['message' => sprintf(
                __('Your request was already sent on %s.', 'creation-reservoir'),
                date_i18n(get_option('date_format') . ' ' . get_option('time_format'), (int) $already['time'])
            )]);
        }

        if (!class_exists('ISPAG_Notifications_Manager')) {
            wp_send_json_error(['message' => __('The notification service is not available. Please try again later.', 'creation-reservoir')]);
        }

        $purchase     = ISPAG_Project_Phase_Resolver::get_purchase($deal_id);
        $project_name = $purchase && !empty($purchase->ObjetCommande) ? trim(wp_strip_all_tags($purchase->ObjetCommande)) : ('#' . $deal_id);
        $engineer     = get_userdata($user_id);
        $engineer_name = $engineer ? $engineer->display_name : __('An engineer', 'creation-reservoir');
        $recipients   = self::recipient_ids($user_id, $purchase);

        $lines = [sprintf(
            __('%1$s (%2$s) asks for an offer on "%3$s".', 'creation-reservoir'),
            esc_html($engineer_name),
            esc_html($engineer ? $engineer->user_email : ''),
            esc_html($project_name)
        )];
        if ($message !== '') {
            $lines[] = '<strong>' . esc_html__('Message', 'creation-reservoir') . ' :</strong><br>' . nl2br(esc_html($message));
        }

        // Type dédié (ISPAG CRM) s'il existe, sinon un type de projet existant qui est aussi retenu pendant une absence
        $types = ISPAG_Notifications_Manager::get_available_notification_types();
        $type  = isset($types['offer_requested']) ? 'offer_requested' : 'article_changed_by_other';

        ISPAG_Notifications_Manager::send(
            $recipients,
            $type,
            sprintf(__('📩 Offer requested: %s', 'creation-reservoir'), $project_name),
            implode('<br><br>', $lines),
            'project-detail/' . $deal_id,
            $deal_id,
            [
                'deal_id'   => $deal_id,
                'actor_id'  => $user_id,
                // Les trois canaux sont imposés : cloche, push (appli) et e-mail, quelles que soient les préférences du destinataire
                'channels'  => ['crm', 'push', 'mail'],
            ]
        );

        update_option(self::option_key($deal_id, $user_id), [
            'time'       => current_time('timestamp'),
            'message'    => $message,
            'recipients' => $recipients,
        ], false);

        $names = array_filter(array_map(function ($id) {
            $u = get_userdata($id);
            return $u ? $u->display_name : '';
        }, $recipients));

        wp_send_json_success([
            'message' => $names
                ? sprintf(__('Your offer request has been sent to %s.', 'creation-reservoir'), implode(', ', $names))
                : __('Your offer request has been sent.', 'creation-reservoir'),
            'label'   => __('Request sent', 'creation-reservoir'),
        ]);
    }
}
