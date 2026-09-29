<?php
/**
 * ISPAG Article Modal header View
 * @package    ISPAG_Project_Manager
 * @version    2.1.0
 * @author     Cyril Barthel
 * @description v2.1.0 : Mise en avant des KPIs (Prix/Qté) sous le titre et Workflow en liste de contrôle.
 */

$user_can = current_user_can('manage_order'); 
$can_view_prices = current_user_can('display_sales_prices');
$allow_display_sensible_info = isset($_COOKIE['ispag_allow_prices']) && $_COOKIE['ispag_allow_prices'] === 'true';
?>

<!-- <div class="ispag-modal-header-v2"> -->
    <div class="header-main">
        <div class="title-area">
            <h2><?php echo esc_html(stripslashes($article->Article)); ?></h2>
            
        </div>
        
        <div class="header-stats">
            <div class="stat-item">
                <span class="stat-label"><?php echo __('Quantity', 'creation-reservoir'); ?></span>
                <span class="stat-value"><?php echo intval($article->Qty) ?></span>
            </div>
            <?php if ($can_view_prices && $allow_display_sensible_info): ?>
            <div class="fields-prices stat-item price-highlight">
                <span class="stat-label"><?php echo __('Total Price', 'creation-reservoir'); ?></span>
                <span class="stat-value"><?php echo number_format((float)$article->prix_total_calculé, 2, '.', ' ') ?> <small><?php echo get_option('wpcb_currency'); ?></small></span>
            </div> 
            <?php endif; ?>
        </div>
    </div>
<!-- </div> -->