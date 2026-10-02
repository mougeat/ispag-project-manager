<?php
defined('ABSPATH') || exit;

/**
 * Relances automatiques des plans en attente de validation (étape SignaturePlan), réglables dans
 * ISPAG Settings → Plan reminders.
 *
 *  - Délai de retour des plans : N jours ouvrables après leur envoi (défaut 5). Il sert à la date {RETURN_DATE}
 *    du mail d'envoi des plans et de point de départ des relances.
 *  - Relances : autant d'échéances que voulu, chacune « X jours ouvrables après la date limite de retour », avec
 *    pour chacune : prévenir le client (e-mail « reviveProjectSign »), le chef de projet, le créateur du projet,
 *    et les canaux de la notification interne (cloche, push, e-mail).
 *  - Une échéance n'est envoyée qu'une fois par envoi de plans. Si plusieurs sont dépassées d'un coup
 *    (premier passage, cron en retard), seule la plus avancée est envoyée.
 *  - Vérification quotidienne (cron ispag_check_plans_status).
 */
class ISPAG_Plan_Reminders {

    const OPT_DAYS  = 'ispag_plan_return_days';
    const OPT_RULES = 'ispag_plan_reminders';
    const PAGE      = 'ispag-plan-reminders';
    const NONCE     = 'ispag_plan_reminders_settings';
    const META_KEY  = '_ispag_plan_reminders_state';
    const LOG       = 'plan_reminders';
    const CHANNELS  = ['crm' => 'Bell', 'push' => 'Push', 'mail' => 'E-mail'];
    const CUSTOMER_SLUG = 'reviveProjectSign';

    public static function init() {
        add_action('admin_menu', [self::class, 'admin_menu']);
    }

    // ------------------------------------------------------------------
    // Réglages
    // ------------------------------------------------------------------

    /** Jours ouvrables laissés au client pour retourner les plans. */
    public static function return_days(): int {
        return max(1, (int) apply_filters('ispag_phase_mail_return_days', (int) get_option(self::OPT_DAYS, 5)));
    }

    /** Échéances proposées tant que rien n'a été enregistré. */
    public static function default_rules(): array {
        return [
            ['id' => 'r1', 'after' => 0,  'customer' => 0, 'manager' => 1, 'creator' => 0, 'channels' => ['crm', 'push', 'mail']],
            ['id' => 'r2', 'after' => 5,  'customer' => 1, 'manager' => 1, 'creator' => 0, 'channels' => ['crm', 'push', 'mail']],
            ['id' => 'r3', 'after' => 10, 'customer' => 1, 'manager' => 1, 'creator' => 1, 'channels' => ['crm', 'push', 'mail']],
        ];
    }

    /** Échéances enregistrées (ou par défaut), triées par délai. */
    public static function rules(): array {
        $saved = get_option(self::OPT_RULES, null);
        $rules = is_array($saved) ? $saved : self::default_rules();
        $out = [];
        foreach ($rules as $r) {
            if (!is_array($r) || !isset($r['after'])) continue;
            $out[] = [
                'id'       => (string) ($r['id'] ?? uniqid('r')),
                'after'    => max(0, (int) $r['after']),
                'customer' => !empty($r['customer']) ? 1 : 0,
                'manager'  => !empty($r['manager']) ? 1 : 0,
                'creator'  => !empty($r['creator']) ? 1 : 0,
                'channels' => array_values(array_intersect(array_keys(self::CHANNELS), (array) ($r['channels'] ?? []))),
            ];
        }
        usort($out, function ($a, $b) { return $a['after'] <=> $b['after']; });
        return $out;
    }

    // ------------------------------------------------------------------
    // Jours ouvrables
    // ------------------------------------------------------------------

    /** Ajoute N jours ouvrables (lundi-vendredi ; jours fériés : filtre ispag_plan_reminders_is_holiday). */
    public static function add_working_days(DateTimeImmutable $date, int $days): DateTimeImmutable {
        while ($days > 0) {
            $date = $date->modify('+1 day');
            if ((int) $date->format('N') < 6 && !apply_filters('ispag_plan_reminders_is_holiday', false, $date)) $days--;
        }
        return $date;
    }

    // ------------------------------------------------------------------
    // Exécution (cron quotidien)
    // ------------------------------------------------------------------

    private static function log($message, array $ctx = []) {
        ISPAG_Logger::get_instance()->log(self::LOG, $message . ($ctx ? ' ' . wp_json_encode($ctx) : ''), get_current_user_id());
    }

    /**
     * Relance les projets dont les plans sont en attente de validation.
     * @param bool $dry_run true = ne rien envoyer ni enregistrer, seulement lister
     * @return array[] une entrée par projet concerné : deal, name, rule (échéance atteinte), actions (texte)
     */
    public static function run(bool $dry_run = false): array {
        global $wpdb;
        $rules = self::rules();
        if (!$rules) return [];

        $t_projects = $wpdb->prefix . 'achats_liste_commande';
        $t_phase    = $wpdb->prefix . 'achats_suivi_phase_commande';

        $projects = (array) $wpdb->get_results("
            SELECT hubspot_deal_id, ObjetCommande, created_by, project_manager
            FROM {$t_projects}
            WHERE (isQotation IS NULL OR isQotation = 0) AND project_status = 1
        ");

        $tz    = wp_timezone();
        $today = new DateTimeImmutable('today', $tz);
        $report = [];

        foreach ($projects as $project) {
            $deal_id = (int) $project->hubspot_deal_id;

            // Plans déjà validés : rien à relancer
            $signed = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$t_phase} WHERE hubspot_deal_id = %d AND slug_phase = 'SignaturePlan' AND status_id IN (1, 5)",
                $deal_id
            ));
            if ($signed > 0) continue;

            // Plans envoyés (dernier état de l'étape = fait) : date de l'envoi
            $last = $wpdb->get_row($wpdb->prepare(
                "SELECT status_id, date_modification FROM {$t_phase} WHERE hubspot_deal_id = %d AND slug_phase = 'EnvoiePlanClient' ORDER BY id DESC LIMIT 1",
                $deal_id
            ));
            if (!$last || (int) $last->status_id !== 1 || empty($last->date_modification)) continue;

            try {
                $sent     = new DateTimeImmutable($last->date_modification, $tz);
                $deadline = self::add_working_days($sent->setTime(0, 0), self::return_days());
            } catch (Exception $e) {
                continue;
            }

            // Échéances déjà envoyées pour CET envoi de plans (un nouvel envoi remet tout à zéro)
            $state = self::get_state($deal_id);
            $fired = (($state['sent'] ?? '') === $last->date_modification) ? (array) ($state['fired'] ?? []) : [];

            // Échéances atteintes et pas encore envoyées
            $due = [];
            foreach ($rules as $rule) {
                if (isset($fired[$rule['id']])) continue;
                $due_date = self::add_working_days($deadline, $rule['after']);
                if ($due_date <= $today) $due[] = $rule;
            }
            if (!$due) continue;

            // Plusieurs échéances d'un coup : on n'envoie que la plus avancée, les autres sont marquées envoyées
            $rule = end($due);
            $actions = self::actions($rule);

            if (!$dry_run) {
                self::fire($rule, $project, $deadline, $today);
                foreach ($due as $d) $fired[$d['id']] = $today->format('Y-m-d');
                self::set_state($deal_id, ['sent' => $last->date_modification, 'fired' => $fired]);
            }
            $report[] = ['deal' => $deal_id, 'name' => $project->ObjetCommande, 'rule' => $rule, 'actions' => $actions, 'deadline' => $deadline->format('d.m.Y')];
        }
        return $report;
    }

    /** Libellé des actions d'une échéance (aperçu). */
    private static function actions(array $rule): string {
        $parts = [];
        if ($rule['customer']) $parts[] = __('customer e-mail', 'creation-reservoir');
        $who = [];
        if ($rule['manager']) $who[] = __('project manager', 'creation-reservoir');
        if ($rule['creator']) $who[] = __('project creator', 'creation-reservoir');
        if ($who && $rule['channels']) $parts[] = implode(' + ', $who) . ' (' . implode(', ', array_map(function ($c) { return self::CHANNELS[$c]; }, $rule['channels'])) . ')';
        return $parts ? implode(' · ', $parts) : __('nothing selected', 'creation-reservoir');
    }

    private static function fire(array $rule, $project, DateTimeImmutable $deadline, DateTimeImmutable $today) {
        $deal_id = (int) $project->hubspot_deal_id;
        self::log('Relance plans', ['deal' => $deal_id, 'rule' => $rule['id'], 'after' => $rule['after']]);

        // Client : e-mail de l'étape « reviveProjectSign » (template modifiable dans Email templates)
        if ($rule['customer']) {
            if (class_exists('ISPAG_Phase_Mail') && ISPAG_Phase_Mail::is_enabled(self::CUSTOMER_SLUG)) {
                // Nouveau délai : la prochaine échéance de relance (à défaut, le délai de retour habituel à partir d'aujourd'hui)
                $next = null;
                foreach (self::rules() as $r) {
                    if ($r['after'] > $rule['after']) { $next = $r; break; }
                }
                $new_deadline = $next
                    ? self::add_working_days($deadline, $next['after'])
                    : self::add_working_days($today, self::return_days());
                ISPAG_Phase_Mail::send_to_project($deal_id, self::CUSTOMER_SLUG, [
                    'return_date' => wp_date('d.m.Y', $new_deadline->getTimestamp()),
                    'sender_id'   => (int) ($project->project_manager ?: $project->created_by), // signature et Reply-To : le chef de projet
                ]);
            } else {
                self::log('E-mail client non envoyé : étape ' . self::CUSTOMER_SLUG . ' sans e-mail activé', ['deal' => $deal_id]);
            }
        }

        // Interne : chef de projet et/ou créateur du projet
        $ids = [];
        if ($rule['manager']) $ids[] = (int) ($project->project_manager ?: $project->created_by);
        if ($rule['creator']) $ids[] = (int) $project->created_by;
        $ids = array_values(array_unique(array_filter($ids)));

        if ($ids && $rule['channels'] && class_exists('ISPAG_Notifications_Manager')) {
            $late = max(0, (int) $deadline->diff($today)->format('%r%a'));
            ISPAG_Notifications_Manager::send(
                $ids,
                'product_manager',
                __('⚠️ Drawing to be validated', 'ispag-crm'),
                sprintf(
                    /* translators: 1: project name, 2: return deadline, 3: days past the deadline */
                    __('ATTENTION, drawings are still waiting for the customer approval on project %1$s (deadline %2$s, %3$d day(s) ago).', 'ispag-crm'),
                    $project->ObjetCommande,
                    $deadline->format('d.m.Y'),
                    $late
                ),
                'project-detail/' . $deal_id,
                $deal_id,
                ['channels' => $rule['channels']]
            );
        }
    }

    // ------------------------------------------------------------------
    // État par projet (table achats_project_meta)
    // ------------------------------------------------------------------

    private static function get_state(int $deal_id): array {
        global $wpdb;
        $raw = $wpdb->get_var($wpdb->prepare(
            'SELECT meta_value FROM ' . $wpdb->prefix . 'achats_project_meta WHERE post_id = %d AND meta_key = %s LIMIT 1',
            $deal_id, self::META_KEY
        ));
        $data = $raw ? json_decode($raw, true) : null;
        return is_array($data) ? $data : [];
    }

    private static function set_state(int $deal_id, array $state) {
        global $wpdb;
        $table = $wpdb->prefix . 'achats_project_meta';
        $value = wp_json_encode($state);
        $exists = $wpdb->get_var($wpdb->prepare("SELECT meta_id FROM {$table} WHERE post_id = %d AND meta_key = %s", $deal_id, self::META_KEY));
        if ($exists) {
            $wpdb->update($table, ['meta_value' => $value], ['post_id' => $deal_id, 'meta_key' => self::META_KEY]);
        } else {
            $wpdb->insert($table, ['post_id' => $deal_id, 'meta_key' => self::META_KEY, 'meta_value' => $value]);
        }
    }

    // ------------------------------------------------------------------
    // Page de réglages
    // ------------------------------------------------------------------

    public static function admin_menu() {
        $title = __('Plan reminders', 'creation-reservoir');
        if (class_exists('ISPAG_Settings')) {
            add_submenu_page(ISPAG_Settings::PAGE, $title, $title, 'manage_options', self::PAGE, [self::class, 'render']);
        } else {
            add_management_page($title, $title, 'manage_options', self::PAGE, [self::class, 'render']);
        }
    }

    private static function save() {
        update_option(self::OPT_DAYS, max(1, (int) ($_POST['return_days'] ?? 5)), false);

        $rules = [];
        foreach ((array) ($_POST['rules'] ?? []) as $r) {
            if (!is_array($r) || !isset($r['after']) || $r['after'] === '') continue;
            $rules[] = [
                'id'       => preg_replace('/[^a-z0-9_]/i', '', (string) ($r['id'] ?? '')) ?: uniqid('r'),
                'after'    => max(0, (int) $r['after']),
                'customer' => !empty($r['customer']) ? 1 : 0,
                'manager'  => !empty($r['manager']) ? 1 : 0,
                'creator'  => !empty($r['creator']) ? 1 : 0,
                'channels' => array_values(array_intersect(array_keys(self::CHANNELS), array_map('sanitize_key', (array) ($r['channels'] ?? [])))),
            ];
        }
        update_option(self::OPT_RULES, $rules, false); // liste vide = plus aucune relance
    }

    private static function row(string $i, array $r): string {
        $name = 'rules[' . $i . ']';
        ob_start();
        ?>
        <tr class="ispag-rem-row">
            <td>
                <input type="hidden" name="<?php echo esc_attr($name); ?>[id]" value="<?php echo esc_attr($r['id']); ?>">
                <input type="number" min="0" step="1" required style="width:80px" name="<?php echo esc_attr($name); ?>[after]" value="<?php echo esc_attr($r['after']); ?>">
            </td>
            <td><label><input type="checkbox" name="<?php echo esc_attr($name); ?>[customer]" value="1" <?php checked($r['customer']); ?>> <?php esc_html_e('Customer (e-mail)', 'creation-reservoir'); ?></label></td>
            <td><label><input type="checkbox" name="<?php echo esc_attr($name); ?>[manager]" value="1" <?php checked($r['manager']); ?>> <?php esc_html_e('Project manager', 'creation-reservoir'); ?></label><br>
                <label><input type="checkbox" name="<?php echo esc_attr($name); ?>[creator]" value="1" <?php checked($r['creator']); ?>> <?php esc_html_e('Project creator', 'creation-reservoir'); ?></label></td>
            <td>
                <?php foreach (self::CHANNELS as $key => $label): ?>
                    <label style="margin-right:10px"><input type="checkbox" name="<?php echo esc_attr($name); ?>[channels][]" value="<?php echo esc_attr($key); ?>" <?php checked(in_array($key, $r['channels'], true)); ?>> <?php echo esc_html($label); ?></label>
                <?php endforeach; ?>
            </td>
            <td><button type="button" class="button ispag-rem-del" title="<?php esc_attr_e('Delete this reminder', 'creation-reservoir'); ?>">✕</button></td>
        </tr>
        <?php
        return ob_get_clean();
    }

    public static function render() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Not authorized', 'creation-reservoir'));
        }

        $notice = '';
        $preview = null;
        if (!empty($_POST['ispag_plan_reminders_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['ispag_plan_reminders_nonce'])), self::NONCE)) {
            self::save();
            $notice = '<div class="notice notice-success"><p>' . esc_html__('Settings saved.', 'creation-reservoir') . '</p></div>';
            if (!empty($_POST['preview'])) $preview = self::run(true);
        }

        $days  = self::return_days();
        $rules = self::rules();

        echo '<div class="wrap"><h1>' . esc_html__('Plan reminders', 'creation-reservoir') . '</h1>' . $notice;
        echo '<form method="post">';
        wp_nonce_field(self::NONCE, 'ispag_plan_reminders_nonce');

        echo '<h2>' . esc_html__('Return delay', 'creation-reservoir') . '</h2>';
        echo '<p><label>' . esc_html__('The customer has', 'creation-reservoir') . ' <input type="number" min="1" step="1" name="return_days" value="' . esc_attr($days) . '" style="width:70px"> '
            . esc_html__('working days (Monday to Friday) to return the approved drawings.', 'creation-reservoir') . '</label></p>';
        echo '<p class="description">' . esc_html__('Used for the {RETURN_DATE} of the e-mail that sends the drawings, and as the starting point of the reminders below. Public holidays are not taken into account.', 'creation-reservoir') . '</p>';

        echo '<h2>' . esc_html__('Reminders', 'creation-reservoir') . '</h2>';
        echo '<p class="description">' . esc_html__('Checked every day while the drawings were sent and are not approved yet. Each reminder is sent once per sending of the drawings; if several are overdue at once, only the latest one is sent. The customer e-mail is the "reviveProjectSign" template (ISPAG Settings → Email templates, enabled in Phase e-mails).', 'creation-reservoir') . '</p>';
        echo '<table class="widefat striped" style="max-width:980px" id="ispag-rem-table"><thead><tr>'
            . '<th>' . esc_html__('Working days after the return deadline', 'creation-reservoir') . '</th>'
            . '<th>' . esc_html__('Customer', 'creation-reservoir') . '</th>'
            . '<th>' . esc_html__('Internal', 'creation-reservoir') . '</th>'
            . '<th>' . esc_html__('Internal notification channels', 'creation-reservoir') . '</th><th></th></tr></thead><tbody>';
        foreach ($rules as $i => $r) {
            echo self::row((string) $i, $r);
        }
        echo '</tbody></table>';
        echo '<p><button type="button" class="button" id="ispag-rem-add">＋ ' . esc_html__('Add a reminder', 'creation-reservoir') . '</button> '
            . '<span class="description">' . esc_html__('0 = on the return deadline itself. Delete all rows for no reminder at all.', 'creation-reservoir') . '</span></p>';

        echo '<p><button type="submit" class="button button-primary">' . esc_html__('Save', 'creation-reservoir') . '</button> '
            . '<button type="submit" class="button" name="preview" value="1">' . esc_html__('Save and preview what would be sent now', 'creation-reservoir') . '</button></p>';
        echo '</form>';

        if ($preview !== null) {
            echo '<h2>' . esc_html__('Preview (nothing was sent)', 'creation-reservoir') . '</h2>';
            if (!$preview) {
                echo '<p>' . esc_html__('No reminder is due right now.', 'creation-reservoir') . '</p>';
            } else {
                echo '<table class="widefat striped" style="max-width:980px"><thead><tr><th>' . esc_html__('Project', 'creation-reservoir') . '</th><th>'
                    . esc_html__('Return deadline', 'creation-reservoir') . '</th><th>' . esc_html__('Reminder', 'creation-reservoir') . '</th><th>' . esc_html__('Would notify', 'creation-reservoir') . '</th></tr></thead><tbody>';
                foreach ($preview as $p) {
                    echo '<tr><td>' . esc_html(html_entity_decode((string) $p['name'], ENT_QUOTES | ENT_HTML5, 'UTF-8')) . ' (' . (int) $p['deal'] . ')</td><td>' . esc_html($p['deadline']) . '</td><td>+' . (int) $p['rule']['after'] . ' d</td><td>' . esc_html($p['actions']) . '</td></tr>';
                }
                echo '</tbody></table>';
            }
        }

        // Modèle d'une nouvelle ligne + ajout / suppression
        $tpl = self::row('__I__', ['id' => '', 'after' => 5, 'customer' => 1, 'manager' => 1, 'creator' => 0, 'channels' => ['crm', 'push', 'mail']]);
        ?>
        <script>
        (function () {
            var tbody = document.querySelector('#ispag-rem-table tbody');
            var tpl = <?php echo wp_json_encode($tpl); ?>;
            var n = tbody.children.length + 100;
            document.getElementById('ispag-rem-add').addEventListener('click', function () {
                tbody.insertAdjacentHTML('beforeend', tpl.split('__I__').join(String(n++)));
            });
            tbody.addEventListener('click', function (e) {
                var b = e.target.closest('.ispag-rem-del');
                if (b) b.closest('tr').remove();
            });
        })();
        </script>
        </div>
        <?php
    }
}
