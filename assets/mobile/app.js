/* Application ISPAG (PWA) : projets consultables et livraisons signées, y compris hors ligne.
 * - Données : IndexedDB (instantané des projets + file d'attente des livraisons à envoyer).
 * - Jeton d'appareil : localStorage. Les prix ne sont lus qu'en ligne (route /prices, droit display_sales_prices) et ne sont jamais stockés sur le téléphone.
 */
(function () {
  'use strict';
  const CFG = window.ISPAG_APP;

  // ------------------------------------------------------------------ textes
  const T = {
    fr: { coStatus: 'Statut', coActiveOn: 'Entreprise active', coInactive: 'Inactive', coActiveHint: 'Une entreprise inactive n’est plus considérée comme active (réservé aux responsables).', coDeactivate: 'Désactiver cette entreprise ?', coActivate: 'Réactiver cette entreprise ?', buySupplier: 'Fournisseur', moreTitle: 'Autres écrans', companiesTitle: 'Entreprises', noCompanies: 'Aucune entreprise.', groupedTitle: 'Relances des offres', groupedOn: 'Relance groupée (offres de chiffreurs)', groupedEvery: 'Tous les', groupedDays: 'jours', groupedSaved: 'Enregistré', groupedFail: 'Enregistrement impossible, réessayez.', groupedHint: 'Une seule tâche pour les offres demandées par un chiffreur ; les autres demandeurs restent relancés offre par offre.', coTitle: 'Société', coContacts: 'Contacts', coOffers: 'Offres', coProjects: 'Projets et commandes', coMap: 'Ouvrir dans Plans', coNone: 'Société introuvable.', stockTitle: 'Stock', stockAll: 'Tous', stockNone: 'Aucun article en stock.', buyTitle: 'Commandes fournisseurs', buyNone: 'Aucune commande.', buyLines: 'Articles commandés', buyReceived: 'reçu', buyDue: 'Livraison', buyConfirmed: 'confirmée', buyRemark: 'Remarque', onlineOnly: 'Connexion requise.', loadFail: 'Chargement impossible, réessayez.', dashTitle: 'Aujourd’hui', dashOver: 'Tâches en retard', dashToday: 'Tâches du jour', dashClosing: 'Offres à clôturer sous 14 jours', dashNothing: 'Rien d’urgent. 🎉', dashAll: 'Toutes les tâches', stageTitle: 'Étape de l’offre', stageChange: 'Changer l’étape', stageConfirm: 'Passer l’offre à « %1 » ?', stageReason: 'Motif de la perte', stageReasonMissing: 'Saisissez le motif de la perte.', stageDone: 'Étape modifiée', stageFail: 'Changement impossible, réessayez.', stageOnline: 'Connexion requise pour changer l’étape.', offerNote: 'Appel / note sur l’offre', dealFollow: 'Suivi de l’offre', dealNoFollow: 'Aucune activité ni tâche sur cette offre.', dealTasks: 'Tâches', dealOnline: 'Connexion requise pour le suivi complet.', runTitle: 'Traiter les tâches', runStart: 'Traiter les tâches', runOf: 'Tâche %1 sur %2', runPrev: 'Précédente', runSkip: 'Passer', runStop: 'Arrêter', runDoneBadge: '✓ Traitée', runEnd: 'Traitement terminé', runSummary: '%1 traitée(s), %2 reportée(s), %3 passée(s).', runBack: 'Retour aux tâches', runResume: 'Reprendre le traitement', runNone: 'Aucune tâche à traiter aujourd\'hui.', runRelated: 'Fiches liées', artTitle: 'Articles', artFull: 'Fiche complète (documents, livraison…)', artLoading: 'Chargement des articles…', artNone: 'Aucun article.', artOnline: 'Connexion requise pour afficher les articles.', pricesTotal: 'Total (hors variantes)', variantB: 'Variante', netPrice: 'net', snooze: 'Reporter', snoozeTo: 'Reporter la tâche à…', s_tomorrow: 'Demain', s_3d: 'Dans 3 jours', s_monday: 'Lundi prochain', s_1w: 'Dans 1 semaine', s_2w: 'Dans 2 semaines', s_pick: 'Choisir une date…', s_ok: 'Reporter', snoozed: 'Tâche reportée', taskDetail: 'Tâche', relatedOffer: 'Offre liée', relatedContact: 'Contact', dueLabel: 'Échéance', cancel: 'Annuler', taskGone: 'Tâche introuvable.', postponedTo: 'reportée au', general: 'Général', outcome: 'Résultat', when: 'Date et heure', o_connected: 'Joint', o_left_live_message: 'Message laissé en direct', o_left_voicemail: 'Message laissé sur la messagerie', o_no_answer: 'Pas de réponse', o_busy: 'Occupé', o_wrong_number: 'Mauvais numéro', o_scheduled: 'Planifié', o_completed: 'Effectué', o_rescheduled: 'Reporté', o_no_show: 'Absent', o_canceled: 'Annulé', pushNoDevice: 'Aucun appareil abonné pour ce compte : désactivez puis réactivez les notifications.', pushRefused: 'Le service de notification a refusé l’envoi :', bio: 'Sécurité', bioOn: 'Activer Face ID / Touch ID', bioOff: 'Désactiver le verrouillage', bioActive: 'Déverrouillage par Face ID / Touch ID activé ✓', bioUnlock: 'Déverrouiller', bioFail: 'Déverrouillage impossible, réessayez.', bioHint: 'L’application reste connectée ; Face ID / Touch ID est demandé à l’ouverture et après une minute en arrière-plan.', bioNone: 'Face ID / Touch ID n’est pas disponible sur cet appareil ou ce navigateur.', locked: 'Application verrouillée', usePass: 'Se reconnecter avec le mot de passe', notif: 'Notifications', notifOn: 'Activer les notifications', notifOff: 'Désactiver', notifTest: 'Envoyer une notification de test', notifActive: 'Notifications activées sur cet appareil ✓', notifNeedInstall: 'Pour recevoir des notifications sur iPhone : installez l’application sur l’écran d’accueil (Partager → Sur l’écran d’accueil), puis ouvrez-la depuis l’icône.', notifDenied: 'Notifications refusées : autorisez-les dans Réglages iOS → Notifications → ISPAG.', notifNoServer: 'Les notifications ne sont pas disponibles sur ce serveur.', notifOffline: 'Connexion nécessaire pour activer les notifications.', notifSent: 'Notification de test envoyée.', notifFail: 'Activation impossible, réessayez.', notifHint: 'Les notifications arrivent quand le téléphone a du réseau ; hors ligne, elles sont délivrées au retour de la connexion (jusqu’à 24 h).', notifBanner: '🔔 Recevoir les notifications sur ce téléphone', plan1: 'Plan', offer1: 'Offre', allP: 'Tout', onlyProjects: 'Projets', onlyOffers: 'Offres', articleDetail: 'Fiche de l’article', tank: 'Réservoir', planOk: 'Plan validé', planNo: 'Plan non validé', docsArticle: 'Plans, croquis, validations', conns: 'Piquages', t_type: 'Type', t_material: 'Matériau', t_support: 'Support', t_volume: 'Volume (L)', t_diameter: 'Diamètre (mm)', t_height: 'Hauteur (mm)', t_feet: 'Hauteur des pieds (mm)', t_clearance: 'Garde au sol (mm)', t_bottom: 'Hauteur du fond (mm)', t_pressure: 'Pression max. (bar)', t_test: 'Pression d’essai (bar)', t_temp: 'Température (°C)', t_ins: 'Isolation', t_ins_thick: 'Épaisseur (mm)', t_ins_cover: 'Revêtement', t_welding: 'Soudure par le client', t_comment: 'Remarque', c_dn: 'DN', c_h: 'Hauteur', c_angle: 'Angle', factory: 'Départ usine', eta: 'Livraison prévue', yes: 'Oui', manager: 'Chef de projet', ordered: 'Commande du', contactsP: 'Contacts du projet', siteInfo: 'Conditions sur site', docs: 'Documents', noDocs: 'Aucun document.', docOffline: 'Document non disponible hors ligne.', docSaved: 'disponible hors ligne', docsSaving: 'Documents enregistrés :', details: 'Détails', notReady: 'hors ligne : ouvrez l’app en ligne une fois', offers: 'Offres', contacts: 'Contacts', tasks: 'Tâches', noOffers: 'Aucune offre sur les 3 derniers mois.', noContacts: 'Aucun contact.', noTasks: 'Aucune tâche ouverte. 🎉', overdue: 'En retard', today: 'Aujourd’hui', later: 'À venir', newTask: 'Nouvelle tâche', taskTitle: 'Titre de la tâche', due: 'Échéance', saveTask: 'Enregistrer la tâche', taskSaved: 'Tâche enregistrée', taskDone: 'Tâche terminée', doneBtn: 'Terminer', newNote: 'Nouvelle note', teamPresent: 'Collègues ISPAG présents', noteType: 'Type', note: 'Note', call: 'Appel', meeting: 'Rendez-vous', email: 'E-mail', noteText: 'Résumé', saveNote: 'Enregistrer', noteSaved: 'Enregistré', noteMissing: 'Saisissez un texte.', activities: 'Dernières activités', noActs: 'Aucune activité récente.', callNow: 'Appeler', phone: 'Téléphone', mail: 'E-mail', func: 'Fonction', stage: 'Étape', closing: 'Clôture', created: 'Créée le', amount: 'Montant', offerContacts: 'Contacts de l’offre', task1: 'Tâche', noTitle: 'Saisissez un titre.', crmSynced: 'Contacts, tâches et offres du', login: 'Connexion', user: 'Identifiant ou e-mail', pass: 'Mot de passe', signin: 'Se connecter', badLogin: 'Identifiants incorrects.', noRight: "Ce compte n'a pas accès à l'application.",
      onlineRes: 'Résultats en ligne (projets clos compris)', closedB: 'Clos', savedOff: 'Gardé sur le téléphone', saveHint: 'Projet gardé sur ce téléphone pour préparer votre visite hors ligne.', unsave: 'Retirer du téléphone', searching: 'Recherche…', noRes: 'Aucun résultat en ligne.', needOnline: 'Ce projet n’est pas sur le téléphone : ouvrez-le une fois en ligne.', savedList: 'Projets gardés hors ligne', subArticles: 'Sous-articles', selAll: 'Tout cocher', deliverSel: 'Livrer la sélection', projects: 'Projets', search: 'Rechercher…', none: 'Aucun projet.', offline: 'Hors ligne', online: 'En ligne', synced: 'Données du', refresh: 'Actualiser', logout: 'Déconnexion',
      back: 'Retour', delivery: 'Livraison', address: 'Adresse de livraison', contact: 'Contact', customerRef: 'Réf. client', company: 'Client', articles: 'Articles', delivered: 'Livré', waiting: 'En attente de synchro',
      newDelivery: 'Nouvelle livraison', selectArticles: 'Articles livrés', receiver: 'Nom de la personne qui réceptionne', receiverPh: 'Prénom et nom', signature: 'Signature', signHere: 'Signez ici avec le doigt',
      clear: 'Effacer', confirm: 'Confirmer la livraison', nameMissing: 'Saisissez le nom.', signMissing: 'Signature manquante.', pickOne: 'Sélectionnez au moins un article.', saved: 'Livraison enregistrée',
      savedQueued: 'Livraison enregistrée sur le téléphone, elle sera envoyée dès que le réseau sera disponible.', sent: 'Livraison envoyée', queue: 'À envoyer', pending: 'élément(s) à envoyer',
      receipts: 'Livraisons signées', pdf: 'PDF', allDone: 'Tous les articles sont livrés.', sync: 'Synchronisation…', syncFail: "Synchronisation impossible pour l'instant.", expired: 'Session expirée, reconnectez-vous (vos livraisons en attente sont conservées).',
      refused: 'Refusée par le serveur', retry: 'Réessayer', discard: 'Supprimer', never: 'jamais', install: "Pour installer : bouton Partager de Safari, puis « Sur l'écran d'accueil ».", noData: 'Aucune donnée sur ce téléphone : connectez-vous une première fois avec le réseau.', qty: 'Qté' },
    de: { coStatus: 'Status', coActiveOn: 'Aktive Firma', coInactive: 'Inaktiv', coActiveHint: 'Eine inaktive Firma gilt nicht mehr als aktiv (nur für Verantwortliche).', coDeactivate: 'Diese Firma deaktivieren?', coActivate: 'Diese Firma reaktivieren?', buySupplier: 'Lieferant', moreTitle: 'Weitere Ansichten', companiesTitle: 'Firmen', noCompanies: 'Keine Firmen.', groupedTitle: 'Angebotsnachfassung', groupedOn: 'Gruppierte Nachfassung (Angebote von Kalkulatoren)', groupedEvery: 'Alle', groupedDays: 'Tage', groupedSaved: 'Gespeichert', groupedFail: 'Speichern nicht möglich, bitte erneut versuchen.', groupedHint: 'Eine einzige Aufgabe für von Kalkulatoren angefragte Angebote; andere Anfragende werden weiterhin pro Angebot nachgefasst.', coTitle: 'Firma', coContacts: 'Kontakte', coOffers: 'Angebote', coProjects: 'Projekte und Bestellungen', coMap: 'In Karten öffnen', coNone: 'Firma nicht gefunden.', stockTitle: 'Lager', stockAll: 'Alle', stockNone: 'Keine Artikel im Lager.', buyTitle: 'Lieferantenbestellungen', buyNone: 'Keine Bestellungen.', buyLines: 'Bestellte Artikel', buyReceived: 'erhalten', buyDue: 'Lieferung', buyConfirmed: 'bestätigt', buyRemark: 'Bemerkung', onlineOnly: 'Verbindung nötig.', loadFail: 'Laden nicht möglich, bitte erneut versuchen.', dashTitle: 'Heute', dashOver: 'Überfällige Aufgaben', dashToday: 'Aufgaben von heute', dashClosing: 'Angebote mit Abschluss in 14 Tagen', dashNothing: 'Nichts Dringendes. 🎉', dashAll: 'Alle Aufgaben', stageTitle: 'Phase des Angebots', stageChange: 'Phase ändern', stageConfirm: 'Angebot auf « %1 » setzen?', stageReason: 'Verlustgrund', stageReasonMissing: 'Bitte den Verlustgrund eingeben.', stageDone: 'Phase geändert', stageFail: 'Änderung nicht möglich, bitte erneut versuchen.', stageOnline: 'Zum Ändern der Phase ist eine Verbindung nötig.', offerNote: 'Anruf / Notiz zum Angebot', dealFollow: 'Verlauf des Angebots', dealNoFollow: 'Keine Aktivität oder Aufgabe zu diesem Angebot.', dealTasks: 'Aufgaben', dealOnline: 'Für den vollständigen Verlauf ist eine Verbindung nötig.', runTitle: 'Aufgaben bearbeiten', runStart: 'Aufgaben bearbeiten', runOf: 'Aufgabe %1 von %2', runPrev: 'Zurück', runSkip: 'Überspringen', runStop: 'Beenden', runDoneBadge: '✓ Erledigt', runEnd: 'Bearbeitung abgeschlossen', runSummary: '%1 erledigt, %2 verschoben, %3 übersprungen.', runBack: 'Zurück zu den Aufgaben', runResume: 'Bearbeitung fortsetzen', runNone: 'Heute keine Aufgaben zu bearbeiten.', runRelated: 'Verknüpfte Einträge', artTitle: 'Artikel', artFull: 'Vollständige Ansicht (Dokumente, Lieferung…)', artLoading: 'Artikel werden geladen…', artNone: 'Keine Artikel.', artOnline: 'Zum Anzeigen der Artikel ist eine Verbindung nötig.', pricesTotal: 'Total (ohne Varianten)', variantB: 'Variante', netPrice: 'netto', snooze: 'Verschieben', snoozeTo: 'Aufgabe verschieben auf…', s_tomorrow: 'Morgen', s_3d: 'In 3 Tagen', s_monday: 'Nächsten Montag', s_1w: 'In 1 Woche', s_2w: 'In 2 Wochen', s_pick: 'Datum wählen…', s_ok: 'Verschieben', snoozed: 'Aufgabe verschoben', taskDetail: 'Aufgabe', relatedOffer: 'Zugehöriges Angebot', relatedContact: 'Kontakt', dueLabel: 'Fällig', cancel: 'Abbrechen', taskGone: 'Aufgabe nicht gefunden.', postponedTo: 'verschoben auf', general: 'Allgemein', outcome: 'Ergebnis', when: 'Datum und Uhrzeit', o_connected: 'Erreicht', o_left_live_message: 'Nachricht persönlich hinterlassen', o_left_voicemail: 'Nachricht auf Mailbox hinterlassen', o_no_answer: 'Keine Antwort', o_busy: 'Besetzt', o_wrong_number: 'Falsche Nummer', o_scheduled: 'Geplant', o_completed: 'Durchgeführt', o_rescheduled: 'Verschoben', o_no_show: 'Nicht erschienen', o_canceled: 'Abgesagt', pushNoDevice: 'Kein Gerät für dieses Konto abonniert: Benachrichtigungen aus- und wieder einschalten.', pushRefused: 'Der Push-Dienst hat die Zustellung abgelehnt:', bio: 'Sicherheit', bioOn: 'Face ID / Touch ID aktivieren', bioOff: 'Sperre deaktivieren', bioActive: 'Entsperren mit Face ID / Touch ID aktiv ✓', bioUnlock: 'Entsperren', bioFail: 'Entsperren nicht möglich, bitte erneut versuchen.', bioHint: 'Die App bleibt angemeldet; Face ID / Touch ID wird beim Öffnen und nach einer Minute im Hintergrund verlangt.', bioNone: 'Face ID / Touch ID ist auf diesem Gerät oder Browser nicht verfügbar.', locked: 'App gesperrt', usePass: 'Mit Passwort neu anmelden', notif: 'Benachrichtigungen', notifOn: 'Benachrichtigungen aktivieren', notifOff: 'Deaktivieren', notifTest: 'Testbenachrichtigung senden', notifActive: 'Benachrichtigungen auf diesem Gerät aktiv ✓', notifNeedInstall: 'Für Benachrichtigungen auf dem iPhone: App zum Home-Bildschirm hinzufügen (Teilen → Zum Home-Bildschirm) und über das Symbol öffnen.', notifDenied: 'Benachrichtigungen abgelehnt: in den iOS-Einstellungen → Mitteilungen → ISPAG erlauben.', notifNoServer: 'Benachrichtigungen sind auf diesem Server nicht verfügbar.', notifOffline: 'Zum Aktivieren ist eine Verbindung nötig.', notifSent: 'Testbenachrichtigung gesendet.', notifFail: 'Aktivierung nicht möglich, bitte erneut versuchen.', notifHint: 'Benachrichtigungen kommen an, wenn das Telefon Netz hat; offline werden sie bei Rückkehr der Verbindung zugestellt (bis 24 h).', notifBanner: '🔔 Benachrichtigungen auf diesem Telefon erhalten', plan1: 'Plan', offer1: 'Angebot', allP: 'Alle', onlyProjects: 'Projekte', onlyOffers: 'Angebote', articleDetail: 'Artikeldetails', tank: 'Behälter', planOk: 'Plan freigegeben', planNo: 'Plan nicht freigegeben', docsArticle: 'Pläne, Skizzen, Freigaben', conns: 'Anschlüsse', t_type: 'Typ', t_material: 'Material', t_support: 'Auflager', t_volume: 'Volumen (L)', t_diameter: 'Durchmesser (mm)', t_height: 'Höhe (mm)', t_feet: 'Fusshöhe (mm)', t_clearance: 'Bodenfreiheit (mm)', t_bottom: 'Bodenhöhe (mm)', t_pressure: 'Max. Druck (bar)', t_test: 'Prüfdruck (bar)', t_temp: 'Temperatur (°C)', t_ins: 'Isolation', t_ins_thick: 'Dicke (mm)', t_ins_cover: 'Verkleidung', t_welding: 'Schweissen durch Kunden', t_comment: 'Bemerkung', c_dn: 'DN', c_h: 'Höhe', c_angle: 'Winkel', factory: 'Abgang Werk', eta: 'Lieferung geplant', yes: 'Ja', manager: 'Projektleiter', ordered: 'Bestellt am', contactsP: 'Projektkontakte', siteInfo: 'Bedingungen vor Ort', docs: 'Dokumente', noDocs: 'Keine Dokumente.', docOffline: 'Dokument offline nicht verfügbar.', docSaved: 'offline verfügbar', docsSaving: 'Dokumente gespeichert:', details: 'Details', notReady: 'offline: App einmal online öffnen', offers: 'Angebote', contacts: 'Kontakte', tasks: 'Aufgaben', noOffers: 'Keine Angebote in den letzten 3 Monaten.', noContacts: 'Keine Kontakte.', noTasks: 'Keine offenen Aufgaben. 🎉', overdue: 'Überfällig', today: 'Heute', later: 'Demnächst', newTask: 'Neue Aufgabe', taskTitle: 'Titel der Aufgabe', due: 'Fällig', saveTask: 'Aufgabe speichern', taskSaved: 'Aufgabe gespeichert', taskDone: 'Aufgabe erledigt', doneBtn: 'Erledigen', newNote: 'Neue Notiz', teamPresent: 'Anwesende ISPAG-Kollegen', noteType: 'Art', note: 'Notiz', call: 'Anruf', meeting: 'Termin', email: 'E-Mail', noteText: 'Zusammenfassung', saveNote: 'Speichern', noteSaved: 'Gespeichert', noteMissing: 'Bitte Text eingeben.', activities: 'Letzte Aktivitäten', noActs: 'Keine aktuelle Aktivität.', callNow: 'Anrufen', phone: 'Telefon', mail: 'E-Mail', func: 'Funktion', stage: 'Phase', closing: 'Abschluss', created: 'Erstellt am', amount: 'Betrag', offerContacts: 'Kontakte des Angebots', task1: 'Aufgabe', noTitle: 'Bitte Titel eingeben.', crmSynced: 'Kontakte, Aufgaben und Angebote vom', login: 'Anmeldung', user: 'Benutzername oder E-Mail', pass: 'Passwort', signin: 'Anmelden', badLogin: 'Anmeldedaten falsch.', noRight: 'Dieses Konto hat keinen Zugriff auf die App.',
      onlineRes: 'Online-Ergebnisse (inkl. abgeschlossene Projekte)', closedB: 'Abgeschlossen', savedOff: 'Auf dem Telefon gespeichert', saveHint: 'Projekt für Ihren Besuch offline auf diesem Telefon gespeichert.', unsave: 'Vom Telefon entfernen', searching: 'Suche…', noRes: 'Keine Online-Ergebnisse.', needOnline: 'Dieses Projekt ist nicht auf dem Telefon: einmal online öffnen.', savedList: 'Offline gespeicherte Projekte', subArticles: 'Unterartikel', selAll: 'Alle wählen', deliverSel: 'Auswahl liefern', projects: 'Projekte', search: 'Suchen…', none: 'Keine Projekte.', offline: 'Offline', online: 'Online', synced: 'Daten vom', refresh: 'Aktualisieren', logout: 'Abmelden',
      back: 'Zurück', delivery: 'Lieferung', address: 'Lieferadresse', contact: 'Kontakt', customerRef: 'Kundenreferenz', company: 'Kunde', articles: 'Artikel', delivered: 'Geliefert', waiting: 'Wartet auf Synchronisierung',
      newDelivery: 'Neue Lieferung', selectArticles: 'Gelieferte Artikel', receiver: 'Name der empfangenden Person', receiverPh: 'Vor- und Nachname', signature: 'Unterschrift', signHere: 'Hier mit dem Finger unterschreiben',
      clear: 'Löschen', confirm: 'Lieferung bestätigen', nameMissing: 'Bitte Namen eingeben.', signMissing: 'Unterschrift fehlt.', pickOne: 'Mindestens einen Artikel auswählen.', saved: 'Lieferung gespeichert',
      savedQueued: 'Lieferung auf dem Telefon gespeichert, sie wird gesendet, sobald ein Netz verfügbar ist.', sent: 'Lieferung gesendet', queue: 'Zu senden', pending: 'Element(e) zu senden',
      receipts: 'Unterschriebene Lieferungen', pdf: 'PDF', allDone: 'Alle Artikel sind geliefert.', sync: 'Synchronisierung…', syncFail: 'Synchronisierung derzeit nicht möglich.', expired: 'Sitzung abgelaufen, bitte neu anmelden (ausstehende Lieferungen bleiben erhalten).',
      refused: 'Vom Server abgelehnt', retry: 'Erneut versuchen', discard: 'Löschen', never: 'nie', install: 'Installieren: Teilen-Taste in Safari, dann «Zum Home-Bildschirm».', noData: 'Keine Daten auf diesem Telefon: bitte einmal mit Netz anmelden.', qty: 'Menge' },
    en: { coStatus: 'Status', coActiveOn: 'Active company', coInactive: 'Inactive', coActiveHint: 'An inactive company is no longer treated as active (managers only).', coDeactivate: 'Deactivate this company?', coActivate: 'Reactivate this company?', buySupplier: 'Supplier', moreTitle: 'More screens', companiesTitle: 'Companies', noCompanies: 'No companies.', groupedTitle: 'Offer follow-ups', groupedOn: 'Grouped follow-up (estimator offers)', groupedEvery: 'Every', groupedDays: 'days', groupedSaved: 'Saved', groupedFail: 'Could not save, please try again.', groupedHint: 'One single task for offers requested by estimators; other requesters are still followed up offer by offer.', coTitle: 'Company', coContacts: 'Contacts', coOffers: 'Offers', coProjects: 'Projects and orders', coMap: 'Open in Maps', coNone: 'Company not found.', stockTitle: 'Stock', stockAll: 'All', stockNone: 'No items in stock.', buyTitle: 'Supplier orders', buyNone: 'No orders.', buyLines: 'Ordered items', buyReceived: 'received', buyDue: 'Delivery', buyConfirmed: 'confirmed', buyRemark: 'Remark', onlineOnly: 'A connection is needed.', loadFail: 'Could not load, please retry.', dashTitle: 'Today', dashOver: 'Overdue tasks', dashToday: 'Tasks for today', dashClosing: 'Offers closing within 14 days', dashNothing: 'Nothing urgent. 🎉', dashAll: 'All tasks', stageTitle: 'Offer stage', stageChange: 'Change stage', stageConfirm: 'Move the offer to « %1 »?', stageReason: 'Reason for loss', stageReasonMissing: 'Enter the reason for the loss.', stageDone: 'Stage changed', stageFail: 'Could not change, please retry.', stageOnline: 'A connection is needed to change the stage.', offerNote: 'Call / note on the offer', dealFollow: 'Offer follow-up', dealNoFollow: 'No activity or task on this offer.', dealTasks: 'Tasks', dealOnline: 'A connection is needed for the full follow-up.', runTitle: 'Process tasks', runStart: 'Process tasks', runOf: 'Task %1 of %2', runPrev: 'Previous', runSkip: 'Skip', runStop: 'Stop', runDoneBadge: '✓ Done', runEnd: 'All done', runSummary: '%1 completed, %2 postponed, %3 skipped.', runBack: 'Back to tasks', runResume: 'Resume processing', runNone: 'No task to process today.', runRelated: 'Related records', artTitle: 'Items', artFull: 'Full record (documents, delivery…)', artLoading: 'Loading items…', artNone: 'No items.', artOnline: 'A connection is needed to show the items.', pricesTotal: 'Total (excluding variants)', variantB: 'Variant', netPrice: 'net', snooze: 'Postpone', snoozeTo: 'Postpone the task to…', s_tomorrow: 'Tomorrow', s_3d: 'In 3 days', s_monday: 'Next Monday', s_1w: 'In 1 week', s_2w: 'In 2 weeks', s_pick: 'Pick a date…', s_ok: 'Postpone', snoozed: 'Task postponed', taskDetail: 'Task', relatedOffer: 'Related offer', relatedContact: 'Contact', dueLabel: 'Due', cancel: 'Cancel', taskGone: 'Task not found.', postponedTo: 'postponed to', general: 'General', outcome: 'Outcome', when: 'Date and time', o_connected: 'Connected', o_left_live_message: 'Left live message', o_left_voicemail: 'Left voicemail', o_no_answer: 'No answer', o_busy: 'Busy', o_wrong_number: 'Wrong number', o_scheduled: 'Scheduled', o_completed: 'Completed', o_rescheduled: 'Rescheduled', o_no_show: 'No show', o_canceled: 'Canceled', pushNoDevice: 'No device subscribed for this account: turn notifications off and on again.', pushRefused: 'The push service refused the delivery:', bio: 'Security', bioOn: 'Enable Face ID / Touch ID', bioOff: 'Turn off the lock', bioActive: 'Face ID / Touch ID unlock enabled ✓', bioUnlock: 'Unlock', bioFail: 'Could not unlock, please retry.', bioHint: 'The app stays signed in; Face ID / Touch ID is asked when opening and after one minute in the background.', bioNone: 'Face ID / Touch ID is not available on this device or browser.', locked: 'App locked', usePass: 'Sign in again with the password', notif: 'Notifications', notifOn: 'Enable notifications', notifOff: 'Turn off', notifTest: 'Send a test notification', notifActive: 'Notifications enabled on this device ✓', notifNeedInstall: 'To receive notifications on iPhone: install the app on the Home Screen (Share → Add to Home Screen), then open it from the icon.', notifDenied: 'Notifications denied: allow them in iOS Settings → Notifications → ISPAG.', notifNoServer: 'Notifications are not available on this server.', notifOffline: 'A connection is needed to enable notifications.', notifSent: 'Test notification sent.', notifFail: 'Could not enable, please retry.', notifHint: 'Notifications arrive when the phone has a connection; offline, they are delivered when the connection returns (up to 24 h).', notifBanner: '🔔 Get notifications on this phone', plan1: 'Drawing', offer1: 'Offer', allP: 'All', onlyProjects: 'Projects', onlyOffers: 'Offers', articleDetail: 'Item details', tank: 'Tank', planOk: 'Drawing approved', planNo: 'Drawing not approved', docsArticle: 'Drawings, sketches, approvals', conns: 'Connections', t_type: 'Type', t_material: 'Material', t_support: 'Support', t_volume: 'Volume (L)', t_diameter: 'Diameter (mm)', t_height: 'Height (mm)', t_feet: 'Feet height (mm)', t_clearance: 'Ground clearance (mm)', t_bottom: 'Bottom height (mm)', t_pressure: 'Max. pressure (bar)', t_test: 'Test pressure (bar)', t_temp: 'Temperature (°C)', t_ins: 'Insulation', t_ins_thick: 'Thickness (mm)', t_ins_cover: 'Cover', t_welding: 'Welding by the customer', t_comment: 'Comment', c_dn: 'DN', c_h: 'Height', c_angle: 'Angle', factory: 'Factory departure', eta: 'Delivery ETA', yes: 'Yes', manager: 'Project manager', ordered: 'Ordered on', contactsP: 'Project contacts', siteInfo: 'Site conditions', docs: 'Documents', noDocs: 'No documents.', docOffline: 'Document not available offline.', docSaved: 'available offline', docsSaving: 'Documents saved:', details: 'Details', notReady: 'offline: open the app online once', offers: 'Offers', contacts: 'Contacts', tasks: 'Tasks', noOffers: 'No offers in the last 3 months.', noContacts: 'No contacts.', noTasks: 'No open tasks. 🎉', overdue: 'Overdue', today: 'Today', later: 'Upcoming', newTask: 'New task', taskTitle: 'Task title', due: 'Due', saveTask: 'Save task', taskSaved: 'Task saved', taskDone: 'Task completed', doneBtn: 'Complete', newNote: 'New note', teamPresent: 'ISPAG colleagues present', noteType: 'Type', note: 'Note', call: 'Call', meeting: 'Appointment', email: 'Email', noteText: 'Summary', saveNote: 'Save', noteSaved: 'Saved', noteMissing: 'Enter some text.', activities: 'Latest activities', noActs: 'No recent activity.', callNow: 'Call', phone: 'Phone', mail: 'Email', func: 'Position', stage: 'Stage', closing: 'Closing', created: 'Created', amount: 'Amount', offerContacts: 'Offer contacts', task1: 'Task', noTitle: 'Enter a title.', crmSynced: 'Contacts, tasks and offers from', login: 'Sign in', user: 'Username or email', pass: 'Password', signin: 'Sign in', badLogin: 'Wrong credentials.', noRight: 'This account cannot use the app.',
      onlineRes: 'Online results (including closed projects)', closedB: 'Closed', savedOff: 'Kept on the phone', saveHint: 'Project kept on this phone to prepare your visit offline.', unsave: 'Remove from the phone', searching: 'Searching…', noRes: 'No online results.', needOnline: 'This project is not on the phone: open it once while online.', savedList: 'Projects kept offline', subArticles: 'Sub-items', selAll: 'Select all', deliverSel: 'Deliver selection', projects: 'Projects', search: 'Search…', none: 'No projects.', offline: 'Offline', online: 'Online', synced: 'Data from', refresh: 'Refresh', logout: 'Sign out',
      back: 'Back', delivery: 'Delivery', address: 'Delivery address', contact: 'Contact', customerRef: 'Customer ref.', company: 'Customer', articles: 'Items', delivered: 'Delivered', waiting: 'Waiting to sync',
      newDelivery: 'New delivery', selectArticles: 'Delivered items', receiver: 'Name of the person receiving', receiverPh: 'First and last name', signature: 'Signature', signHere: 'Sign here with your finger',
      clear: 'Clear', confirm: 'Confirm delivery', nameMissing: 'Please enter the name.', signMissing: 'Signature missing.', pickOne: 'Select at least one item.', saved: 'Delivery saved',
      savedQueued: 'Delivery saved on the phone; it will be sent as soon as a connection is available.', sent: 'Delivery sent', queue: 'To send', pending: 'item(s) to send',
      receipts: 'Signed deliveries', pdf: 'PDF', allDone: 'All items are delivered.', sync: 'Syncing…', syncFail: 'Cannot sync right now.', expired: 'Session expired, please sign in again (pending deliveries are kept).',
      refused: 'Refused by the server', retry: 'Retry', discard: 'Delete', never: 'never', install: 'To install: Safari Share button, then “Add to Home Screen”.', noData: 'No data on this phone: sign in once while online.', qty: 'Qty' }
  };

  const SITE = {
    fr: { comment: 'Remarque', unloading: 'Moyens de déchargement', corridor: 'Largeur du couloir', door: 'Largeur de porte', doors: 'Nombre de portes', obstacles: 'Autres obstacles', room: 'Dimensions du local', room_h: 'Hauteur du local', ceiling: 'Type de plafond', hoist: 'Palan autorisé', floor: 'Revêtement du sol', vent: 'Ventilation', elec: 'Électricité', parking: 'Adresse de stationnement', notes: 'Observations' },
    en: { comment: 'Comment', unloading: 'Unloading facilities', corridor: 'Corridor width', door: 'Door width', doors: 'Number of doors', obstacles: 'Other obstacles', room: 'Room size', room_h: 'Room height', ceiling: 'Ceiling type', hoist: 'Hoist allowed', floor: 'Floor covering', vent: 'Ventilation', elec: 'Electricity', parking: 'Parking address', notes: 'Observations' },
    de: { comment: 'Bemerkung', unloading: 'Abladehilfen', corridor: 'Gangbreite', door: 'Türbreite', doors: 'Anzahl Türen', obstacles: 'Weitere Hindernisse', room: 'Raumgrösse', room_h: 'Raumhöhe', ceiling: 'Deckenart', hoist: 'Hebezeug erlaubt', floor: 'Bodenbelag', vent: 'Lüftung', elec: 'Strom', parking: 'Parkplatzadresse', notes: 'Beobachtungen' }
  };
  let lang = 'fr';
  function t(k) { return (T[lang] && T[lang][k]) || T.fr[k] || k; }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }

  // ------------------------------------------------------------------ stockage
  function lsGet(k) { try { return JSON.parse(localStorage.getItem(k)); } catch (e) { return null; } }
  function lsSet(k, v) { try { localStorage.setItem(k, JSON.stringify(v)); } catch (e) {} }

  let dbp = null;
  function db() {
    if (dbp) return dbp;
    dbp = new Promise(function (resolve, reject) {
      const r = indexedDB.open('ispag-app', 1);
      r.onupgradeneeded = function () { r.result.createObjectStore('kv'); r.result.createObjectStore('outbox', { keyPath: 'client_id' }); };
      r.onsuccess = function () { resolve(r.result); };
      r.onerror = function () { reject(r.error); };
    });
    return dbp;
  }
  function tx(store, mode, fn) {
    return db().then(function (d) {
      return new Promise(function (resolve, reject) {
        const t = d.transaction(store, mode), s = t.objectStore(store), req = fn(s);
        t.oncomplete = function () { resolve(req && req.result); };
        t.onerror = t.onabort = function () { reject(t.error); };
      });
    });
  }
  const kvGet = function (k) { return tx('kv', 'readonly', function (s) { return s.get(k); }); };
  const kvSet = function (k, v) { return tx('kv', 'readwrite', function (s) { return s.put(v, k); }); };
  const outAll = function () { return tx('outbox', 'readonly', function (s) { return s.getAll(); }).then(function (r) { return (r || []).sort(function (a, b) { return a.created - b.created; }); }); };
  const outPut = function (o) { return tx('outbox', 'readwrite', function (s) { return s.put(o); }); };
  const outDel = function (id) { return tx('outbox', 'readwrite', function (s) { return s.delete(id); }); };

  // ------------------------------------------------------------------ état
  let auth = lsGet('ispag.auth');            // { token, user }
  function saveAuth() { lsSet('ispag.auth', auth); kvSet('auth', auth).catch(function () {}); }
  let snap = null;                           // instantané des projets
  let saved = {};                            // projets clos ouverts en ligne, gardés pour la visite hors ligne
  let remote = { q: '', list: [], busy: false, timer: null };
  let crm = null;                            // instantané CRM : contacts, tâches, offres
  let outbox = [];                           // livraisons en attente
  let syncing = false, syncMsg = '', sessionExpired = false, swReady = false;
  const app = document.getElementById('app');

  function applyLang() { const l = auth && auth.user && auth.user.lang; lang = T[l] ? l : (T[(navigator.language || 'fr').slice(0, 2)] ? (navigator.language || 'fr').slice(0, 2) : 'fr'); }

  // ------------------------------------------------------------------ API
  function api(path, opts) {
    opts = opts || {};
    const headers = { 'Content-Type': 'application/json' };
    if (auth && auth.token) headers['X-ISPAG-Token'] = auth.token;
    return fetch(CFG.api + path, { method: opts.method || 'GET', headers: headers, body: opts.body ? JSON.stringify(opts.body) : undefined, cache: 'no-store' })
      .then(function (r) { return r.json().catch(function () { return {}; }).then(function (j) { return { status: r.status, body: j }; }); });
  }

  function login(username, password) {
    return api('/login', { method: 'POST', body: { username: username, password: password, device: navigator.userAgent.slice(0, 100) } }).then(function (r) {
      if (r.status === 200 && r.body.token) {
        auth = { token: r.body.token, user: r.body.user }; saveAuth(); applyLang(); sessionExpired = false;
        return { ok: true };
      }
      return { ok: false, message: r.status === 403 ? t('noRight') : t('badLogin') };
    });
  }

  function logout() {
    const done = function () { auth = null; snap = null; crm = null; kvSet('crm', null); kvSet('auth', null); forgetLock(); try { localStorage.removeItem('ispag.auth'); } catch (e) {} kvSet('snapshot', null); kvSet('saved', null); saved = {}; render(); };
    const proceed = function () { api('/logout', { method: 'POST' }).catch(function () {}).then(done); };
    if (!outbox.length) { proceed(); return; }
    ispagConfirm(outbox.length + ' ' + t('pending') + ' — ' + t('logout') + ' ?', { text: true, danger: true }).then(function (ok) { if (ok) proceed(); });
  }

  // ------------------------------------------------------------------ synchronisation
  function pendingIds(deal) {
    const s = {};
    outbox.forEach(function (o) { if (o.kind !== 'crm' && o.deal_id === deal && !o.error) o.article_ids.forEach(function (id) { s[id] = true; }); });
    return s;
  }

  function sync(manual) {
    if (!auth || syncing || !navigator.onLine) { return Promise.resolve(); }
    syncing = true; syncMsg = t('sync'); renderStatus();
    let chain = Promise.resolve();
    outbox.slice().forEach(function (o) {
      chain = chain.then(function () {
        if (sessionExpired || o.error) return;
        const req = o.kind === 'crm' ? api('/crm-action', { method: 'POST', body: Object.assign({ client_id: o.client_id }, o.payload) })
          : api('/deliveries', { method: 'POST', body: { client_id: o.client_id, deal_id: o.deal_id, article_ids: o.article_ids, receiver_name: o.receiver_name, signature: o.signature, signed_at: o.signed_at } });
        return req
          .then(function (r) {
            if (r.status === 200 && r.body.ok) { return outDel(o.client_id).then(function () { outbox = outbox.filter(function (x) { return x.client_id !== o.client_id; }); });
            }
            if (r.status === 401) { sessionExpired = true; return; }
            if (r.status === 409 || r.status >= 500) { throw new Error('retry'); }
            o.error = (r.body && r.body.message) || String(r.status); return outPut(o); // refus définitif (droits, données) : conservé pour que l'utilisateur voie
          });
      });
    });
    return chain.then(function () {
      if (sessionExpired) throw new Error('expired');
      return api('/snapshot');
    }).then(function (r) {
      if (r.status === 401) { sessionExpired = true; throw new Error('expired'); }
      if (r.status !== 200) throw new Error('snap');
      snap = r.body; auth.user = r.body.user; saveAuth(); applyLang();
      return kvSet('snapshot', snap);
    }).then(function () {
      const ids = Object.keys(saved); let ch = Promise.resolve();   // projets gardés hors ligne : remis à jour
      ids.forEach(function (k) { ch = ch.then(function () { return apiq('/project', { id: k }).then(function (r) { if (r.status === 200 && r.body.project) saved[k] = r.body.project; else if (r.status === 404 || r.status === 403) delete saved[k]; }).catch(function () {}); }); });
      return ch.then(function () { return ids.length ? kvSet('saved', saved) : null; });
    }).then(function () {
      if (!(snap.caps && snap.caps.crm)) { crm = null; return kvSet('crm', null); }
      return api('/crm').then(function (r) {
        if (r.status === 401) { sessionExpired = true; throw new Error('expired'); }
        if (r.status === 200) { crm = r.body; return kvSet('crm', crm); }
      });
    }).then(function () { syncMsg = ''; cacheDocuments(); }).catch(function (e) {
      syncMsg = e.message === 'expired' ? t('expired') : (manual ? t('syncFail') : '');
    }).then(function () { syncing = false; render(); });
  }

  // ------------------------------------------------------------------ rendu
  function route() { return (location.hash || '#/').slice(1).split('/').filter(Boolean); }
  function go(h) { location.hash = h; }
  function fmtDate(iso) { if (!iso) return t('never'); const d = new Date(iso); return isNaN(d) ? '' : d.toLocaleString(lang === 'de' ? 'de-CH' : lang === 'en' ? 'en-GB' : 'fr-CH', { dateStyle: 'short', timeStyle: 'short' }); }
  function findProject(id) { return (snap && snap.projects.filter(function (p) { return p.deal_id === id; })[0]) || saved[id]; }
  function savedOnly() { return Object.keys(saved).map(function (k) { return saved[k]; }).filter(function (p) { return !snap || !snap.projects.some(function (x) { return x.deal_id === p.deal_id; }); }); }
  function apiq(path, params) { return api(path + (CFG.api.indexOf('?') >= 0 ? '&' : '?') + Object.keys(params).map(function (k) { return k + '=' + encodeURIComponent(params[k]); }).join('&')); }
  function matchP(p, q) { return !q || (p.title + ' ' + p.number + ' ' + p.company + ' ' + p.customer_ref + ' ' + (p.contacts || []).map(function (c) { return c.name; }).join(' ')).toLowerCase().indexOf(q) >= 0; }

  function renderStatus() {
    const el = document.getElementById('status'); if (!el) return;
    const off = !navigator.onLine;
    el.className = 'status' + (off ? ' off' : '');
    const ready = swReady && !!(navigator.serviceWorker && navigator.serviceWorker.controller);
    el.innerHTML = '<span>' + (off ? '⚠️ ' + t('offline') : '● ' + t('online')) + (snap ? ' · ' + t('synced') + ' ' + esc(fmtDate(snap.generated_at)) : '') + ' · ' + (ready ? '📴✓' : '📴✗ ' + esc(t('notReady'))) + '</span><span>' + esc(syncMsg || (outbox.length ? '⏳ ' + outbox.length + ' ' + t('pending') : '')) + '</span>';
  }

  // Logo du site : enregistré sur le téléphone (data URL) pour rester affiché sans réseau
  let logoData = null;
  function brand() {
    const src = logoData || CFG.logo || '';
    return src ? '<img class="logo" src="' + esc(src) + '" alt="ISPAG">' : '<span class="logo-t">ISPAG</span>';
  }
  function loadLogo() {
    kvGet('logo').then(function (v) {
      if (v && v.src === CFG.logo) { logoData = v.data; return; }
      if (!CFG.logo || !navigator.onLine) return;
      return fetch(CFG.logo, { credentials: 'omit' }).then(function (r) { return r.ok ? r.blob() : null; }).then(function (b) {
        if (!b || b.size > 600000) return;
        return new Promise(function (res) { const fr = new FileReader(); fr.onload = function () { res(fr.result); }; fr.onerror = function () { res(null); }; fr.readAsDataURL(b); });
      }).then(function (data) { if (data) { logoData = data; return kvSet('logo', { src: CFG.logo, data: data }); } });
    }).catch(function () {});
  }

  function shell(title, inner, opts) {
    opts = opts || {};
    app.innerHTML =
      '<header class="bar">' + (opts.back ? '<button data-act="back" class="bk" aria-label="' + esc(t('back')) + '">‹</button>' : '') +
      '<a class="brand" href="' + esc(homeHash()) + '">' + brand() + '</a><span class="sp"></span>' +
      (opts.noActions ? '' : '<button data-act="bell" aria-label="' + esc(t('notif')) + '">🔔</button><button data-act="refresh" aria-label="' + esc(t('refresh')) + '">↻</button><button data-act="logout" aria-label="' + esc(t('logout')) + '">⎋</button>') +
      '</header><div id="status"></div>' + runBar() + '<div class="wrap">' + (title && !opts.noTitle ? '<h1 class="ptitle">' + esc(title) + '</h1>' : '') + inner + '</div>' + (opts.tab ? tabsHtml(opts.tab) : '');
    renderStatus();
    window.scrollTo(0, 0);
  }

  function renderLogin(msg) {
    shell('ISPAG', '<form class="card red" id="login" novalidate><h2>' + esc(t('login')) + '</h2>' +
      '<label class="f" for="u">' + esc(t('user')) + '</label><input type="text" id="u" autocomplete="username" autocapitalize="none" autocorrect="off">' +
      '<label class="f" for="p">' + esc(t('pass')) + '</label><input type="password" id="p" autocomplete="current-password">' +
      '<div class="msg" id="msg">' + esc(msg || '') + '</div><button class="btn" type="submit">' + esc(t('signin')) + '</button>' +
      '<p class="hint">' + esc(t('install')) + '</p></form>', { noActions: true, noTitle: true });
    app.querySelector('#login').addEventListener('submit', function (e) {
      e.preventDefault();
      const u = app.querySelector('#u').value.trim(), p = app.querySelector('#p').value;
      if (!u || !p) return;
      if (!navigator.onLine) { app.querySelector('#msg').textContent = t('offline'); return; }
      app.querySelector('.btn').disabled = true;
      login(u, p).then(function (r) { if (r.ok) { sync(true); fetchPushKey().then(checkPush); render(); } else { renderLogin(r.message); } }).catch(function () { renderLogin(t('syncFail')); });
    });
  }

  function projCard(p, closed) {
    const todo = p.articles ? p.articles.filter(function (a) { return !a.done; }).length : 0;
    return '<a class="card item" href="#/p/' + p.deal_id + '"><div class="t">' + esc(p.title || p.number) + (p.is_offer ? '<span class="badge offer">' + esc(t('offer1')) + '</span>' : '') + (closed || p.closed ? '<span class="badge wait">' + esc(t('closedB')) + '</span>' : '') + '</div><div class="s">' + esc([p.number, p.company].filter(Boolean).join(' · ')) + '</div>' +
      (p.articles ? '<div class="s">' + p.articles.length + ' ' + esc(t('articles').toLowerCase()) + (p.is_offer || p.closed ? '' : (todo ? '<span class="badge">' + todo + ' ✕</span>' : '<span class="badge ok">✓</span>')) + '</div>' : '') + '</a>';
  }

  /** Recherche en ligne dans tous les projets et offres (clos compris), après une courte pause de frappe. */
  function remoteSearch(q) {
    clearTimeout(remote.timer);
    if (q.length < 2 || !navigator.onLine || !auth) { if (remote.q) { remote.q = ''; remote.list = []; } return; }
    if (remote.q === q) return;
    remote.timer = setTimeout(function () {
      remote.busy = true; fillRemote();
      apiq('/search', { q: q }).then(function (r) {
        if ((sessionStorage.getItem('ispag.q') || '').toLowerCase() !== q) return;
        remote.q = q; remote.list = (r.status === 200 && r.body.results) || []; remote.busy = false; fillRemote();
      }).catch(function () { remote.busy = false; fillRemote(); });
    }, 350);
  }
  function fillRemote() {
    const el = app.querySelector('#remote'); if (!el) return;
    const q = (sessionStorage.getItem('ispag.q') || '').toLowerCase();
    if (q.length < 2 || !navigator.onLine) { el.innerHTML = ''; return; }
    const known = {}; (snap ? snap.projects : []).forEach(function (p) { known[p.deal_id] = true; });
    const list = remote.q === q ? remote.list.filter(function (r) { return !known[r.deal_id]; }) : [];
    el.innerHTML = remote.busy ? '<p class="hint">' + esc(t('searching')) + '</p>' : (remote.q === q ? '<h3 class="grp">' + esc(t('onlineRes')) + '</h3>' + (list.length ? list.map(function (r) { return projCard(r, r.closed); }).join('') : '<div class="empty">' + esc(t('noRes')) + '</div>') : '');
  }

  function renderList() {
    if (!snap && !Object.keys(saved).length) { shell(t('projects'), '<div class="empty">' + esc(navigator.onLine ? t('sync') : t('noData')) + '</div>', { tab: 'projects' }); return; }
    const q = (sessionStorage.getItem('ispag.q') || '').toLowerCase(), pf = sessionStorage.getItem('ispag.pf') || 'all';
    const base = snap ? snap.projects : [];
    const hasOffers = base.some(function (p) { return p.is_offer; });
    const list = base.filter(function (p) {
      if (pf === 'projects' && p.is_offer) return false;
      if (pf === 'offers' && !p.is_offer) return false;
      return matchP(p, q);
    });
    const kept = savedOnly().filter(function (p) { return matchP(p, q); });
    let html = '<input type="search" id="q" placeholder="' + esc(t('search')) + '" value="' + esc(q) + '" style="margin-bottom:12px">';
    if (hasOffers) html += '<div class="chips">' + [['all', t('allP')], ['projects', t('onlyProjects')], ['offers', t('onlyOffers')]].map(function (c) { return '<button type="button" class="chip' + (c[0] === pf ? ' on' : '') + '" data-act="pf" data-v="' + c[0] + '">' + esc(c[1]) + '</button>'; }).join('') + '</div>';
    if (outbox.length) html += '<a class="card item" href="#/queue"><span class="t">⏳ ' + outbox.length + ' ' + esc(t('pending')) + '</span></a>';
    html += pushBanner();
    html += list.length ? list.map(function (p) { return projCard(p); }).join('') : (kept.length || q.length >= 2 ? '' : '<div class="empty">' + esc(t('none')) + '</div>');
    if (kept.length) html += '<h3 class="grp">' + esc(t('savedList')) + '</h3>' + kept.map(function (p) { return projCard(p, true); }).join('');
    html += '<div id="remote"></div>';
    shell(t('projects'), html, { tab: 'projects' });
    fillRemote();
    const qi = app.querySelector('#q');
    qi.addEventListener('input', function () { sessionStorage.setItem('ispag.q', qi.value); remoteSearch(qi.value.trim().toLowerCase()); const pos = qi.selectionStart; renderList(); const n = app.querySelector('#q'); n.focus(); n.setSelectionRange(pos, pos); });
  }

  // Articles cochés dans la fiche projet, repris sur l'écran de livraison
  function selGet(id) { try { return JSON.parse(sessionStorage.getItem('ispag.sel.' + id) || '[]'); } catch (e) { return []; } }
  function selSet(id, arr) { try { sessionStorage.setItem('ispag.sel.' + id, JSON.stringify(arr)); } catch (e) {} }

  function addrLine(d) { return [d.address, d.address2, d.address3, [d.zip, d.city].filter(Boolean).join(' ')].filter(Boolean).join(', '); }

  /** Projet ou offre absent de l'instantané (ancien, clos) : chargé en ligne puis gardé sur le téléphone pour la visite. */
  const opening = {};
  function openRemote(id) {
    if (!navigator.onLine) { shell(t('projects'), '<div class="empty">' + esc(t('needOnline')) + '</div>', { back: true }); return; }
    shell(t('projects'), '<div class="empty">' + esc(t('sync')) + '</div>', { back: true });
    if (opening[id]) return; opening[id] = true;
    apiq('/project', { id: id }).then(function (r) {
      opening[id] = false;
      if (r.status === 200 && r.body.project) { saved[id] = r.body.project; return kvSet('saved', saved).then(function () { cacheDocuments(); render(); }); }
      go('#/projects');
    }).catch(function () { opening[id] = false; go('#/projects'); });
  }

  function renderProject(id) {
    const p = findProject(id);
    if (!p) { openRemote(id); return; }
    const pend = pendingIds(id);
    const d = p.delivery || {};
    const kv = function (label, val) { return val ? '<div class="kv"><span>' + esc(label) + '</span><strong>' + val + '</strong></div>' : ''; };
    let html = '<div class="card red">' + kv(t('company'), esc(p.company)) + kv(t('customerRef'), esc(p.customer_ref)) + kv(t('manager'), esc(p.manager)) +
      (p.is_offer ? kv(t('created'), esc(dateOnly(p.created))) + kv(t('stage'), '<span class="badge offer">' + esc(t('offer1')) + '</span>') : kv(t('ordered'), p.ordered_at ? esc(dateOnly(new Date(p.ordered_at * 1000).toISOString())) : '')) +
      (addrLine(d) ? '<div class="kv"><span>' + esc(t('address')) + '</span><a href="https://maps.google.com/?q=' + encodeURIComponent(addrLine(d)) + '" target="_blank" rel="noopener">' + esc(addrLine(d)) + '</a></div>' : '') +
      (d.contact || d.phone ? '<div class="kv"><span>' + esc(t('contact')) + '</span><span style="color:inherit">' + esc(d.contact) + ' ' + (d.phone ? '<a href="tel:' + esc(d.phone.replace(/[^+\d]/g, '')) + '">' + esc(d.phone) + '</a>' : '') + '</span></div>' : '') +
      '</div>';
    if (p.contacts && p.contacts.length) {
      html += '<div class="card"><h2>' + esc(t('contactsP')) + '</h2>' + p.contacts.map(function (c) {
        return '<div class="kv"><span style="color:inherit"><strong>' + esc(c.name) + '</strong></span><span style="color:inherit">' + (c.phone ? phoneLink(c.phone) : '') + (c.email ? ' <a href="mailto:' + esc(c.email) + '">✉️</a>' : '') + '</span></div>';
      }).join('') + '</div>';
    }
    const site = d.site && Object.keys(d.site).length ? d.site : null, L = SITE[lang] || SITE.fr;
    if (site) html += '<div class="card"><h2>' + esc(t('siteInfo')) + '</h2>' + Object.keys(L).filter(function (k) { return site[k]; }).map(function (k) { return '<div class="kv"><span>' + esc(L[k]) + '</span><strong>' + esc(site[k]) + '</strong></div>'; }).join('') + '</div>';

    const todo = p.is_offer || p.closed ? [] : p.articles.filter(function (a) { return !a.done && !pend[a.id]; });
    if (saved[id] && savedOnly().some(function (x) { return x.deal_id === id; })) html += '<div class="card"><div class="hint">📌 ' + esc(t('saveHint')) + '</div><button type="button" class="btn" data-act="unsave" data-id="' + id + '">' + esc(t('unsave')) + '</button></div>';
    // Articles : par groupe, comme sur le site ; articles principaux puis leurs sous-articles
    const ids = {}; p.articles.forEach(function (a) { ids[a.id] = true; });
    const groups = [], gmap = {};
    const byId = {}; p.articles.forEach(function (a) { byId[a.id] = a; });
    // un sous-article suit le groupe de son article principal (son propre groupe peut être différent ou vide)
    p.articles.forEach(function (a) { const m = a.master && byId[a.master]; const g = (m ? m.group : a.group) || ''; if (!gmap[g]) { gmap[g] = { name: g, mains: [], subs: {} }; groups.push(gmap[g]); } if (m) (gmap[g].subs[a.master] = gmap[g].subs[a.master] || []).push(a); else gmap[g].mains.push(a); });
    groups.sort(function (x, y) { return x.name === y.name ? 0 : !x.name ? 1 : !y.name ? -1 : x.name.localeCompare(y.name, undefined, { numeric: true }); });
    const chosen = {}; selGet(id).forEach(function (x) { chosen[x] = true; });
    function artRow(a, sub) {
      const badge = a.done ? '<span class="badge ok">' + esc(t('delivered')) + '</span>' : (pend[a.id] ? '<span class="badge wait">⏳</span>' : '');
      const plan = a.type === 1 ? '<span class="badge ' + (a.plan_ok ? 'ok' : 'wait') + '">' + esc(a.plan_ok ? '✓ ' + t('planOk') : t('planNo')) + '</span>' : '';
      const first = a.desc && !a.name ? a.desc.split('\n')[0] : a.name;
      const row = '<a class="art item' + (sub ? ' sub' : '') + (a.done ? ' done' : '') + '" data-aid="' + a.id + '" href="#/p/' + id + '/a/' + a.id + '" style="text-decoration:none;color:inherit"><span class="n">' + esc(first) + (a.ref ? ' <span class="hint">' + esc(a.ref) + '</span>' : '') + badge + plan + '</span><span class="q">' + esc(a.qty) + '</span><span class="q">›</span></a>';
      if (a.done || pend[a.id] || p.is_offer || p.closed) return row;
      return '<div class="selrow' + (sub ? ' sub' : '') + '"><input type="checkbox" class="sel" value="' + a.id + '" aria-label="' + esc(first) + '"' + (chosen[a.id] ? ' checked' : '') + '>' + row + '</div>';
    }
    html += '<div class="card"><h2>' + esc(t('articles')) + '</h2>' + (groups.length ? groups.map(function (g) {
      const rows = g.mains.map(function (a) { return artRow(a, false) + (g.subs[a.id] || []).map(function (x) { return artRow(x, true); }).join(''); }).join('');
      return (groups.length > 1 || g.name ? '<h3 class="grp">' + esc(g.name || t('general')) + '</h3>' : '') + rows;
    }).join('') : '<div class="empty">' + esc(t('none')) + '</div>') + '</div>';

    // documents (hors prix) : ouverts depuis la copie du téléphone quand elle existe
    const docs = p.documents || [];
    function docRow(x) {
      const art = x.article ? p.articles.filter(function (a) { return a.id === x.article; })[0] : null;
      return '<button type="button" class="doc" data-act="open-doc" data-url="' + esc(x.url) + '" data-mime="' + esc(x.mime) + '"><span class="ic">' + docIcon(x.mime) + '</span><span class="n">' + esc(x.title) +
        '<br><span class="hint">' + esc([x.type, art ? (art.name || (art.desc || '').split('\n')[0]) : '', x.at ? dateOnly(new Date(x.at * 1000).toISOString()) : ''].filter(Boolean).join(' · ')) + '</span></span>' +
        '<span class="off" data-doc="' + esc(x.url) + '"></span></button>';
    }
    // Comme sur le site : documents généraux d'abord, puis ceux des articles rangés par groupe
    const gOf = {}; p.articles.forEach(function (a) { gOf[a.id] = a.group || ''; });
    const dgen = [], dgrp = {}, dnames = [];
    docs.forEach(function (x) {
      if (!x.article || gOf[x.article] === undefined) { dgen.push(x); return; }
      const g = gOf[x.article]; if (!g) { dgen.push(x); return; } if (!dgrp[g]) { dgrp[g] = []; dnames.push(g); } dgrp[g].push(x);
    });
    dnames.sort(function (a, b) { return a === b ? 0 : !a ? 1 : !b ? -1 : a.localeCompare(b, undefined, { numeric: true }); });
    html += '<div class="card"><h2>' + esc(t('docs')) + (docs.length ? ' · ' + docs.length : '') + '</h2>' + (docs.length ?
      (dgen.length ? '<h3 class="grp">' + esc(t('general')) + '</h3>' + dgen.map(docRow).join('') : '') +
      dnames.map(function (g) { return '<h3 class="grp">' + esc(g || t('general')) + '</h3>' + dgrp[g].map(docRow).join(''); }).join('')
      : '<div class="empty">' + esc(t('noDocs')) + '</div>') + '</div>';

    html += todo.length ? '<div class="selbar"><button type="button" class="btn light" data-act="selall" data-id="' + id + '">☑ ' + esc(t('selAll')) + '</button><button class="btn green" data-act="deliver" id="deliverbtn">✍️ ' + esc(t('deliverSel')) + ' (<span id="selcount">' + Object.keys(chosen).filter(function (k) { return todo.some(function (a) { return String(a.id) === k; }); }).length + '</span>)</button></div>' : (p.is_offer || p.closed ? '' : '<p class="hint" style="text-align:center">' + esc(t('allDone')) + '</p>');
    if (!p.is_offer && p.receipts && p.receipts.length) {
      html += '<div class="card" style="margin-top:12px"><h2>' + esc(t('receipts')) + '</h2>' + p.receipts.map(function (r) {
        return '<div class="kv"><span>' + esc(fmtDate(r.at.replace(' ', 'T'))) + '</span><span style="color:inherit">' + esc(r.by) + (r.pdf ? ' · <button type="button" class="lnk" data-act="open-doc" data-url="' + esc(r.pdf) + '" data-mime="application/pdf">' + esc(t('pdf')) + '</button>' : '') + '</span></div>';
      }).join('') + '</div>';
    }
    shell(p.title || p.number, html, { back: true });
    app.querySelectorAll('.sel').forEach(function (c) {
      c.addEventListener('change', function () {
        const v = Array.prototype.map.call(app.querySelectorAll('.sel:checked'), function (x) { return parseInt(x.value, 10); });
        selSet(id, v); const n = app.querySelector('#selcount'); if (n) n.textContent = v.length;
      });
    });
    markCachedDocs();
    loadPrices(id).then(function (pr) { applyPrices(app, pr); });
  }

  // ------------------------------------------------------------------ documents : copie sur le téléphone (Cache API)
  const DOC_CACHE = 'ispag-docs-v1', DOC_MAX_FILE = 20 * 1024 * 1024, DOC_BUDGET = 250 * 1024 * 1024;
  function docIcon(mime) { return /pdf/.test(mime || '') ? '📄' : /^image\//.test(mime || '') ? '🖼️' : '📎'; }
  function docCacheable(x) { return /pdf|^image\//.test(x.mime || '') && (!x.size || x.size <= DOC_MAX_FILE); }
  function docCache() { return window.caches ? caches.open(DOC_CACHE) : Promise.reject(new Error('nocache')); }

  /** Marque dans la page les documents déjà disponibles hors ligne. */
  function markCachedDocs() {
    docCache().then(function (c) { return c.keys(); }).then(function (keys) {
      const have = {}; keys.forEach(function (k) { have[k.url] = true; });
      Array.prototype.forEach.call(app.querySelectorAll('.off[data-doc]'), function (e) { e.textContent = have[e.dataset.doc] ? '✓' : ''; e.title = have[e.dataset.doc] ? t('docSaved') : ''; });
    }).catch(function () {});
  }

  /** Après chaque synchronisation : enregistre les documents (PDF, images) des projets actifs, du plus récent au plus ancien, et retire ceux qui ne sont plus listés. */
  let docsBusy = false;
  function cacheDocuments() {
    if ((!snap && !Object.keys(saved).length) || docsBusy || !navigator.onLine || !window.caches) return Promise.resolve();
    const list = [], seen = {};
    (snap ? snap.projects : []).concat(savedOnly()).forEach(function (p) {
      (p.documents || []).concat((p.receipts || []).filter(function (r) { return r.pdf; }).map(function (r) { return { url: r.pdf, mime: 'application/pdf', size: 0 }; })).forEach(function (x) {
        if (!seen[x.url] && docCacheable(x)) { seen[x.url] = true; list.push(x); }
      });
    });
    docsBusy = true;
    let used = 0, done = 0;
    return docCache().then(function (c) {
      return c.keys().then(function (keys) {
        const wanted = {}; list.forEach(function (x) { wanted[x.url] = true; });
        return Promise.all(keys.filter(function (k) { return !wanted[k.url]; }).map(function (k) { return c.delete(k); })).then(function () { return c.keys(); });
      }).then(function (keys) {
        const have = {}; keys.forEach(function (k) { have[k.url] = true; });
        let chain = Promise.resolve();
        list.forEach(function (x) {
          chain = chain.then(function () {
            if (used > DOC_BUDGET || !navigator.onLine) return;
            if (have[x.url]) { used += x.size || 0; return; }
            return fetch(x.url, { credentials: 'omit' }).then(function (res) {
              if (!res.ok || res.type === 'opaque') return;
              const len = parseInt(res.headers.get('content-length') || '0', 10);
              if (len > DOC_MAX_FILE) return;
              used += len || x.size || 0; done++;
              return c.put(x.url, res).then(function () { have[x.url] = true; });
            }).catch(function () {});
          });
        });
        return chain;
      });
    }).catch(function () {}).then(function () { docsBusy = false; markCachedDocs(); });
  }

  /** Ouvre un document : la copie du téléphone si elle existe, sinon l'adresse en ligne. */
  function openDoc(url, mime) {
    const w = window.open('', '_blank');   // ouvert pendant le geste de l'utilisateur (Safari bloque sinon), adresse renseignée ensuite
    const go2 = function (u) { if (w) { w.location.href = u; } else { location.href = u; } };
    docCache().then(function (c) { return c.match(url); }).then(function (res) { return res ? res.blob() : null; }).catch(function () { return null; }).then(function (blob) {
      if (blob) { go2(URL.createObjectURL(new Blob([blob], { type: blob.type || mime || 'application/pdf' }))); }
      else if (navigator.onLine) { go2(url); }
      else { if (w) w.close(); toast(t('docOffline')); }
    });
  }


  // --- fiche d'un article : description, fiche technique du réservoir, piquages, plans / croquis / validations
  function renderArticle(deal, aid) {
    const p = findProject(deal), a = p && p.articles.filter(function (x) { return x.id === aid; })[0];
    if (!a) { go('#/p/' + deal); return; }
    const kv = function (label, val) { return val ? '<div class="kv"><span>' + esc(label) + '</span><strong>' + esc(val) + '</strong></div>' : ''; };
    const day = function (ts) { return ts ? dateOnly(new Date(ts * 1000).toISOString()) : ''; };
    let html = '<div class="card red">' + (a.desc ? '<div class="desc" style="margin:0 0 8px">' + esc(a.desc) + '</div>' : '') + kv(t('articles'), a.qty + (a.ref ? ' · ' + a.ref : '')) +
      (a.type === 1 ? '<div class="kv"><span>' + esc(t('plan1')) + '</span><span class="badge ' + (a.plan_ok ? 'ok' : 'wait') + '">' + esc(a.plan_ok ? '✓ ' + t('planOk') : t('planNo')) + '</span></div>' : '') +
      kv(t('factory'), day(a.eta)) + kv(t('eta'), day(a.eta_end)) + '</div>';
    const k = a.tank;
    if (k) {
      const rows = [['t_type', k.type], ['t_material', k.material], ['t_support', k.support], ['t_volume', k.volume], ['t_diameter', k.diameter], ['t_height', k.height], ['t_feet', k.feet], ['t_clearance', k.clearance], ['t_bottom', k.bottom],
        ['t_pressure', k.pressure], ['t_test', k.test], ['t_temp', k.temp], ['t_ins', k.ins], ['t_ins_thick', k.ins_thick], ['t_ins_cover', k.ins_cover], ['t_welding', k.welding_client ? t('yes') : ''], ['t_comment', k.comment]];
      html += '<div class="card"><h2>' + esc(t('tank')) + '</h2>' + rows.map(function (r) { return kv(t(r[0]), r[1]); }).join('') + '</div>';
      if (k.conns && k.conns.length) {
        html += '<div class="card"><h2>' + esc(t('conns')) + ' · ' + k.conns.length + '</h2>' + k.conns.map(function (c) {
          return '<div class="art"><span class="n">' + esc(c.type) + (c.acc ? '<br><span class="hint">' + esc(c.acc) + '</span>' : '') + '</span><span class="q" style="font-weight:400;font-size:13px">' +
            esc([c.dn ? t('c_dn') + ' ' + c.dn : '', c.height !== '' && c.height != null ? t('c_h') + ' ' + c.height : '', c.angle ? t('c_angle') + ' ' + c.angle + '°' : ''].filter(Boolean).join(' · ')) + (c.ok ? ' ✓' : '') + '</span></div>';
        }).join('') + '</div>';
      }
    }
    const subs = p.articles.filter(function (x) { return x.master === aid; });
    if (subs.length) html += '<div class="card"><h2>' + esc(t('subArticles')) + ' · ' + subs.length + '</h2>' + subs.map(function (x) {
      const first = x.name || (x.desc || '').split('\n')[0];
      return '<a class="art item sub" href="#/p/' + deal + '/a/' + x.id + '" style="text-decoration:none;color:inherit"><span class="n">' + esc(first) + (x.ref ? ' <span class="hint">' + esc(x.ref) + '</span>' : '') + (x.done ? '<span class="badge ok">' + esc(t('delivered')) + '</span>' : '') + '</span><span class="q">' + esc(x.qty) + '</span><span class="q">›</span></a>';
    }).join('') + '</div>';
    const docs = (p.documents || []).filter(function (x) { return x.article === aid; });
    html += '<div class="card"><h2>' + esc(t('docsArticle')) + (docs.length ? ' · ' + docs.length : '') + '</h2>' + (docs.length ? docs.map(function (x) {
      return '<button type="button" class="doc" data-act="open-doc" data-url="' + esc(x.url) + '" data-mime="' + esc(x.mime) + '"><span class="ic">' + docIcon(x.mime) + '</span><span class="n">' + esc(x.title) +
        '<br><span class="hint">' + esc([x.type, x.at ? dateOnly(new Date(x.at * 1000).toISOString()) : ''].filter(Boolean).join(' · ')) + '</span></span><span class="off" data-doc="' + esc(x.url) + '"></span></button>';
    }).join('') : '<div class="empty">' + esc(t('noDocs')) + '</div>') + '</div>';
    shell(a.name || (a.desc || '').split('\n')[0], html, { back: true });
    markCachedDocs();
  }

  function renderQueue() {
    let html = outbox.length ? outbox.map(function (o) {
      if (o.kind === 'crm') {
        return '<div class="card"><div class="t">' + esc(crmLabel(o)) + '</div><div class="s">' + esc(fmtDate(o.payload.at)) + '</div>' +
          (o.error ? '<div class="msg">' + esc(t('refused')) + ' (' + esc(o.error) + ')</div><button class="btn light" data-act="discard" data-id="' + esc(o.client_id) + '">' + esc(t('discard')) + '</button>' : '<span class="badge wait">⏳</span>') + '</div>';
      }
      const p = findProject(o.deal_id);
      return '<div class="card"><div class="t">' + esc(p ? (p.title || p.number) : '#' + o.deal_id) + '</div><div class="s">' + esc(o.receiver_name) + ' · ' + esc(fmtDate(o.signed_at)) + ' · ' + o.article_ids.length + ' ' + esc(t('articles').toLowerCase()) + '</div>' +
        (o.error ? '<div class="msg">' + esc(t('refused')) + ' (' + esc(o.error) + ')</div><button class="btn light" data-act="discard" data-id="' + esc(o.client_id) + '">' + esc(t('discard')) + '</button>' : '<span class="badge wait">⏳</span>') + '</div>';
    }).join('') : '<div class="empty">' + esc(t('none')) + '</div>';
    if (outbox.length) html += '<button class="btn" data-act="refresh">' + esc(t('retry')) + '</button>';
    shell(t('queue'), html, { back: true });
  }

  // ------------------------------------------------------------------ saisie d'une livraison
  /** Articles à livrer, groupés comme dans le projet : article principal puis ses sous-articles en retrait, tous cochés par défaut. */
  function deliverRows(todo, chosen) {
    const ids = {}; todo.forEach(function (a) { ids[a.id] = true; });
    const groups = [], gmap = {};
    todo.forEach(function (a) { if (a.master && ids[a.master]) return; const g = a.group || ''; if (!gmap[g]) { gmap[g] = { name: g, rows: [] }; groups.push(gmap[g]); } gmap[g].rows.push(a); todo.filter(function (x) { return x.master === a.id; }).forEach(function (x) { gmap[g].rows.push(x); }); });
    groups.sort(function (x, y) { return x.name === y.name ? 0 : !x.name ? 1 : !y.name ? -1 : x.name.localeCompare(y.name, undefined, { numeric: true }); });
    const row = function (a) {
      const label = a.name || (a.desc || '').split('\n')[0] || a.ref;
      return '<label class="art' + (a.master && ids[a.master] ? ' sub' : '') + '"><input type="checkbox" class="pick" value="' + a.id + '"' + (!chosen || chosen[a.id] ? ' checked' : '') + '><span class="n">' + esc(label) + (a.ref && a.name ? ' <span class="hint">' + esc(a.ref) + '</span>' : '') + '</span><span class="q">' + esc(a.qty) + '</span></label>';
    };
    return groups.map(function (g) { return (groups.length > 1 || g.name ? '<h3 class="grp">' + esc(g.name || t('general')) + '</h3>' : '') + g.rows.map(row).join(''); }).join('');
  }

  function renderDeliver(id) {
    const p = findProject(id);
    if (!p) { go('#/projects'); return; }
    const pend = pendingIds(id);
    const todo = p.articles.filter(function (a) { return !a.done && !pend[a.id]; });
    if (!todo.length) { go('#/p/' + id); return; }
    const sel = selGet(id).filter(function (x) { return todo.some(function (a) { return a.id === x; }); }), preset = sel.length ? sel.reduce(function (o, x) { o[x] = true; return o; }, {}) : null;
    const names = []; (p.contacts || []).forEach(function (c) { if (c.name) names.push(c.name); }); if (p.delivery && p.delivery.contact && names.indexOf(p.delivery.contact) < 0) names.unshift(p.delivery.contact);
    const chips = names.length ? '<div class="chips" id="rnchips" style="margin:0 0 6px">' + names.slice(0, 6).map(function (n) { return '<button type="button" class="chip" data-act="rnpick" data-v="' + esc(n) + '">' + esc(n) + '</button>'; }).join('') + '</div>' : '';
    shell(t('newDelivery'),
      '<div class="card red"><h2>' + esc(t('selectArticles')) + '</h2>' + deliverRows(todo, preset) + '</div>' +
      '<form class="card" id="dform" novalidate><label class="f" for="rn">' + esc(t('receiver')) + '</label><input type="text" id="rn" autocomplete="name" placeholder="' + esc(t('receiverPh')) + '">' + chips +
      '<label class="f">' + esc(t('signature')) + '</label><div class="pad"><canvas id="pad"></canvas><div class="hint" id="padhint">' + esc(t('signHere')) + '</div></div>' +
      '<button type="button" class="btn light" id="clr">↺ ' + esc(t('clear')) + '</button><div class="msg" id="msg" aria-live="polite"></div>' +
      '<button type="submit" class="btn green" id="ok">✅ ' + esc(t('confirm')) + '</button></form>', { back: true });
    bindSignature(p);
  }

  function bindSignature(p) {
    const canvas = app.querySelector('#pad'), ctx = canvas.getContext('2d'), hint = app.querySelector('#padhint'), msg = app.querySelector('#msg');
    let drawing = false, dirty = false, last = null;
    function resize() {
      const ratio = Math.min(window.devicePixelRatio || 1, 2), keep = dirty ? canvas.toDataURL() : null;
      canvas.width = canvas.clientWidth * ratio; canvas.height = canvas.clientHeight * ratio;
      ctx.setTransform(ratio, 0, 0, ratio, 0, 0); ctx.lineWidth = 2.6; ctx.lineCap = 'round'; ctx.lineJoin = 'round'; ctx.strokeStyle = '#111827';
      if (keep) { const img = new Image(); img.onload = function () { ctx.drawImage(img, 0, 0, canvas.clientWidth, canvas.clientHeight); }; img.src = keep; }
    }
    resize();
    function pt(e) { const r = canvas.getBoundingClientRect(); return [e.clientX - r.left, e.clientY - r.top]; }
    canvas.addEventListener('pointerdown', function (e) { e.preventDefault(); canvas.setPointerCapture(e.pointerId); drawing = true; last = pt(e); ctx.beginPath(); ctx.moveTo(last[0], last[1]); ctx.lineTo(last[0] + 0.1, last[1] + 0.1); ctx.stroke(); dirty = true; hint.style.display = 'none'; });
    canvas.addEventListener('pointermove', function (e) { if (!drawing) return; const q = pt(e); ctx.beginPath(); ctx.moveTo(last[0], last[1]); ctx.lineTo(q[0], q[1]); ctx.stroke(); last = q; });
    ['pointerup', 'pointercancel', 'pointerleave'].forEach(function (ev) { canvas.addEventListener(ev, function () { drawing = false; }); });
    app.querySelector('#clr').addEventListener('click', function () { ctx.clearRect(0, 0, canvas.clientWidth, canvas.clientHeight); dirty = false; hint.style.display = ''; });

    app.querySelector('#dform').addEventListener('submit', function (e) {
      e.preventDefault();
      const name = app.querySelector('#rn').value.trim();
      const ids = Array.prototype.map.call(app.querySelectorAll('.pick:checked'), function (c) { return parseInt(c.value, 10); });
      if (!ids.length) { msg.textContent = t('pickOne'); return; }
      if (!name) { msg.textContent = t('nameMissing'); app.querySelector('#rn').focus(); return; }
      if (!dirty) { msg.textContent = t('signMissing'); return; }
      app.querySelector('#ok').disabled = true;
      const item = { client_id: uid(), deal_id: p.deal_id, article_ids: ids, receiver_name: name, signature: canvas.toDataURL('image/png'), signed_at: new Date().toISOString(), created: Date.now() };
      outPut(item).then(function () {
        outbox.push(item);
        toast(navigator.onLine ? t('saved') : t('savedQueued'));
        go('#/p/' + p.deal_id);
        sync(false);
      }).catch(function () { msg.textContent = t('syncFail'); app.querySelector('#ok').disabled = false; });
    });
  }

  function uid() {
    if (window.crypto && crypto.randomUUID) return crypto.randomUUID().replace(/-/g, '');
    return (Date.now().toString(16) + Math.random().toString(16).slice(2) + Math.random().toString(16).slice(2)).replace(/[^a-f0-9]/g, '').padEnd(32, '0').slice(0, 32);
  }

  function toast(text) {
    const el = document.createElement('div'); el.className = 'toast'; el.textContent = text; document.body.appendChild(el);
    setTimeout(function () { el.remove(); }, 4500);
  }




  // ------------------------------------------------------------------ verrouillage Face ID / Touch ID (WebAuthn, authentificateur de la plateforme)
  const LOCK = 'ispag.lock';
  let locked = !!lsGet(LOCK), hiddenAt = 0, bioAvail = null;
  function lockCfg() { return lsGet(LOCK); }
  function forgetLock() { try { localStorage.removeItem(LOCK); } catch (e) {} kvSet('lock', null).catch(function () {}); locked = false; }
  function bioSupported() { return !!(window.PublicKeyCredential && navigator.credentials && navigator.credentials.create); }
  function checkBio() {
    if (!bioSupported() || !PublicKeyCredential.isUserVerifyingPlatformAuthenticatorAvailable) { bioAvail = false; return Promise.resolve(); }
    return PublicKeyCredential.isUserVerifyingPlatformAuthenticatorAvailable().then(function (ok) { bioAvail = !!ok; }).catch(function () { bioAvail = false; });
  }
  function rnd(n) { const a = new Uint8Array(n); (window.crypto || {}).getRandomValues ? crypto.getRandomValues(a) : a.forEach(function (_, i) { a[i] = Math.floor(Math.random() * 256); }); return a; }
  function enableBio() {
    const name = (auth && auth.user && auth.user.name) || 'ISPAG';
    navigator.credentials.create({ publicKey: {
      rp: { name: 'ISPAG', id: location.hostname }, user: { id: rnd(16), name: name, displayName: name }, challenge: rnd(32),
      pubKeyCredParams: [{ type: 'public-key', alg: -7 }, { type: 'public-key', alg: -257 }],
      authenticatorSelection: { authenticatorAttachment: 'platform', userVerification: 'required', residentKey: 'discouraged' }, timeout: 60000, attestation: 'none'
    } }).then(function (cred) { const cfg = { id: bytesToB64(cred.rawId) }; lsSet(LOCK, cfg); kvSet('lock', cfg).catch(function () {}); toast(t('bioActive')); }).catch(function () { toast(t('bioFail')); }).then(renderSettings);
  }
  function disableBio() { forgetLock(); renderSettings(); }
  function unlock() {
    const cfg = lockCfg();
    if (!cfg) { locked = false; render(); return Promise.resolve(); }
    return navigator.credentials.get({ publicKey: { challenge: rnd(32), allowCredentials: [{ type: 'public-key', id: b64ToBytes(cfg.id), transports: ['internal'] }], userVerification: 'required', timeout: 60000 } })
      .then(function () { locked = false; render(); }).catch(function () { const m = app.querySelector('#msg'); if (m) m.textContent = t('bioFail'); });
  }
  function renderLock() {
    shell('', '<div class="card red" style="text-align:center;margin-top:40px"><div style="font-size:48px">🔒</div><h2>' + esc(t('locked')) + '</h2>' +
      '<button class="btn" data-act="unlock">' + esc(t('bioUnlock')) + '</button><div class="msg" id="msg" aria-live="polite"></div>' +
      '<button class="btn light" data-act="logout">' + esc(t('usePass')) + '</button></div>', { noActions: true, noTitle: true });
  }
  document.addEventListener('visibilitychange', function () {
    if (document.hidden) { hiddenAt = Date.now(); return; }
    if (lockCfg() && auth && hiddenAt && Date.now() - hiddenAt > 60000) { locked = true; render(); }
  });

  // ------------------------------------------------------------------ notifications push
  let pushState = { checked: false, supported: false, subscribed: false, denied: false, needInstall: false, key: lsGet('ispag.vapid') || '' };
  function pushCapable() { return ('serviceWorker' in navigator) && ('PushManager' in window) && ('Notification' in window); }
  function b64ToBytes(b64) { const p = '='.repeat((4 - b64.length % 4) % 4), raw = atob((b64 + p).replace(/-/g, '+').replace(/_/g, '/')), a = new Uint8Array(raw.length); for (let i = 0; i < raw.length; i++) a[i] = raw.charCodeAt(i); return a; }
  function bytesToB64(buf) { let s2 = ''; const a = new Uint8Array(buf); for (let i = 0; i < a.length; i++) s2 += String.fromCharCode(a[i]); return btoa(s2).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, ''); }

  /** État des notifications de cet appareil (sans rien demander à l'utilisateur). */
  function checkPush() {
    pushState.needInstall = !pushCapable();
    if (!pushCapable()) { pushState.checked = true; return Promise.resolve(); }
    pushState.denied = Notification.permission === 'denied';
    return navigator.serviceWorker.ready.then(function (reg) { return reg.pushManager.getSubscription(); }).then(function (sub) { pushState.subscribed = !!sub && Notification.permission === 'granted'; })
      .catch(function () {}).then(function () { pushState.checked = true; });
  }
  function fetchPushKey() {
    if (!auth || !navigator.onLine) return Promise.resolve();
    return api('/push').then(function (r) {
      if (r.status === 200) { pushState.supported = !!r.body.supported; if (r.body.key) { pushState.key = r.body.key; lsSet('ispag.vapid', r.body.key); } }
    }).catch(function () {});
  }
  function enablePush() {
    if (!navigator.onLine) { toast(t('notifOffline')); return; }
    // La permission doit être demandée directement dans le geste de l'utilisateur (iOS)
    const ask = Notification.requestPermission();
    Promise.resolve(ask).then(function (perm) {
      if (perm !== 'granted') { pushState.denied = perm === 'denied'; renderSettings(); return; }
      return fetchPushKey().then(function () {
        if (!pushState.key) { toast(t('notifNoServer')); return; }
        return navigator.serviceWorker.ready.then(function (reg) {
          return reg.pushManager.getSubscription().then(function (old) { return old || reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: b64ToBytes(pushState.key) }); });
        }).then(function (sub) {
          return api('/push/subscribe', { method: 'POST', body: { endpoint: sub.endpoint, p256dh: bytesToB64(sub.getKey('p256dh')), auth: bytesToB64(sub.getKey('auth')) } });
        }).then(function (r) {
          if (r.status === 200) { pushState.subscribed = true; toast(t('notifActive')); } else { toast(t('notifFail')); }
        });
      });
    }).catch(function () { toast(t('notifFail')); }).then(renderSettings);
  }
  function disablePush() {
    navigator.serviceWorker.ready.then(function (reg) { return reg.pushManager.getSubscription(); }).then(function (sub) {
      if (!sub) return;
      const ep = sub.endpoint;
      return sub.unsubscribe().then(function () { return navigator.onLine ? api('/push/unsubscribe', { method: 'POST', body: { endpoint: ep } }) : null; });
    }).catch(function () {}).then(function () { pushState.subscribed = false; renderSettings(); });
  }
  function testPush() {
    if (!navigator.onLine) { toast(t('notifOffline')); return; }
    api('/push/test', { method: 'POST', body: {} }).then(function (r) {
      const b = r.body || {}, box = app.querySelector('#pushdiag');
      let msg;
      if (r.status !== 200) msg = t('notifFail') + ' (' + r.status + ')';
      else if (b.ok) msg = t('notifSent') + ' (' + b.sent + '/' + b.devices + ')';
      else if (b.reason === 'no_device') msg = t('pushNoDevice');
      else if (b.reason === 'unsupported') msg = t('notifNoServer');
      else msg = t('pushRefused') + ' ' + (b.report || []).map(function (x) { return (x.host || '?') + ' → ' + (x.http || 'x') + (x.error ? ' ' + x.error : ''); }).join(' ; ');
      if (box) box.textContent = msg; else toast(msg);
    }).catch(function () { toast(t('notifFail')); });
  }

  function renderSettings() {
    let body;
    if (pushState.needInstall) body = '<p>' + esc(t('notifNeedInstall')) + '</p>';
    else if (pushState.denied) body = '<p>' + esc(t('notifDenied')) + '</p>';
    else if (pushState.supported === false && pushState.checked && !pushState.key) body = '<p>' + esc(t('notifNoServer')) + '</p>';
    else if (pushState.subscribed) body = '<p><strong>' + esc(t('notifActive')) + '</strong></p><button class="btn light" data-act="push-test">🔔 ' + esc(t('notifTest')) + '</button><button class="btn light" data-act="push-off">' + esc(t('notifOff')) + '</button><div class="hint" id="pushdiag" style="margin-top:10px;word-break:break-word"></div>';
    else body = '<button class="btn" data-act="push-on">🔔 ' + esc(t('notifOn')) + '</button>';
    let sec;
    if (lockCfg()) sec = '<p><strong>' + esc(t('bioActive')) + '</strong></p><button class="btn light" data-act="bio-off">' + esc(t('bioOff')) + '</button>';
    else if (bioAvail) sec = '<button class="btn" data-act="bio-on">🔐 ' + esc(t('bioOn')) + '</button>';
    else sec = '<p>' + esc(t('bioNone')) + '</p>';
    shell(t('notif'), '<div class="card red"><h2>' + esc(t('notif')) + '</h2>' + body + '<p class="hint" style="margin-top:12px">' + esc(t('notifHint')) + '</p></div>' +
      '<div class="card"><h2>' + esc(t('bio')) + '</h2>' + sec + '<p class="hint" style="margin-top:12px">' + esc(t('bioHint')) + '</p></div>', { back: true });
  }
  function pushBanner() {
    if (!pushState.checked || pushState.subscribed || pushState.denied || sessionStorage.getItem('ispag.nb') || !navigator.onLine) return '';
    if (!pushState.needInstall && pushState.supported === false) return '';
    return '<a class="card item banner" href="#/settings"><span class="t">' + esc(t('notifBanner')) + '</span></a>';
  }

  // ------------------------------------------------------------------ CRM : onglets, offres, contacts, tâches, notes
  function hasProjects() { return !!(snap && snap.caps ? snap.caps.projects : true); }
  function hasCrm() { return !!crm; }
  function homeHash() { return hasCrm() ? '#/home' : '#/'; }

  function tabsHtml(cur) {
    const tabs = [];
    if (hasCrm()) tabs.push(['home', '#/home', '🏠', t('dashTitle')]);
    if (hasProjects()) tabs.push(['projects', '#/projects', '📦', t('projects')]);
    if (hasCrm()) {
      tabs.push(['offers', '#/offers', '💼', t('offers')], ['contacts', '#/contacts', '👤', t('contacts')]);
      if (moreCaps().company) tabs.push(['companies', '#/companies', '🏢', t('companiesTitle')]);
      tabs.push(['tasks', '#/tasks', '✅', t('tasks')]);
    }
    if (tabs.length < 2) return '';
    return '<nav class="tabs' + (tabs.length > 5 ? ' tabs6' : '') + '">' + tabs.map(function (x) { return '<a href="' + x[1] + '"' + (x[0] === cur ? ' class="on"' : '') + '><span>' + x[2] + '</span>' + esc(x[3]) + '</a>'; }).join('') + '</nav>';
  }

  function crmLabel(o) {
    const p = o.payload || {};
    if (p.kind === 'task_done') { const tk = crm && crm.tasks.filter(function (x) { return x.id === p.task_id; })[0]; return '✅ ' + t('taskDone') + (tk ? ' : ' + tk.title : ''); }
    if (p.kind === 'task_snooze') { const tk = crm && crm.tasks.filter(function (x) { return x.id === p.task_id; })[0]; return '⏱ ' + t('task1') + (tk ? ' « ' + tk.title + ' »' : '') + ' ' + t('postponedTo') + ' ' + dateOnly(p.to); }
    if (p.kind === 'task_add') return '➕ ' + t('task1') + ' : ' + (p.title || p.content || '');
    const c = findContact(p.contact_id);
    return (({ NOTE: '📝 ', CALL: '📞 ', MEETING: '🤝 ', EMAIL: '✉️ ' })[p.type] || '📝 ') + t(({ NOTE: 'note', CALL: 'call', MEETING: 'meeting', EMAIL: 'email' })[p.type] || 'note') + (c ? ' · ' + c.name : '') + (p.outcome && T.fr['o_' + p.outcome] ? ' · ' + t('o_' + p.outcome) : '');
  }
  function findContact(id) { return crm && crm.contacts.filter(function (c) { return c.id === id; })[0]; }
  function crmPending() { return outbox.filter(function (o) { return o.kind === 'crm' && !o.error; }); }
  function queueCrm(payload, msg, then) {
    const item = { client_id: uid(), kind: 'crm', payload: Object.assign({ at: new Date().toISOString() }, payload), created: Date.now() };
    return outPut(item).then(function () { outbox.push(item); toast(navigator.onLine ? msg : t('savedQueued')); if (then) then(); sync(false); });
  }
  function dayLabel(iso) { if (!iso) return ''; const d = new Date(iso.replace(' ', 'T')); return isNaN(d) ? iso : d.toLocaleString(lang === 'de' ? 'de-CH' : lang === 'en' ? 'en-GB' : 'fr-CH', { weekday: 'short', day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }); }
  function dateOnly(iso) { if (!iso) return ''; const d = new Date(iso.replace(' ', 'T')); return isNaN(d) ? iso : d.toLocaleDateString(lang === 'de' ? 'de-CH' : lang === 'en' ? 'en-GB' : 'fr-CH'); }
  function phoneLink(ph) { return ph ? '<a href="tel:' + esc(ph.replace(/[^+\d]/g, '')) + '">' + esc(ph) + '</a>' : ''; }
  function searchBox(id) { const q = sessionStorage.getItem('ispag.q.' + id) || ''; return { q: q.toLowerCase(), html: '<input type="search" id="q" placeholder="' + esc(t('search')) + '" value="' + esc(q) + '" style="margin-bottom:12px">' }; }
  function bindSearch(id, redo) { const qi = app.querySelector('#q'); if (!qi) return; qi.addEventListener('input', function () { sessionStorage.setItem('ispag.q.' + id, qi.value); const pos = qi.selectionStart; redo(); const n = app.querySelector('#q'); n.focus(); n.setSelectionRange(pos, pos); }); }
  function noCrm(title, tab) { shell(title, '<div class="empty">' + esc(navigator.onLine ? t('sync') : t('noData')) + '</div>', { tab: tab }); }

  // --- offres des 3 derniers mois

  // ------------------------------------------------------------------ articles et prix d'une offre ou d'un projet
  const offerCache = {}, priceCache = {};
  function canPrices() { return !!(snap && snap.caps && snap.caps.prices); }
  function money(v) { return Number(v || 0).toLocaleString('fr-CH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
  /** Prix du site (brut, remise, net, total de ligne, variante) : lus en ligne, jamais gardés sur le téléphone. */
  function loadPrices(id) {
    if (!canPrices() || !navigator.onLine) return Promise.resolve(null);
    if (priceCache[id]) return Promise.resolve(priceCache[id]);
    return apiq('/prices', { id: id }).then(function (r) { if (r.status === 200 && r.body && r.body.articles) { priceCache[id] = r.body; return r.body; } return null; }).catch(function () { return null; });
  }
  /** Ajoute le prix de chaque ligne ([data-aid]) et le total de la fiche. */
  function applyPrices(root, pr) {
    if (!pr || !root) return;
    root.querySelectorAll('[data-aid]').forEach(function (el) {
      const x = pr.articles[el.getAttribute('data-aid')]; if (!x || el.querySelector('.pr')) return;
      const line = document.createElement('span');
      line.className = 'pr' + (x.variant ? ' var' : '');
      line.innerHTML = (x.variant ? '<span class="badge">' + esc(t('variantB')) + '</span> ' : '') + (x.discount > 0 ? '<span class="hint">−' + esc(String(x.discount)) + '%</span> ' : '') +
        '<span class="hint">× ' + esc(money(x.net)) + '</span> <strong>' + esc(money(x.total)) + '</strong>';
      const n = el.querySelector('.n') || el; n.appendChild(line);
    });
    const first = root.querySelector('[data-aid]'), card = first ? first.closest('.card') : null;   // carte des articles
    if (card && pr.total != null && !card.querySelector('.ptotal')) {
      const d = document.createElement('div'); d.className = 'kv ptotal';
      d.innerHTML = '<span>' + esc(t('pricesTotal')) + '</span><strong>' + esc(money(pr.total)) + ' ' + esc(pr.currency || 'CHF') + '</strong>';
      const last = card.lastElementChild; if (last && last.classList.contains('btn')) card.insertBefore(d, last); else card.appendChild(d);
    }
  }
  /** Articles d'une offre, par groupe (comme la fiche du site) : sous-articles à la suite de leur article principal. */
  function offerArticlesHtml(p, id) {
    const byId = {}; p.articles.forEach(function (a) { byId[a.id] = a; });
    const groups = [], gmap = {};
    p.articles.forEach(function (a) {
      const m = a.master && byId[a.master]; const g = (m ? m.group : a.group) || '';
      if (!gmap[g]) { gmap[g] = { name: g, mains: [], subs: {} }; groups.push(gmap[g]); }
      if (m) (gmap[g].subs[a.master] = gmap[g].subs[a.master] || []).push(a); else gmap[g].mains.push(a);
    });
    groups.sort(function (x, y) { return x.name === y.name ? 0 : !x.name ? 1 : !y.name ? -1 : x.name.localeCompare(y.name, undefined, { numeric: true }); });
    const row = function (a, sub) {
      const first = a.name || (a.desc || '').split('\n')[0];
      const body = '<span class="n">' + esc(first) + (a.ref ? ' <span class="hint">' + esc(a.ref) + '</span>' : '') + '</span><span class="q">× ' + esc(String(a.qty)) + '</span>';
      return findProject(id) ? '<a class="art item' + (sub ? ' sub' : '') + '" data-aid="' + a.id + '" href="#/p/' + id + '/a/' + a.id + '" style="text-decoration:none;color:inherit">' + body + '</a>'
        : '<div class="art' + (sub ? ' sub' : '') + '" data-aid="' + a.id + '">' + body + '</div>';
    };
    return groups.length ? groups.map(function (g) {
      return (groups.length > 1 || g.name ? '<h3 class="grp">' + esc(g.name || t('general')) + '</h3>' : '') +
        g.mains.map(function (a) { return row(a, false) + (g.subs[a.id] || []).map(function (x) { return row(x, true); }).join(''); }).join('');
    }).join('') : '<div class="empty">' + esc(t('artNone')) + '</div>';
  }
  function fillOfferArticles(id) {
    const box = app.querySelector('#oart'); if (!box) return;
    const show = function (p) {
      if (!app.querySelector('#oart')) return;
      box.innerHTML = '<h2>' + esc(t('artTitle')) + ' · ' + p.articles.length + '</h2>' + offerArticlesHtml(p, id) +
        '<a class="btn light" href="#/p/' + id + '" style="display:block;text-align:center;text-decoration:none;margin-top:10px">' + esc(t('artFull')) + '</a>';
      loadPrices(id).then(function (pr) { applyPrices(box, pr); });
    };
    const p = findProject(id) || offerCache[id];
    if (p) { show(p); return; }
    if (!navigator.onLine) { box.innerHTML = '<h2>' + esc(t('artTitle')) + '</h2><div class="empty">' + esc(t('artOnline')) + '</div>'; return; }
    apiq('/project', { id: id }).then(function (r) {
      if (r.status === 200 && r.body.project) { offerCache[id] = r.body.project; show(r.body.project); } else box.innerHTML = '<h2>' + esc(t('artTitle')) + '</h2><div class="empty">' + esc(t('artNone')) + '</div>';
    }).catch(function () { box.innerHTML = '<h2>' + esc(t('artTitle')) + '</h2><div class="empty">' + esc(t('artOnline')) + '</div>'; });
  }

  function renderOffers() {
    if (!crm) { noCrm(t('offers'), 'offers'); return; }
    const sb = searchBox('offers');
    const list = crm.offers.filter(function (o) { return !sb.q || (o.name + ' ' + o.company + ' ' + o.ref + ' ' + o.stage).toLowerCase().indexOf(sb.q) >= 0; });
    let html = sb.html + (list.length ? list.map(function (o) {
      return '<a class="card item" href="#/o/' + encodeURIComponent(o.ref) + '"><div class="t">' + esc(o.name || o.ref) + '</div><div class="s">' + esc([o.company, o.stage].filter(Boolean).join(' · ')) + '</div>' +
        '<div class="s">' + esc(dateOnly(o.created)) + (o.amount != null ? '<span class="badge">' + esc(Math.round(o.amount).toLocaleString('fr-CH')) + ' CHF</span>' : '') + '</div></a>';
    }).join('') : '<div class="empty">' + esc(t('noOffers')) + '</div>');
    html += '<div id="oremote"></div>';
    shell(t('offers'), html, { tab: 'offers' });
    bindSearch('offers', renderOffers);
    olderOffers(sb.q, list);
  }
  // offres de plus de 3 mois : recherche en ligne (même route que les projets), uniquement les offres absentes de la liste
  const olderState = { timer: 0, q: '', list: [] };
  function olderOffers(q, shown) {
    clearTimeout(olderState.timer);
    const el = app.querySelector('#oremote');
    if (!el || !q || q.length < 2 || !navigator.onLine || !auth) { if (el) el.innerHTML = ''; return; }
    const known = {}; shown.forEach(function (o) { known[parseInt(o.ref, 10)] = true; });
    function paint() {
      if (!app.contains(el)) return;
      const rows = olderState.list.filter(function (r) { return r.is_offer && !known[r.deal_id]; });
      el.innerHTML = rows.length ? '<h3 class="grp">' + esc(t('onlineRes')) + '</h3>' + rows.map(function (r) { return projCard(r, false); }).join('') : '';
    }
    if (olderState.q === q) { paint(); return; }
    el.innerHTML = '<p class="hint">' + esc(t('searching')) + '</p>';
    olderState.timer = setTimeout(function () {
      apiq('/search', { q: q }).then(function (r) {
        olderState.q = q; olderState.list = (r.status === 200 && r.body.results) || []; paint();
      }).catch(function () { el.innerHTML = ''; });
    }, 350);
  }
  function renderOffer(ref) {
    const o = crm && crm.offers.filter(function (x) { return x.ref === ref; })[0];
    if (!o) { go('#/offers'); return; }
    let html = '<div class="card red"><div class="kv"><span>' + esc(t('company')) + '</span>' + (o.company_id && moreCaps().company ? '<a href="#/co/' + o.company_id + '"><strong>' + esc(o.company) + ' ›</strong></a>' : '<strong>' + esc(o.company) + '</strong>') + '</div>' +
      '<div class="kv"><span>' + esc(t('stage')) + '</span><strong>' + esc(o.stage) + '</strong></div>' +
      '<div class="kv"><span>' + esc(t('created')) + '</span><strong>' + esc(dateOnly(o.created)) + '</strong></div>' +
      (o.closing ? '<div class="kv"><span>' + esc(t('closing')) + '</span><strong>' + esc(dateOnly(o.closing)) + '</strong></div>' : '') +
      (o.amount != null ? '<div class="kv"><span>' + esc(t('amount')) + '</span><strong>' + esc(Math.round(o.amount).toLocaleString('fr-CH')) + ' CHF</strong></div>' : '') + '</div>';
    if (o.contacts.length) {
      html += '<div class="card"><h2>' + esc(t('offerContacts')) + '</h2>' + o.contacts.map(function (c) {
        const full = findContact(c.id);
        return '<a class="art item" href="#/c/' + c.id + '" style="text-decoration:none;color:inherit"><span class="n">' + esc(c.name) + (full && full.phone ? ' <span class="hint">' + esc(full.phone) + '</span>' : '') + '</span><span class="q">›</span></a>';
      }).join('') + '</div>';
    }
    if (o.contacts.length) html += '<a class="btn light" href="#/c/' + o.contacts[0].id + '/new/CALL/' + encodeURIComponent(o.ref) + '" style="display:block;text-align:center;text-decoration:none;margin:0 0 12px">📝 ' + esc(t('offerNote')) + '</a>';
    if (crm.stages && crm.stages.length) {
      html += '<div class="card"><h2>' + esc(t('stageTitle')) + '</h2><select id="stsel">' + crm.stages.map(function (x) { return '<option value="' + esc(x.key) + '"' + (x.key === o.stage_key ? ' selected' : '') + '>' + esc(x.label) + '</option>'; }).join('') + '</select>' +
        '<textarea id="streason" rows="3" placeholder="' + esc(t('stageReason')) + '" style="display:none;margin-top:8px"></textarea>' +
        '<button class="btn" data-act="offer-stage" data-ref="' + esc(o.ref) + '" style="margin-top:8px">' + esc(t('stageChange')) + '</button></div>';
    }
    const dealId = parseInt(o.ref, 10);
    html += '<div class="card" id="ofollow"><h2>' + esc(t('dealFollow')) + '</h2><div class="empty">' + esc(t('artLoading')) + '</div></div>';
    if (dealId > 0) html += '<div class="card" id="oart"><h2>' + esc(t('artTitle')) + '</h2><div class="empty">' + esc(t('artLoading')) + '</div></div>';
    shell(o.name || o.ref, html, { back: true });
    if (dealId > 0) fillOfferArticles(dealId);
    fillOfferFollow(o.ref);
    const sel = app.querySelector('#stsel');
    if (sel) sel.addEventListener('change', function () { app.querySelector('#streason').style.display = sel.value === 'closed_lost' ? 'block' : 'none'; });
  }
  function changeStage(ref) {
    const o = crm && crm.offers.filter(function (x) { return x.ref === ref; })[0], sel = app.querySelector('#stsel');
    if (!o || !sel || sel.value === o.stage_key) return;
    if (!navigator.onLine) { toast(t('stageOnline')); return; }
    const reason = (app.querySelector('#streason').value || '').trim(), label = sel.options[sel.selectedIndex].text;
    if (sel.value === 'closed_lost' && !reason) { toast(t('stageReasonMissing')); return; }
    ispagConfirm(t('stageConfirm').replace('%1', label), { text: true }).then(function (ok) {
      if (!ok) return;
      api('/crm-stage', { method: 'POST', body: { ref: ref, stage: sel.value, reason: reason } }).then(function (r) {
        if (r.status !== 200) { toast(t('stageFail')); return; }
        o.stage_key = sel.value; o.stage = label; kvSet('crm', crm); toast(t('stageDone')); renderOffer(ref);
      }).catch(function () { toast(t('stageFail')); });
    });
  }
  // suivi de l'offre : tâches ouvertes du snapshot (hors ligne) + activités et tâches du serveur (en ligne)
  function fillOfferFollow(ref) {
    const box = app.querySelector('#ofollow');
    if (!box) return;
    const head = '<h2>' + esc(t('dealFollow')) + '</h2>';
    function draw(tasks, acts, offline) {
      if (!app.contains(box)) return;
      let h = head;
      if (tasks.length) h += '<div class="hint" style="margin:4px 0">' + esc(t('dealTasks')) + '</div>' + tasks.map(function (x) {
        return '<div class="art"><span class="n">' + (x.done ? '✓ ' : '') + (x.id ? '<a href="#/t/' + x.id + '">' + esc(x.title) + '</a>' : esc(x.title)) + '<br><span class="hint">' + esc(dayLabel(x.due)) + '</span></span></div>';
      }).join('');
      if (acts.length) h += '<div class="hint" style="margin:8px 0 4px">' + esc(t('activities')) + '</div>' + acts.map(function (a) {
        return '<div class="art"><span class="n"><strong>' + esc(({ NOTE: t('note'), CALL: t('call'), MEETING: t('meeting'), EMAIL: t('email') })[a.t] || a.t) + '</strong>' + (a.o && T.fr['o_' + a.o] ? ' <span class="badge">' + esc(t('o_' + a.o)) + '</span>' : '') + ' <span class="hint">' + esc(dateOnly(a.at)) + '</span><br>' + esc(a.x) + '</span></div>';
      }).join('');
      if (!tasks.length && !acts.length) h += '<div class="empty">' + esc(t(offline ? 'dealOnline' : 'dealNoFollow')) + '</div>';
      else if (offline) h += '<p class="hint">' + esc(t('dealOnline')) + '</p>';
      box.innerHTML = h;
    }
    const local = (crm ? crm.tasks : []).filter(function (x) { return String(x.deal) === String(ref); }).map(function (x) { return { id: x.id, title: x.title, due: x.due, done: false }; });
    if (!navigator.onLine) { draw(local, [], true); return; }
    apiq('/crm-deal', { ref: ref }).then(function (r) {
      if (r.status === 200 && r.body) draw(r.body.tasks || [], r.body.acts || [], false); else draw(local, [], true);
    }).catch(function () { draw(local, [], true); });
  }

  // --- contacts
  function renderContacts() {
    if (!crm) { noCrm(t('contacts'), 'contacts'); return; }
    const sb = searchBox('contacts');
    const list = crm.contacts.filter(function (c) { return !sb.q || (c.name + ' ' + c.company + ' ' + c.email + ' ' + c.phone).toLowerCase().indexOf(sb.q) >= 0; });
    const html = sb.html + (list.length ? list.slice(0, 200).map(function (c) {
      return '<a class="card item" href="#/c/' + c.id + '"><div class="t">' + esc(c.name) + '</div><div class="s">' + esc([c.company, c.function].filter(Boolean).join(' · ')) + '</div></a>';
    }).join('') + (list.length > 200 ? '<p class="hint" style="text-align:center">' + esc(t('search')) + '</p>' : '') : '<div class="empty">' + esc(t('noContacts')) + '</div>');
    shell(t('contacts'), html, { tab: 'contacts' });
    bindSearch('contacts', renderContacts);
  }
  function renderContact(id) {
    const c = findContact(id);
    if (!c) { go('#/contacts'); return; }
    const queued = crmPending().filter(function (o) { return o.payload.kind === 'note' && o.payload.contact_id === id; });
    let html = '<div class="card red">' +
      (c.company ? '<div class="kv"><span>' + esc(t('company')) + '</span>' + (c.company_id && moreCaps().company ? '<a href="#/co/' + c.company_id + '"><strong>' + esc(c.company) + ' ›</strong></a>' : '<strong>' + esc(c.company) + '</strong>') + '</div>' : '') +
      (c.function ? '<div class="kv"><span>' + esc(t('func')) + '</span><strong>' + esc(c.function) + '</strong></div>' : '') +
      (c.phone ? '<div class="kv"><span>' + esc(t('phone')) + '</span>' + phoneLink(c.phone) + '</div>' : '') +
      (c.email ? '<div class="kv"><span>' + esc(t('mail')) + '</span><a href="mailto:' + esc(c.email) + '">' + esc(c.email) + '</a></div>' : '') + '</div>';
    html += '<div class="grid2">' + (c.phone ? '<a class="btn" href="tel:' + esc(c.phone.replace(/[^+\d]/g, '')) + '">📞 ' + esc(t('callNow')) + '</a>' : '') +
      '<a class="btn light" href="#/c/' + id + '/new/CALL">📝 ' + esc(t('call')) + ' / ' + esc(t('note')) + '</a></div>' +
      '<a class="btn light" href="#/tasks/new/' + id + '" style="display:block;text-align:center;text-decoration:none;margin-top:10px">➕ ' + esc(t('newTask')) + '</a>';
    html += '<div class="card" style="margin-top:12px"><h2>' + esc(t('activities')) + '</h2>' +
      queued.map(function (o) { return '<div class="art"><span class="n">⏳ ' + esc(crmLabel(o)) + '<br><span class="hint">' + esc(o.payload.content) + '</span></span></div>'; }).join('') +
      (c.acts.length ? c.acts.map(function (a) {
        return '<div class="art"><span class="n"><strong>' + esc(({ NOTE: t('note'), CALL: t('call'), MEETING: t('meeting'), EMAIL: t('email') })[a.t] || a.t) + '</strong>' + (a.o && T.fr['o_' + a.o] ? ' <span class="badge">' + esc(t('o_' + a.o)) + '</span>' : '') + ' <span class="hint">' + esc(dateOnly(a.at)) + '</span><br>' + esc(a.x) + '</span></div>';
      }).join('') : (queued.length ? '' : '<div class="empty">' + esc(t('noActs')) + '</div>')) + '</div>';
    shell(c.name, html, { back: true });
  }
  const OUT = { CALL: ['connected', 'left_live_message', 'left_voicemail', 'no_answer', 'busy', 'wrong_number'], MEETING: ['scheduled', 'completed', 'rescheduled', 'no_show', 'canceled'] };
  function nowLocal() { const d = new Date(), p2 = function (n) { return String(n).padStart(2, '0'); }; return d.getFullYear() + '-' + p2(d.getMonth() + 1) + '-' + p2(d.getDate()) + 'T' + p2(d.getHours()) + ':' + p2(d.getMinutes()); }
  // Collègues ISPAG présents (liste fournie par le serveur avec les données du téléphone) : cases à cocher
  function teamPicker() {
    const team = (crm && crm.team) || [];
    if (!team.length) return '';
    return '<label class="f">' + esc(t('teamPresent')) + '</label><div class="chips">' + team.map(function (m) {
      return '<label class="chip" style="cursor:pointer"><input type="checkbox" class="team-cb" value="' + m.id + '" style="width:auto;margin-right:6px">' + esc(m.name) + '</label>';
    }).join('') + '</div>';
  }
  function renderNoteForm(id, type, deal, fromRun) {
    const c = findContact(id);
    if (!c) { go('#/contacts'); return; }
    const types = [['CALL', t('call')], ['MEETING', t('meeting')], ['NOTE', t('note')], ['EMAIL', t('email')]];
    shell(t('newNote') + ' · ' + c.name,
      '<form class="card red" id="nform" novalidate><label class="f" for="nt">' + esc(t('noteType')) + '</label><select id="nt">' + types.map(function (x) { return '<option value="' + x[0] + '"' + (x[0] === type ? ' selected' : '') + '>' + esc(x[1]) + '</option>'; }).join('') + '</select>' +
      '<div id="extra"></div>' + teamPicker() +
      '<label class="f" for="nx">' + esc(t('noteText')) + '</label><textarea id="nx" rows="6"></textarea><div class="msg" id="msg" aria-live="polite"></div>' +
      '<button type="submit" class="btn green">✅ ' + esc(t('saveNote')) + '</button></form>', { back: true });
    const sel = app.querySelector('#nt'), extra = app.querySelector('#extra');
    function drawExtra() {
      const ty = sel.value, list = OUT[ty];
      extra.innerHTML = list ? '<label class="f" for="no">' + esc(t('outcome')) + '</label><select id="no">' + list.map(function (o) { return '<option value="' + o + '"' + (o === (ty === 'CALL' ? 'connected' : 'completed') ? ' selected' : '') + '>' + esc(t('o_' + o)) + '</option>'; }).join('') + '</select>' +
        '<label class="f" for="nd">' + esc(t('when')) + '</label><input type="datetime-local" id="nd" value="' + nowLocal() + '">' : '';
    }
    sel.addEventListener('change', drawExtra); drawExtra();
    app.querySelector('#nform').addEventListener('submit', function (e) {
      e.preventDefault();
      const text = app.querySelector('#nx').value.trim();
      if (!text) { app.querySelector('#msg').textContent = t('noteMissing'); return; }
      app.querySelector('.btn').disabled = true;
      const ty = sel.value, payload = { kind: 'note', type: ty, contact_id: id, content: text };
      if (deal) payload.deal = deal;
      const team = Array.prototype.map.call(app.querySelectorAll('.team-cb:checked'), function (x) { return parseInt(x.value, 10); });
      if (team.length) payload.team = team;
      if (OUT[ty]) { payload.outcome = app.querySelector('#no').value; const dv = app.querySelector('#nd').value; if (dv) { payload.when = dv.replace('T', ' '); payload.at = new Date(dv).toISOString(); } }
      queueCrm(payload, t('noteSaved'), function () { go(fromRun ? '#/run' : deal ? '#/o/' + encodeURIComponent(deal) : '#/c/' + id); });
    });
  }

  // --- écrans en lecture seule (société, stock, commandes fournisseurs) : lus en ligne, jamais gardés sur le téléphone
  function moreCaps() { return (snap && snap.caps && snap.caps.more) || {}; }
  function loadScreen(title, tab, path, params, draw) {
    if (!navigator.onLine) { shell(title, '<div class="empty">' + esc(t('onlineOnly')) + '</div>', { tab: tab, back: !tab }); return; }
    shell(title, '<div class="empty">' + esc(t('artLoading')) + '</div>', { tab: tab, back: !tab });
    const hash = location.hash;
    apiq(path, params).then(function (r) {
      if (location.hash !== hash) return;
      if (r.status === 200) draw(r.body); else shell(title, '<div class="empty">' + esc(t(r.status === 404 ? 'coNone' : 'loadFail')) + '</div>', { tab: tab, back: !tab });
    }).catch(function () { if (location.hash === hash) shell(title, '<div class="empty">' + esc(t('loadFail')) + '</div>', { tab: tab, back: !tab }); });
  }
  function moneyCh(v) { return Math.round(v || 0).toLocaleString('fr-CH'); }
  // Liste des entreprises : recherche par nom (en ligne) ; sans recherche, les entreprises des offres les plus récentes
  function renderCompanies() {
    const q = sessionStorage.getItem('ispag.q.companies') || '';
    loadScreen(t('companiesTitle'), 'companies', '/companies', { q: q }, function (d) {
      let h = '<input type="search" id="q" placeholder="' + esc(t('search')) + '" value="' + esc(q) + '" style="margin-bottom:10px">';
      h += d.results.length ? d.results.map(function (c) {
        return '<a class="card item" href="#/co/' + c.id + '"><div class="t">' + esc(c.name) + (c.active === false ? ' <span class="badge">' + esc(t('coInactive')) + '</span>' : '') + '</div>' + (c.city ? '<div class="s">' + esc(c.city) + '</div>' : '') + '</a>';
      }).join('') : '<div class="empty">' + esc(t('noCompanies')) + '</div>';
      shell(t('companiesTitle'), h, { tab: 'companies' });
      const qi = app.querySelector('#q');
      qi.addEventListener('change', function () { sessionStorage.setItem('ispag.q.companies', qi.value.trim()); renderCompanies(); });
    });
  }
  function renderCompany(id) {
    loadScreen(t('coTitle'), '', '/company', { id: id }, function (c) {
      let h = '<div class="card red"><h2 style="text-transform:none;letter-spacing:0;font-size:18px">' + esc(c.name) + '</h2>' +
        (c.address ? '<div class="kv"><span>📍</span><a href="https://maps.apple.com/?q=' + encodeURIComponent(c.address) + '">' + esc(c.address) + '</a></div>' : '') +
        (c.phone ? '<div class="kv"><span>' + esc(t('phone')) + '</span>' + phoneLink(c.phone) + '</div>' : '') +
        (c.email ? '<div class="kv"><span>' + esc(t('mail')) + '</span><a href="mailto:' + esc(c.email) + '">' + esc(c.email) + '</a></div>' : '') + '</div>';
      if (c.contacts.length) h += '<div class="card"><h2>' + esc(t('coContacts')) + ' · ' + c.contacts.length + '</h2>' + c.contacts.map(function (k) {
        return '<a class="art item" href="#/c/' + k.id + '" style="text-decoration:none;color:inherit"><span class="n"><strong>' + esc(k.name) + '</strong><br><span class="hint">' + esc([k['function'], k.phone].filter(Boolean).join(' · ')) + '</span></span><span class="q">›</span></a>';
      }).join('') + '</div>';
      if (c.offers.length) h += '<div class="card"><h2>' + esc(t('coOffers')) + '</h2>' + c.offers.map(function (o) {
        const own = crm && crm.offers.some(function (x) { return x.ref === o.ref; });
        const inner = '<span class="n"><strong>' + esc(o.name || o.ref) + '</strong><br><span class="hint">' + esc(dateOnly(o.created)) + (o.amount != null ? ' · ' + moneyCh(o.amount) + ' CHF' : '') + '</span></span>';
        return own ? '<a class="art item" href="#/o/' + encodeURIComponent(o.ref) + '" style="text-decoration:none;color:inherit">' + inner + '<span class="q">›</span></a>' : '<div class="art">' + inner + '</div>';
      }).join('') + '</div>';
      if (c.projects.length) h += '<div class="card"><h2>' + esc(t('coProjects')) + '</h2>' + c.projects.map(function (p) {
        return '<a class="art item" href="#/p/' + p.deal_id + '" style="text-decoration:none;color:inherit"><span class="n"><strong>' + esc(p.title || p.number) + '</strong>' + (p.is_offer ? ' <span class="badge offer">' + esc(t('offer1')) + '</span>' : '') + (p.closed ? ' <span class="badge wait">' + esc(t('closedB')) + '</span>' : '') + '<br><span class="hint">' + esc(p.number + ' · ' + dateOnly(p.created)) + '</span></span><span class="q">›</span></a>';
      }).join('') + '</div>';
      h += '<div class="card"><h2>' + esc(t('coStatus')) + '</h2>' +
        (c.can_edit
          ? '<div class="kv"><span>' + esc(t('coActiveOn')) + '</span><button type="button" class="chip' + (c.active ? ' on' : '') + '" id="co-active">' + (c.active ? 'ON' : 'OFF') + '</button></div><div class="hint" style="margin-top:6px">' + esc(t('coActiveHint')) + '</div>'
          : '<div class="kv"><span>' + esc(t('coActiveOn')) + '</span><strong>' + (c.active ? 'ON' : 'OFF') + '</strong></div>') + '</div>';
      const g = c.grouped;
      if (g && g.available) {
        const dayOpts = [7, 10, 14, 21, 28, 30, 45, 60, 90]; if (dayOpts.indexOf(g.days) < 0) dayOpts.push(g.days);
        dayOpts.sort(function (a, b) { return a - b; });
        h += '<div class="card"><h2>' + esc(t('groupedTitle')) + '</h2>' +
          (g.can_edit
            ? '<div class="kv"><span>' + esc(t('groupedOn')) + '</span><button type="button" class="chip' + (g.on ? ' on' : '') + '" id="gf-on">' + (g.on ? 'ON' : 'OFF') + '</button></div>' +
              '<div class="kv" id="gf-days-row"' + (g.on ? '' : ' style="display:none"') + '><span>' + esc(t('groupedEvery')) + '</span><select id="gf-days" style="width:auto">' + dayOpts.map(function (d) { return '<option value="' + d + '"' + (d === g.days ? ' selected' : '') + '>' + d + ' ' + esc(t('groupedDays')) + '</option>'; }).join('') + '</select></div>'
            : '<div class="kv"><span>' + esc(t('groupedOn')) + '</span><strong>' + (g.on ? esc(t('groupedEvery')) + ' ' + g.days + ' ' + esc(t('groupedDays')) : 'OFF') + '</strong></div>') +
          '<div class="hint" style="margin-top:6px">' + esc(t('groupedHint')) + '</div></div>';
      }
      shell(c.name, h, { back: true });
      const act = app.querySelector('#co-active');
      if (act) act.addEventListener('click', function () {
        const next = !act.classList.contains('on');
        ispagConfirm(t(next ? 'coActivate' : 'coDeactivate'), { text: true }).then(function (ok) {
          if (!ok) return;
          api('/company-active', { method: 'POST', body: { id: c.id, active: next } }).then(function (r) {
            if (r.status === 200) { act.classList.toggle('on', r.body.active); act.textContent = r.body.active ? 'ON' : 'OFF'; toast(t('groupedSaved')); } else toast(t('groupedFail'));
          }).catch(function () { toast(t('groupedFail')); });
        });
      });
      if (g && g.available && g.can_edit) {
        const btn = app.querySelector('#gf-on'), sel = app.querySelector('#gf-days'), row = app.querySelector('#gf-days-row');
        const save = function (body, done) {
          api('/company-grouped', { method: 'POST', body: Object.assign({ id: c.id }, body) }).then(function (r) {
            if (r.status === 200) { toast(t('groupedSaved')); if (done) done(r.body); } else toast(t('groupedFail'));
          }).catch(function () { toast(t('groupedFail')); });
        };
        btn.addEventListener('click', function () {
          const next = !btn.classList.contains('on');
          save({ enabled: next, days: parseInt(sel.value, 10) }, function (b) { btn.classList.toggle('on', b.on); btn.textContent = b.on ? 'ON' : 'OFF'; row.style.display = b.on ? '' : 'none'; });
        });
        sel.addEventListener('change', function () { save({ days: parseInt(sel.value, 10) }); });
      }
    });
  }
  function renderStock() {
    const q = sessionStorage.getItem('ispag.q.stock') || '', loc = sessionStorage.getItem('ispag.loc') || '';
    loadScreen(t('stockTitle'), '', '/stock', { q: q }, function (d) {
      const rows = d.rows.filter(function (r) { return !loc || (r.by[loc] || 0) !== 0; });
      let h = '<input type="search" id="q" placeholder="' + esc(t('search')) + '" value="' + esc(q) + '" style="margin-bottom:10px">' +
        '<div class="chips"><button class="chip' + (loc ? '' : ' on') + '" data-act="stock-loc" data-v="">' + esc(t('stockAll')) + '</button>' + d.locations.map(function (l) { return '<button class="chip' + (String(l.id) === loc ? ' on' : '') + '" data-act="stock-loc" data-v="' + l.id + '">' + esc(l.name) + '</button>'; }).join('') + '</div>';
      h += rows.length ? '<div class="card">' + rows.map(function (r) {
        const where = d.locations.filter(function (l) { return r.by[l.id]; }).map(function (l) { return l.name + ' : ' + r.by[l.id]; }).join(' · ');
        return '<div class="art"><span class="n"><strong>' + esc(r.title) + '</strong><br><span class="hint">' + esc([r.ref, where].filter(Boolean).join(' — ')) + '</span></span><span class="q">' + esc(String(loc ? (r.by[loc] || 0) : r.total)) + '</span></div>';
      }).join('') + '</div>' : '<div class="empty">' + esc(t('stockNone')) + '</div>';
      shell(t('stockTitle'), h, { back: true });
      const qi = app.querySelector('#q');
      qi.addEventListener('change', function () { sessionStorage.setItem('ispag.q.stock', qi.value.trim()); renderStock(); });
    });
  }
  function renderBuy() {
    const q = sessionStorage.getItem('ispag.q.buy') || '';
    loadScreen(t('buyTitle'), '', '/purchases', { q: q }, function (d) {
      let h = '<input type="search" id="q" placeholder="' + esc(t('search')) + '" value="' + esc(q) + '" style="margin-bottom:10px">';
      h += d.results.length ? d.results.map(function (o) {
        return '<a class="card item" href="#/buy/' + o.id + '"><div class="t">' + esc(o.ref || o.number) + '</div><div class="s">' + esc([o.supplier, o.state].filter(Boolean).join(' · ')) + '</div>' +
          '<div class="s">' + esc(dateOnly(o.created)) + (o.due ? ' · ' + esc(t('buyDue')) + ' ' + esc(dateOnly(o.due)) : '') + '<span class="badge">' + moneyCh(o.total) + '</span></div></a>';
      }).join('') : '<div class="empty">' + esc(t('buyNone')) + '</div>';
      shell(t('buyTitle'), h, { back: true });
      const qi = app.querySelector('#q');
      qi.addEventListener('change', function () { sessionStorage.setItem('ispag.q.buy', qi.value.trim()); renderBuy(); });
    });
  }
  function renderBuyOrder(id) {
    loadScreen(t('buyTitle'), '', '/purchase', { id: id }, function (d) {
      const o = d.order;
      let h = '<div class="card red"><div class="kv"><span>' + esc(t('buyTitle')) + '</span><strong>' + esc(o.ref || o.number) + '</strong></div>' +
        '<div class="kv"><span>' + esc(t('buySupplier')) + '</span><strong>' + esc(o.supplier) + '</strong></div>' +
        '<div class="kv"><span>' + esc(t('stage')) + '</span><strong>' + esc(o.state) + '</strong></div>' +
        '<div class="kv"><span>' + esc(t('created')) + '</span><strong>' + esc(dateOnly(o.created)) + '</strong></div>' +
        (o.due ? '<div class="kv"><span>' + esc(t('buyDue')) + '</span><strong>' + esc(dateOnly(o.due)) + (o.confirmed ? ' ✓' : '') + '</strong></div>' : '') +
        '<div class="kv"><span>Total</span><strong>' + moneyCh(o.total) + '</strong></div>' +
        (o.remark ? '<div class="desc" style="margin-top:8px">' + esc(o.remark) + '</div>' : '') + '</div>';
      h += '<div class="card"><h2>' + esc(t('buyLines')) + ' · ' + d.lines.length + '</h2>' + (d.lines.length ? d.lines.map(function (l) {
        return '<div class="art"><span class="n"><strong>' + esc(l.title) + '</strong>' + (l.desc ? '<br><span class="hint">' + esc(l.desc) + '</span>' : '') + '<br><span class="hint">' + l.received + '/' + l.qty + ' ' + esc(t('buyReceived')) + (l.due ? ' · ' + esc(dateOnly(l.due)) + (l.confirmed ? ' ✓' : '') : '') + '</span></span><span class="q">× ' + l.qty + '</span></div>';
      }).join('') : '<div class="empty">' + esc(t('buyNone')) + '</div>') + '</div>';
      shell(o.ref || o.number, h, { back: true });
    });
  }
  function moreCard() {
    const m = moreCaps(), links = [];
    if (m.stock) links.push(['#/stock', '📦', t('stockTitle')]);
    if (m.purchases) links.push(['#/buy', '🧾', t('buyTitle')]);
    return links.length ? '<div class="card"><h2>' + esc(t('moreTitle')) + '</h2>' + links.map(function (l) {
      return '<a class="art item" href="' + l[0] + '" style="text-decoration:none;color:inherit"><span class="n">' + l[1] + ' ' + esc(l[2]) + '</span><span class="q">›</span></a>';
    }).join('') + '</div>' : '';
  }

  // --- tableau de bord du jour (tâches en retard / du jour, offres à clôturer) : tout vient du dernier instantané, donc hors ligne aussi
  function renderHome() {
    if (!crm) { noCrm(t('dashTitle'), 'home'); return; }
    const done = {}, moved = {};
    crmPending().forEach(function (o) { if (o.payload.kind === 'task_done') done[o.payload.task_id] = true; else if (o.payload.kind === 'task_snooze') moved[o.payload.task_id] = o.payload.to; });
    const now = Date.now(), end = new Date(); end.setHours(23, 59, 59, 999);
    const over = [], today = [];
    crm.tasks.filter(function (x) { return !done[x.id]; }).forEach(function (x) {
      const d = effDue(x, moved), ts = d ? new Date(d.replace(' ', 'T')).getTime() : NaN;
      if (isNaN(ts)) return;
      (ts < now ? over : ts <= end.getTime() ? today : []).push({ x: x, due: d });
    });
    const horizon = Date.now() + 14 * 864e5;
    const closing = crm.offers.filter(function (o) { const ts = o.closing ? new Date(o.closing.replace(' ', 'T')).getTime() : NaN; return !isNaN(ts) && ts <= horizon && ts >= now - 864e5 && o.status !== 0; })
      .sort(function (a, b) { return a.closing.localeCompare(b.closing); }).slice(0, 8);
    function trow(r) { return '<a class="art item" href="#/t/' + r.x.id + '" style="text-decoration:none;color:inherit"><span class="n">' + esc(r.x.title) + '<br><span class="hint">' + esc(dayLabel(r.due)) + (r.x.contact_name ? ' · ' + esc(r.x.contact_name) : '') + '</span></span><span class="q">›</span></a>'; }
    let html = (over.length + today.length ? '<a class="btn green" href="#/run/new" style="display:block;text-align:center;text-decoration:none;margin:0 0 12px">▶ ' + esc(t('runStart')) + ' · ' + (over.length + today.length) + '</a>' : '');
    if (over.length) html += '<div class="card red"><h2>' + esc(t('dashOver')) + ' · ' + over.length + '</h2>' + over.slice(0, 6).map(trow).join('') + '</div>';
    if (today.length) html += '<div class="card"><h2>' + esc(t('dashToday')) + ' · ' + today.length + '</h2>' + today.slice(0, 6).map(trow).join('') + '</div>';
    if (closing.length) html += '<div class="card"><h2>' + esc(t('dashClosing')) + '</h2>' + closing.map(function (o) {
      return '<a class="art item" href="#/o/' + encodeURIComponent(o.ref) + '" style="text-decoration:none;color:inherit"><span class="n"><strong>' + esc(o.name || o.ref) + '</strong><br><span class="hint">' + esc([o.company, dateOnly(o.closing)].filter(Boolean).join(' · ')) + '</span></span><span class="q">›</span></a>';
    }).join('') + '</div>';
    if (!over.length && !today.length && !closing.length) html += '<div class="empty">' + esc(t('dashNothing')) + '</div>';
    html += '<a class="btn light" href="#/tasks" style="display:block;text-align:center;text-decoration:none;margin:8px 0 12px">' + esc(t('dashAll')) + '</a>' + moreCard();
    shell(t('dashTitle'), html, { tab: 'home' });
  }

  // --- tâches
  function renderTasks() {
    if (!crm) { noCrm(t('tasks'), 'tasks'); return; }
    const done = {}, added = [];
    const moved = {};
    crmPending().forEach(function (o) { if (o.payload.kind === 'task_done') done[o.payload.task_id] = true; else if (o.payload.kind === 'task_add') added.push(o); else if (o.payload.kind === 'task_snooze') moved[o.payload.task_id] = o.payload.to; });
    const now = Date.now(), endToday = new Date(); endToday.setHours(23, 59, 59, 999);
    const rows = crm.tasks.filter(function (x) { return !done[x.id]; }).map(function (x) { return { id: x.id, title: x.title, text: x.text, due: effDue(x, moved), contact: x.contact, cname: x.contact_name, wait: !!moved[x.id] }; })
      .concat(added.map(function (o) { const c = findContact(o.payload.contact_id); return { id: 0, cid: o.client_id, title: o.payload.title || o.payload.content, text: '', due: o.payload.due || '', contact: o.payload.contact_id || 0, cname: c ? c.name : '', wait: true }; }));
    rows.sort(function (a, b) { return (a.due || '9999').localeCompare(b.due || '9999'); });
    const groups = { over: [], today: [], later: [] };
    rows.forEach(function (r) { const d = r.due ? new Date(r.due.replace(' ', 'T')).getTime() : NaN; (isNaN(d) ? groups.later : d < now ? groups.over : d <= endToday.getTime() ? groups.today : groups.later).push(r); });
    function row(r) {
      const open = r.id ? 'href="#/t/' + r.id + '"' : '';
      return '<div class="art"><button class="chk" data-act="task-done" data-id="' + r.id + '"' + (r.id ? '' : ' disabled') + ' aria-label="' + esc(t('doneBtn')) + '"></button>' +
        '<a class="n tl" ' + open + '>' + esc(r.title) + (r.wait ? ' <span class="badge wait">⏳</span>' : '') + '<br><span class="hint">' + esc(dayLabel(r.due)) + (r.cname ? ' · ' + esc(r.cname) : '') + '</span></a>' +
        (r.id ? '<button class="snz" data-act="snooze" data-id="' + r.id + '" aria-label="' + esc(t('snooze')) + '">⏱</button>' : '') + '</div>';
    }
    const dueNow = groups.over.length + groups.today.length;
    let html = (dueNow ? '<a class="btn green" href="#/run/new" style="display:block;text-align:center;text-decoration:none;margin:0 0 10px">▶ ' + esc(t('runStart')) + ' · ' + dueNow + '</a>' : '') +
      '<a class="btn' + (dueNow ? ' light' : '') + '" href="#/tasks/new" style="display:block;text-align:center;text-decoration:none;margin:0 0 12px">➕ ' + esc(t('newTask')) + '</a>';
    [['over', t('overdue')], ['today', t('today')], ['later', t('later')]].forEach(function (g) {
      if (groups[g[0]].length) html += '<div class="card' + (g[0] === 'over' ? ' red' : '') + '"><h2>' + esc(g[1]) + ' · ' + groups[g[0]].length + '</h2>' + groups[g[0]].map(row).join('') + '</div>';
    });
    if (!rows.length) html += '<div class="empty">' + esc(t('noTasks')) + '</div>';
    shell(t('tasks'), html, { tab: 'tasks' });
  }

  // échéance affichée : jour reporté (en attente d'envoi) à l'heure d'origine
  function effDue(task, moved) { const to = moved && moved[task.id]; if (!to) return task.due; return to + ' ' + ((task.due || '').slice(11, 16) || '09:00'); }
  function ymd(d) { const p2 = function (n) { return String(n).padStart(2, '0'); }; return d.getFullYear() + '-' + p2(d.getMonth() + 1) + '-' + p2(d.getDate()); }
  function plusDays(n) { const d = new Date(); d.setHours(12, 0, 0, 0); d.setDate(d.getDate() + n); return d; }
  function nextMonday() { const d = new Date(); d.setHours(12, 0, 0, 0); d.setDate(d.getDate() + ((8 - d.getDay()) % 7 || 7)); return d; }

  /** Feuille « Reporter la tâche à… » : propositions rapides ou date au choix ; l'action part au prochain réseau si besoin. */
  function snoozeSheet(taskId, after) {
    const old = document.getElementById('snz-sheet'); if (old) old.remove();
    const opts = [['s_tomorrow', plusDays(1)], ['s_3d', plusDays(3)], ['s_monday', nextMonday()], ['s_1w', plusDays(7)], ['s_2w', plusDays(14)]];
    const sh = document.createElement('div'); sh.id = 'snz-sheet'; sh.className = 'sheet';
    sh.innerHTML = '<div class="box"><h2>' + esc(t('snoozeTo')) + '</h2>' + opts.map(function (o) {
      return '<button type="button" class="btn light" data-to="' + ymd(o[1]) + '">' + esc(t(o[0])) + ' <span class="hint">· ' + esc(dateOnly(ymd(o[1]) + ' 12:00')) + '</span></button>';
    }).join('') + '<label class="f" for="snz-d">' + esc(t('s_pick')) + '</label><input type="date" id="snz-d" min="' + ymd(plusDays(0)) + '"><button type="button" class="btn" id="snz-ok" disabled>' + esc(t('s_ok')) + '</button>' +
      '<button type="button" class="btn light" data-cancel="1">' + esc(t('cancel')) + '</button></div>';
    document.body.appendChild(sh);
    function apply(day) { sh.remove(); queueCrm({ kind: 'task_snooze', task_id: taskId, to: day }, t('snoozed'), after || render); }
    sh.addEventListener('click', function (e) {
      const b = e.target.closest('button');
      if (e.target === sh || (b && b.dataset.cancel)) { sh.remove(); return; }
      if (b && b.dataset.to) apply(b.dataset.to);
      if (b && b.id === 'snz-ok') apply(sh.querySelector('#snz-d').value);
    });
    sh.querySelector('#snz-d').addEventListener('input', function () { sh.querySelector('#snz-ok').disabled = !this.value; });
  }

  function renderTask(id) {
    const x = crm && crm.tasks.filter(function (k) { return k.id === id; })[0];
    const gone = crmPending().some(function (o) { return o.payload.kind === 'task_done' && o.payload.task_id === id; });
    if (!x || gone) { go('#/tasks'); return; }
    const moved = {}; crmPending().forEach(function (o) { if (o.payload.kind === 'task_snooze') moved[o.payload.task_id] = o.payload.to; });
    const c = x.contact ? findContact(x.contact) : null;
    const offer = x.deal && crm.offers.filter(function (o) { return o.ref === x.deal || o.ref === String(x.deal).split(',')[0]; })[0];
    let html = '<div class="card red"><div class="kv"><span>' + esc(t('dueLabel')) + '</span><strong>' + esc(dayLabel(effDue(x, moved))) + (moved[id] ? ' ⏳' : '') + '</strong></div>' +
      (x.text && x.text !== x.title ? '<div class="desc" style="margin-top:10px">' + esc(x.text) + '</div>' : '') + '</div>';
    if (c || x.contact_name) {
      html += '<div class="card"><h2>' + esc(t('relatedContact')) + '</h2>' + (c ? '<a class="art item" href="#/c/' + c.id + '" style="text-decoration:none;color:inherit"><span class="n"><strong>' + esc(c.name) + '</strong><br><span class="hint">' + esc([c.company, c.function].filter(Boolean).join(' · ')) + '</span></span><span class="q">›</span></a>' +
        (c.phone ? '<div class="grid2" style="margin-top:8px"><a class="btn" href="tel:' + esc(c.phone.replace(/[^+\d]/g, '')) + '">📞 ' + esc(c.phone) + '</a><a class="btn light" href="#/c/' + c.id + '/new/CALL">📝 ' + esc(t('note')) + '</a></div>' : '') : '<div>' + esc(x.contact_name) + '</div>') + '</div>';
    }
    if (offer) html += '<a class="card item" href="#/o/' + encodeURIComponent(offer.ref) + '"><div class="s">' + esc(t('relatedOffer')) + '</div><div class="t">' + esc(offer.name || offer.ref) + '</div><div class="s">' + esc([offer.company, offer.stage].filter(Boolean).join(' · ')) + '</div></a>';
    html += '<div class="grid2"><button class="btn green" data-act="task-done" data-id="' + id + '" data-back="1">✅ ' + esc(t('doneBtn')) + '</button><button class="btn light" data-act="snooze" data-id="' + id + '">⏱ ' + esc(t('snooze')) + '</button></div>';
    shell(x.title, html, { back: true });
  }


  // ------------------------------------------------------------------ traiter les tâches (comme sur le site)
  const RUN_KEY = 'ispag.run', RUN_MAX_AGE = 12 * 3600 * 1000;
  function runGet() { const st = lsGet(RUN_KEY); return st && st.ids && st.ids.length && Date.now() - (st.at || 0) < RUN_MAX_AGE ? st : null; }
  function runSet(st) { if (st) { st.at = st.at || Date.now(); lsSet(RUN_KEY, st); } else { try { localStorage.removeItem(RUN_KEY); } catch (e) {} } }
  /** Tâches échues (en retard et du jour), dans l'ordre de l'échéance, hors celles déjà traitées ou reportées en attente d'envoi. */
  function runQueue() {
    if (!crm) return [];
    const gone = {}, moved = {};
    crmPending().forEach(function (o) { if (o.payload.kind === 'task_done') gone[o.payload.task_id] = true; else if (o.payload.kind === 'task_snooze') moved[o.payload.task_id] = o.payload.to; });
    const end = new Date(); end.setHours(23, 59, 59, 999);
    return crm.tasks.filter(function (x) {
      if (gone[x.id]) return false;
      const d = effDue(x, moved); const ts = d ? new Date(d.replace(' ', 'T')).getTime() : NaN;
      return isNaN(ts) || ts <= end.getTime();
    }).sort(function (a, b) { return (a.due || '9999').localeCompare(b.due || '9999'); }).map(function (x) { return x.id; });
  }
  function runBar() {
    const st = runGet(); const r = route();
    if (!st || r[0] === 'run' || !auth) return '';
    return '<a class="runbar" href="#/run">▶ ' + esc(t('runTitle')) + ' · ' + esc(t('runOf').replace('%1', Math.min(st.idx + 1, st.ids.length)).replace('%2', st.ids.length)) + ' — ' + esc(t('runResume')) + '</a>';
  }
  function renderRun(fresh) {
    if (!crm) { noCrm(t('runTitle'), 'tasks'); return; }
    let st = fresh ? null : runGet();
    if (!st) {
      const ids = runQueue();
      if (!ids.length) { runSet(null); shell(t('runTitle'), '<div class="empty">' + esc(t('runNone')) + '</div><a class="btn light" href="#/tasks" style="display:block;text-align:center;text-decoration:none">' + esc(t('runBack')) + '</a>', { back: true }); return; }
      st = { ids: ids, idx: 0, done: 0, moved: 0, skipped: 0, at: Date.now() };
      runSet(st);
      if (fresh) { history.replaceState(null, '', '#/run'); }
    }
    if (st.idx >= st.ids.length) {
      shell(t('runTitle'), '<div class="card red"><h2>' + esc(t('runEnd')) + '</h2><div class="desc">' + esc(t('runSummary').replace('%1', st.done).replace('%2', st.moved).replace('%3', st.skipped)) + '</div></div>' +
        '<a class="btn green" href="#/tasks" data-act="run-stop" style="display:block;text-align:center;text-decoration:none">' + esc(t('runBack')) + '</a>', { noActions: false });
      return;
    }
    const id = st.ids[st.idx];
    const x = crm.tasks.filter(function (k) { return k.id === id; })[0];
    const finished = crmPending().some(function (o) { return o.payload.kind === 'task_done' && o.payload.task_id === id; });
    if (!x) { st.idx++; runSet(st); renderRun(); return; }
    const moved = {}; crmPending().forEach(function (o) { if (o.payload.kind === 'task_snooze') moved[o.payload.task_id] = o.payload.to; });
    const c = x.contact ? findContact(x.contact) : null;
    const offer = x.deal && crm.offers.filter(function (o) { return o.ref === x.deal || o.ref === String(x.deal).split(',')[0]; })[0];
    const pct = Math.round(st.idx / st.ids.length * 100);
    let html = '<div class="runprog"><span style="width:' + pct + '%"></span></div>' +
      '<div class="card red"><div class="hint">' + esc(t('runOf').replace('%1', st.idx + 1).replace('%2', st.ids.length)) + (finished ? ' · <span class="badge ok">' + esc(t('runDoneBadge')) + '</span>' : '') + '</div>' +
      '<h2 style="margin:6px 0 4px;font-size:18px;text-transform:none;letter-spacing:0">' + esc(x.title) + '</h2>' +
      '<div class="kv"><span>' + esc(t('dueLabel')) + '</span><strong>' + esc(dayLabel(effDue(x, moved))) + (moved[id] ? ' ⏳' : '') + '</strong></div>' +
      (x.text && x.text !== x.title ? '<div class="desc" style="margin-top:10px">' + esc(x.text) + '</div>' : '') + '</div>';
    if (c || offer || x.contact_name) {
      html += '<div class="card"><h2>' + esc(t('runRelated')) + '</h2>' +
        (offer ? '<a class="art item" href="#/o/' + encodeURIComponent(offer.ref) + '" style="text-decoration:none;color:inherit"><span class="n"><strong>' + esc(offer.name || offer.ref) + '</strong><br><span class="hint">' + esc([offer.company, offer.stage].filter(Boolean).join(' · ')) + '</span></span><span class="q">›</span></a>' : '') +
        (c ? '<a class="art item" href="#/c/' + c.id + '" style="text-decoration:none;color:inherit"><span class="n"><strong>' + esc(c.name) + '</strong><br><span class="hint">' + esc([c.company, c.function].filter(Boolean).join(' · ')) + '</span></span><span class="q">›</span></a>' +
          (c.phone ? '<div class="grid2" style="margin-top:8px"><a class="btn" href="tel:' + esc(c.phone.replace(/[^+\d]/g, '')) + '">📞 ' + esc(c.phone) + '</a><a class="btn light" href="#/c/' + c.id + '/new/CALL/' + (offer ? encodeURIComponent(offer.ref) : '') + '/run">📝 ' + esc(t('note')) + '</a></div>' : '') : (x.contact_name ? '<div class="hint">' + esc(x.contact_name) + '</div>' : '')) + '</div>';
    }
    html += '<div class="grid2"><button class="btn green" data-act="run-done"' + (finished ? ' disabled' : '') + '>✅ ' + esc(t('doneBtn')) + '</button><button class="btn light" data-act="run-snooze"' + (finished ? ' disabled' : '') + '>⏱ ' + esc(t('snooze')) + '</button></div>' +
      '<div class="grid2" style="margin-top:8px"><button class="btn light" data-act="run-prev"' + (st.idx === 0 ? ' disabled' : '') + '>◀ ' + esc(t('runPrev')) + '</button><button class="btn light" data-act="run-skip">' + esc(t('runSkip')) + ' ▶</button></div>' +
      '<button class="btn light" data-act="run-stop" style="margin-top:8px">✕ ' + esc(t('runStop')) + '</button>';
    shell(t('runTitle'), html, {});
  }
  function runStep(kind) {
    const st = runGet(); if (!st) { go('#/tasks'); return; }
    const id = st.ids[st.idx];
    const next = function (field) { const cur = runGet() || st; if (field) cur[field] = (cur[field] || 0) + 1; cur.idx++; runSet(cur); renderRun(); };
    if (kind === 'prev') { st.idx = Math.max(0, st.idx - 1); runSet(st); renderRun(); }
    else if (kind === 'skip') next('skipped');
    else if (kind === 'done') queueCrm({ kind: 'task_done', task_id: id }, t('taskDone'), function () { next('done'); });
    else if (kind === 'snooze') snoozeSheet(id, function () { next('moved'); });
    else if (kind === 'stop') { runSet(null); go('#/tasks'); }
  }

  function renderTaskForm(contactId) {
    const c = contactId ? findContact(contactId) : null;
    const d = new Date(Date.now() + 86400000); d.setHours(9, 0, 0, 0);
    const pad2 = function (n) { return String(n).padStart(2, '0'); };
    const def = d.getFullYear() + '-' + pad2(d.getMonth() + 1) + '-' + pad2(d.getDate()) + 'T09:00';
    shell(t('newTask') + (c ? ' · ' + c.name : ''),
      '<form class="card red" id="tform" novalidate><label class="f" for="tt">' + esc(t('taskTitle')) + '</label><input type="text" id="tt" autocomplete="off">' +
      '<label class="f" for="td">' + esc(t('due')) + '</label><input type="datetime-local" id="td" value="' + def + '"><div class="msg" id="msg" aria-live="polite"></div>' +
      '<button type="submit" class="btn green">✅ ' + esc(t('saveTask')) + '</button></form>', { back: true });
    app.querySelector('#tform').addEventListener('submit', function (e) {
      e.preventDefault();
      const title = app.querySelector('#tt').value.trim();
      if (!title) { app.querySelector('#msg').textContent = t('noTitle'); return; }
      app.querySelector('.btn').disabled = true;
      const dv = app.querySelector('#td').value;
      queueCrm({ kind: 'task_add', title: title, due: dv ? dv.replace('T', ' ') : '', contact_id: contactId || 0 }, t('taskSaved'), function () { go(contactId ? '#/c/' + contactId : '#/tasks'); });
    });
  }

  // ------------------------------------------------------------------ navigation
  function render() {
    if (!auth) { renderLogin(); return; }
    if (locked && lockCfg()) { renderLock(); return; }
    if (sessionExpired && !navigator.onLine === false) { /* la bannière d'état explique la situation */ }
    const r = route();
    if (r[0] === 'settings') return renderSettings();
    if (r[0] === 'home') return renderHome();
    if (r[0] === 'companies') return renderCompanies();
    if (r[0] === 'projects') return renderList();
    if (!r.length && hasCrm()) { go('#/home'); return; }   // l'application s'ouvre sur « Aujourd'hui »
    if (r[0] === 'co' && r[1]) return renderCompany(parseInt(r[1], 10));
    if (r[0] === 'stock') return renderStock();
    if (r[0] === 'buy') return r[1] ? renderBuyOrder(parseInt(r[1], 10)) : renderBuy();
    if (r[0] === 'offers') return renderOffers();
    if (r[0] === 'o' && r[1]) return renderOffer(decodeURIComponent(r[1]));
    if (r[0] === 'contacts') return renderContacts();
    if (r[0] === 'c' && r[1]) return r[2] === 'new' ? renderNoteForm(parseInt(r[1], 10), r[3] || 'CALL', r[4] ? decodeURIComponent(r[4]) : '', r[5] === 'run') : renderContact(parseInt(r[1], 10));
    if (r[0] === 't' && r[1]) return renderTask(parseInt(r[1], 10));
    if (r[0] === 'run') return renderRun(r[1] === 'new');
    if (r[0] === 'tasks') return r[1] === 'new' ? renderTaskForm(parseInt(r[2], 10) || 0) : renderTasks();
    if (!r.length && !hasProjects() && hasCrm()) return renderTasks();
    if (r[0] === 'p' && r[1] && r[2] === 'a' && r[3]) return renderArticle(parseInt(r[1], 10), parseInt(r[3], 10));
    if (r[0] === 'p' && r[1]) { if (r[2] === 'deliver') renderDeliver(parseInt(r[1], 10)); else renderProject(parseInt(r[1], 10)); }
    else if (r[0] === 'queue') renderQueue();
    else renderList();
  }

  app.addEventListener('click', function (e) {
    const b = e.target.closest('[data-act]'); if (!b) return;
    const act = b.dataset.act, r = route();
    if (act === 'back') { history.length > 1 ? history.back() : go(homeHash()); }
    else if (act === 'unsave') { delete saved[b.dataset.id]; kvSet('saved', saved).then(function () { cacheDocuments(); go('#/projects'); render(); }); }
    else if (act === 'refresh') { sessionExpired = false; sync(true); }
    else if (act === 'logout') { logout(); }
    else if (act === 'selall') { const boxes = app.querySelectorAll('.sel'), all = Array.prototype.every.call(boxes, function (x) { return x.checked; }); boxes.forEach(function (x) { x.checked = !all; }); const v = all ? [] : Array.prototype.map.call(boxes, function (x) { return parseInt(x.value, 10); }); selSet(parseInt(b.dataset.id, 10), v); const n = app.querySelector('#selcount'); if (n) n.textContent = v.length; }
    else if (act === 'rnpick') { const i = app.querySelector('#rn'); if (i) i.value = b.dataset.v; }
    else if (act === 'deliver') { go('#/p/' + r[1] + '/deliver'); }
    else if (act === 'bell') { go('#/settings'); }
    else if (act === 'bio-on') { enableBio(); }
    else if (act === 'bio-off') { disableBio(); }
    else if (act === 'unlock') { unlock(); }
    else if (act === 'push-on') { enablePush(); }
    else if (act === 'push-off') { disablePush(); }
    else if (act === 'push-test') { testPush(); }
    else if (act === 'pf') { sessionStorage.setItem('ispag.pf', b.dataset.v); renderList(); }
    else if (act === 'open-doc') { openDoc(b.dataset.url, b.dataset.mime); }
    else if (act === 'task-done') { const id = parseInt(b.dataset.id, 10); if (id) queueCrm({ kind: 'task_done', task_id: id }, t('taskDone'), function () { if (b.dataset.back) go('#/tasks'); else render(); }); }
    else if (act === 'stock-loc') { sessionStorage.setItem('ispag.loc', b.dataset.v); renderStock(); }
    else if (act === 'offer-stage') changeStage(b.dataset.ref);
    else if (act === 'run-done') runStep('done');
    else if (act === 'run-snooze') runStep('snooze');
    else if (act === 'run-prev') runStep('prev');
    else if (act === 'run-skip') runStep('skip');
    else if (act === 'run-stop') runStep('stop');
    else if (act === 'snooze') { const id = parseInt(b.dataset.id, 10); if (id) snoozeSheet(id); }
    else if (act === 'discard') { outDel(b.dataset.id).then(function () { outbox = outbox.filter(function (x) { return x.client_id !== b.dataset.id; }); render(); }); }
  });
  window.addEventListener('hashchange', render);
  window.addEventListener('online', function () { renderStatus(); sync(false); });
  window.addEventListener('offline', renderStatus);
  document.addEventListener('visibilitychange', function () { if (!document.hidden) sync(false); });

  // ------------------------------------------------------------------ démarrage
  applyLang();
  loadLogo();
  if ('serviceWorker' in navigator) {
    // Portée sans « / » final : l'icône de l'écran d'accueil peut pointer vers /ispag-app (sans barre) et l'ancienne portée ne la couvrait pas
    const scope = CFG.base.replace(/\/$/, '');
    navigator.serviceWorker.getRegistrations().then(function (rs) {
      return Promise.all(rs.map(function (r) { return r.scope === CFG.base ? r.unregister() : null; }));   // ancienne inscription
    }).catch(function () {}).then(function () {
      return navigator.serviceWorker.register(CFG.base + 'sw.js', { scope: scope });
    }).then(function () { return navigator.serviceWorker.ready; }).then(function () { swReady = true; renderStatus(); }).catch(function () { swReady = false; renderStatus(); });
    navigator.serviceWorker.addEventListener('controllerchange', function () { renderStatus(); });
  }
  if (navigator.storage && navigator.storage.persist) navigator.storage.persist().catch(function () {});
  Promise.all([kvGet('snapshot'), outAll(), kvGet('crm'), kvGet('auth').catch(function () { return null; }), kvGet('lock').catch(function () { return null; }), kvGet('saved').catch(function () { return null; })]).then(function (v) {
    saved = v[5] || {}; snap = v[0] || null; outbox = v[1] || []; crm = v[2] || null;
    if (!lockCfg() && v[4] && v[4].id) { lsSet(LOCK, v[4]); locked = true; }   // verrouillage retrouvé après un vidage du stockage rapide
    if (!auth && v[3] && v[3].token) { auth = v[3]; lsSet('ispag.auth', auth); applyLang(); }   // le stockage rapide a été vidé par iOS : retrouvé dans IndexedDB
    return auth || !navigator.onLine ? null : api('/session').then(function (r) {   // …ou, à défaut, grâce au cookie de secours du serveur
      if (r.status === 200 && r.body.token) { auth = { token: r.body.token, user: r.body.user }; saveAuth(); applyLang(); }
    }).catch(function () {});
  }).then(function () {
    checkBio().then(function () { if (locked && lockCfg()) unlock(); });
    render();
    if (auth) { sync(false); fetchPushKey().then(checkPush).then(function () { if (!route().length || route()[0] === 'settings') render(); }); }
  }).catch(function () { render(); });
})();
