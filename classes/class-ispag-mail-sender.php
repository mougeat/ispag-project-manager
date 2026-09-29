<?php

class ISPAG_Mail_Sender {

    private static $api_key;
    private static $log_file = WP_CONTENT_DIR . '/ispag_brevo_mail.log';
    private static $sent_emails = [];

    public static function init(){
        self::$api_key = getenv('BREVO_API_KEY');
        add_action('ispag_send_mail_from_slug', [self::class, 'send_mail_from_slug'], 10, 3);
    }

    private static function log($message) {
        $timestamp = current_time('mysql');
        $user_id = get_current_user_id(); // Récupère l'ID de l'utilisateur actuel
        $log_entry = "[$timestamp] [USER:$user_id] $message" . PHP_EOL;
        file_put_contents(self::$log_file, $log_entry, FILE_APPEND);
    }

    /**
     * Récupère l'ID du template Brevo lié à un slug de phase
     */
    public static function getBrevoTemplateId(?string $slug = null) {
        if (empty($slug)) return null;

        global $wpdb;
        $table = $wpdb->prefix . 'achats_slug_phase';
        
        // Utilisation de get_var pour récupérer directement la valeur unique
        $template_id = $wpdb->get_var($wpdb->prepare(
            "SELECT Brevo_id FROM $table WHERE SlugPhase = %s", 
            $slug
        ));

        if (!$template_id) {
            self::log("WARNING: Aucun Brevo_id trouvé en base pour le slug '$slug'");
        }

        return $template_id;
    }

    /**
     * Récupère le délai (en jours) configuré pour un slug
     */
    public static function getBrevoDelayDays(?string $slug = null) {
        if (empty($slug)) return 0;

        global $wpdb;
        $table = $wpdb->prefix . 'achats_slug_phase';
        
        $delay = $wpdb->get_var($wpdb->prepare(
            "SELECT Brevo_delay_days FROM $table WHERE SlugPhase = %s", 
            $slug
        ));

        return $delay ? (int)$delay : 0;
    }

    public static function send_mail_from_slug($html, $deal_id, $slug) {
        self::log("--- Nouvelle demande : Slug '$slug' pour Deal ID $deal_id ---");
        
        $brevo_template_id = self::getBrevoTemplateId($slug);
        $brevo_delay = self::getBrevoDelayDays($slug);

        if (!$brevo_template_id) {
            self::log("ERREUR : Envoi annulé, Template ID manquant pour '$slug'");
            return;
        }

        self::brevo_send_email_with_pdf($deal_id, $brevo_template_id, $brevo_delay);
    }

    public static function brevo_send_email_with_pdf(?int $deal_id = null, $template_id = null, ?int $delay = 0, ?array $additional_recipients = null) {
        $url = 'https://api.brevo.com/v3/smtp/email';

        if (empty(self::$api_key)) {
            self::log("ERREUR : Clé API Brevo (BREVO_API_KEY) non définie.");
            return;
        }

        // 1. Récupération des données du projet
        $project = apply_filters('ispag_get_project_by_deal_id', null, $deal_id);
        if (!$project) {
            self::log("ERREUR : Impossible de trouver le projet pour Deal ID $deal_id");
            return;
        }
        self::log("Données de deal récupérées : " . print_r($project, true));

        // 2. Récupération du contact associé
        $user_id = isset($project->AssociatedContactIDs) ? $project->AssociatedContactIDs : null;
        $user = $user_id ? get_userdata($user_id) : null;

        if (!$user) {
            self::log("ERREUR : Aucun contact valide trouvé (ID: $user_id) pour le Deal $deal_id");
            return;
        }

        $email = trim($user->user_email);
        $firstname = get_user_meta($user_id, 'first_name', true);
        $lastname = get_user_meta($user_id, 'last_name', true);
        $name = trim($firstname . ' ' . $lastname);

        // 3. Récupération articles et infos livraison
        $article_repo = new ISPAG_Article_Repository();
        // $articles = $article_repo->get_articles_by_deal($deal_id);
        if (current_user_can('navigate_new_project_details_presentation')) {
            $articles = $article_repo->get_optimised_articles_by_deal($deal_id);
        }
        else{
            $articles = $article_repo->get_articles_by_deal($deal_id);
        }
        $details_repo = new ISPAG_Project_Details_Repository();
        $infos = $details_repo->get_infos_livraison($deal_id);

        $attachments = array();
        $items = array();

        if (!empty($articles)) {
            foreach ($articles as $groupe => $articles_principaux) {
                foreach ($articles_principaux as $article) {
                    $items[] = $article;
                    // Si un plan existe et n'est pas encore approuvé, on l'ajoute en PJ
                    if (!empty($article->last_drawing_url) && ($article->DrawingApproved ?? false) != true) {
                        $attachments[] = array(
                            "url" => self::encode_url_path($article->last_drawing_url),
                            "name" => self::clean_filename($article->Article ?? 'document') . '.pdf'
                        );
                    }
                }
            }
        }

        // 4. Préparation du payload Brevo
        $raw_params = array_merge(
            (array)$project,
            (array)$infos,
            ['items' => $items]
        );
        // Nettoyage des NULL pour éviter les plantages JSON
        $clean_params = array_map(function($v) { return is_null($v) ? '' : $v; }, $raw_params);

        $current_user = wp_get_current_user();

        // 1. On récupère l'email de l'utilisateur ou la valeur par défaut
        $original_mail = !empty($current_user->user_email) ? $current_user->user_email : 'c.barthel@ispag-asp.com';

        // 2. On définit les correspondances (Mapping)
        $mail_mapping = [
            'vente@ispag-asp.com'     => 'c.tonelli@ispag-asp.ch',
            'c.barthel@ispag-asp.com' => 'c.barthel@ispag-asp.ch'
        ];

        // 3. On remplace si l'email est dans la liste, sinon on garde l'original
        $sender_mail = isset($mail_mapping[$original_mail]) ? $mail_mapping[$original_mail] : $original_mail;
        $sender_name = !empty($current_user->display_name) ? $current_user->display_name : 'ISPAG';

        // 5. Préparation des destinataires (To + CC)
        $to = [["email" => $email, "name" => $name]];
        $cc_emails = [
            ['email' => 'c.barthel@ispag-asp.ch', 'name' => 'Cyril Barthel'],
            ['email' => 'log@mg.ispag-asp.com', 'name' => 'log CRM'],
            ['email' => $sender_mail, 'name' => $sender_name]
        ];

        // Ajouter les abonnés (Abonne)
        if (!empty($project->Abonne)) {
            $array_abonne = explode(';', $project->Abonne);
            foreach ($array_abonne as $u_id) {
                $u_id = trim($u_id);
                if (!empty($u_id)) {
                    $u_data = get_userdata($u_id);
                    if ($u_data && is_email($u_data->user_email)) {
                        $cc_emails[] = [
                            'email' => $u_data->user_email,
                            'name'  => $u_data->display_name ?: $u_data->user_login
                        ];
                    }
                }
            }
        }

        // Ajouter les destinataires supplémentaires (AssociatedContactIDs + created_by)
        if (!empty($additional_recipients) && is_array($additional_recipients)) {
            foreach ($additional_recipients as $recipient) {
                if (!empty($recipient['email']) && !empty($recipient['name'])) {
                    $cc_emails[] = [
                        'email' => $recipient['email'],
                        'name'  => $recipient['name']
                    ];
                }
            }
        }

        // Suppression des doublons (basé sur l'email)
        $unique_cc = [];
        $seen_emails = [];
        foreach ($cc_emails as $cc) {
            if (!isset($seen_emails[$cc['email']])) {
                $seen_emails[$cc['email']] = true;
                $unique_cc[] = $cc;
            }
        }

        $data = [
            "sender"     => ["name" => $sender_name, "email" => $sender_mail],
            "to"         => $to,
            "templateId" => (int) $template_id,
            "params"     => $clean_params
        ];

        // Gestion du délai d'envoi (ScheduledAt)
        if ($delay > 0) {
            $data['scheduledAt'] = date("Y-m-d\TH:i:sP", strtotime("+" . (int)$delay . " day"));
        }

        if (!empty($attachments)) {
            $data['attachment'] = $attachments;
        }

        $data["cc"] = array_values($unique_cc);

        // 6. Exécution CURL
        self::log("Tentative d'envoi : Template #$template_id à $email, copie à " . print_r($data["cc"], true));
        self::log("PAYLOAD JSON : " . json_encode($data));

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "api-key: " . self::$api_key,
            "Content-Type: application/json",
            "Accept: application/json"
        ]);

        $response = curl_exec($ch);
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if (curl_errno($ch)) {
            $error_msg = "ERREUR CURL : " . curl_error($ch);
            self::log($error_msg);
            self::show_alert("❌ Erreur lors de l'envoi de l'email : $error_msg", true);
        } else {
            self::log("RÉPONSE BREVO ($httpcode) : " . $response);
            $response_data = json_decode($response, true);

            if ($httpcode !== 201 && $httpcode !== 200) {
                // Erreur Brevo
                $error_msg = isset($response_data['message']) ? $response_data['message'] : "Erreur inconnue (code: $httpcode)";
                self::log("ERREUR BREVO : $error_msg");
                self::show_alert("❌ L'envoi de l'email a échoué : $error_msg", true);
            } else {
                // Succès
                self::log("SUCCESS : Email envoyé avec succès !");
                self::show_alert("✅ Email envoyé avec succès !", false);
            }
        }

        curl_close($ch);
    }

    public static function encode_url_path($url) {
        $parts = parse_url($url);
        if (!isset($parts['path'])) return $url;
        $path = $parts['path'];
        $encoded_path = implode('/', array_map('rawurlencode', explode('/', $path)));
        $new_url = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '') . $encoded_path;
        if (isset($parts['query'])) $new_url .= '?' . $parts['query'];
        return $new_url;
    }

    public static function clean_filename($filename) {
        $filename = str_replace(' ', '_', $filename);
        $filename = preg_replace('~&([a-z]{1,2})(?:acute|cedil|circ|grave|lig|orn|ring|slash|th|tilde|uml);~i', '$1', htmlentities($filename, ENT_QUOTES, 'UTF-8'));
        $filename = preg_replace('/[^A-Za-z0-9_\-]/', '', $filename);
        return $filename;
    }

    /**
     * Affiche une infobox d'erreur ou de succès via ispagConfirm (ou une alternative si non disponible).
     *
     * @param string $message Le message à afficher.
     * @param bool $is_error Si vrai, affiche une erreur (sinon un succès).
     */
    private static function show_alert($message, $is_error = true) {
        // Vérifie si ispagConfirm est disponible (côté frontend)
        $script = "
            if (typeof ispagConfirm === 'function') {
                ispagConfirm('$message', {
                    labelOk: '" . ($is_error ? 'OK' : 'Fermer') . "',
                    danger: " . ($is_error ? 'true' : 'false') . "
                }).then(() => {});
            } else {
                // Fallback : utilise alert() si ispagConfirm n'est pas chargé
                alert('$message');
            }
        ";
        wp_add_inline_script('ispag-main', $script); // Remplacez 'ispag-main' par le handle de votre script JS principal
    }
}