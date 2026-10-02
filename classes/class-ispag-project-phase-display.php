<?php
/**
 * Rendu de l'onglet interne (staff, dropdown de statut) et de la timeline client (lecture seule)
 * pour le suivi de phase d'un projet. S'appuie uniquement sur Catalog/Resolver/Tracker.
 */
if (!defined('ABSPATH')) { exit; }

class ISPAG_Project_Phase_Display
{
    const NONCE_ACTION = 'ispag_nonce';
    const CAPABILITY   = 'manage_order';

    public static function init()
    {
        add_action('wp_ajax_ispag_update_project_phase_status', [__CLASS__, 'ajax_update_phase_status']);
        add_action('wp_ajax_ispag_render_phase_tab', [__CLASS__, 'ajax_render_tab']);
        add_action('wp_ajax_ispag_get_next_pending_phase', [__CLASS__, 'ajax_get_next_pending_phase']);
        add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue_assets']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_assets']);
    }


    public static function enqueue_assets()
    {
        wp_register_style('ispag-phase-tracker', plugin_dir_url(__FILE__) . '../assets/css/_phase-tracker.css', [], '1.2.0');
        wp_register_script('ispag-phase-tracker', plugin_dir_url(__FILE__) . '../assets/js/phase-tracker.js', ['jquery'], '1.2.0', true);

        wp_enqueue_style('ispag-phase-tracker');   // ⬅️ ajouté
        wp_enqueue_script('ispag-phase-tracker');  // ⬅️ ajouté

        wp_localize_script('ispag-phase-tracker', 'ispagPhaseTracker', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce(self::NONCE_ACTION),
        ]);
    }

    /**
     * AJAX : rendu du contenu de l'onglet "Follow up", rechargé à chaque affichage.
     * Sert la vue interne (dropdown) ou la timeline client selon la capability.
     */
    public static function ajax_render_tab()
    {
        check_ajax_referer(self::NONCE_ACTION, '_ajax_nonce');

        $hubspot_deal_id = isset($_POST['hubspot_deal_id']) ? intval($_POST['hubspot_deal_id']) : 0;
        if (!$hubspot_deal_id)
        {
            wp_send_json_error(['message' => __('Invalid request.', 'ispag-crm')]);
        }

        ISPAG_Project_Phase_Automation::run_checks($hubspot_deal_id);

        $html = current_user_can(self::CAPABILITY)
            ? self::render_internal_tab($hubspot_deal_id)
            : self::render_client_timeline($hubspot_deal_id);

        wp_send_json_success(['html' => $html]);
    }

    /**
     * AJAX : renvoie la prochaine phase non validée (nom, couleur), pour mise à jour du badge "Next step".
     */
    public static function ajax_get_next_pending_phase()
    {
        check_ajax_referer(self::NONCE_ACTION, '_ajax_nonce');

        $hubspot_deal_id = isset($_POST['hubspot_deal_id']) ? intval($_POST['hubspot_deal_id']) : 0;
        if (!$hubspot_deal_id)
        {
            wp_send_json_error(['message' => __('Invalid request.', 'creation-reservoir')]);
        }

        $context = current_user_can(self::CAPABILITY)
            ? ISPAG_Project_Phase_Resolver::CONTEXT_INTERNAL
            : ISPAG_Project_Phase_Resolver::CONTEXT_CLIENT;

        $next = ISPAG_Project_Phase_Resolver::get_next_pending_phase($hubspot_deal_id, $context);

        if ($next)
        {
            wp_send_json_success([
                'label' => __($next['phase']->TitrePhase, 'creation-reservoir'),
                'color' => $next['phase']->Color ?: 'secondary',
            ]);
        }

        wp_send_json_success([
            'label' => __('Completed', 'creation-reservoir'),
            'color' => 'success',
        ]);
    }

    /**
     * Onglet interne : liste verticale, une ligne par phase applicable, dropdown de statut.
     */
    public static function render_internal_tab($hubspot_deal_id)
    {
        wp_enqueue_style('ispag-phase-tracker');
        wp_enqueue_script('ispag-phase-tracker');

        $rows     = ISPAG_Project_Phase_Resolver::resolve($hubspot_deal_id, ISPAG_Project_Phase_Resolver::CONTEXT_INTERNAL);
        $statuses = ISPAG_Project_Phase_Catalog::get_phase_statuses();

        // error_log('IN render_internal_tab ==> ROWS : ' . print_r($rows, true));

        // Familles : une colonne par type de prestation (vide = étapes communes), dans l'ordre d'apparition des étapes
        $families = [];
        foreach ($rows as $row) {
            $key = trim((string) $row['phase']->type_prestation);
            if (!isset($families[$key])) {
                $families[$key] = ['rows' => [], 'done' => 0, 'color' => $row['phase']->product_type_color ?: ''];
            }
            $families[$key]['rows'][] = $row;
            if ($row['status'] && (int) $row['status']->TacheComplete === 1) {
                $families[$key]['done']++;
            }
        }
        $family_labels = [
            ''        => __('General', 'creation-reservoir'),
            'Product' => __('Product', 'creation-reservoir'),
            'Welding' => __('On-site welding', 'creation-reservoir'),
            'Isol'    => __('Insulation', 'creation-reservoir'),
            'div'     => __('Accessories', 'creation-reservoir'),
        ];

        ob_start(); ?>
        <div id="ispag-phase-tracker" class="ispag-phase-tracker ispag-phase-tracker--internal" data-deal-id="<?php echo esc_attr($hubspot_deal_id); ?>">
            <?php if (empty($rows)): ?>
                <p class="ispag-phase-tracker__empty"><?php esc_html_e('No applicable phase for this project.', 'creation-reservoir'); ?></p>
            <?php else: ?>
            <div class="ispag-phase-board">
            <?php foreach ($families as $key => $family):
                $total = count($family['rows']);
                $pct   = $total ? round(100 * $family['done'] / $total) : 0;
                $label = $family_labels[$key] ?? $key;
                ?>
                <section class="ispag-phase-family" data-family="<?php echo esc_attr($key); ?>" style="--family-color: <?php echo esc_attr($family['color'] ?: '#9ca3af'); ?>">
                    <header class="ispag-phase-family__head">
                        <span class="ispag-phase-family__title"><?php echo esc_html($label); ?></span>
                        <span class="ispag-phase-family__count"><?php echo (int) $family['done']; ?>/<?php echo (int) $total; ?></span>
                        <span class="ispag-phase-family__bar"><span style="width: <?php echo (int) $pct; ?>%"></span></span>
                    </header>
                    <div class="ispag-phase-family__steps">
            <?php foreach ($family['rows'] as $row):
                $phase  = $row['phase'];
                $status = $row['status'];
                $is_done = $status && (int) $status->TacheComplete === 1;
                $icon_auto = $phase->is_automatic ? '<span class="dashicons dashicons-controls-repeat" title="' . esc_attr__('Automatic step', 'creation-reservoir') . '"></span>' : null;
                $icon_send_notif = $phase->Brevo_id != 0 ? '<span class="dashicons dashicons-email" title="' . esc_attr__('Triggers email sending', 'creation-reservoir') . '"></span>' : null;
                ?>
                <div class="ispag-phase-tracker__row<?php echo $is_done ? ' is-done' : ''; ?>" data-slug-phase="<?php echo esc_attr($phase->SlugPhase); ?>" style="--status-color: <?php echo esc_attr($status->Couleur ?? '#ccc'); ?>">
                    <div class="ispag-phase-tracker__label">
                        <span class="ispag-phase-tracker__name"><?php echo esc_html(__($phase->TitrePhase, 'creation-reservoir')); ?> <?php echo $icon_auto; ?> <?php echo $icon_send_notif; ?></span>
                        <?php if ($row['date_modification']): ?>
                            <span class="ispag-phase-tracker__date"><?php echo esc_html(mysql2date('d.m.Y H:i', $row['date_modification'])); ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="ispag-phase-tracker__control">
                        <select class="ispag-phase-tracker__status-select"
                                data-action="ispag-update-phase-status"
                                style="--status-color: <?php echo esc_attr($status->Couleur ?? '#ccc'); ?>">
                            <?php foreach ($statuses as $status_option): ?>
                                <option value="<?php echo esc_attr($status_option->Id); ?>"
                                        data-color="<?php echo esc_attr($status_option->Couleur); ?>"
                                        data-complete="<?php echo (int) $status_option->TacheComplete; ?>"
                                        <?php selected((int) $row['status_id'], (int) $status_option->Id); ?>>
                                    <?php echo esc_html(__($status_option->Nom, 'creation-reservoir')); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            <?php endforeach; ?>
                    </div>
                </section>
            <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Timeline client : lecture seule, wording adapté selon l'état de chaque phase.
     */
    public static function render_client_timeline($hubspot_deal_id)
    {
        wp_enqueue_style('ispag-phase-tracker');

        $rows = ISPAG_Project_Phase_Resolver::resolve($hubspot_deal_id, ISPAG_Project_Phase_Resolver::CONTEXT_CLIENT);

        $current_found = false;
        $items = [];
        foreach ($rows as $row)
        {
            $phase  = $row['phase'];
            $status = $row['status'];
            $is_done = $status && (int) $status->TacheComplete === 1;

            if ($is_done)
            {
                $state = 'done';
                $label = $phase->TitrePhaseProgression ?: $phase->TitrePhase;
            }
            elseif (!$current_found)
            {
                $state = 'current';
                $label = $phase->TitrePhaseFuture ?: $phase->TitrePhase;
                $current_found = true;
            }
            else
            {
                $state = 'future';
                $label = $phase->TitrePhaseFuture ?: $phase->TitrePhase;
            }

            $items[] = ['state' => $state, 'label' => $label, 'row' => $row];
        }

        $total = count($items);
        $done  = count(array_filter($items, function ($i) { return $i['state'] === 'done'; }));
        $pct   = $total ? (int) round(100 * $done / $total) : 0;
        $state_labels = [
            'done'    => __('Done', 'creation-reservoir'),
            'current' => __('In progress', 'creation-reservoir'),
            'future'  => __('Upcoming', 'creation-reservoir'),
        ];

        ob_start(); ?>
        <div id="ispag-phase-timeline" class="ispag-phase-timeline">
            <?php if ($total): ?>
            <div class="ispag-phase-timeline__summary">
                <strong><?php echo esc_html(sprintf(__('%1$d of %2$d steps completed', 'creation-reservoir'), $done, $total)); ?></strong>
                <span class="ispag-phase-timeline__bar"><span style="width: <?php echo $pct; ?>%"></span></span>
            </div>
            <?php endif; ?>
            <ol class="ispag-phase-timeline__list">
            <?php foreach ($items as $item): ?>
                <li class="ispag-phase-timeline__step ispag-phase-timeline__step--<?php echo esc_attr($item['state']); ?>">
                    <span class="ispag-phase-timeline__dot" aria-hidden="true"><?php echo $item['state'] === 'done' ? '&#10003;' : ''; ?></span>
                    <span class="ispag-phase-timeline__body">
                        <span class="ispag-phase-timeline__label"><?php echo esc_html(__($item['label'], 'creation-reservoir')); ?></span>
                        <span class="ispag-phase-timeline__meta">
                            <?php echo esc_html($state_labels[$item['state']]); ?>
                            <?php if ($item['state'] === 'done' && !empty($item['row']['date_modification'])): ?>
                                &middot; <?php echo esc_html(mysql2date('d.m.Y', $item['row']['date_modification'])); ?>
                            <?php endif; ?>
                        </span>
                    </span>
                </li>
            <?php endforeach; ?>
            </ol>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * AJAX : changement manuel de statut depuis le dropdown interne.
     */
    public static function ajax_update_phase_status()
    {
        check_ajax_referer(self::NONCE_ACTION, '_ajax_nonce');

        if (!current_user_can(self::CAPABILITY))
        {
            wp_send_json_error(['message' => __('Not authorized.', 'ispag-crm')]);
        }

        $hubspot_deal_id = isset($_POST['hubspot_deal_id']) ? intval($_POST['hubspot_deal_id']) : 0;
        $slug_phase      = isset($_POST['slug_phase']) ? sanitize_text_field(wp_unslash($_POST['slug_phase'])) : '';
        $status_id       = isset($_POST['status_id']) ? intval($_POST['status_id']) : 0;

        if (!$hubspot_deal_id || !$slug_phase || !$status_id)
        {
            wp_send_json_error(['message' => __('Invalid request.', 'ispag-crm')]);
        }

        $suivi_id = ISPAG_Project_Phase_Tracker::record_status_change(
            $hubspot_deal_id,
            $slug_phase,
            $status_id,
            ['source' => ISPAG_Project_Phase_Tracker::SOURCE_MANUAL]
        );

        if ($suivi_id === false)
        {
            wp_send_json_error(['message' => __('Could not save status change.', 'ispag-crm')]);
        }

        $status = ISPAG_Project_Phase_Catalog::get_phase_status($status_id);
        wp_send_json_success([
            'suivi_id' => $suivi_id,
            'color'    => $status->Couleur ?? '#ccc',
        ]);
    }

    
}

