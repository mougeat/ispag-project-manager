<li class="document-item" data-doc-id="<?php echo esc_attr($doc->IdMedia); ?>">
    <a href="<?php echo esc_url($doc->file_url); ?>" target="_blank" data-media-id="<?php echo esc_attr($doc->IdMedia); ?>">
        <div class="doc-badge badge-<?php echo esc_attr($doc->ClassCss); ?>">
            <?php echo esc_html__($doc->label, 'creation-reservoir'); ?>
        </div>
        <?php echo $icon; ?> <?php echo esc_html($doc->post_title); ?>
    </a>
    <div class='doc-meta'>
        <?php echo  __('Added by', 'creation-reservoir') . ' ' . esc_html($doc->display_name) . ' ' .  __('on', 'creation-reservoir')  . ' ' .  date('d.m.Y H:i', strtotime($doc->dateReadable)); ?>
    </div>
    <?php if(current_user_can( 'manage_order' )) : ?>
        <button class="ispag-btn ispag-btn-grey-outlined extract-doc-btn" style="cursor:pointer;" title="<?php _e('Extract data', 'creation-reservoir'); ?>" data-doc-id="<?php echo esc_attr($doc->IdMedia); ?>" data-deal-id="<?php echo esc_attr($doc->hubspot_deal_id ?? null); ?>" data-purchase-id="<?php echo esc_attr($doc->purchase_order); ?>" data-doc-type="<?php echo esc_attr($doc->ClassCss); ?>" data-tank-id="<?php echo esc_attr($doc->Historique); ?>">
            <span class="dashicons dashicons-analytics"></span>
        </button>
    <?php endif; ?>
    <?php if(current_user_can( 'manage_order' )) : ?>
        <button class="ispag-btn ispag-btn-grey-outlined delete-doc-btn" style="cursor:pointer;" title="<?php _e('Delete document', 'creation-reservoir'); ?>">
            <span class="dashicons dashicons-trash"></span>
        </button>        
    <?php endif; ?>
</li>
 