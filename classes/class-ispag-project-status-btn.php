<?php


class ISPAG_Project_status_btn {
    private $wpdb;
    private $table_historique;
    private $details_commande;
    private $table_liste_commande;
    private $table_articles_fournisseur;
    private $table_suivi;
    protected static $instance = null;
    protected static $id_user_invoice = 6052;

    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->table_historique = $wpdb->prefix . 'achats_historique';
        $this->details_commande = $wpdb->prefix . 'achats_details_commande';
        $this->table_liste_commande = $wpdb->prefix . 'achats_liste_commande';
        $this->table_articles_fournisseur = $this->wpdb->prefix . 'achats_articles_cmd_fournisseurs';
        $this->table_suivi = $this->wpdb->prefix . 'achats_suivi_phase_commande';
    }

    public static function init() {
        if (self::$instance === null) {
            self::$instance = new self();
        }


        add_action('wp_ajax_ispag_prepare_mail_project', [self::$instance, 'prepare_mail_from_action']);

    }
    public static function prepare_mail_from_action() {
        // if (!check_ajax_referer('ispag_nonce', '_ajax_nonce', false)) {
        //     wp_send_json_error('Nonce invalide');
        // }
        
        $deal_id = intval($_POST['deal_id'] ?? 0);
        $action_type = sanitize_text_field($_POST['type'] ?? '');

        if (!$deal_id || !$deal_id) {
            wp_send_json_error(['message' => 'Paramètres manquants.']);
        }

        

        // self::prepare_mail($deal_id, $action_type);

        try {
            self::prepare_mail($deal_id, $action_type);
        } catch (Throwable $e) {
            // error_log('Erreur fatale prepare_mail: ' . $e->getMessage());
            wp_send_json_error(['message' => 'Erreur fatale : ' . $e->getMessage()]);
        }

    }

    public static function prepare_mail($deal_id = null, $message_type = null ) {

        global $wpdb;
        $contact_id = self::$id_user_invoice;

        $subject = 'sujet';
        $message = 'message';
        $email_contact = 'c.barthel@ispag-asp.ch';

        // $achat_id = intval($_POST['achat_id']);
        if (!$deal_id) {
            wp_send_json_error(['message' => 'ID de projet manquant.']);
        }

        if (strpos($message_type, 'situation') !== false OR strpos($message_type, 'facturation') !== false) {
            // $message_type contient 'invoice'
            $contact_id = self::$id_user_invoice;
        }
        
        // 3. Récupérer contact user
        $user = get_user_by('ID', $contact_id);
        if (!$user) wp_send_json_error(['message' => 'Contact utilisateur introuvable.']);
        $email_contact = $user->user_email;
        $lang = get_user_meta($contact_id, 'locale', true) ?: get_user_meta($contact_id, 'pll_language', true);
        if (!$lang) {
            $lang = get_locale();
        }

        // 4. Récupérer le template
        $template = $wpdb->get_row($wpdb->prepare("
            SELECT subject, message FROM {$wpdb->prefix}achats_template_mail 
            WHERE lang = %s AND message_family = 'project' AND message_type = %s
            LIMIT 1
        ", $lang, $message_type));

        if (!$template) wp_send_json_error(['message' => 'Template non trouvé pour la langue : ' . $lang]);

        // 5. Remplacer les tags
        $subject = self::replace_text($template->subject, $deal_id, $contact_id);
        $message = self::replace_text($template->message, $deal_id, $contact_id);

        $subject = html_entity_decode($subject, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $message = html_entity_decode($message, ENT_QUOTES | ENT_HTML5, 'UTF-8');


        // $instance = new self();
        // // $current_status = $instance->get_current_status($achat_id);
        // // $next_status = $instance->get_next_status($current_status->Id);


        // 6. Réponse avec mailto
        wp_send_json_success([
            'deal_id' => $deal_id,
            'subject' => $subject,
            'message_type' => $message_type,
            'template' => $template,
            'lang' => $lang,
            'message' => $message,
            'email_contact' => $email_contact,
            'email_copy' => ' ' // à adapter
        ]);
    }

    
    public static function replace_text($text, $deal_id, $contact_id) {
        // 1. Récupérer contact
        $user = get_user_by('ID', $contact_id);
        if (!$user) wp_send_json_error(['message' => 'Contact utilisateur introuvable.']);
        
        // 1bis. Forcer la langue si disponible (Polylang)
        $lang = get_user_meta($contact_id, 'locale', true) ?: get_user_meta($contact_id, 'pll_language', true);
        if ($lang) {
            if (function_exists('pll_set_language')) pll_set_language($lang);
            switch_to_locale($lang); // utile si tu veux charger gettext dans la bonne langue
        }


        // 2. Récupérer données de l'achat
        $repo = new ISPAG_Projet_Repository();
        $project = $repo->get_project_by_deal_id('', $deal_id);

        if (!$project) {
            return "Erreur : Projet introuvable pour le deal ID $deal_id";
        }
        

        // 3. Récupérer articles
        $articles = (new ISPAG_Article_Repository())->get_articles_by_deal($deal_id);

        $product_list = "\n";
        $last_group = null;

        foreach ($articles as $index => $article) {
            // error_log('replace_text : ' . print_r($article));
            $group = trim($article->Groupe ?? '');

            // Si nouveau groupe, on l'affiche
            if ($group !== $last_group) {
                if ($last_group !== null) $product_list .= "-------\n"; // sépare les groupes
                $product_list .= "🟢 $group\n";
                $last_group = $group;
            }

            // Ajouter description
            $product_list .= trim($article->Article) . "\n";
        }

        // Supprimer le dernier '-------' s'il n'y a pas de groupe après
        // $product_list = rtrim($product_list, "-\n");
        $product_list = preg_replace("/-------\s*$/", "", $product_list);
        $product_list = stripslashes($product_list);

        // 4. Récupérer infos livraison
        $info_livraison = (new ISPAG_Project_Details_Repository())->get_infos_livraison($deal_id);

        $formatter = new IntlDateFormatter(
            'fr_FR',
            IntlDateFormatter::LONG,
            IntlDateFormatter::NONE,
            null,
            null,
            'MMMM yyyy'
        );
        

        // 5. Remplacer les balises
        $replacements = [
            'PRENOM'   => $user->first_name,
            'NOM'   => $user->last_name,
            'PROJECT_NAME'   => $project->ObjetCommande,
            'PROJECT_NUMBER' => $project->NumCommande,
            'PURCHASE_LINK'  => '<a href="' . $project->project_url . '">ici</a>',
            'PRODUCT_LIST'   => $product_list,
            'DELIVERY_ADRESS' => $info_livraison->AdresseDeLivraison,
            'DELIVERY_ADRESS2' => $info_livraison->DeliveryAdresse2,
            'DELIVERY_NIP' => $info_livraison->NIP,
            'DELIVERY_CITY' => $info_livraison->City,
            'DELIVERY_CONTACT' => $info_livraison->PersonneContact,
            'DELIVERY_CONTACT_PHONE' => $info_livraison->num_tel_contact,
            'DELIVERY_DATE' => '',
            'INVOICE_DATE' => $formatter->format(new DateTime()),
        ];

        $text = strtr($text, $replacements);

        // 6. Nettoyer le texte
        $text = str_ireplace(['<br />', '<br/>'], "\n", $text);
        $text = preg_replace("/<hr\W*?\/?>/", str_repeat('- ', 30), $text);
        $text = strip_tags($text);

        return $text;
    }
}