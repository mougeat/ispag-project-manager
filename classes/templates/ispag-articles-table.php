<?php
if (!defined('ABSPATH')) {
    exit;
}

// Instancier la classe pour accéder à display_modal()
$modal = new ISPAG_Achats_Articles_Manager();
?>

<!-- Modal Structure -->
<?php //echo $modal->display_modal(); ?>

<div class="ispag-toolbar">
    <input type="text" id="ispag-articles-search"
           placeholder="<?php _e('Search by title or reference...', 'creation-reservoir'); ?>"
           class="ispag-search-field"
           value="<?php echo esc_attr($search); ?>">

    <span class="ispag-kanban-filter-wrapper">
        <select id="ispag-articles-type-filter" name="type_filter">
            <option value=""><?php _e('All Types', 'creation-reservoir'); ?></option>
            <?php foreach ($types as $type): ?>
                <option value="<?php echo esc_attr($type['Id']); ?>"
                    <?php selected($type_filter, $type['Id']); ?>>
                    <?php echo esc_html($type['type']); ?>
                </option>
            <?php endforeach; ?>
        </select>
    </span>

    <a href="?search=&type_filter=" class="ispag-btn ispag-btn-secondary-outlined">
        <?php _e('Clear Filters', 'creation-reservoir'); ?>
    </a>
</div>

<div class="ispag-table-wrapper">
    <h2><?php _e('Custom Tank Components List', 'creation-reservoir'); ?></h2>
    <table class="ispag-project-table">
        <thead>
            <tr>
                <th><?php _e('ID', 'creation-reservoir'); ?></th>
                <th><?php _e('Type', 'creation-reservoir'); ?></th>
                <th><?php _e('ISPAG Reference', 'creation-reservoir'); ?></th>
                <th><?php _e('Title', 'creation-reservoir'); ?></th>
                <th><?php _e('Sales price', 'creation-reservoir'); ?> (<?php echo esc_html(get_option('wpcb_currency', '€')); ?>)</th>
                <th><?php _e('Weight', 'creation-reservoir'); ?></th>
                <th><?php _e('Delivery Time', 'creation-reservoir'); ?> (<?php _e('days', 'creation-reservoir'); ?>)</th>
                <th><?php _e('Actions', 'creation-reservoir'); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($articles)): ?>
                <tr>
                    <td colspan="8" style="text-align: center;">
                        <?php _e('No articles found.', 'creation-reservoir'); ?>
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($articles as $article): ?>
                    <tr>
                        <td data-label="<?php _e('ID', 'creation-reservoir'); ?>"><?php echo esc_html($article['Id']); ?></td>
                        <td data-label="<?php _e('Type', 'creation-reservoir'); ?>">
                            <?php echo $this->get_type_prestation_name($article['TypeArticle']); ?>
                        </td>
                        <td data-label="<?php _e('ISPAG Reference', 'creation-reservoir'); ?>"><?php echo esc_html($article['ref_article_ispag']); ?></td>
                        <td data-label="<?php _e('Title', 'creation-reservoir'); ?>"><?php echo esc_html($article['TitreArticle']); ?></td>
                        <td data-label="<?php _e('Sales price', 'creation-reservoir'); ?>"><?php echo esc_html(number_format($this->get_latest_sales_price($article['Id']), 2, '.', '\'')); ?> <?php echo esc_html(get_option('wpcb_currency', '€')); ?></td>
                        <td data-label="<?php _e('Weight', 'creation-reservoir'); ?>"><?php echo esc_html($article['Poids'] . ' ' . $article['UnitePoids']); ?></td>
                        <td data-label="<?php _e('Delivery Time', 'creation-reservoir'); ?>"><?php echo esc_html($article['delivery_time']); ?></td>
                        <td data-label="<?php _e('Actions', 'creation-reservoir'); ?>">
                            <div class="ispag-actions-bar">
                                <button class="ispag-action-btn ispag-edit-btn" data-article-id="<?php echo esc_attr($article['Id']); ?>" title="<?php _e('Edit', 'creation-reservoir'); ?>">
                                    <span class="dashicons dashicons-edit"></span>
                                    <span><?php _e('Edit', 'creation-reservoir'); ?></span>
                                </button>
                                <button class="ispag-action-btn ispag-preview-btn" data-article-id="<?php echo esc_attr($article['Id']); ?>" title="<?php _e('Preview', 'creation-reservoir'); ?>">
                                    <span class="dashicons dashicons-visibility"></span>
                                    <span><?php _e('Preview', 'creation-reservoir'); ?></span>
                                </button>
                                <button class="ispag-action-btn ispag-delete-btn" data-article-id="<?php echo esc_attr($article['Id']); ?>" title="<?php _e('Delete', 'creation-reservoir'); ?>">
                                    <span class="dashicons dashicons-trash"></span>
                                    <span><?php _e('Delete', 'creation-reservoir'); ?></span>
                                </button>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>