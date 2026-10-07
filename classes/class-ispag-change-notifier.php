<?php
defined('ABSPATH') || exit;

/**
 * Prévient le chef de projet (PM) quand une personne extérieure à ISPAG (client, ingénieur…) modifie des articles de son projet.
 *
 * Déclenché par l'action  do_action('ispag_article_modified', $article_id, $what, $deal_id = 0, $article_title = '')
 * ($what : created, updated, deleted, tank, fittings, exchangers). Les plugins (réservoirs, projets) l'appellent
 * après un enregistrement réussi ; sans PM défini sur le projet, ou si l'auteur est le PM ou un membre ISPAG, rien n'est envoyé.
 * Les modifications d'un même auteur sur un même projet sont regroupées : un seul message, envoyé WINDOW secondes après la première.
 */
class ISPAG_Change_Notifier {

    /** Délai (secondes) pendant lequel les modifications d'un même auteur sur un même projet sont regroupées en un seul message. */
    const WINDOW = 900;
    const QUEUE  = 'ispag_change_digest_queue';
    const FLUSH  = 'ispag_change_digest_flush';

    public static function init() {
        add_action('ispag_article_modified', [self::class, 'handle'], 10, 4);
        add_action(self::FLUSH, [self::class, 'flush']);
        add_action('ispag_save_drawing', [self::class, 'notify_drawing_to_approve'], 20, 5);
        // Plan validé / modifications demandées par téléversement d'un document : même notification qu'en ligne
        add_action('ispag_validate_drawing', [self::class, 'notify_plan_uploaded_validation'], 20, 5);
        add_action('ispag_save_drawing', [self::class, 'notify_plan_uploaded_modification'], 20, 5);
    }

    /** Membre de l'équipe ISPAG (administrateur, ou gestionnaire de commandes qui n'est ni ingénieur ni client). */
    public static function is_ispag_member($user_id) {
        $user_id = (int) $user_id;
        if (!$user_id) return false;
        if (user_can($user_id, 'manage_options')) return true;
        if (!user_can($user_id, 'manage_order')) return false;
        $u = get_userdata($user_id);
        return !($u && array_intersect(['ingenieur', 'client'], (array) $u->roles));
    }

    /**
     * Une modification d'article est mise en file ; un seul message par auteur et par projet part à la fin de la fenêtre
     * (flush). Rien n'est mis en file si l'auteur fait partie d'ISPAG, si le projet n'a pas de PM ou si l'auteur est le PM.
     */
    public static function handle($article_id, $what = 'updated', $deal_id = 0, $article_title = '') {
        global $wpdb;
        $actor_id = get_current_user_id();
        $article_id = (int) $article_id;
        if (!$actor_id || !$article_id || !class_exists('ISPAG_Notifications_Manager')) return;
        if (self::is_ispag_member($actor_id)) return;

        $article = $wpdb->get_row($wpdb->prepare(
            "SELECT Id, Article, hubspot_deal_id FROM {$wpdb->prefix}achats_details_commande WHERE Id = %d", $article_id
        ));
        $deal_id = (int) ($article->hubspot_deal_id ?? $deal_id);
        if (!$deal_id) return;

        $purchase = ISPAG_Project_Phase_Resolver::get_purchase($deal_id);
        $pm_id = $purchase ? ISPAG_Project_Phase_Resolver::get_project_manager_id($purchase) : null;
        if (!$pm_id || $pm_id === $actor_id) return;

        $title_article = $article ? trim(wp_strip_all_tags($article->Article)) : (trim(wp_strip_all_tags((string) $article_title)) ?: ('#' . $article_id));

        $key = $deal_id . '|' . $actor_id;
        $queue = (array) get_option(self::QUEUE, []);
        if (!isset($queue[$key])) {
            $queue[$key] = ['deal_id' => $deal_id, 'actor_id' => $actor_id, 'items' => []];
        }
        $item = $queue[$key]['items'][$article_id] ?? ['title' => $title_article, 'what' => []];
        $item['title'] = $title_article;
        if (!in_array($what, $item['what'], true)) $item['what'][] = $what;
        $queue[$key]['items'][$article_id] = $item;
        update_option(self::QUEUE, $queue, false);

        if (!wp_next_scheduled(self::FLUSH, [$key])) {
            wp_schedule_single_event(time() + self::WINDOW, self::FLUSH, [$key]);
        }
    }

    /** Envoie au PM le message groupé d'un auteur sur un projet. */
    public static function flush($key) {
        $queue = (array) get_option(self::QUEUE, []);
        if (empty($queue[$key])) return;
        $entry = $queue[$key];
        unset($queue[$key]);
        update_option(self::QUEUE, $queue, false);
        if (!class_exists('ISPAG_Notifications_Manager') || empty($entry['items'])) return;

        $deal_id  = (int) $entry['deal_id'];
        $actor_id = (int) $entry['actor_id'];
        $purchase = ISPAG_Project_Phase_Resolver::get_purchase($deal_id);
        $pm_id = $purchase ? ISPAG_Project_Phase_Resolver::get_project_manager_id($purchase) : null;
        if (!$pm_id || $pm_id === $actor_id) return;

        $actor = get_userdata($actor_id);
        $actor_name = $actor ? $actor->display_name : __('Someone', 'creation-reservoir');
        $project_name = $purchase->ObjetCommande ?? ('#' . $deal_id);

        $labels = [
            'created'    => __('item created', 'creation-reservoir'),
            'updated'    => __('item modified', 'creation-reservoir'),
            'deleted'    => __('item deleted', 'creation-reservoir'),
            'tank'       => __('design modified', 'creation-reservoir'),
            'fittings'   => __('fittings modified', 'creation-reservoir'),
            'exchangers' => __('heat exchangers modified', 'creation-reservoir'),
        ];
        $lines = [];
        $max = 8;
        $n = 0;
        foreach ($entry['items'] as $item) {
            $n++;
            if ($n > $max) continue;
            $verbs = array_map(function ($w) use ($labels) { return $labels[$w] ?? $labels['updated']; }, $item['what']);
            $lines[] = '- ' . esc_html($item['title']) . ' (' . esc_html(implode(', ', array_unique($verbs))) . ')';
        }
        if ($n > $max) $lines[] = '- ' . sprintf(__('… and %d more', 'creation-reservoir'), $n - $max);

        ISPAG_Notifications_Manager::send(
            [$pm_id],
            'article_changed_by_other',
            sprintf(_n('%1$s changed %2$d item in project %3$s', '%1$s changed %2$d items in project %3$s', $n, 'creation-reservoir'), $actor_name, $n, $project_name),
            implode('<br>', $lines),
            'project-detail/' . $deal_id,
            $deal_id,
            ['deal_id' => $deal_id, 'actor_id' => $actor_id, 'count' => $n]
        );
    }

    /**
     * Un plan à approuver (product_drawing) vient d'être joint : les personnes concernées par le projet
     * (AssociatedContactIDs) sont prévenues (cloche, push, mail selon leurs préférences), avec le lien de validation.
     */
    public static function notify_drawing_to_approve($html, $article_id, $attach_id, $user_id, $doc_type) {
        global $wpdb;
        if ($doc_type !== 'product_drawing' || !class_exists('ISPAG_Notifications_Manager')) return;

        $article_id = (int) $article_id;
        $article = $wpdb->get_row($wpdb->prepare(
            "SELECT Id, Article, hubspot_deal_id FROM {$wpdb->prefix}achats_details_commande WHERE Id = %d", $article_id
        ));
        if (!$article) return;

        $deal_id = (int) $article->hubspot_deal_id;
        $list = (string) $wpdb->get_var($wpdb->prepare(
            "SELECT AssociatedContactIDs FROM {$wpdb->prefix}achats_liste_commande WHERE hubspot_deal_id = %d LIMIT 1", $deal_id
        ));
        $contacts = array_values(array_unique(array_filter(array_map('intval', preg_split('/[\s;,]+/', $list)))));
        // L'auteur du téléversement n'a pas besoin d'être prévenu
        $contacts = array_values(array_diff($contacts, [(int) $user_id, get_current_user_id()]));
        if (!$contacts) return;

        $title_article = trim(wp_strip_all_tags((string) $article->Article)) ?: ('#' . $article_id);
        $project = ISPAG_Project_Phase_Resolver::get_purchase($deal_id);
        $project_name = $project->ObjetCommande ?? ('#' . $deal_id);

        // Lien direct vers la validation du plan (sinon, vers le projet)
        $url = apply_filters('ispag_plan_validation_url', 'project-detail/' . $deal_id, $article_id, (int) $attach_id);

        ISPAG_Notifications_Manager::send(
            $contacts,
            'drawing_to_approve',
            sprintf(__('📐 Drawing to approve: %s', 'creation-reservoir'), $title_article),
            sprintf(
                __('A drawing has been attached to "%1$s" (project %2$s). Please check it, then approve it or request modifications.', 'creation-reservoir'),
                $title_article, $project_name
            ),
            $url,
            $article_id,
            ['deal_id' => $deal_id, 'drawing_id' => (int) $attach_id]
        );
    }

    public static function notify_plan_uploaded_validation($html, $article_id, $attach_id, $user_id, $doc_type) {
        if ($doc_type === 'drawingApproval') self::notify_plan_uploaded('validated', $article_id, $user_id);
    }

    public static function notify_plan_uploaded_modification($html, $article_id, $attach_id, $user_id, $doc_type) {
        if ($doc_type === 'drawingModification') self::notify_plan_uploaded('modification', $article_id, $user_id);
    }

    /**
     * Une personne concernée par le projet téléverse un document « plan validé » ou « modifications » : le chef de projet,
     * le créateur du projet et l'administrateur reçoivent la même notification que lors d'une validation ou d'une demande
     * de modification faite en ligne (type product_manager). L'auteur du téléversement n'est jamais notifié.
     */
    private static function notify_plan_uploaded($kind, $article_id, $user_id) {
        global $wpdb;
        if (!class_exists('ISPAG_Notifications_Manager')) return;

        $article_id = (int) $article_id;
        $actor_id = (int) $user_id ?: get_current_user_id();
        $article = $wpdb->get_row($wpdb->prepare(
            "SELECT Id, hubspot_deal_id FROM {$wpdb->prefix}achats_details_commande WHERE Id = %d", $article_id
        ));
        if (!$article) return;

        $deal_id = (int) $article->hubspot_deal_id;
        $purchase = ISPAG_Project_Phase_Resolver::get_purchase($deal_id);

        $recipients = [1];
        if ($purchase) {
            $recipients[] = (int) ($purchase->project_manager ?? 0);
            $recipients[] = (int) ($purchase->created_by ?? 0);
        }
        $recipients = array_values(array_diff(array_unique(array_filter(array_map('intval', $recipients))), [$actor_id]));
        if (!$recipients) return;

        $who = get_userdata($actor_id);
        $name = $who ? $who->display_name : '';

        if ($kind === 'validated') {
            $title = sprintf(esc_html__('✅ Plan Validated: %s', 'ispag-crm'), esc_html($article_id));
            $message = sprintf(
                esc_html__('A plan has been validated by <strong>%1$s</strong> on %2$s.<br>- <strong>Article ID</strong>: %3$s<br>- <strong>Deal ID</strong>: %4$s', 'ispag-crm'),
                esc_html($name), esc_html(date_i18n('d/m/Y', current_time('timestamp'))), esc_html($article_id), esc_html($deal_id)
            );
        } else {
            $title = sprintf(esc_html__('✏️ Modifications requested: %s', 'ispag-crm'), esc_html($article_id));
            $message = sprintf(
                esc_html__('<strong>%1$s</strong> uploaded a document requesting modifications.<br>- <strong>Article ID</strong>: %2$s<br>- <strong>Deal ID</strong>: %3$s', 'ispag-crm'),
                esc_html($name), esc_html($article_id), esc_html($deal_id)
            );
        }

        ISPAG_Notifications_Manager::send($recipients, 'product_manager', $title, $message, 'project-detail/' . $deal_id . '/', $deal_id);
    }
}
