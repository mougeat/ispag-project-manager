<?php

class ISPAG_Purchase_Request_Generator {

    protected $wpdb;
    protected $deal_id;

    protected $table_articles;
    protected $table_commandes;
    protected $table_liste_commandes;
    protected static $instance = null;

    public function __construct($deal_id) {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->deal_id = intval($deal_id);

        // noms de tables
        $this->table_articles = 'wor9711_achats_details_commande';
        $this->table_commandes = 'wor9711_achats_articles_cmd_fournisseurs';
        $this->table_liste_commandes = 'wor9711_achats_commande_liste_fournisseurs';
    }

    public static function init() {

        if (self::$instance === null) {
            self::$instance = new self(0); // ou passe un vrai $deal_id si nécessaire
        }
        
        add_action('wp_ajax_ispag_generate_purchase_requests', function () {
            if (!current_user_can('manage_order')) {
                wp_send_json_error(['message' => __('Not allowed', 'creation-reservoir')]);
            }

            $deal_id = isset($_POST['deal_id']) ? intval($_POST['deal_id']) : 0;
            if (!$deal_id) {
                wp_send_json_error(['message' => __('Deal ID missing', 'creation-reservoir')]);
            }

            $generator = new ISPAG_Purchase_Request_Generator($deal_id);
            $generate_po = $generator->generate_purchase_requests();

            $articles_list = apply_filters('ispag_reload_article_list', $deal_id, null);

            wp_send_json_success(['message' => __('Order generated', 'creation-reservoir'), 'generate_po' => $generate_po, 'articles_list' => $articles_list]);
        });

        add_action('ispag_generate_purchase_requests', [self::$instance, 'action_generate_purchase_requests'], 10, 2);

    }

    public function action_generate_purchase_requests($html, $deal_id){
        if (!current_user_can('manage_order')) {
// \1('❌ Non autorisé');
            return false;  // Ne pas arrêter le script ici
        }

        if (!$deal_id) {
// \1('❌ deal_id manquant');
            return false;
        }

        $generator = new ISPAG_Purchase_Request_Generator($deal_id);
        $generate_po = $generator->generate_purchase_requests();

        $articles_list = apply_filters('ispag_reload_article_list', $deal_id, null);

        // error_log('Commandes générées');

        // Ne pas faire wp_send_json_success ici
        return ['generate_po' => $generate_po, 'articles_list' => $articles_list];
    }


    public function generate_purchase_requests() {
        $articles = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->table_articles} 
                 WHERE hubspot_deal_id = %d AND (DemandeAchatOk IS NULL OR DemandeAchatOk != 1)
                 ORDER BY IdFournisseur",
                $this->deal_id
            )
        );

        //on recupere les ddonnées du projet
        // $project_datas = new ISPAG_Projet_Repository();
        // $datas = $project_datas->get_projects_or_offers('', '', '', $this->deal_id, 0, 1);
        $project = apply_filters('ispag_get_project_by_deal_id', null, $this->deal_id); 
        $commande['Project'] = $project;
        

        $commandes_par_fournisseur = [];

        foreach ($articles as $article) {
            
            $ligne_existante = $this->wpdb->get_row(
                $this->wpdb->prepare(
                    "SELECT * FROM {$this->table_commandes} 
                     WHERE IdCommandeClient = %d",
                    $article->Id
                )
            );

            
            if(intval($project->isQotation) === 1){
                $ref = $project->ObjetCommande;
                $etat = get_option('wpcb_first_qotation_state');
            }
            else{
                $ref = get_option('wpcb_kst') . '/' . $project->NumCommande . ' - ' . $project->ObjetCommande;
                $etat = get_option('wpcb_first_order_state');
            }
            
            if ($ligne_existante) {
                $commande[$article->IdFournisseur] = ['exist'];
                
                $this->wpdb->update(
                    $this->table_commandes,
                    ['UnitPrice' => 0],
                    ['Id' => $ligne_existante->Id],
                    ['%f'],
                    ['%d']
                );

                $this->wpdb->update(
                    $this->table_liste_commandes,
                    array( 
                        'EtatCommande'           => $etat, 
                        'TimestampDateCreation'  => time() // On met toutes les modifs dans le 2ème argument
                    ),
                    array( 'Id' => $ligne_existante->IdCommande ), // Le WHERE (3ème argument)
                    array( '%d', '%d', '%d' ), // Formats des données (Etat et Timestamp sont des entiers)
                    array( '%d' )        // Format du WHERE (l'ID est un entier)
                );
            } else {
                $fournisseur_id = $article->IdFournisseur;
                
                

                if (!isset($commandes_par_fournisseur[$fournisseur_id])) {

                
                    
                    $this->wpdb->insert(
                        $this->table_liste_commandes,
                        [
                            'hubspot_deal_id' => $this->deal_id,
                            'IdFournisseur' => $article->IdFournisseur,
                            'TimestampDateCreation' => time(),
                            'EtatCommande' => $etat,
                            'RefCommande' => $ref,
                            'Abonne' => ';1;'
                        ],
                        ['%d', '%d', '%d', '%d', '%s']
                    );

                    $commande_id = $this->wpdb->insert_id;
                    $commandes_par_fournisseur[$fournisseur_id] = $commande_id;
                } else {
                    $commande_id = $commandes_par_fournisseur[$fournisseur_id];
                    
                }

                
                $this->wpdb->insert(
                    $this->table_commandes,
                    [
                        'IdCommande' => $commande_id,
                        'IdArticleStandard' => $article->IdArticleStandard,
                        'IdCommandeClient' => $article->Id,
                        'RefSurMesure' => $article->Article,
                        'DescSurMesure' => $article->Description,
                        'Qty' => $article->Qty,

                    ],
                    ['%d','%d','%d','%s','%s','%d']
                );
            }


            // ['sales_price' => 0],
            $this->wpdb->update(
                $this->table_articles,
                ['DemandeAchatOk' => 1],
                
                ['Id' => $article->Id],
                ['%d'],
                ['%d']
            );
        }
        return $commande;
    }
}
