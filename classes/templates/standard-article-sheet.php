<?php
/**
 * Fiche d'un article standard.
 * Variables : $article, $types, $type_name, $can_edit, $can_purch, $currency, $sales_price, $usage, $purchases, $suppliers, $list_url
 */
defined('ABSPATH') || exit;

$id       = (int) $article->Id;
$ro       = $can_edit ? '' : 'readonly disabled';
$conception = trim((string) $article->conception);
?>
<div class="ispag-std-wrap ispag-std-sheet" data-article-id="<?php echo $id; ?>">

    <p><a href="<?php echo esc_url($list_url); ?>">← <?php esc_html_e('Standard articles', 'creation-reservoir'); ?></a></p>

    <div class="ispag-std-head">
        <div class="ispag-std-head-id">
            <div class="ispag-std-image"><?php echo ISPAG_Standard_Articles_Pages::thumb($article->image, 'medium'); ?></div>
            <div>
                <input class="ispag-std-title ispag-std-field" type="text" data-scope="sales" data-id="<?php echo $id; ?>" data-field="TitreArticle"
                       value="<?php echo esc_attr($article->TitreArticle); ?>" <?php echo $ro; ?>>
                <div class="ispag-std-sub">
                    #<?php echo $id; ?> · <?php echo esc_html($type_name[(int) $article->TypeArticle] ?? '—'); ?>
                    · <?php echo esc_html(ISPAG_Standard_Articles_Pages::money($sales_price)); ?> <?php echo esc_html($currency); ?>
                    <?php if ($usage): ?>· <?php printf(esc_html(_n('used on %d project line', 'used on %d project lines', $usage, 'creation-reservoir')), $usage); ?><?php endif; ?>
                </div>
                <span class="ispag-std-msg" aria-live="polite"></span>
            </div>
        </div>
        <?php if ($can_edit): ?>
            <button type="button" class="ispag-btn ispag-btn-red-outlined" id="ispag-std-delete" data-id="<?php echo $id; ?>" <?php echo $usage ? 'disabled title="' . esc_attr__('This article is used in projects and cannot be deleted.', 'creation-reservoir') . '"' : ''; ?>>
                <span class="dashicons dashicons-trash"></span> <?php esc_html_e('Delete', 'creation-reservoir'); ?>
            </button>
        <?php endif; ?>
    </div>

    <div class="ispag-std-tabs">
        <button type="button" class="ispag-std-tab is-active" data-tab="sales"><?php esc_html_e('Sales', 'creation-reservoir'); ?></button>
        <?php if ($can_purch): ?>
            <button type="button" class="ispag-std-tab" data-tab="purchase">
                <?php esc_html_e('Purchasing', 'creation-reservoir'); ?> <span class="ispag-std-badge <?php echo $purchases ? '' : 'is-warn'; ?>"><?php echo count($purchases); ?></span>
            </button>
        <?php endif; ?>
    </div>

    <!-- ============ VENTES ============ -->
    <div class="ispag-std-pane is-active" data-pane="sales">
        <div class="ispag-card">
            <h5><?php esc_html_e('Article', 'creation-reservoir'); ?></h5>
            <div class="ispag-std-grid">
                <label><span><?php esc_html_e('ISPAG Reference', 'creation-reservoir'); ?></span>
                    <input class="ispag-std-field" type="text" data-scope="sales" data-id="<?php echo $id; ?>" data-field="ref_article_ispag" value="<?php echo esc_attr($article->ref_article_ispag); ?>" <?php echo $ro; ?>></label>
                <label><span><?php esc_html_e('Type', 'creation-reservoir'); ?></span>
                    <select class="ispag-std-field" data-scope="sales" data-id="<?php echo $id; ?>" data-field="TypeArticle" <?php echo $ro; ?>>
                        <?php foreach ($types as $t): ?>
                            <option value="<?php echo (int) $t->Id; ?>" <?php selected((int) $article->TypeArticle, (int) $t->Id); ?>><?php echo esc_html($t->type); ?></option>
                        <?php endforeach; ?>
                    </select></label>
                <label><span><?php esc_html_e('Barcode', 'creation-reservoir'); ?></span>
                    <input class="ispag-std-field" type="text" data-scope="sales" data-id="<?php echo $id; ?>" data-field="CodeBarre" value="<?php echo esc_attr($article->CodeBarre); ?>" <?php echo $ro; ?>></label>
                <label><span><?php esc_html_e('Delivery Time', 'creation-reservoir'); ?> (<?php esc_html_e('days', 'creation-reservoir'); ?>)</span>
                    <input class="ispag-std-field" type="number" min="0" step="1" data-scope="sales" data-id="<?php echo $id; ?>" data-field="delivery_time" value="<?php echo (int) $article->delivery_time; ?>" <?php echo $ro; ?>></label>
                <label><span><?php esc_html_e('Weight', 'creation-reservoir'); ?></span>
                    <input class="ispag-std-field" type="number" min="0" step="0.01" data-scope="sales" data-id="<?php echo $id; ?>" data-field="Poids" value="<?php echo esc_attr((float) $article->Poids); ?>" <?php echo $ro; ?>></label>
                <label><span><?php esc_html_e('Weight unit', 'creation-reservoir'); ?></span>
                    <input class="ispag-std-field" type="text" data-scope="sales" data-id="<?php echo $id; ?>" data-field="UnitePoids" value="<?php echo esc_attr($article->UnitePoids); ?>" <?php echo $ro; ?>></label>
                <label><span><?php esc_html_e('Image (media ID)', 'creation-reservoir'); ?></span>
                    <input class="ispag-std-field" type="number" min="0" step="1" data-scope="sales" data-id="<?php echo $id; ?>" data-field="image" value="<?php echo (int) $article->image; ?>" <?php echo $ro; ?>></label>
            </div>
            <label class="ispag-std-block"><span><?php esc_html_e('Description', 'creation-reservoir'); ?></span>
                <textarea class="ispag-std-field" rows="6" data-scope="sales" data-id="<?php echo $id; ?>" data-field="description_ispag" <?php echo $ro; ?>><?php echo esc_textarea($article->description_ispag); ?></textarea></label>
        </div>

        <div class="ispag-card">
            <h5><?php esc_html_e('Sales price', 'creation-reservoir'); ?>: <strong><?php echo esc_html(ISPAG_Standard_Articles_Pages::money($sales_price)); ?> <?php echo esc_html($currency); ?></strong></h5>
            <?php if ($can_edit): ?>
                <form class="ispag-std-inline-form ispag-std-price-form" data-kind="sales" data-id="<?php echo $id; ?>">
                    <input type="number" name="price" min="0" step="0.01" placeholder="<?php esc_attr_e('New price', 'creation-reservoir'); ?>" required>
                    <label><?php esc_html_e('Effective date', 'creation-reservoir'); ?>
                        <input type="date" name="valid_from" value="<?php echo esc_attr(date('Y-m-d')); ?>" required></label>
                    <input type="text" name="note" placeholder="<?php esc_attr_e('Note', 'creation-reservoir'); ?>">
                    <button type="submit" class="ispag-btn ispag-btn-primary"><?php esc_html_e('Change price', 'creation-reservoir'); ?></button>
                    <span class="ispag-std-msg"></span>
                </form>
            <?php endif; ?>
            <button type="button" class="ispag-std-link ispag-std-history-btn" data-kind="sales" data-id="<?php echo $id; ?>"><?php esc_html_e('Price history', 'creation-reservoir'); ?></button>
            <div class="ispag-std-history-box" hidden></div>
        </div>

        <?php if ($conception !== '' && $conception !== '{}' && $conception !== '[]'): ?>
            <details class="ispag-card">
                <summary><?php esc_html_e('Technical configuration', 'creation-reservoir'); ?></summary>
                <pre class="ispag-std-pre"><?php echo esc_html($conception); ?></pre>
            </details>
        <?php endif; ?>
    </div>

    <!-- ============ ACHATS ============ -->
    <?php if ($can_purch): ?>
    <div class="ispag-std-pane" data-pane="purchase" hidden>
        <div class="ispag-card">
            <h5><?php esc_html_e('Suppliers and purchase prices', 'creation-reservoir'); ?></h5>
            <?php if (!$purchases): ?>
                <p class="ispag-notice"><?php esc_html_e('No supplier yet for this article.', 'creation-reservoir'); ?></p>
            <?php endif; ?>

            <?php foreach ($purchases as $p):
                $net = (float) $p->purchase_price * (1 - (float) $p->discount / 100); ?>
                <div class="ispag-std-supplier" data-purchase-id="<?php echo (int) $p->Id; ?>">
                    <div class="ispag-std-supplier-head">
                        <a href="<?php echo esc_url(home_url('/company/' . (int) $p->supplier_id . '/')); ?>"><strong><?php echo esc_html($p->company_name ?: ('#' . (int) $p->supplier_id)); ?></strong></a>
                        <span class="ispag-std-price">
                            <?php echo esc_html(ISPAG_Standard_Articles_Pages::money($p->purchase_price)); ?>
                            <?php if ((float) $p->discount > 0): ?>− <?php echo esc_html(rtrim(rtrim(number_format((float) $p->discount, 2, '.', ''), '0'), '.')); ?>% = <strong><?php echo esc_html(ISPAG_Standard_Articles_Pages::money($net)); ?></strong><?php endif; ?>
                            <?php echo esc_html($p->currency); ?>
                        </span>
                        <button type="button" class="ispag-std-link ispag-std-history-btn" data-kind="purchase" data-id="<?php echo (int) $p->Id; ?>"><?php esc_html_e('Price history', 'creation-reservoir'); ?></button>
                        <button type="button" class="ispag-std-link is-danger ispag-std-del-purchase" data-id="<?php echo (int) $p->Id; ?>"><?php esc_html_e('Remove', 'creation-reservoir'); ?></button>
                    </div>
                    <div class="ispag-std-grid">
                        <label><span><?php esc_html_e('Supplier reference', 'creation-reservoir'); ?></span>
                            <input class="ispag-std-field" type="text" data-scope="purchase" data-id="<?php echo (int) $p->Id; ?>" data-field="supplier_reference" value="<?php echo esc_attr($p->supplier_reference); ?>"></label>
                        <label><span><?php esc_html_e('Delivery Time', 'creation-reservoir'); ?> (<?php esc_html_e('days', 'creation-reservoir'); ?>)</span>
                            <input class="ispag-std-field" type="number" min="0" step="1" data-scope="purchase" data-id="<?php echo (int) $p->Id; ?>" data-field="delivery_days" value="<?php echo (int) $p->delivery_days; ?>"></label>
                    </div>
                    <label class="ispag-std-block"><span><?php esc_html_e('Supplier description', 'creation-reservoir'); ?></span>
                        <textarea class="ispag-std-field" rows="3" data-scope="purchase" data-id="<?php echo (int) $p->Id; ?>" data-field="supplier_description"><?php echo esc_textarea($p->supplier_description); ?></textarea></label>
                    <form class="ispag-std-inline-form ispag-std-price-form" data-kind="purchase" data-id="<?php echo (int) $p->Id; ?>">
                        <input type="number" name="price" min="0" step="0.01" value="<?php echo esc_attr((float) $p->purchase_price); ?>" required>
                        <input type="number" name="discount" min="0" max="100" step="0.01" value="<?php echo esc_attr((float) $p->discount); ?>" title="<?php esc_attr_e('Discount', 'creation-reservoir'); ?> %">
                        <input type="text" name="currency" list="ispag-std-currencies" value="<?php echo esc_attr($p->currency ?: 'CHF'); ?>" size="4">
                        <label><?php esc_html_e('Effective date', 'creation-reservoir'); ?>
                            <input type="date" name="valid_from" value="<?php echo esc_attr(date('Y-m-d')); ?>" required></label>
                        <input type="text" name="note" placeholder="<?php esc_attr_e('Note', 'creation-reservoir'); ?>">
                        <button type="submit" class="ispag-btn ispag-btn-primary"><?php esc_html_e('Change price', 'creation-reservoir'); ?></button>
                        <span class="ispag-std-msg"></span>
                    </form>
                    <div class="ispag-std-history-box" hidden></div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="ispag-card">
            <h5><?php esc_html_e('Add a supplier', 'creation-reservoir'); ?></h5>
            <form class="ispag-std-inline-form" id="ispag-std-add-purchase" data-article-id="<?php echo $id; ?>">
                <select name="supplier_id" required>
                    <option value=""><?php esc_html_e('Supplier', 'creation-reservoir'); ?></option>
                    <?php $linked = wp_list_pluck($purchases, 'supplier_id');
                    foreach ($suppliers as $s): if (in_array((int) $s->Id, array_map('intval', $linked), true)) continue; ?>
                        <option value="<?php echo (int) $s->Id; ?>"><?php echo esc_html($s->company_name); ?></option>
                    <?php endforeach; ?>
                </select>
                <input type="number" name="price" min="0" step="0.01" placeholder="<?php esc_attr_e('Purchase price', 'creation-reservoir'); ?>" required>
                <input type="number" name="discount" min="0" max="100" step="0.01" placeholder="<?php esc_attr_e('Discount', 'creation-reservoir'); ?> %">
                <input type="text" name="currency" list="ispag-std-currencies" value="CHF" size="4">
                <input type="text" name="reference" placeholder="<?php esc_attr_e('Supplier reference', 'creation-reservoir'); ?>">
                <input type="number" name="delivery_days" min="0" step="1" placeholder="<?php esc_attr_e('Delivery Time', 'creation-reservoir'); ?>">
                <button type="submit" class="ispag-btn ispag-btn-primary">+ <?php esc_html_e('Add', 'creation-reservoir'); ?></button>
                <span class="ispag-std-msg"></span>
            </form>
            <datalist id="ispag-std-currencies"><option value="CHF"><option value="EUR"><option value="USD"></datalist>
        </div>
    </div>
    <?php endif; ?>
</div>
