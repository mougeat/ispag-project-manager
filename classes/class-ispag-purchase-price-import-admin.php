<?php
defined('ABSPATH') or die();

/**
 * ISPAG_Purchase_Price_Import_Admin
 *
 * Page d'administration : import CSV des tarifs fournisseurs, lié à la référence ISPAG de l'article.
 * Un article (ref_article_ispag) peut avoir plusieurs fournisseurs : la clé d'une ligne d'achat est le couple
 * article + fournisseur.
 *
 * Colonnes du CSV (séparateur ; ou , détecté ; en-tête obligatoire) :
 *   ref_article_ispag ; supplier_name ; supplier_reference ; supplier_description ; purchase_price ; discount ; currency ; note
 *
 * Pour chaque ligne :
 *   - l'article est retrouvé par ref_article_ispag (achats_articles) ; absent => erreur, ou création si l'option est cochée ;
 *   - le fournisseur est retrouvé par son nom dans ispag_companies (sans casse ni accents) ; absent => erreur,
 *     ou création si l'option est cochée ; une entreprise trouvée mais pas encore fournisseur est marquée fournisseur ;
 *   - la ligne d'achat (achats_articles_purchase) existe pour ce couple => mise à jour de la référence / description
 *     fournisseur, et du prix dans achats_articles_purchase_price_history s'il a changé (prix, remise ou devise) ;
 *   - sinon => création de la ligne d'achat et de son premier prix.
 * Un fichier peut être simulé (dry run) avant l'écriture réelle.
 */
class ISPAG_Purchase_Price_Import_Admin {

    private string $nonce_action = 'ispag_purchase_price_import';
    protected static ?self $instance = null;

    /** Colonnes reconnues (en-têtes en minuscules). */
    const COLUMNS = ['ref_article_ispag', 'supplier_name', 'supplier_reference', 'supplier_description', 'purchase_price', 'discount', 'currency', 'note'];

    public static function init(): void {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        add_action('admin_menu', [self::$instance, 'register_menu']);
        add_action('admin_post_ispag_purchase_price_import', [self::$instance, 'handle_import']);
        add_action('admin_post_ispag_purchase_price_template', [self::$instance, 'handle_template']);
        add_action('admin_post_ispag_purchase_price_export', [self::$instance, 'handle_export']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // MENU + PAGE
    // ─────────────────────────────────────────────────────────────────────────

    public function register_menu(): void {
        add_submenu_page(
            'ispag-entreprises',
            __('Supplier price import', 'ispag'),
            __('Supplier price import', 'ispag'),
            'manage_options',
            'ispag_purchase_price_import',
            [self::$instance, 'render_page']
        );
    }

    public function render_page(): void {
        if (!current_user_can('manage_options')) wp_die(__('Access denied'));

        $result = get_transient('ispag_purchase_price_import_result_' . get_current_user_id());
        if ($result) {
            delete_transient('ispag_purchase_price_import_result_' . get_current_user_id());
        }

        $suppliers = ISPAG_Standard_Article_Service::suppliers();
        $types     = ISPAG_Standard_Article_Service::types();
        $tpl_url   = wp_nonce_url(admin_url('admin-post.php?action=ispag_purchase_price_template'), $this->nonce_action);
        $exp_url   = wp_nonce_url(admin_url('admin-post.php?action=ispag_purchase_price_export'), $this->nonce_action);
        ?>
        <div class="wrap">
            <h1><?= esc_html__('Supplier price import', 'ispag') ?></h1>

            <?php if ($result): ?>
                <div class="notice notice-<?= $result['type'] === 'error' ? 'error' : ($result['type'] === 'warning' ? 'warning' : 'success') ?> is-dismissible">
                    <p><?= wp_kses_post($result['message']) ?></p>
                    <?php if (!empty($result['details'])): ?>
                        <details open>
                            <summary><?= esc_html__('View line-by-line details', 'ispag') ?></summary>
                            <ul style="max-height:360px;overflow-y:auto;margin-top:8px;">
                                <?php foreach ($result['details'] as $line): ?>
                                    <li style="color:<?= $line['ok'] ? 'green' : 'red' ?>"><?= esc_html($line['msg']) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </details>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <div style="max-width:760px;background:#fff;padding:24px;border:1px solid #ccd0d4;border-radius:4px;margin-top:16px;">
                <h2 style="margin-top:0"><?= esc_html__('Import a CSV file', 'ispag') ?></h2>

                <p><?= esc_html__('Expected columns (separator ; or ,), header row required:', 'ispag') ?></p>
                <code style="display:block;background:#f0f0f0;padding:8px;margin-bottom:8px;overflow-x:auto;">
                    <?= esc_html(implode(' ; ', self::COLUMNS)) ?>
                </code>
                <p class="description" style="margin-bottom:16px;">
                    <?= esc_html__('An article (ref_article_ispag) can have several suppliers: one line per article and supplier. Existing lines are updated (price history included), missing ones are created.', 'ispag') ?><br>
                    <a href="<?= esc_url($tpl_url) ?>"><?= esc_html__('Download a template file', 'ispag') ?></a> ·
                    <a href="<?= esc_url($exp_url) ?>"><?= esc_html__('Export the current prices (same format, to edit and re-import)', 'ispag') ?></a>
                </p>

                <form method="post" action="<?= esc_url(admin_url('admin-post.php')) ?>" enctype="multipart/form-data">
                    <?php wp_nonce_field($this->nonce_action, 'ispag_nonce') ?>
                    <input type="hidden" name="action" value="ispag_purchase_price_import">

                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row"><label for="csv_file"><?= esc_html__('CSV file', 'ispag') ?></label></th>
                            <td>
                                <input type="file" id="csv_file" name="csv_file" accept=".csv,.txt" required>
                                <p class="description"><?= esc_html__('UTF-8 encoding recommended. Separator ; or , detected automatically.', 'ispag') ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="valid_from"><?= esc_html__('Effective date', 'ispag') ?></label></th>
                            <td>
                                <input type="date" id="valid_from" name="valid_from" value="<?= esc_attr(date('Y-m-d')) ?>" required>
                                <p class="description"><?= esc_html__('Date from which the new prices apply (the previous price is closed the day before).', 'ispag') ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="default_supplier"><?= esc_html__('Default supplier', 'ispag') ?></label></th>
                            <td>
                                <select id="default_supplier" name="default_supplier">
                                    <option value=""><?= esc_html__('— none —', 'ispag') ?></option>
                                    <?php foreach ($suppliers as $s): ?>
                                        <option value="<?= esc_attr($s->Id) ?>"><?= esc_html($s->company_name) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="description"><?= esc_html__('Only used for lines whose supplier_name is empty.', 'ispag') ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?= esc_html__('Missing data', 'ispag') ?></th>
                            <td>
                                <label style="display:block;margin-bottom:6px;">
                                    <input type="checkbox" name="create_suppliers" value="1">
                                    <?= esc_html__('Create suppliers that do not exist yet (as companies flagged supplier)', 'ispag') ?>
                                </label>
                                <label style="display:block;">
                                    <input type="checkbox" name="create_articles" value="1" id="ispag-create-articles">
                                    <?= esc_html__('Create articles whose ref_article_ispag does not exist yet, with the type:', 'ispag') ?>
                                </label>
                                <select name="new_article_type" style="margin-left:24px;margin-top:4px;">
                                    <?php foreach ($types as $t): ?>
                                        <option value="<?= esc_attr($t->Id) ?>"><?= esc_html($t->type) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="description"><?= esc_html__('Without these options, unknown suppliers and articles are reported as errors and the line is skipped (protects against typos).', 'ispag') ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?= esc_html__('Simulation mode', 'ispag') ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="dry_run" value="1" checked>
                                    <?= esc_html__('Simulate without writing to the database (dry run)', 'ispag') ?>
                                </label>
                            </td>
                        </tr>
                    </table>

                    <?php submit_button(__('Import the CSV', 'ispag')) ?>
                </form>
            </div>

            <div style="max-width:760px;margin-top:24px;">
                <h3><?= esc_html__('Example of a valid CSV file', 'ispag') ?></h3>
                <table class="widefat striped" style="font-size:13px;">
                    <thead><tr><?php foreach (self::COLUMNS as $c): ?><th><?= esc_html($c) ?></th><?php endforeach; ?></tr></thead>
                    <tbody>
                        <?php foreach (self::example_rows() as $r): ?>
                            <tr><?php foreach ($r as $v): ?><td><?= esc_html($v) ?></td><?php endforeach; ?></tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php
    }

    private static function example_rows(): array {
        return [
            ['CB30-10H', 'Wolf GmbH', 'W-100-A', 'Bride DN100', '125.50', '25', 'CHF', 'Tarif 2026'],
            ['CB30-10H', 'Rudert Edelstahl', 'R-8841', 'Bride DN100 inox', '118.00', '0', 'EUR', ''],
            ['TH20402', 'Wolf GmbH', 'W-402', 'Thermomètre', '89.00', '10', 'CHF', ''],
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // MODÈLE / EXPORT
    // ─────────────────────────────────────────────────────────────────────────

    private function send_csv(string $filename, array $rows): void {
        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        foreach ($rows as $row) {
            fputcsv($out, $row, ';', '"', '\\');
        }
        fclose($out);
        exit;
    }

    public function handle_template(): void {
        if (!current_user_can('manage_options')) wp_die(__('Access denied'));
        check_admin_referer($this->nonce_action);
        $this->send_csv('supplier-prices-template.csv', array_merge([self::COLUMNS], self::example_rows()));
    }

    /** Prix actuels dans le même format que l'import (pratique pour corriger puis réimporter). */
    public function handle_export(): void {
        if (!current_user_can('manage_options')) wp_die(__('Access denied'));
        check_admin_referer($this->nonce_action);
        global $wpdb;
        $lines = $wpdb->get_results(
            "SELECT a.ref_article_ispag, c.company_name, p.supplier_reference, p.supplier_description, p.purchase_price, p.discount, p.currency
             FROM {$wpdb->prefix}achats_articles_purchase p
             INNER JOIN {$wpdb->prefix}achats_articles a ON a.Id = p.article_id
             LEFT JOIN {$wpdb->prefix}ispag_companies c ON c.Id = p.supplier_id
             ORDER BY a.ref_article_ispag ASC, c.company_name ASC"
        );
        $rows = [self::COLUMNS];
        foreach ((array) $lines as $l) {
            $rows[] = [$l->ref_article_ispag, $l->company_name, $l->supplier_reference, preg_replace('/\s+/', ' ', (string) $l->supplier_description),
                       number_format((float) $l->purchase_price, 2, '.', ''), rtrim(rtrim(number_format((float) $l->discount, 2, '.', ''), '0'), '.') ?: '0', $l->currency, ''];
        }
        // Neutralise l'injection de formules à l'ouverture dans Excel
        foreach ($rows as $i => $row) {
            if ($i === 0) continue;
            $rows[$i] = array_map(function ($v) {
                return is_string($v) && $v !== '' && strpbrk($v[0], "=+-@\t\r") !== false ? "'" . $v : $v;
            }, $row);
        }
        $this->send_csv('supplier-prices-' . date('Y-m-d') . '.csv', $rows);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // TRAITEMENT DU CSV
    // ─────────────────────────────────────────────────────────────────────────

    public function handle_import(): void {
        if (!current_user_can('manage_options')) wp_die(__('Access denied'));
        check_admin_referer($this->nonce_action, 'ispag_nonce');

        $valid_from = sanitize_text_field($_POST['valid_from'] ?? date('Y-m-d'));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $valid_from)) {
            $valid_from = date('Y-m-d');
        }
        $options = [
            'dry_run'          => !empty($_POST['dry_run']),
            'valid_from'       => $valid_from,
            'default_supplier' => absint($_POST['default_supplier'] ?? 0),
            'create_suppliers' => !empty($_POST['create_suppliers']),
            'create_articles'  => !empty($_POST['create_articles']),
            'new_article_type' => absint($_POST['new_article_type'] ?? 0),
        ];

        if (empty($_FILES['csv_file']['tmp_name'])) {
            $this->redirect_with_result('error', __('No file received.', 'ispag'));
            return;
        }
        $parsed = $this->parse_csv($_FILES['csv_file']['tmp_name']);
        if (!$parsed['rows']) {
            $this->redirect_with_result('error', $parsed['error'] ?: __('The CSV file is empty or unreadable.', 'ispag'));
            return;
        }
        if ($parsed['error']) {
            $this->redirect_with_result('error', $parsed['error']);
            return;
        }

        $r = $this->process_rows($parsed['rows'], $options);

        $mode = $options['dry_run'] ? ' ' . __('[SIMULATION MODE — nothing was written]', 'ispag') : '';
        $msg  = sprintf(
            __('%1$d price line(s) created, %2$d updated, %3$d unchanged, %4$d skipped, %5$d error(s).%6$s', 'ispag'),
            $r['created'], $r['updated'], $r['unchanged'], $r['skipped'], $r['errors'], $mode
        );
        $this->redirect_with_result($r['errors'] > 0 ? 'warning' : 'success', $msg, $r['details']);
    }

    /**
     * Applique (ou simule) les lignes du CSV.
     * @param array $rows lignes associatives (clés = COLUMNS)
     * @param array $o    dry_run, valid_from, default_supplier, create_suppliers, create_articles, new_article_type
     * @return array created, updated, unchanged, skipped, errors, details
     */
    public function process_rows(array $rows, array $o): array {
        global $wpdb;
        $out = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'skipped' => 0, 'errors' => 0, 'details' => []];
        $dry = !empty($o['dry_run']);
        $tag = $dry ? '[DRY RUN] ' : '';
        $seen = [];
        $suppliers_by_name = $this->supplier_index();
        $fake_id = -1; // identifiants provisoires en simulation
        $sim_articles = [];

        $log = function (bool $ok, string $msg) use (&$out) {
            if (count($out['details']) < 3000) $out['details'][] = ['ok' => $ok, 'msg' => $msg];
        };

        foreach ($rows as $i => $row) {
            $n   = $i + 2; // ligne 1 = en-tête
            $ref = trim((string) ($row['ref_article_ispag'] ?? ''));
            $sname = trim((string) ($row['supplier_name'] ?? ''));
            $label = "Line $n ($ref" . ($sname !== '' ? ' / ' . $sname : '') . ')';

            if ($ref === '') { $log(false, "Line $n: ref_article_ispag is empty — skipped."); $out['errors']++; continue; }

            $price_raw = trim((string) ($row['purchase_price'] ?? ''));
            $price     = (float) str_replace(["'", ' ', ','], ['', '', '.'], $price_raw);
            $discount  = (float) str_replace(',', '.', (string) ($row['discount'] ?? 0));
            $currency  = strtoupper(trim((string) ($row['currency'] ?? ''))) ?: 'CHF';
            $note      = sanitize_text_field((string) ($row['note'] ?? ''));
            $sref      = sanitize_text_field((string) ($row['supplier_reference'] ?? ''));
            $sdesc     = sanitize_textarea_field((string) ($row['supplier_description'] ?? ''));

            if ($price_raw === '' || $price < 0) { $log(false, "$label: missing or negative purchase_price — skipped."); $out['errors']++; continue; }
            if ($discount < 0 || $discount > 100) { $log(false, "$label: discount must be between 0 and 100 — skipped."); $out['errors']++; continue; }

            // ── Article ──
            $found = ISPAG_Standard_Article_Service::find_by_ref($ref);
            if (count($found) > 1) {
                $log(false, "$label: " . count($found) . " articles share this ref_article_ispag (ids " . implode(', ', wp_list_pluck($found, 'Id')) . ") — ambiguous, skipped.");
                $out['errors']++; continue;
            }
            $article_id = 0;
            $article_note = '';
            $ref_key = mb_strtolower($ref, 'UTF-8');
            if ($found) {
                $article_id = (int) $found[0]->Id;
            } elseif (isset($sim_articles[$ref_key])) {
                $article_id = $sim_articles[$ref_key]; // article créé plus haut dans ce fichier
            } elseif (!empty($o['create_articles']) && !empty($o['new_article_type'])) {
                $article_id = $dry ? $fake_id-- : ISPAG_Standard_Article_Service::create($sdesc !== '' ? mb_substr($sdesc, 0, 200) : $ref, (int) $o['new_article_type'], $ref);
                if (!$article_id) { $log(false, "$label: could not create the article — skipped."); $out['errors']++; continue; }
                $sim_articles[$ref_key] = $article_id; // mémorisé (simulation ou non) : les lignes suivantes de la même référence réutilisent cet article
                $article_note = ' (new article created)';
            } else {
                $log(false, "$label: article not found (ref_article_ispag) — skipped.");
                $out['skipped']++; continue;
            }

            // ── Fournisseur ──
            $supplier_id = 0;
            $supplier_note = '';
            if ($sname !== '') {
                $key = $this->norm($sname);
                $ids = $suppliers_by_name[$key] ?? [];
                if ($ids) {
                    $supplier_id = (int) $ids[0]['id'];
                    if (count($ids) > 1) $supplier_note = ' (several companies with this name, first used)';
                    if (!$ids[0]['is_supplier']) {
                        if (!$dry) $wpdb->update($wpdb->prefix . 'ispag_companies', ['isSupplier' => 1], ['Id' => $supplier_id], ['%d'], ['%d']);
                        $suppliers_by_name[$key][0]['is_supplier'] = 1;
                        $supplier_note .= ' (company flagged as supplier)';
                    }
                } elseif (!empty($o['create_suppliers'])) {
                    if ($dry) { $supplier_id = $fake_id--; }
                    else {
                        $wpdb->insert($wpdb->prefix . 'ispag_companies', [
                            'company_name' => $sname, 'isSupplier' => 1, 'isIngenieur' => 0, 'is_active' => 1, 'created_at' => current_time('mysql'),
                        ]);
                        $supplier_id = (int) $wpdb->insert_id;
                    }
                    if (!$supplier_id) { $log(false, "$label: could not create the supplier — skipped."); $out['errors']++; continue; }
                    $suppliers_by_name[$key] = [['id' => $supplier_id, 'is_supplier' => 1]];
                    $supplier_note = ' (new supplier created)';
                } else {
                    $log(false, "$label: supplier \"$sname\" not found — skipped.");
                    $out['skipped']++; continue;
                }
            } elseif (!empty($o['default_supplier'])) {
                $supplier_id = (int) $o['default_supplier'];
            } else {
                $log(false, "$label: supplier_name is empty and no default supplier — skipped."); $out['errors']++; continue;
            }

            // Doublon dans le fichier : la dernière ligne gagnerait silencieusement, on prévient et on saute
            $pair = $article_id . ':' . $supplier_id;
            if (isset($seen[$pair])) { $log(false, "$label: duplicate of line {$seen[$pair]} (same article and supplier) — skipped."); $out['skipped']++; continue; }
            $seen[$pair] = $n;

            // ── Ligne d'achat ──
            $purchase = ($article_id > 0 && $supplier_id > 0) ? $this->find_purchase($article_id, $supplier_id) : null;

            if (!$purchase) {
                if (!$dry) {
                    $pid = ISPAG_Standard_Article_Service::add_purchase($article_id, $supplier_id, $price, $discount, $currency, $sref, $sdesc, 0, $o['valid_from'], $note ?: 'Import CSV');
                    if (!$pid) { $log(false, "$label: error while creating the price line."); $out['errors']++; continue; }
                }
                $log(true, "$label: {$tag}price line created → $price $currency (discount {$discount}%)." . $article_note . $supplier_note);
                $out['created']++;
                continue;
            }

            // Mise à jour de la référence / description fournisseur (uniquement si fournies et différentes)
            $changes = [];
            if ($sref !== '' && $sref !== (string) $purchase->supplier_reference) $changes['supplier_reference'] = $sref;
            if ($sdesc !== '' && $sdesc !== (string) $purchase->supplier_description) $changes['supplier_description'] = $sdesc;
            if ($changes && !$dry) {
                $wpdb->update($wpdb->prefix . 'achats_articles_purchase', $changes, ['Id' => (int) $purchase->Id]);
            }

            $current = $this->get_current_price((int) $purchase->Id);
            $same_price = $current
                && abs((float) $current->purchase_price - $price) < 0.005
                && abs((float) $current->discount - $discount) < 0.005
                && strtoupper((string) $current->currency) === $currency;

            if (!$same_price) {
                $ok = $dry ? true : ISPAG_Standard_Article_Service::apply_purchase_price((int) $purchase->Id, $price, $discount, $currency, $o['valid_from'], $note ?: 'Import CSV');
                if (!$ok) { $log(false, "$label: error while updating the price."); $out['errors']++; continue; }
                $log(true, "$label: {$tag}price updated → $price $currency (discount {$discount}%)" . ($changes ? ', supplier info updated' : '') . '.' . $supplier_note);
                $out['updated']++;
            } elseif ($changes) {
                $log(true, "$label: {$tag}same price, supplier reference/description updated.");
                $out['updated']++;
            } else {
                $log(true, "$label: same price, nothing to change.");
                $out['unchanged']++;
            }
        }
        return $out;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // HELPERS PRIVÉS
    // ─────────────────────────────────────────────────────────────────────────

    /** Nom normalisé pour la comparaison : sans casse, sans accents, espaces réduits. */
    private function norm(string $s): string {
        return trim(preg_replace('/\s+/u', ' ', mb_strtolower(remove_accents($s), 'UTF-8')));
    }

    /** Toutes les entreprises indexées par nom normalisé : [nom => [['id'=>, 'is_supplier'=>], …]] (plus ancien Id d'abord). */
    private function supplier_index(): array {
        global $wpdb;
        $map = [];
        foreach ((array) $wpdb->get_results("SELECT Id, company_name, isSupplier FROM {$wpdb->prefix}ispag_companies WHERE company_name IS NOT NULL AND company_name <> '' ORDER BY Id ASC") as $c) {
            $map[$this->norm((string) $c->company_name)][] = ['id' => (int) $c->Id, 'is_supplier' => (int) $c->isSupplier];
        }
        return $map;
    }

    /**
     * Parse le CSV (séparateur ; ou , détecté). Retourne ['rows' => [...], 'error' => string].
     * Les en-têtes sont normalisés (minuscules) ; les colonnes obligatoires sont contrôlées.
     */
    private function parse_csv(string $filepath): array {
        $handle = fopen($filepath, 'r');
        if (!$handle) return ['rows' => [], 'error' => ''];

        $first = fgets($handle);
        rewind($handle);
        $sep = substr_count((string) $first, ';') >= substr_count((string) $first, ',') ? ';' : ',';

        $rows = [];
        $headers = [];
        $line = 0;
        while (($data = fgetcsv($handle, 0, $sep, '"', '\\')) !== false) {
            $line++;
            if ($data === [null]) continue; // ligne vide
            $data = array_map(function ($f) {
                $f = (string) $f;
                return mb_check_encoding($f, 'UTF-8') ? $f : (string) @iconv('Windows-1252', 'UTF-8//IGNORE', $f);
            }, $data);

            if (!$headers) {
                $data[0] = ltrim($data[0], "\xEF\xBB\xBF");
                $headers = array_map(function ($h) { return strtolower(trim($h)); }, $data);
                $missing = array_diff(['ref_article_ispag', 'purchase_price'], $headers);
                if ($missing) {
                    fclose($handle);
                    return ['rows' => [], 'error' => sprintf(__('Missing column(s) in the header row: %s', 'ispag'), implode(', ', $missing))];
                }
                continue;
            }
            $row = [];
            foreach ($headers as $idx => $h) {
                if (in_array($h, self::COLUMNS, true)) $row[$h] = trim($data[$idx] ?? '');
            }
            $rows[] = $row;
        }
        fclose($handle);
        return ['rows' => $rows, 'error' => ''];
    }

    private function find_purchase(int $article_id, int $supplier_id): ?object {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT Id, supplier_reference, supplier_description, purchase_price, discount, currency
             FROM {$wpdb->prefix}achats_articles_purchase
             WHERE article_id = %d AND supplier_id = %d
             LIMIT 1",
            $article_id, $supplier_id
        ));
    }

    /** Prix actuellement actif dans l'historique (valid_to IS NULL) ; à défaut, la ligne d'achat elle-même est comparée. */
    private function get_current_price(int $purchase_id): ?object {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT purchase_price, discount, currency
             FROM {$wpdb->prefix}achats_articles_purchase_price_history
             WHERE purchase_id = %d AND valid_to IS NULL
             ORDER BY valid_from DESC LIMIT 1",
            $purchase_id
        ));
    }

    private function redirect_with_result(string $type, string $message, array $details = []): void {
        set_transient(
            'ispag_purchase_price_import_result_' . get_current_user_id(),
            ['type' => $type, 'message' => $message, 'details' => $details],
            300
        );
        wp_safe_redirect(admin_url('admin.php?page=ispag_purchase_price_import'));
        exit;
    }
}
