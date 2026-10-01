<?php /** @var array $d  Voir ISPAG_Article_View */ ?>
<div class="header-main ispag-av-header">
    <div class="title-area">
        <h2><?php echo esc_html($d['title']); ?></h2>
        <?php if (!empty($d['subtitle'])): ?><div class="ispag-av-subtitle"><?php echo esc_html($d['subtitle']); ?></div><?php endif; ?>
    </div>
    <div class="header-stats">
        <div class="stat-item">
            <span class="stat-label"><?php esc_html_e('Quantity', 'creation-reservoir'); ?></span>
            <span class="stat-value"><?php echo (int) $d['qty']; ?></span>
        </div>
        <?php if ($d['unit_net'] !== null): ?>
            <div class="stat-item">
                <span class="stat-label"><?php esc_html_e('Unit price', 'creation-reservoir'); ?></span>
                <span class="stat-value"><?php echo number_format((float) $d['unit_net'], 2, '.', ' '); ?></span>
                <?php if ((float) $d['discount'] > 0): ?><span class="ispag-av-discount">−<?php echo esc_html(number_format((float) $d['discount'], 2)); ?>%</span><?php endif; ?>
            </div>
        <?php endif; ?>
        <?php if ($d['total'] !== null): ?>
            <div class="fields-prices stat-item price-highlight">
                <span class="stat-label"><?php esc_html_e('Total Price', 'creation-reservoir'); ?></span>
                <span class="stat-value"><?php echo number_format((float) $d['total'], 2, '.', ' '); ?> <small><?php echo esc_html($d['currency']); ?></small></span>
            </div>
        <?php endif; ?>
    </div>
</div>
