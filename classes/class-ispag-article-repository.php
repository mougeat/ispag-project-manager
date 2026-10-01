<?php
defined('ABSPATH') or die();

class ISPAG_Article_Repository {
    protected $wpdb;
    protected $table_articles;
    protected $table_prestations;
    protected $table_fournisseurs;
    protected $table_article;
    protected $table_article_purchase;
    protected $table_price_history;
    protected static $instance = null;

    public static function ini(){
        if (self::$instance === null) {
            self::$instance = new self();
        }
        add_filter('ispag_get_standard_titles_by_type', [self::$instance, 'get_standard_titles_by_type'], 10, 1);
        add_filter('ispag_get_articles_by_deal', [self::$instance, 'filter_get_articles_by_deal'], 10, 3);
        add_filter('ispag_get_article_by_id', [self::$instance, 'get_article_by_id'], 10, 2);
        add_filter('ispag_get_articles_by_ids', [self::$instance, 'get_articles_by_ids'], 10, 2);
        add_filter('ispag_get_article_deal_id', [self::$instance, 'get_article_deal_id'], 10, 2);
        add_action('ispag_delete_articles_whith_deal_id', [self::$instance, 'delete_articles_whith_deal_id'],10,2);
        add_filter('ispag_get_groupe_by_article_id', [self::$instance, 'get_groupe_by_article_id'], 10, 2);

        // Ajoute cette ligne dans la méthode ini()
        add_action('wp_ajax_ispag_toggle_article_archive', [self::$instance,  'handle_toggle_article_archive']);
    }

    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->table_articles = $wpdb->prefix . 'achats_details_commande';
        $this->table_prestations = $wpdb->prefix . 'achats_type_prestations';
        $this->table_fournisseurs = $wpdb->prefix . 'ispag_companies';
        $this->table_article = $wpdb->prefix . 'achats_articles';
        $this->table_article_purchase = $wpdb->prefix . 'achats_articles_purchase';
        $this->table_price_history = $wpdb->prefix . 'achats_articles_price_history';
    }

    /** Image du type d'article (médiathèque, sinon icône fournie avec le plugin), ou '' : remplace l'image générique. */
    public static function type_image($type_id) {
        static $cache = [];
        $type_id = (int) $type_id;
        if ($type_id <= 0) return '';
        if (!array_key_exists($type_id, $cache)) {
            global $wpdb;
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT Id, prestation, image FROM {$wpdb->prefix}achats_type_prestations WHERE Id = %d", $type_id
            ), ARRAY_A);
            $cache[$type_id] = $row ? (string) ISPAG_Type_Icons::image_url($row) : '';
        }
        return $cache[$type_id];
    }

    /**
     * HTML de l'image d'un article : SVG en ligne, <img> ou, si l'image est absente ou introuvable (404),
     * l'icône neutre utilisée dans la fenêtre d'ajout d'article.
     */
    public static function image_html($content, $class = '', $icon_size = 40) {
        wp_enqueue_style('dashicons');
        $content = str_replace('../../', '', trim((string) $content));
        $icon = '<span class="dashicons dashicons-format-image ispag-image-fallback" style="font-size:' . (int) $icon_size . 'px;width:auto;height:auto;color:#ccc;"></span>';

        // Image absente ou placeholder par défaut : même icône neutre partout (blocs, modales)
        if ($content === '' || preg_match('#/placeholder\.webp(\?.*)?$#i', $content)) {
            return $icon;
        }
        if (strpos($content, '<svg') === 0) {
            return $content;
        }
        $onerror = "this.onerror=null;this.outerHTML=" . esc_attr(wp_json_encode($icon)) . ";";
        return '<img src="' . esc_attr($content) . '" alt="image"' . ($class !== '' ? ' class="' . esc_attr($class) . '"' : '') . ' onerror="' . $onerror . '">';
    }

    public function delete_articles_whith_deal_id($html, $deal_id){
        global $wpdb;

        // 1. Sélectionner les articles liés au projet
        $articles = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $this->table_articles WHERE hubspot_deal_id = %d",
            $deal_id
        ));

        // 2. Traiter les résultats (log, hook, etc.)
        foreach ($articles as $article) {
            // Appel du hook avec juste l'ID article (évite le null inutile)
            // error_log("Appel du hook ispag_delete_tank_with_article_id avec juste l'ID article {$article->Id}");
            do_action('ispag_delete_tank_with_article_id', null, $article->Id);

            // Log de la suppression (dans error_log par exemple)
            // error_log("Article deleted : ID {$article->Id}, Nom : {$article->Article}, Qte : {$article->Qty}");
        }

        // 3. Supprimer les articles liés au projet
        $wpdb->delete($this->table_articles, ['hubspot_deal_id' => $deal_id]);
    }



    public function filter_get_articles_by_deal($html, $deal_id, $only_archive = false){
        // return $this->get_articles_by_deal($deal_id, $only_archive);
        if (current_user_can('navigate_new_project_details_presentation')) {
            return $this->get_optimised_articles_by_deal($deal_id);
            
        }
        else{
            return $this->get_articles_by_deal($deal_id);
            
        }
    }

    /**
     * Récupère TOUS les articles d'un deal (actifs et archivés) groupés par statut et hiérarchie (Master / Secondaire).
     */
    public function get_all_articles_by_deal_grouped($deal_id) {
        if (empty($deal_id) || !is_numeric($deal_id)) {
            return ['active' => [], 'archived' => []];
        }

        $sql = "
            SELECT 
                a.*,
                p.sort AS prestation_sort,
                p.prestation,
                f.company_name AS fournisseur_nom,
                ta.image
            FROM {$this->table_articles} a
            LEFT JOIN {$this->table_prestations} p ON p.Id = a.Type
            LEFT JOIN {$this->table_fournisseurs} f ON f.Id = a.IdFournisseur
            LEFT JOIN {$this->table_article} ta ON ta.Id = a.IdArticleStandard
            WHERE a.hubspot_deal_id = %d
            ORDER BY a.Groupe ASC, p.sort ASC, a.tri ASC
        ";

        $prepared_sql = $this->wpdb->prepare($sql, $deal_id);
        if (!$prepared_sql) {
            return ['active' => [], 'archived' => []];
        }

        $results = $this->wpdb->get_results($prepared_sql);
        if (empty($results)) {
            return ['active' => [], 'archived' => []];
        }

        // 1. Batching des documents
        $article_ids = wp_list_pluck($results, 'Id');
        $all_documents = $this->get_batch_article_documents($deal_id, $article_ids);

        $current_user_id = get_current_user_id();
        $default_placeholder = plugin_dir_url(__FILE__) . "../../../assets/img/placeholder.webp";
        $plate_exchanger_img = wp_get_attachment_url(12289);

        // Dictionnaires temporaires
        $articles_by_id = [];
        $secondaires_by_master = [];

        // 2. Premier passage : traitement individuel et découplage Principaux / Secondaires
        foreach ($results as $article) {
            // Initialisation du tableau des secondaires pour chaque article
            $article->secondaires = [];

            // Assets image
            if (empty($article->image)) {
                $article->image = self::type_image($article->Type ?? 0) ?: $default_placeholder;
            } else {
                $article->image = wp_get_attachment_url($article->image);
            }
            
            // Dates
            $article->date_livraison = $article->TimestampDateDeLivraisonFin != 0 ? date('d.m.Y', $article->TimestampDateDeLivraisonFin) : null;
            $article->date_facturation = (!empty($article->invoiced) && $article->invoiced != 0) ? date('d.m.Y', $article->invoiced) : '';

            $article->btn_heatExchanger = null;

            // Switch des types d'articles
            switch ($article->Type) {
                case 1:
                    $article->Article = apply_filters('ispag_get_tank_title', $article->Article, $article->Id);
                    $article->fittings_description = apply_filters('ispag_get_tank_connections_description', null, $article->Id);
                    $article->Description = apply_filters('ispag_get_tank_description', $article->Article, $article->Id, false);
                    $article->Description_local = $article->Description;
                    $article->last_drawing_url = apply_filters('ispag_get_last_drawing_url', '', $article->Id);
                    $article->last_drawing_id = apply_filters('ispag_get_last_drawing_id', '', $article->Id);
                    $article->last_doc_type = apply_filters('ispag_get_if_last_drawing_or_modif', '', $article->Id);
                    $article->welding_text_informations = apply_filters('ispag_get_welding_text', null, $article->Article, $article->Id);
                    $article->tank_on_site_welded = apply_filters('ispag_get_tank_on_site_welded', $article->Article, $article->Id);
                    $article->image = apply_filters('ispag_design_tank_svg', $article->image, $article->Id, false);
                    $article->btn_heatExchanger = apply_filters('ispag_get_exchanger_btn', null, $article->Id);
                    $article->created_by_id = apply_filters('ispag_get_tank_created_by_id', $current_user_id, $article->Id);
                    break;

                case 2:
                    $article->Article = apply_filters('ispag_get_insulation_title', $article->Article, intval($article->IdArticleStandard));
                    $article->Description = apply_filters('ispag_get_insulation_description', $article->Article, $article->IdArticleStandard);
                    break;

                case 3:
                    $article->Article = apply_filters('ispag_get_welding_title', $article->Article, intval($article->IdArticleStandard));
                    $article->Description = apply_filters('ispag_get_welding_description', $article->Article, $article->IdArticleStandard, $article->hubspot_deal_id ?? 0);
                    break;

                case 5:
                case 500:
                    $article->Article = apply_filters('ispag_get_plate_exchanger_title', $article->Article, intval($article->Id));
                    $article->Description = apply_filters('ispag_get_plate_exchanger_description', $article->Article, intval($article->Id));
                    $article->image = $plate_exchanger_img;
                    break;
            }

            // Nettoyage description
            $description = str_ireplace(['<br>', '<br />', '<br/>'], "\n", $article->Description);
            $article->Description = stripslashes($description);

            // Documents & Calculs de prix
            $article->documents = $all_documents[$article->Id] ?? [];
            $article->prix_total_calculé = apply_filters('ispag_calculate_total_sales_price', $article->Id, 'default');
            $article->prix_net_calculé = apply_filters('ispag_calculate_net_unit_price', $article->Id, 'default');

            // Séparation : Est-ce un article secondaire ?
            $master_id = !empty($article->IdArticleMaster) ? (int) $article->IdArticleMaster : 0;

            if ($master_id > 0) {
                // C'est un article secondaire
                $secondaires_by_master[$master_id][] = $article;
            } else {
                // C'est un article principal
                $articles_by_id[$article->Id] = $article;
            }
        }

        // 3. Deuxième passage : Rattachement des secondaires aux principaux et création de la structure par groupes
        $active_articles = [];
        $archived_articles = [];

        foreach ($articles_by_id as $article_id => $article) {
            // Si l'article principal possède des secondaires, on les lui rattache
            if (isset($secondaires_by_master[$article_id])) {
                $article->secondaires = $secondaires_by_master[$article_id];
            }

            $group_name = $article->Groupe ?: __('General', 'creation-reservoir');

            if (!empty($article->archive) && $article->archive == 1) {
                $archived_articles[$group_name][] = $article;
            } else {
                $active_articles[$group_name][] = $article;
            }
        }

        return [
            'active'   => $active_articles,
            'archived' => $archived_articles
        ];
    }

    public function get_optimised_articles_by_deal($deal_id, $only_archive = false) {
        if (empty($deal_id) || !is_numeric($deal_id)) {
            return [];
        }

        $and_archive = $only_archive 
            ? "AND a.archive = 1" 
            : "AND (a.archive IS NULL OR a.archive = 0)";

        // 1. Récupération de tous les articles en une seule requête
        $sql = "
            SELECT 
                a.*,
                p.sort AS prestation_sort,
                p.prestation,
                f.company_name AS fournisseur_nom,
                ta.image
            FROM {$this->table_articles} a
            LEFT JOIN {$this->table_prestations} p ON p.Id = a.Type
            LEFT JOIN {$this->table_fournisseurs} f ON f.Id = a.IdFournisseur
            LEFT JOIN {$this->table_article} ta ON ta.Id = a.IdArticleStandard
            WHERE a.hubspot_deal_id = %d
            {$and_archive}
            ORDER BY a.Groupe ASC, p.sort ASC, a.tri ASC
        ";

        $prepared_sql = $this->wpdb->prepare($sql, $deal_id);
        if ($prepared_sql === false) {
            return [];
        }

        $results = $this->wpdb->get_results($prepared_sql);
        if (empty($results)) {
            return [];
        }

        // Collecter tous les IDs d'articles pour charger les documents en une seule fois (Évite le N+1)
        $article_ids = wp_list_pluck($results, 'Id');
        $all_documents = $this->get_batch_article_documents($deal_id, $article_ids); // Méthode en batch à créer si possible

        $current_user_id = get_current_user_id();
        $default_placeholder = plugin_dir_url(__FILE__) . "../../../assets/img/placeholder.webp";
        $plate_exchanger_img = wp_get_attachment_url(12289);

        // 2. Traitement itératif des articles
        foreach ($results as $article) {
            // Gestion des images
            if (empty($article->image)) {
                $article->image = self::type_image($article->Type ?? 0) ?: $default_placeholder;
            } else {
                $article->image = wp_get_attachment_url($article->image);
            }
            
            // Dates
            $article->date_livraison = $article->TimestampDateDeLivraisonFin != 0 ? date('d.m.Y', $article->TimestampDateDeLivraisonFin) : null;
            $article->date_facturation = (!empty($article->invoiced) && $article->invoiced != 0) ? date('d.m.Y', $article->invoiced) : '';

            $article->btn_heatExchanger = null;

            // Filtres spécifiques selon le type
            switch ($article->Type) {
                case 1:
                    $article->Article = apply_filters('ispag_get_tank_title', $article->Article, $article->Id);
                    $article->fittings_description = apply_filters('ispag_get_tank_connections_description', null, $article->Id);
                    $article->Description = apply_filters('ispag_get_tank_description', $article->Article, $article->Id, false);
                    $article->Description_local = apply_filters('ispag_get_tank_description', $article->Article, $article->Id, false);
                    $article->last_drawing_url = apply_filters('ispag_get_last_drawing_url', '', $article->Id);
                    $article->last_drawing_id = apply_filters('ispag_get_last_drawing_id', '', $article->Id);
                    $article->last_doc_type = apply_filters('ispag_get_if_last_drawing_or_modif', '', $article->Id);
                    $article->welding_text_informations = apply_filters('ispag_get_welding_text', null, $article->Article, $article->Id);
                    $article->tank_on_site_welded = apply_filters('ispag_get_tank_on_site_welded', $article->Article, $article->Id);
                    $article->image = apply_filters('ispag_design_tank_svg', $article->image, $article->Id, false); 
                    $article->btn_heatExchanger = apply_filters('ispag_get_exchanger_btn', null, $article->Id);
                    $article->created_by_id = apply_filters('ispag_get_tank_created_by_id', $current_user_id, $article->Id);
                    break;

                case 2:
                    $article->Article = apply_filters('ispag_get_insulation_title', $article->Article, intval($article->IdArticleStandard));
                    $article->Description = apply_filters('ispag_get_insulation_description', $article->Article, $article->IdArticleStandard);
                    break;

                case 3:
                    $article->Article = apply_filters('ispag_get_welding_title', $article->Article, intval($article->IdArticleStandard));
                    $article->Description = apply_filters('ispag_get_welding_description', $article->Article, $article->IdArticleStandard, $article->hubspot_deal_id ?? 0);
                    break;

                case 5:
                case 500:
                    $article->Article = apply_filters('ispag_get_plate_exchanger_title', $article->Article, intval($article->Id));
                    $article->Description = apply_filters('ispag_get_plate_exchanger_description', $article->Article, intval($article->Id));
                    $article->image = $plate_exchanger_img;
                    break;
            }

            // Description nettoyage
            $description = str_ireplace(['<br>', '<br />', '<br/>'], "\n", $article->Description);
            $article->Description = stripslashes($description);

            // Documents et prix calculés
            $article->documents = $all_documents[$article->Id] ?? [];
            $article->prix_total_calculé = apply_filters('ispag_calculate_total_sales_price', $article->Id, 'default');
            $article->prix_net_calculé = apply_filters('ispag_calculate_net_unit_price', $article->Id, 'default');
        }
        
        // 3. Regroupement hiérarchique optimisé
        $principaux = [];
        foreach ($results as $article) {
            if ($article->IdArticleMaster == 0) {
                $article->secondaires = [];
                $principaux[$article->Id] = $article;
            }
        }

        foreach ($results as $article) {
            if ($article->IdArticleMaster != 0 && isset($principaux[$article->IdArticleMaster])) {
                $principaux[$article->IdArticleMaster]->secondaires[] = $article;
            }
        }

        $grouped = [];
        foreach ($principaux as $principal) {
            $grouped[$principal->Groupe][] = $principal;
        }

        return $grouped;
    }


    public function get_articles_by_deal($deal_id, $only_archive = false) {
        if (empty($deal_id) || !is_numeric($deal_id)) {
            // error_log("ISPAG_Article_Repository: deal_id incorrect");
            return [];
        }

        if ($only_archive) {
            $and_archive = "AND a.archive = 1";
        } else {
            $and_archive = "AND (a.archive IS NULL OR a.archive = 0)";
        }

        $sql = "
            SELECT 
                a.*,
                p.sort AS prestation_sort,
                p.prestation,
                f.company_name AS fournisseur_nom,
                ta.image
            FROM {$this->table_articles} a
            LEFT JOIN {$this->table_prestations} p ON p.Id = a.Type
            LEFT JOIN {$this->table_fournisseurs} f ON f.Id = a.IdFournisseur
            LEFT JOIN {$this->table_article} ta ON ta.Id = a.IdArticleStandard
            WHERE a.hubspot_deal_id = %d
            {$and_archive}
            ORDER BY a.Groupe ASC, p.sort ASC, a.tri ASC
        ";

        $prepared_sql = $this->wpdb->prepare($sql, $deal_id);
        if ($prepared_sql === false) {
            // error_log("ISPAG_Article_Repository: erreur prepare SQL");
            return [];
        }

        $results = $this->wpdb->get_results($prepared_sql);

        if ($results === null) {
            // error_log("ISPAG_Article_Repository: erreur get_results SQL");
            return [];
        }

        if (empty($results)) return [];

        

        foreach ($results as $article) {


            if (empty($article->image)) {
                $article->image = self::type_image($article->Type ?? 0) ?: plugin_dir_url(__FILE__) . "../../../assets/img/placeholder.webp";
            }
            else {
                $article->image = wp_get_attachment_url($article->image);
            }
            
            $article->date_livraison = $article->TimestampDateDeLivraisonFin != 0 ? date('d.m.Y', $article->TimestampDateDeLivraisonFin) : null;
            $article->date_facturation = (!empty($article->invoiced) && $article->invoiced != 0 ) ? date('d.m.Y', $article->invoiced) : '';

            $article->btn_heatExchanger = null;
            // Si article de type cuve
            if ($article->Type == 1) {
                $article->Article = apply_filters('ispag_get_tank_title', $article->Article, $article->Id);
                $article->fittings_description = apply_filters('ispag_get_tank_connections_description', null, $article->Id);
                $article->Description = apply_filters('ispag_get_tank_description', $article->Article, $article->Id, false);
                $article->Description_local = apply_filters('ispag_get_tank_description', $article->Article, $article->Id, false);
                $article->last_drawing_url = apply_filters('ispag_get_last_drawing_url', '', $article->Id);
                $article->last_drawing_id = apply_filters('ispag_get_last_drawing_id', '', $article->Id);
                $article->last_doc_type = apply_filters('ispag_get_if_last_drawing_or_modif', '', $article->Id);
                $article->welding_text_informations = apply_filters('ispag_get_welding_text', null, $article->Article, $article->Id);
                $article->tank_on_site_welded = apply_filters('ispag_get_tank_on_site_welded', $article->Article, $article->Id);
                $article->image = apply_filters('ispag_design_tank_svg', $article->image, $article->Id, false);
                $article->btn_heatExchanger = apply_filters('ispag_get_exchanger_btn', null, $article->Id);
                $article->created_by_id = apply_filters('ispag_get_tank_created_by_id', get_current_user_id(), $article->Id);
            }
            elseif ($article->Type == 2) {
                $article->Article = apply_filters('ispag_get_insulation_title', $article->Article, intval($article->IdArticleStandard));
                $article->Description = apply_filters('ispag_get_insulation_description', $article->Article, $article->IdArticleStandard);
            }
            elseif ($article->Type == 3) {
                $article->Article = apply_filters('ispag_get_welding_title', $article->Article, intval($article->IdArticleStandard));
                $article->Description = apply_filters('ispag_get_welding_description', $article->Article, $article->IdArticleStandard, $article->hubspot_deal_id ?? 0);
            }
            elseif ($article->Type == 5 OR $article->Type == 500) {
                $article->Article = apply_filters('ispag_get_plate_exchanger_title', $article->Article, intval($article->Id));
                $article->Description = apply_filters('ispag_get_plate_exchanger_description', $article->Article, intval($article->Id));
                $article->image = wp_get_attachment_url(12289);
            }

            $description = str_ireplace(['<br>', '<br />', '<br/>'], "\n", $article->Description);
            $description = stripslashes($description);
            $article->Description = $description;


            // On va récupérer les documentations et spreadsheet pour chaque article
            $article->documents = $this->get_latest_article_documents($article->hubspot_deal_id, $article->Id);
            $article->prix_total_calculé = apply_filters('ispag_calculate_total_sales_price', $article->Id, 'default');
            $article->prix_net_calculé = apply_filters('ispag_calculate_net_unit_price', $article->Id, 'default');
            
        }
        
        // Regroupement
        $grouped = [];
        $principaux = [];

        foreach ($results as $article) {
            if ($article->IdArticleMaster == 0) {
                $article->secondaires = [];
                $principaux[$article->Id] = $article;
            }
        }

        foreach ($results as $article) {
            if ($article->IdArticleMaster != 0 && isset($principaux[$article->IdArticleMaster])) {
                $principaux[$article->IdArticleMaster]->secondaires[] = $article;
            }
        }

        foreach ($principaux as $principal) {
            // $prix_total = floatval($principal->sales_price);

            // foreach ($principal->secondaires as $secondaire) {
            //     $prix_total += floatval($secondaire->sales_price) * intval($secondaire->Qty);
            // }

            // $principal->prix_total_calculé = $prix_total;
            $grouped[$principal->Groupe][] = $principal;
        }


        return $grouped;
    }

    public function get_articles_by_ids($html, $ids) {
        if (empty($ids) || !is_array($ids)) return [];

        $today        = current_time('Y-m-d');
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));

        $sql = "
            SELECT 
                a.*,
                p.sort AS prestation_sort,
                p.prestation,
                f.company_name AS fournisseur_nom,
                ph.sales_price
            FROM {$this->table_articles} a
            LEFT JOIN {$this->table_prestations} p ON p.type = a.Type
            LEFT JOIN {$this->table_fournisseurs} f ON f.Id = a.IdFournisseur
            LEFT JOIN {$this->table_price_history} ph
                ON ph.article_id = a.Id
                AND ph.valid_from <= %s
                AND (ph.valid_to IS NULL OR ph.valid_to >= %s)
            WHERE a.Id IN ($placeholders)
            ORDER BY a.Groupe ASC, p.sort ASC, a.tri ASC, ph.valid_from DESC
        ";

        // Les deux %s de la jointure avant les %d des IDs
        $prepared_sql = $this->wpdb->prepare($sql, $today, $today, ...$ids);
        $articles     = $this->wpdb->get_results($prepared_sql);

        foreach ($articles as &$article) {
            $article->master_articles = $article->hubspot_deal_id ? $this->get_article_and_group($article->hubspot_deal_id) : [];

            if (intval($article->sales_price) === 0 || empty($article->sales_price)) {
                $article->sales_price = apply_filters('ispag_calculate_sales_price', $article->Id, 'default');
            }

            $article->image = self::type_image($article->Type ?? 0) ?: plugin_dir_url(__FILE__) . "../assets/img/placeholder.webp";
            $article->date_livraison = date('d.m.Y', $article->TimestampDateDeLivraisonFin);
            $article->date_facturation = (!empty($article->invoiced) && $article->invoiced != 0 ) ? date('d.m.Y', $article->invoiced) : '';

            switch ($article->Type) {
                case 1:
                    $article->Article = apply_filters('ispag_get_tank_title', $article->Article, $article->Id);
                    $article->fittings_description = apply_filters('ispag_get_tank_connections_description', null, $article->Id);
                    $article->Description = apply_filters('ispag_get_tank_description', $article->Article, $article->Id, false);
                    $article->last_drawing_url = apply_filters('ispag_get_last_drawing_url', '', $article->Id);
                    $article->last_drawing_id = apply_filters('ispag_get_last_drawing_id', '', $article->Id);
                    $article->last_doc_type = apply_filters('ispag_get_if_last_drawing_or_modif', '', $article->Id);
                    $article->welding_text_informations = apply_filters('ispag_get_welding_text', null, $article->Article, $article->Id);
                    $article->tank_on_site_welded = apply_filters('ispag_get_tank_on_site_welded', $article->Article, $article->Id);
                    $article->image = apply_filters('ispag_design_tank_svg', $article->image, $article->Id, false);
                    break;

                case 2:
                    $article->Article = apply_filters('ispag_get_insulation_title', $article->Article, intval($article->IdArticleStandard));
                    $article->Description = apply_filters('ispag_get_insulation_description', $article->Article, $article->IdArticleStandard);
                    break;

                case 3:
                    $article->Article = apply_filters('ispag_get_welding_title', $article->Article, intval($article->IdArticleStandard));
                    $article->Description = apply_filters('ispag_get_welding_description', $article->Article, $article->IdArticleStandard, $article->hubspot_deal_id ?? 0);
                    break;
                case 5:
                    $article->Article = apply_filters('ispag_get_plate_exchanger_title', $article->Article, intval($article->Id));
                    $article->Description = apply_filters('ispag_get_plate_exchanger_description', $article->Article, intval($article->Id));
                    $article->image = wp_get_attachment_url(12289);
                    break;
            
            }

            $article->documents = $this->get_latest_article_documents($article->hubspot_deal_id, $article->Id);
            $article->Description = stripslashes(str_ireplace(['<br>', '<br />', '<br/>'], "\n", $article->Description));
            $article->prix_total_calculé = apply_filters('ispag_calculate_total_sales_price', $article->Id, 'default');
            $article->prix_net_calculé = apply_filters('ispag_calculate_net_unit_price', $article->Id, 'default');

            $result[$article->Id] = $article;
        }

        return $result;
    }



    public function get_article_by_id($value, $article_id) {
        if(!$article_id) return false;
        $sql = "
            SELECT 
                a.*,
                p.sort AS prestation_sort,
                p.prestation,
                f.company_name AS fournisseur_nom
            FROM {$this->table_articles} a
            LEFT JOIN {$this->table_prestations} p ON p.type = a.Type
            LEFT JOIN {$this->table_fournisseurs} f ON f.Id = a.IdFournisseur
            WHERE a.Id = %d
            ORDER BY a.Groupe ASC, p.sort ASC, a.tri ASC
        ";

       $prepared_sql = $this->wpdb->prepare($sql, $article_id);
       $article = $this->wpdb->get_row($prepared_sql);

       if (!$article) {
            return false; 
        }

       $article->master_articles = array();
        if ($article && isset($article->hubspot_deal_id)) {
            $article->master_articles = $this->get_article_and_group($article->hubspot_deal_id);
        }

        // Si le prix de vente = 0 ou null alors on va le calculer
        if(intval($article->is_manual_price) != 1 && $article->Type == 1){
            $article->sales_price = apply_filters('ispag_calculate_sales_price', $article->Id, 'default');
        }

        $article->image = self::type_image($article->Type ?? 0) ?: plugin_dir_url(__FILE__) . "../assets/img/placeholder.webp";
        $article->date_livraison = date('d.m.Y', $article->TimestampDateDeLivraisonFin);
        $article->date_facturation = (!empty($article->invoiced) && $article->invoiced != 0 ) ? date('d.m.Y', $article->invoiced) : '';
        

        // Si article de type cuve
        if ($article->Type == 1) {
            $article->Article = apply_filters('ispag_get_tank_title', $article->Article, $article->Id);
            $article->fittings_description = apply_filters('ispag_get_tank_connections_description', null, $article->Id);
            $article->Description = apply_filters('ispag_get_tank_description', $article->Article, $article->Id, false);
            $article->last_drawing_url = apply_filters('ispag_get_last_drawing_url', '', $article->Id);
            $article->last_drawing_id = apply_filters('ispag_get_last_drawing_id', '', $article->Id);
            $article->last_doc_type = apply_filters('ispag_get_if_last_drawing_or_modif', '', $article->Id);
            $article->welding_text_informations = apply_filters('ispag_get_welding_text', null, $article->Article, $article->Id);
            $article->tank_on_site_welded = apply_filters('ispag_get_tank_on_site_welded', $article->Article, $article->Id);
            $article->created_by_id = apply_filters('ispag_get_tank_created_by_id', get_current_user_id(), $article->Id);

            $article->image = apply_filters('ispag_design_tank_svg', $article->image, $article->Id, false);
        }
        elseif ($article->Type == 2) {
            $article->Article = apply_filters('ispag_get_insulation_title', $article->Article, intval($article->IdArticleStandard));
            $article->Description = apply_filters('ispag_get_insulation_description', $article->Article, $article->IdArticleStandard);
        }
        elseif ($article->Type == 3) {
            $article->Article = apply_filters('ispag_get_welding_title', $article->Article, intval($article->IdArticleStandard));
            $article->Description = apply_filters('ispag_get_welding_description', $article->Article, $article->IdArticleStandard, $article->hubspot_deal_id ?? 0);
        }
        elseif ($article->Type == 5 OR $article->Type == 500) {
            $article->Article = apply_filters('ispag_get_plate_exchanger_title', $article->Article, intval($article->Id));
            $article->Description = apply_filters('ispag_get_plate_exchanger_description', $article->Article, intval($article->Id));
            $article->image = wp_get_attachment_url(12289);
        }

        // On va récupérer les documentations et spreadsheet pour chaque article
        $article->documents = $this->get_latest_article_documents($article->hubspot_deal_id, $article->Id);

        

        $description = str_ireplace(['<br>', '<br />', '<br/>'], "\n", $article->Description);
        $description = stripslashes($description);
        $article->Description = $description;

        $article->prix_total_calculé = apply_filters('ispag_calculate_total_sales_price', $article->Id, 'default');
        $article->prix_net_calculé = apply_filters('ispag_calculate_net_unit_price', $article->Id, 'default');

        // // Tu peux aussi ajouter une fallback pour les autres
        // else {
        //     // $article->Article = $article->Titre ?? ''; // ou autre champ si tu veux forcer
        //     $article->Description = $article->Description ?? '';
        //     $article->last_drawing_url = 'VIDE';
        // }


        return $article;
    }

    



    public function get_article_and_group($deal_id = null) {
        $articles_groupes = $this->wpdb->get_results(
            $this->wpdb->prepare("
                SELECT Id, Article, Groupe, Type
                FROM {$this->table_articles}
                WHERE IdArticleMaster = 0 AND hubspot_deal_id = %d
                ORDER BY Groupe ASC, tri ASC
            ", $deal_id)
        );
        
        $articles_grouped = [];

        foreach ($articles_groupes as $art) {
            // --- Traitement dynamique du titre selon le type ---
            
            // Type 1 : Génération du titre pour un réservoir (Tank)
            if ($art->Type == 1) {
                $art->Article = apply_filters('ispag_get_tank_title', $art->Article, $art->Id);
            } 
            // Type 2 : Génération du titre pour une isolation (Insulation)
            elseif ($art->Type == 2) {
                $art->Article = apply_filters('ispag_get_insulation_title', $art->Article, $art->Id);
            }

            // --- Groupage classique ---
            $groupe = $art->Groupe ?: 'Autre';
            if (!isset($articles_grouped[$groupe])) {
                $articles_grouped[$groupe] = [];
            }
            $articles_grouped[$groupe][] = $art;
        }

        return $articles_grouped;
    }
    public function get_groupes_by_deal($deal_id) {
        $sql = "
            SELECT DISTINCT Groupe
            FROM {$this->table_articles}
            WHERE hubspot_deal_id = %d AND Groupe IS NOT NULL AND Groupe != ''
            ORDER BY Groupe ASC
        ";

        return $this->wpdb->get_col($this->wpdb->prepare($sql, $deal_id));
    }
    public function get_groupe_by_article_id($html, $article_id = null) {
        if(empty($article_id)) return '';
        $sql = "
            SELECT DISTINCT Groupe
            FROM {$this->table_articles}
            WHERE Id = %d
            LIMIT 1
        ";

        return $this->wpdb->get_var($this->wpdb->prepare($sql, $article_id));
    }

    public function get_standard_titles_by_type($type) {
        $table_standard = $this->wpdb->prefix . 'achats_articles';
        $table_purchase = $this->wpdb->prefix . 'achats_articles_purchase';

        $results = $this->wpdb->get_results(
            $this->wpdb->prepare("SELECT Id, TitreArticle FROM $table_standard WHERE TypeArticle = %d ORDER BY TitreArticle ASC", $type)
        );
 
        if (!$results) return [];

        $titles = [];

        foreach ($results as $article) {
            $title = $article->TitreArticle;

            // Appliquer le filtre si défini
            if (has_filter('ispag_get_insulation_title')) {
                $title = apply_filters('ispag_get_insulation_title', $title, intval($article->Id));
            }

            $titles[] = [
                'id' => intval($article->Id),
                'title' => $title,
            ];
        }

        // Récupère tous les fournisseurs liés aux articles de ce type
        $suppliers = $this->wpdb->get_results(
            $this->wpdb->prepare("
                SELECT DISTINCT f.Id as supplier_id, f.company_name as supplier_name
                FROM $table_purchase ap
                INNER JOIN $table_standard a ON ap.article_id = a.Id
                INNER JOIN {$this->wpdb->prefix}ispag_companies f ON ap.supplier_id = f.Id
                WHERE a.TypeArticle = %d
                ORDER BY f.company_name ASC
            ", $type)
        );

        return [
            'titles' => $titles,
            'suppliers' => array_map(function($row) {
                return [
                    'id' => intval($row->supplier_id),
                    'name' => $row->supplier_name,
                ];
            }, $suppliers),
        ];
    }


    public function get_standard_article_by_title($title, $type) {
        $today = current_time('Y-m-d');

        $sql = $this->wpdb->prepare("
            SELECT a.TitreArticle, a.description_ispag, f.company_name AS Fournisseur, a.Id,
                ph.sales_price
            FROM {$this->table_article} a
            LEFT JOIN {$this->table_article_purchase} ap ON ap.article_id = a.Id
            LEFT JOIN {$this->table_fournisseurs} f ON f.Id = ap.supplier_id
            LEFT JOIN {$this->table_price_history} ph
                ON ph.article_id = a.Id
                AND ph.valid_from <= %s
                AND (ph.valid_to IS NULL OR ph.valid_to >= %s)
            WHERE a.Id = %s AND a.TypeArticle = %d
            ORDER BY ph.valid_from DESC
        ", $today, $today, $title, $type);

        $results = $this->wpdb->get_results($sql);
        if (empty($results)) {
            return null;
        }

        $article_info = (object) [
            'TitreArticle'        => apply_filters('ispag_get_insulation_title', $results[0]->TitreArticle, intval($results[0]->Id)),
            'description_ispag'   => html_entity_decode(apply_filters('ispag_get_insulation_description', $results[0]->description_ispag, $results[0]->IdArticleStandard)),
            'sales_price'         => $results[0]->sales_price, // ← vient maintenant de ph
            'Id_article_standard' => $results[0]->Id,
            'suppliers'           => [],
        ];

        $suppliers = [];
        foreach ($results as $row) {
            if ($row->Fournisseur && !in_array($row->Fournisseur, $suppliers)) {
                $suppliers[] = $row->Fournisseur;
            }
        }
        $article_info->suppliers = $suppliers;

        return $article_info;
    }

    public function get_latest_article_documents($deal_id, $article_id) {
        global $wpdb;

        $results = $wpdb->get_results(
            $wpdb->prepare(
                "
                SELECT 
                    t.ClassCss, 
                    t.IdMedia, 
                    dt.label, 
                    dt.badge_class
                FROM wor9711_achats_historique t
                INNER JOIN (
                    SELECT ClassCss, MAX(dateReadable) AS max_date
                    FROM wor9711_achats_historique
                    WHERE hubspot_deal_id = %d
                    AND Historique = %s
                    AND ClassCss IN ('documentation', 'spreadsheet')
                    AND IdMedia > 0
                    GROUP BY ClassCss
                ) latest ON t.ClassCss = latest.ClassCss AND t.dateReadable = latest.max_date
                LEFT JOIN wor9711_achats_doc_types dt ON dt.slug = t.ClassCss
                WHERE t.hubspot_deal_id = %d AND t.Historique = %s
                ",
                $deal_id, $article_id, $deal_id, $article_id
            )
        );

        $documents = [];

        foreach ($results as $row) {
            $url = wp_get_attachment_url($row->IdMedia);
            if ($url) {
                $documents[] = [
                    'class'        => $row->ClassCss,
                    'url'          => $url,
                    'label'        => $row->label ?: ucfirst($row->ClassCss),
                    'badge_class'  => $row->badge_class ?: 'badge-default',
                ];
            }
        }
        $article_id = (int)$article_id;


        return $documents;
    }

    public function get_batch_article_documents($deal_id, $article_ids) {
        if (empty($deal_id) || empty($article_ids)) {
            return [];
        }

        global $wpdb;

        // Sécurisation des IDs d'articles pour la clause IN
        $article_ids_sanitized = array_map('intval', $article_ids);
        $ids_placeholder = implode(',', array_fill(0, count($article_ids_sanitized), '%d'));

        // On utilise ROW_NUMBER() pour récupérer le document le plus récent par article et par ClassCss
        $sql = "
            SELECT ranked.*, dt.label, dt.badge_class
            FROM (
                SELECT 
                    t.Historique AS article_id,
                    t.ClassCss, 
                    t.IdMedia,
                    ROW_NUMBER() OVER (PARTITION BY t.Historique, t.ClassCss ORDER BY t.dateReadable DESC) as rn
                FROM wor9711_achats_historique t
                WHERE t.hubspot_deal_id = %d
                  AND t.Historique IN ($ids_placeholder)
                  AND t.ClassCss IN ('documentation', 'spreadsheet')
                  AND t.IdMedia > 0
            ) ranked
            LEFT JOIN wor9711_achats_doc_types dt ON dt.slug = ranked.ClassCss
            WHERE ranked.rn = 1
        ";

        // Construction des paramètres : $deal_id suivi de tous les $article_ids
        $params = array_merge([$deal_id], $article_ids_sanitized);
        
        $prepared_sql = $wpdb->prepare($sql, $params);
        if ($prepared_sql === false) {
            return [];
        }

        $results = $wpdb->get_results($prepared_sql);

        // Organisation des documents par article_id sous forme de tableau associatif
        $documents_by_article = [];

        foreach ($results as $row) {
            $url = wp_get_attachment_url($row->IdMedia);
            if ($url) {
                $article_id = (int)$row->article_id;
                
                if (!isset($documents_by_article[$article_id])) {
                    $documents_by_article[$article_id] = [];
                }

                $documents_by_article[$article_id][] = [
                    'class'       => $row->ClassCss,
                    'url'         => $url,
                    'label'       => $row->label ?: ucfirst($row->ClassCss),
                    'badge_class' => $row->badge_class ?: 'badge-default',
                ];
            }
        }

        return $documents_by_article;
    }

    public function get_article_deal_id($html, $article_id) {
        global $wpdb;
        
    //    $article_id = (int)$article_id;

        $deal_id = $wpdb->get_var($wpdb->prepare(
            "SELECT hubspot_deal_id 
            FROM {$wpdb->prefix}achats_details_commande 
            WHERE Id = %d",
            $article_id
        ));

        return $deal_id ?: 'not found';
    }


    /**
     * Bascule l'état d'archivage d'un article (archive/désarchive)
     *
     * @param int $article_id L'ID de l'article à archiver/désarchiver
     * @return bool True si la mise à jour a réussi, false sinon
     */
    public function toggle_article_archive($article_id) {
        if (!is_numeric($article_id) || $article_id <= 0) {
            return false;
        }

        // Récupère l'état actuel de l'article
        $current_archive_status = $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT archive FROM {$this->table_articles} WHERE Id = %d",
                $article_id
            )
        );

        if ($current_archive_status === null) {
            return false; // Article introuvable
        }

        // Inverse l'état (0 → 1, 1 → 0)
        $new_archive_status = $current_archive_status == 1 ? 0 : 1;

        // Met à jour l'article
        $updated = $this->wpdb->update(
            $this->table_articles,
            ['archive' => $new_archive_status],
            ['Id' => $article_id],
            ['%d'],
            ['%d']
        );

        return $updated !== false;
    }

    /**
     * Gère l'appel AJAX pour archiver/désarchiver un article
     */
    public function handle_toggle_article_archive() {
        check_ajax_referer('ispag_nonce', 'nonce');

        if (!current_user_can('manage_order')) {
            wp_send_json_error(['message' => __('Unauthorized', 'creation-reservoir')]);
        }

        $article_id = isset($_POST['article_id']) ? intval($_POST['article_id']) : 0;

        if (!$article_id) {
            wp_send_json_error(['message' => __('Invalid article ID', 'creation-reservoir')]);
        }

        $success = $this->toggle_article_archive($article_id);

        if ($success) {
            $new_status = $this->wpdb->get_var(
                $this->wpdb->prepare(
                    "SELECT archive FROM {$this->table_articles} WHERE Id = %d",
                    $article_id
                )
            );
            $message = $new_status == 1
                ? __('Article archived', 'creation-reservoir')
                : __('Article unarchived', 'creation-reservoir');

            wp_send_json_success([
                'message' => $message,
                'archive' => $new_status,
            ]);
        } else {
            wp_send_json_error(['message' => __('Failed to update article', 'creation-reservoir')]);
        }
    }

    public static function get_standard_article_purchase_price($article_id = null, $supplier_id = null) {
        if (empty($article_id) || empty($supplier_id)) {
            return [];
        }

        global $wpdb;
        
        // Utilisation de get_row avec ARRAY_A pour récupérer la ligne sous forme de tableau associatif
        $result = $wpdb->get_row($wpdb->prepare(
            "SELECT aph.purchase_price, aph.discount 
            FROM {$wpdb->prefix}achats_articles_purchase_price_history aph
            LEFT JOIN {$wpdb->prefix}achats_articles_purchase ap
                ON ap.Id = aph.purchase_id 
            WHERE ap.article_id = %d
            AND ap.supplier_id = %d",
            $article_id,
            $supplier_id
        ), ARRAY_A);

        // Retourne le tableau ou un tableau vide si aucun résultat n'est trouvé
        return $result ? $result : [];
    }

}
