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

    /** Champs de l'adresse de livraison : colonne => libellé. */
    private static function delivery_fields(): array {
        return [
            'AdresseDeLivraison' => __('Adress', 'creation-reservoir'),
            'DeliveryAdresse2'   => __('Complement', 'creation-reservoir'),
            'DeliveryAdresse3'   => __('Complement 2', 'creation-reservoir'),
            'NIP'                => __('Postal code', 'creation-reservoir'),
            'City'               => __('City', 'creation-reservoir'),
            'PersonneContact'    => __('Contact', 'creation-reservoir'),
            'num_tel_contact'    => __('Phone number', 'creation-reservoir'),
        ];
    }

    private static function can_edit_delivery($deal_id): bool {
        return current_user_can('manage_order') || ISPAG_Projet_Repository::is_user_project_owner($deal_id);
    }

    /**
     * Bloc « Delivery » : adresse en lecture, avec un seul formulaire d'édition (bouton Edit) qui enregistre tous les champs d'un coup.
     */
    private static function render_bloc_livraison($infos) {
        $deal_id  = (int) $infos->hubspot_deal_id;
        $can_edit = self::can_edit_delivery($deal_id);
        $fields   = self::delivery_fields();
        $val = function ($k) use ($infos) { return trim(stripslashes((string) ($infos->$k ?? ''))); };

        // Lignes affichées / copiées : adresse (3 lignes), « NPA Ville », puis contact
        $address_lines = array_values(array_filter([$val('AdresseDeLivraison'), $val('DeliveryAdresse2'), $val('DeliveryAdresse3')]));
        $zip_city = trim($val('NIP') . ' ' . $val('City'));
        if ($zip_city !== '') $address_lines[] = $zip_city;
        $contact_line = implode(' : ', array_filter([$val('PersonneContact'), $val('num_tel_contact')]));
        $copy_text = implode("\n", array_filter([implode("\n", $address_lines), $contact_line]));

        echo ispag_get_template( 'ispag-popover-modal', [ null ] );
        echo '<div class="ispag-box" id="ispag-delivery-box" data-deal="' . esc_attr($deal_id) . '" data-nonce="' . esc_attr(wp_create_nonce('ispag_project_delivery')) . '">';
        echo '<div class="ispag-delivery-head"><h3>' . esc_html__('Delivery', 'creation-reservoir') . '</h3>';
        if ($can_edit) {
            echo '<button type="button" class="ispag-btn ispag-btn-grey-outlined ispag-delivery-edit-btn">✏️ ' . esc_html__('Edit', 'creation-reservoir') . '</button>';
        }
        echo '</div>';

        // --- Lecture ---
        echo '<div class="ispag-delivery-view" id="delivery-info-text">';
        if ($address_lines || $contact_line !== '') {
            echo '<address class="ispag-delivery-address">';
            foreach ($address_lines as $i => $line) {
                echo ($i === 0 ? '<strong>' . esc_html($line) . '</strong>' : esc_html($line)) . '<br>';
            }
            echo '</address>';
            if ($contact_line !== '') {
                echo '<p class="ispag-delivery-contact">👤 ' . esc_html($contact_line) . '</p>';
            }
        } else {
            echo '<p class="ispag-delivery-empty">' . esc_html__('No delivery address yet.', 'creation-reservoir') . '</p>';
        }
        echo '</div>';

        // --- Édition : un formulaire pour tous les champs ---
        if ($can_edit) {
            echo '<form class="ispag-delivery-form" hidden>';
            foreach ($fields as $name => $label) {
                $wide = in_array($name, ['AdresseDeLivraison', 'DeliveryAdresse2', 'DeliveryAdresse3', 'PersonneContact'], true);
                $type = $name === 'num_tel_contact' ? 'tel' : 'text';
                echo '<label class="ispag-delivery-field' . ($wide ? ' is-wide' : '') . '"><span>' . esc_html($label) . '</span>'
                    . '<input type="' . $type . '" name="' . esc_attr($name) . '" value="' . esc_attr($val($name)) . '"'
                    . ($name === 'NIP' ? ' inputmode="numeric" autocomplete="postal-code"' : '') . '></label>';
            }
            echo '<div class="ispag-delivery-form-actions">'
                . '<button type="submit" class="ispag-btn ispag-btn-green">' . esc_html__('Save', 'creation-reservoir') . '</button>'
                . '<button type="button" class="ispag-btn ispag-btn-grey-outlined ispag-delivery-cancel-btn">' . esc_html__('Cancel', 'creation-reservoir') . '</button>'
                . '<span class="ispag-delivery-status" aria-live="polite"></span>'
                . '</div>';
            echo '</form>';
        }

        echo '<pre id="delivery-info-copy" style="display:none;">' . esc_html($copy_text) . '</pre>';
        echo '<div class="ispag-delivery-actions">';
        echo '<button type="button" class="ispag-btn ispag-btn-grey-outlined ispag-btn-copy-description" data-target="#delivery-info-copy">📋</button>';
        echo '</div>';
        echo '</div>';
    }

    /** Enregistre tous les champs du formulaire d'adresse d'un coup et renvoie le bloc rafraîchi. */
    public static function ajax_save_delivery() {
        check_ajax_referer('ispag_project_delivery', 'nonce');
        $deal_id = absint($_POST['deal_id'] ?? 0);
        if (!$deal_id) {
            wp_send_json_error('ID projet manquant');
        }
        if (!self::can_edit_delivery($deal_id)) {
            wp_send_json_error(__('Not authorized', 'creation-reservoir'), 403);
        }

        $data = [];
        foreach (array_keys(self::delivery_fields()) as $name) {
            $data[$name] = sanitize_text_field(wp_unslash($_POST[$name] ?? ''));
        }

        global $wpdb;
        $table  = $wpdb->prefix . 'achats_info_commande';
        $old    = (new ISPAG_Project_Details_Repository())->get_infos_livraison($deal_id); // pour savoir ce qui change
        $exists = $wpdb->get_var($wpdb->prepare("SELECT Id FROM $table WHERE hubspot_deal_id = %d LIMIT 1", $deal_id));
        if ($exists) {
            $ok = $wpdb->update($table, $data, ['Id' => (int) $exists]) !== false;
        } else {
            // Colonnes NOT NULL sans valeur par défaut : on les renseigne à l'insertion
            $defaults = ['Comment' => '', 'unloadingFacilities' => 0];
            $ok = $wpdb->insert($table, array_merge($defaults, $data, ['hubspot_deal_id' => $deal_id])) !== false;
        }
        if (!$ok) {
            wp_send_json_error(__('Error while saving', 'creation-reservoir'));
        }

        self::notify_delivery_change($deal_id, $old, $data);

        $infos = (new ISPAG_Project_Details_Repository())->get_infos_livraison($deal_id);
        ob_start();
        self::render_bloc_livraison($infos);
        wp_send_json_success(['html' => ob_get_clean()]);
    }

    /** AJAX : bloc « Delivery » dans une fenêtre (lien des e-mails de livraison : ?ispag_modal=delivery). */
    public static function ajax_delivery_modal() {
        $deal_id = absint($_POST['deal_id'] ?? 0);
        if (!$deal_id || !is_user_logged_in() || !self::can_edit_delivery($deal_id)) {
            wp_send_json_error(__('Not authorized', 'creation-reservoir'), 403);
        }
        $infos = (new ISPAG_Project_Details_Repository())->get_infos_livraison($deal_id);
        ob_start();
        self::render_bloc_livraison($infos);
        wp_send_json_success(['html' => ob_get_clean()]);
    }

    /** Une autre personne que le chef de projet modifie l'adresse / le contact de livraison : le chef de projet est prévenu. */
    private static function notify_delivery_change($deal_id, $old, array $new) {
        if (!class_exists('ISPAG_Notifications_Manager')) return;
        $project = apply_filters('ispag_get_project_by_deal_id', null, $deal_id);
        $pm = $project ? (int) ($project->project_manager ?: $project->created_by) : 0;
        $me = get_current_user_id();
        if (!$pm || $pm === $me) return;

        $labels = self::delivery_fields();
        $lines  = [];
        foreach ($new as $key => $value) {
            $before = trim(stripslashes((string) ($old->$key ?? '')));
            if ($before !== trim((string) $value)) {
                $lines[] = '<strong>' . esc_html($labels[$key] ?? $key) . '</strong> : ' . esc_html($before !== '' ? $before : '—') . ' → ' . esc_html($value !== '' ? $value : '—');
            }
        }
        if (!$lines) return;

        $who = get_userdata($me);
        ISPAG_Notifications_Manager::send(
            [$pm],
            'product_manager',
            sprintf(esc_html__('📦 Delivery information changed: %s', 'creation-reservoir'), esc_html($project->ObjetCommande ?? $deal_id)),
            sprintf(esc_html__('%s updated the delivery address / contact:', 'creation-reservoir'), esc_html($who ? $who->display_name : '')) . '<br>' . implode('<br>', $lines),
            'project-detail/' . $deal_id . '/',
            $deal_id
        );
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