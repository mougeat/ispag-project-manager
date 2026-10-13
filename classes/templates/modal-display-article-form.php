<?php
defined('ABSPATH') || exit;
/**
 * ISPAG Article Edit Modal View
 * @version     2.1.8
 */
$user_can = current_user_can('manage_order'); 
$can_view_prices = current_user_can('display_sales_prices');
// Prix affichés par défaut ; seul « Masquer les prix » (cookie ispag_allow_prices = false) les cache (même règle que price-visibility.js)
$allow_display_sensible_info = !(isset($_COOKIE['ispag_allow_prices']) && $_COOKIE['ispag_allow_prices'] === 'false');
?>



<form class="ispag-edit-article-form" id="ispag-edit-article-form" <?= $id_attr ?>>
    <input type="hidden" id="current-editing-article-id" value="<?= esc_attr($article->Id ?? 0) ?>">
    <input type="hidden" name="IdArticleStandard" value="<?= esc_attr($article->IdArticleStandard ?? 0) ?>">
    <input type="hidden" name="isProjectOrPurchase" value="project">
    
    <div class="ispag-art-top">
        <?php if ($is_new): ?>
            <input type="hidden" name="type" value="<?= esc_attr($article->Type) ?>">
        <?php endif; ?>

        <div class="ispag-art-main" id="ispag-title-description-area">
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
                $has_standard = !empty($article->IdArticleStandard);
            ?>
                <div class="ispag-field ispag-field--title">
                    <label for="article-title"><strong><?= __('Title', 'creation-reservoir') ?></strong></label>
                    <input type="text" name="article_title" value="<?= esc_attr(stripslashes($article->Article)) ?>" list="standard-titles" id="article-title" data-type="<?= esc_attr($article->Type) ?>" autocomplete="off" placeholder="<?= esc_attr__('Type to search the standard articles…', 'creation-reservoir') ?>">
                    <datalist id="standard-titles">
                        <?php foreach ($standard_titles['titles'] as $title): ?>
                            <option value="<?= esc_attr($title['title']) ?>" data-id="<?= esc_attr($title['id']) ?>">
                        <?php endforeach; ?>
                    </datalist>
                    <div class="ispag-standard-banner" id="ispag-standard-banner"<?= $has_standard ? '' : ' hidden' ?>>
                        <span class="dashicons dashicons-yes-alt"></span>
                        <span class="ispag-standard-text"><?= __('Standard article: fields filled in from the catalogue', 'creation-reservoir') ?></span>
                        <button type="button" class="ispag-standard-detach"><?= __('Detach', 'creation-reservoir') ?></button>
                    </div>
                </div>

                <div class="ispag-art-row">
                    <div class="ispag-field">
                        <label><?= __('Group', 'creation-reservoir') ?></label>
                        <input type="text" name="group" list="group-list" value="<?= esc_attr(stripslashes($article->Groupe)) ?>" autocomplete="off">
                        <datalist id="group-list">
                            <?php foreach ($groupes as $groupe): ?>
                                <option value="<?= esc_attr(stripslashes($groupe)) ?>">
                            <?php endforeach; ?>
                        </datalist>
                    </div>
                    <div class="ispag-field ispag-field--qty">
                        <label><?= __('Quantity', 'creation-reservoir') ?></label>
                        <input type="number" name="qty" value="<?= esc_attr($article->Qty) ?>" step="any" min="0">
                    </div>
                    <?php if ($user_can): ?>
                        <div class="ispag-field">
                            <label><?= __('Master article', 'creation-reservoir') ?></label>
                            <select name="master_article">
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
                </div>

                <div class="ispag-field">
                    <label for="article-description"><strong><?= __('Description', 'creation-reservoir') ?></strong></label>
                    <textarea name="description" id="article-description" rows="8"><?= esc_textarea($description) ?></textarea>
                </div>
            <?php endif; ?>
        </div>

        <aside class="ispag-art-side">
            <div class="ispag-modal-left visual-container" id="modal_img">
                <div class="image-wrapper">
                    <?php echo ISPAG_Article_Repository::image_html($article->image ?? '', 'responsive-svg', 50); ?>
                </div>
            </div>
            <?php if ($can_view_prices && $allow_display_sensible_info && $article->Type != 1 && $article->Type != 5): ?>
                <div class="ispag-art-summary" id="ispag-art-summary" aria-live="polite">
                    <span class="ispag-art-summary-label"><?= __('Line total', 'creation-reservoir') ?></span>
                    <span class="ispag-art-summary-total" id="ispag-art-summary-total">—</span>
                    <span class="ispag-art-summary-note" id="ispag-art-summary-note"></span>
                </div>
            <?php endif; ?>
            <?php if ($user_can): ?>
                <label class="ispag-variant-toggle" for="is_variant_<?= (int) ($article->Id ?? 0) ?>">
                    <span class="ispag-variant-text">
                        <strong><?= __('Variant', 'creation-reservoir') ?></strong>
                        <small><?= __('Not counted in the total sales price nor in the project costs', 'creation-reservoir') ?></small>
                    </span>
                    <input type="hidden" name="has_variant_field" value="1">
                    <input type="checkbox" class="ispag-sw-input" name="is_variant" id="is_variant_<?= (int) ($article->Id ?? 0) ?>" value="1" <?= !empty($article->is_variant) ? 'checked' : '' ?>>
                    <span class="ispag-variant-state"><span class="is-on"><?= __('Yes', 'creation-reservoir') ?></span><span class="is-off"><?= __('No', 'creation-reservoir') ?></span></span>
                </label>
            <?php endif; ?>
        </aside>
    </div>

    <?php if ($article->Type == 1): ?>
        <div class="ispag-modal-grid">
            <?php do_action('ispag_render_tank_dimensions_form', $article->Id); ?>
        </div>
    
    <?php endif; ?>

    <div class="ispag-art-cards ispag-bloc-common">
        <?php if ($user_can && $allow_display_sensible_info): ?>
        <div class="ispag-art-card detail-block">
            <h3><span class="dashicons dashicons-calendar-alt"></span> <?= __('Logistics', 'creation-reservoir') ?></h3>
            <div class="ispag-field">
                <label><?= __('Supplier', 'creation-reservoir') ?></label>
                <input type="text" name="supplier" id="tank-supplier-display" value="<?= esc_attr($article->fournisseur_nom) ?>" list="supplier-list" data-value="<?= esc_attr($article->fournisseur_nom) ?>" autocomplete="off">
                <datalist id="supplier-list">
                    <?php foreach ($standard_titles['suppliers'] as $supplier) : ?>
                        <option value="<?= esc_attr($supplier['name']) ?>" data-id="<?= esc_attr($supplier['id']) ?>">
                    <?php endforeach; ?>
                </datalist>
            </div>
            <div class="ispag-art-row">
                <div class="ispag-field">
                    <label><?= __('Factory departure', 'creation-reservoir') ?></label>
                    <input type="date" name="date_depart" value="<?= $article->TimestampDateDeLivraison ? date('Y-m-d', $article->TimestampDateDeLivraison) : '' ?>">
                </div>
                <div class="ispag-field">
                    <label><?= __('Delivery ETA', 'creation-reservoir') ?></label>
                    <input type="date" name="date_eta" value="<?= $article->TimestampDateDeLivraisonFin ? date('Y-m-d', $article->TimestampDateDeLivraisonFin) : '' ?>">
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($can_view_prices && $allow_display_sensible_info): ?>
        <div class="ispag-art-card detail-block">
            <h3><span class="dashicons dashicons-cart"></span> <?= __('Pricing', 'creation-reservoir') ?></h3>
            <div class="ispag-art-row">
                <div class="ispag-field ispag-field--price">
                    <label><?= __('Gross unit price', 'creation-reservoir') ?> (€)</label>
                    <input type="text" name="sales_price" class="js-sales-price-input" value="<?= esc_attr($article->prix_total_calculé) ?>" <?= checked($article->is_manual_price, 0) ? 'disabled' : '' ?>>
                </div>
                <div class="ispag-field ispag-field--discount">
                    <label><?= __('Discount', 'creation-reservoir') ?> (%)</label>
                    <input type="text" name="discount" value="<?= esc_attr($article->discount) ?>">
                </div>
            </div>
            <div class="ispag-art-checks">
                <label for="is_manual_price_<?= $article->Id ?>">
                    <input type="checkbox" name="is_manual_price" class="js-is-manual-checkbox" id="is_manual_price_<?= $article->Id ?>" value="1" <?= checked($article->is_manual_price, 1) ?>>
                    <?= __('Force manual price (no auto-calculation)', 'creation-reservoir') ?>
                </label>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <?php if ($article->Type == 1 && !$is_new && !empty($article->Id)): ?>
        <div class="ispag-field ispag-open-fittings-row" style="margin: 10px 0 0;">
            <button type="button" class="ispag-btn ispag-btn-grey-outlined ispag-open-fittings-from-edit" data-article-id="<?= esc_attr($article->Id) ?>">
                <span class="dashicons dashicons-admin-tools"></span> <?= __('Fittings', 'creation-reservoir') ?>
            </button>
        </div>
    <?php endif; ?>

    <?php if ($article->Type == 1): ?>
        <?php do_action('ispag_render_tank_comments_form', $article->Id); ?>
    <?php endif; ?>

    <?php if ($article->Type == 1 || $article->Type == 5): ?>
    <div class="ispag-art-cards">
        <div class="ispag-art-card detail-block">
            <h3><span class="dashicons dashicons-admin-settings"></span> <?= __('Classification', 'creation-reservoir') ?></h3>
            <div class="ispag-art-row">
                <div class="ispag-field">
                    <label><?= __('Group', 'creation-reservoir') ?></label>
                    <input type="text" name="group" list="group-list" value="<?= esc_attr(stripslashes($article->Groupe)) ?>" autocomplete="off">
                    <datalist id="group-list">
                        <?php foreach ($groupes as $groupe): ?>
                            <option value="<?= esc_attr(stripslashes($groupe)) ?>">
                        <?php endforeach; ?>
                    </datalist>
                </div>
                <div class="ispag-field ispag-field--qty">
                    <label><?= __('Quantity', 'creation-reservoir') ?></label>
                    <input type="number" name="qty" value="<?= esc_attr($article->Qty) ?>" step="any" min="0">
                </div>
            </div>
            <?php if ($user_can): ?>
                <div class="ispag-field">
                    <label><?= __('Master article', 'creation-reservoir') ?></label>
                    <select name="master_article">
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
        </div>
    </div>
    <?php endif; ?>

    <?php if ($user_can): ?>
    <div class="ispag-art-steps-wrap">
        <h3><span class="dashicons dashicons-yes"></span> <?= __('Workflow', 'creation-reservoir') ?></h3>
        <div class="ispag-art-steps workflow-checkboxes" role="group">
            <label class="ispag-art-step"><input type="checkbox" name="DemandeAchatOk" <?= $article->DemandeAchatOk ? 'checked' : '' ?>><span><i>1</i> <?= __('Purchase requested', 'creation-reservoir') ?></span></label>
            <label class="ispag-art-step"><input type="checkbox" name="DrawingApproved" <?= ((int)$article->DrawingApproved === 1) ? 'checked' : '' ?>><span><i>2</i> <?= __('Drawing approved', 'creation-reservoir') ?></span></label>
            <label class="ispag-art-step"><input type="checkbox" name="Livre" <?= $article->Livre ? 'checked' : '' ?>><span><i>3</i> <?= __('Delivered', 'creation-reservoir') ?></span></label>
            <label class="ispag-art-step"><input type="checkbox" name="invoiced" <?= $article->invoiced ? 'checked' : '' ?>><span><i>4</i> <?= __('Invoiced', 'creation-reservoir') ?></span></label>
        </div>
    </div>
    <?php endif; ?>

</form> 
