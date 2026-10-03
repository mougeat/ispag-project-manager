<?php
defined('ABSPATH') || exit;

/**
 * Notes de calcul de prix (uploads/ispag_pricing/article_<id>_<purchase|project>.txt).
 *
 * Elles contiennent prix d'achat, rabais et coefficients : elles ne doivent jamais être accessibles par leur adresse
 * directe. Le dossier est fermé (.htaccess + index.php) et les fichiers sont servis par PHP après contrôle des droits
 * (capacité manage_order), via ISPAG_Pricing_Files::url().
 *
 * Serveur nginx : .htaccess est ignoré, ajouter  location ^~ /wp-content/uploads/ispag_pricing/ { deny all; }
 */
class ISPAG_Pricing_Files {

    const ACTION = 'ispag_pricing_note';

    public static function init() {
        add_action('admin_post_' . self::ACTION, [self::class, 'serve']);
        add_action('admin_init', [self::class, 'protect_dir']);
    }

    public static function dir(): string {
        $u = wp_upload_dir();
        return trailingslashit($u['basedir']) . 'ispag_pricing/';
    }

    public static function path(int $article_id, string $kind): string {
        $kind = $kind === 'project' ? 'project' : 'purchase';
        return self::dir() . 'article_' . $article_id . '_' . $kind . '.txt';
    }

    public static function exists(int $article_id, string $kind): bool {
        return file_exists(self::path($article_id, $kind));
    }

    /** Adresse protégée (nonce inclus) pour consulter une note. */
    public static function url(int $article_id, string $kind): string {
        return wp_nonce_url(add_query_arg([
            'action' => self::ACTION,
            'id'     => $article_id,
            'kind'   => $kind === 'project' ? 'project' : 'purchase',
        ], admin_url('admin-post.php')), self::ACTION . '_' . $article_id);
    }

    /** Ferme le dossier à l'accès direct (idempotent). */
    public static function protect_dir() {
        $u = wp_upload_dir();
        foreach ([self::dir(), trailingslashit($u['basedir']) . 'ispag_debug_pricing/'] as $dir) {
            self::protect_one($dir);
        }
    }

    private static function protect_one(string $dir) {
        if (!is_dir($dir)) {
            return;
        }
        if (!file_exists($dir . '.htaccess')) {
            @file_put_contents($dir . '.htaccess', "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
        }
        if (!file_exists($dir . 'index.php')) {
            @file_put_contents($dir . 'index.php', "<?php // Silence is golden.\n");
        }
    }

    public static function serve() {
        $id = isset($_GET['id']) ? absint($_GET['id']) : 0;
        if (!is_user_logged_in() || !current_user_can('manage_order') || !wp_verify_nonce($_GET['_wpnonce'] ?? '', self::ACTION . '_' . $id)) {
            wp_die(esc_html__('Access denied', 'creation-reservoir'), '', ['response' => 403]);
        }
        $kind = (($_GET['kind'] ?? '') === 'project') ? 'project' : 'purchase';
        $file = self::path($id, $kind);
        if (!$id || !is_readable($file)) {
            wp_die(esc_html__('Not found', 'creation-reservoir'), '', ['response' => 404]);
        }
        nocache_headers();
        header('Content-Type: text/plain; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        readfile($file);
        exit;
    }
}
