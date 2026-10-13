<?php /** @var array $d  Voir ISPAG_Article_View */ ?>
<div class="header-main ispag-av-header">
    <div class="title-area">
        <h2><?php echo esc_html($d['title']); ?></h2>
        <?php if (!empty($d['subtitle'])): ?><div class="ispag-av-subtitle"><?php echo esc_html($d['subtitle']); ?></div><?php endif; ?>
        <?php if (!empty($d['group']) || !empty($d['chips']) || !empty($d['is_variant'])): ?>
        <div class="ispag-av-chips">
            <?php if (!empty($d['group'])): ?><span class="ispag-badge-group"><?php echo esc_html($d['group']); ?></span><?php endif; ?>
            <?php if (!empty($d['is_variant'])): ?><span class="ispag-chip ispag-chip--variant" title="<?php echo esc_attr__('Not counted in the total sales price, nor its purchases in the project costs', 'creation-reservoir'); ?>"><?php esc_html_e('Variant', 'creation-reservoir'); ?></span><?php endif; ?>
            <?php foreach ((array) ($d['chips'] ?? []) as $chip): ?><span class="ispag-chip ispag-chip--<?php echo esc_attr($chip[1]); ?>"><?php echo esc_html($chip[0]); ?></span><?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
    <div class="header-stats">
        <div class="stat-item">
            <span class="stat-label"><?php esc_html_e('Quantity', 'creation-reservoir'); ?></span>
            <span class="stat-value"><?php echo (int) $d['qty']; ?></span>
        </div>
        <?php
        // En-tête : prix brut unitaire (avant remise) quand il est fourni, sinon prix net
        $has_gross = isset($d['unit_gross']) && $d['unit_gross'] !== null;
        $unit_value = $has_gross ? $d['unit_gross'] : $d['unit_net'];
        ?>
        <?php if ($unit_value !== null): ?>
            <div class="stat-item">
                <span class="stat-label"><?php echo $has_gross ? esc_html__('Gross unit price', 'creation-reservoir') : esc_html__('Unit price', 'creation-reservoir'); ?></span>
                <span class="stat-value ispag-av-unit-price<?php echo $has_gross ? ' ispag-av-gross' : ''; ?>"><?php echo number_format((float) $unit_value, 2, '.', $has_gross ? '' : ' '); ?></span>
                <?php if ((float) $d['discount'] > 0): ?><span class="ispag-av-discount">−<?php echo esc_html(number_format((float) $d['discount'], 2)); ?>%</span><?php endif; ?>
            </div>
        <?php endif; ?>
        <?php if ($d['total'] !== null): ?>
            <div class="fields-prices stat-item price-highlight">
                <span class="stat-label"><?php esc_html_e('Total Price', 'creation-reservoir'); ?></span>
                <span class="stat-value<?php echo !empty($d['is_variant']) ? ' is-excluded' : ''; ?>"><?php echo number_format((float) $d['total'], 2, '.', ' '); ?> <small><?php echo esc_html($d['currency']); ?></small></span>
            </div>
        <?php endif; ?>
    </div>
    <?php if (!empty($d['edit_id'])): ?>
    <button type="button" class="ispag-btn ispag-btn-warning-outlined ispag-av-edit" data-article-id="<?php echo (int) $d['edit_id']; ?>"><i class="fas fa-edit"></i> <?php esc_html_e('Edit product', 'creation-reservoir'); ?></button>
    <?php endif; ?>
</div>
