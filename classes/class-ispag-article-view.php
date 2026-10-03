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
 *   unit_gross   float|null   prix brut par pièce, avant remise (affiché dans l'en-tête s'il est fourni)
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

        // Documents liés à l'article : plans, validations, pièces jointes… (historique de l'article)
        $documents = [];
        $deal_id   = (int) ($article->hubspot_deal_id ?? 0);
        $repo      = new ISPAG_Article_Repository();
        foreach ($repo->get_all_article_documents($deal_id, (int) $article->Id) as $doc) {
            $documents[] = ['label' => __($doc['label'], 'creation-reservoir'), 'url' => $doc['url'], 'date' => $doc['date']];
        }
        // Calcul de prix : note générée par le site (réservée à la gestion des commandes)
        if ($is_staff) {
            if (ISPAG_Pricing_Files::exists((int) $article->Id, 'project')) {
                $documents[] = ['label' => __('Price calculation note', 'creation-reservoir'), 'url' => ISPAG_Pricing_Files::url((int) $article->Id, 'project'), 'date' => date_i18n('d.m.Y', filemtime(ISPAG_Pricing_Files::path((int) $article->Id, 'project')))];
            }
        }

        // Documents générés par le site (croquis, fiche technique, certificat, plaque signalétique, notice)
        $tools = [];
        $deal_ref = $article->hubspot_deal_id ?? 0;
        if ($is_staff || !empty($article->last_drawing_url)) {
            $tools[] = (string) apply_filters('ispag_get_sketch_btn', '', $article, $deal_ref);
        }
        $tools[] = (string) apply_filters('ispag_get_technical_sheet_btn', null, $article, $deal_ref);
        $tools[] = (string) apply_filters('ispag_get_welding_certificat_btn', null, $article, $deal_ref);
        if ($article->Type == 1 && ($article->last_doc_type['slug'] ?? '') == 'drawingApproval') {
            $tools[] = (string) apply_filters('ispag_get_namesplate_btn', null, $article->Id);
        }
        $tools = array_values(array_filter($tools, function ($h) { return trim(strip_tags($h, '<a><button>')) !== ''; }));

        return [
            'title'       => stripslashes((string) $article->Article),
            'subtitle'    => '',
            'image_html'  => ISPAG_Article_Repository::image_html($article->image, 'ispag-modal-img-fluid', 50),
            'description' => $article->Description ?? '',
            'qty'         => (int) $article->Qty,
            // Prix brut unitaire (avant remise), repris dans l'en-tête pour être copié dans le logiciel d'offres
            'unit_gross'  => $show_prices ? (float) ($article->prix_total_calculé ?? 0) : null,
            'unit_net'    => $show_prices ? (float) ($article->prix_net_calculé ?? 0) : null,
            'discount'    => !empty($article->IdArticleMaster) ? 0.0 : (float) ($article->discount ?? 0),
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
            'tools'       => $tools,
            'is_staff'    => $is_staff,
        ];
    }
}
