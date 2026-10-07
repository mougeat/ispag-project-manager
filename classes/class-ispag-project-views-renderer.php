<?php
defined('ABSPATH') or die();

/**
 * Class ISPAG_Project_views_Renderer
 * Gère l'affichage et les actions de la page de détail des projets/achats.
 * Logging : Toutes les actions sont loguées dans ispag_detail_page.log.
 */
class ISPAG_Project_views_Renderer
{


    public function display_ispag_project_articles($deal_id, $isQotation = false)
    {
        $user_id = get_current_user_id();
        $logger = ISPAG_Logger::get_instance();
        $logger->log_user_action('detail_page', 'display_ispag_project_articles_start', ['deal_id' => $deal_id, 'isQotation' => $isQotation], $user_id);

        if (!$deal_id)
        {
            $logger->log('detail_page', 'ERROR: Missing deal_id', $user_id);
            return '<p>' . __('Project not found', 'creation-reservoir') . '</p>';
        }

        $can_manage_order = current_user_can('manage_order');
        $can_view_prices = current_user_can('display_sales_prices');

        $logger->log_user_action('detail_page', 'permissions_checked', ['can_manage_order' => $can_manage_order, 'can_view_prices' => $can_view_prices], $user_id);

        

        echo '<div id="display_article_page">';
        if ($can_manage_order)
        {
            // echo '<div id="ispag-bulk-message" class="bulk_message"></div>';

            echo '
            <div class="ispag-article-header-global" style="margin-bottom: 1rem;">
                <input type="checkbox" id="select-all-articles" class="ispag-article-checkbox">
                <label for="select-all-articles">' . __('Select all', 'creation-reservoir') . '</label>
            </div>';
        }

        

        echo '<div class="ispag-articles-content" id="display_ispag_article_list">';
        echo '
        <div class="ispag-article-list-overlay">
            <div id="ispag-loading-spinner" style="text-align:center;"><span class="dashicons dashicons-update" style="animation: spin 2s linear infinite;"></span> ' . __('Loading', 'creation-reservoir') . '</div>
        </div>';
        echo '<div class="ispag-articles-list" data-deal-id=' . $deal_id . '>';
        echo '</div>';
        

        
        echo '</div>';
        echo '</div>';
    }
    
    public function render_project_action_button($deal_id = null, $is_qotation = false) {
        if (empty($deal_id)) {
            return;
        }

        $details_repo = new ISPAG_Project_Details_Repository();
        $infos = $details_repo->get_infos_livraison($deal_id);

        // Définition des conditions d'affichage de chaque groupe
        // « Add product » : manage_order, ou generate_tank sur une offre
        // Client / ingénieur (generate_tank) : seulement tant que le projet est une offre ; une fois en commande, plus d'ajout
        $has_article_content = current_user_can('manage_order') || (current_user_can('generate_tank') && $is_qotation);
        // Groupe « projet » : seulement la check-list de soudure sur site (« Replicate project » est dans le groupe actions, avec « Transform to project »)
        $has_project_content = (class_exists('ISPAG_Tank_Welding_Site_Sheet') && current_user_can('manage_site_welding_datas') && !ISPAG_Projet_Repository::get_is_qotation_by_deal_id($deal_id));
        $has_actions_content = current_user_can('manage_order');

        ob_start();
        ?>
        <div class="ispag-action-toolbar" style="display: flex; flex-direction: column; gap: 10px; width: 100%;">

            <!-- 1. GROUPE ARTICLE -->
            <?php if ($has_article_content): ?>
                <div class="ispag-button-group ispag-group-article" style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
                    <button id="ispag-add-article" class="ispag-btn ispag-btn-warning-outlined" data-deal-id="<?php echo esc_attr($deal_id); ?>" source="project">
                        <span class="dashicons dashicons-plus-alt"></span> <?php _e('Add product', 'creation-reservoir'); ?>
                    </button>
                </div>
            <?php endif; ?>

            <!-- Séparateur horizontal -->
            <?php if ($has_article_content && ($has_project_content || $has_actions_content)): ?>
                <hr style="border: none; border-top: 1px solid #ccd0d4; margin: 4px 0;" />
            <?php endif; ?>

            <!-- 2. GROUPE PROJET -->
            <?php if ($has_project_content): ?>
                <div class="ispag-button-group ispag-group-projet" style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
                    <?php 
                    //&& !ISPAG_Projet_Repository::get_is_qotation_by_deal_id($deal_id)
                    if (class_exists('ISPAG_Tank_Welding_Site_Sheet') && current_user_can('manage_site_welding_datas') ) {
                        echo apply_filters('ispag_get_welding_site_sheet_btn', null, $deal_id);
                    }
                    ?>
                </div>
            <?php endif; ?>

            <!-- Séparateur horizontal -->
            <?php if ($has_project_content && $has_actions_content): ?>
                <hr style="border: none; border-top: 1px solid #ccd0d4; margin: 4px 0;" />
            <?php endif; ?>

            <!-- 3. GROUPE ACTIONS -->
            <?php if ($has_actions_content): ?>
                <div class="ispag-button-group ispag-group-actions" style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
                    <?php 
                    if (!$is_qotation) {
                        echo get_delivery_btn($infos);
                    }
                    
                    if ($is_qotation) {
                        ?>
                        <button id="convert-to-project" class="ispag-btn ispag-btn-secondary-outlined" data-id="<?php echo esc_attr($deal_id); ?>">
                            <span class="dashicons dashicons-migrate"></span> <?php _e('Transform to project', 'creation-reservoir'); ?>
                        </button>
                        <?php
                    }

                    if ($is_qotation) {
                        ?>
                        <button id="ispag-duplicate-btn" class="ispag-btn ispag-btn-warning-outlined" data-deal-id="<?php echo esc_attr($deal_id); ?>" source="project">
                            <i class="fas fa-copy"></i> <?php _e('Replicate project', 'creation-reservoir'); ?>
                        </button>
                        <?php
                    }

                    echo get_generate_po_button($deal_id);
                    echo display_invoice_btn($deal_id);
                    echo apply_filters('ispag_delete_project_btn', null, $deal_id);
                    ?>
                </div>
            <?php endif; ?>

        </div>
        <?php
        return ob_get_clean();
    }
    
    public function render_project_stat($deal_id = null, $can_view_prices = false){
        if ($can_view_prices)
        {
            echo '<div id="ispag-bloc-stat-projet" class="fields-prices">';
                $project_repo = new ISPAG_Project_Details_Repository();
                echo $project_repo->build_deal_stats_html(null, $deal_id);
                // echo apply_filters('ispag_display_deal_stats', null, $deal_id);
            echo '</div>';

            echo '<div id="ispag-coef-notice" data-deal-id="' . $deal_id . '"  class="fields-prices">';
                $notice = new ISPAG_Article_Pricing();
                echo $notice->render_sales_coef_notice($deal_id);
            echo '</div>';
        }
        else{
            echo '';
        }
    }

    

    public function display_ispag_project_details($deal_id = null, $details = null)
    {
        $user_id = get_current_user_id();
        $logger = ISPAG_Logger::get_instance();
        $logger->log_user_action('detail_page', 'display_ispag_project_details_start', ['deal_id' => $deal_id], $user_id);

        ob_start();

        echo ISPAG_Project_Details_Renderer::display($deal_id, $details);
    
        return ob_get_clean();
    }

    public function bulk_selected_article($deal_id, $is_qotation = false)
    {
        $user_id = get_current_user_id();
        $logger = ISPAG_Logger::get_instance();
        $logger->log_user_action('detail_page', 'bulk_selected_article_start', ['deal_id' => $deal_id], $user_id);

        $can_manage_order = current_user_can('manage_order');
        if (!$can_manage_order)
        {
            $logger->log('detail_page', 'ERROR: User cannot manage order', $user_id);
            return false;
        }

        $discount_value = apply_filters('ispag_get_project_discount', null, $deal_id) ?? null;
        $logger->log_user_action('detail_page', 'project_discount_fetched', ['deal_id' => $deal_id, 'discount' => $discount_value], $user_id);

        ob_start();
        ?>
        <div class="ispag-bulk-actions ispag-card" style="display:none;">
            <div class="ispag-bulk-header">
                <h4>🛠️ <?php _e('Bulk update selected articles', 'creation-reservoir') ; ?></h4>
            </div>
            <input type="hidden" id="deal-id" value="<?php echo($deal_id); ?>">

            <div class="ispag-bulk-grid">

                <?php
                if(! $is_qotation){
                ?>

                <div class="ispag-bulk-field">
                    <label for="bulk-date-depart"><?php _e('Factory departure date', 'creation-reservoir') ; ?></label>
                    <input type="date" id="bulk-date-depart">
                </div>

                <div class="ispag-bulk-field">
                    <label for="bulk-date-eta"><?php _e('Delivery ETA', 'creation-reservoir') ; ?></label>
                    <input type="date" id="bulk-date-eta">
                </div>

                <div class="ispag-bulk-field">
                    <label for="bulk-livre-date">📦 <?php _e('Delivered on', 'creation-reservoir') ; ?></label>
                    <input type="date" id="bulk-livre-date">
                </div>

                <div class="ispag-bulk-field">
                    <label for="bulk-invoiced-date">🧾 <?php _e('Invoiced on', 'creation-reservoir') ; ?></label>
                    <input type="date" id="bulk-invoiced-date">
                </div>

                <?php
                }
                ?>

                <div class="ispag-bulk-field">
                    <label for="bulk-discount"><?php _e('Discount', 'creation-reservoir') ; ?></label>
                    <div class="ispag-input-suffix">
                        <input type="number" id="bulk-discount" name="bulk-discount" min="0" max="100" step="0.01" maxlength="5" value="<?php esc_attr($discount_value); ?>">
                        <span class="suffix">%</span>
                    </div>
                </div>

                <div class="ispag-bulk-field ispag-bulk-toggles">
                    <label class="ispag-toggle-chip" data-tristate="bulk-demande-ok">
                        <input type="checkbox" id="bulk-demande-ok" class="ispag-tristate-checkbox">
                        <span>🛒 <?php _e('Purchase request OK', 'creation-reservoir'); ?></span>
                    </label>

                    <?php if (!$is_qotation): ?>
                    <label class="ispag-toggle-chip" data-tristate="bulk-drawing-ok">
                        <input type="checkbox" id="bulk-drawing-ok" class="ispag-tristate-checkbox">
                        <span>📝 <?php _e('Drawing approved', 'creation-reservoir'); ?></span>
                    </label>
                    <?php endif; ?>

                    <button type="button" id="bulk-delete-articles" class="ispag-chip-btn ispag-chip-btn--danger">
                        <span class="dashicons dashicons-trash"></span> <?php _e('Delete articles', 'creation-reservoir'); ?>
                    </button>
                </div>

            </div>

            <div class="ispag-bulk-footer">
                <button type="button" id="apply-bulk-update" class="ispag-btn ispag-btn-green">
                    <span class="dashicons dashicons-update-alt ispag-btn-spinner-icon" style="display:none;"></span>
                    <span class="ispag-btn-label">✅<?php _e('Apply changes', 'creation-reservoir'); ?></span>
                </button>
            </div>
        </div>

        <?php
        return ob_get_clean();
    }

}