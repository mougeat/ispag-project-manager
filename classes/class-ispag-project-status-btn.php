<?php
defined('ABSPATH') || exit;


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
        
        if (!current_user_can('manage_order')) {
            wp_send_json_error(['message' => 'Not authorized.']);
        }

        $deal_id = intval($_POST['deal_id'] ?? 0);
        $action_type = sanitize_text_field($_POST['type'] ?? '');

        if (!$deal_id) {
            wp_send_json_error(['message' => 'Missing parameters.']);
        }

        

        // self::prepare_mail($deal_id, $action_type);

        $article_ids = array_values(array_filter(array_map('intval', explode(',', (string) ($_POST['article_ids'] ?? '')))));

        try {
            self::prepare_mail($deal_id, $action_type, $article_ids);
        } catch (Throwable $e) {
            // error_log('Error fatale prepare_mail: ' . $e->getMessage());
            wp_send_json_error(['message' => 'Fatal error: ' . $e->getMessage()]);
        }

    }

    public static function prepare_mail($deal_id = null, $message_type = null, array $article_ids = []) {
        $mail = self::build_mail($deal_id, $message_type, $article_ids);
        if (is_wp_error($mail)) {
            wp_send_json_error(['message' => $mail->get_error_message()]);
        }
        // Articles facturés : situation = articles cochés ; facture finale = tous les articles encore non facturés
        $mail['invoiced_count'] = self::mark_articles_invoiced($deal_id, $message_type, $article_ids);

        // Commande client à joindre : brouillon .eml avec pièce jointe (un lien mailto: ne peut pas joindre de fichier)
        if (ISPAG_Project_Mail_Draft::attachments_count($deal_id) > 0) {
            $mail['eml_url'] = ISPAG_Project_Mail_Draft::download_url($deal_id, $message_type, $article_ids);
            $mail['attachments_count'] = ISPAG_Project_Mail_Draft::attachments_count($deal_id);
        }
        wp_send_json_success($mail);
    }

    /**
     * Marque des articles comme facturés (date du jour), sans toucher à ceux déjà facturés.
     *  - 'situation'   : les articles cochés ;
     *  - 'facturation' : tous les articles non archivés encore non facturés du projet.
     * Appelé une seule fois par demande (pas dans build_mail, rejoué au téléchargement du .eml).
     *
     * @return int nombre d'articles marqués
     */
    private static function mark_articles_invoiced($deal_id, $message_type, array $article_ids) {
        global $wpdb;
        $table = $wpdb->prefix . 'achats_details_commande';
        $deal_id = (int) $deal_id;
        $not_invoiced = '(invoiced IS NULL OR invoiced = 0)';

        if ($message_type === 'situation') {
            if (!$article_ids) return 0; // rien de coché : rien à marquer
            $in = implode(',', array_map('intval', $article_ids));
            $updated = $wpdb->query($wpdb->prepare(
                "UPDATE {$table} SET invoiced = %d WHERE hubspot_deal_id = %d AND Id IN ({$in}) AND {$not_invoiced}",
                time(), $deal_id
            ));
        } elseif ($message_type === 'facturation') {
            $updated = $wpdb->query($wpdb->prepare(
                "UPDATE {$table} SET invoiced = %d WHERE hubspot_deal_id = %d AND (archive IS NULL OR archive = 0) AND {$not_invoiced}",
                time(), $deal_id
            ));
        } else {
            return 0;
        }

        $updated = (int) $updated;
        if ($updated > 0) {
            do_action('isag_run_auto_update', $deal_id); // même mise à jour de phases que la modification groupée
        }
        return $updated;
    }

    /**
     * Prépare le mail (destinataire, objet, texte avec balises remplacées).
     *
     * @return array|WP_Error
     */
    public static function build_mail($deal_id = null, $message_type = null, array $article_ids = []) {
        if (!$deal_id) {
            return new WP_Error('mail', 'ID de projet manquant.');
        }

        // Contact qui rédige les factures (réglage ISPAG Settings → Invoicing) : destinataire du mail
        $user = ISPAG_Invoice_Settings::contact();
        if (!$user) return new WP_Error('mail', 'Invoice contact not set. Choose it in ISPAG Settings → Invoicing.');
        $contact_id = $user->ID;
        $email_contact = $user->user_email;
        $lang = 'fr_FR'; // textes uniquement en français

        // Texte du mail : réglage (ou texte par défaut)
        $tpl = ISPAG_Invoice_Settings::template((string) $message_type);
        if (!$tpl) return new WP_Error('mail', 'Unknown mail type: ' . $message_type);
        $template = (object) $tpl;

        // Remplacer les balises
        $subject = self::replace_text($template->subject, $deal_id, $contact_id, $article_ids);
        $message = self::replace_text($template->message, $deal_id, $contact_id, $article_ids);

        $subject = html_entity_decode($subject, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $message = html_entity_decode($message, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return [
            'deal_id' => $deal_id,
            'subject' => $subject,
            'message_type' => $message_type,
            'template' => $template,
            'lang' => $lang,
            'message' => $message,
            'email_contact' => $email_contact,
            'email_copy' => ' ' // à adapter
        ];
    }

    
    public static function replace_text($text, $deal_id, $contact_id, array $article_ids = []) {
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
            return "Error: Projet introuvable pour le deal ID $deal_id";
        }
        

        // 3. Récupérer articles
        // $articles = (new ISPAG_Article_Repository())->get_articles_by_deal($deal_id);
        $article_repo = new ISPAG_Article_Repository();
        if (current_user_can('navigate_new_project_details_presentation')) {
            $articles = $article_repo->get_optimised_articles_by_deal($deal_id);
        }
        else{
            $articles = $article_repo->get_articles_by_deal($deal_id);
        }

        // Seuls les articles sélectionnés (s'il y en a) : titres regroupés par groupe
        //   Groupe
        //   art1
        //   art2
        //
        //   Groupe2
        //   ...
        // Les repositories renvoient [groupe => [article principal (->secondaires), …]] : on aplatit principal + secondaires
        $by_group = [];
        $add = function ($article) use (&$by_group, $article_ids) {
            if ($article_ids && !in_array((int) $article->Id, $article_ids, true)) return;
            $title = trim(stripslashes((string) ($article->Article ?? '')));
            if ($title === '') return;
            $group = trim(stripslashes((string) ($article->Groupe ?? '')));
            $by_group[$group][] = $title;
        };
        foreach ((array) $articles as $group_articles) {
            foreach ((array) $group_articles as $principal) {
                if (!is_object($principal)) continue;
                $add($principal);
                foreach ((array) ($principal->secondaires ?? []) as $secondaire) {
                    $add($secondaire);
                }
            }
        }
        $blocks = [];
        foreach ($by_group as $group => $titles) {
            $blocks[] = implode("\n", array_filter(array_merge([$group], $titles), 'strlen'));
        }
        $product_list = implode("\n\n", $blocks);

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
            'MOIS_EN_COURS' => $formatter->format(new DateTime()),
            'PROJECT_URL'  => (string) $project->project_url,
        ];

        // Balises {TAG} (réglages ISPAG Settings → Invoicing)
        $braced = [];
        foreach ($replacements as $tag => $value) {
            $braced['{' . $tag . '}'] = (string) $value;
        }
        $text = strtr($text, $braced);

        // 6. Nettoyer le texte
        $text = str_ireplace(['<br />', '<br/>'], "\n", $text);
        $text = preg_replace("/<hr\W*?\/?>/", str_repeat('- ', 30), $text);
        $text = strip_tags($text);

        restore_current_locale();

        return $text;
    }
}