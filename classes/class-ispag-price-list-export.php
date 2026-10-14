<?php
defined('ABSPATH') || exit;

/**
 * Listes de prix de vente en PDF (ISPAG Settings → Price lists).
 *
 *  - Isolations (articles standards de type 2) : un tableau par produit (laine de roche, Armaflex…),
 *    litres × épaisseur, pour les cuves de hauteur < 2500 mm et de 2501 à 4500 mm.
 *  - Soudures sur place (type 3) : diamètre × matière × nombre de soudures.
 *
 * Les prix viennent de l'historique des prix de vente (achats_articles_price_history) : pour la date choisie
 * (aujourd'hui par défaut) on prend la ligne dont [valid_from ; valid_to] contient cette date. Un article sans aucun
 * historique garde son prix de la fiche article ; un article dont tous les prix sont échus à cette date est omis.
 * La liste peut donc être éditée « à la date de » une ancienne ou future entrée en vigueur d'un tarif.
 *
 * Droit requis : manage_options (même niveau que la page de réglages qui porte les boutons).
 */
class ISPAG_Price_List_Export {

    const ACTION      = 'ispag_price_list_pdf';
    const TYPE_INSUL  = 2;
    const TYPE_WELD   = 3;
    const TYPE_OPTION = 200;
    /** Hauteur maximale couvrant la liste d'isolation (mm), affichée dans l'en-tête de la 2e colonne. */
    const INSUL_MAX_HEIGHT = 4500;

    public static function init() {
        add_action('admin_post_' . self::ACTION, [self::class, 'handle']);
    }

    // ------------------------------------------------------------------ Interface (ISPAG Settings)

    /** Section « Price lists » de la page ISPAG Settings (formulaires GET indépendants du formulaire de réglages). */
    public static function render_section() {
        $kinds = [
            'insulation' => ['Insulation price list', 'Rock wool and Armaflex insulation (article type 2): one table per product, litres × thickness, for tanks lower than 2500 mm and from 2501 to 4500 mm.'],
            'welding'    => ['On-site welding price list', 'On-site welding of tanks (article type 3): diameter × material × number of welds.'],
        ];
        echo '<h2>Price lists (PDF)</h2>';
        echo '<p>Customer-ready sales price lists (excluding VAT) built from the standard articles and their price history. '
            . 'Pick the date the prices must be valid on: a price whose validity period does not cover that date is not used. '
            . 'Leave today\'s date to print the prices currently in force.</p>';
        echo '<form method="get" action="' . esc_url(admin_url('admin-post.php')) . '" target="_blank" style="max-width:760px">';
        echo '<input type="hidden" name="action" value="' . esc_attr(self::ACTION) . '">';
        wp_nonce_field(self::ACTION, '_wpnonce', false);
        echo '<table class="form-table"><tr><th scope="row"><label for="ispag_pl_date">Prices valid on</label></th><td>'
            . '<input type="date" id="ispag_pl_date" name="date" value="' . esc_attr(current_time('Y-m-d')) . '"> '
            . '<span class="description">Date shown on the first page and used to pick each price from its history.</span></td></tr>';
        foreach ($kinds as $key => $def) {
            echo '<tr><th scope="row">' . esc_html($def[0]) . '</th><td>'
                . '<button type="submit" name="kind" value="' . esc_attr($key) . '" class="button button-primary">Export PDF</button>'
                . '<p class="description">' . esc_html($def[1]) . '</p></td></tr>';
        }
        echo '</table></form>';
    }

    // ------------------------------------------------------------------ Requête

    public static function handle() {
        if (!current_user_can('manage_options') || !wp_verify_nonce($_REQUEST['_wpnonce'] ?? '', self::ACTION)) {
            wp_die(esc_html__('Access denied', 'creation-reservoir'), '', ['response' => 403]);
        }
        $kind = sanitize_key(wp_unslash($_REQUEST['kind'] ?? ''));
        $date = (string) wp_unslash($_REQUEST['date'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !strtotime($date)) {
            $date = current_time('Y-m-d');
        }
        if (!in_array($kind, ['insulation', 'welding'], true)) {
            wp_die(esc_html__('Unknown price list', 'creation-reservoir'), '', ['response' => 400]);
        }

        $pdf = $kind === 'insulation' ? self::build_insulation($date) : self::build_welding($date);
        if (!$pdf) {
            wp_die(esc_html__('No standard article with a price valid on this date.', 'creation-reservoir'), '', ['response' => 404]);
        }

        $file = ($kind === 'insulation' ? 'ISPAG-Tarifs-Isolation-' : 'ISPAG-Tarifs-Soudure-') . $date . '.pdf';
        while (ob_get_level()) ob_end_clean();
        nocache_headers();
        $pdf->Output('I', $file);
        exit;
    }

    // ------------------------------------------------------------------ Données

    /**
     * Articles d'un type avec leur prix à la date donnée.
     * Chaque objet : Id, ref_article_ispag, TitreArticle, conception (tableau), price (float|null), valid_from (date|null).
     * Seuls les articles actifs (si la colonne is_active existe) et avec un prix > 0 sont retournés.
     */
    public static function articles($type, $date) {
        global $wpdb;
        $a = $wpdb->prefix . 'achats_articles';
        $h = $wpdb->prefix . 'achats_articles_price_history';

        $active = $wpdb->get_var("SHOW COLUMNS FROM `{$a}` LIKE 'is_active'") ? ' AND a.is_active = 1' : '';
        $sql = "SELECT a.Id, a.ref_article_ispag, a.TitreArticle, a.conception, a.sales_price AS base_price,
                       (SELECT COUNT(*) FROM {$h} hc WHERE hc.article_id = a.Id) AS nb_history,
                       (SELECT hh.sales_price FROM {$h} hh WHERE hh.article_id = a.Id AND hh.valid_from <= %s AND (hh.valid_to IS NULL OR hh.valid_to >= %s)
                         ORDER BY hh.valid_from DESC, hh.Id DESC LIMIT 1) AS price_at,
                       (SELECT hh2.valid_from FROM {$h} hh2 WHERE hh2.article_id = a.Id AND hh2.valid_from <= %s AND (hh2.valid_to IS NULL OR hh2.valid_to >= %s)
                         ORDER BY hh2.valid_from DESC, hh2.Id DESC LIMIT 1) AS valid_from
                FROM {$a} a
                WHERE a.TypeArticle = %d{$active}
                ORDER BY a.Id ASC";
        $rows = (array) $wpdb->get_results($wpdb->prepare($sql, $date, $date, $date, $date, (int) $type));

        $out = [];
        foreach ($rows as $r) {
            // Pas d'historique du tout : prix de la fiche ; historique sans ligne valable à cette date : article omis
            $price = $r->price_at !== null ? (float) $r->price_at : ((int) $r->nb_history === 0 ? (float) $r->base_price : null);
            if ($price === null || $price <= 0) continue;
            $conception = json_decode((string) $r->conception, true);
            $out[] = (object) [
                'Id'         => (int) $r->Id,
                'ref'        => (string) $r->ref_article_ispag,
                'title'      => self::text($r->TitreArticle),
                'conception' => is_array($conception) ? $conception : [],
                'price'      => $price,
                'valid_from' => $r->valid_from,
            ];
        }
        return $out;
    }

    /** Texte d'article lisible : entités HTML décodées, antislashs d'apostrophes retirés. */
    public static function text($s) {
        return trim(html_entity_decode(str_replace("\\'", "'", wp_strip_all_tags((string) $s)), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    public static function money($v) {
        return number_format((float) $v, 2, '.', ' ');
    }

    private static function currency() {
        return (string) (get_option('wpcb_currency') ?: 'CHF');
    }

    private static function fr_date($date) {
        return date('d.m.Y', strtotime($date));
    }

    private static function subtitle($date, array $items) {
        $from = array_filter(array_map(function ($i) { return $i->valid_from; }, $items));
        $txt  = 'Prix de vente ISPAG en vigueur au ' . self::fr_date($date) . ' - hors TVA';
        return $from ? $txt . ' (dernière mise à jour des tarifs : ' . self::fr_date(max($from)) . ')' : $txt;
    }

    // ------------------------------------------------------------------ Isolations

    private static function build_insulation($date) {
        $items = self::articles(self::TYPE_INSUL, $date);
        if (!$items) return null;

        // Regroupement par type d'isolant, puis dédoublonnage : épaisseur × hauteur × litres (l'article le plus récent l'emporte)
        $groups = [];
        foreach ($items as $it) {
            $ins = $it->conception['insulation'] ?? null;
            if (!is_array($ins) || empty($ins['tankVolum']) || empty($ins['insulationType'])) continue;
            if (!preg_match('/(\d+)\s*mm/i', $it->title, $mm)) continue;
            $name = preg_match('/^Isolation\s+(.+?)\s+\d+\s*mm/i', $it->title, $n) ? $n[1] : 'Isolation';
            $g = (int) $ins['insulationType'];
            $groups[$g]['names'][mb_strtolower($name)] = ($groups[$g]['names'][mb_strtolower($name)] ?? 0) + 1;
            $groups[$g]['limit'] = (int) ($ins['tankHeightLimit'] ?? 2500) ?: 2500;
            $groups[$g]['items'][] = [
                'id'    => $it->Id, 'cover' => (string) ($ins['insulationCover'] ?? ''),
                'mm'    => (int) $mm[1], 'over' => ($ins['tankHeight'] ?? '') === 'over',
                'litre' => (int) $ins['tankVolum'], 'price' => $it->price,
            ];
        }
        if (!$groups) return null;

        // Un seul tableau : litres en lignes ; colonnes = hauteur de cuve > isolant > épaisseur
        ksort($groups);
        $prod = []; // [isolant => ['label' => ..., 'thick' => [mm => true]]]
        $cells = $litres = [];
        $limit = 2500;
        foreach ($groups as $gid => $g) {
            // Recouvrements minoritaires (autre manteau) ignorés : on garde la finition la plus répandue
            $covers = array_count_values(array_column($g['items'], 'cover'));
            arsort($covers);
            $cover = (string) key($covers);
            arsort($g['names']);
            $short = trim(explode('/', (string) key($g['names']))[0]); // « laine de roche/Aluman » -> « laine de roche »
            $prod[$gid] = ['label' => ucfirst($short), 'thick' => []];
            $limit = $g['limit'];
            usort($g['items'], function ($x, $y) { return $x['id'] <=> $y['id']; });
            foreach ($g['items'] as $i) {
                if ($i['cover'] !== $cover) continue;
                $cells[$gid][$i['litre']][$i['over'] ? 1 : 0][$i['mm']] = $i['price'];
                $prod[$gid]['thick'][$i['mm']] = true;
                $litres[$i['litre']] = true;
            }
            ksort($prod[$gid]['thick']);
        }
        ksort($litres);

        $heads = $top = $mid = $rows = [];
        foreach ([0 => 'Hauteur < ' . $limit . ' mm', 1 => 'Hauteur ' . ($limit + 1) . ' à ' . self::INSUL_MAX_HEIGHT . ' mm'] as $over => $label) {
            $span = 0;
            foreach ($prod as $p) {
                $mid[] = ['label' => $p['label'], 'span' => count($p['thick'])];
                foreach (array_keys($p['thick']) as $mm) $heads[] = $mm . ' mm';
                $span += count($p['thick']);
            }
            $top[] = ['label' => $label, 'span' => $span];
        }
        foreach (array_keys($litres) as $l) {
            $line = [];
            foreach ([0, 1] as $over) {
                foreach ($prod as $gid => $p) {
                    foreach (array_keys($p['thick']) as $mm) $line[] = $cells[$gid][$l][$over][$mm] ?? null;
                }
            }
            $rows[] = ['label' => number_format($l, 0, '.', ' '), 'cells' => $line];
        }

        $pdf = new ISPAG_Price_List_PDF('L');
        $pdf->begin('Tarifs isolation', self::subtitle($date, $items));
        $pdf->section('Isolation sur chantier', 'Isolation sous manteau Aluman, pour cuve de hauteur maximum ' . number_format(self::INSUL_MAX_HEIGHT / 1000, 1) . ' m. Hauteur hors virole, volume de la cuve en litres. Prix en ' . self::currency() . ', hors TVA.');
        $pdf->price_table($top, $heads, $rows, 'Litres', $mid, 5.2);
        self::insulation_footer($pdf, $date);
        return $pdf;
    }

    private static function insulation_footer(ISPAG_Price_List_PDF $pdf, $date) {
        $lines = [];
        foreach (self::articles(self::TYPE_OPTION, $date) as $o) {
            $title = preg_replace('/^Plus[- ]value pour\s+/iu', '', $o->title);
            $lines[] = [ucfirst(trim(preg_replace('/\s*\(\d+\s*%\)\s*$/', '', $title))), self::currency() . ' ' . self::money($o->price)];
        }
        // Plus-values en pourcentage : articles à 0.00 dont le libellé porte le taux
        global $wpdb;
        $pct = (array) $wpdb->get_results($wpdb->prepare(
            "SELECT TitreArticle FROM {$wpdb->prefix}achats_articles WHERE TypeArticle = %d AND sales_price = 0 ORDER BY Id",
            self::TYPE_OPTION
        ));
        foreach ($pct as $p) {
            $t = self::text($p->TitreArticle);
            if (preg_match('/\((\d+\s*%)\)\s*$/', $t, $m)) {
                $lines[] = [ucfirst(trim(preg_replace('/^Plus[- ]value pour\s+/iu', 'Plus-value : ', preg_replace('/\s*\(\d+\s*%\)\s*$/', '', $t)))), '+ ' . str_replace(' ', '', $m[1])];
            }
        }
        $pdf->key_value_box('Options et plus-values', $lines);
        $pdf->bullets('Conditions', apply_filters('ispag_price_list_insulation_conditions', [
            'Prix rendu-posé sur place, dans toute la Suisse, sur réservoir non raccordé (plus-value si déjà raccordé : +10 %).',
            'Injection de la virole en mousse PU et caissons pour pieds : selon liste de prix.',
            'Prix valable jusqu\'à une hauteur totale de ' . number_format(self::INSUL_MAX_HEIGHT / 1000, 1) . ' m maximum.',
            'Déplacement à vide : 1 monteur CHF 2.-/km, 2 monteurs CHF 3.-/km.',
            'Délai d\'intervention : 10 jours ouvrables sur appel.',
            'Prix bruts HT, TVA en sus. Offre valable jusqu\'à nouvel avis.',
        ], $date));
    }

    // ------------------------------------------------------------------ Soudures

    private static function build_welding($date) {
        $items = self::articles(self::TYPE_WELD, $date);
        if (!$items) return null;

        $price = $mat_label = $mat_id = [];
        $diam_seen = $welds_seen = [];
        usort($items, function ($x, $y) { return $x->Id <=> $y->Id; }); // le plus récent écrase l'ancien
        foreach ($items as $it) {
            $w = $it->conception['welding'] ?? null;
            if (!is_array($w) || empty($w['tank_diameter']) || empty($w['tank_material'])) continue;
            // Nombre de soudures = morceaux - 1 (libellé « livrée en N morceaux ») ; sinon valeur de la fiche
            $welds = preg_match('/en\s+(\d+)\s+morceaux/iu', $it->title, $m) ? (int) $m[1] - 1 : (int) ($w['nb_welding'] ?? 0);
            if ($welds < 1) continue;
            $mat = (int) $w['tank_material'];
            $d   = (int) $w['tank_diameter'];
            $price[$d][$mat][$welds] = $it->price;
            $diam_seen[$d] = true;
            $welds_seen[$welds] = true;
            if (preg_match('/cuve\s+(.+?)\s+de\s+di/iu', $it->title, $mm)) $mat_label[$mat] = $mm[1];
            $mat_id[$mat] = true;
        }
        if (!$price) return null;
        ksort($diam_seen);
        ksort($welds_seen);
        ksort($mat_id);

        $groups = $heads = $rows = [];
        foreach (array_keys($mat_id) as $mat) {
            $groups[] = ['label' => $mat_label[$mat] ?? ('Matière ' . $mat), 'span' => count($welds_seen)];
            foreach (array_keys($welds_seen) as $n) $heads[] = $n . ($n > 1 ? ' soudures' : ' soudure');
        }
        foreach (array_keys($diam_seen) as $d) {
            $line = [];
            foreach (array_keys($mat_id) as $mat) {
                foreach (array_keys($welds_seen) as $n) $line[] = $price[$d][$mat][$n] ?? null;
            }
            if (count(array_filter($line, function ($v) { return $v !== null; })) === 0) continue;
            $rows[] = ['label' => number_format($d, 0, '.', ' '), 'cells' => $line];
        }

        $pdf = new ISPAG_Price_List_PDF('L');
        $pdf->begin('Tarifs soudure sur place', self::subtitle($date, $items));
        $pdf->section('Soudure sur place des réservoirs ISPAG', 'Tarif par cuve selon le nombre de soudures, dans un rayon de 80 km autour de Genève - Lausanne. Diamètre en mm. Prix en ' . self::currency() . ', hors TVA.');
        $pdf->price_table($groups, $heads, $rows, 'Diamètre');
        $pdf->bullets('Conditions', apply_filters('ispag_price_list_welding_conditions', [
            'Tarifs par cuve, dans un rayon de 80 km autour de Genève - Lausanne.',
            'Prix bruts HT, TVA en sus. Offre valable jusqu\'à nouvel avis.',
        ], $date));
        return $pdf;
    }
}
