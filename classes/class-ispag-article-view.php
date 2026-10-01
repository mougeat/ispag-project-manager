<?php
defined('ABSPATH') || exit;

/**
 * Modale « affichage article » commune aux projets et aux achats.
 *
 * Chaque module prépare un tableau normalisé, ce rendu fait le reste :
 *   title        string   titre de l'article
 *   subtitle     string   (option) référence, type…
 *   image_html   string   HTML déjà prêt de l'image
 *   description  string   texte brut (les retours à la ligne sont conservés)
 *   qty          int
 *   unit_net     float|null   prix net par pièce (null = non affiché)
 *   discount     float        remise en %
 *   total        float|null   total de la ligne (null = non affiché)
 *   currency     string
 *   info         array   [[libellé, valeur], …] bloc « Logistics »
 *   steps        array   [[libellé, bool fait], …] avancement (réservé au personnel)
 *   documents    array   [['label'=>, 'url'=>], …]
 *   is_staff     bool
 */
class ISPAG_Article_View {

    public static function header(array $d) {
        ob_start();
        include plugin_dir_path(__FILE__) . 'templates/article-view-header.php';
        return ob_get_clean();
    }

    public static function body(array $d) {
        ob_start();
        include plugin_dir_path(__FILE__) . 'templates/article-view-body.php';
        return ob_get_clean();
    }

    /** Données normalisées d'un article de projet. */
    public static function from_project($article) {
        $is_staff        = current_user_can('manage_order');
        $show_prices     = current_user_can('display_sales_prices')
            && isset($_COOKIE['ispag_allow_prices']) && $_COOKIE['ispag_allow_prices'] === 'true';
        $fmt = function ($ts) { return $ts ? date('d.m.Y', (int) $ts) : '-'; };

        $info = [];
        if ($is_staff && $show_prices && !empty($article->fournisseur_nom)) {
            $info[] = [__('Supplier', 'creation-reservoir'), $article->fournisseur_nom];
        }
        $info[] = [__('Factory departure', 'creation-reservoir'), $fmt($article->TimestampDateDeLivraison ?? 0)];
        $info[] = [__('Delivery ETA', 'creation-reservoir'), $fmt($article->TimestampDateDeLivraisonFin ?? 0)];

        $documents = [];
        foreach ((array) ($article->documents ?? []) as $doc) {
            $documents[] = ['label' => __($doc['label'], 'creation-reservoir'), 'url' => $doc['url']];
        }

        return [
            'title'       => stripslashes((string) $article->Article),
            'subtitle'    => '',
            'image_html'  => ISPAG_Article_Repository::image_html($article->image, 'ispag-modal-img-fluid', 50),
            'description' => $article->Description ?? '',
            'qty'         => (int) $article->Qty,
            'unit_net'    => $show_prices ? (float) ($article->prix_net_calculé ?? 0) : null,
            'discount'    => (float) ($article->discount ?? 0),
            'total'       => $show_prices ? (float) ($article->prix_net_calculé ?? 0) * (int) $article->Qty : null,
            'currency'    => get_option('wpcb_currency'),
            'info'        => $info,
            'steps'       => [
                [__('Purchase requested', 'creation-reservoir'), !empty($article->DemandeAchatOk)],
                [__('Drawing approved', 'creation-reservoir'), (int) $article->DrawingApproved === 1],
                [__('Delivered', 'creation-reservoir'), !empty($article->Livre)],
                [__('Invoiced', 'creation-reservoir'), !empty($article->invoiced)],
            ],
            'documents'   => $documents,
            'is_staff'    => $is_staff,
        ];
    }
}
