<?php
defined('ABSPATH') || exit;
/**
 * ISPAG Article Edit Modal View
 * @version     2.1.8
 */
$user_can = current_user_can('manage_order'); 
$can_view_prices = current_user_can('display_sales_prices');
$allow_display_sensible_info = isset($_COOKIE['ispag_allow_prices']) && $_COOKIE['ispag_allow_prices'] === 'true';
?>

<div class="ispag-modal-header-v2">
    <div class="header-main">
        <div class="title-area">
            <h2>
                <?= $is_new ? __('New article', 'creation-reservoir') : __('Edit article', 'creation-reservoir') . ' : ' . esc_html(stripslashes($article->Article)) ?>
            </h2>
            <span class="ispag-badge-group"><?php echo esc_html(stripslashes($article->Groupe)) ?></span>
        </div>
        <div class="header-stats">
            <div class="stat-item">
                <span class="stat-label"><?php echo __('Status', 'creation-reservoir'); ?></span>
                <span class="stat-value" style="font-size: 14px;"><?= $is_new ? __('Creation', 'creation-reservoir') : __('Update', 'creation-reservoir') ?></span>
            </div>
        </div>
    </div> 
</div>