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



    public static function getBrevoTemplateId(?string $slug = null)
    {
//         error_log("--- DEBUT EXECUTION getBrevoTemplateId: " . date('Y-m-d H:i:s') . " ---\n", 3, self::$log_file);
        global $wpdb;
        $bdd = $wpdb->prefix . 'achats_slug_phase';
        $sql = "SELECT Brevo_id FROM $bdd WHERE SlugPhase = '$slug';";
        $requete = $wpdb->get_results($sql);
        
//         error_log('Brevo template Id : ' . $requete[0]->Brevo_id . " ---\n", 3, self::$log_file);
//         error_log("--- FIN EXECUTION getBrevoTemplateId: " . date('Y-m-d H:i:s') . " ---\n", 3, self::$log_file);
        return !empty($requete) ? ($requete[0]->Brevo_id) : null;

    }
    public static function getBrevoDelayDays(?string $slug = null)
    {
        global $wpdb;
        $bdd = $wpdb->prefix . 'achats_slug_phase';
        $sql = "SELECT Brevo_delay_days FROM $bdd WHERE SlugPhase = '$slug';";
        $requete = $wpdb->get_results($sql);
        
        // error_log('Brevo template delays : ' . $requete[0]->Brevo_delay_days);
        return !empty($requete) ? ($requete[0]->Brevo_delay_days) : null;

    }

    public static function brevo_send_email_with_pdf(?int $deal_id = null, $template_id = null, ?int $delay = 0) {
    
//         error_log("--- DEBUT EXECUTION brevo_send_email_with_pdf: " . date('Y-m-d H:i:s') . " ---\n", 3, self::$log_file);
//         error_log("deal ID: " . $deal_id . " ---\n", 3, self::$log_file);
//         error_log("template ID: " . $template_id . " ---\n", 3, self::$log_file);
//         error_log("delay: " . $delay . " ---\n", 3, self::$log_file);
        
        $url = 'https://api.brevo.com/v3/smtp/email';

        if(!empty($template_id)){

            // $project_repo = new ISPAG_Projet_Repository();
            // $project = $project_repo->get_projects_or_offers(null, null, false,$deal_id); 
            $project = apply_filters('ispag_get_project_by_deal_id', null, $deal_id );
//             error_log("project datas : " . print_r($project, true) . " ---\n", 3, self::$log_file);
            $article_repo = new ISPAG_Article_Repository();
            $articles = $article_repo->get_articles_by_deal($deal_id);
            $details_repo = new ISPAG_Project_Details_Repository(); 
            $infos = $details_repo->get_infos_livraison($deal_id);


            // $email = $project_data['mail'];
            $user_id = $project->AssociatedContactIDs; // l'ID utilisateur
            $user = get_userdata($user_id);

//             error_log("user datas : " . print_r($user, true) . " ---\n", 3, self::$log_file);

            $contact_ids = $user_id;

            if ($user) {
                $email = $user->user_email;
                $firstname = get_user_meta($user_id, 'first_name', true);
                $lastname = get_user_meta($user_id, 'last_name', true);

                $name = $firstname .' ' . $lastname;

                
            }

//             error_log("before update brevo contact ---\n", 3, self::$log_file);
            self::update_brevo_contact($user_id);  
//             error_log("after update brevo contact ---\n", 3, self::$log_file);
            

            // Préparer les fichiers joints
            $attachments = array();
            $items = array();

            // Définir la date d'envoi différé (1 jour plus tard)
            if($delay > 0){
                $scheduled_time = date("Y-m-d\TH:i:sP", strtotime("+" . $delay ." day"));
            }
            else{
                $scheduled_time = null;
            }
            
//             error_log("before if articles ---\n", 3, self::$log_file);
            if(isset($articles)){
//                 error_log("articles datas : " . print_r($articles, true) . " ---\n", 3, self::$log_file);

                $items = [];
                foreach ($articles as $groupe => $articles_principaux) {
                    foreach ($articles_principaux as $article) {
                        $items[] = $article; // on stocke dans un tableau simple
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

            // if (is_array($project) && isset($project[0])) {
            //     $project = $project[0]; // on récupère juste l’objet
            // }

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
                    "email" => $sender_mail // Doit être validé dans Brevo
                ],
                "to" => [
                    ["email" => $email, "name" => mb_convert_encoding($name, 'UTF-8', 'auto')]
                ],
                "templateId" => (int) $template_id,
                

                "params" => $params

                
            ];
            if(!empty($scheduled_time)){
                $data['scheduledAt'] = $scheduled_time; // 🔥 Envoi différé
            }

            if(count($attachments) !== 0){
                $data['attachment'] = $attachments;
            }
            $abonne = $project->Abonne;
            $company_id = $project->AssociatedCompanyID;
            $array_abonne = explode(';', $abonne);

            // Ajouter CC et BCC si présents
            foreach ($array_abonne as $value) {
                if (!empty($value)) {
                    $user_id = $value;
                    $contact_ids = $contact_ids.','.$user_id;
                    self::update_brevo_contact($user_id);  
                    $user = get_userdata($user_id);
                    if ($user && !empty($user->user_email)) {
                        $cc_emails[] = $user->user_email;
                    }
                }

            }
            $cc_emails[] = 'c.barthel@ispag-asp.ch';
            if (!empty($cc_emails)) {
                $data["cc"] = array_map(fn($email) => ['email' => $email], $cc_emails);;
            }
            $api_key = self::$api_key;
            $headers = [
                "accept: application/json",
                "api-key: $api_key",
                "Content-Type: application/json"
            ];

//             error_log("Mail datas : " . print_r($data, true) . " ---\n", 3, self::$log_file);
//             error_log("Mail headers : " . print_r($headers, true) . " ---\n", 3, self::$log_file);
            

            $json_data = json_encode($data);

            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $json_data);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

//             error_log("[BREVO] JSON envoyé : " . $json_data . " ---\n", 3, self::$log_file); // Ajoutez cette ligne pour inspection
//             error_log("template ID: " . $template_id . " ---\n", 3, self::$log_file); // Ligne 8

//             error_log("[BREVO] Données envoyées : " . print_r($data, true) . " ---\n", 3, self::$log_file);

            $response = curl_exec($ch);
            $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpcode != 201) {
//                 error_log("❌ Erreur API Brevo ($httpcode) : " . $response, 3, self::$log_file);
            }
        }
//         error_log("--- FIN EXECUTION brevo_send_email_with_pdf: " . date('Y-m-d H:i:s') . " ---\n", 3, self::$log_file);
    }

    public static function encode_url_path($url) {
        $parts = parse_url($url);
        if (!isset($parts['path'])) return $url;
        
        $segments = explode('/', $parts['path']);
        $encoded_segments = array_map('rawurlencode', $segments);
        $encoded_path = implode('/', $encoded_segments);
        
        // reconstruire l'url
        $encoded_url = $parts['scheme'] . '://' . $parts['host'] . $encoded_path;
        if (isset($parts['query'])) {
            $encoded_url .= '?' . $parts['query'];
        }
        return $encoded_url;
    }

    public static function clean_filename($filename) {
        // Remplace les espaces par des underscores
        $filename = str_replace(' ', '_', $filename);
        // Supprime les accents (ex : é -> e)
        $filename = iconv('UTF-8', 'ASCII//TRANSLIT', $filename);
        // Supprime tout ce qui n’est pas lettre, chiffre, underscore ou tiret
        $filename = preg_replace('/[^A-Za-z0-9_\-]/', '', $filename);
        return $filename;
    }

    //*****
    // Mise a jour des contacts dans BREVO
    
    //***** */
    public static function update_brevo_contact(?int $user_id) {
        global $wpdb;

        $table_fournisseurs = $wpdb->prefix . 'achats_fournisseurs'; 
        $company_id_meta_key = 'ispag_company_id'; // Clé de meta-donnée définie précédemment
    
        if (empty($user_id)) {
            // Loggez une erreur ou retournez si l'email est manquant
            return false;
        }

        $user = get_userdata($user_id);

        if ($user) {
            $email = $user->user_email;
            $firstname = get_user_meta($user_id, 'first_name', true);
            $lastname = get_user_meta($user_id, 'last_name', true);

            // --- LOGIQUE DE RÉCUPÉRATION DE L'ENTREPRISE ---
        
            $linked_company_id = get_user_meta( $user_id, $company_id_meta_key, true );
            $company_name = 'Non lié'; // Valeur par défaut

            if ( absint( $linked_company_id ) > 0 ) {
                // Récupérer le nom du fournisseur via l'ID lié
                $company_name_from_db = $wpdb->get_var( $wpdb->prepare( 
                    "SELECT Fournisseur FROM {$table_fournisseurs} WHERE Id = %d", 
                    absint( $linked_company_id ) 
                ) );

                if ( $company_name_from_db ) {
                    $company_name = $company_name_from_db;
                } 
                // Si l'ID est là mais l'entreprise n'est pas trouvée, $company_name reste 'Non lié'
            }
            
            // --- FIN LOGIQUE DE RÉCUPÉRATION DE L'ENTREPRISE ---

            $attributes = array(
                'ROLE' => $user->roles,
                'ENTREPRISE' => $company_name,
            );
            
        }

        $url = 'https://api.brevo.com/v3/contacts';
        $api_key = self::$api_key;

        // Préparer les données
        $data = [
            "email" => $email,
            "emailBlacklisted" => false,
            "smsBlacklisted" => false,
            "listIds" => [1], // Remplacez 1 par l'ID de votre liste principale Brevo si nécessaire
            "updateEnabled" => true, // Très important pour mettre à jour le contact s'il existe
            "attributes" => array_merge([
                "PRENOM" => $firstname,
                "NOM" => $lastname,
            ], $attributes)
        ];

        $headers = [
            "accept: application/json",
            "api-key: $api_key",
            "Content-Type: application/json"
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST"); // POST pour cet endpoint pour update/create
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $response = curl_exec($ch);
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // Vérifier les codes de réponse (201 pour créé, 204 pour mis à jour)
        if ($httpcode !== 201 && $httpcode !== 204) {
//             error_log("[BREVO CONTACT ERROR] Erreur lors de la mise à jour du contact ($httpcode) : " . $response);
            return false;
        }
        return true;
    }
}
