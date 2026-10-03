<?php
defined('ABSPATH') || exit;

/**
 * Logo du site pour les PDF.
 *
 * Utilise le logo défini dans l'apparence du site (Personnaliser → Identité du site). FPDF ne lit que PNG, JPEG et GIF :
 * un logo WebP ou SVG est converti une fois en PNG, mis en cache dans uploads/ispag-pdf-logo/ et reconverti
 * si le logo change. Sans logo de site, ou si la conversion échoue, les PDF gardent leur logo d'origine.
 */
class ISPAG_Site_Logo {

    /** @var string|null Chemin déjà résolu pendant cette requête ('' = aucun). */
    protected static $path = null;

    /** Chemin local d'un logo lisible par FPDF, ou '' si le site n'en a pas. */
    public static function path() {
        if (self::$path !== null) return self::$path;

        $path = '';
        $id = (int) get_theme_mod('custom_logo');
        $file = $id ? get_attached_file($id) : '';
        if ($file && file_exists($file)) {
            $path = self::usable($file, $id);
        }

        /** Permet d'imposer un autre logo (chemin local PNG/JPEG/GIF). */
        self::$path = (string) apply_filters('ispag_pdf_logo_path', $path);
        return self::$path;
    }

    /** Fichier PNG/JPEG/GIF : tel quel ; sinon version PNG en cache. */
    protected static function usable($file, $id) {
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if (in_array($ext, ['png', 'jpg', 'jpeg', 'gif'], true)) return $file;

        $upload = wp_upload_dir();
        $dir = trailingslashit($upload['basedir']) . 'ispag-pdf-logo';
        $png = $dir . '/logo-' . $id . '-' . filemtime($file) . '.png';
        if (file_exists($png)) return $png;

        wp_mkdir_p($dir);
        foreach (glob($dir . '/logo-*.png') ?: [] as $old) @unlink($old); // anciennes versions

        return self::convert($file, $ext, $png) ? $png : '';
    }

    protected static function convert($file, $ext, $png) {
        try {
            if (class_exists('Imagick')) {
                $im = new Imagick();
                $im->setBackgroundColor(new ImagickPixel('transparent'));
                if ($ext === 'svg') $im->setResolution(300, 300);
                $im->readImage($file);
                $im->setImageFormat('png');
                $im->writeImage($png);
                $im->clear();
                $im->destroy();
                return file_exists($png);
            }
            if ($ext === 'webp' && function_exists('imagecreatefromwebp')) {
                $img = imagecreatefromwebp($file);
                if ($img) {
                    imagealphablending($img, false);
                    imagesavealpha($img, true);
                    imagepng($img, $png);
                    imagedestroy($img);
                    return file_exists($png);
                }
            }
        } catch (Exception $e) {
            // conversion impossible : on retombe sur le logo d'origine du PDF
        }
        return false;
    }

    /**
     * Dimensions (mm) d'un logo qui tient dans $max_w × $max_h en conservant ses proportions.
     * Sans lecture possible de l'image : largeur max, hauteur libre.
     */
    public static function fit($path, $max_w, $max_h = 0) {
        $size = $path ? @getimagesize($path) : false;
        if (!$size || $size[0] <= 0 || $size[1] <= 0) return [$max_w, 0];

        $ratio = $size[0] / $size[1];
        $w = $max_w;
        $h = $w / $ratio;
        if ($max_h > 0 && $h > $max_h) {
            $h = $max_h;
            $w = $h * $ratio;
        }
        return [$w, $h];
    }
}
