<?php
defined('ABSPATH') or die();

/**
 * ISPAG_Sales_Price_Import_Admin
 *
 * Pages d'administration pour :
 *  1) Importer un fichier CSV de tarifs de vente (existant)
 *  2) Importer / mettre à jour des articles (nouveau)
 *
 * ── Import tarifs de vente ──────────────────────────────────────────────
 * Format CSV attendu (séparateur ; ou ,) :
 *   ref_article_ispag ; sales_price ; note
 *
 * ── Import articles ─────────────────────────────────────────────────────
 * Format CSV attendu (séparateur ; ou ,) :
 *   TypeArticle ; ref_article_ispag ; TitreArticle ; description_ispag ; conception ; sales_price (optionnelle)
 *
 * Si ref_article_ispag existe déjà → l'article est mis à jour.
 * Sinon → il est créé.
 * "conception" doit être un JSON valide (le champ doit être entre guillemets dans le CSV
 * s'il contient le séparateur choisi).
 * Si la colonne "sales_price" est présente et renseignée sur une ligne, un nouveau prix
 * de vente est historisé exactement comme dans l'import de tarifs existant.
 */
class ISPAG_Sales_Price_Import_Admin {

    private string $table_articles;
    private string $table_history;
    private string $nonce_action          = 'ispag_sales_price_import';
    private string $nonce_action_articles = 'ispag_article_import';
    protected static ?self $instance = null;

    public function __construct() {
        global $wpdb;
        $this->table_articles = $wpdb->prefix . 'achats_articles';
        $this->table_history  = $wpdb->prefix . 'achats_articles_price_history';
    }

    public static function init(): void {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        add_action('admin_menu',                            [self::$instance, 'register_menu']);
        add_action('admin_post_ispag_sales_price_import',  [self::$instance, 'handle_import']);
        add_action('admin_post_ispag_article_import',      [self::$instance, 'handle_article_import']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // MENU
    // ─────────────────────────────────────────────────────────────────────────

    public function register_menu(): void {
        add_submenu_page(
            'ispag-entreprises',
            __('Import tarifs vente', 'ispag'),
            __('Import tarifs vente', 'ispag'),
            'manage_options',
            'ispag_sales_price_import',
            [self::$instance, 'render_page']
        );

        add_submenu_page(
            'ispag-entreprises',
            __('Import articles', 'ispag'),
            __('Import articles', 'ispag'),
            'manage_options',
            'ispag_article_import',
            [self::$instance, 'render_articles_page']
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PAGE D'ADMINISTRATION — TARIFS DE VENTE (existant, inchangé)
    // ─────────────────────────────────────────────────────────────────────────

    public function render_page(): void {
        if (!current_user_can('manage_options')) wp_die(__('Access denied'));

        $transient_key = 'ispag_sales_price_import_result_' . get_current_user_id();
        $result        = get_transient($transient_key);
        if ($result) {
            delete_transient($transient_key);
        }
        ?>
        <div class="wrap">
            <h1><?= esc_html__('Sales price import', 'ispag') ?></h1>

            <?php if ($result): ?>
                <div class="notice notice-<?= in_array($result['type'], ['error', 'warning']) ? $result['type'] : 'success' ?> is-dismissible">
                    <p><?= wp_kses_post($result['message']) ?></p>
                    <?php if (!empty($result['details'])): ?>
                        <details>
                            <summary><?= esc_html__('View line-by-line details', 'ispag') ?></summary>
                            <ul style="max-height:300px;overflow-y:auto;margin-top:8px;">
                                <?php foreach ($result['details'] as $line): ?>
                                    <li style="color:<?= $line['ok'] ? 'green' : '#cc0000' ?>">
                                        <?= esc_html($line['msg']) ?>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </details>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <div style="max-width:640px;background:#fff;padding:24px;border:1px solid #ccd0d4;border-radius:4px;margin-top:16px;">

                <h2 style="margin-top:0"><?= esc_html__('Import a CSV file', 'ispag') ?></h2>

                <p><?= esc_html__('Expected format (separator ; or ,):', 'ispag') ?></p>
                <code style="display:block;background:#f0f0f0;padding:8px;margin-bottom:16px;">
                    ref_article_ispag ; sales_price ; note
                </code>

                <form method="post" action="<?= esc_url(admin_url('admin-post.php')) ?>" enctype="multipart/form-data">
                    <?php wp_nonce_field($this->nonce_action, 'ispag_nonce') ?>
                    <input type="hidden" name="action" value="ispag_sales_price_import">

                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row">
                                <label for="csv_file"><?= esc_html__('Fichier CSV', 'ispag') ?></label>
                            </th>
                            <td>
                                <input type="file" id="csv_file" name="csv_file" accept=".csv,.txt" required>
                                <p class="description">
                                    <?= esc_html__('UTF-8 encoding recommended. Separator ; or , detected automatically.', 'ispag') ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="valid_from"><?= esc_html__('Effective date', 'ispag') ?></label>
                            </th>
                            <td>
                                <input type="date" id="valid_from" name="valid_from"
                                       value="<?= esc_attr(date('Y-m-d')) ?>" required>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <?= esc_html__('Mode simulation', 'ispag') ?>
                            </th>
                            <td>
                                <label>
                                    <input type="checkbox" name="dry_run" value="1" checked>
                                    <?= esc_html__('Simulate without writing to the database (dry run)', 'ispag') ?>
                                </label>
                                <p class="description">
                                    <?= esc_html__('In simulation mode, no data is modified. Uncheck to actually apply the changes.', 'ispag') ?>
                                </p>
                            </td>
                        </tr>
                    </table>

                    <?php submit_button(__('Import the CSV', 'ispag')) ?>
                </form>
            </div>

            <div style="max-width:640px;margin-top:24px;">
                <h3><?= esc_html__('Example of a valid CSV file', 'ispag') ?></h3>
                <table class="widefat striped" style="font-size:13px;">
                    <thead>
                        <tr>
                            <th>ref_article_ispag</th>
                            <th>sales_price</th>
                            <th>note</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr><td>REF-ABC-001</td><td>125.50</td><td>Tarif 2026</td></tr>
                        <tr><td>REF-ABC-002</td><td>89.00</td><td></td></tr>
                        <tr><td>XYZ-999</td><td>440.00</td><td>Hausse matière</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
        <?php
    }

    public function handle_import(): void {
        if (!current_user_can('manage_options')) wp_die(__('Access denied'));
        check_admin_referer($this->nonce_action, 'ispag_nonce');

        $dry_run    = !empty($_POST['dry_run']);
        $valid_from = sanitize_text_field($_POST['valid_from'] ?? date('Y-m-d'));

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $valid_from)) {
            $valid_from = date('Y-m-d');
        }

        if (empty($_FILES['csv_file']['tmp_name'])) {
            $this->redirect_with_result('error', __('No file received.', 'ispag'));
            return;
        }

        $rows = $this->parse_csv($_FILES['csv_file']['tmp_name']);

        if (empty($rows)) {
            $this->redirect_with_result('error', __('The CSV file is empty or unreadable.', 'ispag'));
            return;
        }

        $updated = 0;
        $skipped = 0;
        $errors  = 0;
        $details = [];

        foreach ($rows as $i => $row) {
            $line_num = $i + 2; // ligne 1 = en-tête

            $ref         = trim($row['ref_article_ispag'] ?? '');
            $sales_price = floatval(str_replace(',', '.', $row['sales_price'] ?? 0));
            $note        = sanitize_text_field($row['note'] ?? '');

            // ── Validations ──
            if ($ref === '') {
                $details[] = ['ok' => false, 'msg' => "Line $line_num: missing ref_article_ispag — skipped."];
                $errors++;
                continue;
            }

            if ($sales_price < 0) {
                $details[] = ['ok' => false, 'msg' => "Line $line_num ($ref) : negative price — skipped."];
                $errors++;
                continue;
            }

            // ── Recherche de l'article par référence ──
            $article = $this->find_article($ref);

            if (!$article) {
                $details[] = ['ok' => false, 'msg' => "Line $line_num ($ref) : reference not found — skipped."];
                $skipped++;
                continue;
            }

            // ── Vérification si le prix a changé ──
            $current = $this->get_current_price($article->Id);

            if ($current && (float)$current->sales_price === $sales_price) {
                $details[] = ['ok' => true, 'msg' => "Line $line_num ($ref) : prix identique ({$sales_price}), aucune modification."];
                $skipped++;
                continue;
            }

            // ── Application ──
            if (!$dry_run) {
                $ok = $this->apply_price_update($article->Id, $sales_price, $valid_from, $note);
            } else {
                $ok = true;
            }

            $ancien = $current ? $current->sales_price : '—';

            if ($ok) {
                $label     = $dry_run ? '[DRY RUN] ' : '';
                $details[] = ['ok' => true, 'msg' => "Line $line_num ($ref) : {$label}price updated {$ancien} → {$sales_price}."];
                $updated++;
            } else {
                $details[] = ['ok' => false, 'msg' => "Line $line_num ($ref) : error while updating the database."];
                $errors++;
            }
        }

        $mode = $dry_run ? ' [SIMULATION MODE — nothing was written]' : '';
        $msg  = sprintf(
            __('%d row(s) updated, %d skipped (same price or not found), %d error(s).%s', 'ispag'),
            $updated, $skipped, $errors, $mode
        );

        $this->redirect_with_result($errors > 0 ? 'warning' : 'success', $msg, $details);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PAGE D'ADMINISTRATION — IMPORT / MISE À JOUR D'ARTICLES (NOUVEAU)
    // ─────────────────────────────────────────────────────────────────────────

    public function render_articles_page(): void {
        if (!current_user_can('manage_options')) wp_die(__('Access denied'));

        $transient_key = 'ispag_article_import_result_' . get_current_user_id();
        $result        = get_transient($transient_key);
        if ($result) {
            delete_transient($transient_key);
        }
        ?>
        <div class="wrap">
            <h1><?= esc_html__('Article import / update', 'ispag') ?></h1>

            <?php if ($result): ?>
                <div class="notice notice-<?= in_array($result['type'], ['error', 'warning']) ? $result['type'] : 'success' ?> is-dismissible">
                    <p><?= wp_kses_post($result['message']) ?></p>
                    <?php if (!empty($result['details'])): ?>
                        <details>
                            <summary><?= esc_html__('View line-by-line details', 'ispag') ?></summary>
                            <ul style="max-height:300px;overflow-y:auto;margin-top:8px;">
                                <?php foreach ($result['details'] as $line): ?>
                                    <li style="color:<?= $line['ok'] ? 'green' : '#cc0000' ?>">
                                        <?= esc_html($line['msg']) ?>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </details>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <div style="max-width:720px;background:#fff;padding:24px;border:1px solid #ccd0d4;border-radius:4px;margin-top:16px;">

                <h2 style="margin-top:0"><?= esc_html__('Import an articles CSV file', 'ispag') ?></h2>

                <p><?= esc_html__('Expected columns (separator ; or ,):', 'ispag') ?></p>
                <code style="display:block;background:#f0f0f0;padding:8px;margin-bottom:8px;white-space:pre-wrap;">TypeArticle ; ref_article_ispag ; TitreArticle ; description_ispag ; conception ; sales_price (optionnelle)</code>
                <p class="description">
                    <?= esc_html__('If an article with the same ref_article_ispag already exists, it is updated. Otherwise, it is created. The "conception" column must contain valid JSON (wrap it in quotes in the CSV). The "sales_price" column is optional: if present and filled on a row, a new sales price is recorded in the history, as in the price import.', 'ispag') ?>
                </p>

                <form method="post" action="<?= esc_url(admin_url('admin-post.php')) ?>" enctype="multipart/form-data">
                    <?php wp_nonce_field($this->nonce_action_articles, 'ispag_nonce') ?>
                    <input type="hidden" name="action" value="ispag_article_import">

                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row">
                                <label for="csv_file_articles"><?= esc_html__('Fichier CSV', 'ispag') ?></label>
                            </th>
                            <td>
                                <input type="file" id="csv_file_articles" name="csv_file" accept=".csv,.txt" required>
                                <p class="description">
                                    <?= esc_html__('UTF-8 encoding recommended. Separator ; or , detected automatically.', 'ispag') ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="valid_from_articles"><?= esc_html__('Price effective date', 'ispag') ?></label>
                            </th>
                            <td>
                                <input type="date" id="valid_from_articles" name="valid_from"
                                       value="<?= esc_attr(date('Y-m-d')) ?>">
                                <p class="description">
                                    <?= esc_html__('Only used if the sales_price column is present in the file.', 'ispag') ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <?= esc_html__('Mode simulation', 'ispag') ?>
                            </th>
                            <td>
                                <label>
                                    <input type="checkbox" name="dry_run" value="1" checked>
                                    <?= esc_html__('Simulate without writing to the database (dry run)', 'ispag') ?>
                                </label>
                                <p class="description">
                                    <?= esc_html__('In simulation mode, no data is modified. Uncheck to actually apply the changes.', 'ispag') ?>
                                </p>
                            </td>
                        </tr>
                    </table>

                    <?php submit_button(__('Import articles', 'ispag')) ?>
                </form>
            </div>

            <div style="max-width:720px;margin-top:24px;">
                <h3><?= esc_html__('Example of a valid CSV file', 'ispag') ?></h3>
                <table class="widefat striped" style="font-size:13px;">
                    <thead>
                        <tr>
                            <th>TypeArticle</th>
                            <th>ref_article_ispag</th>
                            <th>TitreArticle</th>
                            <th>description_ispag</th>
                            <th>conception</th>
                            <th>sales_price</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>1</td>
                            <td>REF-ABC-001</td>
                            <td>Réservoir 500L</td>
                            <td>Réservoir isolé...</td>
                            <td>{"welding":{"nb_welding":1}}</td>
                            <td>1250.00</td>
                        </tr>
                        <tr>
                            <td>2</td>
                            <td>REF-ABC-002</td>
                            <td>Vanne DN50</td>
                            <td>Vanne à bille...</td>
                            <td></td>
                            <td></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
        <?php
    }

    public function handle_article_import(): void {
        if (!current_user_can('manage_options')) wp_die(__('Access denied'));
        check_admin_referer($this->nonce_action_articles, 'ispag_nonce');

        $dry_run    = !empty($_POST['dry_run']);
        $valid_from = sanitize_text_field($_POST['valid_from'] ?? date('Y-m-d'));

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $valid_from)) {
            $valid_from = date('Y-m-d');
        }

        if (empty($_FILES['csv_file']['tmp_name'])) {
            $this->redirect_with_result_articles('error', __('No file received.', 'ispag'));
            return;
        }

        $rows = $this->parse_csv($_FILES['csv_file']['tmp_name']);

        if (empty($rows)) {
            $this->redirect_with_result_articles('error', __('The CSV file is empty or unreadable.', 'ispag'));
            return;
        }

        $created       = 0;
        $updated       = 0;
        $price_updated = 0;
        $errors        = 0;
        $details       = [];

        // La colonne sales_price est optionnelle : on regarde si elle est présente dans l'en-tête.
        $has_sales_price_column = array_key_exists('sales_price', $rows[0]);

        foreach ($rows as $i => $row) {
            $line_num = $i + 2; // ligne 1 = en-tête

            $ref = trim($row['ref_article_ispag'] ?? '');

            if ($ref === '') {
                $details[] = ['ok' => false, 'msg' => "Line $line_num: missing ref_article_ispag — skipped."];
                $errors++;
                continue;
            }

            $type_article      = intval($row['TypeArticle'] ?? 0);
            $titre_article     = sanitize_text_field($row['TitreArticle'] ?? '');
            $description_ispag = wp_kses_post($row['description_ispag'] ?? '');
            $conception_raw    = trim($row['conception'] ?? '');

            $conception_json = null;
            if ($conception_raw !== '') {
                $decoded = json_decode($conception_raw, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    $details[] = ['ok' => false, 'msg' => "Line $line_num ($ref) : invalid JSON in the conception column — line skipped (" . json_last_error_msg() . ")."];
                    $errors++;
                    continue;
                }
                // Ré-encodage pour garantir un JSON propre et normalisé en base.
                $conception_json = wp_json_encode($decoded, JSON_UNESCAPED_UNICODE);
            }

            $data = [
                'TypeArticle'       => $type_article,
                'ref_article_ispag' => $ref,
                'TitreArticle'      => $titre_article,
                'description_ispag' => $description_ispag,
            ];

            // On ne touche pas à "conception" si la colonne est vide sur cette ligne
            // (évite d'écraser une conception existante avec du vide par erreur).
            if ($conception_json !== null) {
                $data['conception'] = $conception_json;
            }

            $existing_id  = $this->find_article_id($ref);
            $action_label = $existing_id ? 'updated' : 'created';
            $article_id   = $existing_id ?: 0;

            if (!$dry_run) {
                if ($existing_id) {
                    $ok = ($this->wpdb_update_article($existing_id, $data) !== false);
                } else {
                    $article_id = $this->wpdb_insert_article($data);
                    $ok         = (bool) $article_id;
                }
            } else {
                $ok = true;
            }

            if (!$ok) {
                $details[] = ['ok' => false, 'msg' => "Line $line_num ($ref) : erreur lors de l'écriture en base."];
                $errors++;
                continue;
            }

            $label     = $dry_run ? '[DRY RUN] ' : '';
            $details[] = ['ok' => true, 'msg' => "Line $line_num ($ref) : {$label}article {$action_label} (TypeArticle={$type_article})."];

            if ($existing_id) {
                $updated++;
            } else {
                $created++;
            }

            // ── Prix de vente optionnel : même logique que l'import de tarifs ──
            if ($has_sales_price_column && array_key_exists('sales_price', $row) && trim((string)$row['sales_price']) !== '') {
                $sales_price = floatval(str_replace(',', '.', $row['sales_price']));

                if ($sales_price < 0) {
                    $details[] = ['ok' => false, 'msg' => "Line $line_num ($ref) : negative sales price — price skipped."];
                    $errors++;
                } else {
                    $current = $article_id ? $this->get_current_price($article_id) : null;

                    if ($current && (float)$current->sales_price === $sales_price) {
                        $details[] = ['ok' => true, 'msg' => "Line $line_num ($ref) : same sales price ({$sales_price}), unchanged."];
                    } else {
                        if (!$dry_run && $article_id) {
                            $ok_price = $this->apply_price_update($article_id, $sales_price, $valid_from, 'Import CSV articles');
                        } else {
                            $ok_price = true;
                        }

                        if ($ok_price) {
                            $ancien    = $current ? $current->sales_price : '—';
                            $details[] = ['ok' => true, 'msg' => "Line $line_num ($ref) : {$label}sales price updated {$ancien} → {$sales_price}."];
                            $price_updated++;
                        } else {
                            $details[] = ['ok' => false, 'msg' => "Line $line_num ($ref) : error while updating the sales price."];
                            $errors++;
                        }
                    }
                }
            }
        }

        $mode = $dry_run ? ' [SIMULATION MODE — nothing was written]' : '';
        $msg  = sprintf(
            __('%d article(s) created, %d updated, %d sales price(s) updated, %d error(s).%s', 'ispag'),
            $created, $updated, $price_updated, $errors, $mode
        );

        $this->redirect_with_result_articles($errors > 0 ? 'warning' : 'success', $msg, $details);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // HELPERS PRIVÉS — ARTICLES (nouveau)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Cherche l'Id d'un article par sa référence ISPAG.
     */
    private function find_article_id(string $ref): ?int {
        global $wpdb;
        $id = $wpdb->get_var($wpdb->prepare(
            "SELECT Id FROM {$this->table_articles} WHERE ref_article_ispag = %s LIMIT 1",
            $ref
        ));
        return $id ? (int) $id : null;
    }

    private function wpdb_update_article(int $article_id, array $data) {
        global $wpdb;
        return $wpdb->update($this->table_articles, $data, ['Id' => $article_id]);
    }

    private function wpdb_insert_article(array $data): int {
        global $wpdb;
        $ok = $wpdb->insert($this->table_articles, $data);
        return $ok ? (int) $wpdb->insert_id : 0;
    }

    private function redirect_with_result_articles(string $type, string $message, array $details = []): void {
        set_transient(
            'ispag_article_import_result_' . get_current_user_id(),
            ['type' => $type, 'message' => $message, 'details' => $details],
            60
        );
        wp_safe_redirect(admin_url('admin.php?page=ispag_article_import'));
        exit;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // HELPERS PRIVÉS — COMMUNS (existant, inchangé)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Parse le CSV — auto-détection du séparateur ; ou ,
     * Retourne un tableau associatif (en-tête = première ligne).
     */
    private function parse_csv(string $filepath): array {
        $handle = fopen($filepath, 'r');
        if (!$handle) return [];

        $first_line = fgets($handle);
        rewind($handle);
        $separator = substr_count($first_line, ';') >= substr_count($first_line, ',') ? ';' : ',';

        $rows    = [];
        $headers = [];
        $i       = 0;

        while (($data = fgetcsv($handle, 0, $separator)) !== false) {
            if ($i === 0) {
                // Nettoyage du BOM UTF-8 éventuel
                $data[0] = ltrim($data[0], "\xEF\xBB\xBF");
                $headers = array_map('trim', $data);
                $i++;
                continue;
            }

            // On tolère les lignes avec moins de colonnes (colonnes optionnelles)
            if (count($data) < count($headers)) {
                $data = array_pad($data, count($headers), '');
            }

            if (count($data) !== count($headers)) continue;

            $rows[] = array_combine($headers, array_map('trim', $data));
            $i++;
        }

        fclose($handle);
        return $rows;
    }

    /**
     * Cherche un article de vente par sa référence ISPAG.
     */
    private function find_article(string $ref): ?object {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT Id, sales_price
             FROM {$this->table_articles}
             WHERE ref_article_ispag = %s
             LIMIT 1",
            $ref
        ));
    }

    /**
     * Retourne le prix de vente actuellement actif (valid_to IS NULL).
     */
    private function get_current_price(int $article_id): ?object {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT sales_price
             FROM {$this->table_history}
             WHERE article_id = %d AND valid_to IS NULL
             LIMIT 1",
            $article_id
        ));
    }

    /**
     * Archive l'ancien prix de vente et insère le nouveau.
     * Met à jour la table principale pour rétrocompatibilité.
     */
    private function apply_price_update(
        int    $article_id,
        float  $sales_price,
        string $valid_from,
        string $note
    ): bool {
        global $wpdb;

        $yesterday = date('Y-m-d', strtotime($valid_from . ' -1 day'));

        // 1. Clôture du prix actif
        $wpdb->query($wpdb->prepare(
            "UPDATE {$this->table_history}
             SET valid_to = %s
             WHERE article_id = %d AND valid_to IS NULL",
            $yesterday, $article_id
        ));

        // 2. Nouveau prix actif dans l'historique
        $result = $wpdb->insert(
            $this->table_history,
            [
                'article_id'   => $article_id,
                'sales_price'  => $sales_price,
                'valid_from'   => $valid_from,
                'valid_to'     => null,
                'changed_by'   => get_current_user_id(),
                'note'         => $note ?: 'Import CSV',
                'created_at'   => current_time('mysql'),
            ],
            ['%d', '%f', '%s', null, '%d', '%s', '%s']
        );

        if (!$result) return false;

        // 3. Synchronisation de la table principale
        $wpdb->update(
            $this->table_articles,
            ['sales_price' => $sales_price],
            ['Id'          => $article_id],
            ['%f'],
            ['%d']
        );

        return true;
    }

    /**
     * Stocke le résultat dans un transient et redirige.
     */
    private function redirect_with_result(string $type, string $message, array $details = []): void {
        set_transient(
            'ispag_sales_price_import_result_' . get_current_user_id(),
            ['type' => $type, 'message' => $message, 'details' => $details],
            60
        );
        wp_safe_redirect(admin_url('admin.php?page=ispag_sales_price_import'));
        exit;
    }
}