<?php
/**
 * Écrit les changements de statut de phase (historique append-only) et déclenche
 * les effets de bord : notification interne staff + email client (ISPAG_Phase_Mail).
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
     * Email client de l'étape (template achats_template_mail de la phase, envoyé par ISPAG_Phase_Mail, sans Brevo).
     * Destinataire principal = premier contact associé. En CC : les autres contacts associés
     * + les abonnés au projet (champ Abonne) + le chef de projet. Envoyé même si la phase n'est pas VisuClient
     * (ça ne concerne que l'affichage, pas la notification).
     */
    private static function notify_client($purchase, $phase, $status_id)
    {
        if ((int) $phase->Brevo_id === 0)
        {
            return;
        }

        ISPAG_Phase_Mail::send_to_project((int) $purchase->hubspot_deal_id, (string) $phase->SlugPhase);
    }

    private static function get_contact_email($contact_id)
    {
        $user = get_userdata((int) $contact_id);
        return ($user && !empty($user->user_email)) ? $user->user_email : null;
    }
}