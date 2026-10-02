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

    // Commande enregistrée (offre transformée en commande) : remerciement + prochaines étapes.
    // {IF_DRAWINGS}…{/IF_DRAWINGS} : gardé seulement si la commande contient un article de type 1 (réservoir spécial, donc des plans) ;
    // {IF_NO_DRAWINGS}…{/IF_NO_DRAWINGS} : gardé seulement s'il n'y en a pas.
    'CmdViag' => [
        'docs' => [],
        'fr_FR' => [
            'subject' => 'Confirmation de votre commande - {PROJECT_NAME}',
            'message' => "Bonjour {PRENOM},\n\nJ'ai bien réceptionné votre commande pour le projet {PROJECT_NAME}.\n\nJe vous remercie de la confiance que vous m'accordez.\n\nVous pouvez suivre l'état de votre commande grâce au lien suivant : {PROJECT_LINK}\n\n{IF_DRAWINGS}Je vais prochainement vous envoyer le(s) plan(s) de fabrication des réservoirs.\n\nVous serez invité à les contrôler et à les valider avant que nous ne commencions la production.\n\nAu besoin, vous pourrez bien sûr apporter vos modifications sur les plans.{/IF_DRAWINGS}{IF_NO_DRAWINGS}Je reviens vers vous prochainement avec la date de livraison prévue.{/IF_NO_DRAWINGS}\n\nMerci et belle journée.\n\nCordialement,\n{USER_NAME}",
            'legacy_messages' => ["Bonjour {PRENOM},\n\nNous avons bien enregistré votre commande pour le projet {PROJECT_NAME} (n° {PROJECT_NUMBER}).\n\n{PRODUCT_LIST}\n\nVous pouvez suivre l'avancement de votre projet à tout moment : {PROJECT_LINK}\n\nNous reviendrons vers vous avec la suite du déroulement (plans, délai de livraison).\n\nCordialement,\n{USER_NAME}"],
        ],
        'en_US' => [
            'subject' => 'Order confirmation - {PROJECT_NAME}',
            'message' => "Hello {PRENOM},\n\nI have received your order for the project {PROJECT_NAME}.\n\nThank you for your trust.\n\nYou can follow the status of your order using the following link: {PROJECT_LINK}\n\n{IF_DRAWINGS}I will shortly send you the manufacturing drawing(s) of the tanks.\n\nYou will be invited to check and approve them before we start production.\n\nIf needed, you can of course request changes to the drawings.{/IF_DRAWINGS}{IF_NO_DRAWINGS}I will get back to you shortly with the planned delivery date.{/IF_NO_DRAWINGS}\n\nThank you and have a nice day.\n\nBest regards,\n{USER_NAME}",
            'legacy_messages' => ["Hello {PRENOM},\n\nWe have registered your order for the project {PROJECT_NAME} (no. {PROJECT_NUMBER}).\n\n{PRODUCT_LIST}\n\nYou can follow the progress of your project at any time: {PROJECT_LINK}\n\nWe will get back to you with the next steps (drawings, delivery time).\n\nBest regards,\n{USER_NAME}"],
        ],
        'de_DE' => [
            'subject' => 'Auftragsbestätigung - {PROJECT_NAME}',
            'message' => "Guten Tag {PRENOM},\n\nich habe Ihre Bestellung für das Projekt {PROJECT_NAME} erhalten.\n\nVielen Dank für Ihr Vertrauen.\n\nDen Stand Ihrer Bestellung können Sie über folgenden Link verfolgen: {PROJECT_LINK}\n\n{IF_DRAWINGS}Ich sende Ihnen in Kürze die Fertigungspläne der Tanks.\n\nSie werden gebeten, diese zu prüfen und freizugeben, bevor wir mit der Produktion beginnen.\n\nBei Bedarf können Sie selbstverständlich Änderungen an den Plänen vornehmen.{/IF_DRAWINGS}{IF_NO_DRAWINGS}Ich melde mich in Kürze mit dem geplanten Liefertermin.{/IF_NO_DRAWINGS}\n\nVielen Dank und einen schönen Tag.\n\nFreundliche Grüsse\n{USER_NAME}",
            'legacy_messages' => ["Guten Tag {PRENOM},\n\nwir haben Ihre Bestellung für das Projekt {PROJECT_NAME} (Nr. {PROJECT_NUMBER}) erfasst.\n\n{PRODUCT_LIST}\n\nDen Projektstand können Sie jederzeit verfolgen: {PROJECT_LINK}\n\nWir melden uns mit den nächsten Schritten (Pläne, Lieferzeit).\n\nFreundliche Grüsse\n{USER_NAME}"],
        ],
    ],

    // Plans envoyés au client pour validation. {RETURN_DATE} = date limite de retour (5 jours ouvrables).
    'EnvoiePlanClient' => [
        'docs' => ['last_drawing'],
        'fr_FR' => [
            'subject' => 'Plans à valider - {PROJECT_NAME}',
            'message' => "Bonjour {PRENOM},\n\nLes plans de la / des cuve(s) sont désormais disponibles sur notre plateforme en ligne pour validation. Ils sont également joints à ce message.\n\nVous pouvez retrouver tous les plans et suivre l'état de votre commande grâce au lien suivant : {PROJECT_LINK}\n\nMerci de nous retourner les plans validés, ou vos remarques, d'ici le {RETURN_DATE} (sous {RETURN_DAYS} jours ouvrables).\n\nSi des ajustements sont nécessaires, vous pouvez nous les transmettre directement via la plateforme ou par retour de mail. Selon leur nature, des plus-values ou moins-values pourront être appliquées.\n\nPour rappel, le délai de livraison sera fixé dès validation des plans.\n\nMerci et belle journée.\n\nCordialement,\n{USER_NAME}",
            'legacy_messages' => ["Bonjour {PRENOM},\n\nVous trouverez en pièce jointe le ou les plans de votre projet {PROJECT_NAME} (n° {PROJECT_NUMBER}).\n\nMerci de les contrôler et de nous les retourner validés, ou de nous indiquer les modifications souhaitées : {PROJECT_LINK}\n\nLa fabrication ne pourra démarrer qu'après votre validation.\n\nCordialement,\n{USER_NAME}", "Bonjour {PRENOM},\n\nLes plans de la / des cuve(s) sont désormais disponibles sur notre plateforme en ligne pour validation. Ils sont également joints à ce message.\n\nVous pouvez retrouver tous les plans et suivre l'état de votre commande grâce au lien suivant : {PROJECT_LINK}\n\nMerci de nous retourner les plans validés, ou vos remarques, d'ici le {RETURN_DATE} (sous 5 jours ouvrables).\n\nSi des ajustements sont nécessaires, vous pouvez nous les transmettre directement via la plateforme ou par retour de mail. Selon leur nature, des plus-values ou moins-values pourront être appliquées.\n\nPour rappel, le délai de livraison sera fixé dès validation des plans.\n\nMerci et belle journée.\n\nCordialement,\n{USER_NAME}"],
        ],
        'en_US' => [
            'subject' => 'Drawings to approve - {PROJECT_NAME}',
            'message' => "Hello {PRENOM},\n\nThe drawings of the tank(s) are now available for approval on our online platform. They are also attached to this message.\n\nYou can find all the drawings and follow the status of your order using the following link: {PROJECT_LINK}\n\nPlease return the approved drawings, or your comments, by {RETURN_DATE} (within {RETURN_DAYS} working days).\n\nIf adjustments are needed, you can send them to us directly through the platform or by reply e-mail. Depending on their nature, additional charges or credits may apply.\n\nAs a reminder, the delivery time will be set as soon as the drawings are approved.\n\nThank you and have a nice day.\n\nBest regards,\n{USER_NAME}",
            'legacy_messages' => ["Hello {PRENOM},\n\nPlease find attached the drawing(s) for your project {PROJECT_NAME} (no. {PROJECT_NUMBER}).\n\nPlease check them and return them approved, or tell us which changes you need: {PROJECT_LINK}\n\nManufacturing can only start once you have approved the drawings.\n\nBest regards,\n{USER_NAME}", "Hello {PRENOM},\n\nThe drawings of the tank(s) are now available for approval on our online platform. They are also attached to this message.\n\nYou can find all the drawings and follow the status of your order using the following link: {PROJECT_LINK}\n\nPlease return the approved drawings, or your comments, by {RETURN_DATE} (within 5 working days).\n\nIf adjustments are needed, you can send them to us directly through the platform or by reply e-mail. Depending on their nature, additional charges or credits may apply.\n\nAs a reminder, the delivery time will be set as soon as the drawings are approved.\n\nThank you and have a nice day.\n\nBest regards,\n{USER_NAME}"],
        ],
        'de_DE' => [
            'subject' => 'Pläne zur Freigabe - {PROJECT_NAME}',
            'message' => "Guten Tag {PRENOM},\n\ndie Pläne des Tanks / der Tanks stehen ab sofort auf unserer Online-Plattform zur Freigabe bereit. Sie sind dieser Nachricht auch beigefügt.\n\nAlle Pläne und den Stand Ihrer Bestellung finden Sie unter folgendem Link: {PROJECT_LINK}\n\nBitte senden Sie uns die freigegebenen Pläne oder Ihre Anmerkungen bis zum {RETURN_DATE} zurück (innerhalb von {RETURN_DAYS} Arbeitstagen).\n\nSind Anpassungen nötig, können Sie uns diese direkt über die Plattform oder per Antwort-E-Mail mitteilen. Je nach Art der Änderung können Mehr- oder Minderkosten anfallen.\n\nZur Erinnerung: Der Liefertermin wird festgelegt, sobald die Pläne freigegeben sind.\n\nVielen Dank und einen schönen Tag.\n\nFreundliche Grüsse\n{USER_NAME}",
            'legacy_messages' => ["Guten Tag {PRENOM},\n\nim Anhang finden Sie die Pläne für Ihr Projekt {PROJECT_NAME} (Nr. {PROJECT_NUMBER}).\n\nBitte prüfen Sie diese und senden Sie sie freigegeben zurück oder teilen Sie uns die gewünschten Änderungen mit: {PROJECT_LINK}\n\nDie Fertigung kann erst nach Ihrer Freigabe beginnen.\n\nFreundliche Grüsse\n{USER_NAME}", "Guten Tag {PRENOM},\n\ndie Pläne des Tanks / der Tanks stehen ab sofort auf unserer Online-Plattform zur Freigabe bereit. Sie sind dieser Nachricht auch beigefügt.\n\nAlle Pläne und den Stand Ihrer Bestellung finden Sie unter folgendem Link: {PROJECT_LINK}\n\nBitte senden Sie uns die freigegebenen Pläne oder Ihre Anmerkungen bis zum {RETURN_DATE} zurück (innerhalb von 5 Arbeitstagen).\n\nSind Anpassungen nötig, können Sie uns diese direkt über die Plattform oder per Antwort-E-Mail mitteilen. Je nach Art der Änderung können Mehr- oder Minderkosten anfallen.\n\nZur Erinnerung: Der Liefertermin wird festgelegt, sobald die Pläne freigegeben sind.\n\nVielen Dank und einen schönen Tag.\n\nFreundliche Grüsse\n{USER_NAME}"],
        ],
    ],

    // Plans validés par le client (étape automatique) : merci, démarrage de la fabrication, délai de livraison à venir.
    // Le plan validé est joint (drawingApproval). Signature : chef de projet si le plan est validé par le client.
    'SignaturePlan' => [
        'docs' => ['drawingApproval'],
        'fr_FR' => [
            'subject' => 'Plans validés - {PROJECT_NAME}',
            'message' => "Bonjour {PRENOM},\n\nJe vous confirme la bonne réception de la validation des plans de votre projet {PROJECT_NAME} (commande n° {PROJECT_NUMBER}). Le plan validé est joint à ce message. Je vous remercie de votre réactivité.\n\nNous lançons dès maintenant la fabrication. Je reviens vers vous très prochainement avec le délai de livraison, maintenant que les plans sont validés.\n\nVous pouvez suivre l'avancement de votre commande à tout moment : {PROJECT_LINK}\n\nMerci et belle journée.\n\nCordialement,\n{USER_NAME}",
            'legacy_messages' => ["Bonjour {PRENOM},\n\nNous avons bien reçu la validation des plans de votre projet {PROJECT_NAME} (n° {PROJECT_NUMBER}). Le plan validé est joint à ce message.\n\nNous lançons la fabrication et vous communiquerons la date de livraison dès qu'elle sera confirmée.\n\nSuivi du projet : {PROJECT_LINK}\n\nCordialement,\n{USER_NAME}"],
        ],
        'en_US' => [
            'subject' => 'Drawings approved - {PROJECT_NAME}',
            'message' => "Hello {PRENOM},\n\nI confirm that we have received the approval of the drawings for your project {PROJECT_NAME} (order no. {PROJECT_NUMBER}). The approved drawing is attached to this message. Thank you for your prompt response.\n\nWe are starting manufacturing right away. I will get back to you very soon with the delivery time, now that the drawings are approved.\n\nYou can follow the progress of your order at any time: {PROJECT_LINK}\n\nThank you and have a nice day.\n\nBest regards,\n{USER_NAME}",
            'legacy_messages' => ["Hello {PRENOM},\n\nWe have received your approval of the drawings for the project {PROJECT_NAME} (no. {PROJECT_NUMBER}). The approved drawing is attached.\n\nWe are starting manufacturing and will let you know the delivery date as soon as it is confirmed.\n\nProject follow-up: {PROJECT_LINK}\n\nBest regards,\n{USER_NAME}"],
        ],
        'de_DE' => [
            'subject' => 'Pläne freigegeben - {PROJECT_NAME}',
            'message' => "Guten Tag {PRENOM},\n\nich bestätige den Eingang der Planfreigabe für Ihr Projekt {PROJECT_NAME} (Bestellung Nr. {PROJECT_NUMBER}). Der freigegebene Plan ist dieser Nachricht beigefügt. Vielen Dank für Ihre schnelle Rückmeldung.\n\nWir starten ab sofort mit der Fertigung. Da die Pläne nun freigegeben sind, melde ich mich in Kürze mit dem Liefertermin.\n\nDen Stand Ihrer Bestellung können Sie jederzeit verfolgen: {PROJECT_LINK}\n\nVielen Dank und einen schönen Tag.\n\nFreundliche Grüsse\n{USER_NAME}",
            'legacy_messages' => ["Guten Tag {PRENOM},\n\nwir haben Ihre Freigabe der Pläne für das Projekt {PROJECT_NAME} (Nr. {PROJECT_NUMBER}) erhalten. Der freigegebene Plan ist angehängt.\n\nWir starten die Fertigung und teilen Ihnen den Liefertermin mit, sobald er bestätigt ist.\n\nProjektstand: {PROJECT_LINK}\n\nFreundliche Grüsse\n{USER_NAME}"],
        ],
    ],

    // Dates de livraison communiquées : articles non livrés qui ont une date de livraison ({DELIVERY_LIST}).
    'DateLivraisonCuve' => [
        'docs' => [],
        'fr_FR' => [
            'subject' => 'Dates de livraison - {PROJECT_NAME}',
            'message' => "Bonjour {PRENOM},\n\nJe suis ravi de vous informer que les dates de livraison pour le projet {PROJECT_NAME} ont été définies.\n\nLes dates de départ de l'usine sont les suivantes :\n\n{DELIVERY_LIST}\n\nVous pouvez suivre l'état de votre commande grâce au lien suivant : {PROJECT_LINK}\n\nImportant : nous faisons notre maximum pour respecter ces délais. Toutefois, la date finale peut varier selon les formalités douanières et l'état du réseau routier.\n\nPour information : comme convenu, le chauffeur devrait vous prévenir avant de livrer.\n\nMerci et belle journée.\n\nCordialement,\n{USER_NAME}",
            'legacy_messages' => ["Bonjour {PRENOM},\n\nLa livraison de votre projet {PROJECT_NAME} (n° {PROJECT_NUMBER}) est prévue : {DELIVERY_DATE}.\n\nLieu de livraison : {DELIVERY_ADRESS}, {DELIVERY_NIP} {DELIVERY_CITY}\nContact sur place : {DELIVERY_CONTACT} {DELIVERY_CONTACT_PHONE}\n\nMerci de vérifier que l'accès et le déchargement sont possibles ce jour-là. Suivi du projet : {PROJECT_LINK}\n\nCordialement,\n{USER_NAME}"],
        ],
        'en_US' => [
            'subject' => 'Delivery dates - {PROJECT_NAME}',
            'message' => "Hello {PRENOM},\n\nI am pleased to inform you that the delivery dates for the project {PROJECT_NAME} have been set.\n\nThe departure dates from the factory are as follows:\n\n{DELIVERY_LIST}\n\nYou can follow the status of your order using the following link: {PROJECT_LINK}\n\nImportant: we are doing our best to meet these dates. However, the final date may vary depending on customs formalities and road conditions.\n\nFor your information: as agreed, the driver should call you before delivering.\n\nThank you and have a nice day.\n\nBest regards,\n{USER_NAME}",
            'legacy_messages' => ["Hello {PRENOM},\n\nDelivery of your project {PROJECT_NAME} (no. {PROJECT_NUMBER}) is planned: {DELIVERY_DATE}.\n\nDelivery address: {DELIVERY_ADRESS}, {DELIVERY_NIP} {DELIVERY_CITY}\nOn-site contact: {DELIVERY_CONTACT} {DELIVERY_CONTACT_PHONE}\n\nPlease make sure access and unloading are possible on that day. Project follow-up: {PROJECT_LINK}\n\nBest regards,\n{USER_NAME}"],
        ],
        'de_DE' => [
            'subject' => 'Liefertermine - {PROJECT_NAME}',
            'message' => "Guten Tag {PRENOM},\n\nich freue mich, Ihnen mitzuteilen, dass die Liefertermine für das Projekt {PROJECT_NAME} festgelegt wurden.\n\nDie Abgangstermine ab Werk sind wie folgt:\n\n{DELIVERY_LIST}\n\nDen Stand Ihrer Bestellung können Sie über folgenden Link verfolgen: {PROJECT_LINK}\n\nWichtig: Wir tun unser Möglichstes, diese Termine einzuhalten. Das endgültige Datum kann jedoch je nach Zollformalitäten und Verkehrslage abweichen.\n\nZur Information: Wie vereinbart sollte der Fahrer Sie vor der Lieferung anrufen.\n\nVielen Dank und einen schönen Tag.\n\nFreundliche Grüsse\n{USER_NAME}",
            'legacy_messages' => ["Guten Tag {PRENOM},\n\ndie Lieferung Ihres Projekts {PROJECT_NAME} (Nr. {PROJECT_NUMBER}) ist geplant: {DELIVERY_DATE}.\n\nLieferadresse: {DELIVERY_ADRESS}, {DELIVERY_NIP} {DELIVERY_CITY}\nAnsprechperson vor Ort: {DELIVERY_CONTACT} {DELIVERY_CONTACT_PHONE}\n\nBitte stellen Sie sicher, dass Zufahrt und Abladen an diesem Tag möglich sind. Projektstand: {PROJECT_LINK}\n\nFreundliche Grüsse\n{USER_NAME}"],
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

    // Relance : plans en attente de validation (étapes réglées dans ISPAG Settings → Plan reminders).
    // {RETURN_DATE} = nouvelle date limite = prochaine échéance de relance ; signature = chef de projet.
    'reviveProjectSign' => [
        'docs' => ['last_drawing'],
        'fr_FR' => [
            'subject' => 'Rappel : plans à valider - {PROJECT_NAME}',
            'message' => "Bonjour {PRENOM},\n\nIl y a quelques jours, nous vous avons envoyé les plans des cuves pour le projet {PROJECT_NAME}. Ils sont de nouveau joints à ce message.\n\nVous ne les avez toujours pas validés et le délai de livraison se rallonge. Merci de nous les retourner au plus vite, au plus tard le {RETURN_DATE}.\n\nNuméro de commande : {PROJECT_NUMBER}\nDate de commande : {ORDER_DATE}\nSuivre le projet : {PROJECT_LINK}\n\nAdresse de livraison :\n{DELIVERY_ADRESS}\n{DELIVERY_NIP} {DELIVERY_CITY}\n{DELIVERY_CONTACT}\n\nL'adresse de livraison ci-dessus est celle prévue actuellement. Si celle-ci n'est pas exacte, merci de nous communiquer rapidement la bonne.\n\nJe me permets de rappeler que le délai de livraison sera fixé une fois les plans de fabrication validés.\n\nCordialement,\n{USER_NAME}",
            'legacy_messages' => ["Bonjour {PRENOM},\n\nSauf erreur de notre part, les plans de votre projet {PROJECT_NAME} (n° {PROJECT_NUMBER}) sont toujours en attente de validation. Ils sont de nouveau joints à ce message.\n\nLa fabrication ne peut pas démarrer tant qu'ils ne sont pas validés. Merci de nous les retourner signés ou de nous indiquer les modifications souhaitées : {PROJECT_LINK}\n\nCordialement,\n{USER_NAME}"],
        ],
        'en_US' => [
            'subject' => 'Reminder: drawings to approve - {PROJECT_NAME}',
            'message' => "Hello {PRENOM},\n\nA few days ago we sent you the tank drawings for the project {PROJECT_NAME}. They are attached again to this message.\n\nYou have still not approved them and the delivery time is getting longer. Please return them as soon as possible, and no later than {RETURN_DATE}.\n\nOrder number: {PROJECT_NUMBER}\nOrder date: {ORDER_DATE}\nFollow the project: {PROJECT_LINK}\n\nDelivery address:\n{DELIVERY_ADRESS}\n{DELIVERY_NIP} {DELIVERY_CITY}\n{DELIVERY_CONTACT}\n\nThe delivery address above is the one currently planned. If it is not correct, please let us know the right one as soon as possible.\n\nAs a reminder, the delivery time will be set once the manufacturing drawings are approved.\n\nBest regards,\n{USER_NAME}",
            'legacy_messages' => ["Hello {PRENOM},\n\nUnless we are mistaken, the drawings for your project {PROJECT_NAME} (no. {PROJECT_NUMBER}) are still awaiting your approval. They are attached again.\n\nManufacturing cannot start until they are approved. Please return them signed or tell us which changes you need: {PROJECT_LINK}\n\nBest regards,\n{USER_NAME}"],
        ],
        'de_DE' => [
            'subject' => 'Erinnerung: Pläne zur Freigabe - {PROJECT_NAME}',
            'message' => "Guten Tag {PRENOM},\n\nvor einigen Tagen haben wir Ihnen die Tankpläne für das Projekt {PROJECT_NAME} gesendet. Sie sind dieser Nachricht erneut beigefügt.\n\nSie haben die Pläne noch nicht freigegeben, und die Lieferzeit verlängert sich. Bitte senden Sie sie uns so bald wie möglich zurück, spätestens bis zum {RETURN_DATE}.\n\nBestellnummer: {PROJECT_NUMBER}\nBestelldatum: {ORDER_DATE}\nProjekt verfolgen: {PROJECT_LINK}\n\nLieferadresse:\n{DELIVERY_ADRESS}\n{DELIVERY_NIP} {DELIVERY_CITY}\n{DELIVERY_CONTACT}\n\nDie oben genannte Lieferadresse ist die aktuell vorgesehene. Sollte sie nicht stimmen, teilen Sie uns bitte rasch die richtige mit.\n\nZur Erinnerung: Der Liefertermin wird festgelegt, sobald die Fertigungspläne freigegeben sind.\n\nFreundliche Grüsse\n{USER_NAME}",
            'legacy_messages' => ["Guten Tag {PRENOM},\n\nsoweit wir sehen, warten die Pläne für Ihr Projekt {PROJECT_NAME} (Nr. {PROJECT_NUMBER}) noch auf Ihre Freigabe. Sie sind erneut angehängt.\n\nOhne Freigabe kann die Fertigung nicht beginnen. Bitte senden Sie die Pläne unterzeichnet zurück oder teilen Sie uns die gewünschten Änderungen mit: {PROJECT_LINK}\n\nFreundliche Grüsse\n{USER_NAME}"],
        ],
    ],
];
