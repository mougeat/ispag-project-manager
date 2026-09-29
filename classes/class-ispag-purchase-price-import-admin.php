<?php
defined('ABSPATH') or die();

/**
 * ISPAG_Purchase_Price_Import_Admin
 *
 * Page d'administration pour importer un fichier CSV de tarifs fournisseur.
 *
 * Format CSV attendu (séparateur ; ou ,) :
 *   supplier_id ; supplier_reference ; purchase_price ; discount ; currency ; note
 *
 * Exemple :
 *   12 ; REF-ABC-001 ; 125.50 ; 25 ; CHF ; Tarif 2026
 *   12 ; REF-ABC-002 ; 89.00  ; 0  ; CHF ;
 */
class ISPAG_Purchase_Price_Import_Admin {

    private string $table_purchase;
    private string $table_history;
    private string $nonce_action = 'ispag_purchase_price_import';
    protected static ?self $instance = null;

    public function __construct() {
        global $wpdb;
        $this->table_purchase = $wpdb->prefix . 'achats_articles_purchase';
        $this->table_history  = $wpdb->prefix . 'achats_articles_purchase_price_history';
    }

    public static function init(): void {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        add_action('admin_menu', [self::$instance, 'register_menu']);
        add_action('admin_post_ispag_purchase_price_import', [self::$instance, 'handle_import']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // MENU
    // ─────────────────────────────────────────────────────────────────────────

    public function register_menu(): void {
        add_submenu_page(
            'ispag-entreprises',
            __('Import tarifs fournisseur', 'ispag'),
            __('Import tarifs fournisseur', 'ispag'),
            'manage_options',
            'ispag_purchase_price_import',
            [self::$instance, 'render_page']
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PAGE D'ADMINISTRATION
    // ─────────────────────────────────────────────────────────────────────────

    public function render_page(): void {
        if (!current_user_can('manage_options')) wp_die(__('Accès refusé'));

        $result = get_transient('ispag_purchase_price_import_result_' . get_current_user_id());
        if ($result) {
            delete_transient('ispag_purchase_price_import_result_' . get_current_user_id());
        }

        // Chargement des fournisseurs pour le select
        global $wpdb;
        $suppliers = $wpdb->get_results(
            "SELECT Id, NomFournisseur FROM {$wpdb->prefix}achats_fournisseurs ORDER BY NomFournisseur ASC"
        );
        ?>
        <div class="wrap">
            <h1><?= esc_html__('Import tarifs fournisseur', 'ispag') ?></h1>

            <?php if ($result): ?>
                <div class="notice notice-<?= $result['type'] === 'error' ? 'error' : 'success' ?> is-dismissible">
                    <p><?= wp_kses_post($result['message']) ?></p>
                    <?php if (!empty($result['details'])): ?>
                        <details>
                            <summary><?= esc_html__('Voir le détail ligne par ligne', 'ispag') ?></summary>
                            <ul style="max-height:300px;overflow-y:auto;margin-top:8px;">
                                <?php foreach ($result['details'] as $line): ?>
                                    <li style="color:<?= $line['ok'] ? 'green' : 'red' ?>">
                                        <?= esc_html($line['msg']) ?>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </details>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <div style="max-width:640px;background:#fff;padding:24px;border:1px solid #ccd0d4;border-radius:4px;margin-top:16px;">

                <h2 style="margin-top:0"><?= esc_html__('Importer un fichier CSV', 'ispag') ?></h2>

                <p><?= esc_html__('Format attendu (séparateur ; ou ,) :', 'ispag') ?></p>
                <code style="display:block;background:#f0f0f0;padding:8px;margin-bottom:16px;">
                    supplier_id ; supplier_reference ; purchase_price ; discount ; currency ; note
                </code>

                <form method="post" action="<?= esc_url(admin_url('admin-post.php')) ?>" enctype="multipart/form-data">
                    <?php wp_nonce_field($this->nonce_action, 'ispag_nonce') ?>
                    <input type="hidden" name="action" value="ispag_purchase_price_import">

                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row">
                                <label for="csv_file"><?= esc_html__('Fichier CSV', 'ispag') ?></label>
                            </th>
                            <td>
                                <input type="file" id="csv_file" name="csv_file" accept=".csv,.txt" required>
                                <p class="description">
                                    <?= esc_html__('Encodage UTF-8 recommandé. Séparateur ; ou , détecté automatiquement.', 'ispag') ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="valid_from"><?= esc_html__('Date d\'entrée en vigueur', 'ispag') ?></label>
                            </th>
                            <td>
                                <input type="date" id="valid_from" name="valid_from"
                                       value="<?= esc_attr(date('Y-m-d')) ?>" required>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="default_supplier"><?= esc_html__('Fournisseur par défaut', 'ispag') ?></label>
                            </th>
                            <td>
                                <select id="default_supplier" name="default_supplier">
                                    <option value=""><?= esc_html__('— défini dans le CSV —', 'ispag') ?></option>
                                    <?php foreach ($suppliers as $s): ?>
                                        <option value="<?= esc_attr($s->Id) ?>">
                                            <?= esc_html($s->NomFournisseur) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="description">
                                    <?= esc_html__('Si sélectionné, écrase la colonne supplier_id du CSV.', 'ispag') ?>
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
                                    <?= esc_html__('Simuler sans écrire en base (dry run)', 'ispag') ?>
                                </label>
                            </td>
                        </tr>
                    </table>

                    <?php submit_button(__('Importer le CSV', 'ispag')) ?>
                </form>
            </div>

            <div style="max-width:640px;margin-top:24px;">
                <h3><?= esc_html__('Exemple de fichier CSV valide', 'ispag') ?></h3>
                <table class="widefat striped" style="font-size:13px;">
                    <thead>
                        <tr>
                            <th>supplier_id</th>
                            <th>supplier_reference</th>
                            <th>purchase_price</th>
                            <th>discount</th>
                            <th>currency</th>
                            <th>note</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr><td>12</td><td>REF-ABC-001</td><td>125.50</td><td>25</td><td>CHF</td><td>Tarif 2026</td></tr>
                        <tr><td>12</td><td>REF-ABC-002</td><td>89.00</td><td>0</td><td>CHF</td><td></td></tr>
                        <tr><td>7</td><td>XYZ-999</td><td>440.00</td><td>30</td><td>EUR</td><td>Hausse matière</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
        <?php
    }

    // ─────────────────────────────────────────────────────────────────────────
    // TRAITEMENT DU CSV
    // ─────────────────────────────────────────────────────────────────────────

    public function handle_import(): void {
        if (!current_user_can('manage_options')) wp_die(__('Accès refusé'));
        check_admin_referer($this->nonce_action, 'ispag_nonce');

        $dry_run          = !empty($_POST['dry_run']);
        $valid_from       = sanitize_text_field($_POST['valid_from'] ?? date('Y-m-d'));
        $default_supplier = intval($_POST['default_supplier'] ?? 0);

        // Validation de la date
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $valid_from)) {
            $valid_from = date('Y-m-d');
        }

        if (empty($_FILES['csv_file']['tmp_name'])) {
            $this->redirect_with_result('error', __('No file received.', 'ispag'));
            return;
        }

        $file = $_FILES['csv_file']['tmp_name'];
        $rows = $this->parse_csv($file);

        if (empty($rows)) {
            $this->redirect_with_result('error', __('The CSV file is empty or unreadable.', 'ispag'));
            return;
        }

        $updated  = 0;
        $skipped  = 0;
        $errors   = 0;
        $details  = [];

        foreach ($rows as $i => $row) {
            $line_num = $i + 2; // +2 car ligne 1 = en-tête

            // Résolution du supplier_id
            $supplier_id = $default_supplier ?: intval($row['supplier_id'] ?? 0);
            $ref         = trim($row['supplier_reference'] ?? '');
            $price       = floatval(str_replace(',', '.', $row['purchase_price'] ?? 0));
            $discount    = floatval(str_replace(',', '.', $row['discount'] ?? 0));
            $currency    = strtoupper(trim($row['currency'] ?? 'CHF'));
            $note        = sanitize_text_field($row['note'] ?? '');

            // Validations basiques
            if (!$supplier_id || !$ref) {
                $details[] = ['ok' => false, 'msg' => "Ligne $line_num : supplier_id ou référence manquant — ignoré."];
                $errors++;
                continue;
            }
            if ($price < 0) {
                $details[] = ['ok' => false, 'msg' => "Ligne $line_num ($ref) : prix négatif — ignoré."];
                $errors++;
                continue;
            }

            // Recherche du purchase_id via supplier_reference + supplier_id
            $purchase = $this->find_purchase($ref, $supplier_id);

            if (!$purchase) {
                $details[] = ['ok' => false, 'msg' => "Ligne $line_num ($ref) : référence introuvable pour fournisseur #$supplier_id — ignoré."];
                $skipped++;
                continue;
            }

            // Vérification : le prix a-t-il vraiment changé ?
            $current = $this->get_current_price($purchase->Id);
            if (
                $current
                && (float)$current->purchase_price === $price
                && (float)$current->discount === $discount
                && strtoupper($current->currency) === $currency
            ) {
                $details[] = ['ok' => true, 'msg' => "Ligne $line_num ($ref) : prix identique, aucune modification."];
                $skipped++;
                continue;
            }

            if (!$dry_run) {
                $ok = $this->apply_price_update($purchase->Id, $price, $discount, $currency, $valid_from, $note);
            } else {
                $ok = true; // simulation
            }

            if ($ok) {
                $label = $dry_run ? '[DRY RUN] ' : '';
                $details[] = ['ok' => true, 'msg' => "Ligne $line_num ($ref) : {$label}prix mis à jour → $price $currency (remise {$discount}%)."];
                $updated++;
            } else {
                $details[] = ['ok' => false, 'msg' => "Ligne $line_num ($ref) : erreur lors de la mise à jour en base."];
                $errors++;
            }
        }

        $mode = $dry_run ? ' [MODE SIMULATION — rien n\'a été écrit]' : '';
        $msg  = sprintf(
            __('%d ligne(s) mise(s) à jour, %d ignorée(s) (prix identique ou introuvable), %d erreur(s).%s', 'ispag'),
            $updated, $skipped, $errors, $mode
        );

        $this->redirect_with_result($errors > 0 ? 'warning' : 'success', $msg, $details);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // HELPERS PRIVÉS
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Parse le CSV (auto-détection du séparateur ; ou ,).
     * Retourne un tableau associatif basé sur la première ligne (en-tête).
     */
    private function parse_csv(string $filepath): array {
        $handle = fopen($filepath, 'r');
        if (!$handle) return [];

        // Détection du séparateur sur la première ligne
        $first_line = fgets($handle);
        rewind($handle);
        $separator = substr_count($first_line, ';') >= substr_count($first_line, ',') ? ';' : ',';

        $rows    = [];
        $headers = [];
        $i       = 0;

        while (($data = fgetcsv($handle, 1000, $separator)) !== false) {
            // Nettoyage BOM UTF-8 éventuel sur le premier champ
            if ($i === 0) {
                $data[0] = ltrim($data[0], "\xEF\xBB\xBF");
                $headers = array_map('trim', $data);
                $i++;
                continue;
            }

            if (count($data) !== count($headers)) continue; // ligne malformée

            $rows[] = array_combine($headers, array_map('trim', $data));
            $i++;
        }

        fclose($handle);
        return $rows;
    }

    /**
     * Cherche un article dans la table catalogue par référence + fournisseur.
     */
    private function find_purchase(string $supplier_reference, int $supplier_id): ?object {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT Id, purchase_price, discount, currency
             FROM {$this->table_purchase}
             WHERE supplier_reference = %s AND supplier_id = %d
             LIMIT 1",
            $supplier_reference, $supplier_id
        ));
    }

    /**
     * Retourne le prix actuellement actif (valid_to IS NULL).
     */
    private function get_current_price(int $purchase_id): ?object {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT purchase_price, discount, currency
             FROM {$this->table_history}
             WHERE purchase_id = %d AND valid_to IS NULL
             LIMIT 1",
            $purchase_id
        ));
    }

    /**
     * Archive l'ancien prix et insère le nouveau.
     */
    private function apply_price_update(
        int    $purchase_id,
        float  $price,
        float  $discount,
        string $currency,
        string $valid_from,
        string $note
    ): bool {
        global $wpdb;

        $yesterday = date('Y-m-d', strtotime($valid_from . ' -1 day'));

        // 1. Clôture du prix actif
        $wpdb->query($wpdb->prepare(
            "UPDATE {$this->table_history}
             SET valid_to = %s
             WHERE purchase_id = %d AND valid_to IS NULL",
            $yesterday, $purchase_id
        ));

        // 2. Nouveau prix actif dans l'historique
        $result = $wpdb->insert(
            $this->table_history,
            [
                'purchase_id'    => $purchase_id,
                'purchase_price' => $price,
                'discount'       => $discount,
                'currency'       => $currency,
                'valid_from'     => $valid_from,
                'valid_to'       => null,
                'changed_by'     => get_current_user_id(),
                'note'           => $note ?: 'Import CSV',
                'created_at'     => current_time('mysql'),
            ],
            ['%d', '%f', '%f', '%s', '%s', null, '%d', '%s', '%s']
        );

        if (!$result) return false;

        // 3. Synchronisation de la table principale (rétrocompatibilité)
        $wpdb->update(
            $this->table_purchase,
            ['purchase_price' => $price, 'discount' => $discount, 'currency' => $currency],
            ['Id' => $purchase_id],
            ['%f', '%f', '%s'],
            ['%d']
        );

        return true;
    }

    /**
     * Stocke le résultat dans un transient et redirige vers la page admin.
     */
    private function redirect_with_result(string $type, string $message, array $details = []): void {
        set_transient(
            'ispag_purchase_price_import_result_' . get_current_user_id(),
            ['type' => $type, 'message' => $message, 'details' => $details],
            60
        );
        wp_safe_redirect(admin_url('admin.php?page=ispag_purchase_price_import'));
        exit;
    }
}
