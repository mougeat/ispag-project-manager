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
    // Types de prestations (export de la production du 29.09.2026). 'image' = ID de média de la prod :
    // à réassigner sur le nouveau site si les images doivent s'afficher.
    'achats_type_prestations' => [
        ['Id' => 1, 'type' => 'Special tanks', 'prestation' => 'Product', 'description' => 'These items are custom-made physical products. They are not kept in stock.', 'stockManaged' => 0, 'sort' => 1, 'color' => '#FEF3C7', 'image' => 5561, 'delivery_time' => '3'],
        ['Id' => 2, 'type' => 'Insulation', 'prestation' => 'Isol', 'description' => 'These items are benefits. They are not managed in stock.', 'stockManaged' => 0, 'sort' => 30, 'color' => '#E0F2FE', 'image' => 5560, 'delivery_time' => '0'],
        ['Id' => 3, 'type' => 'On site welding', 'prestation' => 'Welding', 'description' => 'These items are benefits. They are not managed in stock.', 'stockManaged' => 0, 'sort' => 20, 'color' => '#FEE2E2', 'image' => 5559, 'delivery_time' => '0'],
        ['Id' => 4, 'type' => 'Heating elements', 'prestation' => 'div', 'description' => 'These articles are standard articles and are managed in stock.', 'stockManaged' => 1, 'sort' => 10, 'color' => '#F3F4F6', 'image' => 5563, 'delivery_time' => '3'],
        ['Id' => 5, 'type' => 'Plate exchanger', 'prestation' => 'div', 'description' => 'These articles are standard articles and are managed in stock.', 'stockManaged' => 1, 'sort' => 100, 'color' => '#F3F4F6', 'image' => 5562, 'delivery_time' => '3'],
        ['Id' => 6, 'type' => 'Water heater RET', 'prestation' => 'Product', 'description' => 'These articles are standard articles but not managed in stock.', 'stockManaged' => 0, 'sort' => 2, 'color' => '#FEF3C7', 'image' => 2384, 'delivery_time' => '3'],
        ['Id' => 7, 'type' => 'Water heater Thermostar', 'prestation' => 'Product', 'description' => 'These articles are standard articles but not managed in stock.', 'stockManaged' => 0, 'sort' => 3, 'color' => '#FEF3C7', 'image' => 2386, 'delivery_time' => '3'],
        ['Id' => 8, 'type' => 'Consumable', 'prestation' => 'div', 'description' => 'Consumables are physical products for which are managed in the inventory level: they are always available.', 'stockManaged' => 1, 'sort' => 2, 'color' => '#F3F4F6', 'image' => 5564, 'delivery_time' => '3'],
        ['Id' => 9, 'type' => 'Water heater RHLS', 'prestation' => 'Product', 'description' => 'These articles are standard articles but not managed in stock.', 'stockManaged' => 0, 'sort' => 4, 'color' => '#FEF3C7', 'image' => 2385, 'delivery_time' => '3'],
        ['Id' => 10, 'type' => 'Water heater MULTI', 'prestation' => 'Product', 'description' => 'These articles are standard articles but not managed in stock.', 'stockManaged' => 0, 'sort' => 5, 'color' => '#FEF3C7', 'image' => 2387, 'delivery_time' => '3'],
        ['Id' => 12, 'type' => 'Energy accumulator PF', 'prestation' => 'Product', 'description' => 'These articles are standard articles but not managed in stock.', 'stockManaged' => 0, 'sort' => 6, 'color' => '#FEF3C7', 'image' => 4233, 'delivery_time' => '3'],
        ['Id' => 13, 'type' => 'DIV', 'prestation' => 'div', 'description' => 'This typ of product are physical products for which you don\'t manage the inventory level: they need to be ordered for each project', 'stockManaged' => 0, 'sort' => 15, 'color' => '#F3F4F6', 'image' => 4233, 'delivery_time' => '3'],
        ['Id' => 200, 'type' => 'Insulation Added value', 'prestation' => 'Isol', 'description' => 'These items are benefits. They are not managed in stock.', 'stockManaged' => 0, 'sort' => 31, 'color' => '#E0F2FE', 'image' => 5560, 'delivery_time' => '0'],
        ['Id' => 500, 'type' => 'Accessories plate exchanger', 'prestation' => 'div', 'description' => 'These articles are standard articles and are managed in stock.', 'stockManaged' => 1, 'sort' => 101, 'color' => '#F3F4F6', 'image' => 5562, 'delivery_time' => '3'],
    ],
];
