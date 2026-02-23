<?php
defined('ABSPATH') or die();

class ISPAG_Projet_Repository {

    protected $table_projects;
    protected $table_details;
    protected $table_users;
    protected $table_fournisseurs;
    protected  $table_prestations;
    protected $table_companies;
    protected $wpdb;
    private $only_active = false;
    protected static $instance = null;
    private static $cache_projects = [];

    const META_COMPANY_CITY         = 'ispag_company_city';


    public function __construct($only_active = false) {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->table_projects = $wpdb->prefix . 'achats_liste_commande';
        $this->table_details = $wpdb->prefix . 'achats_details_commande';
        $this->table_companies    = $wpdb->prefix . 'ispag_companies';
        $this->table_users = $wpdb->prefix . 'users';
        $this->table_fournisseurs = $wpdb->prefix . 'achats_fournisseurs';
        $this->table_prestations = $wpdb->prefix . 'achats_type_prestations';
        $this->only_active = $only_active;

    }

    public static function init() {
        if (self::$instance === null) {
            self::$instance = new self(); 
        }
        add_filter('ispag_get_projects_or_offers', [self::$instance, 'filter_get_projects_or_offers'], 10, 7);
        add_filter('ispag_get_project_by_deal_id', [self::$instance, 'get_project_by_deal_id'], 10, 2);
        add_filter('ispag_get_projects_by_deal_ids', [self::$instance, 'get_projects_by_deal_ids'], 10, 2);
        add_action('ispag_delete_project_whith_deal_id', [self::$instance, 'delete_project_whith_deal_id'],10,2);

    }
    public function delete_project_whith_deal_id($html, $deal_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'achats_liste_commande';
        $wpdb->delete($table, ['hubspot_deal_id' => $deal_id]);
    }   

    public function get_projects_by_deal_ids($html, array $deal_ids): array {
        if (empty($deal_ids)) return [];

        // Sécurité : cast en int
        $deal_ids = array_map('intval', $deal_ids);
        $placeholders = implode(',', array_fill(0, count($deal_ids), '%d'));

        // Récupération des projets
        $query = "
            SELECT p.*, 
            ing.company_name AS ingenieur_projet
            FROM {$this->table_projects} p
            LEFT JOIN {$this->table_fournisseurs} ing ON ing.viag_id = p.ingenieur_id
            WHERE hubspot_deal_id IN ($placeholders)
        ";
        $projects = $this->wpdb->get_results($this->wpdb->prepare($query, ...$deal_ids));

        $result = [];
        foreach ($projects as $project) {
            $deal_id = (int)$project->hubspot_deal_id;
            $project->contact_name = $this->get_contact_names($project->AssociatedContactIDs);
            $project->get_contact_local = $this->get_contact_local($project->AssociatedContactIDs);
            $project->nom_entreprise = self::get_company_name_from_id($project->AssociatedCompanyID);
            $base_url = trailingslashit(get_site_url()) . 'liste-des-projets/';
            $base_url_dev = trailingslashit(get_site_url()) . 'details-du-projet/';
            $base_purchase_url = trailingslashit(get_site_url()) . 'liste-des-achats/';

            $project->project_url = esc_url(add_query_arg('deal_id', $deal_id, $base_url_dev));
            $project->project_url_dev = esc_url(add_query_arg('deal_id', $deal_id, $base_url_dev));
            $project->purchase_url = esc_url(add_query_arg(['search' => $deal_id], $base_purchase_url));

            $result[$deal_id] = $project;
        }

        return $result;
    }

    
    public function get_project_by_deal_id($html, $deal_id) {
        // 1. SÉCURISATION
        if (is_object($deal_id) && isset($deal_id->hubspot_deal_id)) {
            $deal_id = $deal_id->hubspot_deal_id;
        }
        $deal_id = (int) $deal_id;
        if ($deal_id <= 0) return null;

        // Si le projet est déjà chargé en mémoire, on le retourne direct !
        if (isset(self::$cache_projects[$deal_id])) {
            return self::$cache_projects[$deal_id];
        }

        global $wpdb;
        $table_users = $wpdb->prefix . 'users';

        // 2. REQUÊTE SQL "ULTRA" (Jointures Entreprises + Ingénieur + Contacts)
        $meta_city_key = ISPAG_Crm_Company_Constants::META_COMPANY_CITY;
        $sql = $wpdb->prepare("
            SELECT 
                p.*, 
                COALESCE(NULLIF(c.company_name, ''), NULLIF(f.Fournisseur, ''), 'N/C') as nom_entreprise,
                COALESCE(NULLIF(f.Ville, ''), 'N/C') as company_city,
                COALESCE(NULLIF(cing.company_name, ''), NULLIF(ing.Fournisseur, ''), 'N/C') as ingenieur_projet,
                -- On récupère tous les noms des contacts séparés par des virgules
                (
                    SELECT GROUP_CONCAT(display_name SEPARATOR ', ') 
                    FROM $table_users 
                    WHERE FIND_IN_SET(ID, REPLACE(p.AssociatedContactIDs, ' ', ''))
                ) as contact_names_combined,
                 COALESCE(
                    NULLIF(pm_city.meta_value, ''), 
                    NULLIF(f.Ville, ''), 
                    'N/C'
                ) as company_city
            FROM {$this->table_projects} p 
            LEFT JOIN {$this->table_companies} c ON c.viag_id = p.AssociatedCompanyID
            LEFT JOIN {$this->table_companies} cing ON cing.viag_id = p.ingenieur_id
            LEFT JOIN {$this->table_fournisseurs} f ON f.viag_id = p.AssociatedCompanyID
            LEFT JOIN {$this->table_fournisseurs} ing ON ing.viag_id = p.ingenieur_id 
            LEFT JOIN {$wpdb->postmeta} pm_city ON (pm_city.post_id = c.viag_id AND pm_city.meta_key = '$meta_city_key')
            WHERE p.hubspot_deal_id = %d 
            LIMIT 1
        ", $deal_id);

        // error_log("ISPAG DEBUG SQL : " . $sql);

        $project = $wpdb->get_row($sql);
        if (!$project) return null;

        // 3. AFFECTATION DES DONNÉES CONTACTS
        // Plus besoin de get_contact_names, les noms sont déjà là
        $project->contact_name = $project->contact_names_combined ?: 'N/C';
        
        // Si get_contact_local a une logique métier complexe (ex: stockage spécifique), 
        // garde-le, sinon on peut aussi l'intégrer si c'est juste de la méta user.
        // $project->get_contact_local = $this->get_contact_local($project->AssociatedContactIDs ?? '');
        $project->get_contact_local = 'fr_FR';

        // 4. LOGIQUE ASP RAPIDE
        $project->project_as_asp = (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT 1 FROM {$this->table_details} WHERE hubspot_deal_id = %d AND Type = 1 LIMIT 1", 
            $deal_id
        ));

        // 5. STATUS ET SUIVI
        $project->is_qotation = (isset($project->isQotation) && $project->isQotation == 1);
        
        $suivi_manager = new ISPAG_Projet_Suivi();
        $next_phases = $suivi_manager->preload_next_phases([$deal_id], $project->is_qotation);
        $project->next_phase = $next_phases[$deal_id] ?? null;

        // 6. CALCULS ET URLS
        $project->total_amount = self::ispag_calculate_deal_total_sales($deal_id);
        // $project->total_amount = 0;
        
        $site_url = trailingslashit(get_site_url());
        $project->project_url = $site_url . 'details-du-projet/?deal_id=' . $deal_id;
        $project->purchase_url = $site_url . 'liste-des-achats/?search=' . $deal_id;
        $project->is_project_owner = self::is_user_project_owner($project, get_current_user_id());
        

        // On stocke dans le cache avant de retourner
        self::$cache_projects[$deal_id] = $project;

        return $project;
    }
 
    public function filter_get_projects_or_offers($html, $is_quotation = null, $user_id = null, $all = false, $search = '', $offset = 0, $limit = 50){
        return $this->get_projects_or_offers($is_quotation, $user_id, $all, $search , $offset, $limit );
    }
    
    public function get_projects_or_offers($is_quotation = null, $user_id = null, $all = false, $search = '', $offset = 0, $limit = 50) {
        global $wpdb; // Utilisation de global si $this->wpdb n'est pas défini dans votre classe

        // --- SÉCURISATION DES TYPES (Anti-Object Warning) ---
        // Si user_id est un objet (ex: WP_User), on extrait l'ID, sinon on force l'entier
        if ($user_id !== null) {
            if (is_object($user_id) && isset($user_id->ID)) {
                $user_id = (int) $user_id->ID;
            } else {
                $user_id = (int) $user_id;
            }
        }

        $limit = (int) $limit;
        $offset = (int) $offset;
        $search = (string) $search;
        $all = (bool) $all;

        $this->only_active = ($search) ? false : true;
        $query_params = [];

        // --- CONSTRUCTION DE LA REQUÊTE ---
        $query = "
            SELECT 
                p.*, 
                SUM(d.sales_price * d.Qty) AS prix_total, 
                (SUM(d.sales_price * d.Qty) - d.discount * SUM(d.sales_price * d.Qty) / 100) AS prix_net,
                ing.company_name AS ingenieur_projet
            FROM {$this->table_projects} p
            LEFT JOIN {$this->table_details} d ON p.hubspot_deal_id = d.hubspot_deal_id
            LEFT JOIN {$this->table_fournisseurs} f ON f.Id = p.AssociatedCompanyID
            LEFT JOIN {$this->table_fournisseurs} ing ON ing.Id = p.ingenieur_id
            LEFT JOIN {$this->table_users} u ON u.Id = p.AssociatedContactIDs
            WHERE ";

        // Filtrage Offre / Projet / Tout
        if ($all && $user_id) {
            $query .= " (FIND_IN_SET(%d, REPLACE(p.AssociatedContactIDs, ';', ',')) > 0 OR p.Abonne LIKE %s)";
            $query_params[] = $user_id;
            $query_params[] = '%;' . $user_id . ';%';
            $this->only_active = false;
        } elseif ($is_quotation === true) {
            $query .= "p.isQotation = 1";
        } elseif ($is_quotation === false) {
            $query .= "(p.isQotation IS NULL OR p.isQotation = 0)";
        } else {
            $query .= "1 = 1";
        }

        if ($this->only_active) {
            $query .= " AND p.project_status = 1";
        }

        // Si utilisateur limité (non "all")
        if (!$all && $user_id) {
            $query .= " AND (FIND_IN_SET(%d, REPLACE(p.AssociatedContactIDs, ';', ',')) > 0 OR p.Abonne LIKE %s)";
            $query_params[] = $user_id;
            $query_params[] = '%;' . $user_id . ';%';
        }

        // Filtrage par recherche
        if (!empty($search)) {
            $query .= " AND (p.ObjetCommande LIKE %s OR u.display_name LIKE %s OR f.Fournisseur LIKE %s OR p.hubspot_deal_id LIKE %s)";
            $like = '%' . $search . '%';
            $query_params[] = $like;
            $query_params[] = $like;
            $query_params[] = $like;
            $query_params[] = $like;
        }

        $query .= " GROUP BY p.Id ORDER BY p.TimestampDateCommande DESC";

        if ($limit > 0) {
            $query .= " LIMIT %d OFFSET %d";
            $query_params[] = $limit;
            $query_params[] = $offset;
        }

        // --- EXÉCUTION ---
        $prepared = $wpdb->prepare($query, ...$query_params);
        $results = $wpdb->get_results($prepared);

        if (!$results) {
            return ['results' => [], 'query' => $query];
        }

        // --- BOUCLE D'ENRICHISSEMENT SÉCURISÉE ---
        foreach ($results as $index => $p) {
            
            // 1. On détermine l'ID de manière sûre avant toute manipulation
            $safe_id = 0;
            if (isset($p->hubspot_deal_id)) {
                if (is_scalar($p->hubspot_deal_id)) {
                    $safe_id = (int) $p->hubspot_deal_id;
                } elseif (is_object($p->hubspot_deal_id) && isset($p->hubspot_deal_id->hubspot_deal_id)) {
                    // C'est ici que l'objet se mord la queue, on récupère l'ID à l'intérieur
                    $safe_id = (int) $p->hubspot_deal_id->hubspot_deal_id;
                }
            }

            if ($safe_id <= 0) continue;

            // 2. On appelle les sous-fonctions avec l'ID numérique uniquement
            // $full = $this->get_project_by_deal_id('', $safe_id);
            $p->total_amount = self::ispag_calculate_deal_total_sales($safe_id);

            // if ($full) {
            //     // 3. Fusion des données
            //     // On transforme en tableau pour fusionner proprement
            //     $merged = array_merge((array)$p, (array)$full);
                
            //     // 4. PROTECTION CRITIQUE : on force l'ID à rester un ENTIER
            //     // Cela empêche que hubspot_deal_id devienne l'objet complet au prochain accès
            //     $merged['hubspot_deal_id'] = $safe_id;
                
            //     $results[$index] = (object) $merged;
            // } else {
            //     // Si get_project_by_deal_id a échoué, on s'assure au moins que l'ID est propre
            //     $p->hubspot_deal_id = $safe_id;
            // }
        }

        return [
            'results' => $results,
            'query'   => $query,
            'limit'   => $limit,
            'offset'  => $offset
        ];
    }


    public function preload_delivery_data($hubspot_deal_ids) {
        global $wpdb;

        $placeholders = implode(',', array_fill(0, count($hubspot_deal_ids), '%d'));

        $results = $wpdb->get_results(
            $wpdb->prepare("
                SELECT 
                    td.hubspot_deal_id,
                    td.TimestampDateDeLivraison,
                    td.TimestampDateDeLivraisonFin,
                    td.Livre,
                    ttp.prestation
                FROM {$this->table_details} td
                LEFT JOIN {$this->table_prestations} ttp ON ttp.Id = td.Type
                WHERE td.hubspot_deal_id IN ($placeholders)
            ", ...$hubspot_deal_ids),
            ARRAY_A
        );

        // Réorganiser les données pour un accès facile
        $grouped = [];
        foreach ($results as $row) {
            $deal_id = $row['hubspot_deal_id'];
            $type = $row['prestation'];
            $grouped[$deal_id][$type][] = $row;
        }

        return $grouped;
    }

    

    /**
     * Récupère noms des contacts depuis la string ;id1;id2; ou CSV.
     */
    protected function get_contact_names($contact_ids) {
        $ids = [];

        // Nettoyer et splitter IDs (support ;id;id; ou CSV)
        $contact_ids = trim($contact_ids);
        if (empty($contact_ids)) {
            return '';
        }
        if (strpos($contact_ids, ';') !== false) {
            $parts = explode(';', $contact_ids);
            foreach ($parts as $part) {
                $id = intval($part);
                if ($id > 0) {
                    $ids[] = $id;
                }
            }
        } else {
            // CSV classique
            $parts = explode(',', $contact_ids);
            foreach ($parts as $part) {
                $id = intval($part);
                if ($id > 0) {
                    $ids[] = $id;
                }
            }
        }
 
        if (empty($ids)) return '';

        global $wpdb;

        // Récupérer display_name des WP users
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $sql = "SELECT ID, display_name FROM {$wpdb->users} WHERE ID IN ($placeholders)";
        $users = $wpdb->get_results($wpdb->prepare($sql, ...$ids));

        if (!$users) return '';

        $names = array_map(fn($u) => $u->display_name, $users);

        return implode(', ', $names);
    }

    private static function get_company_name_from_id($company_id) {
        global $wpdb;
        $company_id = intval($company_id);
        if (!$company_id) return '';

        $table_fournisseurs = $wpdb->prefix . 'ispag_companies';

        $sql = "SELECT company_name AS Fournisseur FROM {$table_fournisseurs} WHERE viag_id = %d LIMIT 1";

        return $wpdb->get_var($wpdb->prepare($sql, $company_id)) ?? '';
    }



    public static function is_user_project_owner($project = null, $user_id = null) {
        // error_log('START render_article_block' . print_r($project, true));
        // if (empty($deal_id)) return false;

        // $project_repo = new ISPAG_Projet_Repository();
        // $project = $project_repo->get_project_by_deal_id('', $deal_id);

        if (!$project || empty($project->AssociatedContactIDs)) {
            return false;
        }

        if (!$user_id) {
            $user_id = get_current_user_id();
        }

        if (!empty($project->AssociatedContactIDs)) {
            $contact_ids = array_map('intval', array_filter(preg_split('/[;,]+/', $project->AssociatedContactIDs)));
        }

        return in_array((int)$user_id, $contact_ids, true);
    }

    public function check_if_is_qotation($deal_id){
        $sql = "SELECT isQotation FROM {$this->table_projects} WHERE hubspot_deal_id  = %d LIMIT 1";

        return $this->wpdb->get_var($this->wpdb->prepare($sql, $deal_id)) ?? '';
    }

    public function get_contact_local($user_id = null){
        if(empty($user_id)) return '';
        return get_user_locale($user_id);
    }

    /**
     * Calcule le prix de vente total d'un projet basé sur les détails des commandes.
     * * @param mixed $deal_id L'ID du deal (hubspot_deal_id) pour le projet.
     * @return float Le prix de vente total du projet.
     */
    public static function ispag_calculate_deal_total_sales( $deal_id ) {
        global $wpdb;

        // 1. SÉCURISATION DE L'ID (Empêche l'erreur "object" dans les appels SQL liés au filtre)
        if (is_object($deal_id) || is_array($deal_id)) {
            // Optionnel : error_log("ISPAG Debug: deal_id est un objet dans calculate_total_sales");
            return 0.00;
        }

        $deal_id = intval( $deal_id );
        if ( $deal_id <= 0 ) {
            return 0.00;
        }

        // 2. RÉCUPÉRATION DES DONNÉES VIA LE FILTRE
        $projet_article_par_groupe = apply_filters('ispag_get_articles_by_deal', null, $deal_id);
        
        // Si le filtre ne renvoie rien de traitable, on s'arrête proprement
        if ( empty($projet_article_par_groupe) || !is_array($projet_article_par_groupe) ) {
            return 0.00;
        }
        
        $projet_total = 0.00;
        
        // Sécurisation de l'option RPLP (taxe ou multiplicateur)
        $rplp_option = get_option('rplp', 0);
        $rplp_multiplier = 1 + (floatval($rplp_option) / 100);

        // 3. BOUCLE SUR LES GROUPES D'ARTICLES
        foreach ($projet_article_par_groupe as $groupe_nom => $articles_du_groupe) {

            // On vérifie que le contenu du groupe est bien itérable
            if ( empty($articles_du_groupe) || (!is_array($articles_du_groupe) && !is_object($articles_du_groupe)) ) {
                continue;
            }

            foreach ($articles_du_groupe as $article) {
                $prix_ligne = 0.00;
                $qty = 0;

                // 4. EXTRACTION SÉCURISÉE (Objet ou Tableau)
                if ( is_object($article) ) {
                    $prix_ligne = isset($article->prix_net_calculé) ? floatval($article->prix_net_calculé) : 0.00;
                    $qty        = isset($article->Qty) ? intval($article->Qty) : 0;
                } elseif ( is_array($article) ) {
                    $prix_ligne = isset($article['prix_net_calculé']) ? floatval($article['prix_net_calculé']) : 0.00;
                    $qty        = isset($article['Qty']) ? intval($article['Qty']) : 0;
                }

                // Calcul de la ligne seulement si on a une quantité
                if ($qty > 0) {
                    $projet_total += ($prix_ligne * $qty);
                }
            }
        }

        return (float) ($projet_total * $rplp_multiplier);
    }




    /**
     * Récupère une liste complète, rapide et sans warnings des projets/offres
     * Inclut : Nom du créateur, filtrage par utilisateur (Contact ou Créateur), et Next Step.
     */
    public function get_fast_project_list($is_quotation = false, $user_id = null, $search = '', $offset = 0, $limit = 10) {
        global $wpdb;

        // Définition des noms de tables
        $table_p            = $this->table_projects; 
        $table_details      = $this->table_details;  
        $table_phase_suivi  = $wpdb->prefix . 'achats_suivi_phase_commande';
        $table_phase_def    = $wpdb->prefix . 'achats_slug_phase';
        $table_users        = $wpdb->prefix . 'users';
        $table_companies    = $wpdb->prefix . 'ispag_companies';
        $table_fournisseurs = $wpdb->prefix . 'achats_fournisseurs';

        // 1. Initialisation des clauses WHERE
        $where = ["1=1"];

        // --- FILTRE UTILISATEUR (SÉCURITÉ) ---
        // Si un user_id est passé, on filtre pour qu'il ne voie que ses projets
        if (!empty($user_id)) {
            $where[] = $wpdb->prepare(
                "(FIND_IN_SET(%s, p.AssociatedContactIDs) > 0 OR p.created_by = %d)", 
                $user_id,
                $user_id
            );
        }

        // --- FILTRE DE TYPE (OFFRE vs PROJET) ---
        if ($is_quotation) {
            $where[] = "p.isQotation = 1";
        } else {
            $where[] = "(p.isQotation IS NULL OR p.isQotation = 0)";
            
            // Si pas de recherche, on limite aux projets actifs
            if (empty($search)) {
                $where[] = "p.project_status = 1";
            }
        }

        // --- FILTRE DE RECHERCHE ---
        if (!empty($search)) {
            $where[] = $wpdb->prepare(
                "(p.ObjetCommande LIKE %s OR p.NumCommande LIKE %s)", 
                '%' . $search . '%', 
                '%' . $search . '%'
            );
        }

        $where_str = implode(' AND ', $where);

        // 2. Requête SQL complète
        $sql = "
            SELECT 
                p.hubspot_deal_id, 
                p.ObjetCommande, 
                p.NumCommande,
                p.TimestampDateCommande,
                p.isQotation,
                p.created_by,
                u.display_name as contact_name,
                creator.display_name as creator_name,
                COALESCE(NULLIF(c.company_name, ''), NULLIF(f.Fournisseur, ''), 'N/C') as company_name,
                -- c.company_city, -- RETIRÉ CAR PROVOQUE L'ERREUR SQL
                
                -- NEXT STEP : Jointure sur le dernier état connu non terminé
                ns.TitrePhase as next_step_name,
                ns.Color as next_step_color,
                ns.SlugPhase as next_step_slug,

                -- LIVRAISONS : Agrégation des dates par type
                delivery.delivery_cuve, delivery.is_delivered_cuve,
                delivery.delivery_soudure, delivery.is_delivered_soudure,
                delivery.delivery_iso, delivery.is_delivered_iso

            FROM $table_p p

            -- Jointure pour le contact principal HubSpot
            LEFT JOIN $table_users u ON (
                p.AssociatedContactIDs <> '' 
                AND u.ID = (CASE WHEN p.AssociatedContactIDs REGEXP '^[0-9]+' THEN SUBSTRING_INDEX(p.AssociatedContactIDs, ',', 1) ELSE NULL END)
            )

            -- Jointure pour le créateur du projet dans l'application
            LEFT JOIN $table_users creator ON (p.created_by = creator.ID)

            -- Jointures pour les entreprises (Client ou Fournisseur)
            LEFT JOIN $table_companies c ON (p.AssociatedCompanyID <> '' AND p.AssociatedCompanyID <> '0' AND c.viag_id = p.AssociatedCompanyID)
            LEFT JOIN $table_fournisseurs f ON (p.AssociatedCompanyID <> '' AND p.AssociatedCompanyID <> '0' AND f.Id = p.AssociatedCompanyID)

            -- NEXT STEP : Identification de la phase actuelle
            LEFT JOIN (
                SELECT fs1.hubspot_deal_id, s1.TitrePhase, s1.Color, s1.SlugPhase
                FROM $table_phase_suivi fs1
                INNER JOIN $table_phase_def s1 ON fs1.slug_phase = s1.SlugPhase
                WHERE fs1.id IN (
                    SELECT MAX(id) 
                    FROM $table_phase_suivi 
                    GROUP BY hubspot_deal_id, slug_phase
                )
                AND (fs1.status_id NOT IN (1, 5) OR fs1.status_id IS NULL)
                AND (s1.display_on_qotation = " . ($is_quotation ? '1' : 's1.display_on_qotation') . ")
                GROUP BY fs1.hubspot_deal_id 
                ORDER BY s1.Ordre ASC
            ) as ns ON ns.hubspot_deal_id = p.hubspot_deal_id

            -- LIVRAISONS : Groupement des détails par type (1=Cuve, 3=Soudure, 2=ISO)
            LEFT JOIN (
                SELECT 
                    hubspot_deal_id,
                    MIN(CASE WHEN Type = 1 THEN TimestampDateDeLivraisonFin END) as delivery_cuve,
                    MAX(CASE WHEN Type = 1 THEN IF(COALESCE(Livre, 0) > 0, 1, 0) ELSE 0 END) as is_delivered_cuve,
                    MIN(CASE WHEN Type = 3 THEN TimestampDateDeLivraisonFin END) as delivery_soudure,
                    MAX(CASE WHEN Type = 3 THEN IF(COALESCE(Livre, 0) > 0, 1, 0) ELSE 0 END) as is_delivered_soudure,
                    MIN(CASE WHEN Type = 2 THEN TimestampDateDeLivraisonFin END) as delivery_iso,
                    MAX(CASE WHEN Type = 2 THEN IF(COALESCE(Livre, 0) > 0, 1, 0) ELSE 0 END) as is_delivered_iso
                FROM $table_details
                GROUP BY hubspot_deal_id
            ) as delivery ON delivery.hubspot_deal_id = p.hubspot_deal_id

            WHERE $where_str
            ORDER BY p.TimestampDateCommande DESC
            LIMIT %d OFFSET %d
        ";

        // Préparation et exécution
        $final_query = $wpdb->prepare($sql, $limit, $offset);
        $results = $wpdb->get_results($final_query);

        // 3. Post-traitement pour l'objet next_phase (compatibilité frontend)
        if (!empty($results)) {
            foreach ($results as &$project) {
                $project->next_phase = $project->next_step_name ? (object) [
                    'TitrePhase' => $project->next_step_name,
                    'Color'      => $project->next_step_color,
                    'SlugPhase'  => $project->next_step_slug
                ] : null;
            }
        }

        return [
            'results' => $results,
            'limit'   => $limit,
            'offset'  => $offset
        ];
    }
}
