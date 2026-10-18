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
        self::ensure_bigint_post_id();
        $wpdb->delete($t, ['post_id' => (int) $deal_id, 'meta_key' => $key]);
        if ($on) {
            $ok = $wpdb->insert($t, ['post_id' => (int) $deal_id, 'meta_key' => $key, 'meta_value' => '1']);
            if ($ok === false || !self::flag($deal_id, $key)) {
                // Table ancienne sans AUTO_INCREMENT sur meta_id : on fournit l'identifiant nous-mêmes.
                $next = (int) $wpdb->get_var("SELECT COALESCE(MAX(meta_id),0)+1 FROM `{$t}`");
                $wpdb->insert($t, ['meta_id' => $next, 'post_id' => (int) $deal_id, 'meta_key' => $key, 'meta_value' => '1']);
            }
        }
    }

    /** Les identifiants de projet récents sont très grands (> 2,1 milliards) : une colonne post_id en INT les tronque ou refuse l'écriture. */
    private static function ensure_bigint_post_id() {
        global $wpdb;
        $t = $wpdb->prefix . 'achats_project_meta';
        $type = $wpdb->get_var($wpdb->prepare("SELECT DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'post_id'", $t));
        if ($type && strtolower($type) !== 'bigint') {
            $wpdb->query("ALTER TABLE `{$t}` MODIFY `post_id` BIGINT UNSIGNED NOT NULL DEFAULT 0");
        }
    }

    /** Case « Publiable » de la page projet (droit export_publishable_projects seulement). Le statut « publié » vient de l'étape « Post linkedin » du flux du projet. */
    /** Une offre (devis) n'est pas publiable : seuls les projets le sont. */
    public static function is_offer($deal_id) {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare("SELECT isQotation FROM {$wpdb->prefix}achats_liste_commande WHERE hubspot_deal_id = %d LIMIT 1", (int) $deal_id)) === 1;
    }

    public static function render_toggle($deal_id) {
        if (!current_user_can(self::CAP) || self::is_offer($deal_id)) return '';
        $nonce = wp_create_nonce('ispag_publishable_' . (int) $deal_id);
        $js = plugin_dir_path(__FILE__) . '../assets/js/ispag-publishable.js';
        wp_enqueue_script('ispag-publishable', plugin_dir_url(__FILE__) . '../assets/js/ispag-publishable.js', [], @filemtime($js) ?: false, true);
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
            <input type="checkbox" class="ispag-sw-input ispag-publishable-input" data-deal="<?php echo (int) $deal_id; ?>" data-nonce="<?php echo esc_attr($nonce); ?>" data-url="<?php echo esc_url(admin_url('admin-ajax.php')); ?>" <?php checked(self::is_publishable($deal_id)); ?>>
        </label>
        <?php return ob_get_clean();
    }

    public static function ajax_toggle() {
        $deal_id = (int) ($_POST['deal_id'] ?? 0);
        if (!current_user_can(self::CAP) || $deal_id <= 0 || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'] ?? '')), 'ispag_publishable_' . $deal_id)) {
            wp_send_json_error('Forbidden', 403);
        }
        if (self::is_offer($deal_id)) wp_send_json_error('An offer cannot be published', 400);
        $on = !empty($_POST['on']);
        self::set_publishable($deal_id, $on);
        // On relit la base : si l'écriture n'a pas été enregistrée, la case revient à son état réel au lieu de faire croire que c'est fait.
        $saved = self::is_publishable($deal_id);
        if ($saved !== $on) {
            global $wpdb;
            wp_send_json_error('Not saved: ' . ($wpdb->last_error ?: 'unknown database error'), 500);
        }
        wp_send_json_success(['publishable' => $saved]);
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
        // Croquis d'une cuve du projet (vue de face générée par le configurateur, sans cotes ni nom : aucun client) : à défaut de photo
        register_rest_route('ispag/v1', '/publishable-projects/(?P<id>\d+)/sketches/(?P<article>\d+)', ['methods' => 'GET', 'callback' => [self::class, 'rest_sketch'], 'permission_callback' => $perm]);
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
        foreach ($ids as $id) { if (!self::is_offer((int) $id)) $out[] = self::summary((int) $id); }   // une offre marquée par erreur n'est jamais proposée
        return rest_ensure_response(array_values(array_filter($out)));
    }

    public static function rest_one($req) {
        self::log('project ' . (int) $req['id']);
        $id = (int) $req['id'];
        if (!self::is_publishable($id) || self::is_offer($id)) return new WP_Error('not_publishable', 'Project not marked as publishable.', ['status' => 404]);
        $data = self::summary($id, true);
        return $data ? rest_ensure_response($data) : new WP_Error('not_found', 'Project not found.', ['status' => 404]);
    }

    /**
     * Envoie par e-mail le texte reçu (propositions de publication), avec ses photos en pièces jointes, à l'adresse réglée côté site : le destinataire n'est jamais choisi par l'appelant.
     * Adresse : option « ispag_pub_digest_to », sinon l'adresse d'administration du site (réglages WordPress → Général). Limite : 5 envois par jour.
     * Champ « html » facultatif : version mise en page du même courriel (liens et boutons) ; sans lui, le texte brut est envoyé.
     */
    public static function rest_digest($req) {
        $to = sanitize_email((string) get_option('ispag_pub_digest_to', get_option('admin_email')));
        $count = (int) get_transient('ispag_pub_digest_count');
        if ($count >= 5) return new WP_Error('rate_limited', 'Too many digests today.', ['status' => 429]);
        $subject = mb_substr(sanitize_text_field((string) $req->get_param('subject')), 0, 150);
        $body    = mb_substr(wp_strip_all_tags((string) $req->get_param('body')), 0, 20000);
        if ($subject === '' || $body === '') return new WP_Error('empty', 'Subject and body are required.', ['status' => 400]);
        // Version mise en page (facultative) : boutons « Ouvrir le projet » ; nettoyée comme un article (pas de script ni d'attribut dangereux)
        $html = (string) $req->get_param('html');
        $html = $html !== '' ? wp_kses_post(mb_substr($html, 0, 120000)) : '';
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
        // Par le système de notification du CRM (cloche / push) : le mail direct ne part pas de façon fiable. Les photos sont alors copiées
        // dans le dossier d'envois et montrées dans le rapport (Telegram, cloche, push) ; le mail avec pièces jointes ne sert plus que si le CRM est absent.
        $ok = false;
        $users = class_exists('ISPAG_Report_Notifier') ? ISPAG_Report_Notifier::recipients($to) : [];
        if ($users) {
            $gallery = '';
            $up = wp_upload_dir();
            $dir = trailingslashit($up['basedir']) . 'ispag-reports';
            if ($attachments && wp_mkdir_p($dir)) {
                @file_put_contents($dir . '/index.html', '');
                foreach ($attachments as $f) {
                    $dest = $dir . '/' . wp_generate_password(16, false) . '-' . basename($f);
                    if (@copy($f, $dest)) $gallery .= '<img src="' . esc_url(trailingslashit($up['baseurl']) . 'ispag-reports/' . basename($dest)) . '" style="max-width:100%;height:auto;margin:6px 0;border-radius:6px" alt="">';
                }
            }
            $page = ($html !== '' ? $html : nl2br(esc_html($body))) . ($gallery !== '' ? '<hr>' . $gallery : '');
            $ok = ISPAG_Report_Notifier::notify($users, 'report_publishable', $subject, $body, $page);
        }
        if (!$ok) {
            $ok = $html !== ''
                ? wp_mail($to, $subject, $html, ['Content-Type: text/html; charset=UTF-8'], $attachments)
                : wp_mail($to, $subject, $body, ['Content-Type: text/plain; charset=UTF-8'], $attachments);
        }
        foreach ($attachments as $f) @unlink($f);
        return rest_ensure_response(['sent' => (bool) $ok, 'photos' => count($attachments), 'html' => $html !== '']);
    }


    /** Identifiants des cuves (lignes de type réservoir) du projet, les premières d'abord. */
    private static function tank_article_ids($deal_id, $limit = 3) {
        global $wpdb;
        return array_map('intval', (array) $wpdb->get_col($wpdb->prepare(
            "SELECT Id FROM {$wpdb->prefix}achats_details_commande WHERE hubspot_deal_id = %d AND Type = 1 AND (archive IS NULL OR archive = 0) AND (IdArticleMaster IS NULL OR IdArticleMaster = 0) ORDER BY Id LIMIT %d",
            $deal_id, (int) $limit
        )));
    }

    /**
     * Croquis d'une cuve en image : le SVG de face du configurateur (sans cotes), converti en PNG sur fond blanc (Imagick, sinon rsvg-convert).
     * Sans convertisseur, le SVG lui-même est servi (image/svg+xml).
     * @return array|null [contenu, type MIME]
     */
    private static function sketch_image($article_id) {
        $url = apply_filters('ispag_get_tank_svg', null, (int) $article_id, false);
        if (!$url) return null;
        $up = wp_upload_dir();
        $path = str_replace($up['baseurl'], $up['basedir'], (string) $url);
        $path = realpath($path);
        if (!$path || !is_readable($path) || strpos($path, realpath($up['basedir'])) !== 0) return null;
        $svg = (string) file_get_contents($path);
        if ($svg === '') return null;
        $svg = preg_replace('/<\?xml[^>]*\?>/', '', $svg);
        $svg = preg_replace('/url\(#[A-Za-z0-9_-]+\)/', '#f3f4f6', $svg);   // dégradés : aplat clair (la conversion les rend mal)
        $tmp_svg = trailingslashit(get_temp_dir()) . 'ispag-sketch-' . wp_generate_password(10, false) . '.svg';
        $tmp_png = preg_replace('/\.svg$/', '.png', $tmp_svg);
        file_put_contents($tmp_svg, $svg);
        $png = null;
        if (extension_loaded('imagick')) {
            try {
                $im = new Imagick();
                $im->setBackgroundColor(new ImagickPixel('white'));
                $im->setResolution(150, 150);
                $im->readImage($tmp_svg);
                $im->setImageBackgroundColor('white');
                $im->setImageAlphaChannel(Imagick::ALPHACHANNEL_REMOVE);
                $im->mergeImageLayers(Imagick::LAYERMETHOD_FLATTEN);
                $im->setImageFormat('png');
                $png = $im->getImageBlob();
                $im->clear(); $im->destroy();
            } catch (Exception $e) { $png = null; }
        }
        if (!$png && function_exists('exec')) {
            @exec('rsvg-convert -w 1600 -b white -f png -o ' . escapeshellarg($tmp_png) . ' ' . escapeshellarg($tmp_svg), $out, $code);
            if (isset($code) && $code === 0 && is_readable($tmp_png)) $png = file_get_contents($tmp_png);
        }
        @unlink($tmp_svg); @unlink($tmp_png);
        return $png ? [$png, 'image/png'] : [$svg, 'image/svg+xml'];
    }

    public static function rest_sketch($req) {
        global $wpdb;
        $id = (int) $req['id']; $article = (int) $req['article'];
        self::log('sketch ' . $id . '/' . $article);
        if (!self::is_publishable($id) || !in_array($article, self::tank_article_ids($id, 20), true)) {
            return new WP_Error('not_found', 'Sketch not found.', ['status' => 404]);
        }
        $img = self::sketch_image($article);
        if (!$img) return new WP_Error('not_found', 'Sketch not available.', ['status' => 404]);
        nocache_headers();
        header('Content-Type: ' . $img[1]);
        header('Content-Length: ' . strlen($img[0]));
        echo $img[0];
        exit;
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
            'project_url' => trailingslashit(get_site_url()) . 'projectdetail/' . (int) $deal_id . '/',   // fiche du projet dans la plateforme (pour passer l'étape « Post linkedin » à « Fait »)
            'year'        => (int) substr((string) $created, 0, 4),
            'tanks'       => $tanks,
            'other_items' => $other,
            'photo_count' => count(self::photo_ids($deal_id)),
            'published'   => self::is_published($deal_id),   // étape « Post linkedin » à « Fait » : déjà utilisé pour une publication, à ne pas reprendre
        ];
        if ($with_photos_urls) {
            // Croquis des cuves (à défaut de photo) : images générées, sans cotes ni nom de projet ou de client
            $out['sketches'] = [];
            foreach (self::tank_article_ids($deal_id) as $aid) {
                $out['sketches'][] = ['url' => rest_url('ispag/v1/publishable-projects/' . (int) $deal_id . '/sketches/' . $aid)];
            }
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
