<?php
/**
 * E-mails envoyés au client quand une étape du suivi de projet passe à « fait »
 * (une étape = une ligne de achats_slug_phase avec Brevo_id > 0 ; plus aucun appel à Brevo).
 *
 * Format : SlugPhase => [
 *     'docs' => [slugs de achats_doc_types à joindre, ou 'last_drawing'],
 *     'fr_FR' | 'en_US' | 'de_DE' => ['subject' => …, 'message' => …],
 * ]
 *
 * Ces textes sont les valeurs par défaut : ils sont copiés dans wor9711_achats_template_mail
 * (message_family = 'project_mail') s'ils n'y sont pas déjà, puis se modifient dans
 * ISPAG Settings → Phase e-mails. Balises : voir ISPAG_Phase_Mail::tags().
 */
defined('ABSPATH') || exit;

return [

    // Commande enregistrée
    'CmdViag' => [
        'docs' => [],
        'fr_FR' => [
            'subject' => 'Votre commande est enregistrée - {PROJECT_NAME} ({PROJECT_NUMBER})',
            'message' => "Bonjour {PRENOM},\n\nNous avons bien enregistré votre commande pour le projet {PROJECT_NAME} (n° {PROJECT_NUMBER}).\n\n{PRODUCT_LIST}\n\nVous pouvez suivre l'avancement de votre projet à tout moment : {PROJECT_LINK}\n\nNous reviendrons vers vous avec la suite du déroulement (plans, délai de livraison).\n\nCordialement,\n{USER_NAME}",
        ],
        'en_US' => [
            'subject' => 'Your order has been registered - {PROJECT_NAME} ({PROJECT_NUMBER})',
            'message' => "Hello {PRENOM},\n\nWe have registered your order for the project {PROJECT_NAME} (no. {PROJECT_NUMBER}).\n\n{PRODUCT_LIST}\n\nYou can follow the progress of your project at any time: {PROJECT_LINK}\n\nWe will get back to you with the next steps (drawings, delivery time).\n\nBest regards,\n{USER_NAME}",
        ],
        'de_DE' => [
            'subject' => 'Ihre Bestellung wurde erfasst - {PROJECT_NAME} ({PROJECT_NUMBER})',
            'message' => "Guten Tag {PRENOM},\n\nwir haben Ihre Bestellung für das Projekt {PROJECT_NAME} (Nr. {PROJECT_NUMBER}) erfasst.\n\n{PRODUCT_LIST}\n\nDen Projektstand können Sie jederzeit verfolgen: {PROJECT_LINK}\n\nWir melden uns mit den nächsten Schritten (Pläne, Lieferzeit).\n\nFreundliche Grüsse\n{USER_NAME}",
        ],
    ],

    // Plans envoyés au client pour validation
    'EnvoiePlanClient' => [
        'docs' => ['last_drawing'],
        'fr_FR' => [
            'subject' => 'Plans à valider - {PROJECT_NAME} ({PROJECT_NUMBER})',
            'message' => "Bonjour {PRENOM},\n\nVous trouverez en pièce jointe le ou les plans de votre projet {PROJECT_NAME} (n° {PROJECT_NUMBER}).\n\nMerci de les contrôler et de nous les retourner validés, ou de nous indiquer les modifications souhaitées : {PROJECT_LINK}\n\nLa fabrication ne pourra démarrer qu'après votre validation.\n\nCordialement,\n{USER_NAME}",
        ],
        'en_US' => [
            'subject' => 'Drawings to approve - {PROJECT_NAME} ({PROJECT_NUMBER})',
            'message' => "Hello {PRENOM},\n\nPlease find attached the drawing(s) for your project {PROJECT_NAME} (no. {PROJECT_NUMBER}).\n\nPlease check them and return them approved, or tell us which changes you need: {PROJECT_LINK}\n\nManufacturing can only start once you have approved the drawings.\n\nBest regards,\n{USER_NAME}",
        ],
        'de_DE' => [
            'subject' => 'Pläne zur Freigabe - {PROJECT_NAME} ({PROJECT_NUMBER})',
            'message' => "Guten Tag {PRENOM},\n\nim Anhang finden Sie die Pläne für Ihr Projekt {PROJECT_NAME} (Nr. {PROJECT_NUMBER}).\n\nBitte prüfen Sie diese und senden Sie sie freigegeben zurück oder teilen Sie uns die gewünschten Änderungen mit: {PROJECT_LINK}\n\nDie Fertigung kann erst nach Ihrer Freigabe beginnen.\n\nFreundliche Grüsse\n{USER_NAME}",
        ],
    ],

    // Plans validés par le client
    'SignaturePlan' => [
        'docs' => ['drawingApproval'],
        'fr_FR' => [
            'subject' => 'Plans validés - {PROJECT_NAME} ({PROJECT_NUMBER})',
            'message' => "Bonjour {PRENOM},\n\nNous avons bien reçu la validation des plans de votre projet {PROJECT_NAME} (n° {PROJECT_NUMBER}). Le plan validé est joint à ce message.\n\nNous lançons la fabrication et vous communiquerons la date de livraison dès qu'elle sera confirmée.\n\nSuivi du projet : {PROJECT_LINK}\n\nCordialement,\n{USER_NAME}",
        ],
        'en_US' => [
            'subject' => 'Drawings approved - {PROJECT_NAME} ({PROJECT_NUMBER})',
            'message' => "Hello {PRENOM},\n\nWe have received your approval of the drawings for the project {PROJECT_NAME} (no. {PROJECT_NUMBER}). The approved drawing is attached.\n\nWe are starting manufacturing and will let you know the delivery date as soon as it is confirmed.\n\nProject follow-up: {PROJECT_LINK}\n\nBest regards,\n{USER_NAME}",
        ],
        'de_DE' => [
            'subject' => 'Pläne freigegeben - {PROJECT_NAME} ({PROJECT_NUMBER})',
            'message' => "Guten Tag {PRENOM},\n\nwir haben Ihre Freigabe der Pläne für das Projekt {PROJECT_NAME} (Nr. {PROJECT_NUMBER}) erhalten. Der freigegebene Plan ist angehängt.\n\nWir starten die Fertigung und teilen Ihnen den Liefertermin mit, sobald er bestätigt ist.\n\nProjektstand: {PROJECT_LINK}\n\nFreundliche Grüsse\n{USER_NAME}",
        ],
    ],

    // Date de livraison communiquée
    'DateLivraisonCuve' => [
        'docs' => [],
        'fr_FR' => [
            'subject' => 'Date de livraison - {PROJECT_NAME} ({PROJECT_NUMBER})',
            'message' => "Bonjour {PRENOM},\n\nLa livraison de votre projet {PROJECT_NAME} (n° {PROJECT_NUMBER}) est prévue : {DELIVERY_DATE}.\n\nLieu de livraison : {DELIVERY_ADRESS}, {DELIVERY_NIP} {DELIVERY_CITY}\nContact sur place : {DELIVERY_CONTACT} {DELIVERY_CONTACT_PHONE}\n\nMerci de vérifier que l'accès et le déchargement sont possibles ce jour-là. Suivi du projet : {PROJECT_LINK}\n\nCordialement,\n{USER_NAME}",
        ],
        'en_US' => [
            'subject' => 'Delivery date - {PROJECT_NAME} ({PROJECT_NUMBER})',
            'message' => "Hello {PRENOM},\n\nDelivery of your project {PROJECT_NAME} (no. {PROJECT_NUMBER}) is planned: {DELIVERY_DATE}.\n\nDelivery address: {DELIVERY_ADRESS}, {DELIVERY_NIP} {DELIVERY_CITY}\nOn-site contact: {DELIVERY_CONTACT} {DELIVERY_CONTACT_PHONE}\n\nPlease make sure access and unloading are possible on that day. Project follow-up: {PROJECT_LINK}\n\nBest regards,\n{USER_NAME}",
        ],
        'de_DE' => [
            'subject' => 'Liefertermin - {PROJECT_NAME} ({PROJECT_NUMBER})',
            'message' => "Guten Tag {PRENOM},\n\ndie Lieferung Ihres Projekts {PROJECT_NAME} (Nr. {PROJECT_NUMBER}) ist geplant: {DELIVERY_DATE}.\n\nLieferadresse: {DELIVERY_ADRESS}, {DELIVERY_NIP} {DELIVERY_CITY}\nAnsprechperson vor Ort: {DELIVERY_CONTACT} {DELIVERY_CONTACT_PHONE}\n\nBitte stellen Sie sicher, dass Zufahrt und Abladen an diesem Tag möglich sind. Projektstand: {PROJECT_LINK}\n\nFreundliche Grüsse\n{USER_NAME}",
        ],
    ],

    // Livraison effectuée
    'ProductDelivered' => [
        'docs' => ['delivery_note', 'certificat_conformity', 'documentation'],
        'fr_FR' => [
            'subject' => 'Livraison effectuée - {PROJECT_NAME} ({PROJECT_NUMBER})',
            'message' => "Bonjour {PRENOM},\n\nVotre commande {PROJECT_NAME} (n° {PROJECT_NUMBER}) a été livrée.\n\nVous trouverez en pièces jointes les documents de livraison (bon de livraison, certificat de conformité, documentation) lorsqu'ils sont disponibles. Ils restent aussi accessibles sur la fiche du projet : {PROJECT_LINK}\n\nNous vous remercions de votre confiance.\n\nCordialement,\n{USER_NAME}",
        ],
        'en_US' => [
            'subject' => 'Delivery completed - {PROJECT_NAME} ({PROJECT_NUMBER})',
            'message' => "Hello {PRENOM},\n\nYour order {PROJECT_NAME} (no. {PROJECT_NUMBER}) has been delivered.\n\nAttached are the delivery documents (delivery note, certificate of conformity, documentation) where available. They are also available on the project page: {PROJECT_LINK}\n\nThank you for your trust.\n\nBest regards,\n{USER_NAME}",
        ],
        'de_DE' => [
            'subject' => 'Lieferung erfolgt - {PROJECT_NAME} ({PROJECT_NUMBER})',
            'message' => "Guten Tag {PRENOM},\n\nIhre Bestellung {PROJECT_NAME} (Nr. {PROJECT_NUMBER}) wurde geliefert.\n\nIm Anhang finden Sie, soweit vorhanden, die Lieferdokumente (Lieferschein, Konformitätserklärung, Dokumentation). Sie sind auch auf der Projektseite abrufbar: {PROJECT_LINK}\n\nWir danken Ihnen für Ihr Vertrauen.\n\nFreundliche Grüsse\n{USER_NAME}",
        ],
    ],

    // Contrôle des conditions de déchargement
    'UnloadingFacility' => [
        'docs' => [],
        'fr_FR' => [
            'subject' => 'Conditions de déchargement - {PROJECT_NAME} ({PROJECT_NUMBER})',
            'message' => "Bonjour {PRENOM},\n\nAfin de préparer au mieux la livraison de votre projet {PROJECT_NAME} (n° {PROJECT_NUMBER}), merci de nous confirmer les conditions de déchargement sur place : accès, largeur des couloirs et des portes, moyen de levage, local, personne de contact.\n\nVous pouvez compléter ces informations directement sur la fiche du projet : {PROJECT_LINK}\n\nAdresse de livraison enregistrée : {DELIVERY_ADRESS}, {DELIVERY_NIP} {DELIVERY_CITY}\n\nCordialement,\n{USER_NAME}",
        ],
        'en_US' => [
            'subject' => 'Unloading conditions - {PROJECT_NAME} ({PROJECT_NUMBER})',
            'message' => "Hello {PRENOM},\n\nTo prepare the delivery of your project {PROJECT_NAME} (no. {PROJECT_NUMBER}) as well as possible, please confirm the unloading conditions on site: access, corridor and door widths, lifting equipment, room, contact person.\n\nYou can fill in this information directly on the project page: {PROJECT_LINK}\n\nDelivery address on file: {DELIVERY_ADRESS}, {DELIVERY_NIP} {DELIVERY_CITY}\n\nBest regards,\n{USER_NAME}",
        ],
        'de_DE' => [
            'subject' => 'Abladebedingungen - {PROJECT_NAME} ({PROJECT_NUMBER})',
            'message' => "Guten Tag {PRENOM},\n\num die Lieferung Ihres Projekts {PROJECT_NAME} (Nr. {PROJECT_NUMBER}) optimal vorzubereiten, bitten wir Sie, die Abladebedingungen vor Ort zu bestätigen: Zufahrt, Breite von Gängen und Türen, Hebezeug, Raum, Kontaktperson.\n\nSie können diese Angaben direkt auf der Projektseite ergänzen: {PROJECT_LINK}\n\nHinterlegte Lieferadresse: {DELIVERY_ADRESS}, {DELIVERY_NIP} {DELIVERY_CITY}\n\nFreundliche Grüsse\n{USER_NAME}",
        ],
    ],

    // Enquête de satisfaction
    'SatisfactionSurvey' => [
        'docs' => [],
        'fr_FR' => [
            'subject' => 'Votre avis nous intéresse - {PROJECT_NAME}',
            'message' => "Bonjour {PRENOM},\n\nVotre projet {PROJECT_NAME} (n° {PROJECT_NUMBER}) est maintenant terminé. Nous espérons que tout s'est bien passé.\n\nPour continuer à nous améliorer, pourriez-vous prendre 2 minutes pour répondre à notre enquête de satisfaction : {SURVEY_LINK}\n\nUn grand merci pour votre confiance.\n\nCordialement,\n{USER_NAME}",
        ],
        'en_US' => [
            'subject' => 'We value your feedback - {PROJECT_NAME}',
            'message' => "Hello {PRENOM},\n\nYour project {PROJECT_NAME} (no. {PROJECT_NUMBER}) is now complete. We hope everything went well.\n\nTo keep improving, could you take 2 minutes to answer our satisfaction survey: {SURVEY_LINK}\n\nThank you very much for your trust.\n\nBest regards,\n{USER_NAME}",
        ],
        'de_DE' => [
            'subject' => 'Ihre Meinung ist uns wichtig - {PROJECT_NAME}',
            'message' => "Guten Tag {PRENOM},\n\nIhr Projekt {PROJECT_NAME} (Nr. {PROJECT_NUMBER}) ist nun abgeschlossen. Wir hoffen, es hat alles gut geklappt.\n\nUm uns weiter zu verbessern, nehmen Sie sich bitte 2 Minuten Zeit für unsere Zufriedenheitsumfrage: {SURVEY_LINK}\n\nHerzlichen Dank für Ihr Vertrauen.\n\nFreundliche Grüsse\n{USER_NAME}",
        ],
    ],

    // Relance : plans en attente de signature (envoyée automatiquement après 14 jours)
    'reviveProjectSign' => [
        'docs' => ['last_drawing'],
        'fr_FR' => [
            'subject' => 'Rappel : plans en attente de validation - {PROJECT_NAME} ({PROJECT_NUMBER})',
            'message' => "Bonjour {PRENOM},\n\nSauf erreur de notre part, les plans de votre projet {PROJECT_NAME} (n° {PROJECT_NUMBER}) sont toujours en attente de validation. Ils sont de nouveau joints à ce message.\n\nLa fabrication ne peut pas démarrer tant qu'ils ne sont pas validés. Merci de nous les retourner signés ou de nous indiquer les modifications souhaitées : {PROJECT_LINK}\n\nCordialement,\n{USER_NAME}",
        ],
        'en_US' => [
            'subject' => 'Reminder: drawings awaiting approval - {PROJECT_NAME} ({PROJECT_NUMBER})',
            'message' => "Hello {PRENOM},\n\nUnless we are mistaken, the drawings for your project {PROJECT_NAME} (no. {PROJECT_NUMBER}) are still awaiting your approval. They are attached again.\n\nManufacturing cannot start until they are approved. Please return them signed or tell us which changes you need: {PROJECT_LINK}\n\nBest regards,\n{USER_NAME}",
        ],
        'de_DE' => [
            'subject' => 'Erinnerung: Pläne warten auf Freigabe - {PROJECT_NAME} ({PROJECT_NUMBER})',
            'message' => "Guten Tag {PRENOM},\n\nsoweit wir sehen, warten die Pläne für Ihr Projekt {PROJECT_NAME} (Nr. {PROJECT_NUMBER}) noch auf Ihre Freigabe. Sie sind erneut angehängt.\n\nOhne Freigabe kann die Fertigung nicht beginnen. Bitte senden Sie die Pläne unterzeichnet zurück oder teilen Sie uns die gewünschten Änderungen mit: {PROJECT_LINK}\n\nFreundliche Grüsse\n{USER_NAME}",
        ],
    ],
];
