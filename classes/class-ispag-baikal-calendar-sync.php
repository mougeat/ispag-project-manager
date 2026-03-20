<?php

/**
 * Classe de synchronisation des livraisons ISPAG vers le calendrier Baïkal (CalDAV)
 */
class ISPAG_Baikal_Calendar_Sync {

    private $baikal_ip       = 'contacts.barthels.duckdns.org';
    private $calendar_token  = 'livraisons'; // Nom du calendrier créé dans Baïkal
    private $baikal_pass     = 'IsPaG2026SecureSync';
    private static $log_file = WP_CONTENT_DIR . '/ispag_calendar_sync.log';

    public function __construct() {
        // Enregistrement du CRON WordPress
        if (!wp_next_scheduled('ispag_cron_sync_calendar')) {
            wp_schedule_event(time(), 'hourly', 'ispag_cron_sync_calendar');
        }
        
        // Liaison de l'action CRON à la méthode de synchro
        add_action('ispag_cron_sync_calendar', [$this, 'sync_all_deliveries_cron']);
        
        // Optionnel : On peut aussi écouter les mises à jour de commande en temps réel si tu as un hook
        // add_action('ispag_delivery_updated', [$this, 'sync_delivery_to_baikal'], 10, 1);
    }

    private function log($message) {
        $timestamp = date('Y-m-d H:i:s');
        $log_message = "[{$timestamp}] {$message}\n";
        file_put_contents(self::$log_file, $log_message, FILE_APPEND);
    }

    /**
     * Boucle principale exécutée par le CRON
     */
    public function sync_all_deliveries_cron() {
        global $wpdb;
        $this->log("--- DÉBUT CRON SYNCHRO CALENDRIER ---");

        // On synchronise les livraisons (de -30 jours à +90 jours pour limiter la charge)
        $start_limit = time() - (DAY_IN_SECONDS * 30);
        $end_limit   = time() + (DAY_IN_SECONDS * 90);

        $deliveries = $wpdb->get_results($wpdb->prepare("
            SELECT Id FROM {$wpdb->prefix}achats_details_commande 
            WHERE TimestampDateDeLivraison >= %d AND TimestampDateDeLivraison <= %d
        ", $start_limit, $end_limit));

        if (empty($deliveries)) {
            $this->log("Aucune livraison à synchroniser dans la plage définie.");
            return;
        }

        $count = 0;
        foreach ($deliveries as $d) {
            $this->sync_delivery_to_baikal($d->Id);
            $count++;
        }

        $this->log("Fin du CRON. {$count} livraisons traitées.");
    }

    /**
     * Synchronise une seule livraison vers Baïkal
     */
    public function sync_delivery_to_baikal($delivery_id) {
        global $wpdb;

        // Récupération des données de la livraison et du type de prestation (pour le nom et la couleur)
        $ev = $wpdb->get_row($wpdb->prepare("
            SELECT d.*, t.prestation 
            FROM {$wpdb->prefix}achats_details_commande d
            LEFT JOIN {$wpdb->prefix}achats_type_prestations t ON d.Type = t.Id
            WHERE d.Id = %d
        ", $delivery_id));

        if (!$ev || empty($ev->TimestampDateDeLivraison)) return;

        // Récupération du nom du projet via ton filtre habituel
        $project = apply_filters('ispag_get_project_by_deal_id', null, $ev->hubspot_deal_id);
        $project_name = !empty($project->ObjetCommande) ? $project->ObjetCommande : 'Projet #' . $ev->hubspot_deal_id;

        // Génération du contenu iCalendar
        $ics = $this->generate_ics($ev, $project_name);

        // Envoi vers le calendrier de Cyril (on pourrait boucler sur plusieurs users si besoin)
        $this->push_to_baikal($ev->Id, 'cyril', $ics);
    }

    /**
     * Génère le format ICS (iCalendar)
     */
    private function generate_ics($ev, $project_name) {
        // Dates au format ICS : YYYYMMDDTHHMMSS
        $dtstart = date('Ymd\THis', $ev->TimestampDateDeLivraison);
        
        // Si pas de date de fin, on ajoute 1 heure par défaut
        $end_ts = !empty($ev->TimestampDateDeLivraisonFin) ? $ev->TimestampDateDeLivraisonFin : ($ev->TimestampDateDeLivraison + 3600);
        $dtend = date('Ymd\THis', $end_ts);
        
        $created = date('Ymd\THis\Z');
        $uid = "delivery-{$ev->Id}@ispag-crm"; // Identifiant unique pour Baïkal
        $summary = "{$ev->prestation} : {$project_name}";
        $description = "Livraison liée au Deal HubSpot : " . ($ev->hubspot_deal_id ?: 'N/A');

        // Nettoyage des caractères spéciaux pour le format ICS
        $summary = str_replace([",", ";"], ["\\,", "\\;"], $summary);

        $ics = "BEGIN:VCALENDAR\r\n";
        $ics .= "VERSION:2.0\r\n";
        $ics .= "PRODID:-//ISPAG//CalendarSync//FR\r\n";
        $ics .= "BEGIN:VEVENT\r\n";
        $ics .= "UID:{$uid}\r\n";
        $ics .= "DTSTAMP:{$created}\r\n";
        $ics .= "DTSTART:{$dtstart}\r\n";
        $ics .= "DTEND:{$dtend}\r\n";
        $ics .= "SUMMARY;CHARSET=UTF-8:{$summary}\r\n";
        $ics .= "DESCRIPTION;CHARSET=UTF-8:{$description}\r\n";
        $ics .= "END:VEVENT\r\n";
        $ics .= "END:VCALENDAR";

        return $ics;
    }

    /**
     * Envoi HTTP PUT vers Baïkal
     */
    private function push_to_baikal($id, $user, $ics) {
        $url = "https://{$this->baikal_ip}/dav.php/calendars/{$user}/{$this->calendar_token}/delivery-{$id}.ics";

        $response = wp_remote_request($url, [
            'method'    => 'PUT',
            'headers'   => [
                'Authorization' => 'Basic ' . base64_encode("$user:{$this->baikal_pass}"),
                'Content-Type'  => 'text/calendar; charset=utf-8',
            ],
            'body'      => $ics,
            'timeout'   => 10
        ]);

        if (is_wp_error($response)) {
            $this->log("ERREUR HTTP pour livraison {$id} : " . $response->get_error_message());
        }
    }

    /**
     * MÉTHODE BOURRIN : Pour synchroniser tout l'historique d'un coup (appel manuel)
     */
    public function sync_all_deliveries_now() {
        set_time_limit(0);
        $this->log("--- DÉBUT SYNCHRO MANUELLE TOTALE ---");
        $this->sync_all_deliveries_cron();
        $this->log("--- FIN SYNCHRO MANUELLE TOTALE ---");
    }
}