<?php
defined('ABSPATH') || exit;

/**
 * « ISPAG AI » (ISPAG Settings → ISPAG AI) : tous les textes (prompts) que les plugins ISPAG envoient à l'IA, modifiables depuis l'administration.
 *
 * Principe
 *  - Chaque plugin déclare ses prompts avec le filtre « ispag_ai_prompts » (id => titre, situation d'appel, champs avec leur texte par défaut,
 *    variables). La page affiche un onglet par prompt : la situation d'appel en haut, puis le ou les textes modifiables.
 *  - Au moment d'envoyer un texte à l'IA, le plugin demande « ispag_ai_prompt » ($défaut, 'id.champ') : ce filtre renvoie le texte
 *    enregistré ici s'il y en a un, sinon le texte par défaut passé par le plugin. Le plugin remplace ensuite lui-même les variables {{nom}}
 *    (strtr, en un seul passage) : si ISPAG Project Manager est absent ou ancien, les plugins fonctionnent avec leurs textes par défaut.
 *  - Un texte enregistré identique au texte par défaut n'est pas conservé : il suit alors les futures améliorations du plugin.
 *    Texte vide = texte par défaut.
 *  - Les agents Mistral (identifiant « ag_… ») ont leurs instructions dans Mistral AI Studio, pas dans le code : l'onglet « Mistral agents »
 *    les liste (lecture seule) avec leur situation d'appel. Les plugins les déclarent avec le filtre « ispag_ai_agents ».
 *
 * Stockage : une seule option (ispag_ai_prompts) : [ 'id.champ' => texte ], hors chargement automatique.
 */
class ISPAG_Ai_Prompts {

    const PAGE     = 'ispag-ai';
    const OPTION   = 'ispag_ai_prompts';
    const NONCE    = 'ispag_ai_prompts_';
    const MAX_LEN  = 30000;       // caractères par texte
    const TAB_AGENTS = 'agents';

    public static function init() {
        add_filter('ispag_ai_prompt', [self::class, 'filter_prompt'], 10, 2);
        add_filter('ispag_ai_prompts', [self::class, 'register_own'], 10);
        add_filter('ispag_ai_agents', [self::class, 'register_own_agents'], 10);
        add_action('admin_menu', [self::class, 'admin_menu'], 20);
    }

    // ------------------------------------------------------------------ lecture (utilisée par tous les plugins, via le filtre)

    /** Textes enregistrés : [ 'id.champ' => texte ]. */
    public static function stored(): array {
        $o = get_option(self::OPTION, []);
        return is_array($o) ? $o : [];
    }

    /** Filtre « ispag_ai_prompt » : texte enregistré si présent et non vide, sinon le texte par défaut du plugin. */
    public static function filter_prompt($default, $id = '') {
        $o = self::stored();
        $v = $o[(string) $id] ?? '';
        return is_string($v) && trim($v) !== '' ? $v : $default;
    }

    /** Retour à la ligne unique, sans espace en début ni en fin : même forme pour comparer et enregistrer. */
    private static function norm($s): string {
        return trim(str_replace(["\r\n", "\r"], "\n", (string) $s));
    }

    // ------------------------------------------------------------------ prompts de ce plugin (ISPAG Project Manager)

    /** Textes par défaut de ISPAG Project Manager (identiques à ceux qui étaient écrits dans le code). */
    public static function defaults(): array {
        $d = [];
        $d['pm_language']['main'] = 'Langue de réponse :{{lang}}';
        $d['pm_project_analysis']['main'] = "Voici les fichiers de configuration ISPAG :\n--- DIMENSIONS STANDARDS ---\n{{defaults}}\n--- RESTRICTIONS TECHNIQUES ---\n{{restrictions}}\n\nAnalyse maintenant le document PDF ci-joint en suivant tes instructions d'agent.";
        return $d;
    }

    /** Texte d'un prompt (enregistré ou par défaut), variables {{nom}} non remplacées. */
    public static function text(string $id, string $field): string {
        $d = self::defaults();
        return (string) apply_filters('ispag_ai_prompt', (string) ($d[$id][$field] ?? ''), $id . '.' . $field);
    }

    /** Remplace les variables {{nom}} en un seul passage : une valeur qui contiendrait « {{x}} » n'est jamais réinterprétée. */
    public static function fill(string $template, array $vars): string {
        $map = [];
        foreach ($vars as $k => $v) $map['{{' . $k . '}}'] = (string) $v;
        return $map ? strtr($template, $map) : $template;
    }

    public static function get(string $id, string $field, array $vars = []): string {
        return self::fill(self::text($id, $field), $vars);
    }

    public static function register_own(array $all): array {
        $d = self::defaults();
        $all['pm_project_analysis'] = [
            'order'  => 60,
            'plugin' => 'ISPAG Project Manager',
            'title'  => __('Project document analysis (message)', 'creation-reservoir'),
            'when'   => __('Sent to the Mistral « project » agent when a project PDF is analysed (action « analyze project data »). The message gives the agent the standard dimensions and the technical restrictions of the tank configurator; the instructions of the agent itself are in Mistral AI Studio (see the « Mistral agents » tab). The PDF is attached by the plugin.', 'creation-reservoir'),
            'fields' => ['main' => [
                'label'    => __('Message', 'creation-reservoir'),
                'default'  => $d['pm_project_analysis']['main'],
                'vars'     => [
                    'defaults'     => __('Standard dimensions by volume (file default_value.json of the tank configurator, with its heading)', 'creation-reservoir'),
                    'restrictions' => __('Technical restrictions and materials (file tank_data.json of the tank configurator, with its heading)', 'creation-reservoir'),
                ],
                'required' => ['defaults', 'restrictions'],
            ]],
        ];
        $all['pm_language'] = [
            'order'  => 61,
            'plugin' => 'ISPAG Project Manager',
            'title'  => __('Answer language (Mistral agents, documents)', 'creation-reservoir'),
            'when'   => __('Added at the very end of every message sent to a Mistral document agent (project, supplier offer, drawing, invoice…). It is appended directly after the message, with no separator.', 'creation-reservoir'),
            'fields' => ['main' => [
                'label'    => __('Text added', 'creation-reservoir'),
                'default'  => $d['pm_language']['main'],
                'vars'     => ['lang' => __('Two-letter language code of the site (e.g. fr)', 'creation-reservoir')],
                'required' => ['lang'],
            ]],
        ];
        return $all;
    }

    /** Agents Mistral déclarés par ce plugin : type => [identifiant, situation d'appel]. */
    public static function register_own_agents(array $all): array {
        $when = [
            'analyse_complete'         => __('Alias of the « project » agent (same identifier).', 'creation-reservoir'),
            'project'                  => __('Project document analysis (action « analyze project data », and tank extraction from document pages): list of tanks and their data.', 'creation-reservoir'),
            'purchase'                 => __('Purchases: analysis of a supplier offer (PDF) to get the tanks, prices, quantities and fittings.', 'creation-reservoir'),
            'product_drawing'          => __('Drawing check (action « analyze drawing »): compliance comparison between the database and the PDF drawing.', 'creation-reservoir'),
            'drawing_approval_control' => __('Drawing approval control (action « drawing approval control »).', 'creation-reservoir'),
            'tank_data_extractor'      => __('No call to this agent found in the plugin code.', 'creation-reservoir'),
            'invoice_analyse'          => __('Purchases: analysis of a supplier invoice (action « invoice analyse »).', 'creation-reservoir'),
        ];
        foreach (ISPAG_Mistral::agents() as $type => $id) {
            $all[] = ['plugin' => 'ISPAG Project Manager', 'type' => (string) $type, 'id' => (string) $id, 'when' => $when[$type] ?? ''];
        }
        return $all;
    }

    // ------------------------------------------------------------------ menu

    public static function admin_menu() {
        $title = __('ISPAG AI', 'creation-reservoir');
        add_submenu_page(ISPAG_Settings::PAGE, $title, $title, 'manage_options', self::PAGE, [self::class, 'render']);
    }

    public static function url(string $tab = ''): string {
        return add_query_arg(array_filter(['page' => self::PAGE, 'tab' => $tab]), admin_url('admin.php'));
    }

    // ------------------------------------------------------------------ données de la page

    /** Prompts déclarés par les plugins actifs, dans l'ordre d'affichage. */
    private static function registry(): array {
        $all = (array) apply_filters('ispag_ai_prompts', []);
        $i = 0; $sort = [];
        foreach ($all as $id => $def) {
            if (!is_array($def) || empty($def['fields']) || !is_array($def['fields'])) { unset($all[$id]); continue; }
            $sort[$id] = [(int) ($def['order'] ?? 50), $i++];
        }
        uasort($sort, function ($a, $b) { return $a <=> $b; });
        $out = [];
        foreach (array_keys($sort) as $id) $out[(string) $id] = $all[$id];
        return $out;
    }

    private static function agents(): array {
        return array_values(array_filter((array) apply_filters('ispag_ai_agents', []), 'is_array'));
    }

    /** Valide et nettoie un texte envoyé par le formulaire. */
    private static function clean($raw): string {
        $s = wp_kses_no_null(wp_check_invalid_utf8((string) wp_unslash($raw)));
        return self::norm($s);
    }

    /** Variables {{nom}} présentes dans un texte. */
    private static function vars_in(string $text): array {
        preg_match_all('/\{\{([A-Za-z0-9_]+)\}\}/', $text, $m);
        return array_values(array_unique($m[1]));
    }

    // ------------------------------------------------------------------ enregistrement

    /** @return string[] messages HTML (notices) */
    private static function handle_post(string $tab, array $def): array {
        $notices = [];
        $nonce = isset($_POST['_wpnonce']) ? sanitize_text_field(wp_unslash($_POST['_wpnonce'])) : '';
        if (!wp_verify_nonce($nonce, self::NONCE . $tab)) {
            return ['<div class="notice notice-error"><p>' . esc_html__('The page has expired, please try again.', 'creation-reservoir') . '</p></div>'];
        }
        $stored = self::stored();

        if (!empty($_POST['ispag_ai_restore'])) {
            foreach (array_keys($def['fields']) as $f) unset($stored[$tab . '.' . $f]);
            update_option(self::OPTION, $stored, false);
            return ['<div class="notice notice-success"><p>' . esc_html__('Default text restored.', 'creation-reservoir') . '</p></div>'];
        }

        $posted = isset($_POST['ispag_ai']) && is_array($_POST['ispag_ai']) ? $_POST['ispag_ai'] : [];
        $errors = []; $warnings = [];
        foreach ($def['fields'] as $f => $fd) {
            if (!isset($posted[$f]) || !is_string($posted[$f])) continue;
            $key = $tab . '.' . $f;
            $val = self::clean($posted[$f]);
            $label = (string) ($fd['label'] ?? $f);

            if ($val === '' || $val === self::norm($fd['default'] ?? '')) { unset($stored[$key]); continue; }   // vide ou identique : texte par défaut

            if (mb_strlen($val) > self::MAX_LEN) {
                $errors[] = sprintf(esc_html__('“%1$s” is too long (maximum %2$d characters): it was not saved.', 'creation-reservoir'), esc_html($label), self::MAX_LEN);
                continue;
            }
            $missing = [];
            foreach ((array) ($fd['required'] ?? []) as $v) { if (strpos($val, '{{' . $v . '}}') === false) $missing[] = '{{' . $v . '}}'; }
            if ($missing) {
                $errors[] = sprintf(esc_html__('“%1$s” must contain %2$s: it was not saved.', 'creation-reservoir'), esc_html($label), '<code>' . esc_html(implode(', ', $missing)) . '</code>');
                continue;
            }
            $unknown = array_diff(self::vars_in($val), array_keys((array) ($fd['vars'] ?? [])));
            if ($unknown) {
                $warnings[] = sprintf(esc_html__('“%1$s”: %2$s is not a known variable and will be sent as written.', 'creation-reservoir'), esc_html($label), '<code>{{' . esc_html(implode('}}, {{', $unknown)) . '}}</code>');
            }
            $stored[$key] = $val;
        }
        update_option(self::OPTION, $stored, false);
        foreach ($errors as $e)   $notices[] = '<div class="notice notice-error"><p>' . $e . '</p></div>';
        foreach ($warnings as $w) $notices[] = '<div class="notice notice-warning"><p>' . $w . '</p></div>';
        if (!$errors) $notices[] = '<div class="notice notice-success"><p>' . esc_html__('Saved.', 'creation-reservoir') . '</p></div>';
        return $notices;
    }

    // ------------------------------------------------------------------ page

    public static function render() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Not authorized', 'creation-reservoir'));
        }
        $reg = self::registry();
        $tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : '';
        if ($tab !== self::TAB_AGENTS && !isset($reg[$tab])) $tab = $reg ? (string) array_key_first($reg) : self::TAB_AGENTS;

        $notices = [];
        if ($tab !== self::TAB_AGENTS && !empty($_POST['ispag_ai_form']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
            $notices = self::handle_post($tab, $reg[$tab]);
        }

        echo '<div class="wrap"><h1>' . esc_html__('ISPAG AI', 'creation-reservoir') . '</h1>';
        echo '<p>' . esc_html__('Texts sent to the AI by the ISPAG plugins. Each tab is one situation: the description tells when the text is sent, and you can adjust its wording. A text left unchanged follows the default of the plugin (including its future improvements). Words between double braces, like {{locale}}, are replaced by the plugin when the text is sent: keep them.', 'creation-reservoir') . '</p>';

        echo '<h2 class="nav-tab-wrapper">';
        foreach ($reg as $id => $def) {
            echo '<a class="nav-tab' . ($id === $tab ? ' nav-tab-active' : '') . '" href="' . esc_url(self::url($id)) . '">' . esc_html((string) ($def['title'] ?? $id)) . '</a>';
        }
        echo '<a class="nav-tab' . ($tab === self::TAB_AGENTS ? ' nav-tab-active' : '') . '" href="' . esc_url(self::url(self::TAB_AGENTS)) . '">' . esc_html__('Mistral agents', 'creation-reservoir') . '</a>';
        echo '</h2>';

        foreach ($notices as $n) echo $n;   // déjà échappés

        if ($tab === self::TAB_AGENTS) self::render_agents(); else self::render_prompt($tab, $reg[$tab]);
        echo '</div>';
    }

    private static function render_prompt(string $tab, array $def) {
        $stored = self::stored();
        echo '<h2>' . esc_html((string) ($def['title'] ?? $tab)) . '</h2>';
        if (!empty($def['plugin'])) echo '<p class="description">' . esc_html__('Plugin', 'creation-reservoir') . ' : ' . esc_html((string) $def['plugin']) . '</p>';
        echo '<div style="background:#fff;border-left:4px solid #2271b1;padding:8px 14px;margin:10px 0 16px;max-width:900px"><p style="margin:0 0 4px"><strong>' . esc_html__('When it is called', 'creation-reservoir') . '</strong></p><p style="margin:0">' . esc_html((string) ($def['when'] ?? '')) . '</p></div>';

        echo '<form method="post" action="' . esc_url(self::url($tab)) . '">';
        wp_nonce_field(self::NONCE . $tab);
        echo '<input type="hidden" name="ispag_ai_form" value="1">';
        foreach ($def['fields'] as $f => $fd) {
            $key = $tab . '.' . $f;
            $default = self::norm($fd['default'] ?? '');
            $custom = isset($stored[$key]) && is_string($stored[$key]) && trim($stored[$key]) !== '' && self::norm($stored[$key]) !== $default;
            $value = $custom ? self::norm($stored[$key]) : $default;
            $rows = max(6, min(32, substr_count($value, "\n") + 3));
            $id = 'ispag_ai_' . $tab . '_' . $f;
            echo '<h3 style="margin-bottom:4px"><label for="' . esc_attr($id) . '">' . esc_html((string) ($fd['label'] ?? $f)) . '</label> ';
            echo $custom
                ? '<span style="font-size:12px;font-weight:600;color:#9a6700;background:#fff8c5;padding:1px 8px;border-radius:10px">' . esc_html__('Customised', 'creation-reservoir') . '</span>'
                : '<span style="font-size:12px;font-weight:400;color:#50575e;background:#f0f0f1;padding:1px 8px;border-radius:10px">' . esc_html__('Default', 'creation-reservoir') . '</span>';
            echo '</h3>';
            echo '<textarea class="large-text code" style="max-width:900px;font-size:13px;line-height:1.5" rows="' . (int) $rows . '" id="' . esc_attr($id) . '" name="ispag_ai[' . esc_attr($f) . ']">' . esc_textarea($value) . '</textarea>';
            if (!empty($fd['vars']) && is_array($fd['vars'])) {
                echo '<p class="description" style="max-width:900px"><strong>' . esc_html__('Variables replaced when the text is sent', 'creation-reservoir') . '</strong><br>';
                foreach ($fd['vars'] as $name => $desc) {
                    $req = in_array($name, (array) ($fd['required'] ?? []), true);
                    echo '<code>{{' . esc_html((string) $name) . '}}</code> — ' . esc_html((string) $desc) . ($req ? ' <em>(' . esc_html__('required', 'creation-reservoir') . ')</em>' : '') . '<br>';
                }
                echo '</p>';
            }
            if ($custom) {
                echo '<details style="max-width:900px;margin:6px 0 0"><summary style="cursor:pointer;color:#2271b1">' . esc_html__('Show the default text', 'creation-reservoir') . '</summary><textarea class="large-text code" style="font-size:12px;background:#f6f7f7" rows="' . (int) max(4, min(20, substr_count($default, "\n") + 2)) . '" readonly>' . esc_textarea($default) . '</textarea></details>';
            }
        }
        echo '<p style="margin-top:18px"><button type="submit" class="button button-primary">' . esc_html__('Save', 'creation-reservoir') . '</button> ';
        echo '<button type="submit" name="ispag_ai_restore" value="1" class="button" onclick="return confirm(' . esc_attr(wp_json_encode(__('Restore the default text of this tab? Your changes will be lost.', 'creation-reservoir'))) . ');">' . esc_html__('Restore default', 'creation-reservoir') . '</button></p>';
        echo '</form>';
    }

    private static function render_agents() {
        echo '<h2>' . esc_html__('Mistral agents', 'creation-reservoir') . '</h2>';
        echo '<div style="background:#fff;border-left:4px solid #2271b1;padding:8px 14px;margin:10px 0 16px;max-width:900px"><p style="margin:0">'
            . esc_html__('For these requests the plugins only send a message and the identifier of a Mistral agent. The instructions of an agent are kept by Mistral (Mistral AI Studio), not in the plugin code: they cannot be shown or edited here. This list tells which agent is called, and when.', 'creation-reservoir') . '</p></div>';
        $agents = self::agents();
        if (!$agents) { echo '<p>' . esc_html__('No agent declared.', 'creation-reservoir') . '</p>'; return; }
        echo '<table class="widefat striped" style="max-width:1100px"><thead><tr><th>' . esc_html__('Plugin', 'creation-reservoir') . '</th><th>' . esc_html__('Request type', 'creation-reservoir') . '</th><th>' . esc_html__('Agent identifier', 'creation-reservoir') . '</th><th>' . esc_html__('When it is used', 'creation-reservoir') . '</th></tr></thead><tbody>';
        foreach ($agents as $a) {
            echo '<tr><td>' . esc_html((string) ($a['plugin'] ?? '')) . '</td><td><code>' . esc_html((string) ($a['type'] ?? '')) . '</code></td><td><code>' . esc_html((string) ($a['id'] ?? '')) . '</code></td><td>' . esc_html((string) ($a['when'] ?? '')) . '</td></tr>';
        }
        echo '</tbody></table>';
    }
}
