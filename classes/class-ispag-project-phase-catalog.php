<?php
/**
 * Accès en cache (mémoire, durée de la requête) aux tables de référence du workflow de phases.
 * Tables : wor9711_achats_slug_phase, wor9711_achats_meta_phase_commande, wor9711_achats_type_prestations.
 */
if (!defined('ABSPATH')) { exit; }

class ISPAG_Project_Phase_Catalog
{
    const TABLE_SLUG_PHASE       = 'wor9711_achats_slug_phase';
    const TABLE_META_PHASE       = 'wor9711_achats_meta_phase_commande';
    const TABLE_TYPE_PRESTATIONS = 'wor9711_achats_type_prestations';

    private static $slug_phases      = null; // indexé par SlugPhase
    private static $phase_statuses   = null; // indexé par Id
    private static $type_prestations = null; // indexé par Id

    /**
     * @return array<string, object> Toutes les phases, indexées par SlugPhase.
     */
    public static function get_slug_phases($force_reload = false)
    {
        global $wpdb;

        if (self::$slug_phases === null || $force_reload)
        {
            $rows = $wpdb->get_results('
                SELECT
                    sp.*,
                    (
                        SELECT tp.color
                        FROM ' . self::TABLE_TYPE_PRESTATIONS . ' tp
                        WHERE tp.prestation = sp.type_prestation
                        ORDER BY tp.Id ASC
                        LIMIT 1
                    ) AS product_type_color
                FROM ' . self::TABLE_SLUG_PHASE . ' sp
            ');

            $indexed = [];
            foreach ($rows as $row)
            {
                $indexed[$row->SlugPhase] = $row;
            }
            self::$slug_phases = $indexed;
        }

        return self::$slug_phases;
    }

    /**
     * Toutes les phases triées par Ordre (tableau réindexé numériquement).
     * @return object[]
     */
    public static function get_slug_phases_sorted()
    {
        $phases = array_values(self::get_slug_phases());

        usort($phases, function ($a, $b) {
            return ((int) $a->Ordre) <=> ((int) $b->Ordre);
        });

        return $phases;
    }

    public static function get_slug_phase($slug)
    {
        $phases = self::get_slug_phases();
        return $phases[$slug] ?? null;
    }

    /**
     * @return array<int, object> Statuts possibles, indexés par Id.
     */
    public static function get_phase_statuses($force_reload = false)
    {
        global $wpdb;

        if (self::$phase_statuses === null || $force_reload)
        {
            $rows = $wpdb->get_results('SELECT * FROM ' . self::TABLE_META_PHASE);

            $indexed = [];
            foreach ($rows as $row)
            {
                $indexed[(int) $row->Id] = $row;
            }
            self::$phase_statuses = $indexed;
        }

        return self::$phase_statuses;
    }

    public static function get_phase_status($status_id)
    {
        $statuses = self::get_phase_statuses();
        return $statuses[(int) $status_id] ?? null;
    }

    /**
     * @return array<int, object> Types de prestation, indexés par Id.
     */
    public static function get_type_prestations($force_reload = false)
    {
        global $wpdb;

        if (self::$type_prestations === null || $force_reload)
        {
            $rows = $wpdb->get_results('SELECT * FROM ' . self::TABLE_TYPE_PRESTATIONS);

            $indexed = [];
            foreach ($rows as $row)
            {
                $indexed[(int) $row->Id] = $row;
            }
            self::$type_prestations = $indexed;
        }

        return self::$type_prestations;
    }

    public static function get_type_prestation($type_id)
    {
        $types = self::get_type_prestations();
        return $types[(int) $type_id] ?? null;
    }

    /**
     * À appeler après toute modification manuelle des tables de référence (admin).
     */
    public static function clear_cache()
    {
        self::$slug_phases      = null;
        self::$phase_statuses   = null;
        self::$type_prestations = null;
    }
}