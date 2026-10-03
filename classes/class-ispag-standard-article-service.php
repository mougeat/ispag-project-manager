<?php
defined('ABSPATH') || exit;

/**
 * Accès aux données des articles standard (catalogue) : ventes (achats_articles + historique des prix de vente)
 * et achats (achats_articles_purchase : un article peut avoir plusieurs fournisseurs, chacun avec son prix d'achat
 * historisé dans achats_articles_purchase_price_history).
 *
 * Règle d'historisation (identique aux imports CSV) : changer un prix clôture le prix actif (valid_to = veille de la
 * nouvelle date) et en insère un nouveau (valid_to NULL) ; la table principale est synchronisée (rétrocompatibilité).
 */
class ISPAG_Standard_Article_Service {

    const PER_PAGE = 50;
    /** Un prix d'achat plus ancien que ce nombre de mois est signalé comme à revoir. */
    const OUTDATED_MONTHS = 12;
    /** Meta de la pièce jointe qui la rattache à un article standard. */
    const DOC_META = '_ispag_std_article_id';

    public static function outdated_months() {
        return max(1, (int) apply_filters('ispag_std_outdated_months', self::OUTDATED_MONTHS));
    }

    /** Date limite (Y-m-d) : un prix actif dont valid_from est antérieure est « ancien ». */
    public static function outdated_cutoff() {
        return date('Y-m-d', strtotime('-' . self::outdated_months() . ' months'));
    }

    private static function t($name) {
        global $wpdb;
        return $wpdb->prefix . $name;
    }

    // ------------------------------------------------------------------ Types / fournisseurs

    public static function types() {
        global $wpdb;
        return (array) $wpdb->get_results('SELECT Id, type, color FROM ' . self::t('achats_type_prestations') . ' ORDER BY sort ASC, type ASC');
    }

    public static function type_names() {
        $names = [];
        foreach (self::types() as $t) {
            $names[(int) $t->Id] = $t->type;
        }
        return $names;
    }

    /** Fournisseurs (entreprises marquées fournisseur), pour les listes déroulantes. */
    public static function suppliers() {
        global $wpdb;
        return (array) $wpdb->get_results('SELECT Id, company_name FROM ' . self::t('ispag_companies') . ' WHERE isSupplier = 1 ORDER BY company_name ASC');
    }

    // ------------------------------------------------------------------ Liste

    /**
     * @param array $args type (int), search (string), supplier (int), no_purchase (bool), page (int)
     * @return array ['rows' => [...], 'total' => int, 'pages' => int]
     */
    public static function search(array $args) {
        global $wpdb;
        $a       = self::t('achats_articles');
        $h       = self::t('achats_articles_price_history');
        $p       = self::t('achats_articles_purchase');
        $where   = ['1=1'];
        $params  = [];

        if (!empty($args['type'])) {
            $where[]  = 'a.TypeArticle = %d';
            $params[] = (int) $args['type'];
        }
        if (!empty($args['search'])) {
            $like     = '%' . $wpdb->esc_like($args['search']) . '%';
            $where[]  = '(a.TitreArticle LIKE %s OR a.ref_article_ispag LIKE %s OR a.description_ispag LIKE %s)';
            array_push($params, $like, $like, $like);
        }
        if (!empty($args['supplier'])) {
            $where[]  = "EXISTS (SELECT 1 FROM {$p} px WHERE px.article_id = a.Id AND px.supplier_id = %d)";
            $params[] = (int) $args['supplier'];
        }
        if (!empty($args['no_purchase'])) {
            $where[] = "NOT EXISTS (SELECT 1 FROM {$p} px WHERE px.article_id = a.Id)";
        }
        $ph      = self::t('achats_articles_purchase_price_history');
        $cutoff  = self::outdated_cutoff();
        // Ligne d'achat dont le prix actif est ancien (ou sans date)
        $outdated_sql = "EXISTS (SELECT 1 FROM {$p} po LEFT JOIN {$ph} pho ON pho.purchase_id = po.Id AND pho.valid_to IS NULL
                                 WHERE po.article_id = a.Id AND (pho.Id IS NULL OR pho.valid_from < %s))";
        if (!empty($args['outdated'])) {
            $where[]  = $outdated_sql;
            $params[] = $cutoff;
        }
        $where_sql = implode(' AND ', $where);

        $all      = !empty($args['all']); // export : pas de pagination
        $page     = $all ? 1 : max(1, (int) ($args['page'] ?? 1));
        $per_page = $all ? 100000 : self::PER_PAGE;
        $offset   = ($page - 1) * $per_page;

        $count_sql = "SELECT COUNT(*) FROM {$a} a WHERE {$where_sql}";
        $total     = (int) ($params ? $wpdb->get_var($wpdb->prepare($count_sql, $params)) : $wpdb->get_var($count_sql));

        $sql = "SELECT a.Id, a.TypeArticle, a.ref_article_ispag, a.TitreArticle, a.description_ispag, a.delivery_time, a.Poids, a.UnitePoids, a.image,
                       COALESCE((SELECT hh.sales_price FROM {$h} hh WHERE hh.article_id = a.Id AND hh.valid_to IS NULL ORDER BY hh.valid_from DESC LIMIT 1), a.sales_price) AS current_price,
                       (SELECT COUNT(*) FROM {$p} pc WHERE pc.article_id = a.Id) AS nb_suppliers,
                       (SELECT COUNT(*) FROM {$p} pd LEFT JOIN {$ph} phd ON phd.purchase_id = pd.Id AND phd.valid_to IS NULL
                         WHERE pd.article_id = a.Id AND (phd.Id IS NULL OR phd.valid_from < %s)) AS nb_outdated
                FROM {$a} a
                WHERE {$where_sql}
                ORDER BY a.TypeArticle ASC, a.TitreArticle ASC
                LIMIT %d OFFSET %d";
        $rows = (array) $wpdb->get_results($wpdb->prepare($sql, array_merge([$cutoff], $params, [$per_page, $offset])));

        return ['rows' => $rows, 'total' => $total, 'pages' => (int) max(1, ceil($total / $per_page)), 'page' => $page];
    }

    // ------------------------------------------------------------------ Fiche : ventes

    public static function get($id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::t('achats_articles') . ' WHERE Id = %d', (int) $id));
    }

    /**
     * Articles portant cette référence ISPAG (comparaison sans casse, espaces ignorés en début/fin).
     * @return object[] 0 = inconnu, 1 = ok, >1 = ambigu
     */
    public static function find_by_ref($ref) {
        global $wpdb;
        return (array) $wpdb->get_results($wpdb->prepare(
            'SELECT Id, TypeArticle, ref_article_ispag, TitreArticle FROM ' . self::t('achats_articles') . ' WHERE LOWER(TRIM(ref_article_ispag)) = LOWER(%s) ORDER BY Id ASC',
            trim((string) $ref)
        ));
    }

    public static function current_sales_price($id) {
        global $wpdb;
        $price = $wpdb->get_var($wpdb->prepare(
            'SELECT sales_price FROM ' . self::t('achats_articles_price_history') . ' WHERE article_id = %d AND valid_to IS NULL ORDER BY valid_from DESC LIMIT 1',
            (int) $id
        ));
        if ($price === null) {
            $price = $wpdb->get_var($wpdb->prepare('SELECT sales_price FROM ' . self::t('achats_articles') . ' WHERE Id = %d', (int) $id));
        }
        return (float) $price;
    }

    public static function sales_history($id) {
        global $wpdb;
        return (array) $wpdb->get_results($wpdb->prepare(
            'SELECT h.*, u.display_name FROM ' . self::t('achats_articles_price_history') . ' h
             LEFT JOIN ' . $wpdb->users . ' u ON u.ID = h.changed_by
             WHERE h.article_id = %d ORDER BY h.valid_from DESC, h.Id DESC',
            (int) $id
        ));
    }

    /** Nombre de lignes de projet qui utilisent cet article (suppression interdite si > 0). */
    public static function usage_count($id) {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . self::t('achats_details_commande') . ' WHERE IdArticleStandard = %d', (int) $id));
    }

    /**
     * Crée un article (titre + type) ; retourne son Id ou 0.
     * Les colonnes NOT NULL sans valeur par défaut reçoivent une valeur vide.
     */
    public static function create($title, $type, $ref = '') {
        global $wpdb;
        $ok = $wpdb->insert(self::t('achats_articles'), [
            'TypeArticle'       => (int) $type,
            'ref_article_ispag' => (string) $ref,
            'CodeBarre'         => '',
            'TitreArticle'      => $title,
            'description_ispag' => '',
            'conception'        => '{}',
            'drawing'           => 0,
            'documentation'     => 0,
            'sales_price'       => 0,
            'delivery_time'     => 0,
            'Poids'             => 0,
            'UnitePoids'        => 'kg',
            'image'             => 0,
        ]);
        return $ok ? (int) $wpdb->insert_id : 0;
    }

    /** Champs de vente modifiables : colonne => [type de nettoyage]. */
    public static function sales_fields() {
        return [
            'TitreArticle'      => 'text',
            'ref_article_ispag' => 'text',
            'CodeBarre'         => 'text',
            'TypeArticle'       => 'int',
            'description_ispag' => 'textarea',
            'delivery_time'     => 'int',
            'Poids'             => 'float',
            'UnitePoids'        => 'text',
            'image'             => 'int',
        ];
    }

    /** Met à jour UN champ de vente (édition en ligne). Retourne la valeur nettoyée ou null si refus. */
    public static function update_sales_field($id, $field, $raw) {
        global $wpdb;
        $fields = self::sales_fields();
        if (!isset($fields[$field])) {
            return null;
        }
        switch ($fields[$field]) {
            case 'int':      $value = (int) $raw; $format = '%d'; break;
            case 'float':    $value = (float) str_replace(',', '.', (string) $raw); $format = '%f'; break;
            case 'textarea': $value = sanitize_textarea_field(wp_unslash($raw)); $format = '%s'; break;
            default:         $value = sanitize_text_field(wp_unslash($raw)); $format = '%s';
        }
        if ($field === 'TitreArticle' && $value === '') {
            return null;
        }
        $done = $wpdb->update(self::t('achats_articles'), [$field => $value], ['Id' => (int) $id], [$format], ['%d']);
        return $done === false ? null : $value;
    }

    /** Nouveau prix de vente historisé. */
    public static function apply_sales_price($id, $price, $valid_from, $note = '') {
        global $wpdb;
        $h         = self::t('achats_articles_price_history');
        $yesterday = date('Y-m-d', strtotime($valid_from . ' -1 day'));

        $wpdb->query($wpdb->prepare("UPDATE {$h} SET valid_to = %s WHERE article_id = %d AND valid_to IS NULL", $yesterday, (int) $id));
        $ok = $wpdb->insert($h, [
            'article_id'  => (int) $id,
            'sales_price' => (float) $price,
            'valid_from'  => $valid_from,
            'valid_to'    => null,
            'changed_by'  => get_current_user_id(),
            'note'        => $note,
            'created_at'  => current_time('mysql'),
        ], ['%d', '%f', '%s', null, '%d', '%s', '%s']);
        if (!$ok) {
            return false;
        }
        $wpdb->update(self::t('achats_articles'), ['sales_price' => (float) $price], ['Id' => (int) $id], ['%f'], ['%d']);
        return true;
    }

    public static function delete($id) {
        global $wpdb;
        if (self::usage_count($id) > 0) {
            return false;
        }
        $purchase_ids = $wpdb->get_col($wpdb->prepare('SELECT Id FROM ' . self::t('achats_articles_purchase') . ' WHERE article_id = %d', (int) $id));
        foreach ($purchase_ids as $pid) {
            self::delete_purchase((int) $pid);
        }
        $wpdb->delete(self::t('achats_articles_price_history'), ['article_id' => (int) $id], ['%d']);
        return $wpdb->delete(self::t('achats_articles'), ['Id' => (int) $id], ['%d']) !== false;
    }

    // ------------------------------------------------------------------ Fiche : achats

    /**
     * Lignes d'achat d'un article : une par fournisseur (prix d'achat courant).
     * price_since = date d'effet du prix actif (NULL si l'historique est vide) ; is_outdated = prix ancien ou sans date.
     */
    public static function purchases($article_id) {
        global $wpdb;
        $rows = (array) $wpdb->get_results($wpdb->prepare(
            'SELECT p.*, c.company_name,
                    (SELECT h.valid_from FROM ' . self::t('achats_articles_purchase_price_history') . ' h WHERE h.purchase_id = p.Id AND h.valid_to IS NULL ORDER BY h.valid_from DESC LIMIT 1) AS price_since
             FROM ' . self::t('achats_articles_purchase') . ' p
             LEFT JOIN ' . self::t('ispag_companies') . ' c ON c.Id = p.supplier_id
             WHERE p.article_id = %d ORDER BY c.company_name ASC',
            (int) $article_id
        ));
        $cutoff = self::outdated_cutoff();
        foreach ($rows as $r) {
            $r->is_outdated = ($r->price_since === null || $r->price_since < $cutoff);
        }
        return $rows;
    }

    public static function get_purchase($purchase_id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::t('achats_articles_purchase') . ' WHERE Id = %d', (int) $purchase_id));
    }

    /** Ajoute un fournisseur à un article ; retourne l'Id de la ligne ou 0 (déjà présent / invalide). */
    public static function add_purchase($article_id, $supplier_id, $price, $discount, $currency, $reference, $description, $delivery_days, $valid_from = null, $note = 'Création') {
        global $wpdb;
        $exists = $wpdb->get_var($wpdb->prepare(
            'SELECT Id FROM ' . self::t('achats_articles_purchase') . ' WHERE article_id = %d AND supplier_id = %d',
            (int) $article_id, (int) $supplier_id
        ));
        if ($exists) {
            return 0;
        }
        $ok = $wpdb->insert(self::t('achats_articles_purchase'), [
            'article_id'           => (int) $article_id,
            'supplier_id'          => (int) $supplier_id,
            'supplier_reference'   => $reference,
            'supplier_description' => $description,
            'purchase_price'       => 0,
            'discount'             => 0,
            'currency'             => $currency,
            'delivery_days'        => (int) $delivery_days,
            'proposal'             => 0,
        ]);
        if (!$ok) {
            return 0;
        }
        $purchase_id = (int) $wpdb->insert_id;
        self::apply_purchase_price($purchase_id, $price, $discount, $currency, $valid_from ?: date('Y-m-d'), $note);
        return $purchase_id;
    }

    /** Champs modifiables d'une ligne d'achat (hors prix, historisé à part). */
    public static function purchase_fields() {
        return [
            'supplier_reference'   => 'text',
            'supplier_description' => 'textarea',
            'delivery_days'        => 'int',
        ];
    }

    public static function update_purchase_field($purchase_id, $field, $raw) {
        global $wpdb;
        $fields = self::purchase_fields();
        if (!isset($fields[$field])) {
            return null;
        }
        if ($fields[$field] === 'int') {
            $value = (int) $raw; $format = '%d';
        } elseif ($fields[$field] === 'textarea') {
            $value = sanitize_textarea_field(wp_unslash($raw)); $format = '%s';
        } else {
            $value = sanitize_text_field(wp_unslash($raw)); $format = '%s';
        }
        $done = $wpdb->update(self::t('achats_articles_purchase'), [$field => $value], ['Id' => (int) $purchase_id], [$format], ['%d']);
        return $done === false ? null : $value;
    }

    /** Nouveau prix d'achat historisé (prix, remise en %, devise). */
    public static function apply_purchase_price($purchase_id, $price, $discount, $currency, $valid_from, $note = '') {
        global $wpdb;
        $h         = self::t('achats_articles_purchase_price_history');
        $yesterday = date('Y-m-d', strtotime($valid_from . ' -1 day'));

        $wpdb->query($wpdb->prepare("UPDATE {$h} SET valid_to = %s WHERE purchase_id = %d AND valid_to IS NULL", $yesterday, (int) $purchase_id));
        $ok = $wpdb->insert($h, [
            'purchase_id'    => (int) $purchase_id,
            'purchase_price' => (float) $price,
            'discount'       => (float) $discount,
            'currency'       => $currency,
            'valid_from'     => $valid_from,
            'valid_to'       => null,
            'changed_by'     => get_current_user_id(),
            'note'           => $note,
            'created_at'     => current_time('mysql'),
        ], ['%d', '%f', '%f', '%s', '%s', null, '%d', '%s', '%s']);
        if (!$ok) {
            return false;
        }
        $wpdb->update(self::t('achats_articles_purchase'),
            ['purchase_price' => (float) $price, 'discount' => (float) $discount, 'currency' => $currency],
            ['Id' => (int) $purchase_id], ['%f', '%f', '%s'], ['%d']);
        return true;
    }

    public static function purchase_history($purchase_id) {
        global $wpdb;
        return (array) $wpdb->get_results($wpdb->prepare(
            'SELECT h.*, u.display_name FROM ' . self::t('achats_articles_purchase_price_history') . ' h
             LEFT JOIN ' . $wpdb->users . ' u ON u.ID = h.changed_by
             WHERE h.purchase_id = %d ORDER BY h.valid_from DESC, h.Id DESC',
            (int) $purchase_id
        ));
    }

    public static function delete_purchase($purchase_id) {
        global $wpdb;
        $wpdb->delete(self::t('achats_articles_purchase_price_history'), ['purchase_id' => (int) $purchase_id], ['%d']);
        return $wpdb->delete(self::t('achats_articles_purchase'), ['Id' => (int) $purchase_id], ['%d']) !== false;
    }

    // ------------------------------------------------------------------ Vue fournisseur

    /** Articles d'un fournisseur avec son prix d'achat (pour la fiche fournisseur). */
    public static function articles_of_supplier($supplier_id) {
        global $wpdb;
        return (array) $wpdb->get_results($wpdb->prepare(
            'SELECT p.Id AS purchase_id, p.supplier_reference, p.purchase_price, p.discount, p.currency, p.delivery_days,
                    a.Id AS article_id, a.TitreArticle, a.ref_article_ispag, a.TypeArticle,
                    (SELECT h.valid_from FROM ' . self::t('achats_articles_purchase_price_history') . ' h WHERE h.purchase_id = p.Id AND h.valid_to IS NULL ORDER BY h.valid_from DESC LIMIT 1) AS price_since
             FROM ' . self::t('achats_articles_purchase') . ' p
             INNER JOIN ' . self::t('achats_articles') . ' a ON a.Id = p.article_id
             WHERE p.supplier_id = %d ORDER BY a.TypeArticle ASC, a.TitreArticle ASC',
            (int) $supplier_id
        ));
    }

    // ------------------------------------------------------------------ Documents (médiathèque)

    /** Pièces jointes rattachées à l'article (meta _ispag_std_article_id sur la pièce jointe). */
    public static function documents($article_id) {
        return get_posts([
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'posts_per_page' => -1,
            'meta_key'       => self::DOC_META,
            'meta_value'     => (int) $article_id,
            'orderby'        => 'date',
            'order'          => 'DESC',
        ]);
    }

    /** Rattache des pièces jointes existantes à l'article ; retourne le nombre rattaché. */
    public static function attach_documents($article_id, array $attachment_ids) {
        $n = 0;
        foreach (array_unique(array_map('intval', $attachment_ids)) as $aid) {
            if ($aid > 0 && get_post_type($aid) === 'attachment') {
                update_post_meta($aid, self::DOC_META, (int) $article_id);
                $n++;
            }
        }
        return $n;
    }

    /** Détache un document (le fichier reste dans la médiathèque). */
    public static function detach_document($article_id, $attachment_id) {
        if ((int) get_post_meta((int) $attachment_id, self::DOC_META, true) !== (int) $article_id) {
            return false;
        }
        delete_post_meta((int) $attachment_id, self::DOC_META);
        return true;
    }

    // ------------------------------------------------------------------ Export

    /**
     * Lignes d'export CSV (une par article). Les colonnes d'achat ne sont fournies qu'avec $with_purchase.
     * @return array [ [en-têtes], [ligne], … ]
     */
    public static function export_rows(array $args, $with_purchase) {
        $types  = self::type_names();
        $result = self::search(array_merge($args, ['all' => true]));

        $head = ['ID', 'Type', 'ISPAG reference', 'Title', 'Description', 'Sales price', 'Weight', 'Weight unit', 'Delivery time (days)'];
        if ($with_purchase) {
            array_push($head, 'Suppliers', 'Nb suppliers', 'Oldest purchase price since', 'Outdated purchase prices');
        }
        $out = [$head];

        foreach ($result['rows'] as $r) {
            $line = [(int) $r->Id, $types[(int) $r->TypeArticle] ?? '', $r->ref_article_ispag, $r->TitreArticle,
                     preg_replace('/\s+/', ' ', (string) $r->description_ispag),
                     number_format((float) $r->current_price, 2, '.', ''), (float) $r->Poids, $r->UnitePoids, (int) $r->delivery_time];
            if ($with_purchase) {
                $names = [];
                $oldest = '';
                foreach (self::purchases($r->Id) as $p) {
                    $net = (float) $p->purchase_price * (1 - (float) $p->discount / 100);
                    $names[] = sprintf('%s: %s %s%s', $p->company_name ?: ('#' . $p->supplier_id), number_format($net, 2, '.', ''), $p->currency, $p->price_since ? ' (' . $p->price_since . ')' : '');
                    if ($p->price_since && ($oldest === '' || $p->price_since < $oldest)) {
                        $oldest = $p->price_since;
                    }
                }
                array_push($line, implode(' | ', $names), (int) $r->nb_suppliers, $oldest, (int) $r->nb_outdated);
            }
            $out[] = $line;
        }
        return $out;
    }

    public static function article_url($article_id) {
        return home_url('/article-standard/' . (int) $article_id . '/');
    }
}
