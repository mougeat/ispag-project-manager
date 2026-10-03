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

<div class="ispag-article ispag-article--row <?php echo $class_secondary; ?> <?php echo $class_archived; ?> <?php echo $article_not_invoiced; ?> <?php echo $article_not_delivered; ?>"
     data-article-id="<?php echo $id; ?>"
     data-level-secondary="<?php echo $class_secondary; ?>"
     data-badge="<?php echo esc_attr($badge_text); ?>">

    <div class="ispag-loading-overlay"><div class="ispag-spinner"></div></div>

    <div class="ispag-article-visual-group">
        <input type="checkbox" class="ispag-article-checkbox" data-article-id="<?php echo $id; ?>" <?php echo $checked_attr; ?> aria-label="<?php esc_attr_e('Select', 'creation-reservoir'); ?>">
        <div class="ispag-article-image">
            <?php echo ISPAG_Article_Repository::image_html($article->image); ?>
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

        <div class="ispag-article-chips">
            <?php if (!empty($article->IdArticleStandard) && class_exists('ISPAG_Standard_Articles_Pages') && ISPAG_Standard_Articles_Pages::can_view()): ?>
                <a href="<?php echo esc_url(ISPAG_Standard_Article_Service::article_url((int) $article->IdArticleStandard)); ?>" target="_blank" rel="noopener" class="ispag-chip ispag-chip--info ispag-chip--link ispag-std-link-btn" title="<?php echo esc_attr__('Standard article', 'creation-reservoir'); ?>">📦 <?php esc_html_e('Standard', 'creation-reservoir'); ?></a>
            <?php endif; ?>

            <?php if ($article->Livre): ?>
                <span class="ispag-chip ispag-chip--ok">✔ <?php esc_html_e('Delivered', 'creation-reservoir'); ?></span>
            <?php elseif ($article_not_delivered): ?>
                <span class="ispag-chip ispag-chip--late">⚠ <?php esc_html_e('Not delivered', 'creation-reservoir'); ?></span>
            <?php endif; ?>

            <?php if ($can_view_prices && $article->Livre && $article->invoiced): ?>
                <span class="ispag-chip ispag-chip--ok" title="<?php echo esc_attr($article->date_facturation); ?>">💲 <?php esc_html_e('Invoiced', 'creation-reservoir'); ?></span>
            <?php elseif ($can_view_prices && $article_not_invoiced): ?>
                <span class="ispag-chip ispag-chip--warn">💲 <?php esc_html_e('To be invoiced', 'creation-reservoir'); ?></span>
            <?php endif; ?>

            <?php if (!$article->DemandeAchatOk && current_user_can('manage_order')): ?>
                <span class="ispag-chip ispag-chip--warn" title="<?php echo esc_attr__('Purchase not requested yet', 'creation-reservoir'); ?>">🛒 <?php esc_html_e('To order', 'creation-reservoir'); ?></span>
            <?php endif; ?>

            <?php if (!empty($article->last_drawing_url)):
                $is_to_approve = (($user_can_manage_order || $user_is_owner) && $article->last_doc_type['slug'] == 'product_drawing');
                $url_plan = $is_to_approve
                            ? apply_filters('ispag_plan_validation_url', '/validation-plan-2?drawing_id=' . $article->last_drawing_id . '&article_id=' . $id, $id, $article->last_drawing_id)
                            : $article->last_drawing_url;
                $text_plan = $is_to_approve ? __('Check drawing for validation', 'creation-reservoir') : __('Drawing', 'creation-reservoir');
                $slug = $article->last_doc_type['slug'];
                $badge_label = ($slug == 'product_drawing') ? __('Drawing to be approved', 'creation-reservoir') : (($slug == 'drawingApproval') ? __('Drawing approved', 'creation-reservoir') : __($article->last_doc_type['label'], 'creation-reservoir'));
                $chip_class = ($slug == 'drawingApproval') ? 'ispag-chip--ok' : (($slug == 'product_drawing') ? 'ispag-chip--warn' : 'ispag-chip--info');
            ?>
                <a href="#" onclick="window.open('<?php echo esc_url($url_plan); ?>', '_blank', 'width=1000,height=800'); return false;" class="ispag-chip <?php echo $chip_class; ?> ispag-chip--link" title="<?php echo esc_attr($text_plan); ?>">📐 <?php echo esc_html($badge_label); ?></a>
            <?php endif; ?>

            <?php // Croquis : badge direct (avec un plan, réservé à l'administrateur et au chef de projet) ?>
            <?php echo apply_filters('ispag_get_sketch_chip', '', $article, $deal_id); ?>

            <?php foreach ($article->documents as $doc): ?>
                <a href="<?php echo esc_url($doc['url']); ?>" target="_blank" class="ispag-chip ispag-chip--info ispag-chip--link">📄 <?php echo esc_html__($doc['label'], 'creation-reservoir'); ?></a>
            <?php endforeach; ?>

            <?php if (!empty($article->TimestampDateDeLivraisonFin)): ?>
                <span class="ispag-chip ispag-chip--date" title="<?php echo esc_attr($article->Livre ? __('Delivered on', 'creation-reservoir') : __('Delivery ETA', 'creation-reservoir')); ?>">📦 <?php echo esc_html($article->date_livraison); ?></span>
            <?php endif; ?>
        </div>
    </div>

    <?php if(!$is_qotation): ?>
    <div class="ispag-article-prices">
        <div class="ispag-article-qty"><b><?php echo $qty; ?></b> <?php esc_html_e('pcs', 'creation-reservoir'); ?></div>
        <div class="fields-prices">
        <?php if ($can_view_prices): ?>
            <!-- AFFICHAGE DU DETAILS DE PRIX D'UNE CUVE -->
            <input type="hidden" name="tank-bare-price" id="tank-bare-price-<?php echo $article->Id; ?>">
            <input type="hidden" name="tank-accessories-price" id="tank-acc-price-<?php echo $article->Id; ?>">

            <div class="ispag-article-unit">× <span class="ispag-article-prix-net"><?php echo $prix_net; ?></span>
                <?php if ((float) $article->discount > 0 && empty($article->IdArticleMaster)): ?><span class="ispag-article-rabais">−<?php echo $rabais; ?>%</span><?php endif; ?>
            </div>
            <div class="ispag-article-total"><?php echo number_format((float) $article->prix_net_calculé * (int) $qty, 2, '.', ' '); ?> <small><?php echo esc_html(get_option('wpcb_currency')); ?></small></div>
        <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <div class="ispag-article-actions">
        <button type="button" class="ispag-btn ispag-btn-secondary-outlined ispag-btn-view" data-article-id="<?php echo $id; ?>" title="<?php echo esc_attr__('See product', 'creation-reservoir'); ?>"><i class="fas fa-search"></i></button>

        <?php if (($user_can_generate_tank && empty($article->DemandeAchatOk)) || $user_can_manage_order): ?>
            <button type="button" class="ispag-btn ispag-btn-warning-outlined ispag-btn-edit" data-article-id="<?php echo $id; ?>" data-deal-id="<?php echo $article->hubspot_deal_id; ?>" title="<?php echo esc_attr__('Edit product', 'creation-reservoir'); ?>"><i class="fas fa-edit"></i></button>
        <?php endif; ?>

        <div class="ispag-more">
            <button type="button" class="ispag-btn ispag-btn-grey-outlined ispag-more-toggle" aria-haspopup="true" aria-expanded="false" title="<?php echo esc_attr__('More actions', 'creation-reservoir'); ?>"><i class="fas fa-ellipsis-h"></i></button>
            <div class="ispag-more-menu" role="menu">
                <?php
                // Outils générés par d'autres plugins (croquis, fiche technique, certificat, plaque, raccords…)
                // Le croquis est maintenant un badge visible directement (voir plus haut)
                echo apply_filters('ispag_get_technical_sheet_btn', null, $article, $deal_id);
                echo apply_filters('ispag_get_welding_certificat_btn', null, $article, $deal_id);
                if ($article->Type == 1 && $article->last_doc_type['slug'] == 'drawingApproval') echo apply_filters('ispag_get_namesplate_btn', null, $article->Id);
                if ($article->Type == 1 && current_user_can('administrator')) {
                    echo '<button type="button" class="ispag-btn ispag-more-item" onclick="generateNoticePDF(' . esc_js($article->Id) . ', \'fr\')">' . esc_html__('Download usermanueal', 'creation-reservoir') . '</button>';
                }
                if ((($user_can_generate_tank && empty($article->DemandeAchatOk)) || $user_can_manage_order) && $article->Type == 1) {
                    echo apply_filters('ispag_get_fitting_btn', '', $id);
                    echo $article->btn_heatExchanger;
                }
                ?>
                <?php if ($user_can_generate_tank || $user_can_manage_order || $user_is_owner): ?>
                    <button type="button" class="ispag-btn ispag-btn-copy ispag-more-item" data-article-id="<?php echo $id; ?>"><i class="fas fa-copy"></i> <?php esc_html_e('Replicate', 'creation-reservoir'); ?></button>
                <?php endif; ?>
                <?php if ($user_can_manage_order): ?>
                    <button type="button" class="ispag-btn ispag-toggle-archive <?php echo esc_attr($button_class); ?> ispag-more-item"
                        data-article-id="<?php echo esc_attr($article->Id); ?>"
                        data-nonce="<?php echo esc_attr(wp_create_nonce('ispag_nonce')); ?>"><?php echo $button_icon; ?> <?php echo esc_html($button_text); ?></button>
                    <button type="button" class="ispag-btn ispag-btn-delete ispag-more-item" data-article-id="<?php echo $id; ?>"><i class="fas fa-trash"></i> <?php esc_html_e('Delete', 'creation-reservoir'); ?></button>
                <?php endif; ?>
            </div>
        </div>
    </div>

</div>
