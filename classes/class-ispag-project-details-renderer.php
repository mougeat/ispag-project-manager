<?php 
class ISPAG_Project_Details_Renderer {

    public static function display($deal_id, $project) {
        $details_repo = new ISPAG_Project_Details_Repository(); 
        $infos = $details_repo->get_infos_livraison($deal_id);

        ob_start();
        // Cette classe doit correspondre exactement au CSS Grid
        echo '<div class="ispag-detail-section">';
            self::render_bloc_project_info($project);
            self::render_bloc_livraison($infos);
            if (current_user_can('manage_order')) {
                self::render_bloc_soumission($project);
            }
        echo '</div>';
        return ob_get_clean();
    }

    private static function render_bloc_project_info($project) {
        $p = $project;
        $ispag_app_base_url = trailingslashit(get_site_url()) . 'contact/';
        $deal_id = (int) $p->hubspot_deal_id;
        $can_edit = current_user_can('manage_order');

        // error_log('DETAIL PROJECT ================================================================> ' . print_r($project, true));

        //-----------------------------------------------------------------------
        // Création du badge prochaine étape du projet
        //-----------------------------------------------------------------------
        if(current_user_can('manage_order')){
            $context = ISPAG_Project_Phase_Resolver::CONTEXT_INTERNAL;
        }
        else{
            $context = ISPAG_Project_Phase_Resolver::CONTEXT_CLIENT;
        }
        $next = ISPAG_Project_Phase_Resolver::get_next_pending_phase($p->hubspot_deal_id, $context);

        if ($next)
        {
            $phase_title = __($next['phase']->TitrePhase, 'creation-reservoir');
            $badge_color = $next['phase']->Color ?: '#ccc';
        }
        else
        {
            $phase_title = __('Error', 'creation-reservoir');
            $badge_color = '#c80000';
        }

        // $bgcolor = !empty($project->next_phase->Color) ? esc_attr($project->next_phase->Color) : '#ccc';
        $next_step_badge = '<span class="ispag-next-step-badge step-badge" style="color:' . $badge_color . '; border:1px solid ' . $badge_color . ';">' . esc_html($phase_title ?? 'Not defined') . '</span>';



        $bgcolor = !empty($p->next_phase->Color) ? esc_attr($p->next_phase->Color) : '#ccc';

        // ── Résolution du display_name pour created_by ────────────────────────
        $created_by_user = $p->created_by ? get_userdata((int)$p->created_by) : null;
        $created_by_name = $created_by_user ? $created_by_user->display_name : '—';

        echo '<div class="ispag-box">';
            echo '<h3><span><i class="fas fa-info-circle"></i> ' . __('Project informations', 'creation-reservoir') . '</span></h3>';
            
            echo '<div class="ispag-box-content">';
                echo '<p><strong>' . __('Next step', 'creation-reservoir') .' : </strong> ' . $next_step_badge . '</p>';

                $champs = [
                    'NumCommande'       => __('Order number', 'creation-reservoir'),
                    'customer_order_id' => __('Customer order ID', 'creation-reservoir'),
                    // 'created_by'        => __('Project monitored by', 'creation-reservoir'),
                ];

                foreach ($champs as $champ => $label) {
                    $val = $p->$champ ?? '';
                    echo '<p><strong>' . esc_html($label) . ' :</strong> ';

                    if ($champ === 'created_by') {
                        // ── Affichage du nom plutôt que l'ID ─────────────────
                        // L'inline-edit garde l'ID en data-value pour la sauvegarde,
                        // mais affiche le display_name à l'écran
                        if ($can_edit) {
                            echo '<span class="ispag-inline-edit" data-name="' . esc_attr($champ) . '" data-value="' . esc_attr($val) . '" data-deal="' . esc_attr($deal_id) . '" data-source="project">';
                            echo esc_html($created_by_name) . ' <i class="fas fa-pen edit-icon"></i></span>';
                        } else {
                            echo esc_html($created_by_name);
                        }
                    } else {
                        if ($can_edit) {
                            echo '<span class="ispag-inline-edit" data-name="' . esc_attr($champ) . '" data-value="' . esc_attr($val) . '" data-deal="' . esc_attr($deal_id) . '" data-source="project">';
                            echo esc_html($val ?: '---') . ' <i class="fas fa-pen edit-icon"></i></span>';
                        } else {
                            echo esc_html($val ?: '-');
                        }
                    }

                    echo '</p>';
                }

                $contact_app_url = esc_url(add_query_arg('user_id', $p->AssociatedContactIDs, $ispag_app_base_url));
                echo '<p><strong>' . __('Order date', 'creation-reservoir') . ' :</strong> ' . date('d.m.Y', $p->TimestampDateCommande) . '</p>';
            echo '</div>';
        echo '</div>';
    }

    private static function render_bloc_livraison($infos) {
        echo ispag_get_template( 'ispag-popover-modal', [ null ] );
        echo '<div class="ispag-box">';
            echo '<h3>';
                echo '<span>' . __('Delivery', 'creation-reservoir') . '</span>';
                echo '<button type="button" class="ispag-btn-copy-description button" data-target="#delivery-info-copy">📋</button>';
            echo '</h3>';

            $champs = [
                'AdresseDeLivraison' => __('Adress', 'creation-reservoir'),
                'DeliveryAdresse2'   => __('Complement', 'creation-reservoir'),
                'DeliveryAdresse3'   => __('Complement 2', 'creation-reservoir'),
                'NIP'                => __('Postal code', 'creation-reservoir'),
                'City'               => __('City', 'creation-reservoir'),
                'PersonneContact'    => __('Contact', 'creation-reservoir'),
                'num_tel_contact'    => __('Phone number', 'creation-reservoir'),
            ];

            $copie_ligne1 = [];
            $copie_ligne2 = '';

            echo '<div id="delivery-info-text">';
            foreach ($champs as $champ => $label) {
                $val = $infos->$champ ?? '';

                echo '<p><strong>' . esc_html($label) . ' :</strong> ';

                $can_edit = current_user_can('manage_order') 
                        || ISPAG_Projet_Repository::is_user_project_owner($infos->hubspot_deal_id);

                if ($champ === 'num_tel_contact') {
                    if ($can_edit) {
                        echo '<span
                                class="ispag-inline-edit ispag-inline-edit-phone"
                                data-name="' . esc_attr($champ) . '"
                                data-value="' . esc_attr($val) . '"
                                data-deal="' . esc_attr($infos->hubspot_deal_id) . '"
                                data-source="delivery"
                                data-field-type="tel"
                            >';
                        // On affiche la valeur brute dans un span dédié pour le formatage JS
                        echo '<span class="ispag-phone-display">' . esc_html($val ?: '---') . '</span> <span class="edit-icon">✏️</span>';
                        echo '</span>';
                    } else {
                        echo '<span class="ispag-phone-display">' . esc_html($val ?: '-') . '</span>';
                    }
                } else {
                    // ── Tous les autres champs — rendu inline-edit standard ───
                    if ($can_edit) {
                        echo '<span 
                                class="ispag-inline-edit" 
                                data-name="' . esc_attr($champ) . '" 
                                data-value="' . esc_attr($val) . '" 
                                data-deal="' . esc_attr($infos->hubspot_deal_id) . '" 
                                data-source="delivery"
                            >';
                        echo esc_html($val ?: '---') . ' <span class="edit-icon">✏️</span>';
                        echo '</span>';
                    } else {
                        echo esc_html($val ?: '-');
                    }
                }

                echo '</p>';

                // ── Préparation du texte à copier ─────────────────────────────
                if (in_array($champ, ['AdresseDeLivraison', 'DeliveryAdresse2', 'DeliveryAdresse3', 'NIP', 'City'])) {
                    if (!empty($val)) $copie_ligne1[] = $val;
                } elseif ($champ === 'PersonneContact') {
                    $copie_ligne2 = $val;
                } elseif ($champ === 'num_tel_contact' && !empty($val)) {
                    $copie_ligne2 .= ': ' . $val;
                }
            }
            echo '</div>';

            $texte_final = implode(" ", $copie_ligne1) . "\n" . $copie_ligne2;
            echo '<div id="delivery-info-copy" style="display:none;">' . esc_html(trim($texte_final)) . '</div>';
        echo '</div>';
    }

    // private static function render_bloc_soumission($project) {
    //     $deal_id = (int) $project->hubspot_deal_id;
    //     echo '<div class="ispag-box">';
    //         echo '<h3><span><i class="fas fa-handshake"></i> ' . __('Submission information', 'creation-reservoir') . '</span></h3>';
    //         echo '<div class="ispag-box-content">';
                
    //             $champs = [
    //                 'ingenieur_id' => __('Engineer', 'creation-reservoir'), 
    //                 'EnSoumission' => __('Competitor', 'creation-reservoir')
    //             ];

    //             foreach ($champs as $champ => $label) {
    //                 $val = $project->$champ ?? '';
    //                 $display_val = $val;

    //                 // LOGIQUE SPECIFIQUE POUR L'INGÉNIEUR
    //                 if ($champ === 'ingenieur_id' && !empty($val)) {
    //                     // On cherche le nom correspondant à l'ID
    //                     $display_val = self::get_company_name_by_id($val);
    //                 }

    //                 echo '<p><strong>' . esc_html($label) . ' :</strong> ';
                    
    //                 // On garde l'ID dans data-value pour le Select2, mais on affiche le nom (display_val)
    //                 echo '<span class="ispag-inline-edit" 
    //                             data-name="'.esc_attr($champ).'" 
    //                             data-value="'.esc_attr($val).'" 
    //                             data-deal="'.esc_attr($deal_id).'" 
    //                             data-source="project">';
    //                 echo esc_html($display_val ?: '---') . ' <i class="fas fa-pen edit-icon"></i></span>';
    //                 echo '</p>';
    //             }
                
    //             self::render_ingenieur_datalist();
    //         echo '</div>';
    //     echo '</div>';
    // }
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

                    if ($champ === 'ingenieur_id' && !empty($val)) {
                        $display_val = self::get_company_name_by_id($val);
                    } elseif ($champ === 'EnSoumission' && !empty($val)) {
                        $display_val = $val;
                    }

                    echo '<p><strong>' . esc_html($label) . ' :</strong> ';

                    // Ajoute un conteneur pour les champs Select2
                    if ($champ === 'ingenieur_id' || $champ === 'EnSoumission') {
                        echo '<div class="ispag-inline-edit-container" style="position: relative; display: inline-block;">';
                    }

                    echo '<span class="ispag-inline-edit"
                                data-name="' . esc_attr($champ) . '"
                                data-value="' . esc_attr($val) . '"
                                data-deal="' . esc_attr($deal_id) . '"
                                data-source="project"
                                data-field-type="select2-' . esc_attr($champ) . '">';
                    echo esc_html($display_val ?: '---') . ' <i class="fas fa-pen edit-icon"></i>';
                    echo '</span>';

                    if ($champ === 'ingenieur_id' || $champ === 'EnSoumission') {
                        echo '</div>'; // Ferme le conteneur
                    }

                    echo '</p>';
                }

            echo '</div>';
        echo '</div>';
    }
    /**
     * Récupère le nom de l'entreprise via son Id (ispag_companies.Id)
     */
    private static function get_company_name_by_id($company_id) {
        global $wpdb;
        $table_name = ISPAG_Crm_Company_Constants::TABLE_NAME;
        
        $name = $wpdb->get_var($wpdb->prepare(
            "SELECT company_name FROM $table_name WHERE Id = %d LIMIT 1",
            $company_id
        ));

        return $name ?: $company_id; // Retourne l'ID si le nom n'est pas trouvé
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