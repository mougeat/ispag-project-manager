<?php /** @var array $d  Voir ISPAG_Article_View */ ?>
<div class="ispag-av">
    <div class="ispag-av-main">
        <figure class="ispag-av-image"><?php echo $d['image_html']; ?></figure>

        <section class="ispag-av-card ispag-av-description">
            <div class="ispag-av-card-head">
                <h3><?php esc_html_e('Description', 'creation-reservoir'); ?></h3>
                <button type="button" class="ispag-btn-copy-description" data-target="#article-description" title="<?php echo esc_attr__('Copy', 'creation-reservoir'); ?>">📋</button>
            </div>
            <div id="article-description" class="description-content">
                <?php echo wp_kses_post(nl2br(stripslashes((string) $d['description']))); ?>
            </div>
        </section>
    </div>

    <aside class="ispag-av-side">
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

        <?php if (!empty($d['is_staff']) && !empty($d['steps'])): ?>
        <section class="ispag-av-card">
            <div class="ispag-av-card-head"><h3><span class="dashicons dashicons-forms"></span> <?php esc_html_e('Progress', 'creation-reservoir'); ?></h3></div>
            <ol class="ispag-av-steps">
                <?php foreach ($d['steps'] as $step): $ok = !empty($step[1]); ?>
                    <li class="<?php echo $ok ? 'is-done' : 'is-pending'; ?>">
                        <span class="ispag-av-step-dot"><?php echo $ok ? '✔' : ''; ?></span>
                        <span class="ispag-av-step-label"><?php echo esc_html($step[0]); ?></span>
                        <span class="ispag-av-step-state"><?php echo $ok ? esc_html__('Completed', 'creation-reservoir') : esc_html__('Pending', 'creation-reservoir'); ?></span>
                    </li>
                <?php endforeach; ?>
            </ol>
        </section>
        <?php endif; ?>

        <?php if (!empty($d['documents']) || !empty($d['tools'])): ?>
        <section class="ispag-av-card">
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
    </aside>
</div>
