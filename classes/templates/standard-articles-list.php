<?php
/**
 * Liste des articles standard.
 * Variables : $result, $types, $type_name, $suppliers, $can_edit, $can_purch, $currency, $filters (type, search, supplier, no_purch, outdated), $export_url, $outdated_months
 */
defined('ABSPATH') || exit;

$base_url = remove_query_arg(['pg', 'type', 'q', 'supplier', 'no_purchase', 'outdated']);
$link = function (array $extra) use ($base_url, $filters) {
    $args = array_filter([
        'type'        => $filters['type'],
        'q'           => $filters['search'],
        'supplier'    => $filters['supplier'],
        'no_purchase' => $filters['no_purch'] ? 1 : 0,
        'outdated'    => $filters['outdated'] ? 1 : 0,
    ]);
    return esc_url(add_query_arg(array_filter(array_merge($args, $extra), function ($v) { return $v !== '' && $v !== 0 && $v !== null; }), $base_url));
};
?>
<div class="ispag-std-wrap">

    <div class="ispag-std-head">
        <h2><?php esc_html_e('Standard articles', 'creation-reservoir'); ?>
            <small>(<?php echo (int) $result['total']; ?>)</small></h2>
        <div class="ispag-std-actions">
            <a class="ispag-btn ispag-btn-secondary-outlined" href="<?php echo esc_url($export_url); ?>">
                <span class="dashicons dashicons-download"></span> <?php esc_html_e('Export CSV', 'creation-reservoir'); ?>
            </a>
            <?php if ($can_edit): ?>
                <button type="button" class="ispag-btn ispag-btn-primary" id="ispag-std-new-toggle">
                    + <?php esc_html_e('New article', 'creation-reservoir'); ?>
                </button>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($can_edit): ?>
        <form id="ispag-std-new-form" class="ispag-std-inline-form" style="display:none;">
            <input type="text" name="title" placeholder="<?php esc_attr_e('Title', 'creation-reservoir'); ?>" required>
            <select name="type" required>
                <option value=""><?php esc_html_e('Type', 'creation-reservoir'); ?></option>
                <?php foreach ($types as $t): ?>
                    <option value="<?php echo (int) $t->Id; ?>" <?php selected($filters['type'], (int) $t->Id); ?>><?php echo esc_html($t->type); ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="ispag-btn ispag-btn-primary"><?php esc_html_e('Create', 'creation-reservoir'); ?></button>
            <span class="ispag-std-msg"></span>
        </form>
    <?php endif; ?>

    <!-- Filtre par type -->
    <div class="ispag-std-chips">
        <a class="ispag-std-chip <?php echo $filters['type'] ? '' : 'is-active'; ?>" href="<?php echo $link(['type' => 0]); ?>"><?php esc_html_e('All Types', 'creation-reservoir'); ?></a>
        <?php foreach ($types as $t): ?>
            <a class="ispag-std-chip <?php echo (int) $filters['type'] === (int) $t->Id ? 'is-active' : ''; ?>" href="<?php echo $link(['type' => (int) $t->Id]); ?>"
               <?php echo preg_match('/^#[0-9a-fA-F]{3,8}$/', (string) $t->color) ? 'style="--chip:' . esc_attr($t->color) . ';"' : ''; ?>>
                <?php echo esc_html($t->type); ?>
            </a>
        <?php endforeach; ?>
    </div>

    <!-- Recherche + filtres -->
    <form method="get" class="ispag-toolbar ispag-std-toolbar">
        <?php if ($filters['type']): ?><input type="hidden" name="type" value="<?php echo (int) $filters['type']; ?>"><?php endif; ?>
        <input type="search" name="q" value="<?php echo esc_attr($filters['search']); ?>" placeholder="<?php esc_attr_e('Search by title or reference...', 'creation-reservoir'); ?>" class="ispag-search-field">
        <?php if ($can_purch): ?>
            <span class="ispag-kanban-filter-wrapper">
                <select name="supplier">
                    <option value=""><?php esc_html_e('Supplier', 'creation-reservoir'); ?></option>
                    <?php foreach ($suppliers as $s): ?>
                        <option value="<?php echo (int) $s->Id; ?>" <?php selected($filters['supplier'], (int) $s->Id); ?>><?php echo esc_html($s->company_name); ?></option>
                    <?php endforeach; ?>
                </select>
            </span>
            <label class="ispag-std-check">
                <input type="checkbox" name="no_purchase" value="1" <?php checked($filters['no_purch']); ?>>
                <?php esc_html_e('Without supplier', 'creation-reservoir'); ?>
            </label>
            <label class="ispag-std-check" title="<?php echo esc_attr(sprintf(__('Purchase price older than %d months', 'creation-reservoir'), $outdated_months)); ?>">
                <input type="checkbox" name="outdated" value="1" <?php checked($filters['outdated']); ?>>
                <?php printf(esc_html__('Price older than %d months', 'creation-reservoir'), $outdated_months); ?>
            </label>
        <?php endif; ?>
        <button type="submit" class="ispag-btn ispag-btn-grey"><?php esc_html_e('Search', 'creation-reservoir'); ?></button>
        <a href="<?php echo esc_url($base_url); ?>" class="ispag-btn ispag-btn-secondary-outlined"><?php esc_html_e('Clear Filters', 'creation-reservoir'); ?></a>
    </form>

    <div class="ispag-table-wrapper ispag-card">
        <table class="ispag-project-table ispag-std-table">
            <thead>
                <tr>
                    <th></th>
                    <th><?php esc_html_e('Type', 'creation-reservoir'); ?></th>
                    <th><?php esc_html_e('ISPAG Reference', 'creation-reservoir'); ?></th>
                    <th><?php esc_html_e('Title', 'creation-reservoir'); ?></th>
                    <th class="num"><?php esc_html_e('Sales price', 'creation-reservoir'); ?> (<?php echo esc_html($currency); ?>)</th>
                    <th class="num"><?php esc_html_e('Weight', 'creation-reservoir'); ?></th>
                    <th class="num"><?php esc_html_e('Delivery Time', 'creation-reservoir'); ?></th>
                    <?php if ($can_purch): ?><th class="num"><?php esc_html_e('Suppliers', 'creation-reservoir'); ?></th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($result['rows'])): ?>
                <tr><td colspan="8" style="text-align:center;"><?php esc_html_e('No articles found.', 'creation-reservoir'); ?></td></tr>
            <?php endif; ?>
            <?php foreach ($result['rows'] as $row):
                $url = ISPAG_Standard_Article_Service::article_url($row->Id); ?>
                <tr class="project-row-item ispag-std-row" data-href="<?php echo esc_url($url); ?>">
                    <td class="thumb"><?php echo ISPAG_Standard_Articles_Pages::thumb($row->image); ?></td>
                    <td><?php echo esc_html($type_name[(int) $row->TypeArticle] ?? '—'); ?></td>
                    <td><?php echo esc_html($row->ref_article_ispag); ?></td>
                    <td class="td-title"><strong><a href="<?php echo esc_url($url); ?>" class="project-link"><?php echo esc_html($row->TitreArticle); ?></a></strong></td>
                    <td class="num"><?php echo esc_html(ISPAG_Standard_Articles_Pages::money($row->current_price)); ?></td>
                    <td class="num"><?php echo $row->Poids > 0 ? esc_html(rtrim(rtrim(number_format((float) $row->Poids, 2, '.', ''), '0'), '.') . ' ' . $row->UnitePoids) : '—'; ?></td>
                    <td class="num"><?php echo (int) $row->delivery_time ? (int) $row->delivery_time . ' ' . esc_html__('days', 'creation-reservoir') : '—'; ?></td>
                    <?php if ($can_purch): ?>
                        <td class="num"><span class="ispag-std-badge <?php echo (int) $row->nb_suppliers ? '' : 'is-warn'; ?>"><?php echo (int) $row->nb_suppliers; ?></span>
                            <?php if ((int) $row->nb_outdated): ?>
                                <span class="ispag-std-badge is-warn" title="<?php echo esc_attr(sprintf(__('Purchase price older than %d months', 'creation-reservoir'), $outdated_months)); ?>">⏰ <?php echo (int) $row->nb_outdated; ?></span>
                            <?php endif; ?></td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if ($result['pages'] > 1): ?>
        <div class="ispag-std-pager">
            <?php for ($i = 1; $i <= $result['pages']; $i++): ?>
                <a class="ispag-std-chip <?php echo $i === $result['page'] ? 'is-active' : ''; ?>" href="<?php echo $link(['pg' => $i]); ?>"><?php echo $i; ?></a>
            <?php endfor; ?>
        </div>
    <?php endif; ?>
</div>
