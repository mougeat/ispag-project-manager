<?php
defined('ABSPATH') || exit;

/**
 * E-mails clients envoyés quand une étape du suivi de projet passe à « fait » — sans Brevo.
 *
 * - Une étape envoie un e-mail si sa ligne de achats_slug_phase a Brevo_id > 0 (Brevo_id = id du template
 *   français de l'étape ; le champ ne sert plus à appeler Brevo).
 * - Les textes sont des templates du CRM (table ispag_templates, éditeur « ISPAG Template Dashboard »,
 *   comme les autres e-mails) : dossier « project_mail », un template par langue (fr / en / de) dont le
 *   NOM est le SlugPhase de l'étape — ne pas le renommer.
 * - Documents joints par étape : option ispag_phase_mail_docs (réglage ISPAG Settings → Phase e-mails),
 *   types de documents (achats_doc_types.slug) ; 'last_drawing' = dernier plan non validé de chaque article.
 * - Envoi par wp_mail ; Brevo_delay_days retarde l'envoi (WP-Cron).
 */
class ISPAG_Phase_Mail {

    const FOLDER     = 'project_mail';
    const FAMILY     = 'project_mail'; // ancien emplacement (achats_template_mail), migré vers ispag_templates
    const DB_MARK    = '2';
    const OPT_DOCS   = 'ispag_phase_mail_docs';
    const LOG        = 'phase_mail';
    const NONCE      = 'ispag_phase_mail_settings';
    const OPT_SURVEY = 'ispag_satisfaction_survey_url';
    const CRON       = 'ispag_phase_mail_delayed';
    const LANGS      = ['fr' => 'Français', 'en' => 'English', 'de' => 'Deutsch'];
    const LANG_KEYS  = ['fr_FR' => 'fr', 'en_US' => 'en', 'de_DE' => 'de']; // clés de install/phase-mail-templates.php
    const DRAWING_TYPES = ['product_drawing', 'drawingApproval', 'drawingModification', 'sketch'];
    const MAX_ATTACH_BYTES = 15728640; // 15 Mo au total : au-delà, les fichiers restent consultables sur la fiche projet

    public static function init() {
        add_action('ispag_send_mail_from_slug', [self::class, 'send_from_hook'], 10, 3);
        add_filter('ispag_send_phase_mail', [self::class, 'filter_send'], 10, 3);
        add_action(self::CRON, [self::class, 'deliver'], 10, 5);
        add_action('admin_menu', [self::class, 'admin_menu']);
        add_action('admin_init', [self::class, 'maybe_ensure_defaults']);
    }

    /** Réessaie tant que les tables du CRM n'existaient pas au moment de l'installation du plugin. */
    public static function maybe_ensure_defaults() {
        if (get_option('ispag_phase_mail_ready') === self::DB_MARK) return;
        self::ensure_defaults();
    }

    // ------------------------------------------------------------------
    // Données
    // ------------------------------------------------------------------

    private static function t_tpl()   { global $wpdb; return $wpdb->prefix . 'ispag_templates'; }
    private static function t_folder() { global $wpdb; return $wpdb->prefix . 'ispag_template_folders'; }
    private static function t_old()   { global $wpdb; return $wpdb->prefix . 'achats_template_mail'; }
    private static function t_slug()  { global $wpdb; return $wpdb->prefix . 'achats_slug_phase'; }

    private static function log($message, array $ctx = []) {
        ISPAG_Logger::get_instance()->log(self::LOG, $message . ($ctx ? ' ' . wp_json_encode($ctx) : ''), get_current_user_id());
    }

    /** Textes par défaut : install/phase-mail-templates.php */
    public static function defaults(): array {
        $file = dirname(__DIR__) . '/install/phase-mail-templates.php';
        $out = [];
        foreach (is_readable($file) ? (array) require $file : [] as $slug => $def) {
            $out[$slug] = ['docs' => (array) ($def['docs'] ?? [])];
            foreach (self::LANG_KEYS as $long => $short) {
                if (!empty($def[$long])) $out[$slug][$short] = $def[$long];
            }
        }
        return $out;
    }

    /** Documents à joindre pour l'étape : réglage enregistré, sinon valeur par défaut. */
    public static function get_docs($slug): string {
        $saved = (array) get_option(self::OPT_DOCS, []);
        if (array_key_exists($slug, $saved)) return (string) $saved[$slug];
        $defaults = self::defaults();
        return implode(',', (array) ($defaults[$slug]['docs'] ?? []));
    }

    private static function folder_id(bool $create = false): int {
        global $wpdb;
        $id = (int) $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . self::t_folder() . ' WHERE name = %s AND owner_id IS NULL ORDER BY id ASC LIMIT 1', self::FOLDER));
        if (!$id && $create && $wpdb->insert(self::t_folder(), ['name' => self::FOLDER, 'owner_id' => null])) {
            $id = (int) $wpdb->insert_id;
        }
        return $id;
    }

    /** L'étape envoie-t-elle un e-mail ? (Brevo_id > 0) */
    public static function is_enabled($slug): bool {
        global $wpdb;
        if (empty($slug)) return false;
        return (int) $wpdb->get_var($wpdb->prepare('SELECT Brevo_id FROM ' . self::t_slug() . ' WHERE SlugPhase = %s', $slug)) > 0;
    }

    public static function get_delay_days($slug): int {
        global $wpdb;
        return max(0, (int) $wpdb->get_var($wpdb->prepare('SELECT Brevo_delay_days FROM ' . self::t_slug() . ' WHERE SlugPhase = %s', $slug)));
    }

    /** Template (ligne ispag_templates) de l'étape pour la langue (repli : français, puis n'importe quelle langue). */
    public static function get_template($slug, $lang = 'fr') {
        global $wpdb;
        $folder = self::folder_id();
        if (!$folder) return null;
        foreach (array_unique([$lang, 'fr']) as $l) {
            $row = $wpdb->get_row($wpdb->prepare(
                'SELECT * FROM ' . self::t_tpl() . ' WHERE folder_id = %d AND name = %s AND language = %s AND owner_id IS NULL ORDER BY id DESC LIMIT 1',
                $folder, $slug, $l
            ));
            if ($row) return $row;
        }
        return $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . self::t_tpl() . ' WHERE folder_id = %d AND name = %s ORDER BY id ASC LIMIT 1',
            $folder, $slug
        ));
    }

    private static function user_lang($user_id): string {
        $raw = (string) (get_user_meta($user_id, 'locale', true) ?: get_user_meta($user_id, 'pll_language', true));
        $lang = strtolower(substr($raw, 0, 2));
        return isset(self::LANGS[$lang]) ? $lang : 'fr';
    }

    /**
     * Crée dans le CRM (ispag_templates, dossier project_mail) les templates par défaut manquants et fait
     * pointer Brevo_id vers le template français de l'étape. Ne modifie jamais un texte déjà présent.
     * Reprend au passage les textes de l'ancien emplacement (achats_template_mail, famille project_mail).
     * Appelé par ISPAG_Installer::install().
     */
    public static function ensure_defaults(): bool {
        global $wpdb;
        $tpl = self::t_tpl();
        foreach ([$tpl, self::t_folder()] as $table) {
            if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) return false; // CRM pas encore installé : réessayé plus tard
        }
        $folder = self::folder_id(true);
        if (!$folder) return false;

        $old_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', self::t_old())) === self::t_old();
        $docs_saved = (array) get_option(self::OPT_DOCS, []);

        foreach (self::defaults() as $slug => $def) {
            foreach (array_keys(self::LANGS) as $lang) {
                $exists = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$tpl} WHERE folder_id = %d AND name = %s AND language = %s AND owner_id IS NULL",
                    $folder, $slug, $lang
                ));
                if ($exists) continue;

                $subject = $def[$lang]['subject'] ?? '';
                $message = $def[$lang]['message'] ?? '';
                if ($old_exists) { // texte éventuellement déjà modifié dans l'ancien emplacement
                    $long = array_search($lang, self::LANG_KEYS, true);
                    $old = $wpdb->get_row($wpdb->prepare(
                        'SELECT subject, message, join_doc_typ FROM ' . self::t_old() . ' WHERE message_family = %s AND message_type = %s AND lang = %s ORDER BY Id DESC LIMIT 1',
                        self::FAMILY, $slug, $long
                    ));
                    if ($old) {
                        $subject = $old->subject;
                        $message = $old->message;
                        if ($lang === 'fr' && !array_key_exists($slug, $docs_saved)) $docs_saved[$slug] = (string) $old->join_doc_typ;
                    }
                }
                if ($message === '') continue;
                $wpdb->insert($tpl, [
                    'folder_id' => $folder,
                    'owner_id'  => null,
                    'language'  => $lang,
                    'name'      => $slug,
                    'subject'   => $subject,
                    'content'   => $message,
                ]);
            }

            // Étape qui envoyait un e-mail Brevo (Brevo_id > 0) : l'id pointe maintenant vers notre template
            $current = (int) $wpdb->get_var($wpdb->prepare('SELECT Brevo_id FROM ' . self::t_slug() . ' WHERE SlugPhase = %s', $slug));
            if ($current <= 0) continue;
            $valid = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$tpl} WHERE id = %d AND folder_id = %d AND name = %s",
                $current, $folder, $slug
            ));
            if ($valid) continue;
            $fr_id = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$tpl} WHERE folder_id = %d AND name = %s AND language = 'fr' AND owner_id IS NULL ORDER BY id ASC LIMIT 1",
                $folder, $slug
            ));
            if ($fr_id) {
                $wpdb->update(self::t_slug(), ['Brevo_id' => $fr_id], ['SlugPhase' => $slug]);
            }
        }
        update_option(self::OPT_DOCS, $docs_saved, false);

        // Ancien emplacement : lignes créées par la version précédente, désormais reprises dans ispag_templates
        if ($old_exists) {
            $wpdb->delete(self::t_old(), ['message_family' => self::FAMILY, 'created_by' => 0]);
        }
        update_option('ispag_phase_mail_ready', self::DB_MARK, false);
        return true;
    }

    // ------------------------------------------------------------------
    // Points d'entrée
    // ------------------------------------------------------------------

    /** Hook ispag_send_mail_from_slug : contact principal du projet en destinataire, les autres en copie. */
    public static function send_from_hook($html, $deal_id, $slug) {
        self::send_to_project((int) $deal_id, (string) $slug);
    }

    public static function send_to_project(int $deal_id, string $slug): bool {
        if (!self::is_enabled($slug)) return false;

        $project = apply_filters('ispag_get_project_by_deal_id', null, $deal_id);
        if (!$project) {
            self::log("Projet introuvable pour le deal $deal_id (étape $slug)");
            return false;
        }

        $contact_ids = ISPAG_Project_Phase_Resolver::get_all_contact_ids($project);
        $to_id = $contact_ids[0] ?? 0;
        if (!$to_id) {
            self::log("Aucun contact associé au deal $deal_id (étape $slug)");
            return false;
        }

        $cc_ids = array_merge(
            array_slice($contact_ids, 1),
            ISPAG_Project_Phase_Resolver::get_subscriber_ids($project),
            [ISPAG_Project_Phase_Resolver::get_project_manager_id($project)]
        );
        return self::dispatch($deal_id, $slug, (int) $to_id, $cc_ids);
    }

    /**
     * Filtre ispag_send_phase_mail (appelé par les notifications du CRM pour le type deal_status_change).
     * @return bool|null null = pas un e-mail d'étape, le CRM garde son comportement
     */
    public static function filter_send($handled, $user_id, $extra) {
        $slug = is_array($extra) ? (string) ($extra['phase_slug'] ?? '') : '';
        $deal_id = is_array($extra) ? (int) ($extra['deal_id'] ?? 0) : 0;
        if ($slug === '' || !$deal_id) return $handled;
        if (!self::is_enabled($slug)) return true; // étape sans e-mail : rien à envoyer
        return self::dispatch($deal_id, $slug, (int) $user_id, (array) ($extra['cc_ids'] ?? []));
    }

    /** Envoie tout de suite, ou programme l'envoi si l'étape a un délai (Brevo_delay_days). */
    private static function dispatch(int $deal_id, string $slug, int $to_id, array $cc_ids): bool {
        $cc_ids = array_values(array_unique(array_filter(array_map('intval', $cc_ids))));
        $delay  = self::get_delay_days($slug);
        if ($delay > 0) {
            wp_schedule_single_event(time() + $delay * DAY_IN_SECONDS, self::CRON, [$deal_id, $slug, $to_id, $cc_ids, get_current_user_id()]);
            self::log("Envoi programmé dans $delay jour(s)", ['deal' => $deal_id, 'slug' => $slug, 'to' => $to_id]);
            return true;
        }
        return self::deliver($deal_id, $slug, $to_id, $cc_ids, get_current_user_id());
    }

    // ------------------------------------------------------------------
    // Envoi
    // ------------------------------------------------------------------

    public static function deliver($deal_id, $slug, $to_id, $cc_ids = [], $sender_id = 0): bool {
        $deal_id = (int) $deal_id;
        $to = get_userdata((int) $to_id);
        if (!$to || !is_email($to->user_email)) {
            self::log("Destinataire invalide ($to_id) pour le deal $deal_id (étape $slug)");
            return false;
        }

        $tpl = self::get_template($slug, self::user_lang($to->ID));
        if (!$tpl) {
            self::log("Aucun template d'e-mail pour l'étape '$slug'");
            return false;
        }

        $project = apply_filters('ispag_get_project_by_deal_id', null, $deal_id);
        if (!$project) {
            self::log("Projet introuvable pour le deal $deal_id (étape $slug)");
            return false;
        }

        $sender = self::sender((int) $sender_id);
        $values = self::tag_values($deal_id, $project, $to, $sender);

        $subject = wp_strip_all_tags(html_entity_decode(strtr($tpl->subject, $values), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $subject = preg_replace('/\{[A-Z_]+\}/', '', $subject);
        $body    = wpautop(preg_replace('/\{[A-Z_]+\}/', '', strtr(wp_kses_post($tpl->content), $values)));

        $attachments = self::collect_attachments($deal_id, self::get_docs($slug));

        // Destinataires en copie : sans doublon ni le destinataire principal
        $cc = [];
        $add_cc = function ($email, $name = '') use (&$cc, $to) {
            $email = trim((string) $email);
            if (is_email($email) && strcasecmp($email, $to->user_email) !== 0) $cc[strtolower($email)] = $name !== '' ? "$name <$email>" : $email;
        };
        foreach ($cc_ids as $id) {
            $u = get_userdata((int) $id);
            if ($u) $add_cc($u->user_email, $u->display_name);
        }
        $add_cc($sender['email'], $sender['name']);
        foreach (apply_filters('ispag_phase_mail_fixed_cc', [
            ['c.barthel@ispag-asp.ch', 'Cyril Barthel'],
            ['log@mg.ispag-asp.com', 'log CRM'],
        ]) as $fixed) {
            $add_cc($fixed[0], $fixed[1] ?? '');
        }

        $from = apply_filters('ispag_phase_mail_from', ['name' => $sender['name'], 'email' => 'noreply@ispag-asp.com'], $sender);
        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . $from['name'] . ' <' . $from['email'] . '>',
            'Reply-To: ' . $sender['name'] . ' <' . $sender['email'] . '>',
        ];
        foreach ($cc as $line) $headers[] = 'Cc: ' . $line;

        $name = trim($to->first_name . ' ' . $to->last_name) ?: $to->display_name;
        $sent = wp_mail($name . ' <' . $to->user_email . '>', $subject, self::wrap_html($body), $headers, $attachments);

        self::log($sent ? 'E-mail envoyé' : 'ÉCHEC wp_mail', [
            'deal' => $deal_id, 'slug' => $slug, 'to' => $to->user_email, 'lang' => $tpl->language,
            'cc' => array_keys($cc), 'attachments' => array_map('basename', $attachments),
        ]);
        return (bool) $sent;
    }

    /** Expéditeur = utilisateur connecté (adresse .com des collaborateurs remplacée par leur adresse .ch). */
    private static function sender(int $user_id): array {
        $user = $user_id ? get_userdata($user_id) : null;
        $email = ($user && $user->user_email) ? $user->user_email : 'c.barthel@ispag-asp.com';
        $map = apply_filters('ispag_phase_mail_sender_map', [
            'vente@ispag-asp.com'     => 'c.tonelli@ispag-asp.ch',
            'c.barthel@ispag-asp.com' => 'c.barthel@ispag-asp.ch',
        ]);
        return [
            'email' => $map[$email] ?? $email,
            'name'  => ($user && $user->display_name) ? $user->display_name : 'ISPAG',
        ];
    }

    private static function wrap_html(string $body): string {
        return '<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.5;color:#222;max-width:640px">'
            . $body . '</div>';
    }

    // ------------------------------------------------------------------
    // Balises
    // ------------------------------------------------------------------

    public static function tags(): array {
        return [
            '{PRENOM}'         => __('Recipient first name', 'creation-reservoir'),
            '{NOM}'            => __('Recipient last name', 'creation-reservoir'),
            '{PROJECT_NAME}'   => __('Project name', 'creation-reservoir'),
            '{PROJECT_NUMBER}' => __('Order number', 'creation-reservoir'),
            '{PROJECT_URL}'    => __('Link to the project (address only)', 'creation-reservoir'),
            '{PROJECT_LINK}'   => __('Link to the project (clickable, shows the project name)', 'creation-reservoir'),
            '{PRODUCT_LIST}'   => __('List of items, grouped', 'creation-reservoir'),
            '{DELIVERY_DATE}'  => __('Planned delivery date (or period)', 'creation-reservoir'),
            '{DELIVERY_ADRESS}' => __('Delivery address', 'creation-reservoir'),
            '{DELIVERY_NIP}'   => __('Delivery postal code', 'creation-reservoir'),
            '{DELIVERY_CITY}'  => __('Delivery city', 'creation-reservoir'),
            '{DELIVERY_CONTACT}' => __('On-site contact', 'creation-reservoir'),
            '{DELIVERY_CONTACT_PHONE}' => __('On-site contact phone', 'creation-reservoir'),
            '{SURVEY_LINK}'    => __('Satisfaction survey link (set below)', 'creation-reservoir'),
            '{USER_NAME}'      => __('Name of the person who triggered the e-mail', 'creation-reservoir'),
        ];
    }

    /** Valeurs des balises, déjà échappées pour le HTML. */
    private static function tag_values(int $deal_id, $project, WP_User $to, array $sender): array {
        $e = function ($v) { return esc_html(html_entity_decode((string) $v, ENT_QUOTES | ENT_HTML5, 'UTF-8')); };
        $infos = (new ISPAG_Project_Details_Repository())->get_infos_livraison($deal_id);
        $url   = (string) ($project->project_url ?? '');
        $name  = $e($project->ObjetCommande ?? '');

        $survey = trim((string) get_option(self::OPT_SURVEY, ''));
        $survey = $survey !== '' ? $survey : $url;

        return [
            '{PRENOM}'         => $e($to->first_name ?: $to->display_name),
            '{NOM}'            => $e($to->last_name),
            '{PROJECT_NAME}'   => $name,
            '{PROJECT_NUMBER}' => $e($project->NumCommande ?? ''),
            '{PROJECT_URL}'    => esc_url($url),
            '{PROJECT_LINK}'   => $url !== '' ? '<a href="' . esc_url($url) . '">' . $name . '</a>' : $name,
            '{PRODUCT_LIST}'   => self::product_list($deal_id),
            '{DELIVERY_DATE}'  => self::delivery_date($deal_id),
            '{DELIVERY_ADRESS}' => $e($infos->AdresseDeLivraison ?? ''),
            '{DELIVERY_NIP}'   => $e($infos->NIP ?? ''),
            '{DELIVERY_CITY}'  => $e($infos->City ?? ''),
            '{DELIVERY_CONTACT}' => $e($infos->PersonneContact ?? ''),
            '{DELIVERY_CONTACT_PHONE}' => $e($infos->num_tel_contact ?? ''),
            '{SURVEY_LINK}'    => $survey !== '' ? '<a href="' . esc_url($survey) . '">' . esc_html($survey) . '</a>' : '',
            '{USER_NAME}'      => esc_html($sender['name']),
        ];
    }

    private static function product_list(int $deal_id): string {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT Groupe, Article FROM ' . $wpdb->prefix . 'achats_details_commande
             WHERE hubspot_deal_id = %d AND archive = 0 AND customer_visible = 1 ORDER BY tri ASC, Id ASC',
            $deal_id
        ));
        if (!$rows) return '';
        $by_group = [];
        foreach ($rows as $r) {
            $by_group[trim((string) $r->Groupe)][] = trim(stripslashes((string) $r->Article));
        }
        $html = '';
        foreach ($by_group as $group => $articles) {
            if ($group !== '') $html .= '<strong>' . esc_html(html_entity_decode($group, ENT_QUOTES | ENT_HTML5, 'UTF-8')) . '</strong>';
            $html .= '<ul>';
            foreach ($articles as $a) {
                if ($a !== '') $html .= '<li>' . esc_html(html_entity_decode($a, ENT_QUOTES | ENT_HTML5, 'UTF-8')) . '</li>';
            }
            $html .= '</ul>';
        }
        return $html;
    }

    /** Du premier jour de livraison prévu au dernier (une seule date si identiques). */
    private static function delivery_date(int $deal_id): string {
        global $wpdb;
        $r = $wpdb->get_row($wpdb->prepare(
            'SELECT MIN(NULLIF(TimestampDateDeLivraison, 0)) AS d1, MAX(NULLIF(TimestampDateDeLivraisonFin, 0)) AS d2, MAX(NULLIF(TimestampDateDeLivraison, 0)) AS d3
             FROM ' . $wpdb->prefix . 'achats_details_commande WHERE hubspot_deal_id = %d AND archive = 0',
            $deal_id
        ));
        if (!$r || (!$r->d1 && !$r->d2)) return esc_html__('to be confirmed', 'creation-reservoir');
        $start = (int) ($r->d1 ?: $r->d2);
        $end   = (int) max((int) $r->d2, (int) $r->d3);
        $fmt   = 'd.m.Y';
        $text  = wp_date($fmt, $start);
        if ($end && wp_date($fmt, $end) !== $text) $text .= ' - ' . wp_date($fmt, $end);
        return esc_html($text);
    }

    // ------------------------------------------------------------------
    // Pièces jointes
    // ------------------------------------------------------------------

    /**
     * @param string $join_doc_typ slugs de achats_doc_types séparés par des virgules (+ 'last_drawing')
     * @return string[] chemins de fichiers
     */
    public static function collect_attachments(int $deal_id, string $join_doc_typ): array {
        global $wpdb;
        $slugs = array_values(array_filter(array_map('trim', explode(',', $join_doc_typ))));
        if (!$slugs) return [];

        $hist = $wpdb->prefix . 'achats_historique';
        $media_ids = [];

        // Un seul fichier par type et par article : le plus récent
        $types = array_values(array_diff($slugs, ['last_drawing']));
        if ($types) {
            $ph = implode(',', array_fill(0, count($types), '%s'));
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT ClassCss, Historique, IdMedia FROM {$hist}
                 WHERE hubspot_deal_id = %d AND IdMedia > 0 AND ClassCss IN ($ph) ORDER BY dateReadable DESC, Id DESC",
                array_merge([$deal_id], $types)
            ));
            $seen = [];
            foreach ((array) $rows as $r) {
                $key = $r->ClassCss . '|' . $r->Historique;
                if (isset($seen[$key])) continue;
                $seen[$key] = true;
                $media_ids[] = (int) $r->IdMedia;
            }
        }

        // Dernier plan des articles dont le plan n'est pas encore validé
        if (in_array('last_drawing', $slugs, true)) {
            $ph = implode(',', array_fill(0, count(self::DRAWING_TYPES), '%s'));
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT h.Historique, h.IdMedia FROM {$hist} h
                 INNER JOIN {$wpdb->prefix}achats_details_commande d ON d.Id = CAST(h.Historique AS UNSIGNED) AND d.archive = 0
                 WHERE h.hubspot_deal_id = %d AND h.IdMedia > 0 AND h.ClassCss IN ($ph)
                   AND h.Historique REGEXP '^[0-9]+$' AND COALESCE(d.DrawingApproved, 0) = 0
                 ORDER BY h.dateReadable DESC, h.Id DESC",
                array_merge([$deal_id], self::DRAWING_TYPES)
            ));
            $seen = [];
            foreach ((array) $rows as $r) {
                if (isset($seen[$r->Historique])) continue;
                $seen[$r->Historique] = true;
                $media_ids[] = (int) $r->IdMedia;
            }
        }

        $files = [];
        $total = 0;
        foreach (array_unique($media_ids) as $id) {
            $path = get_attached_file($id);
            if (!$path || !is_readable($path)) continue;
            $size = (int) filesize($path);
            if ($total + $size > apply_filters('ispag_phase_mail_max_attachment_bytes', self::MAX_ATTACH_BYTES)) {
                self::log('Pièce jointe ignorée (taille cumulée trop grande)', ['file' => basename($path)]);
                continue;
            }
            $total += $size;
            $files[$path] = $path;
        }
        return array_values($files);
    }

    // ------------------------------------------------------------------
    // Réglages : ISPAG Settings → Phase e-mails
    // ------------------------------------------------------------------

    public static function admin_menu() {
        $title = __('Phase e-mails', 'creation-reservoir');
        if (class_exists('ISPAG_Settings')) {
            add_submenu_page(ISPAG_Settings::PAGE, $title, $title, 'manage_options', 'ispag-phase-mails', [self::class, 'render']);
        } else {
            add_management_page($title, $title, 'manage_options', 'ispag-phase-mails', [self::class, 'render']);
        }
    }

    /** Étapes qui ont (ou ont eu) un e-mail : Brevo_id > 0 ou un template dans le dossier project_mail. */
    private static function admin_slugs(): array {
        global $wpdb;
        $folder = self::folder_id();
        return (array) $wpdb->get_results($wpdb->prepare(
            'SELECT s.SlugPhase, s.TitrePhase, s.Brevo_id FROM ' . self::t_slug() . ' s
             WHERE s.Brevo_id > 0 OR s.SlugPhase IN (SELECT name FROM ' . self::t_tpl() . ' WHERE folder_id = %d)
             ORDER BY s.Ordre ASC',
            $folder
        ));
    }

    private static function save() {
        global $wpdb;
        update_option(self::OPT_SURVEY, esc_url_raw(wp_unslash($_POST['survey_url'] ?? '')), false);

        $docs_saved = (array) get_option(self::OPT_DOCS, []);
        foreach (self::admin_slugs() as $s) {
            $slug = $s->SlugPhase;
            $data = (array) ($_POST['pm'][$slug] ?? []);
            $docs_saved[$slug] = implode(',', array_map('sanitize_text_field', array_map('wp_unslash', (array) ($data['docs'] ?? []))));

            $fr = self::get_template($slug, 'fr');
            $wpdb->update(self::t_slug(), ['Brevo_id' => (!empty($data['enabled']) && $fr) ? (int) $fr->id : 0], ['SlugPhase' => $slug]);
        }
        update_option(self::OPT_DOCS, $docs_saved, false);
    }

    public static function render() {
        global $wpdb;
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Not authorized', 'creation-reservoir'));
        }

        $notice = '';
        if (!empty($_POST['ispag_phase_mail_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['ispag_phase_mail_nonce'])), self::NONCE)) {
            self::save();
            $notice = '<div class="notice notice-success"><p>' . esc_html__('Settings saved.', 'creation-reservoir') . '</p></div>';
        }

        $doc_types = (array) $wpdb->get_results('SELECT slug, label FROM ' . $wpdb->prefix . 'achats_doc_types ORDER BY sort_order ASC');
        $doc_options = ['last_drawing' => __('Latest drawing of each item not yet approved', 'creation-reservoir')];
        foreach ($doc_types as $d) $doc_options[$d->slug] = $d->label . ' (' . $d->slug . ')';

        echo '<div class="wrap"><h1>' . esc_html__('Phase e-mails', 'creation-reservoir') . '</h1>' . $notice;
        echo '<p>' . esc_html__('E-mails sent to the customer when a project step is completed (sent from this site, no Brevo). The texts are edited like the other e-mail templates, in the template dashboard: folder "project_mail", one template per language, named after the step code (do not rename them). The language is the one of the recipient; French is used when a translation is missing.', 'creation-reservoir') . '</p>';
        echo '<form method="post">';
        wp_nonce_field(self::NONCE, 'ispag_phase_mail_nonce');

        echo '<table class="form-table"><tr><th><label for="survey_url">' . esc_html__('Satisfaction survey URL', 'creation-reservoir') . '</label></th><td>'
            . '<input type="url" class="large-text" id="survey_url" name="survey_url" value="' . esc_attr((string) get_option(self::OPT_SURVEY, '')) . '">'
            . '<p class="description">' . esc_html__('Used by the {SURVEY_LINK} tag. Empty = link to the project.', 'creation-reservoir') . '</p></td></tr></table>';

        foreach (self::admin_slugs() as $s) {
            $slug = $s->SlugPhase;
            $selected = array_filter(array_map('trim', explode(',', self::get_docs($slug))));
            echo '<div style="background:#fff;border:1px solid #ccd0d4;padding:8px 14px;margin:10px 0"><h3 style="margin:.4em 0">'
                . esc_html($s->TitrePhase) . ' <code>' . esc_html($slug) . '</code></h3>';
            echo '<p><label><input type="checkbox" name="pm[' . esc_attr($slug) . '][enabled]" value="1" ' . checked((int) $s->Brevo_id > 0, true, false) . '> '
                . esc_html__('Send this e-mail when the step is completed', 'creation-reservoir') . '</label></p>';
            echo '<p><strong>' . esc_html__('Attached documents', 'creation-reservoir') . '</strong><br>';
            foreach ($doc_options as $val => $label) {
                echo '<label style="display:inline-block;margin-right:14px"><input type="checkbox" name="pm[' . esc_attr($slug) . '][docs][]" value="' . esc_attr($val) . '" ' . checked(in_array($val, $selected, true), true, false) . '> ' . esc_html($label) . '</label>';
            }
            echo '</p></div>';
        }

        echo '<h2>' . esc_html__('Available tags', 'creation-reservoir') . '</h2><ul>';
        foreach (self::tags() as $tag => $desc) {
            echo '<li><code>' . esc_html($tag) . '</code> — ' . esc_html($desc) . '</li>';
        }
        echo '</ul><p><button type="submit" class="button button-primary">' . esc_html__('Save', 'creation-reservoir') . '</button></p></form></div>';
    }
}
