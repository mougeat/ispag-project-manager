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
}
