<?php
if (!defined('ABSPATH')) {
    exit;
}

// Vérifier que toutes les variables nécessaires sont définies
if (!isset($article, $types, $latest_sales_price, $supplier_data)) {
    echo '<div style="color: red; padding: 20px;">Error: Missing data to display the modal</div>';
    return;
}

$currency = get_option('wpcb_currency', '€');
$type_options = '';
foreach ($types as $type) {
    $selected = selected($article['TypeArticle'], $type['Id'], false);
    $type_options .= '<option value="' . esc_attr($type['Id']) . '" ' . $selected . '>' . esc_html($type['type']) . '</option>';
}

// Récupérer l'ID de l'image depuis la base de données
$image_id = isset($article['image']) ? intval($article['image']) : 0;
$image_html = '';
if ($image_id > 0) {
    $image_html = wp_get_attachment_image($image_id, 'medium', false, [
        'alt' => esc_attr($article['TitreArticle']),
        'class' => 'responsive-svg',
        'style' => 'max-width: 100%; height: auto;'
    ]);
}

// Préparer les données des fournisseurs pour le tableau
// On suppose que $supplier_data contient les informations d'un seul fournisseur
// Si vous avez plusieurs fournisseurs, il faudra adapter cette partie
$suppliers = [];
if (!empty($supplier_data['supplier_id'])) {
    $suppliers[] = [
        'nom' => $supplier_data['fournisseur_nom'],
        'ref' => $supplier_data['supplier_reference'] ?? '',
        'description' => $supplier_data['supplier_description'] ?? '',
        'prix_achat' => $supplier_data['purchase_price'] ?? 0,
        'discount' => $supplier_data['discount'] ?? 0
    ];
}
?>

<div class="ispag-modal-header-v2">
    <div class="header-main">
        <div class="title-area">
            <!-- Titre éditable en ligne -->
            <h2 id="editable-article-title"
                class="ispag-editable-title"
                contenteditable="true"
                spellcheck="false"
                data-article-id="<?php echo esc_attr($article['Id']); ?>"
                data-field="TitreArticle"
                style="margin-top: 0px; font-size: 1.8rem; border-bottom: 1px dashed transparent; cursor: pointer;">
                <?php echo esc_html($article['TitreArticle']); ?>
            </h2>
        </div>
        <div class="header-stats">
            <div class="stat-item">
                <span class="stat-label"><?php _e('Status', 'creation-reservoir'); ?></span>
                <span class="stat-value" style="font-size: 14px;"><?php _e('Modification', 'creation-reservoir'); ?></span>
            </div>
        </div>
    </div>
</div>

<div class="ispag-modal-body-scroll">
    <form id="ispag-edit-article-form">
        <input type="hidden" name="article_id" value="<?php echo esc_attr($article['Id']); ?>">
        <input type="hidden" name="action" value="ispag_update_article">
        <input type="hidden" name="nonce" value="<?php echo wp_create_nonce('ispag_update_article_nonce'); ?>">
        <input type="hidden" name="image" id="article-image-id" value="<?php echo esc_attr($image_id); ?>">

        <div class="ispag-modal-grid">
            <!-- Partie gauche: UNIQUEMENT l'image -->
            <div class="ispag-modal-left visual-container" id="modal_img" style="width: 200px;">
                <div class="image-wrapper" style="margin-bottom: 20px; text-align: center; min-height: 200px; display: flex; align-items: center; justify-content: center; border: 1px dashed #ddd; padding: 10px;">
                    <?php if ($image_id > 0): ?>
                        <?php echo $image_html; ?>
                    <?php else: ?>
                        <div style="color: #8c8f94;">
                            <span class="dashicons dashicons-format-gallery" style="font-size: 48px; display: block;"></span>
                            <p style="margin: 10px 0 0 0;"><?php _e('No image', 'creation-reservoir'); ?></p>
                        </div>
                    <?php endif; ?>
                </div>
                <input type="file" name="image" id="article-image-upload" style="display: none;" accept="image/*">
            </div>

            <!-- Partie droite: Tous les détails de l'article (y compris Type et Référence) -->
            <div class="ispag-modal-right detail-block">
                <h3><span class="dashicons dashicons-admin-settings"></span> <?php _e('Article Details', 'creation-reservoir'); ?></h3>

                <!-- Type d'article éditable -->
                <div class="ispag-field" style="margin-bottom: 15px;">
                    <label><strong><?php _e('Type', 'creation-reservoir'); ?></strong></label>
                    <select name="TypeArticle"
                            class="ispag-editable-field"
                            data-field="TypeArticle"
                            style="width: 100%; padding: 8px;">
                        <?php echo $type_options; ?>
                    </select>
                </div>

                <!-- Référence ISPAG éditable -->
                <div class="ispag-field" style="margin-bottom: 15px;">
                    <label><strong><?php _e('ISPAG Reference', 'creation-reservoir'); ?></strong></label>
                    <div class="ispag-editable-div"
                         contenteditable="true"
                         data-field="ref_article_ispag"
                         style="width: 100%; padding: 8px; border: 1px dashed transparent; min-height: 36px;">
                        <?php echo esc_html($article['ref_article_ispag']); ?>
                    </div>
                    <input type="hidden" name="ref_article_ispag" value="<?php echo esc_attr($article['ref_article_ispag']); ?>">
                </div>

                <!-- Description éditable -->
                <div class="ispag-field" style="margin-bottom: 15px;">
                    <label><strong><?php _e('Description', 'creation-reservoir'); ?></strong></label>
                    <div class="ispag-editable-div"
                         contenteditable="true"
                         data-field="description_ispag"
                         style="width: 100%; padding: 8px; border: 1px dashed transparent; min-height: 100px;">
                        <?php echo esc_html($article['description_ispag'] ?? $article['Description'] ?? ''); ?>
                    </div>
                    <input type="hidden" name="description_ispag" value="<?php echo esc_attr($article['description_ispag'] ?? $article['Description'] ?? ''); ?>">
                </div>

                <!-- Délai de livraison éditable -->
                <div class="ispag-field" style="margin-bottom: 15px;">
                    <label><strong><?php _e('Delivery Time', 'creation-reservoir'); ?> (<?php _e('days', 'creation-reservoir'); ?>)</strong></label>
                    <div class="ispag-editable-div"
                         contenteditable="true"
                         data-field="delivery_time"
                         style="width: 100%; padding: 8px; border: 1px dashed transparent; min-height: 36px;">
                        <?php echo esc_html($article['delivery_time']); ?>
                    </div>
                    <input type="hidden" name="delivery_time" value="<?php echo esc_attr($article['delivery_time']); ?>">
                </div>

                <!-- Poids et unité -->
                <div style="display: flex; gap: 10px; margin-bottom: 15px;">
                    <div class="ispag-field" style="flex: 1;">
                        <label><strong><?php _e('Weight', 'creation-reservoir'); ?></strong></label>
                        <div class="ispag-editable-div"
                             contenteditable="true"
                             data-field="Poids"
                             style="width: 100%; padding: 8px; border: 1px dashed transparent; min-height: 36px;">
                            <?php echo esc_html($article['Poids']); ?>
                        </div>
                        <input type="hidden" name="Poids" value="<?php echo esc_attr($article['Poids']); ?>">
                    </div>
                    <div class="ispag-field" style="flex: 1;">
                        <label><strong><?php _e('Unit', 'creation-reservoir'); ?></strong></label>
                        <div class="ispag-editable-div"
                             contenteditable="true"
                             data-field="UnitePoids"
                             style="width: 100%; padding: 8px; border: 1px dashed transparent; min-height: 36px;">
                            <?php echo esc_html($article['UnitePoids']); ?>
                        </div>
                        <input type="hidden" name="UnitePoids" value="<?php echo esc_attr($article['UnitePoids']); ?>">
                    </div>
                </div>

                <!-- Dernier prix de vente (non éditable) -->
                <div class="ispag-field" style="margin-bottom: 15px;">
                    <label><strong><?php _e('Last Sales Price', 'creation-reservoir'); ?> (<?php echo esc_html($currency); ?>)</strong></label>
                    <div style="width: 100%; padding: 8px; background: #f9f9f9; border-radius: var(--ispag-btn-border-radius);">
                        <?php echo esc_html(number_format($latest_sales_price, 2, '.', '\'')); ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Partie achat (en bas) - Tableau des fournisseurs -->
        <div class="ispag-modal-full detail-block" style="margin-top: 20px;">
            <h3><span class="dashicons dashicons-cart"></span> <?php _e('Suppliers Information', 'creation-reservoir'); ?></h3>

            <?php
            // Récupérer les fournisseurs (remplacez get_supplier_data par get_suppliers_data)
            $suppliers = $this->get_suppliers_data($article['Id']);
            ?>

            <table class="ispag-purchase-table" style="width: 100%; border-collapse: collapse;">
                <thead>
                    <tr>
                        <th style="padding: 8px; border: 1px solid #ddd; background: #f9f9f9; text-align: left;"><?php _e('Supplier Name', 'creation-reservoir'); ?></th>
                        <th style="padding: 8px; border: 1px solid #ddd; background: #f9f9f9; text-align: left;"><?php _e('Reference', 'creation-reservoir'); ?></th>
                        <th style="padding: 8px; border: 1px solid #ddd; background: #f9f9f9; text-align: left;"><?php _e('Description', 'creation-reservoir'); ?></th>
                        <th style="padding: 8px; border: 1px solid #ddd; background: #f9f9f9; text-align: left;"><?php _e('Purchase Price', 'creation-reservoir'); ?> (<?php echo esc_html($currency); ?>)</th>
                        <th style="padding: 8px; border: 1px solid #ddd; background: #f9f9f9; text-align: left;"><?php _e('Discount', 'creation-reservoir'); ?> (%)</th>
                        <th style="padding: 8px; border: 1px solid #ddd; background: #f9f9f9; text-align: left;"><?php _e('Actions', 'creation-reservoir'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($suppliers)): ?>
                        <tr>
                            <td colspan="6" style="padding: 8px; border: 1px solid #ddd; text-align: center;">
                                <?php _e('No suppliers for this article', 'creation-reservoir'); ?>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($suppliers as $index => $supplier): ?>
                            <tr>
                                <td style="padding: 8px; border: 1px solid #ddd;">
                                    <div class="ispag-editable-div"
                                        contenteditable="true"
                                        data-field="suppliers[<?php echo $index; ?>][fournisseur_nom]"
                                        style="width: 100%; padding: 8px; border: 1px dashed transparent; min-height: 36px;">
                                        <?php echo esc_html($supplier['fournisseur_nom'] ?? ''); ?>
                                    </div>
                                    <input type="hidden" name="suppliers[<?php echo $index; ?>][fournisseur_id]" value="<?php echo esc_attr($supplier['fournisseur_id'] ?? ''); ?>">
                                    <input type="hidden" name="suppliers[<?php echo $index; ?>][fournisseur_nom]" value="<?php echo esc_attr($supplier['fournisseur_nom'] ?? ''); ?>">
                                </td>
                                <td style="padding: 8px; border: 1px solid #ddd;">
                                    <div class="ispag-editable-div"
                                        contenteditable="true"
                                        data-field="suppliers[<?php echo $index; ?>][fournisseur_ref]"
                                        style="width: 100%; padding: 8px; border: 1px dashed transparent; min-height: 36px;">
                                        <?php echo esc_html($supplier['fournisseur_ref'] ?? ''); ?>
                                    </div>
                                    <input type="hidden" name="suppliers[<?php echo $index; ?>][fournisseur_ref]" value="<?php echo esc_attr($supplier['fournisseur_ref'] ?? ''); ?>">
                                </td>
                                <td style="padding: 8px; border: 1px solid #ddd;">
                                    <div class="ispag-editable-div"
                                        contenteditable="true"
                                        data-field="suppliers[<?php echo $index; ?>][fournisseur_description]"
                                        style="width: 100%; padding: 8px; border: 1px dashed transparent; min-height: 36px;">
                                        <?php echo esc_html($supplier['fournisseur_description'] ?? ''); ?>
                                    </div>
                                    <input type="hidden" name="suppliers[<?php echo $index; ?>][fournisseur_description]" value="<?php echo esc_attr($supplier['fournisseur_description'] ?? ''); ?>">
                                </td>
                                <td style="padding: 8px; border: 1px solid #ddd;">
                                    <div class="ispag-editable-div"
                                        contenteditable="true"
                                        data-field="suppliers[<?php echo $index; ?>][prix_achat]"
                                        style="width: 100%; padding: 8px; border: 1px dashed transparent; min-height: 36px;">
                                        <?php echo esc_html(number_format($supplier['prix_achat'] ?? 0, 2, '.', '\'')); ?>
                                    </div>
                                    <input type="hidden" name="suppliers[<?php echo $index; ?>][prix_achat]" value="<?php echo esc_attr($supplier['prix_achat'] ?? ''); ?>">
                                </td>
                                <td style="padding: 8px; border: 1px solid #ddd;">
                                    <div class="ispag-editable-div"
                                        contenteditable="true"
                                        data-field="suppliers[<?php echo $index; ?>][discount]"
                                        style="width: 100%; padding: 8px; border: 1px dashed transparent; min-height: 36px;">
                                        <?php echo esc_html($supplier['discount'] ?? ''); ?>
                                    </div>
                                    <input type="hidden" name="suppliers[<?php echo $index; ?>][discount]" value="<?php echo esc_attr($supplier['discount'] ?? ''); ?>">
                                </td>
                                <td style="padding: 8px; border: 1px solid #ddd; text-align: center;">
                                    <button type="button" class="ispag-btn ispag-btn-danger-outlined ispag-remove-supplier"
                                            data-supplier-index="<?php echo $index; ?>"
                                            style="padding: 2px 6px; font-size: 12px;">
                                        <span class="dashicons dashicons-trash"></span>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

            <button type="button" class="ispag-btn ispag-btn-secondary-outlined ispag-add-supplier" style="margin-top: 10px;">
                <span class="dashicons dashicons-plus"></span> <?php _e('Add Supplier', 'creation-reservoir'); ?>
            </button>
        </div>

        <div class="ispag-modal-actions" style="margin-top: 30px; padding-bottom: 20px;">
            <button type="submit" class="ispag-btn ispag-btn-secondary">
                <span class="dashicons dashicons-media-archive"></span> <?php _e('Save', 'creation-reservoir'); ?>
            </button>
            <button type="button" class="ispag-btn ispag-btn-secondary-outlined" id="ispag-cancel-edit">
                <?php _e('Cancel', 'creation-reservoir'); ?>
            </button>
        </div>
    </form>
</div>
