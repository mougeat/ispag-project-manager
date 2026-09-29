<?php
$article_not_invoiced = null;
$badge_text = ''; // Initialisation de la variable badge

$can_view_prices = current_user_can('display_sales_prices');

// Logique d'alertes
if($article->Livre && !$article->invoiced){
    $article_not_invoiced = 'ispag-article-not-invoiced';
    $badge_text = __('To be invoiced', 'creation-reservoir'); // Traductible (A facturer)
}

$article_not_delivered = null;
if(!$article->Livre && time() > $article->TimestampDateDeLivraisonFin && $article->TimestampDateDeLivraisonFin != 0){
    $article_not_delivered = 'ispag-article-not-delivered';
    if (empty($badge_text)) {
        $badge_text = __('Not delivered', 'creation-reservoir'); // Traductible (Non livré)
    }
}
$is_qotation = filter_input(INPUT_GET, 'qotation', FILTER_VALIDATE_BOOLEAN) ?? false;
?>

<div class="ispag-article <?php echo $class_secondary; ?> <?php echo $class_archived; ?> <?php echo $article_not_invoiced; ?> <?php echo $article_not_delivered; ?>" 
     data-article-id="<?php echo $id; ?>" 
     data-level-secondary="<?php echo $class_secondary; ?>"
     data-badge="<?php echo esc_attr($badge_text); ?>">
    
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
                <span class="ispag-drawing-wrapper">
                    <a href="#" onclick="window.open('<?php echo esc_url($url_plan); ?>', '_blank', 'width=1000,height=800'); return false;" class="ispag-btn ispag-btn-secondary-outlined"><?php echo esc_html($text_plan); ?></a>
                    <span class="ispag-badge <?php echo $badge_class; ?>"><?php echo esc_html($badge_label); ?></span>
                </span>
            <?php 
             endif; 
            if (empty($article->last_drawing_url) || $user_can_manage_order): 
                echo apply_filters('ispag_get_sketch_btn', '', $article, $deal_id);
            endif; ?>

            <?php 
                echo apply_filters('ispag_get_technical_sheet_btn', null, $article, $deal_id);
                echo apply_filters('ispag_get_welding_certificat_btn', null, $article, $deal_id);
                if($article->Type == 1 && $article->last_doc_type['slug'] == 'drawingApproval') echo apply_filters('ispag_get_namesplate_btn', null, $article->Id);
            ?>

            <?php
            if ($article->Type == 1 && current_user_can('administrator')) {
                echo '<p>';
                echo '<button class="ispag-btn ispag-btn-secondary-outlined" onclick="generateNoticePDF(' . esc_js($article->Id) . ', \'fr\')">' . __('Download usermanueal', 'creation-reservoir') . '</button>';
                echo '</p>';
            }
            ?>

            <?php foreach ($article->documents as $doc): ?>
                <a href="<?php echo esc_url($doc['url']); ?>" target="_blank" class="ispag-btn ispag-btn-grey-outlined"><?php echo esc_html__($doc['label'], 'creation-reservoir'); ?></a>
            <?php endforeach; ?>
        </div>

        <div class="ispag-article-dates">
            <?php if (!empty($article->TimestampDateDeLivraisonFin)): ?>
                <span class="date-item">📦<?php echo ($article->Livre) ? __('Delivered on', 'creation-reservoir') : __('Delivery ETA', 'creation-reservoir'); ?> : <?php echo $article->date_livraison; ?></span>
            <?php endif; ?>
            <?php if (!empty($article->TimestampDateDeLivraisonFin) && $can_view_prices && $article->date_facturation): ?>
                <span class="date-item">💲<?php echo __('Invoiced on', 'creation-reservoir'); ?> : <?php echo $article->date_facturation; ?></span>
            <?php endif; ?>
        </div>
    </div>

    <?php if(!$is_qotation): ?>
    <div class="ispag-article-prices">
        <div class="ispag-article-qty"><b><?php echo $qty; ?></b> pcs</div>
        <div class="fields-prices">
        <?php if ($can_view_prices): ?>
        
            
            <!-- AFFICHAGE DU DETAILS DE PRIX D'UNE CUVE -->
            <input type="hidden" name="tank-bare-price" id="tank-bare-price-<?php echo $article->Id; ?>">
            <input type="hidden" name="tank-accessories-price" id="tank-acc-price-<?php echo $article->Id; ?>">


            <div class="ispag-article-prix-net" style="color:#e74c3c; font-weight:bold;"><?php echo number_format((float)$article->prix_total_calculé, 2, '.', ' ') ?> <small><?php echo get_option('wpcb_currency'); ?></small></div>
            <div class="ispag-article-rabais" style="font-size:0.8em; color:#888;">-<?php echo $rabais; ?>%</div>
            <div class="ispag-article-prix-net" style="color:#00a32a; font-weight:bold;"><?php echo $prix_net; ?> <?php echo get_option('wpcb_currency'); ?></div>
            
        <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <div class="ispag-article-actions">
        <button class="ispag-btn ispag-btn-secondary-outlined ispag-btn-view" data-article-id="<?php echo $id; ?>" title="<?php echo __('See product', 'creation-reservoir'); ?>"><i class="fas fa-search"></i></button>
        
        <?php if (($user_can_generate_tank && empty($article->DemandeAchatOk)) || $user_can_manage_order): ?>
            <button class="ispag-btn ispag-btn-warning-outlined ispag-btn-edit" data-article-id="<?php echo $id; ?>" data-deal-id="<?php echo $article->hubspot_deal_id; ?>" title="<?php echo __('Edit product', 'creation-reservoir'); ?>"><i class="fas fa-edit"></i></button>
        <?php endif; ?>

        <?php 
            if ((($user_can_generate_tank && empty($article->DemandeAchatOk)) || $user_can_manage_order) && $article->Type == 1) {
                echo apply_filters('ispag_get_fitting_btn', '', $id);
                echo $article->btn_heatExchanger;
            }
        ?>

        <?php if ($user_can_generate_tank || $user_can_manage_order): ?>
            <button class="ispag-btn ispag-btn-red-outlined ispag-btn-copy" data-article-id="<?php echo $id; ?>" title="<?php echo __('Replicate', 'creation-reservoir'); ?>"><i class="fas fa-copy"></i></button>
        <?php endif; ?>

        <?php if ($user_can_manage_order): ?>
        <button
            class="ispag-btn ispag-btn-red-outlined ispag-toggle-archive <?php echo esc_attr($button_class); ?>"
            data-article-id="<?php echo esc_attr($article->Id); ?>"
            data-nonce="<?php echo esc_attr(wp_create_nonce('ispag_nonce')); ?>"
            title="<?php echo $button_text; ?>";
        >
            <?php echo $button_icon; ?>
        </button>
        <?php endif; ?>

        <?php if ($user_can_manage_order): ?>
            <button class="ispag-btn ispag-btn-delete" data-article-id="<?php echo $id; ?>" title="<?php echo __('Delete', 'creation-reservoir'); ?>"><i class="fas fa-trash"></i></button>
        <?php endif; ?>

    </div>

</div>