<?php
/**
 * ISPAG Article Edit Modal View
 * @version     2.1.8
 */
$user_can = current_user_can('manage_order'); 
$can_view_prices = current_user_can('display_sales_prices');
$allow_display_sensible_info = isset($_COOKIE['ispag_allow_prices']) && $_COOKIE['ispag_allow_prices'] === 'true';
?>



<form class="ispag-edit-article-form" id="ispag-edit-article-form" <?= $id_attr ?>>
    <input type="hidden" id="current-editing-article-id" value="<?= esc_attr($article->Id ?? 0) ?>">
    <input type="hidden" name="IdArticleStandard" value="<?= esc_attr($article->IdArticleStandard ?? 0) ?>">
    <input type="hidden" name="isProjectOrPurchase" value="project">
    
    <div class="ispag-modal-grid">
        <?php if ($is_new): ?>
            <input type="hidden" name="type" value="<?= esc_attr($article->Type) ?>">
        <?php endif; ?>
        
        <div class="ispag-modal-left visual-container" id="modal_img">
            <div class="image-wrapper">
                <?php
                echo ISPAG_Article_Repository::image_html($article->image ?? '', 'responsive-svg', 50);
                ?>
            </div>
        </div>

        <div class="ispag-modal-right" id="ispag-title-description-area">
            <?php if ($article->Type == 1): ?>
                <div id="ispag-tank-form-container">
                    <?php do_action('ispag_render_tank_form', $article->Id); ?>
                </div>
            <?php elseif ($article->Type == 5): ?>
                <div class="ispag-exchanger-form-container">
                    <?php echo apply_filters('ispag_render_plate_heat_exchanger_form', $article->Id); ?>
                </div>
            
            <?php else: 
                $description = str_ireplace(['<br>', '<br />', '<br/>'], "\n", $article->Description);
                $description = stripslashes($description);
            ?>
                <div class="ispag-field">
                    <label><strong><?= __('Title', 'creation-reservoir') ?></strong></label>
                    <input type="text" name="article_title" value="<?= esc_attr(stripslashes($article->Article)) ?>" list="standard-titles" id="article-title" data-type="<?= esc_attr($article->Type) ?>" style="width:100%;">
                    <datalist id="standard-titles">
                        <?php foreach ($standard_titles['titles'] as $title): ?>
                            <option value="<?= esc_attr($title['title']) ?>" data-id="<?= esc_attr($title['id']) ?>">
                        <?php endforeach; ?>
                    </datalist> 
                </div>
                <div class="ispag-field" style="margin-top:15px;">
                    <label><strong><?= __('Description', 'creation-reservoir') ?></strong></label>
                    <textarea name="description" id="article-description" rows="10" style="width:100%;"><?= esc_textarea($description) ?></textarea>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($article->Type == 1): ?>
        <div class="ispag-modal-grid">
            <?php do_action('ispag_render_tank_dimensions_form', $article->Id); ?>
        </div>
    
    <?php endif; ?>

    <div class="ispag-modal-grid ispag-bloc-common">
        <?php if ($user_can && $allow_display_sensible_info): ?>
        <div class="ispag-modal-left detail-block">
            <h3><span class="dashicons dashicons-calendar-alt"></span> <?= __('Logistics', 'creation-reservoir') ?></h3>
            <div class="ispag-field">
                <label><?= __('Supplier', 'creation-reservoir') ?></label>
                <input type="text" name="supplier" id="tank-supplier-display" value="<?= esc_attr($article->fournisseur_nom) ?>" list="supplier-list" style="width:100%;" data-value="<?= esc_attr($article->fournisseur_nom) ?>">
                <datalist id="supplier-list">
                    <?php foreach ($standard_titles['suppliers'] as $supplier) : ?>
                        <option value="<?= esc_attr($supplier['name']) ?>" data-id="<?= esc_attr($supplier['id']) ?>">
                    <?php endforeach; ?>
                </datalist>
            </div>
            <div class="ispag-field">
                <label><?= __('Factory departure', 'creation-reservoir') ?></label>
                <input type="date" name="date_depart" value="<?= $article->TimestampDateDeLivraison ? date('Y-m-d', $article->TimestampDateDeLivraison) : '' ?>" style="width:100%;">
            </div>
            <div class="ispag-field">
                <label><?= __('Delivery ETA', 'creation-reservoir') ?></label>
                <input type="date" name="date_eta" value="<?= $article->TimestampDateDeLivraisonFin ? date('Y-m-d', $article->TimestampDateDeLivraisonFin) : '' ?>" style="width:100%;">
            </div>
        </div>
        <?php endif; ?>

        <div class="ispag-modal-right detail-block">
            <h3><span class="dashicons dashicons-admin-settings"></span> <?= __('Classification', 'creation-reservoir') ?></h3>
            <div class="ispag-field">
                <label><?= __('Group', 'creation-reservoir') ?></label>
                <input type="text" name="group" list="group-list" value="<?= esc_attr(stripslashes($article->Groupe)) ?>" style="width:100%;">
                <datalist id="group-list">
                    <?php foreach ($groupes as $groupe): ?>
                        <option value="<?= esc_attr(stripslashes($groupe)) ?>">
                    <?php endforeach; ?>
                </datalist>
            </div>

            <?php if ($user_can): ?>
                <div class="ispag-field">
                    <label><?= __('Master article', 'creation-reservoir') ?></label>
                    <select name="master_article" style="width:100%;">
                        <option value="0">—</option>
                        <?php foreach ($article->master_articles as $groupe => $articles): ?>
                            <optgroup label="<?= esc_attr($groupe) ?>">
                                <?php foreach ($articles as $article_master): ?>
                                    <option value="<?= esc_attr($article_master->Id) ?>" <?= $article->IdArticleMaster == $article_master->Id ? 'selected' : '' ?>>
                                        <?= esc_html($article_master->Article) ?>
                                    </option>
                                <?php endforeach; ?>
                            </optgroup>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>
            
            <div class="ispag-field">
                <label><?= __('Quantity', 'creation-reservoir') ?></label>
                <input type="number" name="qty" value="<?= esc_attr($article->Qty) ?>" step="any" style="width:100%;">
            </div>
        </div>
    </div>

    <div class="ispag-modal-grid">
        <?php if ($can_view_prices && $allow_display_sensible_info): ?>
        <div class="ispag-modal-left detail-block">
            <h3><span class="dashicons dashicons-cart"></span> <?= __('Pricing', 'creation-reservoir') ?></h3>
            
            <div class="ispag-field">
                <label><?= __('Gross unit price', 'creation-reservoir') ?> (€)</label>
                <input type="text" 
                    name="sales_price" 
                    class="js-sales-price-input" 
                    value="<?= esc_attr($article->prix_total_calculé) ?>" 
                    style="width:100%;" 
                    <?= checked($article->is_manual_price, 0) ? 'disabled' : '' ?>>
            </div>

            <div class="ispag-field" style="margin-top: 10px; display: flex; align-items: center; gap: 10px;">
                <input type="checkbox" 
                    name="is_manual_price" 
                    class="js-is-manual-checkbox" 
                    id="is_manual_price_<?= $article->Id ?>" 
                    value="1" 
                    <?= checked($article->is_manual_price, 1) ?>>
                <label for="is_manual_price_<?= $article->Id ?>" style="margin-bottom: 0; cursor: pointer;">
                    <?= __('Force manual price (no auto-calculation)', 'creation-reservoir') ?>
                </label>
            </div>

            <div class="ispag-field" style="margin-top: 15px;">
                <label><?= __('Discount', 'creation-reservoir') ?> (%)</label>
                <input type="text" name="discount" value="<?= esc_attr($article->discount) ?>" style="width:100%;">
            </div>
        </div>
        <?php endif; ?>

        <?php if ($user_can): ?>
        <div class="ispag-modal-right detail-block">
            <h3><span class="dashicons dashicons-yes"></span> <?= __('Workflow', 'creation-reservoir') ?></h3>
            <div class="workflow-checkboxes">
                <label><input type="checkbox" name="DemandeAchatOk" <?= $article->DemandeAchatOk ? 'checked' : '' ?>> <?= __('Purchase requested', 'creation-reservoir') ?></label><br>
                <label><input type="checkbox" name="DrawingApproved" <?= ((int)$article->DrawingApproved === 1) ? 'checked' : '' ?>> <?= __('Drawing approved', 'creation-reservoir') ?></label><br>
                <label><input type="checkbox" name="Livre" <?= $article->Livre ? 'checked' : '' ?>> <?= __('Delivered', 'creation-reservoir') ?></label><br>
                <label><input type="checkbox" name="invoiced" <?= $article->invoiced ? 'checked' : '' ?>> <?= __('Invoiced', 'creation-reservoir') ?></label><br>
            </div>
        </div>
        <?php endif; ?>
    </div>


</form> 
