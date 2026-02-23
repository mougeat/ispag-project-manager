<?php
/**
 * ISPAG Article Edit Modal View
 * @version     2.1.8
 */
$user_can = current_user_can('manage_order'); 
$can_view_prices = current_user_can('display_sales_prices');
?>

<div class="ispag-modal-header-v2">
    <div class="header-main">
        <div class="title-area">
            <h2>
                <?= $is_new ? __('New article', 'creation-reservoir') : __('Edit article', 'creation-reservoir') . ' : ' . esc_html(stripslashes($article->Article)) ?>
            </h2>
            <span class="ispag-badge-group"><?php echo esc_html(stripslashes($article->Groupe)) ?></span>
        </div>
        <div class="header-stats">
            <div class="stat-item">
                <span class="stat-label"><?php echo __('Status', 'creation-reservoir'); ?></span>
                <span class="stat-value" style="font-size: 14px;"><?= $is_new ? __('Creation', 'creation-reservoir') : __('Modification', 'creation-reservoir') ?></span>
            </div>
        </div>
    </div>
</div>

<div class="ispag-modal-body-scroll">
    <form class="ispag-edit-article-form" <?= $id_attr ?>>
        <input type="hidden" name="IdArticleStandard" value="<?= esc_attr($article->IdArticleStandard) ?>">
        
        <div class="ispag-modal-grid">
            <?php if ($is_new): ?>
                <input type="hidden" name="type" value="<?= esc_attr($article->Type) ?>">
            <?php endif; ?>
            
            <div class="ispag-modal-left visual-container" id="modal_img">
                <div class="image-wrapper">
                    <?php
                    $content = trim($article->image);
                    if (strpos($content, '<svg') === 0) {
                        echo $content;
                    } else {
                        $src = htmlspecialchars($content, ENT_QUOTES);
                        echo '<img src="' . $src . '" alt="image" class="responsive-svg">';
                    }
                    ?>
                </div>
            </div>

            <div class="ispag-modal-right" id="ispag-title-description-area">
                <?php if ($article->Type == 1): ?>
                    <div id="ispag-tank-form-container">
                        <?php do_action('ispag_render_tank_form', $article->Id); ?>
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
            <?php if ($user_can): ?>
            <div class="ispag-modal-left detail-block">
                <h3><span class="dashicons dashicons-calendar-alt"></span> <?= __('Logistics', 'creation-reservoir') ?></h3>
                <div class="ispag-field">
                    <label><?= __('Supplier', 'creation-reservoir') ?></label>
                    <input type="text" name="supplier" value="<?= esc_attr($article->fournisseur_nom) ?>" list="supplier-list" style="width:100%;">
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
            <?php if ($can_view_prices): ?>
            <div class="ispag-modal-left detail-block">
                <h3><span class="dashicons dashicons-cart"></span> <?= __('Pricing', 'creation-reservoir') ?></h3>
                <div class="ispag-field">
                    <label><?= __('Gross unit price', 'creation-reservoir') ?> (€)</label>
                    <input type="text" name="sales_price" value="<?= esc_attr($article->sales_price) ?>" style="width:100%;">
                </div>
                <div class="ispag-field">
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

        <div class="ispag-modal-actions" style="margin-top: 30px; padding-bottom: 20px;">
            <button type="submit" class="ispag-btn ispag-btn-red-outlined">
                <span class="dashicons dashicons-media-archive"></span> <?= __('Save', 'creation-reservoir') ?>
            </button>
            <button type="button" class="ispag-btn ispag-btn-secondary-outlined" onclick="closeIspagModal()">
                <?= __('Cancel', 'creation-reservoir') ?>
            </button>
        </div>
    </form> 
</div>