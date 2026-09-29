<?php
/**
 * Calcule, pour une commande/offre donnée (hubspot_deal_id), la liste ordonnée des phases
 * applicables et leur statut courant. Couche de LECTURE uniquement — aucune écriture ici.
 */
if (!defined('ABSPATH')) { exit; }

class ISPAG_Project_Phase_Resolver
{
    const TABLE_PURCHASE = 'wor9711_achats_liste_commande';
    const TABLE_DETAILS  = 'wor9711_achats_details_commande';
    const TABLE_SUIVI    = 'wor9711_achats_suivi_phase_commande';

    const DEFAULT_STATUS_ID = 10; // '-' (PhaseCmdAutre) quand aucune ligne de suivi n'existe encore

    const CONTEXT_INTERNAL = 'internal';
    const CONTEXT_CLIENT   = 'client';

    /**
     * Ligne de commande/offre (wor9711_achats_liste_commande) pour un hubspot_deal_id.
     */
    public static function get_purchase($hubspot_deal_id)
    {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT * FROM ' . self::TABLE_PURCHASE . ' WHERE hubspot_deal_id = %d',
                (int) $hubspot_deal_id
            )
        );

        return $row ?: null;
    }

    /**
     * Tous les IDs WP de AssociatedContactIDs (liste CSV, séparateur ','), le premier
     * étant par convention le contact principal.
     * @return int[]
     */
    public static function get_all_contact_ids($purchase)
    {
        if (empty($purchase->AssociatedContactIDs))
        {
            return [];
        }

        $ids = array_filter(array_map('trim', explode(',', $purchase->AssociatedContactIDs)));
        return array_values(array_unique(array_map('intval', $ids)));
    }

    /**
     * Premier contact de AssociatedContactIDs, par convention le contact principal.
     */
    public static function get_primary_contact_id($purchase)
    {
        $ids = self::get_all_contact_ids($purchase);
        return $ids[0] ?? null;
    }

    /**
     * IDs WP des abonnés au projet (champ Abonne, format ';123;456;789;').
     * @return int[]
     */
    public static function get_subscriber_ids($purchase)
    {
        $raw = trim((string) $purchase->Abonne, '; ');
        if ($raw === '')
        {
            return [];
        }

        $ids = array_filter(array_map('trim', explode(';', $raw)));
        return array_values(array_unique(array_map('intval', $ids)));
    }

        /**
     * Id WP du chef de projet (project_manager), ou null si non défini.
     */
    public static function get_project_manager_id($purchase)
    {
        return !empty($purchase->project_manager) ? (int) $purchase->project_manager : null;
    }

    /**
     * Id de type de prestation (wor9711_achats_type_prestations.Id) distincts présents
     * dans les lignes non archivées de la commande.
     */
    private static function get_present_type_ids($hubspot_deal_id)
    {
        global $wpdb;

        $type_ids = $wpdb->get_col(
            $wpdb->prepare(
                'SELECT DISTINCT Type FROM ' . self::TABLE_DETAILS . ' WHERE hubspot_deal_id = %d AND archive = 0',
                (int) $hubspot_deal_id
            )
        );

        return array_map('intval', $type_ids);
    }

    /**
     * Libellés de prestation ('Product', 'Isol', 'Welding', 'div', ...) présents dans la commande.
     * @return string[]
     */
    private static function get_present_prestations($hubspot_deal_id)
    {
        $prestations = [];

        foreach (self::get_present_type_ids($hubspot_deal_id) as $type_id)
        {
            $type_prestation = ISPAG_Project_Phase_Catalog::get_type_prestation($type_id);
            if ($type_prestation)
            {
                $prestations[$type_prestation->prestation] = true;
            }
        }

        return array_keys($prestations);
    }

    /**
     * Dernier statut enregistré pour chaque slug_phase (historique append-only : la ligne
     * la plus récente par slug_phase fait foi).
     * @return array<string, object> [slug_phase => ligne wor9711_achats_suivi_phase_commande]
     */
    private static function get_latest_statuses($hubspot_deal_id)
    {
        global $wpdb;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT * FROM ' . self::TABLE_SUIVI . ' WHERE hubspot_deal_id = %d ORDER BY date_modification ASC, id ASC',
                (int) $hubspot_deal_id
            )
        );

        $latest = [];
        foreach ($rows as $row)
        {
            $latest[$row->slug_phase] = $row; // la dernière ligne rencontrée écrase la précédente
        }

        return $latest;
    }

    /**
     * Calcule la liste ordonnée des phases applicables à une commande, avec leur statut courant.
     *
     * @param int    $hubspot_deal_id
     * @param string $context self::CONTEXT_INTERNAL | self::CONTEXT_CLIENT
     * @return array[] Liste de tableaux associatifs prêts pour l'affichage, triés par Ordre.
     */
    public static function resolve($hubspot_deal_id, $context = self::CONTEXT_INTERNAL)
    {
        $purchase = self::get_purchase($hubspot_deal_id);
        if (!$purchase)
        {
            return [];
        }

        $is_quotation        = ((int) $purchase->isQotation) === 1;
        $present_prestations = self::get_present_prestations($hubspot_deal_id);
        $latest_statuses     = self::get_latest_statuses($hubspot_deal_id);

        $result = [];

        foreach (ISPAG_Project_Phase_Catalog::get_slug_phases_sorted() as $phase)
        {
            // Filtre 1 : type de prestation (vide = toujours applicable).
            $applies_always      = ($phase->type_prestation === '');
            $applies_by_prestation = in_array($phase->type_prestation, $present_prestations, true);
            if (!$applies_always && !$applies_by_prestation)
            {
                continue;
            }

            // Filtre 2 : offre vs commande.
            if ($is_quotation && !$phase->display_on_qotation)
            {
                continue;
            }

            // Filtre 3 : visibilité client.
            if ($context === self::CONTEXT_CLIENT && !$phase->VisuClient)
            {
                continue;
            }

            $suivi     = $latest_statuses[$phase->SlugPhase] ?? null;
            $status_id = $suivi ? (int) $suivi->status_id : self::DEFAULT_STATUS_ID;
            $status    = ISPAG_Project_Phase_Catalog::get_phase_status($status_id);

            $result[] = [
                'slug_phase'        => $phase->SlugPhase,
                'phase'             => $phase,
                'status_id'         => $status_id,
                'status'            => $status,
                'date_modification' => $suivi->date_modification ?? null,
                'has_history'       => $suivi !== null,
            ];
        }

        return $result;
    }

    /**
     * Prochaine phase non validée pour une commande : la première phase applicable
     * (dans l'ordre) dont le statut courant n'a PAS TacheComplete = 1 (donc ni "Done" ni "N/A").
     *
     * @param int    $hubspot_deal_id
     * @param string $context self::CONTEXT_INTERNAL | self::CONTEXT_CLIENT
     * @return array|null Même structure qu'une entrée de resolve(), ou null si tout est validé/N-A.
     */
    public static function get_next_pending_phase($hubspot_deal_id, $context = self::CONTEXT_INTERNAL)
    {
        $rows = self::resolve($hubspot_deal_id, $context);

        foreach ($rows as $row)
        {
            $is_complete = $row['status'] && (int) $row['status']->TacheComplete === 1;

            if (!$is_complete)
            {
                return $row;
            }
        }

        return null; // toutes les phases applicables sont Done ou N/A
    }

    /**
     * Version publique de get_latest_statuses(), pour que l'Automation puisse comparer
     * le statut calculé au dernier statut enregistré sans réinventer la requête.
     * @return array<string, object>
     */
    public static function get_latest_statuses_public($hubspot_deal_id)
    {
        return self::get_latest_statuses($hubspot_deal_id);
    }
}