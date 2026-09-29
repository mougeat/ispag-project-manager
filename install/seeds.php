<?php
/**
 * Valeurs initiales des tables de référence d'ISPAG Project Manager.
 * Chaque table n'est remplie que si elle est VIDE (jamais sur un site existant). Format :
 *
 *   'achats_doc_types' => [
 *       ['id' => 1, 'slug' => '…', 'label' => '…', 'ajax_action' => '…', 'badge_class' => '…', 'sort_order' => 1, 'restricted' => 0, 'for_article_type' => 0],
 *   ],
 *
 * Autres tables de référence : à ajouter ici depuis un export de données de la production.
 */
defined('ABSPATH') || exit;

return [
    // Types de documents (export de la production du 29.09.2026)
    'achats_doc_types' => [
        ['id' => 1, 'slug' => 'ccmd', 'label' => 'Order confirmation', 'ajax_action' => '', 'badge_class' => '', 'sort_order' => 4, 'restricted' => 1, 'for_article_type' => 0],
        ['id' => 3, 'slug' => 'customer_order', 'label' => 'Order', 'ajax_action' => '', 'badge_class' => '', 'sort_order' => 3, 'restricted' => 1, 'for_article_type' => 0],
        ['id' => 4, 'slug' => 'quotation', 'label' => 'Quotation', 'ajax_action' => 'analyze_and_confirm_data', 'badge_class' => '', 'sort_order' => 2, 'restricted' => 1, 'for_article_type' => 0],
        ['id' => 6, 'slug' => 'request_supplier_quotation', 'label' => 'Request for quotation', 'ajax_action' => 'analyze_project_data', 'badge_class' => '', 'sort_order' => 1, 'restricted' => 0, 'for_article_type' => 0],
        ['id' => 7, 'slug' => 'invoice', 'label' => 'Invoice', 'ajax_action' => 'invoice_analyse', 'badge_class' => '', 'sort_order' => 7, 'restricted' => 1, 'for_article_type' => 0],
        ['id' => 8, 'slug' => 'product_drawing', 'label' => 'Drawing', 'ajax_action' => 'analyze_drawing', 'badge_class' => 'badge-warning', 'sort_order' => 21, 'restricted' => 1, 'for_article_type' => 1],
        ['id' => 9, 'slug' => 'drawingApproval', 'label' => 'Drawing approval', 'ajax_action' => 'drawing_approval_control', 'badge_class' => 'badge-success', 'sort_order' => 23, 'restricted' => 0, 'for_article_type' => 1],
        ['id' => 10, 'slug' => 'drawingModification', 'label' => 'Drawing modification', 'ajax_action' => '', 'badge_class' => 'badge-drawingModification', 'sort_order' => 22, 'restricted' => 0, 'for_article_type' => 1],
        ['id' => 11, 'slug' => 'sketch', 'label' => 'Sketch', 'ajax_action' => 'tank_data_extractor', 'badge_class' => 'badge-sketch', 'sort_order' => 20, 'restricted' => 0, 'for_article_type' => 1],
        ['id' => 12, 'slug' => 'documentation', 'label' => 'Documentation', 'ajax_action' => '', 'badge_class' => '', 'sort_order' => 24, 'restricted' => 1, 'for_article_type' => 1],
        ['id' => 13, 'slug' => 'spreadsheet', 'label' => 'Spreadsheet', 'ajax_action' => '', 'badge_class' => '', 'sort_order' => 25, 'restricted' => 1, 'for_article_type' => 1],
        ['id' => 14, 'slug' => 'delivery_note', 'label' => 'Delivery note', 'ajax_action' => '', 'badge_class' => '', 'sort_order' => 5, 'restricted' => 1, 'for_article_type' => 0],
        ['id' => 16, 'slug' => 'note', 'label' => 'Note', 'ajax_action' => '', 'badge_class' => '', 'sort_order' => 10, 'restricted' => 0, 'for_article_type' => 0],
        ['id' => 17, 'slug' => 'picture', 'label' => 'Picture', 'ajax_action' => '', 'badge_class' => '', 'sort_order' => 30, 'restricted' => 0, 'for_article_type' => 0],
        ['id' => 18, 'slug' => 'certificat_conformity', 'label' => 'Certificate of conformity', 'ajax_action' => '', 'badge_class' => '', 'sort_order' => 26, 'restricted' => 1, 'for_article_type' => 1],
        ['id' => 19, 'slug' => 'proforma_invoice', 'label' => 'Proforma invoice', 'ajax_action' => '', 'badge_class' => 'pro_format', 'sort_order' => 6, 'restricted' => 1, 'for_article_type' => 0],
        ['id' => 20, 'slug' => 'design_detail', 'label' => 'Design detail', 'ajax_action' => '', 'badge_class' => '', 'sort_order' => 27, 'restricted' => 1, 'for_article_type' => 1],
    ],
];
