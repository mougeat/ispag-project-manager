<?php
defined('ABSPATH') || exit;

/**
 * E-mails clients envoyés quand une étape du suivi de projet passe à « fait » — sans Brevo.
 *
 * - Une étape envoie un e-mail si sa ligne de achats_slug_phase a Brevo_id > 0 (Brevo_id = id du template
 *   français de l'étape ; le champ ne sert plus à appeler Brevo).
 * - Les textes sont dans achats_template_mail, comme les e-mails des commandes fournisseurs :
 *   message_family = 'project_mail', message_type = SlugPhase, lang = fr_FR / en_US / de_DE (/ it_IT…).
 *   Ils s'éditent dans ISPAG Settings → Email templates (plugin ISPAG Achats).
 * - Documents joints par étape : option ispag_phase_mail_docs (réglage ISPAG Settings → Phase e-mails),
 *   types de documents (achats_doc_types.slug) ; 'last_drawing' = dernier plan non validé de chaque article.
 * - Envoi par wp_mail ; Brevo_delay_days retarde l'envoi (WP-Cron).
 */
class ISPAG_Phase_Mail {

    const FOLDER     = 'project_mail'; // dossier de l'éditeur du CRM (ispag_templates) où une version précédente avait créé les textes
    const FAMILY     = 'project_mail';
    const DB_MARK    = '4';
    const OPT_DOCS   = 'ispag_phase_mail_docs';
    const LOG        = 'phase_mail';
    const NONCE      = 'ispag_phase_mail_settings';
    const OPT_SURVEY = 'ispag_satisfaction_survey_url';
    const CRON       = 'ispag_phase_mail_delayed';
    const LANGS      = ['fr_FR' => 'Français', 'en_US' => 'English', 'de_DE' => 'Deutsch'];
    const LOCALES    = ['fr' => 'fr_FR', 'en' => 'en_US', 'de' => 'de_DE', 'it' => 'it_IT'];
    const LINK_LABELS = ['fr_FR' => 'voir le projet', 'en_US' => 'view the project', 'de_DE' => 'Projekt ansehen', 'it_IT' => 'vedi il progetto'];
    const DRAWING_TYPES = ['product_drawing', 'drawingApproval', 'drawingModification', 'sketch'];
    const MAX_ATTACH_BYTES = 15728640; // 15 Mo au total : au-delà, les fichiers restent consultables sur la fiche projet

    /** Erreurs de la dernière exécution de ensure_defaults() (affichées dans les réglages). */
    public static $errors = [];

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
        if (wp_doing_ajax() || get_transient('ispag_phase_mail_retry')) return; // jamais pendant un appel AJAX ; en cas d'échec, une tentative par heure
        if (!self::ensure_defaults()) {
            set_transient('ispag_phase_mail_retry', 1, HOUR_IN_SECONDS);
        }
    }

    // ------------------------------------------------------------------
    // Données
    // ------------------------------------------------------------------

    private static function t_tpl()   { global $wpdb; return $wpdb->prefix . 'achats_template_mail'; }
    private static function t_crm()   { global $wpdb; return $wpdb->prefix . 'ispag_templates'; }       // ancien emplacement (éditeur du CRM)
    private static function t_folder() { global $wpdb; return $wpdb->prefix . 'ispag_template_folders'; }
    private static function t_slug()  { global $wpdb; return $wpdb->prefix . 'achats_slug_phase'; }

    private static function log($message, array $ctx = []) {
        ISPAG_Logger::get_instance()->log(self::LOG, $message . ($ctx ? ' ' . wp_json_encode($ctx) : ''), get_current_user_id());
    }

    /** Textes par défaut : install/phase-mail-templates.php (slug => ['docs' => [...], 'fr_FR' => ['subject','message'], …]) */
    public static function defaults(): array {
        $file = dirname(__DIR__) . '/install/phase-mail-templates.php';
        return is_readable($file) ? (array) require $file : [];
    }

    /** Documents à joindre pour l'étape : réglage enregistré, sinon valeur par défaut. */
    public static function get_docs($slug): string {
        $saved = (array) get_option(self::OPT_DOCS, []);
        if (array_key_exists($slug, $saved)) return (string) $saved[$slug];
        $defaults = self::defaults();
        return implode(',', (array) ($defaults[$slug]['docs'] ?? []));
    }

    /** Dossier project_mail de l'éditeur du CRM (ancien emplacement des textes), 0 s'il n'existe pas. */
    private static function folder_id(): int {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . self::t_folder() . ' WHERE name = %s AND owner_id IS NULL ORDER BY id ASC LIMIT 1', self::FOLDER));
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

    /**
     * Template de l'étape pour la langue (repli : français, puis n'importe quelle langue).
     * @return object|null  ->id, ->subject, ->content, ->language
     */
    public static function get_template($slug, $lang = 'fr_FR') {
        global $wpdb;
        $row = null;
        foreach (array_unique([$lang, 'fr_FR']) as $l) {
            $row = $wpdb->get_row($wpdb->prepare(
                'SELECT Id, lang, subject, message FROM ' . self::t_tpl() . ' WHERE message_family = %s AND message_type = %s AND lang = %s ORDER BY Id DESC LIMIT 1',
                self::FAMILY, $slug, $l
            ));
            if ($row) break;
        }
        if (!$row) {
            $row = $wpdb->get_row($wpdb->prepare(
                'SELECT Id, lang, subject, message FROM ' . self::t_tpl() . ' WHERE message_family = %s AND message_type = %s ORDER BY Id ASC LIMIT 1',
                self::FAMILY, $slug
            ));
        }
        return $row ? (object) ['id' => (int) $row->Id, 'language' => $row->lang, 'subject' => $row->subject, 'content' => $row->message] : null;
    }

    private static function user_lang($user_id): string {
        $raw = (string) (get_user_meta($user_id, 'locale', true) ?: get_user_meta($user_id, 'pll_language', true));
        return self::LOCALES[strtolower(substr($raw, 0, 2))] ?? 'fr_FR';
    }

    /**
     * Crée dans achats_template_mail (famille project_mail) les templates par défaut manquants et fait pointer
     * Brevo_id vers le template français de l'étape. Ne modifie jamais un texte déjà présent.
     * Reprend au passage les textes créés par une version précédente dans l'éditeur du CRM (ispag_templates,
     * dossier project_mail), puis les y supprime. Appelé par ISPAG_Installer::install().
     */
    public static function ensure_defaults(): bool {
        global $wpdb;
        $suppress = $wpdb->suppress_errors(true); // jamais d'erreur SQL affichée dans une page ou une réponse AJAX
        $done = self::ensure_defaults_run();
        $wpdb->suppress_errors($suppress);
        return $done;
    }

    private static function ensure_defaults_run(): bool {
        global $wpdb;
        self::$errors = [];
        $tpl = self::t_tpl();
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $tpl)) !== $tpl) {
            self::$errors[] = "Table $tpl introuvable";
            return false;
        }

        // Textes éventuellement déjà créés (et modifiés) dans l'éditeur du CRM
        $crm = [];
        $crm_ok = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', self::t_crm())) === self::t_crm() && ($folder = self::folder_id());
        if ($crm_ok) {
            $long = ['fr' => 'fr_FR', 'en' => 'en_US', 'de' => 'de_DE'];
            foreach ((array) $wpdb->get_results($wpdb->prepare('SELECT id, name, language, subject, content FROM ' . self::t_crm() . ' WHERE folder_id = %d AND owner_id IS NULL', $folder)) as $r) {
                if (isset($long[$r->language])) $crm[$r->name][$long[$r->language]] = $r;
            }
        }

        foreach (self::defaults() as $slug => $def) {
            foreach (array_keys(self::LANGS) as $lang) {
                if (empty($def[$lang]) && empty($crm[$slug][$lang])) continue;
                $exists = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$tpl} WHERE message_family = %s AND message_type = %s AND lang = %s",
                    self::FAMILY, $slug, $lang
                ));
                if ($exists) {
                    // Texte par défaut d'une version précédente, jamais modifié : remplacé par la nouvelle version
                    $legacy = (array) ($def[$lang]['legacy_messages'] ?? []);
                    if ($legacy) {
                        $ph = implode(',', array_fill(0, count($legacy), '%s'));
                        $wpdb->query($wpdb->prepare(
                            "UPDATE {$tpl} SET subject = %s, message = %s WHERE message_family = %s AND message_type = %s AND lang = %s AND message IN ($ph)",
                            array_merge([$def[$lang]['subject'], $def[$lang]['message'], self::FAMILY, $slug, $lang], $legacy)
                        ));
                    }
                    continue;
                }

                $src = $crm[$slug][$lang] ?? null;
                $subject = $src ? $src->subject : $def[$lang]['subject'];
                $message = $src ? $src->content : $def[$lang]['message'];
                if ($wpdb->insert($tpl, [
                    'Brevo_id'       => 0,
                    'lang'           => $lang,
                    'subject'        => (string) $subject,
                    'message'        => (string) $message,
                    'telegram'       => null,
                    'message_type'   => $slug,
                    'message_family' => self::FAMILY,
                    'prompt'         => '',
                    'join_doc_typ'   => '',
                    'selectionnable' => 1,
                    'created_by'     => 0,
                ]) === false) {
                    self::$errors[] = "Template $slug/$lang non créé : " . $wpdb->last_error;
                    error_log('[ISPAG Phase Mail] ' . end(self::$errors));
                }
            }

            // Étape qui envoyait un e-mail Brevo (Brevo_id > 0) : l'id pointe maintenant vers notre template
            $current = (int) $wpdb->get_var($wpdb->prepare('SELECT Brevo_id FROM ' . self::t_slug() . ' WHERE SlugPhase = %s', $slug));
            if ($current <= 0) continue;
            $valid = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$tpl} WHERE Id = %d AND message_family = %s AND message_type = %s",
                $current, self::FAMILY, $slug
            ));
            if ($valid) continue;
            $fr_id = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT Id FROM {$tpl} WHERE message_family = %s AND message_type = %s AND lang = 'fr_FR' ORDER BY Id ASC LIMIT 1",
                self::FAMILY, $slug
            ));
            if ($fr_id) {
                $wpdb->update(self::t_slug(), ['Brevo_id' => $fr_id], ['SlugPhase' => $slug]);
            }
        }
        if (self::$errors) return false;

        // Reprise terminée : on retire les copies de l'éditeur du CRM
        if ($crm_ok) {
            $wpdb->query($wpdb->prepare('DELETE FROM ' . self::t_crm() . ' WHERE folder_id = %d AND owner_id IS NULL', $folder));
            if (!(int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . self::t_crm() . ' WHERE folder_id = %d', $folder))) {
                $wpdb->delete(self::t_folder(), ['id' => $folder]);
            }
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
        $values = self::tag_values($deal_id, $project, $to, $sender, (string) $tpl->language);

        $has_drawings = self::has_drawings($deal_id);
        $subject = wp_strip_all_tags(html_entity_decode(strtr(self::apply_conditions($tpl->subject, $has_drawings), $values), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $subject = preg_replace('/\{[A-Z_]+\}/', '', $subject);
        $message = self::apply_conditions(wp_kses_post($tpl->content), $has_drawings);
        $body    = wpautop(preg_replace('/\{[A-Z_]+\}/', '', strtr($message, $values)));

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
        ]) as $fixed) {
            $add_cc($fixed[0], $fixed[1] ?? '');
        }

        // Copie cachée (CCI, adresse réglable dans ISPAG Settings → E-mails) : la boîte de journal du CRM (webhook Mailgun) classe le mail dans le projet grâce à la réf. du pied de page
        $bcc = [];
        foreach ((array) apply_filters('ispag_phase_mail_bcc', [get_option(ISPAG_Settings::OPT_LOG_MAILBOX, 'log@mg.ispag-asp.com')]) as $addr) {
            $addr = strtolower(trim((string) $addr));
            if (is_email($addr) && strcasecmp($addr, $to->user_email) !== 0 && !isset($cc[$addr])) $bcc[$addr] = $addr;
        }

        $from = apply_filters('ispag_phase_mail_from', ['name' => $sender['name'], 'email' => 'noreply@ispag-asp.com'], $sender);
        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . $from['name'] . ' <' . $from['email'] . '>',
            'Reply-To: ' . $sender['name'] . ' <' . $sender['email'] . '>',
        ];
        foreach ($cc as $line) $headers[] = 'Cc: ' . $line;
        foreach ($bcc as $addr) $headers[] = 'Bcc: ' . $addr;

        // Réf. de classement lue par le CRM (ISPAG_Mailgun_Webhook_Handler::parse_metadata) : [D-<réf. du deal>]
        $ref = trim((string) ($project->deal_group_ref ?? '')) ?: (string) $deal_id;
        $html = self::wrap_html($body, $ref);

        // Version texte : le CRM lit le corps texte (body-plain) du message reçu, la réf. doit y figurer
        $plain = self::plain_text($html);
        $set_alt = function ($phpmailer) use ($plain) { $phpmailer->AltBody = $plain; };
        add_action('phpmailer_init', $set_alt);

        $name = trim($to->first_name . ' ' . $to->last_name) ?: $to->display_name;
        $sent = wp_mail($name . ' <' . $to->user_email . '>', $subject, $html, $headers, $attachments);
        remove_action('phpmailer_init', $set_alt);

        self::log($sent ? 'E-mail envoyé' : 'ÉCHEC wp_mail', [
            'deal' => $deal_id, 'slug' => $slug, 'to' => $to->user_email, 'lang' => $tpl->language,
            'cc' => array_keys($cc), 'bcc' => array_keys($bcc), 'ref' => $ref, 'attachments' => array_map('basename', $attachments),
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

    private static function wrap_html(string $body, string $ref = ''): string {
        $footer = $ref !== '' ? '<p style="margin-top:28px;color:#9a9a9a;font-size:11px">Ref: [D-' . esc_html($ref) . ']</p>' : '';
        return '<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.5;color:#222;max-width:640px">'
            . $body . $footer . '</div>';
    }

    /** HTML => texte brut (sauts de ligne conservés). */
    private static function plain_text(string $html): string {
        $text = preg_replace('#<br\s*/?>|</p>|</li>|</ul>|</div>#i', "\n", $html);
        $text = html_entity_decode(wp_strip_all_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim(preg_replace("/\n{3,}/", "\n\n", $text));
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
            '{PROJECT_LINK}'   => __('Link to the project (clickable: "view the project" in the recipient language)', 'creation-reservoir'),
            '{IF_DRAWINGS}…{/IF_DRAWINGS}' => __('Text kept only if the order contains a type 1 item (special tank, drawings to approve)', 'creation-reservoir'),
            '{IF_NO_DRAWINGS}…{/IF_NO_DRAWINGS}' => __('Text kept only if the order has no type 1 item', 'creation-reservoir'),
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

    /**
     * Commande avec des plans à faire valider : au moins un article (non archivé) de type 1 (réservoir spécial).
     */
    public static function has_drawings(int $deal_id): bool {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'achats_details_commande WHERE hubspot_deal_id = %d AND archive = 0 AND Type = 1',
            $deal_id
        )) > 0;
    }

    /**
     * Blocs conditionnels d'un template : {IF_DRAWINGS}…{/IF_DRAWINGS} gardé s'il y a des plans,
     * {IF_NO_DRAWINGS}…{/IF_NO_DRAWINGS} gardé s'il n'y en a pas ; sinon le bloc est retiré.
     */
    public static function apply_conditions(string $text, bool $has_drawings): string {
        $text = preg_replace_callback('/\{IF_(NO_)?DRAWINGS\}(.*?)\{\/IF_(?:NO_)?DRAWINGS\}/s', function ($m) use ($has_drawings) {
            $show = $m[1] === 'NO_' ? !$has_drawings : $has_drawings;
            return $show ? $m[2] : '';
        }, $text);
        return preg_replace("/\n{3,}/", "\n\n", trim($text));
    }

    /** Valeurs des balises, déjà échappées pour le HTML. */
    private static function tag_values(int $deal_id, $project, WP_User $to, array $sender, string $lang = 'fr_FR'): array {
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
            '{PROJECT_LINK}'   => $url !== '' ? '<a href="' . esc_url($url) . '">' . esc_html(self::LINK_LABELS[$lang] ?? self::LINK_LABELS['fr_FR']) . '</a>' : $name,
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

    /** Étapes qui ont (ou ont eu) un e-mail : Brevo_id > 0 ou un template project_mail. */
    private static function admin_slugs(): array {
        global $wpdb;
        return (array) $wpdb->get_results($wpdb->prepare(
            'SELECT s.SlugPhase, s.TitrePhase, s.Brevo_id FROM ' . self::t_slug() . ' s
             WHERE s.Brevo_id > 0 OR s.SlugPhase IN (SELECT message_type FROM ' . self::t_tpl() . ' WHERE message_family = %s)
             ORDER BY s.Ordre ASC',
            self::FAMILY
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

            $fr = self::get_template($slug, 'fr_FR');
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

        // Crée (ou complète) les templates du dossier project_mail à chaque ouverture : sans effet s'ils existent déjà
        self::ensure_defaults();
        if (self::$errors) {
            $notice .= '<div class="notice notice-error"><p><strong>' . esc_html__('Templates could not be created:', 'creation-reservoir') . '</strong><br>'
                . implode('<br>', array_map('esc_html', self::$errors)) . '</p></div>';
        }

        $doc_types = (array) $wpdb->get_results('SELECT slug, label FROM ' . $wpdb->prefix . 'achats_doc_types ORDER BY sort_order ASC');
        $doc_options = ['last_drawing' => __('Latest drawing of each item not yet approved', 'creation-reservoir')];
        foreach ($doc_types as $d) $doc_options[$d->slug] = $d->label . ' (' . $d->slug . ')';

        echo '<div class="wrap"><h1>' . esc_html__('Phase e-mails', 'creation-reservoir') . '</h1>' . $notice;
        echo '<p>' . esc_html__('E-mails sent to the customer when a project step is completed (sent from this site, no Brevo). The texts are edited like the supplier order e-mails, in ISPAG Settings → Email templates (one template per step and language). The language is the one of the recipient; French is used when a translation is missing.', 'creation-reservoir') . '</p>';
        echo '<form method="post">';
        wp_nonce_field(self::NONCE, 'ispag_phase_mail_nonce');

        echo '<table class="form-table"><tr><th><label for="survey_url">' . esc_html__('Satisfaction survey URL', 'creation-reservoir') . '</label></th><td>'
            . '<input type="url" class="large-text" id="survey_url" name="survey_url" value="' . esc_attr((string) get_option(self::OPT_SURVEY, '')) . '">'
            . '<p class="description">' . esc_html__('Used by the {SURVEY_LINK} tag. Empty = link to the project.', 'creation-reservoir') . '</p></td></tr></table>';

        foreach (self::admin_slugs() as $s) {
            $slug = $s->SlugPhase;
            $selected = array_filter(array_map('trim', explode(',', self::get_docs($slug))));
            $langs_ok = [];
            foreach (self::LANGS as $l => $label) {
                $row = $wpdb->get_var($wpdb->prepare('SELECT Id FROM ' . self::t_tpl() . ' WHERE message_family = %s AND message_type = %s AND lang = %s LIMIT 1', self::FAMILY, $slug, $l));
                $langs_ok[] = $l . ($row ? ' ✓' : ' ✗');
            }
            echo '<div style="background:#fff;border:1px solid #ccd0d4;padding:8px 14px;margin:10px 0"><h3 style="margin:.4em 0">'
                . esc_html($s->TitrePhase) . ' <code>' . esc_html($slug) . '</code> <small style="font-weight:400;color:#666">'
                . esc_html__('Templates:', 'creation-reservoir') . ' ' . esc_html(implode(' · ', $langs_ok)) . '</small></h3>';
            echo '<p><label><input type="checkbox" name="pm[' . esc_attr($slug) . '][enabled]" value="1" ' . checked((int) $s->Brevo_id > 0, true, false) . '> '
                . esc_html__('Send this e-mail when the step is completed', 'creation-reservoir') . '</label></p>';
            echo '<details><summary style="cursor:pointer"><strong>' . esc_html__('Attached documents', 'creation-reservoir') . '</strong> ('
                . count($selected) . ($selected ? ': ' . esc_html(implode(', ', $selected)) : '') . ')</summary><p>';
            foreach ($doc_options as $val => $label) {
                echo '<label style="display:inline-block;margin-right:14px"><input type="checkbox" name="pm[' . esc_attr($slug) . '][docs][]" value="' . esc_attr($val) . '" ' . checked(in_array($val, $selected, true), true, false) . '> ' . esc_html($label) . '</label>';
            }
            echo '</p></details></div>';
        }

        echo '<h2>' . esc_html__('Available tags', 'creation-reservoir') . '</h2><ul>';
        foreach (self::tags() as $tag => $desc) {
            echo '<li><code>' . esc_html($tag) . '</code> — ' . esc_html($desc) . '</li>';
        }
        echo '</ul><p><button type="submit" class="button button-primary">' . esc_html__('Save', 'creation-reservoir') . '</button></p></form></div>';
    }
}
