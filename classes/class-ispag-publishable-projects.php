<?php
defined('ABSPATH') || exit;

/**
 * Projets « publiables » : base d'inspiration pour des publications (LinkedIn, blog) rédigées avec l'aide de Claude.
 *
 *  - Une case « Publiable » sur la page projet (visible et modifiable uniquement avec le droit export_publishable_projects) marque un projet ; par défaut aucun projet ne l'est.
 *  - Une API REST en lecture seule (ispag/v1/publishable-projects) ne renvoie QUE les projets marqués, et uniquement des données
 *    non sensibles : types de cuves, dimensions, nature des postes, année, photos. Jamais le nom du projet, le client, les contacts,
 *    les adresses, les prix, les numéros de commande, les notes ni les documents.
 *  - Accès : droit « export_publishable_projects » (à accorder à un compte dédié, dans la fiche utilisateur), par mot de passe
 *    d'application WordPress. Chaque appel est journalisé (100 derniers).
 *
 * L'état « publiable » est stocké dans achats_project_meta (post_id = identifiant du projet, meta_key = ispag_publishable).
 */
class ISPAG_Publishable_Projects {

    const META_KEY = 'ispag_publishable';
    const PHASE_SLUG = 'post_linkedin';        // étape « Post linkedin » du flux du projet : « Fait » = le projet a déjà servi à une publication
    const DONE_CSS   = 'PhaseCmdFait';         // statut de phase « Done » (« N/A » ne compte pas)
    const CAP      = 'export_publishable_projects';
    const LOG_OPT  = 'ispag_pub_api_log';
    const MAX_PHOTOS = 20;

    public static function init() {
        add_action('rest_api_init', [self::class, 'register_routes']);
        add_action('wp_ajax_ispag_toggle_publishable', [self::class, 'ajax_toggle']);
    }

    // ── État « publiable » ───────────────────────────────────────────────────

    public static function is_publishable($deal_id) {
        return self::flag($deal_id, self::META_KEY);
    }

    public static function set_publishable($deal_id, $on) {
        self::set_flag($deal_id, self::META_KEY, $on);
    }

    /**
     * Publié = l'étape « Post linkedin » du flux du projet est à « Fait » (dernier statut enregistré pour ce projet).
     * La routine hebdomadaire ne reprend plus les projets publiés.
     */
    public static function is_published($deal_id) {
        global $wpdb;
        $css = $wpdb->get_var($wpdb->prepare(
            "SELECT m.ClasseCss FROM {$wpdb->prefix}achats_suivi_phase_commande s
               JOIN {$wpdb->prefix}achats_meta_phase_commande m ON m.Id = s.status_id
              WHERE s.hubspot_deal_id = %d AND s.slug_phase = %s ORDER BY s.id DESC LIMIT 1",
            (int) $deal_id, self::PHASE_SLUG
        ));
        return $css === self::DONE_CSS;
    }

    private static function flag($deal_id, $key) {
        global $wpdb;
        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT meta_value FROM {$wpdb->prefix}achats_project_meta WHERE post_id = %d AND meta_key = %s AND meta_value = '1' LIMIT 1",
            (int) $deal_id, $key
        ));
    }

    private static function set_flag($deal_id, $key, $on) {
        global $wpdb;
        $t = $wpdb->prefix . 'achats_project_meta';
        $wpdb->delete($t, ['post_id' => (int) $deal_id, 'meta_key' => $key]);
        if ($on) $wpdb->insert($t, ['post_id' => (int) $deal_id, 'meta_key' => $key, 'meta_value' => '1']);
    }

    /** Case « Publiable » de la page projet (droit export_publishable_projects seulement). Le statut « publié » vient de l'étape « Post linkedin » du flux du projet. */
    public static function render_toggle($deal_id) {
        if (!current_user_can(self::CAP)) return '';
        $nonce = wp_create_nonce('ispag_publishable_' . (int) $deal_id);
        ob_start(); ?>
        <style>
        label.ispag-publishable{position:relative !important;display:flex !important;align-items:center !important;justify-content:space-between !important;gap:12px;cursor:pointer;font-size:14px;color:#444;margin:0 !important;width:100%;}
        label.ispag-publishable input.ispag-sw-input{-webkit-appearance:none !important;appearance:none !important;position:relative !important;flex:0 0 40px;display:inline-block !important;width:40px !important;height:22px !important;min-width:40px;margin:0 !important;padding:0 !important;border:0 !important;border-radius:22px !important;background:#c9ced6 !important;cursor:pointer;transition:background .15s;box-shadow:none !important;}
        label.ispag-publishable input.ispag-sw-input::before{content:'' !important;position:absolute !important;top:3px;left:3px;width:16px;height:16px;border-radius:50%;background:#fff;box-shadow:0 1px 2px rgba(0,0,0,.35);transition:left .15s;display:block !important;margin:0 !important;border:0 !important;transform:none !important;}
        label.ispag-publishable input.ispag-sw-input::after{display:none !important;content:none !important;}
        label.ispag-publishable input.ispag-sw-input:checked{background:#2e7d32 !important;}
        label.ispag-publishable input.ispag-sw-input:checked::before{left:21px;}
        label.ispag-publishable input.ispag-sw-input:focus-visible{outline:2px solid #2b4aa0;outline-offset:2px;}
        </style>
        <label class="ispag-publishable" title="<?php echo esc_attr__('Allows an anonymised summary of this project and its photos to inspire posts (LinkedIn, blog).', 'creation-reservoir'); ?>">
            <span><?php esc_html_e('Publishable', 'creation-reservoir'); ?></span>
            <input type="checkbox" class="ispag-sw-input" <?php checked(self::is_publishable($deal_id)); ?> onchange="(function(c){var f=new FormData();f.append('action','ispag_toggle_publishable');f.append('deal_id','<?php echo (int) $deal_id; ?>');f.append('nonce','<?php echo esc_js($nonce); ?>');f.append('on',c.checked?1:0);fetch('<?php echo esc_js(admin_url('admin-ajax.php')); ?>',{method:'POST',credentials:'same-origin',body:f}).then(function(r){return r.json();}).then(function(r){if(!r.success){c.checked=!c.checked;alert('Error');}}).catch(function(){c.checked=!c.checked;});})(this)>
        </label>
        <?php return ob_get_clean();
    }

    public static function ajax_toggle() {
        $deal_id = (int) ($_POST['deal_id'] ?? 0);
        if (!current_user_can(self::CAP) || $deal_id <= 0 || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'] ?? '')), 'ispag_publishable_' . $deal_id)) {
            wp_send_json_error('Forbidden', 403);
        }
        self::set_publishable($deal_id, !empty($_POST['on']));
        wp_send_json_success(['publishable' => self::is_publishable($deal_id)]);
    }

    // ── API REST (lecture seule) ─────────────────────────────────────────────

    public static function register_routes() {
        $perm = [self::class, 'can_read'];
        register_rest_route('ispag/v1', '/publishable-projects', ['methods' => 'GET', 'callback' => [self::class, 'rest_list'], 'permission_callback' => $perm]);
        register_rest_route('ispag/v1', '/publishable-projects/(?P<id>\d+)', ['methods' => 'GET', 'callback' => [self::class, 'rest_one'], 'permission_callback' => $perm]);
        // Envoi par e-mail des propositions de publication (appelé par la routine hebdomadaire) : destinataire fixé côté site
        register_rest_route('ispag/v1', '/publishable-projects/digest', ['methods' => 'POST', 'callback' => [self::class, 'rest_digest'], 'permission_callback' => $perm]);
        // Photo d'un projet publiable, servie par l'API (le compte n'a ainsi besoin d'aucun autre chemin du site)
        register_rest_route('ispag/v1', '/publishable-projects/(?P<id>\d+)/photos/(?P<media>\d+)', ['methods' => 'GET', 'callback' => [self::class, 'rest_photo'], 'permission_callback' => $perm]);
        // Bibliothèque d'images du simulateur (captures d'écran d'exemple, sans donnée client), pour illustrer les publications
        register_rest_route('ispag/v1', '/publishable-library', ['methods' => 'GET', 'callback' => [self::class, 'rest_library'], 'permission_callback' => $perm]);
        register_rest_route('ispag/v1', '/publishable-library/(?P<name>[a-z0-9-]+\.png)', ['methods' => 'GET', 'callback' => [self::class, 'rest_library_file'], 'permission_callback' => $perm]);
    }

    private static function library_dir() {
        return trailingslashit(WP_PLUGIN_DIR) . 'ispag-tank-builder/assets/docs/library/';
    }

    public static function rest_library() {
        $f = self::library_dir() . 'index.json';
        $items = is_readable($f) ? json_decode(file_get_contents($f), true) : [];
        $out = [];
        foreach ((array) $items as $it) {
            if (empty($it['file']) || !is_readable(self::library_dir() . basename($it['file']))) continue;
            $out[] = [
                'name'    => $it['name'] ?? $it['file'],
                'caption' => $it['caption'] ?? '',
                'type'    => $it['type'] ?? '',
                'url'     => rest_url('ispag/v1/publishable-library/' . basename($it['file'])),
            ];
        }
        return rest_ensure_response($out);
    }

    public static function rest_library_file($req) {
        $file = self::library_dir() . basename((string) $req['name']);
        if (!is_readable($file)) return new WP_Error('not_found', 'Image not found.', ['status' => 404]);
        nocache_headers();
        header('Content-Type: image/png');
        header('Content-Length: ' . filesize($file));
        readfile($file);
        exit;
    }

    public static function can_read() {
        return is_user_logged_in() && current_user_can(self::CAP);
    }

    private static function log($route) {
        $log = (array) get_option(self::LOG_OPT, []);
        $log[] = ['t' => time(), 'u' => get_current_user_id(), 'r' => $route];
        update_option(self::LOG_OPT, array_slice($log, -100), false);
    }

    public static function rest_list() {
        global $wpdb;
        self::log('list');
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->prefix}achats_project_meta WHERE meta_key = %s AND meta_value = '1' ORDER BY post_id DESC LIMIT 200", self::META_KEY
        ));
        $out = [];
        foreach ($ids as $id) $out[] = self::summary((int) $id);
        return rest_ensure_response(array_values(array_filter($out)));
    }

    public static function rest_one($req) {
        self::log('project ' . (int) $req['id']);
        $id = (int) $req['id'];
        if (!self::is_publishable($id)) return new WP_Error('not_publishable', 'Project not marked as publishable.', ['status' => 404]);
        $data = self::summary($id, true);
        return $data ? rest_ensure_response($data) : new WP_Error('not_found', 'Project not found.', ['status' => 404]);
    }

    /**
     * Envoie par e-mail le texte reçu (propositions de publication), avec ses photos en pièces jointes, à l'adresse réglée côté site : le destinataire n'est jamais choisi par l'appelant.
     * Adresse : option « ispag_pub_digest_to », sinon l'adresse d'administration du site (réglages WordPress → Général). Limite : 5 envois par jour.
     */
    public static function rest_digest($req) {
        $to = sanitize_email((string) get_option('ispag_pub_digest_to', get_option('admin_email')));
        if (!is_email($to)) return new WP_Error('no_recipient', 'No valid recipient.', ['status' => 500]);
        $count = (int) get_transient('ispag_pub_digest_count');
        if ($count >= 5) return new WP_Error('rate_limited', 'Too many digests today.', ['status' => 429]);
        $subject = mb_substr(sanitize_text_field((string) $req->get_param('subject')), 0, 150);
        $body    = mb_substr(wp_strip_all_tags((string) $req->get_param('body')), 0, 20000);
        if ($subject === '' || $body === '') return new WP_Error('empty', 'Subject and body are required.', ['status' => 400]);
        // Photos jointes (envoi multipart, champ « photos[] ») : 6 images au plus, 2 Mo chacune, vérifiées comme de vraies images JPEG / PNG
        $attachments = [];
        $files = $req->get_file_params();
        $list  = $files['photos'] ?? null;
        if ($list && isset($list['tmp_name'])) {
            $tmp  = (array) $list['tmp_name'];
            $name = (array) ($list['name'] ?? []);
            foreach ($tmp as $i => $path) {
                if (count($attachments) >= 6 || !is_string($path) || !is_readable($path)) continue;
                if (filesize($path) > 2 * 1024 * 1024) continue;
                $info = @getimagesize($path);
                if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG], true)) continue;
                $ext = $info[2] === IMAGETYPE_PNG ? 'png' : 'jpg';
                $dest = trailingslashit(get_temp_dir()) . 'photo-' . (count($attachments) + 1) . '-' . wp_generate_password(6, false) . '.' . $ext;
                if (@copy($path, $dest)) $attachments[] = $dest;
            }
        }
        self::log('digest (' . count($attachments) . ' photos)');
        set_transient('ispag_pub_digest_count', $count + 1, DAY_IN_SECONDS);
        $ok = wp_mail($to, $subject, $body, ['Content-Type: text/plain; charset=UTF-8'], $attachments);
        foreach ($attachments as $f) @unlink($f);
        return rest_ensure_response(['sent' => (bool) $ok, 'photos' => count($attachments)]);
    }

    /** Envoie l'image (taille « large », sans métadonnées EXIF) si elle appartient bien aux photos d'un projet publiable. */
    public static function rest_photo($req) {
        $id = (int) $req['id']; $media = (int) $req['media'];
        self::log('photo ' . $id . '/' . $media);
        if (!self::is_publishable($id) || !in_array($media, self::photo_ids($id), true)) {
            return new WP_Error('not_found', 'Photo not found.', ['status' => 404]);
        }
        $file = null;
        $meta = wp_get_attachment_metadata($media);
        $full = get_attached_file($media);
        if ($full && !empty($meta['sizes']['large']['file'])) {
            $cand = trailingslashit(dirname($full)) . $meta['sizes']['large']['file'];
            if (is_readable($cand)) $file = $cand;
        }
        $tmp = null;
        if (!$file && $full && is_readable($full)) {
            // Pas de taille « large » : on en génère une (le ré-encodage retire les métadonnées EXIF, dont la position GPS)
            $ed = wp_get_image_editor($full);
            if (is_wp_error($ed)) return new WP_Error('not_found', 'Photo not found.', ['status' => 404]);
            $ed->resize(1024, 1024, false);
            $saved = $ed->save(trailingslashit(get_temp_dir()) . 'ispag-pub-' . wp_generate_password(12, false) . '.jpg', 'image/jpeg');
            if (is_wp_error($saved) || empty($saved['path'])) return new WP_Error('not_found', 'Photo not found.', ['status' => 404]);
            $file = $tmp = $saved['path'];
        }
        if (!$file) return new WP_Error('not_found', 'Photo not found.', ['status' => 404]);
        $type = wp_check_filetype($file)['type'] ?: 'image/jpeg';
        nocache_headers();
        header('Content-Type: ' . $type);
        header('Content-Length: ' . filesize($file));
        readfile($file);
        if ($tmp) @unlink($tmp);
        exit;
    }

    /**
     * Données non sensibles d'un projet : jamais de nom de projet, de client, de contact, d'adresse, de prix ni de document.
     * @return array|null
     */
    public static function summary($deal_id, $with_photos_urls = false) {
        global $wpdb;
        $o = $wpdb->prefix . 'achats_liste_commande';
        $d = $wpdb->prefix . 'achats_details_commande';
        $tp = $wpdb->prefix . 'achats_type_prestations';
        $created = $wpdb->get_var($wpdb->prepare("SELECT date_creation FROM {$o} WHERE hubspot_deal_id = %d LIMIT 1", $deal_id));
        if (!$created) return null;

        $lines = $wpdb->get_results($wpdb->prepare(
            "SELECT d.Id, d.Type, d.Qty, t.type AS type_label FROM {$d} d LEFT JOIN {$tp} t ON t.Id = d.Type
              WHERE d.hubspot_deal_id = %d AND (d.archive IS NULL OR d.archive = 0) AND (d.IdArticleMaster IS NULL OR d.IdArticleMaster = 0) ORDER BY d.Id", $deal_id
        ));
        $tanks = []; $other = [];
        foreach ($lines as $l) {
            if ((int) $l->Type === 1) {
                $t = ['title' => (string) apply_filters('ispag_get_tank_title', '', (int) $l->Id), 'quantity' => (float) $l->Qty];
                $datas = apply_filters('ispag_get_tank_datas', null, (int) $l->Id);
                $dim = is_array($datas) ? ($datas['dimensions'] ?? null) : null;
                if ($dim) {
                    $t['volume_l'] = (float) ($dim->Volume ?? 0); $t['diameter_mm'] = (float) ($dim->Diameter ?? 0); $t['height_mm'] = (float) ($dim->Height ?? 0);
                    $t['design_pressure_bar'] = (float) ($dim->MaxPressure ?? 0);
                }
                $tanks[] = $t;
            } elseif (!empty($l->type_label)) {
                $other[$l->type_label] = ($other[$l->type_label] ?? 0) + 1;
            }
        }
        $out = [
            'id'          => (int) $deal_id,
            'year'        => (int) substr((string) $created, 0, 4),
            'tanks'       => $tanks,
            'other_items' => $other,
            'photo_count' => count(self::photo_ids($deal_id)),
            'published'   => self::is_published($deal_id),   // étape « Post linkedin » à « Fait » : déjà utilisé pour une publication, à ne pas reprendre
        ];
        if ($with_photos_urls) {
            $out['photos'] = [];
            foreach (self::photo_ids($deal_id) as $aid) {
                // Taille « large » : les versions redimensionnées de WordPress ne conservent pas les métadonnées EXIF (position GPS…)
                if (wp_get_attachment_image_url($aid, 'large')) $out['photos'][] = ['url' => rest_url('ispag/v1/publishable-projects/' . (int) $deal_id . '/photos/' . $aid)];
            }
        }
        return $out;
    }

    /** Identifiants de médias des photos du projet (documents de type « picture »), images seulement, sans légende ni nom de fichier. */
    private static function photo_ids($deal_id) {
        global $wpdb;
        $rows = $wpdb->get_col($wpdb->prepare(
            "SELECT IdMedia FROM {$wpdb->prefix}achats_historique WHERE hubspot_deal_id = %d AND ClassCss = 'picture' AND IdMedia > 0 ORDER BY Id DESC LIMIT %d",
            $deal_id, self::MAX_PHOTOS
        ));
        $ids = [];
        foreach ($rows as $id) if (wp_attachment_is_image((int) $id)) $ids[] = (int) $id;
        return $ids;
    }
}
