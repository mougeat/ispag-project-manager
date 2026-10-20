<?php
defined('ABSPATH') || exit;

/**
 * Regroupe les pages d'administration ISPAG par fonction : une seule entrée de menu par groupe, et une barre d'onglets en tête de
 * chaque page du groupe pour passer de l'une à l'autre.
 *
 * Les pages gardent leur adresse (?page=…) et leur code : seules leurs entrées de menu sont masquées (elles restent enregistrées, donc
 * accessibles), la première page présente du groupe porte le nom du groupe, et les onglets sont construits à partir des titres de menu.
 * Un plugin absent ou une page non accessible à l'utilisateur disparaît simplement du groupe. Filtre « ispag_admin_groups » pour ajuster.
 */
class ISPAG_Admin_Groups {

    const HIDDEN = 'ispag-grp-hidden';

    /** @var array|null slug => [group key, parent, cap, title] des pages existantes, calculé à la fin de la construction du menu */
    private static $pages = null;

    public static function init() {
        add_action('admin_menu', [self::class, 'organize'], 9999);
        add_filter('submenu_file', [self::class, 'submenu_file'], 20);
        add_action('in_admin_header', [self::class, 'tabs']);
        add_action('admin_head', [self::class, 'css']);
    }

    /** Groupes dans l'ordre d'affichage : clé => [titre, [slugs de page dans l'ordre des onglets]]. */
    public static function groups(): array {
        $groups = [
            'general' => [__('General', 'creation-reservoir'), ['ispag-settings', 'ispag-rights', 'ispag-reference-tables']],
            'projects' => [__('Projects', 'creation-reservoir'), ['ispag-plan-reminders', 'ispag-phase-mails']],
            'purchases' => [__('Purchases & invoicing', 'creation-reservoir'), ['ispag-achats-settings', 'ispag-prepayments', 'ispag-invoice-settings', 'ispag-mail-templates', 'ispag-stock-locations']],
            'tanks' => [__('Tanks & simulator', 'creation-reservoir'), ['ispag-tank-data', 'ispag-tank-rules', 'ispag-tank-simulator-settings', 'ispag-sim-licences', 'ispag-logbook-dashboard']],
            'crm' => [__('CRM settings', 'creation-reservoir'), ['ispag-crm-follow-up', 'ispag-week-plan', 'ispag-whatsapp-settings', 'ispag-agenda-sync', 'ispag-calendar-sync', 'ispag-carddav']],
            'tools' => [__('Imports & tools', 'creation-reservoir'), ['ispag_import_companies', 'ispag_import_projects', 'ispag_article_import', 'ispag_purchase_price_import', 'ispag_sales_price_import', 'ispag-simap-sync', 'ispag-uid-validation', 'ispag-regen-display-names', 'ispag-valves-stats']],
        ];
        return (array) apply_filters('ispag_admin_groups', $groups);
    }

    /** Une page appartient-elle à un groupe ? slug => clé de groupe. */
    private static function slug_map(): array {
        $map = [];
        foreach (self::groups() as $key => $g) foreach ($g[1] as $slug) $map[$slug] = $key;
        return $map;
    }

    /** Entrées de menu existantes, indexées par slug : [parent, index dans $submenu, cap, titre]. */
    private static function registered(): array {
        global $submenu;
        $out = [];
        foreach ((array) $submenu as $parent => $items) {
            foreach ((array) $items as $i => $it) {
                if (!isset($it[2])) continue;
                $out[(string) $it[2]] = ['parent' => (string) $parent, 'index' => $i, 'cap' => (string) $it[1], 'title' => wp_strip_all_tags((string) $it[0])];
            }
        }
        return $out;
    }

    public static function organize() {
        global $submenu;
        $reg = self::registered();
        self::$pages = [];
        $weights = [];   // [parent][index] => poids de tri
        $gi = 0;
        foreach (self::groups() as $key => $g) {
            $gi++;
            $present = [];
            foreach ($g[1] as $slug) {
                if (isset($reg[$slug])) $present[] = $slug;
            }
            if (!$present) continue;
            foreach ($present as $slug) self::$pages[$slug] = ['group' => $key, 'parent' => $reg[$slug]['parent'], 'cap' => $reg[$slug]['cap'], 'title' => $reg[$slug]['title']];
            // Le chef de file du groupe : première page présente qu'on peut ouvrir ; les autres sont masquées du menu
            $lead = null;
            foreach ($present as $slug) { if (current_user_can($reg[$slug]['cap'])) { $lead = $slug; break; } }
            foreach ($present as $slug) {
                $p = $reg[$slug]['parent']; $i = $reg[$slug]['index'];
                if ($slug === $lead) {
                    if (count($present) > 1) $submenu[$p][$i][0] = $g[0];
                    $weights[$p][$i] = $gi * 10;
                } else {
                    $submenu[$p][$i][4] = trim((string) ($submenu[$p][$i][4] ?? '') . ' ' . self::HIDDEN);
                    $weights[$p][$i] = $gi * 10 + 1;
                }
            }
        }
        // Ordre du menu : page d'accueil du parent d'abord, puis les pages hors groupe, puis les groupes dans l'ordre
        foreach ($weights as $parent => $w) {
            $items = $submenu[$parent];
            $first = array_key_first($items);
            uksort($items, function ($a, $b) use ($w, $first, $items) {
                $wa = $a === $first ? -1 : ($w[$a] ?? 5); $wb = $b === $first ? -1 : ($w[$b] ?? 5);
                return $wa === $wb ? $a <=> $b : $wa <=> $wb;
            });
            $submenu[$parent] = array_values($items);
        }
    }

    /** Dans un groupe, le menu garde allumée l'entrée du groupe, quelle que soit la page ouverte. */
    public static function submenu_file($file) {
        $slug = self::current_slug();
        if ($slug === '' || !isset(self::$pages[$slug])) return $file;
        $key = self::$pages[$slug]['group'];
        foreach (self::$pages as $s => $p) {
            if ($p['group'] === $key && current_user_can($p['cap'])) return $s;   // premier du groupe (ordre de définition)
        }
        return $file;
    }

    private static function current_slug(): string {
        return isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
    }

    private static function url($slug, $parent): string {
        $base = substr($parent, -4) === '.php' || strpos($parent, '.php?') !== false ? $parent : 'admin.php';
        return add_query_arg('page', $slug, admin_url($base));
    }

    /** Onglets du groupe en tête de la page ouverte (au moins deux pages accessibles). */
    public static function tabs() {
        $slug = self::current_slug();
        if ($slug === '' || !isset(self::$pages[$slug])) return;
        $key = self::$pages[$slug]['group'];
        $groups = self::groups();
        $tabs = [];
        foreach ($groups[$key][1] as $s) {
            if (isset(self::$pages[$s]) && current_user_can(self::$pages[$s]['cap'])) $tabs[$s] = self::$pages[$s];
        }
        if (count($tabs) < 2) return;
        echo '<div class="ispag-grp-tabs"><span class="ispag-grp-title">' . esc_html($groups[$key][0]) . '</span><nav class="nav-tab-wrapper" aria-label="' . esc_attr($groups[$key][0]) . '">';
        foreach ($tabs as $s => $p) {
            echo '<a class="nav-tab' . ($s === $slug ? ' nav-tab-active' : '') . '" href="' . esc_url(self::url($s, $p['parent'])) . '">' . esc_html($p['title']) . '</a>';
        }
        echo '</nav></div>';
    }

    public static function css() {
        echo '<style>#adminmenu li.' . self::HIDDEN . '{display:none !important}'
            . '.ispag-grp-tabs{margin:14px 20px 0 0;display:flex;align-items:flex-end;gap:14px;flex-wrap:wrap}'
            . '.ispag-grp-title{font-size:13px;font-weight:700;color:#646970;text-transform:uppercase;letter-spacing:.04em;padding-bottom:9px}'
            . '.ispag-grp-tabs .nav-tab-wrapper{flex:1;margin:0;border-bottom:1px solid #c3c4c7}'
            . '.ispag-grp-tabs + .wrap,.ispag-grp-tabs ~ #wpbody-content .wrap{margin-top:12px}</style>';
    }
}
