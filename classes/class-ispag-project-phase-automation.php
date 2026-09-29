<?php
/**
 * Règles de validation automatique des phases (is_automatic = 1).
 * Calcule à la volée le statut "attendu" d'une phase à partir des lignes de commande
 * et des documents uploadés, et ne réécrit en base (via le Tracker) que si ce statut
 * diffère de l'historique existant. Appelé uniquement à l'affichage de l'onglet Follow up
 * — jamais en tâche de fond, jamais sur la sauvegarde d'une ligne.
 */
if (!defined('ABSPATH')) { exit; }

class ISPAG_Project_Phase_Automation
{
    const TABLE_DETAILS    = 'wor9711_achats_details_commande';
    const TABLE_HISTORIQUE = 'wor9711_achats_historique';

    // Statuts wor9711_achats_meta_phase_commande
    const STATUS_DONE               = 1;  // Done
    const STATUS_PROGRESS           = 2;  // In progress
    const STATUS_PENDING            = 3;  // In progress
    const STATUS_PLANNED            = 4;  // Planned
    const STATUS_NA                 = 5;  // N/A
    const STATUS_MODIFICATION_ASKED = 11; // Modifications asked
    const STATUS_OTHER = 10;

    /**
     * Familles de prestation concernées par les slugs *Delivered / *Invoice.
     */
    const FAMILY_MAP = [
        'ProductDelivered' => 'Product',
        'WeldingDelivered' => 'Welding',
        'IsolDelivered'    => 'Isol',
        'divDelivered'     => 'div',
        'ProductInvoice'   => 'Product',
        'WeldingInvoice'   => 'Welding',
        'IsolInvoice'      => 'Isol',
        'divInvoice'       => 'div',
    ];

    const DELIVERY_SLUGS = ['ProductDelivered', 'WeldingDelivered', 'IsolDelivered', 'divDelivered'];
    const INVOICE_SLUGS  = ['ProductInvoice', 'WeldingInvoice', 'IsolInvoice', 'divInvoice'];

    /**
     * Types de document (wor9711_achats_doc_types.slug) qui comptent dans le circuit
     * de plan produit, utilisés par PlanFournisseur et SignaturePlan.
     */
    const PLAN_DOC_TYPES = ['product_drawing', 'drawingApproval', 'drawingModification'];

    /**
     * Slugs pour lesquels un statut N/A (5) déjà enregistré fige la phase :
     * l'automatisation ne la recalcule plus tant que le statut n'a pas été
     * changé manuellement pour autre chose que N/A.
     */
    const SKIP_IF_NA_SLUGS = [
        // 'CmdFournisseur',
        'customer_request',
        'get_customer_order', // TODO: confirmer le slug exact, cf. évaluation précédente
        'DateLivraisonCuve',
        'competitor_in_request',
        'no_article',
        'all_items_have_sales_price',
    ];

    /**
     * Initialisation du cron. À appeler au bootstrap du plugin, comme les autres managers.
     */
    public static function init()
    {
        add_action('ispag_run_phase_automation_checks', [__CLASS__, 'run_all_active_projects']);

        if (!wp_next_scheduled('ispag_run_phase_automation_checks'))
        {
            wp_schedule_event(time(), 'hourly', 'ispag_run_phase_automation_checks');
        }
    }

    /**
     * Exécute run_checks() pour tous les projets actifs (project_status = 1).
     * Appelé par le cron horaire, en complément du déclenchement à l'ouverture de l'onglet.
     */
    public static function run_all_active_projects()
    {
        global $wpdb;

        $deal_ids = $wpdb->get_col(
            'SELECT hubspot_deal_id FROM ' . ISPAG_Project_Phase_Resolver::TABLE_PURCHASE . ' WHERE project_status = 1'
        );

        foreach ($deal_ids as $hubspot_deal_id)
        {
            self::run_checks((int) $hubspot_deal_id);
        }
    }

    /**
     * Exécute tous les contrôles automatiques pour une commande, et enregistre
     * (via le Tracker) les changements de statut détectés.
     */
    public static function run_checks($hubspot_deal_id)
    {
        $latest_statuses = ISPAG_Project_Phase_Resolver::get_latest_statuses_public($hubspot_deal_id);

        foreach (ISPAG_Project_Phase_Catalog::get_slug_phases_sorted() as $phase)
        {
            if (!(int) $phase->is_automatic)
            {
                continue;
            }

            $current = $latest_statuses[$phase->SlugPhase] ?? null;
            $current_status_id = $current ? (int) $current->status_id : null;

            // Phase gelée : déjà N/A et concernée par la règle de gel → on ne recalcule pas.
            if ($current_status_id === self::STATUS_NA && in_array($phase->SlugPhase, self::SKIP_IF_NA_SLUGS, true))
            {
                continue;
            }

            $computed_status_id = self::evaluate($hubspot_deal_id, $phase->SlugPhase);
            if ($computed_status_id === null)
            {
                continue;
            }

            if ($current_status_id === $computed_status_id)
            {
                continue;
            }

            ISPAG_Project_Phase_Tracker::record_status_change(
                $hubspot_deal_id,
                $phase->SlugPhase,
                $computed_status_id,
                [
                    'modified_by' => null,
                    'source'      => ISPAG_Project_Phase_Tracker::SOURCE_AUTOMATIC,
                ]
            );
        }
    }

    /**
     * Calcule le statut attendu pour un slug donné, ou null si aucune règle ne le concerne.
     */
    private static function evaluate($hubspot_deal_id, $slug_phase)
    {
        if ($slug_phase === 'CmdFournisseur')
        {
            return self::evaluate_supplier_order($hubspot_deal_id);
        }

        if (in_array($slug_phase, self::DELIVERY_SLUGS, true))
        {
            return self::evaluate_family_flag($hubspot_deal_id, self::FAMILY_MAP[$slug_phase], 'Livre');
        }

        if (in_array($slug_phase, self::INVOICE_SLUGS, true))
        {
            return self::evaluate_family_flag($hubspot_deal_id, self::FAMILY_MAP[$slug_phase], 'invoiced');
        }

        if ($slug_phase === 'customer_request')
        {
            return self::evaluate_customer_request($hubspot_deal_id);
        }

        if ($slug_phase === 'get_customer_order')
        {
            return self::evaluate_customer_order_received($hubspot_deal_id);
        }

        if ($slug_phase === 'PlanFournisseur')
        {
            return self::evaluate_plan_fournisseur($hubspot_deal_id);
        }

        if ($slug_phase === 'SignaturePlan')
        {
            return self::evaluate_signature_plan($hubspot_deal_id);
        }

        if ($slug_phase === 'DateLivraisonCuve')
        {
            return self::evaluate_date_livraison_cuve($hubspot_deal_id);
        }
        if ($slug_phase === 'competitor_in_request')
        {
            return self::evaluate_competitor_in_request($hubspot_deal_id);
        }

        if ($slug_phase === 'no_article')
        {
            return self::evaluate_no_article($hubspot_deal_id);
        }

        if ($slug_phase === 'all_items_have_sales_price')
        {
            return self::evaluate_all_items_have_sales_price($hubspot_deal_id);
        }

        return null;
    }

    /**
     * CmdFournisseur : porte sur TOUTES les lignes de la commande (pas de filtre de famille).
     * - Aucune ligne                                        => N/A
     * - Aucune demande à faire (DemandeAchatOk = 1 partout) => Done
     * - Au moins une demande à faire (DemandeAchatOk NULL)  => In progress
     */
    private static function evaluate_supplier_order($hubspot_deal_id)
    {
        global $wpdb;

        $total = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . self::TABLE_DETAILS . ' WHERE hubspot_deal_id = %d AND archive = 0',
            $hubspot_deal_id
        ));

        if ($total === 0)
        {
            return self::STATUS_NA;
        }

        $pending = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . self::TABLE_DETAILS . ' WHERE hubspot_deal_id = %d AND archive = 0 AND DemandeAchatOk IS NULL',
            $hubspot_deal_id
        ));

        return $pending === 0 ? self::STATUS_DONE : self::STATUS_OTHER;
    }

    /**
     * Règle commune aux slugs *Delivered (colonne Livre) et *Invoice (colonne invoiced),
     * filtrée sur une famille de prestation (Product / Welding / Isol / div).
     * - Aucun article de la famille                            => N/A
     * - Tous les articles ont la colonne renseignée (non NULL) => Done
     * - Au moins un article a la colonne NULL                  => In progress
     */
    private static function evaluate_family_flag($hubspot_deal_id, $prestation, $flag_column)
    {
        global $wpdb;

        $type_ids = self::get_type_ids_for_prestation($prestation);
        if (empty($type_ids))
        {
            return self::STATUS_NA;
        }

        $placeholders = implode(',', array_fill(0, count($type_ids), '%d'));

        $total = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . self::TABLE_DETAILS . '
             WHERE hubspot_deal_id = %d AND archive = 0 AND Type IN (' . $placeholders . ')',
            array_merge([$hubspot_deal_id], $type_ids)
        ));

        if ($total === 0)
        {
            return self::STATUS_NA;
        }

        $pending = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . self::TABLE_DETAILS . '
             WHERE hubspot_deal_id = %d AND archive = 0 AND Type IN (' . $placeholders . ')
             AND ' . $flag_column . ' IS NULL',
            array_merge([$hubspot_deal_id], $type_ids)
        ));

        return $pending === 0 ? self::STATUS_DONE : self::STATUS_PROGRESS;
    }

    /**
     * Ids de wor9711_achats_type_prestations dont la colonne 'prestation' correspond
     * à la famille demandée (ex: 'Product' => [1, 6, 7, 9, 10, 12]).
     * @return int[]
     */
    private static function get_type_ids_for_prestation($prestation)
    {
        $ids = [];
        foreach (ISPAG_Project_Phase_Catalog::get_type_prestations() as $type_id => $type_prestation)
        {
            if ($type_prestation->prestation === $prestation)
            {
                $ids[] = $type_id;
            }
        }
        return $ids;
    }

    /**
     * customer_request : au moins 1 document ClassCss = 'request_supplier_quotation' pour ce deal.
     */
    private static function evaluate_customer_request($hubspot_deal_id)
    {
        global $wpdb;

        $exists = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . self::TABLE_HISTORIQUE . '
             WHERE hubspot_deal_id = %d AND ClassCss = %s',
            $hubspot_deal_id,
            'request_supplier_quotation'
        ));

        return $exists > 0 ? self::STATUS_DONE : self::STATUS_PROGRESS;
    }

    /**
     * Au moins 1 document ClassCss = 'customer_order' pour ce deal.
     */
    private static function evaluate_customer_order_received($hubspot_deal_id)
    {
        global $wpdb;

        $exists = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . self::TABLE_HISTORIQUE . '
             WHERE hubspot_deal_id = %d AND ClassCss = %s',
            $hubspot_deal_id,
            'customer_order'
        ));

        return $exists > 0 ? self::STATUS_DONE : self::STATUS_OTHER;
    }

    /**
     * Articles Type = 1 (product, littéral) non archivés de la commande.
     * @return int[] Ids de wor9711_achats_details_commande
     */
    private static function get_type1_article_ids($hubspot_deal_id)
    {
        global $wpdb;

        $ids = $wpdb->get_col($wpdb->prepare(
            'SELECT Id FROM ' . self::TABLE_DETAILS . '
             WHERE hubspot_deal_id = %d AND archive = 0 AND Type = 1',
            $hubspot_deal_id
        ));

        return array_map('intval', $ids);
    }

    /**
     * Pour une liste d'articles, renvoie le ClassCss du dernier document uploadé
     * (parmi PLAN_DOC_TYPES uniquement), indexé par article_id. Un article sans
     * document parmi ces 3 types n'apparaît pas dans le résultat.
     * @return array<int, string>
     */
    private static function get_last_plan_document_by_article($hubspot_deal_id, array $article_ids)
    {
        global $wpdb;

        if (empty($article_ids))
        {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($article_ids), '%d'));
        $article_ids_str = array_map('strval', $article_ids); // Historique est stocké en texte

        $sql = 'SELECT article_id, ClassCss FROM (
                    SELECT
                        h.Historique AS article_id,
                        h.ClassCss,
                        ROW_NUMBER() OVER (PARTITION BY h.Historique ORDER BY h.Date DESC, h.Id DESC) AS rn
                    FROM ' . self::TABLE_HISTORIQUE . ' h
                    WHERE h.hubspot_deal_id = %d
                      AND h.ClassCss IN (\'product_drawing\', \'drawingApproval\', \'drawingModification\')
                      AND h.Historique IN (' . $placeholders . ')
                ) ranked
                WHERE rn = 1';

        $rows = $wpdb->get_results($wpdb->prepare($sql, array_merge([$hubspot_deal_id], $article_ids_str)));

        $result = [];
        foreach ($rows as $row)
        {
            $result[(int) $row->article_id] = $row->ClassCss;
        }

        return $result;
    }

    /**
     * PlanFournisseur :
     * - Aucun article Type=1                                       => N/A
     * - Au moins 1 dernier doc = drawingModification                => Modifications asked (priorité)
     * - Tous les derniers docs = product_drawing OU drawingApproval => Done
     *   (un plan approuvé implique qu'il a forcément été reçu avant)
     * - Sinon (manquant ou autre classCss)                          => In progress
     */
    private static function evaluate_plan_fournisseur($hubspot_deal_id)
    {
        $article_ids = self::get_type1_article_ids($hubspot_deal_id);
        if (empty($article_ids))
        {
            return self::STATUS_NA;
        }

        $last_docs = self::get_last_plan_document_by_article($hubspot_deal_id, $article_ids);

        $has_modification = false;
        $all_received     = true;

        foreach ($article_ids as $id)
        {
            $classcss = $last_docs[$id] ?? null;

            if ($classcss === 'drawingModification')
            {
                $has_modification = true;
                $all_received     = false;
            }
            elseif (!in_array($classcss, ['product_drawing', 'drawingApproval'], true))
            {
                $all_received = false;
            }
        }

        if ($has_modification)
        {
            return self::STATUS_MODIFICATION_ASKED;
        }

        return $all_received ? self::STATUS_DONE : self::STATUS_PENDING;
    }

    /**
     * SignaturePlan :
     * - Aucun article Type=1                                          => N/A
     * - Tous les derniers docs (parmi les 3 types) = drawingApproval   => Done
     * - Sinon                                                         => In progress
     */
    private static function evaluate_signature_plan($hubspot_deal_id)
    {
        $article_ids = self::get_type1_article_ids($hubspot_deal_id);
        if (empty($article_ids))
        {
            return self::STATUS_NA;
        }

        $last_docs = self::get_last_plan_document_by_article($hubspot_deal_id, $article_ids);

        foreach ($article_ids as $id)
        {
            if (($last_docs[$id] ?? null) !== 'drawingApproval')
            {
                return self::STATUS_PROGRESS;
            }
        }

        return self::STATUS_DONE;
    }

    /**
     * DateLivraisonCuve : au moins 1 article (tous types confondus) a une date de
     * livraison renseignée (début OU fin).
     */
    private static function evaluate_date_livraison_cuve($hubspot_deal_id)
    {
        global $wpdb;

        $exists = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . self::TABLE_DETAILS . '
             WHERE hubspot_deal_id = %d AND archive = 0
               AND (
                   (TimestampDateDeLivraison IS NOT NULL AND TimestampDateDeLivraison != 0)
                   OR (TimestampDateDeLivraisonFin IS NOT NULL AND TimestampDateDeLivraisonFin != 0)
               )',
            $hubspot_deal_id
        ));

        return $exists > 0 ? self::STATUS_PLANNED : self::STATUS_PROGRESS;
    }

    /**
     * competitor_in_request : un concurrent est renseigné dans EnSoumission (non vide).
     */
    private static function evaluate_competitor_in_request($hubspot_deal_id)
    {
        $purchase = ISPAG_Project_Phase_Resolver::get_purchase($hubspot_deal_id);
        if (!$purchase)
        {
            return self::STATUS_OTHER;
        }

        $has_competitor = trim((string) $purchase->EnSoumission) !== '';

        return $has_competitor ? self::STATUS_DONE : self::STATUS_OTHER;
    }

    /**
     * no_article : la commande n'a aucun article (non archivé).
     */
    private static function evaluate_no_article($hubspot_deal_id)
    {
        global $wpdb;

        $total = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . self::TABLE_DETAILS . ' WHERE hubspot_deal_id = %d AND archive = 0',
            $hubspot_deal_id
        ));

        return $total === 0 ? self::STATUS_OTHER : self::STATUS_DONE;
    }

    /**
     * all_items_have_sales_price : toutes les lignes MAÎTRES (IdArticleMaster = 0, non archivées)
     * ont un prix de vente calculé > 0, via le moteur de pricing (ispag_calculate_total_sales_price)
     * plutôt que la colonne brute sales_price — qui reste à 0 pour les articles standard/calculés
     * tant qu'aucun override manuel n'est posé (is_manual_price). Le total du maître inclut déjà
     * ses éventuels articles secondaires, donc on ne teste pas ces derniers séparément.
     * Une commande sans aucune ligne maître renvoie STATUS_OTHER (rien à valider).
     */
    private static function evaluate_all_items_have_sales_price($hubspot_deal_id)
    {
        global $wpdb;

        $master_ids = $wpdb->get_col($wpdb->prepare(
            'SELECT Id FROM ' . self::TABLE_DETAILS . '
             WHERE hubspot_deal_id = %d AND archive = 0 AND IdArticleMaster = 0',
            $hubspot_deal_id
        ));

        if (empty($master_ids))
        {
            return self::STATUS_OTHER;
        }

        foreach ($master_ids as $master_id)
        {
            $total_price = apply_filters('ispag_calculate_total_sales_price', (int) $master_id);

            if ((float) $total_price <= 0)
            {
                return self::STATUS_OTHER;
            }
        }

        return self::STATUS_DONE;
    }
}