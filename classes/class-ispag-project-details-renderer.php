<?php 
class ISPAG_Project_Details_Renderer {

    public static function display($deal_id, $project) {
        $details_repo = new ISPAG_Project_Details_Repository(); 
        $infos = $details_repo->get_infos_livraison($deal_id);

        // Cette classe doit correspondre exactement au CSS Grid
        echo '<div class="ispag-detail-section">';
            self::render_bloc_project_info($project);
            self::render_bloc_livraison($infos);
            if (current_user_can('manage_order')) {
                self::render_bloc_soumission($project);
            }
        echo '</div>';
    }

    private static function render_bloc_project_info($project) {
        $p = $project;
        $ispag_app_base_url = 'https://app.ispag-asp.ch/contact/';
        $deal_id = (int) $p->hubspot_deal_id;
        $can_edit = current_user_can('manage_order');
        $bgcolor = !empty($p->next_phase->Color) ? esc_attr($p->next_phase->Color) : '#ccc';

        echo '<div class="ispag-box">';
            echo '<h3><span><i class="fas fa-info-circle"></i> ' . __('Project informations', 'creation-reservoir') . '</span></h3>';
            
            echo '<div class="ispag-box-content">';
                echo '<p><strong>Status :</strong> <span class="ispag-state-badge" style="background-color:' . $bgcolor . ';">' . esc_html($p->next_phase->TitrePhase ?? 'Non défini') . '</span></p>';

                $champs = [
                    'NumCommande'       => __('Order number', 'creation-reservoir'),
                    'customer_order_id' => __('Customer order ID', 'creation-reservoir'),
                ];

                foreach ($champs as $champ => $label) {
                    $val = $p->$champ ?? '';
                    echo '<p><strong>' . esc_html($label) . ' :</strong> ';
                    if ($can_edit) {
                        echo '<span class="ispag-inline-edit" data-name="'.esc_attr($champ).'" data-value="'.esc_attr($val).'" data-deal="'.esc_attr($deal_id).'" data-source="project">';
                        echo esc_html($val ?: '---') . ' <i class="fas fa-pen edit-icon"></i></span>';
                    } else {
                        echo esc_html($val ?: '-');
                    }
                    echo '</p>';
                }

                $contact_app_url = esc_url(add_query_arg('user_id', $p->AssociatedContactIDs, $ispag_app_base_url));
                echo '<p><strong>' . __('Date', 'creation-reservoir') . ' :</strong> ' . date('d.m.Y', $p->TimestampDateCommande) . '</p>';
                echo '<p><strong>' . __('Contact', 'creation-reservoir') . ' :</strong> <a href="' . $contact_app_url . '" target="_blank" class="ispag-link">' . esc_html($p->contact_name) . ' <i class="fas fa-external-link-alt"></i></a></p>';
                echo '<p><strong>' . __('Company', 'creation-reservoir') . ' :</strong> ' . esc_html($p->nom_entreprise) . '</p>';
            echo '</div>';
        echo '</div>';
    }

    private static function render_bloc_livraison($infos) {
        echo '<div class="ispag-box">';
            echo '<h3>';
                echo '<span>' . __('Delivery', 'creation-reservoir') . '</span>';
                echo '<button type="button" class="ispag-btn-copy-description button" data-target="#delivery-info-copy">📋</button>';
            echo '</h3>';

            $champs = [
                'AdresseDeLivraison' => __('Adress', 'creation-reservoir'),
                'DeliveryAdresse2'   => __('Complement', 'creation-reservoir'),
                'NIP'        => __('Postal code', 'creation-reservoir'),
                'City'               => __('City', 'creation-reservoir'),
                'PersonneContact'    => __('Contact', 'creation-reservoir'),
                'num_tel_contact'    => __('Phone', 'creation-reservoir'),
            ];

            $copie_ligne1 = [];
            $copie_ligne2 = '';

            echo '<div id="delivery-info-text">';
            foreach ($champs as $champ => $label) {
                $val = $infos->$champ ?? '';
                echo '<p><strong>' . esc_html($label) . ' :</strong> ';
                if (current_user_can('manage_order') || ISPAG_Projet_Repository::is_user_project_owner($infos->hubspot_deal_id)) {
                    echo '<span class="ispag-inline-edit" data-name="' . esc_attr($champ) . '" data-value="' . esc_attr($val) . '" data-deal="' . esc_attr($infos->hubspot_deal_id) . '" data-source="delivery">';
                    echo esc_html($val ?: '---') . ' <span class="edit-icon">✏️</span></span>';
                } else {
                    echo esc_html($val ?: '-');
                }
                echo '</p>';

                if (in_array($champ, ['AdresseDeLivraison', 'DeliveryAdresse2', 'NIP', 'City'])) {
                    if(!empty($val)) $copie_ligne1[] = $val;
                } elseif ($champ === 'PersonneContact') {
                    $copie_ligne2 = $val;
                } elseif ($champ === 'num_tel_contact' && !empty($val)) {
                    $copie_ligne2 .= ': ' . $val;
                }
            }
            echo '</div>';

            echo '<pre id="delivery-info-copy" style="display:none;">' .
                esc_html(implode("\n", [implode(" ", array_filter($copie_ligne1)), $copie_ligne2])) .
            '</pre>';
        echo '</div>';
    }

    private static function render_bloc_soumission($project) {
        $deal_id = (int) $project->hubspot_deal_id;
        echo '<div class="ispag-box">';
            echo '<h3><span><i class="fas fa-handshake"></i> ' . __('Submission information', 'creation-reservoir') . '</span></h3>';
            echo '<div class="ispag-box-content">';
                
                $champs = [
                    'ingenieur_id' => __('Engineer', 'creation-reservoir'), 
                    'EnSoumission' => __('Competitor', 'creation-reservoir')
                ];

                foreach ($champs as $champ => $label) {
                    $val = $project->$champ ?? '';
                    $display_val = $val;

                    // LOGIQUE SPECIFIQUE POUR L'INGÉNIEUR
                    if ($champ === 'ingenieur_id' && !empty($val)) {
                        // On cherche le nom correspondant à l'ID
                        $display_val = self::get_company_name_by_id($val);
                    }

                    echo '<p><strong>' . esc_html($label) . ' :</strong> ';
                    
                    // On garde l'ID dans data-value pour le Select2, mais on affiche le nom (display_val)
                    echo '<span class="ispag-inline-edit" 
                                data-name="'.esc_attr($champ).'" 
                                data-value="'.esc_attr($val).'" 
                                data-deal="'.esc_attr($deal_id).'" 
                                data-source="project">';
                    echo esc_html($display_val ?: '---') . ' <i class="fas fa-pen edit-icon"></i></span>';
                    echo '</p>';
                }
                
                self::render_ingenieur_datalist();
            echo '</div>';
        echo '</div>';
    }

    /**
     * Récupère le nom de l'entreprise via son ID (viag_id)
     */
    private static function get_company_name_by_id($viag_id) {
        global $wpdb;
        $table_name = ISPAG_Crm_Company_Constants::TABLE_NAME;
        
        // On suppose que viag_id est la colonne de référence dans ta table
        $name = $wpdb->get_var($wpdb->prepare(
            "SELECT company_name FROM $table_name WHERE viag_id = %s LIMIT 1",
            $viag_id
        ));

        return $name ?: $viag_id; // Retourne l'ID si le nom n'est pas trouvé
    }

    private static function render_ingenieur_datalist() {
        $ingenieurs = self::get_ingenieur_names_for_datalist();
        echo '<datalist id="ispag-fournisseurs-datalist">';
        foreach ($ingenieurs as $nom) echo '<option value="' . esc_attr($nom) . '">';
        echo '</datalist>';
    }

    private static function get_ingenieur_names_for_datalist() {
        global $wpdb;
        $table_name = ISPAG_Crm_Company_Constants::TABLE_NAME;
        return $wpdb->get_col("SELECT DISTINCT company_name FROM $table_name WHERE isSupplier = 1 ORDER BY company_name ASC") ?: [];
    }
}