<?php
defined('ABSPATH') or die();

/**
 * CRON hebdomadaire : détecte les lignes orphelines dans les tables achats_*.
 *
 * Rien n'est supprimé automatiquement : pour chaque nettoyage qui a des lignes à supprimer,
 * une notification (ISPAG_Notifications_Manager) est envoyée à l'admin. Le lien de la
 * notification (signé, valable 14 jours, réservé aux manage_options) exécute la suppression.
 */
class ISPAG_Cleanup_Orphans_Cron {
    const EVENT      = 'ispag_cleanup_orphans_event';
    const QUERY_VAR  = 'ispag_cleanup_run';
    const LOG_NAME   = 'cleanup_orphans';
    const LINK_TTL   = 14 * DAY_IN_SECONDS;

    protected $wpdb;
    public static $instance = null;

    public static function init() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;

        add_action(self::EVENT, [$this, 'check_and_notify']);
        // Priorité 11 : cette classe est instanciée pendant « init » (priorité 10) ; un rappel ajouté à la priorité en cours n'est pas exécuté
        add_action('init', [$this, 'maybe_execute_from_link'], 11);

        if (!wp_next_scheduled(self::EVENT)) {
            wp_schedule_event(strtotime('next monday 01:00:00'), 'weekly', self::EVENT);
        }
    }

    /**
     * Définition des nettoyages. Chaque entrée :
     *  - label   : texte affiché ;
     *  - table / where : table cible et condition SQL (alias t) qui désigne les lignes concernées ;
     *  - columns : colonnes du détail affiché pour décider (libellé => expression SQL sur t) ;
     *  - filter  : (optionnel) filtre PHP supplémentaire sur la ligne ;
     *  - delete  : (optionnel) suppression propre à la ligne au lieu d'un DELETE direct (suppression d'un projet = plusieurs tables).
     */
    protected function jobs() {
        $p = $this->wpdb->prefix;
        $u = $this->wpdb->users;
        return [
            'old_projects' => [
                'label'   => __('Quotations / projects without articles, inactive for more than 3 months (created by non-ISPAG users)', 'creation-reservoir'),
                'table'   => $p . 'achats_liste_commande',
                'where'   => "t.isQotation IS NOT NULL AND t.TimestampDateCommande < " . (int) strtotime('-3 months') . "
                              AND NOT EXISTS (SELECT 1 FROM {$p}achats_details_commande a WHERE a.hubspot_deal_id = t.hubspot_deal_id)",
                'columns' => [
                    __('Project', 'creation-reservoir')        => 't.ObjetCommande',
                    __('Project number', 'creation-reservoir') => 't.NumCommande',
                    __('Deal ID', 'creation-reservoir')        => 't.hubspot_deal_id',
                    __('Created on', 'creation-reservoir')     => "DATE_FORMAT(FROM_UNIXTIME(t.TimestampDateCommande), '%d.%m.%Y')",
                    __('Created by', 'creation-reservoir')     => "(SELECT display_name FROM {$u} WHERE ID = t.created_by)",
                ],
                'extra'   => ['created_by' => 't.created_by'],
                'filter'  => [$this, 'creator_is_external'],
                'delete'  => [$this, 'delete_project'],
                'deal_col' => 'hubspot_deal_id',
            ],
            'info_commande' => [
                'label' => __('Delivery information without project or purchase order', 'creation-reservoir'),
                'table' => $p . 'achats_info_commande',
                'where' => "(t.hubspot_deal_id IS NULL OR t.hubspot_deal_id = 0)
                            AND (t.purchase_order IS NULL OR t.purchase_order = 0)",
                'columns' => [
                    __('Adress', 'creation-reservoir')  => "CONCAT_WS(', ', NULLIF(t.AdresseDeLivraison, ''), NULLIF(t.NIP, ''), NULLIF(t.City, ''))",
                    __('Contact', 'creation-reservoir') => 't.PersonneContact',
                    __('Comment', 'creation-reservoir') => 'LEFT(t.Comment, 120)',
                ],
            ],
            'details_commande' => [
                'label' => __('Project articles whose project no longer exists', 'creation-reservoir'),
                'table' => $p . 'achats_details_commande',
                // On ne supprime pas les articles que le CRON historique peut rattacher via IdArticleMaster.
                'where' => "NOT EXISTS (SELECT 1 FROM {$p}achats_liste_commande pr WHERE pr.hubspot_deal_id = t.hubspot_deal_id)
                            AND NOT EXISTS (
                                SELECT 1 FROM {$p}achats_details_commande m
                                JOIN {$p}achats_liste_commande pm ON pm.hubspot_deal_id = m.hubspot_deal_id
                                WHERE m.Id = t.IdArticleMaster
                            )",
                'columns' => [
                    __('Article', 'creation-reservoir')   => 'LEFT(t.Article, 120)',
                    __('Quantity', 'creation-reservoir')  => 't.Qty',
                    __('Serial number', 'creation-reservoir') => 't.serial_no',
                    __('Deal ID', 'creation-reservoir')   => 't.hubspot_deal_id',
                    __('Created on', 'creation-reservoir') => "DATE_FORMAT(t.created_at, '%d.%m.%Y')",
                    __('Created by', 'creation-reservoir') => "(SELECT display_name FROM {$u} WHERE ID = t.created_by)",
                ],
            ],
            'tank_dimensions' => [
                'label' => __('Tanks linked to an article that no longer exists', 'creation-reservoir'),
                'table' => $p . 'achats_tank_dimensions',
                'where' => "t.customerTankId IS NOT NULL
                            AND NOT EXISTS (SELECT 1 FROM {$p}achats_details_commande a WHERE a.Id = t.customerTankId)",
                'columns' => [
                    __('Type', 'creation-reservoir')       => 't.TankType',
                    __('Material', 'creation-reservoir')   => 't.Material',
                    __('Volume', 'creation-reservoir')     => 't.Volume',
                    __('Diameter', 'creation-reservoir')   => 't.Diameter',
                    __('Height', 'creation-reservoir')     => 't.Height',
                    __('Missing article ID', 'creation-reservoir') => 't.customerTankId',
                    __('Created on', 'creation-reservoir') => "DATE_FORMAT(t.creation_date, '%d.%m.%Y')",
                ],
            ],
            'tank_heat_exchanger' => [
                'label' => __('Heat exchangers without tank', 'creation-reservoir'),
                'table' => $p . 'achats_tank_heat_exchanger',
                'where' => "NOT EXISTS (SELECT 1 FROM {$p}achats_tank_dimensions d WHERE d.Id = t.tank_id)",
                'columns' => [
                    __('Missing tank ID', 'creation-reservoir') => 't.tank_id',
                    __('Surface', 'creation-reservoir')         => 't.heatExchangerSurface',
                    __('Details', 'creation-reservoir')         => 'LEFT(t.coilDetails, 120)',
                ],
            ],
            'tank_connection' => [
                'label' => __('Connections (fittings) without tank', 'creation-reservoir'),
                'table' => $p . 'achats_tank_connection',
                'where' => "NOT EXISTS (SELECT 1 FROM {$p}achats_tank_dimensions d WHERE d.Id = t.TankId)",
                'columns' => [
                    __('Missing tank ID', 'creation-reservoir') => 't.TankId',
                    __('Type', 'creation-reservoir')            => 't.Type',
                    __('Inches', 'creation-reservoir')          => 't.Pouces',
                    __('Height', 'creation-reservoir')          => 't.Height',
                    __('Angle', 'creation-reservoir')           => 't.Angle',
                ],
            ],
        ];
    }

    /** Un projet n'est proposé à la suppression que s'il vient d'un utilisateur sans rôle ISPAG. */
    public function creator_is_external($row) {
        $user = get_userdata((int) ($row['created_by'] ?? 0));
        if (!$user) return false;
        return !array_intersect(['achat_ispag', 'vente_ispag', 'administrator', 'membre_ispag'], (array) $user->roles);
    }

    /** Suppression d'un projet : mêmes actions que le bouton « Supprimer le projet ». */
    public function delete_project($row) {
        $deal_id = (int) ($row['hubspot_deal_id'] ?? 0);
        if (!$deal_id) return false;
        do_action('ispag_delete_document_whith_deal_id', null, $deal_id);
        do_action('ispag_delete_articles_whith_deal_id', null, $deal_id);
        do_action('ispag_delete_suivis_whith_deal_id', null, $deal_id);
        do_action('ispag_delete_project_whith_deal_id', null, $deal_id);
        return true;
    }

    protected function table_exists($table) {
        return $this->wpdb->get_var($this->wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
    }

    /**
     * Lignes concernées par un nettoyage, avec leur détail : [ ['id' => 12, 'cells' => [libellé => valeur], 'raw' => [...]], … ].
     * @param int $limit 0 = toutes (utilisé pour compter et pour contrôler une sélection)
     */
    protected function fetch_rows($key, array $job, $limit = 0, array $only_ids = []) {
        if (!$this->table_exists($job['table'])) {
            return [];
        }
        $select = ['t.Id AS __id'];
        $i = 0;
        foreach ($job['columns'] as $label => $expr) {
            $select[] = "($expr) AS c$i";
            $i++;
        }
        foreach ((array) ($job['extra'] ?? []) as $alias => $expr) {
            $select[] = "($expr) AS $alias";
        }
        if (!empty($job['deal_col'])) {
            $select[] = "t.{$job['deal_col']} AS {$job['deal_col']}";
        }
        $where = $job['where'];
        if ($only_ids) {
            $where = "($where) AND t.Id IN (" . implode(',', array_map('intval', $only_ids)) . ')';
        }
        $sql  = "SELECT " . implode(', ', $select) . " FROM {$job['table']} t WHERE $where ORDER BY t.Id DESC";
        $rows = $this->wpdb->get_results($sql, ARRAY_A) ?: [];

        $out = [];
        foreach ($rows as $r) {
            if (!empty($job['filter']) && !call_user_func($job['filter'], $r)) {
                continue;
            }
            $cells = [];
            $i = 0;
            foreach ($job['columns'] as $label => $expr) {
                $cells[$label] = (string) $r["c$i"];
                $i++;
            }
            $out[] = ['id' => (int) $r['__id'], 'cells' => $cells, 'raw' => $r];
            if ($limit && count($out) >= $limit) break;
        }
        return $out;
    }

    protected function count_orphans($key, array $job) {
        return count($this->fetch_rows($key, $job));
    }

    /**
     * CRON : compte les lignes concernées et envoie une notification par nettoyage concerné.
     * Le lien de la notification ouvre la page de contrôle (liste détaillée avec cases à cocher) : rien n'est supprimé sans validation.
     */
    public function check_and_notify() {
        $logger = ISPAG_Logger::get_instance();
        $logger->log(self::LOG_NAME, '--- DÉBUT VÉRIFICATION ORPHELINS ---');

        foreach ($this->jobs() as $key => $job) {
            $count = $this->count_orphans($key, $job);
            if ($count === 0) {
                $logger->log(self::LOG_NAME, "[$key] Rien à nettoyer.");
                continue;
            }

            if (!class_exists('ISPAG_Notifications_Manager')) {
                $logger->log(self::LOG_NAME, "[$key] $count ligne(s) orphelines mais ISPAG_Notifications_Manager indisponible.");
                continue;
            }

            ISPAG_Notifications_Manager::send(
                [1], // Admin (ID = 1)
                'article_cleanup',
                '🗑️ Weekly cleanup: ' . $key,
                sprintf(
                    '%d row(s) to review: %s. Open this notification to check the list and choose what to delete.',
                    $count,
                    $job['label']
                ),
                $this->build_link($key),
                0
            );
            $logger->log(self::LOG_NAME, "[$key] Notification envoyée ($count ligne(s)).");
        }

        $logger->log(self::LOG_NAME, '--- FIN VÉRIFICATION ORPHELINS ---');
    }

    protected function sign($key, $expires) {
        return hash_hmac('sha256', $key . '|' . $expires, wp_salt('auth'));
    }

    /** Lien (signé, 14 jours) vers la page de contrôle ; $key désigne le nettoyage à ouvrir en premier. */
    public function build_link($key) {
        $expires = time() + self::LINK_TTL;
        return add_query_arg([
            self::QUERY_VAR => $key,
            'exp'           => $expires,
            'sig'           => $this->sign($key, $expires),
        ], home_url('/'));
    }

    /**
     * Lien de notification cliqué : vérifie la signature + les droits, puis affiche la page de contrôle.
     * La suppression n'a lieu que sur envoi du formulaire (POST + nonce), uniquement pour les lignes cochées.
     */
    public function maybe_execute_from_link() {
        if (empty($_GET[self::QUERY_VAR])) {
            return;
        }

        $key     = sanitize_key(wp_unslash($_GET[self::QUERY_VAR]));
        $expires = isset($_GET['exp']) ? (int) $_GET['exp'] : 0;
        $sig     = isset($_GET['sig']) ? (string) wp_unslash($_GET['sig']) : '';

        $jobs = $this->jobs();
        if (!isset($jobs[$key]) || !hash_equals($this->sign($key, $expires), $sig)) {
            wp_die(esc_html__('Invalid cleanup link.', 'creation-reservoir'), '', ['response' => 403]);
        }
        if ($expires < time()) {
            wp_die(esc_html__('This cleanup link has expired.', 'creation-reservoir'), '', ['response' => 410]);
        }
        if (!is_user_logged_in()) {
            auth_redirect();
        }
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to run this cleanup.', 'creation-reservoir'), '', ['response' => 403]);
        }

        $result = null;
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cleanup_job'])) {
            check_admin_referer('ispag_cleanup_delete');
            $job_key = sanitize_key(wp_unslash($_POST['cleanup_job']));
            $ids     = array_filter(array_map('intval', (array) ($_POST['ids'] ?? [])));
            if (isset($jobs[$job_key]) && $ids) {
                $result = ['key' => $job_key, 'deleted' => $this->execute_selected($job_key, $ids)];
            }
        }

        $this->render_review($key, $result);
        exit;
    }

    /**
     * Supprime les lignes cochées d'un nettoyage. Chaque identifiant est revérifié au moment de l'exécution :
     * une ligne qui n'est plus concernée (réparée entre-temps) n'est jamais supprimée.
     */
    protected function execute_selected($key, array $ids) {
        $jobs   = $this->jobs();
        $job    = $jobs[$key];
        $logger = ISPAG_Logger::get_instance();

        $valid   = $this->fetch_rows($key, $job, 0, $ids);
        $deleted = 0;
        if (!empty($job['delete'])) {
            foreach ($valid as $row) {
                if (call_user_func($job['delete'], $row['raw'])) $deleted++;
            }
        } else {
            $valid_ids = array_column($valid, 'id');
            foreach (array_chunk($valid_ids, 500) as $chunk) {
                $affected = $this->wpdb->query("DELETE FROM {$job['table']} WHERE Id IN (" . implode(',', $chunk) . ')');
                $deleted += $affected === false ? 0 : (int) $affected;
            }
        }

        $logger->log(self::LOG_NAME, "[$key] $deleted ligne(s) supprimée(s) par l'utilisateur " . get_current_user_id() . ' (ids : ' . implode(',', $ids) . ').', get_current_user_id());
        return $deleted;
    }

    // ------------------------------------------------------------------ page de contrôle

    protected function render_review($open_key, $result) {
        $jobs   = $this->jobs();
        $action = esc_url(add_query_arg(null, null));
        $max    = 500;
        nocache_headers();
        header('X-Robots-Tag: noindex, nofollow');
        ?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo('charset'); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?php esc_html_e('Data cleanup', 'creation-reservoir'); ?></title>
<style>
 * { box-sizing: border-box; }
 body { margin: 0; font-family: system-ui, -apple-system, "Segoe UI", sans-serif; background: #f3f4f6; color: #212529; }
 .wrap { max-width: 1100px; margin: 0 auto; padding: 18px 14px 60px; }
 h1 { color: #c80000; margin: 6px 0 4px; font-size: 24px; }
 .lead { color: #6b7280; margin: 0 0 18px; }
 .card { background: #fff; border-radius: 12px; box-shadow: 0 2px 10px rgba(0,0,0,.08); border-top: 4px solid #c80000; padding: 16px; margin-bottom: 18px; }
 .card h2 { margin: 0; font-size: 17px; }
 .card .sub { color: #6b7280; font-size: 13px; margin: 4px 0 12px; }
 .notice { background: #dcfce7; color: #166534; border-radius: 8px; padding: 10px 14px; margin-bottom: 16px; }
 .tablewrap { overflow-x: auto; }
 table { width: 100%; border-collapse: collapse; font-size: 14px; }
 th, td { text-align: left; padding: 7px 9px; border-bottom: 1px solid #eef0f2; vertical-align: top; }
 th { background: #f9fafb; font-size: 12px; text-transform: uppercase; letter-spacing: .03em; color: #6b7280; white-space: nowrap; }
 tr.sel td { background: #fff1f1; }
 td.chk, th.chk { width: 34px; }
 input[type=checkbox] { width: 18px; height: 18px; }
 .bar { display: flex; align-items: center; gap: 14px; margin-top: 12px; flex-wrap: wrap; }
 button { font-size: 15px; padding: 10px 16px; border: 0; border-radius: 8px; background: #c80000; color: #fff; font-weight: 600; cursor: pointer; }
 button:disabled { opacity: .45; cursor: not-allowed; }
 .muted { color: #6b7280; font-size: 13px; }
 .empty { color: #6b7280; padding: 8px 0; }
</style>
</head>
<body>
<div class="wrap">
 <h1>🗑️ <?php esc_html_e('Data cleanup', 'creation-reservoir'); ?></h1>
 <p class="lead"><?php esc_html_e('Check the details, tick what can be deleted and confirm. Nothing is deleted without your validation.', 'creation-reservoir'); ?></p>

 <?php if ($result): ?>
  <div class="notice">✅ <?php echo esc_html(sprintf(__('%1$d row(s) deleted: %2$s', 'creation-reservoir'), $result['deleted'], $jobs[$result['key']]['label'])); ?></div>
 <?php endif; ?>

 <?php
 $any = false;
 // La section ouverte par la notification passe en premier
 $order = array_merge([$open_key], array_diff(array_keys($jobs), [$open_key]));
 foreach ($order as $key):
     $job  = $jobs[$key];
     $all  = $this->fetch_rows($key, $job);
     if (!$all) continue;
     $any  = true;
     $rows = array_slice($all, 0, $max);
 ?>
 <form class="card" method="post" action="<?php echo $action; ?>" data-job="<?php echo esc_attr($key); ?>">
  <?php wp_nonce_field('ispag_cleanup_delete'); ?>
  <input type="hidden" name="cleanup_job" value="<?php echo esc_attr($key); ?>">
  <h2><?php echo esc_html($job['label']); ?></h2>
  <div class="sub"><?php echo esc_html(sprintf(_n('%d row found', '%d rows found', count($all), 'creation-reservoir'), count($all))); ?>
   <?php if (count($all) > $max) echo ' · ' . esc_html(sprintf(__('the first %d are shown', 'creation-reservoir'), $max)); ?> · <code><?php echo esc_html(preg_replace('/^' . preg_quote($this->wpdb->prefix, '/') . '/', '', $job['table'])); ?></code></div>
  <div class="tablewrap"><table>
   <thead><tr>
    <th class="chk"><input type="checkbox" class="all" aria-label="<?php esc_attr_e('Select all', 'creation-reservoir'); ?>"></th>
    <th>ID</th>
    <?php foreach (array_keys($job['columns']) as $label): ?><th><?php echo esc_html($label); ?></th><?php endforeach; ?>
   </tr></thead>
   <tbody>
   <?php foreach ($rows as $row): ?>
    <tr>
     <td class="chk"><input type="checkbox" class="pick" name="ids[]" value="<?php echo (int) $row['id']; ?>"></td>
     <td><?php echo (int) $row['id']; ?></td>
     <?php foreach ($row['cells'] as $value): ?><td><?php echo esc_html(wp_strip_all_tags(stripslashes($value))); ?></td><?php endforeach; ?>
    </tr>
   <?php endforeach; ?>
   </tbody>
  </table></div>
  <div class="bar">
   <button type="submit" disabled><?php esc_html_e('Delete the selected rows', 'creation-reservoir'); ?> (<span class="n">0</span>)</button>
   <span class="muted"><?php esc_html_e('Unticked rows are kept.', 'creation-reservoir'); ?></span>
  </div>
 </form>
 <?php endforeach; ?>

 <?php if (!$any): ?>
  <div class="card"><div class="empty">✅ <?php esc_html_e('Nothing to clean: all checks are clear.', 'creation-reservoir'); ?></div></div>
 <?php endif; ?>
</div>
<script>
document.querySelectorAll('form[data-job]').forEach(function (f) {
  var all = f.querySelector('.all'), picks = f.querySelectorAll('.pick'), btn = f.querySelector('button'), n = f.querySelector('.n');
  var msg = <?php echo wp_json_encode(__('Delete the selected rows? This cannot be undone.', 'creation-reservoir')); ?>;
  function update() {
    var c = f.querySelectorAll('.pick:checked').length;
    n.textContent = c; btn.disabled = c === 0;
    all.checked = c === picks.length; all.indeterminate = c > 0 && c < picks.length;
    picks.forEach(function (p) { p.closest('tr').className = p.checked ? 'sel' : ''; });
  }
  all.addEventListener('change', function () { picks.forEach(function (p) { p.checked = all.checked; }); update(); });
  picks.forEach(function (p) { p.addEventListener('change', update); });
  f.addEventListener('submit', function (e) { if (!confirm(msg + ' (' + n.textContent + ')')) e.preventDefault(); });
  update();
});
</script>
</body>
</html>
        <?php
    }
}
