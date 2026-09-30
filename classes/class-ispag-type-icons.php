<?php
defined('ABSPATH') || exit;

/**
 * Icônes fournies avec le plugin pour les types d'articles (assets/img/article-types/*.svg).
 * Utilisées quand un type n'a pas d'image choisie dans la médiathèque (Reference tables → Article types → Image).
 * Correspondance par Id de type (base de production) ; à défaut, par le code « prestation » (Product / Isol / Welding / div).
 */
class ISPAG_Type_Icons {

    /** Id du type => nom du fichier (sans .svg). */
    const BY_TYPE_ID = [
        1   => 'special-tanks',
        2   => 'insulation',
        3   => 'onsite-welding',
        4   => 'heating-elements',
        5   => 'plate-exchanger',
        6   => 'water-heater-ret',
        7   => 'water-heater-thermostar',
        8   => 'consumable',
        9   => 'water-heater-rhls',
        10  => 'water-heater-multi',
        12  => 'energy-accumulator-pf',
        13  => 'div',
        200 => 'insulation-added-value',
        500 => 'plate-exchanger-accessories',
    ];

    /** Code « prestation » => icône générique. */
    const BY_SERVICE = [
        'Product' => 'special-tanks',
        'Isol'    => 'insulation',
        'Welding' => 'onsite-welding',
        'div'     => 'div',
    ];

    /** Nom de fichier de l'icône d'un type (objet ou tableau avec Id et prestation), ou '' si aucune. */
    public static function name($type) {
        $type = (array) $type;
        $id   = (int) ($type['Id'] ?? 0);
        if (isset(self::BY_TYPE_ID[$id])) return self::BY_TYPE_ID[$id];
        $svc = (string) ($type['prestation'] ?? '');
        return self::BY_SERVICE[$svc] ?? '';
    }

    /** URL de l'icône fournie, ou '' si aucune. */
    public static function url($type) {
        $name = self::name($type);
        return $name === '' ? '' : plugins_url('assets/img/article-types/' . $name . '.svg', __DIR__);
    }

    /** URL de l'image d'un type : image de la médiathèque si elle existe, sinon l'icône fournie. */
    public static function image_url($type, $size = 'thumbnail') {
        $type = (array) $type;
        $img  = !empty($type['image']) ? wp_get_attachment_image_url((int) $type['image'], $size) : '';
        return $img ?: self::url($type);
    }
}
