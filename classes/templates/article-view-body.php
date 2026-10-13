<?php
/** @var array $d  Voir ISPAG_Article_View */
$has_price_card = isset($d['unit_net']) && $d['unit_net'] !== null;
$has_gross      = isset($d['unit_gross']) && $d['unit_gross'] !== null;
$money          = function ($v) { return number_format((float) $v, 2, '.', ' '); };
$steps_done     = 0;
foreach ((array) ($d['steps'] ?? []) as $s) { if (!empty($s[1])) $steps_done++; }
?>
<div class="ispag-av">
    <div class="ispag-av-top">
        <section class="ispag-av-card ispag-av-description">
            <div class="ispag-av-card-head">
                <h3><span class="dashicons dashicons-text-page"></span> <?php esc_html_e('Description', 'creation-reservoir'); ?></h3>
                <button type="button" class="ispag-btn-copy-description" data-target="#article-description" title="<?php echo esc_attr__('Copy', 'creation-reservoir'); ?>">📋</button>
            </div>
            <div id="article-description" class="description-content">
                <?php echo wp_kses_post(nl2br(stripslashes((string) $d['description']))); ?>
            </div>
        </section>

        <figure class="ispag-av-image"><?php echo $d['image_html']; ?></figure>
    </div>

    <?php if (!empty($d['info']) || $has_price_card): ?>
    <div class="ispag-av-cards">
        <?php if (!empty($d['info'])): ?>
        <section class="ispag-av-card">
            <div class="ispag-av-card-head"><h3><span class="dashicons dashicons-calendar-alt"></span> <?php esc_html_e('Logistics', 'creation-reservoir'); ?></h3></div>
            <ul class="info-list">
                <?php foreach ($d['info'] as $line): ?>
                    <li><strong><?php echo esc_html($line[0]); ?></strong> <span><?php echo esc_html($line[1]); ?></span></li>
                <?php endforeach; ?>
            </ul>
        </section>
        <?php endif; ?>

        <?php if ($has_price_card): ?>
        <section class="ispag-av-card">
            <div class="ispag-av-card-head"><h3><span class="dashicons dashicons-cart"></span> <?php esc_html_e('Pricing', 'creation-reservoir'); ?></h3></div>
            <ul class="info-list ispag-av-prices">
                <?php if ($has_gross): ?><li><strong><?php esc_html_e('Gross unit price', 'creation-reservoir'); ?></strong> <span><?php echo esc_html($money($d['unit_gross'])); ?></span></li><?php endif; ?>
                <?php if ((float) ($d['discount'] ?? 0) > 0): ?><li><strong><?php esc_html_e('Discount', 'creation-reservoir'); ?></strong> <span>−<?php echo esc_html(number_format((float) $d['discount'], 2)); ?>%</span></li><?php endif; ?>
                <li><strong><?php esc_html_e('Net unit price', 'creation-reservoir'); ?></strong> <span><?php echo esc_html($money($d['unit_net'])); ?></span></li>
                <li><strong><?php esc_html_e('Quantity', 'creation-reservoir'); ?></strong> <span>× <?php echo (int) $d['qty']; ?></span></li>
                <?php if ($d['total'] !== null): ?>
                <li class="ispag-av-total<?php echo !empty($d['is_variant']) ? ' is-excluded' : ''; ?>"><strong><?php esc_html_e('Total Price', 'creation-reservoir'); ?></strong> <span><?php echo esc_html($money($d['total'])); ?> <small><?php echo esc_html($d['currency']); ?></small></span></li>
                <?php endif; ?>
            </ul>
            <?php if (!empty($d['is_variant'])): ?>
                <p class="ispag-av-note"><?php esc_html_e('Variant: not counted in the total', 'creation-reservoir'); ?></p>
            <?php endif; ?>
        </section>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if (!empty($d['is_staff']) && !empty($d['steps'])): ?>
    <section class="ispag-av-steps-wrap">
        <h3><span class="dashicons dashicons-yes"></span> <?php esc_html_e('Progress', 'creation-reservoir'); ?> <small><?php echo (int) $steps_done; ?>/<?php echo count($d['steps']); ?></small></h3>
        <ol class="ispag-av-pills">
            <?php foreach ($d['steps'] as $i => $step): $ok = !empty($step[1]); ?>
                <li class="<?php echo $ok ? 'is-done' : 'is-pending'; ?>" title="<?php echo $ok ? esc_attr__('Completed', 'creation-reservoir') : esc_attr__('Pending', 'creation-reservoir'); ?>">
                    <i><?php echo $ok ? '✔' : (int) ($i + 1); ?></i> <?php echo esc_html($step[0]); ?>
                </li>
            <?php endforeach; ?>
        </ol>
    </section>
    <?php endif; ?>

    <?php if (!empty($d['documents']) || !empty($d['tools'])): ?>
    <section class="ispag-av-card ispag-av-docs-card">
        <div class="ispag-av-card-head"><h3><span class="dashicons dashicons-media-document"></span> <?php esc_html_e('Documents', 'creation-reservoir'); ?></h3></div>
        <?php if (!empty($d['documents'])): ?>
        <ul class="ispag-av-doclist">
            <?php foreach ($d['documents'] as $doc): ?>
                <li>
                    <a href="<?php echo esc_url($doc['url']); ?>" target="_blank" rel="noopener">📄 <?php echo esc_html($doc['label']); ?></a>
                    <?php if (!empty($doc['date'])): ?><span class="ispag-av-docdate"><?php echo esc_html($doc['date']); ?></span><?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
        <?php if (!empty($d['tools'])): ?>
        <div class="ispag-av-tools"><?php echo implode('', $d['tools']); // boutons fournis par les modules (croquis, fiche technique…) ?></div>
        <?php endif; ?>
    </section>
    <?php endif; ?>
</div>
