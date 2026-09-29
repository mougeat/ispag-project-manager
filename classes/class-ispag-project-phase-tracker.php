<?php
/**
 * Écrit les changements de statut de phase (historique append-only) et déclenche
 * les effets de bord : notification interne staff + email client via Brevo.
 */
if (!defined('ABSPATH')) { exit; }

class ISPAG_Project_Phase_Tracker
{
    const TABLE_PURCHASE = 'wor9711_achats_liste_commande';
    const TABLE_SUIVI    = 'wor9711_achats_suivi_phase_commande';

    const SOURCE_MANUAL    = 'manual';
    const SOURCE_AUTOMATIC = 'automatic';
    const SOURCE_SYNC      = 'sync';

    /**
     * Enregistre un changement de statut pour une phase donnée et déclenche les effets de bord.
     *
     * @param int    $hubspot_deal_id
     * @param string $slug_phase
     * @param int    $status_id
     * @param array  $args {
     *     @type int|null $modified_by  user_id WP à l'origine du changement (null si automatique)
     *     @type string   $comment
     *     @type string   $source       self::SOURCE_MANUAL | SOURCE_AUTOMATIC | SOURCE_SYNC
     *     @type bool     $notify       envoyer les notifs/emails (true par défaut)
     * }
     * @return int|false Id de la ligne insérée, ou false en cas d'échec.
     */
    public static function record_status_change($hubspot_deal_id, $slug_phase, $status_id, array $args = [])
    {
        global $wpdb;

        $phase = ISPAG_Project_Phase_Catalog::get_slug_phase($slug_phase);
        if (!$phase)
        {
            return false;
        }

        $purchase = ISPAG_Project_Phase_Resolver::get_purchase($hubspot_deal_id);
        if (!$purchase)
        {
            return false;
        }

        $defaults = [
            'modified_by' => get_current_user_id() ?: null,
            'comment'     => null,
            'source'      => self::SOURCE_MANUAL,
            'notify'      => true,
        ];
        $args = array_merge($defaults, $args);

        $inserted = $wpdb->insert(
            self::TABLE_SUIVI,
            [
                'hubspot_deal_id'   => (int) $hubspot_deal_id,
                'slug_phase'        => $slug_phase,
                'status_id'         => (int) $status_id,
                'modified_by'       => $args['modified_by'],
                'comment'           => $args['comment'],
                'source'            => $args['source'],
                'date_modification' => current_time('mysql'),
            ],
            ['%d', '%s', '%d', '%d', '%s', '%s', '%s']
        );

        if (!$inserted)
        {
            return false;
        }

        $suivi_id = (int) $wpdb->insert_id;

        if ($args['notify'] && $status_id === 1)
        {
            // self::notify_internal($purchase, $phase, $status_id);
            self::notify_client($purchase, $phase, $status_id);
        }

        return $suivi_id;
    }

    /**
     * Notification interne : project_manager en priorité, created_by en fallback.
     */
    private static function notify_internal($purchase, $phase, $status_id)
    {
        $recipient = self::get_internal_recipient($purchase);
        if (!$recipient)
        {
            return;
        }

        $status = ISPAG_Project_Phase_Catalog::get_phase_status($status_id);

        ISPAG_Notifications_Manager::send(
            [$recipient],
            'deal_status_change',
            sprintf(
                __('%s : %s', 'creation-reservoir'),
                $purchase->ObjetCommande,
                __($phase->TitrePhase, 'creation-reservoir')
            ),
            $status ? __($status->Nom, 'creation-reservoir') : '',
            'project-detail/' . $purchase->hubspot_deal_id,
            $purchase->hubspot_deal_id,
            []
        );
    }

    private static function get_internal_recipient($purchase)
    {
        if (!empty($purchase->project_manager))
        {
            return (int) $purchase->project_manager;
        }

        return (int) $purchase->created_by;
    }

    /**
     * Email client via Brevo (template propre à la phase), routé via ISPAG_Notifications_Manager.
     * Destinataire principal = premier contact associé. En CC : les autres contacts associés
     * + les abonnés au projet (champ Abonne). Envoyé même si la phase n'est pas VisuClient
     * (ça ne concerne que l'affichage, pas la notification).
     */
    private static function notify_client($purchase, $phase, $status_id)
    {
        if ((int) $phase->Brevo_id === 0)
        {
            return;
        }


        $all_contact_ids = ISPAG_Project_Phase_Resolver::get_all_contact_ids($purchase);
        $primary_contact_id = $all_contact_ids[0] ?? null;
        if (!$primary_contact_id)
        {
            return;
        }

        $subscriber_ids = ISPAG_Project_Phase_Resolver::get_subscriber_ids($purchase);
        $project_manager_id = ISPAG_Project_Phase_Resolver::get_project_manager_id($purchase);

        // Fusion de tous les contacts (autres contacts, abonnés, chef de projet)
        $raw_cc_ids = array_merge(
            $all_contact_ids,
            $subscriber_ids,
            [$project_manager_id]
        );

        // Nettoyage : suppression des doublons, des valeurs vides et du contact principal
        $cc_ids = array_values(array_unique(array_diff(
            array_filter($raw_cc_ids), 
            [$primary_contact_id]
        )));

        $status = ISPAG_Project_Phase_Catalog::get_phase_status($status_id);

        $project = apply_filters('ispag_get_project_by_deal_id', null, $purchase->hubspot_deal_id);
        $article_repo = new ISPAG_Article_Repository();
        // $articles = $article_repo->get_articles_by_deal($purchase->hubspot_deal_id);
        if (current_user_can('navigate_new_project_details_presentation')) {
            $articles = $article_repo->get_optimised_articles_by_deal($purchase->hubspot_deal_id);
        }
        else{
            $articles = $article_repo->get_articles_by_deal($purchase->hubspot_deal_id);
        }
        $details_repo = new ISPAG_Project_Details_Repository();
        $infos = $details_repo->get_infos_livraison($purchase->hubspot_deal_id);

        $items = array();
        if (!empty($articles)) {
            foreach ($articles as $groupe => $articles_principaux) {
                foreach ($articles_principaux as $article) {
                    $items[] = $article;
                    // Si un plan existe et n'est pas encore approuvé, on l'ajoute en PJ
                    if (!empty($article->last_drawing_url) && ($article->DrawingApproved ?? false) != true) {
                        $attachments[] = array(
                            "url" => ISPAG_Brevo_Mailer::encode_url_path($article->last_drawing_url),
                            "name" => ISPAG_Brevo_Mailer::clean_filename($article->Article ?? 'document') . '.pdf'
                        );
                    }
                }
            }
        }

        $raw_params = array_merge(
            (array)$project,
            (array)$infos,
            ['items' => $items]
        );
        // Nettoyage des NULL pour éviter les plantages JSON
        $clean_params = array_map(function($v) { return is_null($v) ? '' : $v; }, $raw_params);

        // $brevo_params = [
        //     // TODO: harmoniser les clés avec les templates Brevo existants (variables {{ params.XXX }})
        //     'OBJET_COMMANDE' => (string) $purchase->ObjetCommande,
        //     'PHASE_TITLE'    => __($phase->TitrePhase, 'creation-reservoir'),
        // ];

        ISPAG_Notifications_Manager::send(
            [$primary_contact_id],
            'deal_status_change',
            sprintf(
                __('%s : %s', 'creation-reservoir'),
                $purchase->ObjetCommande,
                __($phase->TitrePhase, 'creation-reservoir')
            ),
            $status ? __($status->Nom, 'creation-reservoir') : '',
            'project-detail/' . $purchase->hubspot_deal_id,
            $purchase->hubspot_deal_id,
            [
                'cc_ids'       => $cc_ids,
                'brevo_params' => $clean_params,
                'template_id'  => ISPAG_Mail_Sender::getBrevoTemplateId($phase->SlugPhase),
                'delay'        => ISPAG_Mail_Sender::getBrevoDelayDays($phase->SlugPhase),
                'channels'     => ['mail'],
            ]
        );
    }

    private static function get_contact_email($contact_id)
    {
        $user = get_userdata((int) $contact_id);
        return ($user && !empty($user->user_email)) ? $user->user_email : null;
    }
}