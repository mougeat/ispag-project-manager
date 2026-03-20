<?php

/**
 * Classe de synchronisation des livraisons ISPAG vers le calendrier Baïkal (CalDAV)
 * VERSION GROUPÉE PAR PROJET (DEAL)
 */
class ISPAG_Baikal_Calendar_Sync {

    private $baikal_ip       = 'contacts.barthels.duckdns.org';
    private $calendar_token  = 'default'; 
    private $baikal_pass     = 'IsPaG2026SecureSync';

    public function __construct() {
        if (!wp_next_scheduled('ispag_cron_sync_calendar')) {
            wp_schedule_event(time(), 'hourly', 'ispag_cron_sync_calendar');
        }
        add_action('ispag_cron_sync_calendar', [$this, 'sync_all_deliveries_cron']);
    }

    public function sync_all_deliveries_cron() {
        global $wpdb;

        $start_limit = time() - (DAY_IN_SECONDS * 30);
        $end_limit   = time() + (DAY_IN_SECONDS * 90);

        // GROUP BY hubspot_deal_id pour n'avoir qu'un seul événement par projet
        $deals = $wpdb->get_results($wpdb->prepare("
            SELECT hubspot_deal_id 
            FROM {$wpdb->prefix}achats_details_commande 
            WHERE TimestampDateDeLivraison >= %d AND TimestampDateDeLivraison <= %d
            AND hubspot_deal_id > 0
            GROUP BY hubspot_deal_id
        ", $start_limit, $end_limit));

        if (empty($deals)) return;

        foreach ($deals as $d) {
            $this->sync_project_to_baikal($d->hubspot_deal_id);
        }
    }

    public function sync_project_to_baikal($deal_id) {
        global $wpdb;

        // 1. Récupérer les infos globales du projet et de livraison
        // On prend la première ligne trouvée pour les infos d'adresse (i.*)
        $project_data = $wpdb->get_row($wpdb->prepare("
            SELECT d.TimestampDateDeLivraison, d.hubspot_deal_id, i.*
            FROM {$wpdb->prefix}achats_details_commande d
            LEFT JOIN {$wpdb->prefix}achats_info_commande i ON d.hubspot_deal_id = i.hubspot_deal_id
            WHERE d.hubspot_deal_id = %d
            LIMIT 1
        ", $deal_id));

        if (!$project_data || empty($project_data->TimestampDateDeLivraison)) return;

        // 2. Récupérer TOUS les articles (prestations) de ce projet
        $items = $wpdb->get_results($wpdb->prepare("
            SELECT d.Quantite, t.prestation
            FROM {$wpdb->prefix}achats_details_commande d
            LEFT JOIN {$wpdb->prefix}achats_type_prestations t ON d.Type = t.Id
            WHERE d.hubspot_deal_id = %d
        ", $deal_id));

        $articles_list = "";
        foreach ($items as $item) {
            $articles_list .= "- " . $item->Quantite . "x " . $item->prestation . "\\n";
        }

        // 3. Infos CRM (Company / Contacts)
        $project = apply_filters('ispag_get_project_by_deal_id', null, $deal_id);
        $contact_info = "";
        $company_info = "";

        if ($project) {
            if (!empty($project->associated_company_id)) {
                $comp_repo = new ISPAG_Crm_Company_Repository();
                $company = $comp_repo->get_company_by_viag_id($project->associated_company_id);
                $comp_name = !empty($company->company_name) ? $company->company_name : "Entreprise #".$project->associated_company_id;
                $company_info = "ENTREPRISE : " . $comp_name . "\\nLien : https://app.ispag-asp.ch/company/{$project->associated_company_id}/";
            }

            if (!empty($project->associated_contact_ids)) {
                $contact_ids = explode(',', $project->associated_contact_ids);
                $contact_lines = [];
                foreach ($contact_ids as $c_id) {
                    $c_id = trim($c_id);
                    if (!$c_id) continue;
                    $user = get_userdata($c_id);
                    if ($user) {
                        $name = trim($user->first_name . ' ' . $user->last_name) ?: $user->display_name;
                        $contact_lines[] = $name . " (https://app.ispag-asp.ch/contact/{$c_id}/)";
                    }
                }
                if (!empty($contact_lines)) {
                    $contact_info = "CONTACT(S) :\\n- " . implode("\\n- ", $contact_lines);
                }
            }
        }

        $ics = $this->generate_project_ics($project_data, $project, $articles_list, $contact_info, $company_info);
        
        // Utilisation du deal_id comme identifiant unique pour Baïkal
        $this->push_to_baikal($deal_id, 'cyril', $ics);
    }

    private function generate_project_ics($ev, $project, $articles_list, $contact_info, $company_info) {
        $dtstart = date('Ymd', $ev->TimestampDateDeLivraison);
        $dtend   = date('Ymd', $ev->TimestampDateDeLivraison + DAY_IN_SECONDS);
        $created = date('Ymd\THis\Z');
        
        // L'UID est maintenant basé sur le Deal ID
        $uid = "project-{$ev->hubspot_deal_id}@ispag-crm";
        $project_name = !empty($project->ObjetCommande) ? $project->ObjetCommande : 'Projet #' . $ev->hubspot_deal_id;
        
        $summary = str_replace([",", ";"], ["\\,", "\\;"], "LIVRAISON : {$project_name}");

        $location = implode(", ", array_filter([$ev->AdresseDeLivraison, $ev->DeliveryAdresse2, ($ev->NIP || $ev->City) ? $ev->NIP . ' ' . $ev->City : null]));

        $description = implode("\\n", array_filter([
            "PROJET : " . $project_name,
            "--------------------------",
            "CONTENU DU PROJET :",
            $articles_list,
            "--------------------------",
            "LIVRAISON : " . $ev->PersonneContact . " (" . $ev->num_tel_contact . ")",
            "NOTE : " . $ev->Comment,
            "--------------------------",
            $company_info,
            $contact_info,
            "--------------------------",
            "VOIR LE PROJET : https://app.ispag-asp.ch/details-du-projet/?deal_id=" . $ev->hubspot_deal_id
        ]));

        $ics = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//ISPAG//CalendarSync//FR\r\nBEGIN:VEVENT\r\n";
        $ics .= "UID:{$uid}\r\nDTSTAMP:{$created}\r\nDTSTART;VALUE=DATE:{$dtstart}\r\nDTEND;VALUE=DATE:{$dtend}\r\n";
        $ics .= "SUMMARY;CHARSET=UTF-8:{$summary}\r\nLOCATION;CHARSET=UTF-8:{$location}\r\n";
        $ics .= "DESCRIPTION;CHARSET=UTF-8:{$description}\r\nEND:VEVENT\r\nEND:VCALENDAR";

        return $ics;
    }

    private function push_to_baikal($deal_id, $user, $ics) {
        if (!function_exists('wp_remote_request')) require_once(ABSPATH . WPINC . '/http.php');

        // L'URL utilise le deal_id pour écraser les anciennes versions
        wp_remote_request("https://{$this->baikal_ip}/dav.php/calendars/{$user}/{$this->calendar_token}/deal-{$deal_id}.ics", [
            'method'    => 'PUT',
            'headers'   => [
                'Authorization' => 'Basic ' . base64_encode("$user:{$this->baikal_pass}"),
                'Content-Type'  => 'text/calendar; charset=utf-8',
            ],
            'body'      => $ics,
            'timeout'   => 15,
            'sslverify' => true 
        ]);
    }

    public function sync_all_deliveries_now() {
        set_time_limit(0);
        $this->sync_all_deliveries_cron();
    }
}