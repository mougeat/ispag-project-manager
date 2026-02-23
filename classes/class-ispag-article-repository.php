<?php
defined('ABSPATH') or die();

class ISPAG_Article_Repository {
    protected $wpdb;
    protected $table_articles;
    protected $table_prestations;
    protected $table_fournisseurs;
    protected $table_article;
    protected $table_article_purchase;
    protected static $instance = null;

    public static function ini(){
        if (self::$instance === null) {
            self::$instance = new self();
        }
        add_filter('ispag_get_standard_titles_by_type', [self::$instance, 'get_standard_titles_by_type'], 10, 1);
        add_filter('ispag_get_articles_by_deal', [self::$instance, 'filter_get_articles_by_deal'], 10, 2);
        add_filter('ispag_get_article_by_id', [self::$instance, 'get_article_by_id'], 10, 2);
        add_filter('ispag_get_articles_by_ids', [self::$instance, 'get_articles_by_ids'], 10, 2);
        add_filter('ispag_get_article_deal_id', [self::$instance, 'get_article_deal_id'], 10, 2);
        add_action('ispag_delete_articles_whith_deal_id', [self::$instance, 'delete_articles_whith_deal_id'],10,2);
        add_filter('ispag_get_groupe_by_article_id', [self::$instance, 'get_groupe_by_article_id'], 10, 2);
    }

    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->table_articles = $wpdb->prefix . 'achats_details_commande';
        $this->table_prestations = $wpdb->prefix . 'achats_type_prestations';
        $this->table_fournisseurs = $wpdb->prefix . 'achats_fournisseurs';
        $this->table_article = $wpdb->prefix . 'achats_articles';
        $this->table_article_purchase = $wpdb->prefix . 'achats_articles_purchase';
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
            // error_log("Article supprimé : ID {$article->Id}, Nom : {$article->Article}, Qte : {$article->Qty}");
        }

        // 3. Supprimer les articles liés au projet
        $wpdb->delete($this->table_articles, ['hubspot_deal_id' => $deal_id]);
    }



    public function filter_get_articles_by_deal($html, $deal_id){
        return $this->get_articles_by_deal($deal_id);
    }

    public function get_articles_by_deal($deal_id) {
        if (empty($deal_id) || !is_numeric($deal_id)) {
            // error_log("ISPAG_Article_Repository: deal_id incorrect");
            return [];
        }

        

        $sql = "
            SELECT 
                a.*,
                p.sort AS prestation_sort,
                p.prestation,
                f.Fournisseur AS fournisseur_nom,
                ta.image
            FROM {$this->table_articles} a
            LEFT JOIN {$this->table_prestations} p ON p.Id = a.Type
            LEFT JOIN {$this->table_fournisseurs} f ON f.Id = a.IdFournisseur
            LEFT JOIN {$this->table_article} ta ON ta.Id = a.IdArticleStandard
            WHERE a.hubspot_deal_id = %d
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
                $article->image = plugin_dir_url(__FILE__) . "../../../assets/img/placeholder.webp";
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
                $article->Description = apply_filters('ispag_get_welding_description', $article->Article, $article->IdArticleStandard);
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

        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $sql = "
            SELECT 
                a.*,
                p.sort AS prestation_sort,
                p.prestation,
                f.Fournisseur AS fournisseur_nom
            FROM {$this->table_articles} a
            LEFT JOIN {$this->table_prestations} p ON p.type = a.Type
            LEFT JOIN {$this->table_fournisseurs} f ON f.Id = a.IdFournisseur
            WHERE a.Id IN ($placeholders)
            ORDER BY a.Groupe ASC, p.sort ASC, a.tri ASC
        ";

        $prepared_sql = $this->wpdb->prepare($sql, ...$ids);
        $articles = $this->wpdb->get_results($prepared_sql);

        foreach ($articles as &$article) {
            $article->master_articles = $article->hubspot_deal_id ? $this->get_article_and_group($article->hubspot_deal_id) : [];

            if (intval($article->sales_price) === 0 || empty($article->sales_price)) {
                $article->sales_price = apply_filters('ispag_calculate_sales_price', $article->Id, 'default');
            }

            $article->image = plugin_dir_url(__FILE__) . "../assets/img/placeholder.webp";
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
                    $article->Description = apply_filters('ispag_get_welding_description', $article->Article, $article->IdArticleStandard);
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
        $sql = "
            SELECT 
                a.*,
                p.sort AS prestation_sort,
                p.prestation,
                f.Fournisseur AS fournisseur_nom
            FROM {$this->table_articles} a
            LEFT JOIN {$this->table_prestations} p ON p.type = a.Type
            LEFT JOIN {$this->table_fournisseurs} f ON f.Id = a.IdFournisseur
            WHERE a.Id = %d
            ORDER BY a.Groupe ASC, p.sort ASC, a.tri ASC
        ";

       $prepared_sql = $this->wpdb->prepare($sql, $article_id);
       $article = $this->wpdb->get_row($prepared_sql);

        if ($article && isset($article->hubspot_deal_id)) {
            $article->master_articles = $this->get_article_and_group($article->hubspot_deal_id);
        } else {
            $article->master_articles = [];
        }

        // Si le prix de vente = 0 ou null alors on va le calculer
        if(intval($article->sales_price) === 0 || empty($article->sales_price)){
            $article->sales_price = apply_filters('ispag_calculate_sales_price', $article->Id, 'default');
        }

        $article->image = plugin_dir_url(__FILE__) . "../assets/img/placeholder.webp";
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
            $article->Description = apply_filters('ispag_get_welding_description', $article->Article, $article->IdArticleStandard);
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
                SELECT DISTINCT f.Id as supplier_id, f.Fournisseur as supplier_name
                FROM $table_purchase ap
                INNER JOIN $table_standard a ON ap.article_id = a.Id
                INNER JOIN {$this->wpdb->prefix}achats_fournisseurs f ON ap.supplier_id = f.Id
                WHERE a.TypeArticle = %d
                ORDER BY f.Fournisseur ASC
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
        $sql = $this->wpdb->prepare("
            SELECT a.TitreArticle, a.description_ispag, a.sales_price, f.Fournisseur, a.Id
            FROM {$this->table_article} a
            LEFT JOIN {$this->table_article_purchase} ap ON ap.article_id = a.Id
            LEFT JOIN {$this->table_fournisseurs} f ON f.Id = ap.supplier_id
            WHERE a.Id = %s AND a.TypeArticle = %d
        ", $title, $type);

        $results = $this->wpdb->get_results($sql);
        if (empty($results)) {
            return null;
        }

        // On prend les infos générales depuis la première ligne
        $article_info = (object) [
            'TitreArticle' => apply_filters('ispag_get_insulation_title', $results[0]->TitreArticle, intval($results[0]->Id)),
            'description_ispag' => html_entity_decode(apply_filters('ispag_get_insulation_description', $results[0]->description_ispag, $results[0]->IdArticleStandard)),
            'sales_price' => $results[0]->sales_price,
            'Id_article_standard' => $results[0]->Id,
            'suppliers' => [],
        ];

        // On récupère tous les fournisseurs uniques
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




}
