<?php
defined('ABSPATH') || exit;

/**
 * Projets « publiables » : base d'inspiration pour des publications (LinkedIn, blog) rédigées avec l'aide de Claude.
 *
 *  - Une case « Publiable » sur la page projet (droit manage_order) marque un projet ; par défaut aucun projet ne l'est.
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
    const CAP      = 'export_publishable_projects';
    const LOG_OPT  = 'ispag_pub_api_log';
    const MAX_PHOTOS = 20;

    public static function init() {
        add_action('rest_api_init', [self::class, 'register_routes']);
        add_action('wp_ajax_ispag_toggle_publishable', [self::class, 'ajax_toggle']);
    }

    // ── État « publiable » ───────────────────────────────────────────────────

    public static function is_publishable($deal_id) {
        global $wpdb;
        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT meta_value FROM {$wpdb->prefix}achats_project_meta WHERE post_id = %d AND meta_key = %s AND meta_value = '1' LIMIT 1",
            (int) $deal_id, self::META_KEY
        ));
    }

    public static function set_publishable($deal_id, $on) {
        global $wpdb;
        $t = $wpdb->prefix . 'achats_project_meta';
        $wpdb->delete($t, ['post_id' => (int) $deal_id, 'meta_key' => self::META_KEY]);
        if ($on) $wpdb->insert($t, ['post_id' => (int) $deal_id, 'meta_key' => self::META_KEY, 'meta_value' => '1']);
    }

    /** Case « Publiable » de la page projet (manage_order seulement). */
    public static function render_toggle($deal_id) {
        if (!current_user_can('manage_order')) return '';
        $nonce = wp_create_nonce('ispag_publishable_' . (int) $deal_id);
        ob_start(); ?>
        <label class="ispag-publishable" style="display:inline-flex;align-items:center;gap:6px;margin-left:10px;cursor:pointer;" title="<?php echo esc_attr__('Allows an anonymised summary of this project and its photos to inspire posts (LinkedIn, blog).', 'creation-reservoir'); ?>">
            <input type="checkbox" <?php checked(self::is_publishable($deal_id)); ?> onchange="(function(c){var f=new FormData();f.append('action','ispag_toggle_publishable');f.append('deal_id','<?php echo (int) $deal_id; ?>');f.append('nonce','<?php echo esc_js($nonce); ?>');f.append('on',c.checked?1:0);fetch('<?php echo esc_js(admin_url('admin-ajax.php')); ?>',{method:'POST',credentials:'same-origin',body:f}).then(function(r){return r.json();}).then(function(r){if(!r.success){c.checked=!c.checked;alert('Error');}}).catch(function(){c.checked=!c.checked;});})(this)">
            <?php esc_html_e('Publishable', 'creation-reservoir'); ?>
        </label>
        <?php return ob_get_clean();
    }

    public static function ajax_toggle() {
        $deal_id = (int) ($_POST['deal_id'] ?? 0);
        if (!current_user_can('manage_order') || $deal_id <= 0 || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'] ?? '')), 'ispag_publishable_' . $deal_id)) {
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
        ];
        if ($with_photos_urls) {
            $out['photos'] = [];
            foreach (self::photo_ids($deal_id) as $aid) {
                // Taille « large » : les versions redimensionnées de WordPress ne conservent pas les métadonnées EXIF (position GPS…)
                $url = wp_get_attachment_image_url($aid, 'large');
                if ($url) $out['photos'][] = ['url' => $url];
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
