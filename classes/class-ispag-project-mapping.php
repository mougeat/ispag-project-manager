<?php

// Fichier: classes/class-ispag-project-mapping.php

if ( ! class_exists( 'ISPAG_Project_Mapping' ) ) :

/**
 * Classe de service pour l'association entre un Deal CRM (HubSpot) et un Projet/Commande ISPAG.
 */
class ISPAG_Project_Mapping {

    /**
     * @var wpdb
     */
    private $wpdb;

    /**
     * Nom de la table des projets/commandes ISPAG.
     * @var string
     */
    private $table_name = 'wor9711_achats_liste_commande';

    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
        // La table est fixée, mais on peut ajouter le préfixe si elle n'est pas absolue
        // $this->table_name = $this->wpdb->prefix . 'achats_liste_commande'; // Si le préfixe était wp_
    }
    
    /**
     * Tente de trouver le hubspot_deal_id correspondant à un Projet/Commande ISPAG
     * en utilisant le Nom de l'ObjetCommande.
     *
     * @param string $deal_name Le nom du deal CRM (ObjetCommande dans la table ISPAG).
     * @return int|null L'ID du deal HubSpot (hubspot_deal_id) trouvé, ou null si non trouvé.
     */
    public function get_hubspot_deal_id_by_project_name( $deal_name ) {
        if ( empty( $deal_name ) ) {
            return null;
        }

        // Utiliser LIKE pour plus de flexibilité, mais %s pour l'échappement
        $sql = $this->wpdb->prepare(
            "SELECT hubspot_deal_id 
             FROM {$this->table_name}
             WHERE ObjetCommande = %s
             LIMIT 1",
            $deal_name
        );

        // get_var retourne la première colonne (hubspot_deal_id) de la première ligne
        $hubspot_id = $this->wpdb->get_var( $sql );

        // S'assurer que le résultat est un entier valide
        return ( $hubspot_id ) ? absint( $hubspot_id ) : null;
    }

    /**
     * Tente de trouver un Projet/Commande ISPAG (ligne complète) en utilisant l'ID du Deal HubSpot.
     *
     * @param int $hubspot_deal_id L'ID du deal HubSpot.
     * @return object|null L'objet de données brutes du Projet/Commande, ou null.
     */
    public function get_project_by_hubspot_deal_id( $hubspot_deal_id ) {
        $hubspot_deal_id = absint( $hubspot_deal_id );
        if ( $hubspot_deal_id === 0 ) {
            return null;
        }

        $sql = $this->wpdb->prepare(
            "SELECT * FROM {$this->table_name}
             WHERE hubspot_deal_id = %d
             LIMIT 1",
            $hubspot_deal_id
        );

        return $this->wpdb->get_row( $sql );
    }

    // ==========================================================
    // MÉTHODES DE MAPPING (Pour mettre à jour le lien)
    // ==========================================================

    /**
     * Met à jour un Projet/Commande ISPAG en ajoutant (ou mettant à jour) son lien vers le Deal HubSpot.
     *
     * Le mapping se base sur le Nom de la Commande (ObjetCommande)
     * car la table ISPAG pourrait ne pas avoir l'ID HubSpot initialement.
     *
     * @param string $deal_name Le nom du Deal CRM à lier.
     * @param int $hubspot_deal_id L'ID du Deal HubSpot à enregistrer.
     * @return bool Vrai si au moins une ligne a été mise à jour.
     */
    public function map_deal_to_project( $deal_name, $hubspot_deal_id ) {
        $hubspot_deal_id = absint( $hubspot_deal_id );
        $deal_name = sanitize_text_field( $deal_name );
        
        if ( $hubspot_deal_id === 0 || empty( $deal_name ) ) {
            return false;
        }
        // On retourne le hubspot_deal_id
        return ( $hubspot_deal_id );
    }

}

endif;