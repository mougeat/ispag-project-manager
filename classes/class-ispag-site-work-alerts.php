<?php
defined('ABSPATH') || exit;

/**
 * Alerte d'intervention sur site sans cuve livrée.
 *
 * Chaque matin, le site cherche les lignes d'isolation (Type 2) et de soudure (Type 3) des projets en cours dont la date d'intervention
 * (date de livraison / début de la ligne) tombe demain ou après-demain, alors que la cuve concernée n'est pas livrée. Une notification
 * part 2 jours avant, puis la veille, au chef de projet, au créateur du projet et à l'administrateur.
 * La cuve concernée est celle liée à la ligne (linked_tank) ; sans lien, toutes les cuves du projet : une seule non livrée suffit.
 */
class ISPAG_Site_Work_Alerts {

    const HOOK     = 'ispag_site_work_alerts';
    const HOUR     = 7;                         // heure du site
    const OFFSETS  = [2, 1];                    // jours avant l'intervention
    const OPT_SENT = 'ispag_site_work_alerts_sent';
    const TYPES    = [2 => 'insulation', 3 => 'welding'];

    public static function init() {
        add_action(self::HOOK, [__CLASS__, 'run']);
        if (!wp_next_scheduled(self::HOOK)) {
            $first = (new DateTimeImmutable('today ' . sprintf('%02d:00', self::HOUR), wp_timezone()))->getTimestamp();
            if ($first <= time()) $first += DAY_IN_SECONDS;
            wp_schedule_event($first, 'daily', self::HOOK);
        }
    }

    /** Lignes d'intervention dont la date tombe dans $offset jours et dont la cuve n'est pas livrée. @return array */
    public static function due($offset, $today = null) {
        global $wpdb;
        $today = $today ?: wp_date('Y-m-d');
        $day   = wp_date('Y-m-d', strtotime($today . ' +' . (int) $offset . ' day'));
        $from  = (new DateTimeImmutable($day . ' 00:00:00', wp_timezone()))->getTimestamp();
        $to    = $from + DAY_IN_SECONDS;
        $d  = $wpdb->prefix . 'achats_details_commande';
        $lc = $wpdb->prefix . 'achats_liste_commande';

        $rows = (array) $wpdb->get_results($wpdb->prepare(
            "SELECT d.Id, d.Article, d.Type, d.hubspot_deal_id, d.linked_tank, d.TimestampDateDeLivraison AS ts,
                    p.ObjetCommande AS project, p.project_manager, p.created_by
             FROM {$d} d
             INNER JOIN {$lc} p ON p.hubspot_deal_id = d.hubspot_deal_id
             WHERE d.Type IN (2, 3) AND d.archive = 0 AND d.is_variant = 0
               AND p.project_status = 1 AND (p.isQotation IS NULL OR p.isQotation = 0)
               AND d.TimestampDateDeLivraison >= %d AND d.TimestampDateDeLivraison < %d", $from, $to));

        $out = [];
        foreach ($rows as $r) {
            $tanks = (int) $r->linked_tank > 0
                ? (array) $wpdb->get_results($wpdb->prepare("SELECT Id, Article, Livre FROM {$d} WHERE Id = %d AND Type = 1", (int) $r->linked_tank))
                : (array) $wpdb->get_results($wpdb->prepare("SELECT Id, Article, Livre FROM {$d} WHERE hubspot_deal_id = %d AND Type = 1 AND archive = 0 AND is_variant = 0", (int) $r->hubspot_deal_id));
            $pending = array_values(array_filter($tanks, function ($t) { return empty($t->Livre); }));
            if (!$pending) continue;
            $r->day     = $day;
            $r->tanks   = array_map(function ($t) { return (string) $t->Article; }, $pending);
            $out[] = $r;
        }
        return $out;
    }

    public static function run() {
        if (!class_exists('ISPAG_Notifications_Manager')) return 0;
        $sent = (array) get_option(self::OPT_SENT, []);
        $today = wp_date('Y-m-d');
        // on oublie les alertes anciennes
        foreach ($sent as $k => $date) { if ($date < wp_date('Y-m-d', strtotime($today . ' -10 day'))) unset($sent[$k]); }

        $count = 0;
        foreach (self::OFFSETS as $offset) {
            // regroupées par projet : une notification par projet et par échéance
            $by_deal = [];
            foreach (self::due($offset, $today) as $r) {
                $key = $r->Id . ':' . $offset . ':' . $r->day;
                if (isset($sent[$key])) continue;
                $by_deal[(int) $r->hubspot_deal_id][] = [$r, $key];
            }
            foreach ($by_deal as $deal_id => $items) {
                $first   = $items[0][0];
                $lines   = [];
                foreach ($items as [$r]) {
                    $kind = (int) $r->Type === 3 ? __('Welding', 'creation-reservoir') : __('Insulation', 'creation-reservoir');
                    $lines[] = '• ' . $kind . ' – ' . wp_strip_all_tags((string) $r->Article) . ' (' . wp_date('d.m.Y', (int) $r->ts) . ') — '
                        . sprintf(__('tank not delivered: %s', 'creation-reservoir'), implode(', ', array_unique(array_map('wp_strip_all_tags', $r->tanks))));
                }
                $title = $offset === 1
                    ? sprintf(__('⚠️ Intervention tomorrow, tank not delivered: %s', 'creation-reservoir'), $first->project)
                    : sprintf(__('⚠️ Intervention in %1$d days, tank not delivered: %2$s', 'creation-reservoir'), $offset, $first->project);
                $recipients = array_values(array_unique(array_filter(array_map('intval', [1, $first->project_manager, $first->created_by]))));
                ISPAG_Notifications_Manager::send(
                    $recipients, 'deal_followup', $title, implode("\n", $lines),
                    home_url('/projectdetail/' . $deal_id . '/'), $deal_id
                );
                foreach ($items as [, $key]) $sent[$key] = $today;
                $count++;
            }
        }
        update_option(self::OPT_SENT, $sent, false);
        return $count;
    }
}
