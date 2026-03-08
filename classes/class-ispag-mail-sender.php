<?php

class ISPAG_Mail_Sender {

    private static $api_key;
    private static $log_file = WP_CONTENT_DIR . '/ispag_brevo_mail.log';

    public static function init(){
        self::$api_key = getenv('BREVO_API_KEY');
        add_action('ispag_send_mail_from_slug', [self::class, 'send_mail_from_slug'], 10, 3);
    }

    public static function send_mail_from_slug($html, $deal_id, $slug) {
        $brevo_template_id = self::getBrevoTemplateId($slug);
        $brevo_delay = self::getBrevoDelayDays($slug);

        self::brevo_send_email_with_pdf($deal_id, $brevo_template_id, $brevo_delay);
    }

    public static function getBrevoTemplateId(?string $slug = null) {
        global $wpdb;
        $bdd = $wpdb->prefix . 'achats_slug_phase';
        $sql = $wpdb->prepare("SELECT Brevo_id FROM $bdd WHERE SlugPhase = %s", $slug);
        $requete = $wpdb->get_results($sql);
        return !empty($requete) ? ($requete[0]->Brevo_id) : null;
    }

    public static function getBrevoDelayDays(?string $slug = null) {
        global $wpdb;
        $bdd = $wpdb->prefix . 'achats_slug_phase';
        $sql = $wpdb->prepare("SELECT Brevo_delay_days FROM $bdd WHERE SlugPhase = %s", $slug);
        $requete = $wpdb->get_results($sql);
        return !empty($requete) ? ($requete[0]->Brevo_delay_days) : null;
    }

    public static function brevo_send_email_with_pdf(?int $deal_id = null, $template_id = null, ?int $delay = 0) {
        
        $url = 'https://api.brevo.com/v3/smtp/email';

        if(!empty($template_id)){

            $project = apply_filters('ispag_get_project_by_deal_id', null, $deal_id );
            $article_repo = new ISPAG_Article_Repository();
            $articles = $article_repo->get_articles_by_deal($deal_id);
            $details_repo = new ISPAG_Project_Details_Repository(); 
            $infos = $details_repo->get_infos_livraison($deal_id);

            $user_id = $project->AssociatedContactIDs;
            $user = get_userdata($user_id);

            // Sécurité : si l'utilisateur n'existe pas, on ne peut pas envoyer de mail
            if (!$user) {
                return;
            }

            $email = $user->user_email;
            $firstname = get_user_meta($user_id, 'first_name', true);
            $lastname = get_user_meta($user_id, 'last_name', true);
            $name = $firstname .' ' . $lastname;

            // Préparer les fichiers joints
            $attachments = array();
            $items = array();

            // Gestion du délai
            $scheduled_time = ($delay > 0) ? date("Y-m-d\TH:i:sP", strtotime("+" . $delay ." day")) : null;
            
            if(isset($articles)){
                foreach ($articles as $groupe => $articles_principaux) {
                    foreach ($articles_principaux as $article) {
                        $items[] = $article; 
                        if(!empty($article->last_drawing_url) AND $article->DrawingApproved != true){
                            $attachments[] = array(
                                "url" => self::encode_url_path($article->last_drawing_url),
                                "name" => self::clean_filename($article->Article).'.pdf'
                            );
                        }
                    }
                }
            }

            $current_user = wp_get_current_user();
            
            // Préparation des paramètres pour le template
            $params = array_merge(
                get_object_vars($project),
                get_object_vars($infos)
            );
            $params['items'] = $items;

            $sender_mail = !empty($current_user->user_email) ? $current_user->user_email : 'c.barthel@ispag-asp.com';
            $sender_name = !empty($current_user->display_name) ? $current_user->display_name : 'Cyril - ISPAG';

            $data = [
                "sender" => [
                    "name" => mb_convert_encoding($sender_name, 'UTF-8', 'auto'),
                    "email" => $sender_mail
                ],
                "to" => [
                    ["email" => $email, "name" => mb_convert_encoding($name, 'UTF-8', 'auto')]
                ],
                "templateId" => (int) $template_id,
                "params" => $params
            ];

            if(!empty($scheduled_time)) $data['scheduledAt'] = $scheduled_time;
            if(!empty($attachments))    $data['attachment'] = $attachments;

            // Gestion des CC (Abonnés)
            $cc_emails = array();
            if(!empty($project->Abonne)){
                $array_abonne = explode(';', $project->Abonne);
                foreach ($array_abonne as $u_id) {
                    if (!empty($u_id)) {
                        $u_data = get_userdata($u_id);
                        if ($u_data && !empty($u_data->user_email)) {
                            $cc_emails[] = $u_data->user_email;
                        }
                    }
                }
            }
            $cc_emails[] = 'c.barthel@ispag-asp.ch';
            $data["cc"] = array_map(fn($e) => ['email' => $e], array_unique($cc_emails));

            $headers = [
                "accept: application/json",
                "api-key: " . self::$api_key,
                "Content-Type: application/json"
            ];

            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

            $response = curl_exec($ch);
            $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
        }
    }

    public static function encode_url_path($url) {
        $parts = parse_url($url);
        if (!isset($parts['path'])) return $url;
        $segments = explode('/', $parts['path']);
        $encoded_path = implode('/', array_map('rawurlencode', $segments));
        $encoded_url = $parts['scheme'] . '://' . $parts['host'] . $encoded_path;
        if (isset($parts['query'])) $encoded_url .= '?' . $parts['query'];
        return $encoded_url;
    }

    public static function clean_filename($filename) {
        $filename = str_replace(' ', '_', $filename);
        $filename = iconv('UTF-8', 'ASCII//TRANSLIT', $filename);
        $filename = preg_replace('/[^A-Za-z0-9_\-]/', '', $filename);
        return $filename;
    }
}