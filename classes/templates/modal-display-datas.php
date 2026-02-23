<?php
/**
 * ISPAG Article Modal View
 * @version    2.1.2
 */
$user_can = current_user_can('manage_order'); 
$can_view_prices = current_user_can('display_sales_prices');
?>

<div class="ispag-modal-header-v2">
    <div class="header-main">
        <div class="title-area">
            <h2><?php echo esc_html(stripslashes($article->Article)); ?></h2>
            <span class="ispag-badge-group"><?php echo esc_html(stripslashes($article->Groupe)) ?></span>
        </div>
        <div class="header-stats">
            <div class="stat-item">
                <span class="stat-label"><?php echo __('Quantity', 'creation-reservoir'); ?></span>
                <span class="stat-value"><?php echo intval($article->Qty) ?></span>
            </div>
            <?php if ($can_view_prices): ?>
            <div class="stat-item price-highlight">
                <span class="stat-label"><?php echo __('Total Price', 'creation-reservoir'); ?></span>
                <span class="stat-value"><?php echo number_format((float)$article->prix_total_calculé, 2, '.', ' ') ?> <small><?php echo get_option('wpcb_currency'); ?></small></span>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="ispag-modal-body-scroll">

    <div class="ispag-modal-grid">
        <div class="ispag-modal-left visual-container" style="min-height: 200px; background: #eee !important;">
            <div class="image-wrapper">
                <?php
                $img_raw = trim($article->image);
                if (empty($img_raw)) {
                    echo '<span class="dashicons dashicons-format-image" style="font-size:50px; color:#ccc;"></span>';
                } elseif (strpos($img_raw, '<svg') === 0) {
                    echo $img_raw;
                } else {
                    // On enlève esc_url temporairement pour debug si c'est un chemin relatif
                    echo '<img src="' . $img_raw . '" alt="Article" style="display:block; max-width:100%; height:auto; margin:auto;">';
                }
                ?>
            </div>
        </div>

        <div class="ispag-modal-right">
            <div class="description-card">
                <div class="card-header">
                    <h3><?php echo __('Description', 'creation-reservoir'); ?></h3>
                    <button class="ispag-btn-copy-description" data-target="#article-description">
                        <img src="https://s.w.org/images/core/emoji/15.1.0/svg/1f4cb.svg" alt="📋" style="width:14px;">
                    </button>
                </div>
                <div id="article-description" class="description-content">
                    <?php echo wp_kses_post(nl2br(stripslashes($article->Description))); ?>
                </div>
            </div>
        </div>
    </div>

    <div class="ispag-modal-grid ispag-bloc-common">
        <div class="ispag-modal-left detail-block">
            <h3><span class="dashicons dashicons-calendar-alt"></span> <?php echo __('Logistics', 'creation-reservoir'); ?></h3>
            <ul class="info-list">
                <?php if ($user_can): ?>
                    <li><strong><?php echo __('Supplier', 'creation-reservoir'); ?></strong> <span><?php echo esc_html($article->fournisseur_nom) ?></span></li>
                <?php endif; ?>
                <li><strong><?php echo __('Factory departure', 'creation-reservoir'); ?></strong> <span><?php echo ($article->TimestampDateDeLivraison ? date('d.m.Y', $article->TimestampDateDeLivraison) : '-'); ?></span></li>
                <li><strong><?php echo __('Delivery ETA', 'creation-reservoir'); ?></strong> <span><?php echo ($article->TimestampDateDeLivraisonFin ? date('d.m.Y', $article->TimestampDateDeLivraisonFin) : '-'); ?></span></li>
            </ul>
        </div>

        <?php if ($user_can): ?>
        <div class="ispag-modal-right detail-block">
            <h3><span class="dashicons dashicons-forms"></span> <?php echo __('Order Progress', 'creation-reservoir'); ?></h3>
            <ul class="workflow-list">
                <?php 
                $status_steps = [
                    __('Purchase requested', 'creation-reservoir') => $article->DemandeAchatOk,
                    __('Drawing approved', 'creation-reservoir')  => (int)$article->DrawingApproved === 1,
                    __('Delivered', 'creation-reservoir')         => $article->Livre,
                    __('Invoiced', 'creation-reservoir')          => $article->invoiced
                ];
                foreach ($status_steps as $label => $ok): ?>
                    <li class="<?php echo $ok ? 'step-done' : 'step-pending'; ?>">
                        <span class="step-icon"><?php echo $ok ? '✅' : '⚪'; ?></span>
                        <span class="step-label"><?php echo $label; ?></span>
                        <span class="step-badge"><?php echo $ok ? __('Completed', 'creation-reservoir') : __('Pending', 'creation-reservoir'); ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>
    </div>
    
</div>