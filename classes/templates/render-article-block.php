<?php
$article_not_invoiced = null;
$can_view_prices = current_user_can('display_sales_prices');

// Logique d'alertes
if($article->Livre && !$article->invoiced){
    $article_not_invoiced = 'ispag-article-not-invoiced';
}
$article_not_delivered = null;
if(!$article->Livre && time() > $article->TimestampDateDeLivraisonFin && $article->TimestampDateDeLivraisonFin != 0){
    $article_not_delivered = 'ispag-article-not-delivered';
}
$is_qotation = filter_input(INPUT_GET, 'qotation', FILTER_VALIDATE_BOOLEAN) ?? false;


// $class_secondary = ($article->is_secondary) ? 'ispag-article-secondary' : ''; 
?>

<div class="ispag-article <?php echo $class_secondary; ?> <?php echo $article_not_invoiced; ?> <?php echo $article_not_delivered; ?>" data-article-id="<?php echo $id; ?>" data-level-secondary="<?php echo $class_secondary; ?>">
    
    <div class="ispag-loading-overlay"><div class="ispag-spinner"></div></div>

    <div class="ispag-article-visual-group">
        <input type="checkbox" class="ispag-article-checkbox" data-article-id="<?php echo $id; ?>" <?php echo $checked_attr; ?> >
        <div class="ispag-article-image">
            <?php 
            $content = str_replace('../../', '', trim($article->image));
            if (strpos($content, '<svg') === 0) echo $content; 
            else echo '<img src="' . htmlspecialchars($content, ENT_QUOTES) . '" alt="image">';
            ?>
            <?php if(!$article->customer_visible): ?>
                <span class="ispag-article-not-visible" title="<?php echo esc_attr(__('Not visible to customer', 'creation-reservoir')); ?>"><i class="fas fa-eye-slash"></i></span>
            <?php endif; ?>
        </div>
    </div>

    <div class="ispag-article-header">
        <div class="ispag-title-container">
            <span class="ispag-article-title"><?php echo esc_html(stripslashes($titre)); ?></span>
            
          
        </div>
        
        <div class="ispag-article-meta">
            <?php echo apply_filters('ispag_get_welding_text', null, $article->Id, false); ?>
        </div>

        <div class="ispag-article-buttons-row">
            <?php if (!$article->DemandeAchatOk && current_user_can('manage_order')): ?>
                <button class="ispag-btn ispag-btn-warning-outlined" style="padding: 2px 8px;">🛒</button>
            <?php endif; ?>

            <?php 
                echo apply_filters('ispag_get_technical_sheet_btn', null, $article, $deal_id);
                echo apply_filters('ispag_get_welding_certificat_btn', null, $article, $deal_id);
                if($article->Type == 1 && $article->last_doc_type['slug'] == 'drawingApproval') echo apply_filters('ispag_get_namesplate_btn', null, $article->Id);
            ?>

            <?php if (!empty($article->last_drawing_url)): 
                $url_plan = (($user_can_manage_order || $user_is_owner) && $article->last_doc_type['slug'] == 'product_drawing') 
                            ? '/validation-plan-2?drawing_id=' . $article->last_drawing_id . '&article_id=' . $id 
                            : $article->last_drawing_url;
                $text_plan = (($user_can_manage_order || $user_is_owner) && $article->last_doc_type['slug'] == 'product_drawing')
                            ? __('Check drawing for validation', 'creation-reservoir')
                            : __('Drawing', 'creation-reservoir');
                $badge_class = $article->last_doc_type['badge_class'];
                $badge_label = ($article->last_doc_type['slug'] == 'product_drawing') ? __('To be approved', 'creation-reservoir') : (($article->last_doc_type['slug'] == 'drawingApproval') ? __('Approved', 'creation-reservoir') : __($article->last_doc_type['label'], 'creation-reservoir'));
            ?>
                <div class="ispag-drawing-wrapper">
                    <a href="#" onclick="window.open('<?php echo esc_url($url_plan); ?>', '_blank', 'width=1000,height=800'); return false;" class="ispag-btn ispag-btn-secondary-outlined"><?php echo esc_html($text_plan); ?></a>
                    <span class="ispag-badge <?php echo $badge_class; ?>"><?php echo esc_html($badge_label); ?></span>
                </div>
            <?php else: echo apply_filters('ispag_get_sketch_btn', '', $article, $deal_id); endif; ?>

            <?php foreach ($article->documents as $doc): ?>
                <a href="<?php echo esc_url($doc['url']); ?>" target="_blank" class="ispag-btn ispag-btn-grey-outlined"><?php echo esc_html__($doc['label'], 'creation-reservoir'); ?></a>
            <?php endforeach; ?>
        </div>

        <div class="ispag-article-dates">
            <?php if (!empty($article->TimestampDateDeLivraison)): ?>
                <span class="date-item">📦 <?php echo ($article->Livre) ? __('Delivered on', 'creation-reservoir') : __('Delivery ETA', 'creation-reservoir'); ?> : <?php echo $article->date_livraison; ?></span>
            <?php endif; ?>
            <?php if (!empty($article->TimestampDateDeLivraison) && $can_view_prices && $article->date_facturation): ?>
                <span class="date-item">💲 <?php echo __('Invoiced on', 'creation-reservoir'); ?> : <?php echo $article->date_facturation; ?></span>
            <?php endif; ?>
        </div>
    </div>

    <?php if(!$is_qotation): ?>
    <div class="ispag-article-prices">
        <div class="ispag-article-qty"><b><?php echo $qty; ?></b> pcs</div>
        <?php if ($can_view_prices): ?>
            <div class="ispag-article-prix-net" style="color:#00a32a; font-weight:bold;"><?php echo $prix_net; ?> <?php echo get_option('wpcb_currency'); ?></div>
            <div class="ispag-article-rabais" style="font-size:0.8em; color:#888;">-<?php echo $rabais; ?>%</div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="ispag-article-actions">
        <button class="ispag-btn ispag-btn-secondary-outlined ispag-btn-view" data-article-id="<?php echo $id; ?>" title="<?php echo __('See product', 'creation-reservoir'); ?>"><i class="fas fa-search"></i></button>
        
        <?php if (($user_can_generate_tank && empty($article->DemandeAchatOk)) || $user_can_manage_order): ?>
            <button class="ispag-btn ispag-btn-warning-outlined ispag-btn-edit" data-article-id="<?php echo $id; ?>" title="<?php echo __('Edit product', 'creation-reservoir'); ?>"><i class="fas fa-edit"></i></button>
        <?php endif; ?>

        <?php if ($user_can_generate_tank || $user_can_manage_order): ?>
            <button class="ispag-btn ispag-btn-red-outlined ispag-btn-copy" data-article-id="<?php echo $id; ?>" title="<?php echo __('Replicate', 'creation-reservoir'); ?>"><i class="fas fa-copy"></i></button>
        <?php endif; ?>

        <?php 
            if ((($user_can_generate_tank && empty($article->DemandeAchatOk)) || $user_can_manage_order) && $article->Type == 1) {
                echo apply_filters('ispag_get_fitting_btn', '', $id);
                echo $article->btn_heatExchanger;
            }
        ?>

        <?php if ($user_can_manage_order): ?>
            <button class="ispag-btn ispag-btn-delete" data-article-id="<?php echo $id; ?>" title="<?php echo __('Delete', 'creation-reservoir'); ?>"><i class="fas fa-trash"></i></button>
        <?php endif; ?>
    </div>

</div>