<?php
defined('ABSPATH') || exit;

/**
 * Prévient le chef de projet (PM) quand une autre personne que lui modifie un article de son projet.
 *
 * Déclenché par l'action  do_action('ispag_article_modified', $article_id, $what, $deal_id = 0, $article_title = '')
 * ($what : created, updated, deleted, tank, fittings, exchangers). Les plugins (réservoirs, projets) l'appellent
 * après un enregistrement réussi ; sans PM défini sur le projet, ou si l'auteur est le PM, rien n'est envoyé.
 * Une seule notification par article, auteur et fenêtre de temps (l'assistant de création enregistre plusieurs fois).
 */
class ISPAG_Change_Notifier {

    /** Délai (secondes) pendant lequel les modifications d'un même auteur sur un même article ne sont notifiées qu'une fois. */
    const WINDOW = 1200;

    public static function init() {
        add_action('ispag_article_modified', [self::class, 'handle'], 10, 4);
        add_action('ispag_save_drawing', [self::class, 'notify_drawing_to_approve'], 20, 5);
    }

    public static function handle($article_id, $what = 'updated', $deal_id = 0, $article_title = '') {
        global $wpdb;
        $actor_id = get_current_user_id();
        $article_id = (int) $article_id;
        if (!$actor_id || !$article_id || !class_exists('ISPAG_Notifications_Manager')) return;

        $article = $wpdb->get_row($wpdb->prepare(
            "SELECT Id, Article, hubspot_deal_id FROM {$wpdb->prefix}achats_details_commande WHERE Id = %d", $article_id
        ));
        $deal_id = (int) ($article->hubspot_deal_id ?? $deal_id);
        if (!$deal_id) return;

        $purchase = ISPAG_Project_Phase_Resolver::get_purchase($deal_id);
        $pm_id = $purchase ? ISPAG_Project_Phase_Resolver::get_project_manager_id($purchase) : null;
        if (!$pm_id || $pm_id === $actor_id) return;

        // Une seule notification par article et par auteur pendant la fenêtre
        $key = 'ispag_pm_notif_' . $article_id . '_' . $actor_id . '_' . $pm_id;
        if (get_transient($key)) return;
        set_transient($key, 1, self::WINDOW);

        $actor = get_userdata($actor_id);
        $actor_name = $actor ? $actor->display_name : __('Someone', 'creation-reservoir');
        $title_article = $article ? trim(wp_strip_all_tags($article->Article)) : (trim(wp_strip_all_tags((string) $article_title)) ?: ('#' . $article_id));
        $project_name = $purchase->ObjetCommande ?? ('#' . $deal_id);

        $labels = [
            'created'   => __('created', 'creation-reservoir'),
            'updated'   => __('modified', 'creation-reservoir'),
            'deleted'   => __('deleted', 'creation-reservoir'),
            'tank'      => __('modified the design of', 'creation-reservoir'),
            'fittings'  => __('modified the fittings of', 'creation-reservoir'),
            'exchangers' => __('modified the heat exchangers of', 'creation-reservoir'),
        ];
        $verb = $labels[$what] ?? $labels['updated'];

        $action = sprintf(__('%1$s %2$s the item "%3$s"', 'creation-reservoir'), $actor_name, $verb, $title_article);

        ISPAG_Notifications_Manager::send(
            [$pm_id],
            'article_changed_by_other',
            sprintf(__('Change by %1$s in project %2$s', 'creation-reservoir'), $actor_name, $project_name),
            $action,
            'project-detail/' . $deal_id,
            $article_id,
            ['deal_id' => $deal_id, 'actor_id' => $actor_id, 'what' => $what]
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
}
