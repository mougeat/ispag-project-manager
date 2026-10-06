/* Application ISPAG (PWA) : projets consultables et livraisons signées, y compris hors ligne.
 * - Données : IndexedDB (instantané des projets + file d'attente des livraisons à envoyer).
 * - Jeton d'appareil : localStorage. Aucune donnée de prix n'est transmise à l'application.
 */
(function () {
  'use strict';
  const CFG = window.ISPAG_APP;

  // ------------------------------------------------------------------ textes
  const T = {
    fr: { manager: 'Chef de projet', ordered: 'Commande du', contactsP: 'Contacts du projet', siteInfo: 'Conditions sur site', docs: 'Documents', noDocs: 'Aucun document.', docOffline: 'Document non disponible hors ligne.', docSaved: 'disponible hors ligne', docsSaving: 'Documents enregistrés :', details: 'Détails', notReady: 'hors ligne : ouvrez l’app en ligne une fois', offers: 'Offres', contacts: 'Contacts', tasks: 'Tâches', noOffers: 'Aucune offre sur les 3 derniers mois.', noContacts: 'Aucun contact.', noTasks: 'Aucune tâche ouverte. 🎉', overdue: 'En retard', today: 'Aujourd’hui', later: 'À venir', newTask: 'Nouvelle tâche', taskTitle: 'Titre de la tâche', due: 'Échéance', saveTask: 'Enregistrer la tâche', taskSaved: 'Tâche enregistrée', taskDone: 'Tâche terminée', doneBtn: 'Terminer', newNote: 'Nouvelle note', noteType: 'Type', note: 'Note', call: 'Appel', meeting: 'Réunion', email: 'E-mail', noteText: 'Résumé', saveNote: 'Enregistrer', noteSaved: 'Enregistré', noteMissing: 'Saisissez un texte.', activities: 'Dernières activités', noActs: 'Aucune activité récente.', callNow: 'Appeler', phone: 'Téléphone', mail: 'E-mail', func: 'Fonction', stage: 'Étape', closing: 'Clôture', created: 'Créée le', amount: 'Montant', offerContacts: 'Contacts de l’offre', task1: 'Tâche', noTitle: 'Saisissez un titre.', crmSynced: 'Contacts, tâches et offres du', login: 'Connexion', user: 'Identifiant ou e-mail', pass: 'Mot de passe', signin: 'Se connecter', badLogin: 'Identifiants incorrects.', noRight: "Ce compte n'a pas accès à l'application.",
      projects: 'Projets', search: 'Rechercher…', none: 'Aucun projet.', offline: 'Hors ligne', online: 'En ligne', synced: 'Données du', refresh: 'Actualiser', logout: 'Déconnexion',
      back: 'Retour', delivery: 'Livraison', address: 'Adresse de livraison', contact: 'Contact', customerRef: 'Réf. client', company: 'Client', articles: 'Articles', delivered: 'Livré', waiting: 'En attente de synchro',
      newDelivery: 'Nouvelle livraison', selectArticles: 'Articles livrés', receiver: 'Nom de la personne qui réceptionne', receiverPh: 'Prénom et nom', signature: 'Signature', signHere: 'Signez ici avec le doigt',
      clear: 'Effacer', confirm: 'Confirmer la livraison', nameMissing: 'Saisissez le nom.', signMissing: 'Signature manquante.', pickOne: 'Sélectionnez au moins un article.', saved: 'Livraison enregistrée',
      savedQueued: 'Livraison enregistrée sur le téléphone, elle sera envoyée dès que le réseau sera disponible.', sent: 'Livraison envoyée', queue: 'À envoyer', pending: 'élément(s) à envoyer',
      receipts: 'Livraisons signées', pdf: 'PDF', allDone: 'Tous les articles sont livrés.', sync: 'Synchronisation…', syncFail: "Synchronisation impossible pour l'instant.", expired: 'Session expirée, reconnectez-vous (vos livraisons en attente sont conservées).',
      refused: 'Refusée par le serveur', retry: 'Réessayer', discard: 'Supprimer', never: 'jamais', install: "Pour installer : bouton Partager de Safari, puis « Sur l'écran d'accueil ».", noData: 'Aucune donnée sur ce téléphone : connectez-vous une première fois avec le réseau.', qty: 'Qté' },
    de: { manager: 'Projektleiter', ordered: 'Bestellt am', contactsP: 'Projektkontakte', siteInfo: 'Bedingungen vor Ort', docs: 'Dokumente', noDocs: 'Keine Dokumente.', docOffline: 'Dokument offline nicht verfügbar.', docSaved: 'offline verfügbar', docsSaving: 'Dokumente gespeichert:', details: 'Details', notReady: 'offline: App einmal online öffnen', offers: 'Angebote', contacts: 'Kontakte', tasks: 'Aufgaben', noOffers: 'Keine Angebote in den letzten 3 Monaten.', noContacts: 'Keine Kontakte.', noTasks: 'Keine offenen Aufgaben. 🎉', overdue: 'Überfällig', today: 'Heute', later: 'Demnächst', newTask: 'Neue Aufgabe', taskTitle: 'Titel der Aufgabe', due: 'Fällig', saveTask: 'Aufgabe speichern', taskSaved: 'Aufgabe gespeichert', taskDone: 'Aufgabe erledigt', doneBtn: 'Erledigen', newNote: 'Neue Notiz', noteType: 'Art', note: 'Notiz', call: 'Anruf', meeting: 'Besprechung', email: 'E-Mail', noteText: 'Zusammenfassung', saveNote: 'Speichern', noteSaved: 'Gespeichert', noteMissing: 'Bitte Text eingeben.', activities: 'Letzte Aktivitäten', noActs: 'Keine aktuelle Aktivität.', callNow: 'Anrufen', phone: 'Telefon', mail: 'E-Mail', func: 'Funktion', stage: 'Phase', closing: 'Abschluss', created: 'Erstellt am', amount: 'Betrag', offerContacts: 'Kontakte des Angebots', task1: 'Aufgabe', noTitle: 'Bitte Titel eingeben.', crmSynced: 'Kontakte, Aufgaben und Angebote vom', login: 'Anmeldung', user: 'Benutzername oder E-Mail', pass: 'Passwort', signin: 'Anmelden', badLogin: 'Anmeldedaten falsch.', noRight: 'Dieses Konto hat keinen Zugriff auf die App.',
      projects: 'Projekte', search: 'Suchen…', none: 'Keine Projekte.', offline: 'Offline', online: 'Online', synced: 'Daten vom', refresh: 'Aktualisieren', logout: 'Abmelden',
      back: 'Zurück', delivery: 'Lieferung', address: 'Lieferadresse', contact: 'Kontakt', customerRef: 'Kundenreferenz', company: 'Kunde', articles: 'Artikel', delivered: 'Geliefert', waiting: 'Wartet auf Synchronisierung',
      newDelivery: 'Neue Lieferung', selectArticles: 'Gelieferte Artikel', receiver: 'Name der empfangenden Person', receiverPh: 'Vor- und Nachname', signature: 'Unterschrift', signHere: 'Hier mit dem Finger unterschreiben',
      clear: 'Löschen', confirm: 'Lieferung bestätigen', nameMissing: 'Bitte Namen eingeben.', signMissing: 'Unterschrift fehlt.', pickOne: 'Mindestens einen Artikel auswählen.', saved: 'Lieferung gespeichert',
      savedQueued: 'Lieferung auf dem Telefon gespeichert, sie wird gesendet, sobald ein Netz verfügbar ist.', sent: 'Lieferung gesendet', queue: 'Zu senden', pending: 'Element(e) zu senden',
      receipts: 'Unterschriebene Lieferungen', pdf: 'PDF', allDone: 'Alle Artikel sind geliefert.', sync: 'Synchronisierung…', syncFail: 'Synchronisierung derzeit nicht möglich.', expired: 'Sitzung abgelaufen, bitte neu anmelden (ausstehende Lieferungen bleiben erhalten).',
      refused: 'Vom Server abgelehnt', retry: 'Erneut versuchen', discard: 'Löschen', never: 'nie', install: 'Installieren: Teilen-Taste in Safari, dann «Zum Home-Bildschirm».', noData: 'Keine Daten auf diesem Telefon: bitte einmal mit Netz anmelden.', qty: 'Menge' },
    en: { manager: 'Project manager', ordered: 'Ordered on', contactsP: 'Project contacts', siteInfo: 'Site conditions', docs: 'Documents', noDocs: 'No documents.', docOffline: 'Document not available offline.', docSaved: 'available offline', docsSaving: 'Documents saved:', details: 'Details', notReady: 'offline: open the app online once', offers: 'Offers', contacts: 'Contacts', tasks: 'Tasks', noOffers: 'No offers in the last 3 months.', noContacts: 'No contacts.', noTasks: 'No open tasks. 🎉', overdue: 'Overdue', today: 'Today', later: 'Upcoming', newTask: 'New task', taskTitle: 'Task title', due: 'Due', saveTask: 'Save task', taskSaved: 'Task saved', taskDone: 'Task completed', doneBtn: 'Complete', newNote: 'New note', noteType: 'Type', note: 'Note', call: 'Call', meeting: 'Meeting', email: 'Email', noteText: 'Summary', saveNote: 'Save', noteSaved: 'Saved', noteMissing: 'Enter some text.', activities: 'Latest activities', noActs: 'No recent activity.', callNow: 'Call', phone: 'Phone', mail: 'Email', func: 'Position', stage: 'Stage', closing: 'Closing', created: 'Created', amount: 'Amount', offerContacts: 'Offer contacts', task1: 'Task', noTitle: 'Enter a title.', crmSynced: 'Contacts, tasks and offers from', login: 'Sign in', user: 'Username or email', pass: 'Password', signin: 'Sign in', badLogin: 'Wrong credentials.', noRight: 'This account cannot use the app.',
      projects: 'Projects', search: 'Search…', none: 'No projects.', offline: 'Offline', online: 'Online', synced: 'Data from', refresh: 'Refresh', logout: 'Sign out',
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
  let snap = null;                           // instantané des projets
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
        auth = { token: r.body.token, user: r.body.user }; lsSet('ispag.auth', auth); applyLang(); sessionExpired = false;
        return { ok: true };
      }
      return { ok: false, message: r.status === 403 ? t('noRight') : t('badLogin') };
    });
  }

  function logout() {
    const done = function () { auth = null; snap = null; crm = null; kvSet('crm', null); try { localStorage.removeItem('ispag.auth'); } catch (e) {} kvSet('snapshot', null); render(); };
    if (outbox.length && !confirm(outbox.length + ' ' + t('pending') + ' — ' + t('logout') + ' ?')) return;
    api('/logout', { method: 'POST' }).catch(function () {}).then(done);
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
      snap = r.body; auth.user = r.body.user; lsSet('ispag.auth', auth); applyLang();
      return kvSet('snapshot', snap);
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
  function findProject(id) { return snap && snap.projects.filter(function (p) { return p.deal_id === id; })[0]; }

  function renderStatus() {
    const el = document.getElementById('status'); if (!el) return;
    const off = !navigator.onLine;
    el.className = 'status' + (off ? ' off' : '');
    const ready = swReady && !!(navigator.serviceWorker && navigator.serviceWorker.controller);
    el.innerHTML = '<span>' + (off ? '⚠️ ' + t('offline') : '● ' + t('online')) + (snap ? ' · ' + t('synced') + ' ' + esc(fmtDate(snap.generated_at)) : '') + ' · ' + (ready ? '📴✓' : '📴✗ ' + esc(t('notReady'))) + '</span><span>' + esc(syncMsg || (outbox.length ? '⏳ ' + outbox.length + ' ' + t('pending') : '')) + '</span>';
  }

  function shell(title, inner, opts) {
    opts = opts || {};
    app.innerHTML =
      '<header class="bar">' + (opts.back ? '<button data-act="back" aria-label="' + esc(t('back')) + '">‹</button>' : '') +
      '<h1>' + esc(title) + '</h1>' +
      (opts.noActions ? '' : '<button data-act="refresh" aria-label="' + esc(t('refresh')) + '">↻</button><button data-act="logout" aria-label="' + esc(t('logout')) + '">⎋</button>') +
      '</header><div id="status"></div><div class="wrap">' + inner + '</div>' + (opts.tab ? tabsHtml(opts.tab) : '');
    renderStatus();
    window.scrollTo(0, 0);
  }

  function renderLogin(msg) {
    shell('ISPAG', '<form class="card red" id="login" novalidate><h2>' + esc(t('login')) + '</h2>' +
      '<label class="f" for="u">' + esc(t('user')) + '</label><input type="text" id="u" autocomplete="username" autocapitalize="none" autocorrect="off">' +
      '<label class="f" for="p">' + esc(t('pass')) + '</label><input type="password" id="p" autocomplete="current-password">' +
      '<div class="msg" id="msg">' + esc(msg || '') + '</div><button class="btn" type="submit">' + esc(t('signin')) + '</button>' +
      '<p class="hint">' + esc(t('install')) + '</p></form>', { noActions: true });
    app.querySelector('#login').addEventListener('submit', function (e) {
      e.preventDefault();
      const u = app.querySelector('#u').value.trim(), p = app.querySelector('#p').value;
      if (!u || !p) return;
      if (!navigator.onLine) { app.querySelector('#msg').textContent = t('offline'); return; }
      app.querySelector('.btn').disabled = true;
      login(u, p).then(function (r) { if (r.ok) { sync(true); render(); } else { renderLogin(r.message); } }).catch(function () { renderLogin(t('syncFail')); });
    });
  }

  function renderList() {
    if (!snap) { shell(t('projects'), '<div class="empty">' + esc(navigator.onLine ? t('sync') : t('noData')) + '</div>', { tab: 'projects' }); return; }
    const q = (sessionStorage.getItem('ispag.q') || '').toLowerCase();
    const list = snap.projects.filter(function (p) { return !q || (p.title + ' ' + p.number + ' ' + p.company + ' ' + p.customer_ref).toLowerCase().indexOf(q) >= 0; });
    let html = '<input type="search" id="q" placeholder="' + esc(t('search')) + '" value="' + esc(q) + '" style="margin-bottom:12px">';
    if (outbox.length) html += '<a class="card item" href="#/queue"><span class="t">⏳ ' + outbox.length + ' ' + esc(t('pending')) + '</span></a>';
    html += list.length ? list.map(function (p) {
      const todo = p.articles.filter(function (a) { return !a.done; }).length;
      return '<a class="card item" href="#/p/' + p.deal_id + '"><div class="t">' + esc(p.title || p.number) + '</div><div class="s">' + esc([p.number, p.company].filter(Boolean).join(' · ')) + '</div>' +
        '<div class="s">' + p.articles.length + ' ' + esc(t('articles').toLowerCase()) + (todo ? '<span class="badge">' + todo + ' ✕</span>' : '<span class="badge ok">✓</span>') + '</div></a>';
    }).join('') : '<div class="empty">' + esc(t('none')) + '</div>';
    shell(t('projects'), html, { tab: 'projects' });
    const qi = app.querySelector('#q');
    qi.addEventListener('input', function () { sessionStorage.setItem('ispag.q', qi.value); const pos = qi.selectionStart; renderList(); const n = app.querySelector('#q'); n.focus(); n.setSelectionRange(pos, pos); });
  }

  function addrLine(d) { return [d.address, d.address2, d.address3, [d.zip, d.city].filter(Boolean).join(' ')].filter(Boolean).join(', '); }

  function renderProject(id) {
    const p = findProject(id);
    if (!p) { go('#/'); return; }
    const pend = pendingIds(id);
    const d = p.delivery || {};
    const kv = function (label, val) { return val ? '<div class="kv"><span>' + esc(label) + '</span><strong>' + val + '</strong></div>' : ''; };
    let html = '<div class="card red">' + kv(t('company'), esc(p.company)) + kv(t('customerRef'), esc(p.customer_ref)) + kv(t('manager'), esc(p.manager)) +
      kv(t('ordered'), p.ordered_at ? esc(dateOnly(new Date(p.ordered_at * 1000).toISOString())) : '') +
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

    const todo = p.articles.filter(function (a) { return !a.done && !pend[a.id]; });
    html += '<div class="card"><h2>' + esc(t('articles')) + '</h2>' + (p.articles.map(function (a) {
      const badge = a.done ? '<span class="badge ok">' + esc(t('delivered')) + '</span>' : (pend[a.id] ? '<span class="badge wait">⏳</span>' : '');
      const head = '<span class="n">' + esc(a.name) + (a.ref ? ' <span class="hint">' + esc(a.ref) + '</span>' : '') + badge + '</span><span class="q">' + esc(a.qty) + '</span>';
      return a.desc && a.desc !== a.name
        ? '<details class="art' + (a.master ? ' sub' : '') + (a.done ? ' done' : '') + '"><summary>' + head + '</summary><div class="desc">' + esc(a.desc) + '</div></details>'
        : '<div class="art' + (a.master ? ' sub' : '') + (a.done ? ' done' : '') + '">' + head + '</div>';
    }).join('') || '<div class="empty">' + esc(t('none')) + '</div>') + '</div>';

    // documents (hors prix) : ouverts depuis la copie du téléphone quand elle existe
    const docs = p.documents || [];
    html += '<div class="card"><h2>' + esc(t('docs')) + (docs.length ? ' · ' + docs.length : '') + '</h2>' + (docs.length ? docs.map(function (x) {
      const art = x.article ? p.articles.filter(function (a) { return a.id === x.article; })[0] : null;
      return '<button type="button" class="doc" data-act="open-doc" data-url="' + esc(x.url) + '" data-mime="' + esc(x.mime) + '"><span class="ic">' + docIcon(x.mime) + '</span><span class="n">' + esc(x.title) +
        '<br><span class="hint">' + esc([x.type, art ? art.name : '', x.at ? dateOnly(new Date(x.at * 1000).toISOString()) : ''].filter(Boolean).join(' · ')) + '</span></span>' +
        '<span class="off" data-doc="' + esc(x.url) + '"></span></button>';
    }).join('') : '<div class="empty">' + esc(t('noDocs')) + '</div>') + '</div>';

    html += todo.length ? '<button class="btn green" data-act="deliver">✍️ ' + esc(t('newDelivery')) + '</button>' : '<p class="hint" style="text-align:center">' + esc(t('allDone')) + '</p>';
    if (p.receipts && p.receipts.length) {
      html += '<div class="card" style="margin-top:12px"><h2>' + esc(t('receipts')) + '</h2>' + p.receipts.map(function (r) {
        return '<div class="kv"><span>' + esc(fmtDate(r.at.replace(' ', 'T'))) + '</span><span style="color:inherit">' + esc(r.by) + (r.pdf ? ' · <button type="button" class="lnk" data-act="open-doc" data-url="' + esc(r.pdf) + '" data-mime="application/pdf">' + esc(t('pdf')) + '</button>' : '') + '</span></div>';
      }).join('') + '</div>';
    }
    shell(p.title || p.number, html, { back: true });
    markCachedDocs();
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
    if (!snap || docsBusy || !navigator.onLine || !window.caches) return Promise.resolve();
    const list = [], seen = {};
    snap.projects.forEach(function (p) {
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
  function renderDeliver(id) {
    const p = findProject(id);
    if (!p) { go('#/'); return; }
    const pend = pendingIds(id);
    const todo = p.articles.filter(function (a) { return !a.done && !pend[a.id]; });
    if (!todo.length) { go('#/p/' + id); return; }
    shell(t('newDelivery'),
      '<div class="card red"><h2>' + esc(t('selectArticles')) + '</h2>' + todo.map(function (a) {
        return '<label class="art"><input type="checkbox" class="pick" value="' + a.id + '" checked><span class="n">' + esc(a.name) + '</span><span class="q">' + esc(a.qty) + '</span></label>';
      }).join('') + '</div>' +
      '<form class="card" id="dform" novalidate><label class="f" for="rn">' + esc(t('receiver')) + '</label><input type="text" id="rn" autocomplete="name" placeholder="' + esc(t('receiverPh')) + '">' +
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


  // ------------------------------------------------------------------ CRM : onglets, offres, contacts, tâches, notes
  function hasProjects() { return !!(snap && snap.caps ? snap.caps.projects : true); }
  function hasCrm() { return !!crm; }
  function homeHash() { return hasProjects() || !hasCrm() ? '#/' : '#/tasks'; }

  function tabsHtml(cur) {
    const tabs = [];
    if (hasProjects()) tabs.push(['projects', '#/', '📦', t('projects')]);
    if (hasCrm()) tabs.push(['offers', '#/offers', '💼', t('offers')], ['contacts', '#/contacts', '👤', t('contacts')], ['tasks', '#/tasks', '✅', t('tasks')]);
    if (tabs.length < 2) return '';
    return '<nav class="tabs">' + tabs.map(function (x) { return '<a href="' + x[1] + '"' + (x[0] === cur ? ' class="on"' : '') + '><span>' + x[2] + '</span>' + esc(x[3]) + '</a>'; }).join('') + '</nav>';
  }

  function crmLabel(o) {
    const p = o.payload || {};
    if (p.kind === 'task_done') { const tk = crm && crm.tasks.filter(function (x) { return x.id === p.task_id; })[0]; return '✅ ' + t('taskDone') + (tk ? ' : ' + tk.title : ''); }
    if (p.kind === 'task_add') return '➕ ' + t('task1') + ' : ' + (p.title || p.content || '');
    const c = findContact(p.contact_id);
    return (({ NOTE: '📝 ', CALL: '📞 ', MEETING: '🤝 ', EMAIL: '✉️ ' })[p.type] || '📝 ') + t(({ NOTE: 'note', CALL: 'call', MEETING: 'meeting', EMAIL: 'email' })[p.type] || 'note') + (c ? ' · ' + c.name : '');
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
  function renderOffers() {
    if (!crm) { noCrm(t('offers'), 'offers'); return; }
    const sb = searchBox('offers');
    const list = crm.offers.filter(function (o) { return !sb.q || (o.name + ' ' + o.company + ' ' + o.ref + ' ' + o.stage).toLowerCase().indexOf(sb.q) >= 0; });
    let html = sb.html + (list.length ? list.map(function (o) {
      return '<a class="card item" href="#/o/' + encodeURIComponent(o.ref) + '"><div class="t">' + esc(o.name || o.ref) + '</div><div class="s">' + esc([o.company, o.stage].filter(Boolean).join(' · ')) + '</div>' +
        '<div class="s">' + esc(dateOnly(o.created)) + (o.amount != null ? '<span class="badge">' + esc(Math.round(o.amount).toLocaleString('fr-CH')) + ' CHF</span>' : '') + '</div></a>';
    }).join('') : '<div class="empty">' + esc(t('noOffers')) + '</div>');
    shell(t('offers'), html, { tab: 'offers' });
    bindSearch('offers', renderOffers);
  }
  function renderOffer(ref) {
    const o = crm && crm.offers.filter(function (x) { return x.ref === ref; })[0];
    if (!o) { go('#/offers'); return; }
    let html = '<div class="card red"><div class="kv"><span>' + esc(t('company')) + '</span><strong>' + esc(o.company) + '</strong></div>' +
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
    shell(o.name || o.ref, html, { back: true });
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
      (c.company ? '<div class="kv"><span>' + esc(t('company')) + '</span><strong>' + esc(c.company) + '</strong></div>' : '') +
      (c.function ? '<div class="kv"><span>' + esc(t('func')) + '</span><strong>' + esc(c.function) + '</strong></div>' : '') +
      (c.phone ? '<div class="kv"><span>' + esc(t('phone')) + '</span>' + phoneLink(c.phone) + '</div>' : '') +
      (c.email ? '<div class="kv"><span>' + esc(t('mail')) + '</span><a href="mailto:' + esc(c.email) + '">' + esc(c.email) + '</a></div>' : '') + '</div>';
    html += '<div class="grid2">' + (c.phone ? '<a class="btn" href="tel:' + esc(c.phone.replace(/[^+\d]/g, '')) + '">📞 ' + esc(t('callNow')) + '</a>' : '') +
      '<a class="btn light" href="#/c/' + id + '/new/CALL">📝 ' + esc(t('call')) + ' / ' + esc(t('note')) + '</a></div>' +
      '<a class="btn light" href="#/tasks/new/' + id + '" style="display:block;text-align:center;text-decoration:none;margin-top:10px">➕ ' + esc(t('newTask')) + '</a>';
    html += '<div class="card" style="margin-top:12px"><h2>' + esc(t('activities')) + '</h2>' +
      queued.map(function (o) { return '<div class="art"><span class="n">⏳ ' + esc(crmLabel(o)) + '<br><span class="hint">' + esc(o.payload.content) + '</span></span></div>'; }).join('') +
      (c.acts.length ? c.acts.map(function (a) {
        return '<div class="art"><span class="n"><strong>' + esc(({ NOTE: t('note'), CALL: t('call'), MEETING: t('meeting'), EMAIL: t('email') })[a.t] || a.t) + '</strong> <span class="hint">' + esc(dateOnly(a.at)) + '</span><br>' + esc(a.x) + '</span></div>';
      }).join('') : (queued.length ? '' : '<div class="empty">' + esc(t('noActs')) + '</div>')) + '</div>';
    shell(c.name, html, { back: true });
  }
  function renderNoteForm(id, type) {
    const c = findContact(id);
    if (!c) { go('#/contacts'); return; }
    const types = [['CALL', t('call')], ['NOTE', t('note')], ['MEETING', t('meeting')], ['EMAIL', t('email')]];
    shell(t('newNote') + ' · ' + c.name,
      '<form class="card red" id="nform" novalidate><label class="f" for="nt">' + esc(t('noteType')) + '</label><select id="nt">' + types.map(function (x) { return '<option value="' + x[0] + '"' + (x[0] === type ? ' selected' : '') + '>' + esc(x[1]) + '</option>'; }).join('') + '</select>' +
      '<label class="f" for="nx">' + esc(t('noteText')) + '</label><textarea id="nx" rows="6"></textarea><div class="msg" id="msg" aria-live="polite"></div>' +
      '<button type="submit" class="btn green">✅ ' + esc(t('saveNote')) + '</button></form>', { back: true });
    app.querySelector('#nform').addEventListener('submit', function (e) {
      e.preventDefault();
      const text = app.querySelector('#nx').value.trim();
      if (!text) { app.querySelector('#msg').textContent = t('noteMissing'); return; }
      app.querySelector('.btn').disabled = true;
      queueCrm({ kind: 'note', type: app.querySelector('#nt').value, contact_id: id, content: text }, t('noteSaved'), function () { go('#/c/' + id); });
    });
  }

  // --- tâches
  function renderTasks() {
    if (!crm) { noCrm(t('tasks'), 'tasks'); return; }
    const done = {}, added = [];
    crmPending().forEach(function (o) { if (o.payload.kind === 'task_done') done[o.payload.task_id] = true; else if (o.payload.kind === 'task_add') added.push(o); });
    const now = Date.now(), endToday = new Date(); endToday.setHours(23, 59, 59, 999);
    const rows = crm.tasks.filter(function (x) { return !done[x.id]; }).map(function (x) { return { id: x.id, title: x.title, text: x.text, due: x.due, contact: x.contact, cname: x.contact_name, wait: false }; })
      .concat(added.map(function (o) { const c = findContact(o.payload.contact_id); return { id: 0, cid: o.client_id, title: o.payload.title || o.payload.content, text: '', due: o.payload.due || '', contact: o.payload.contact_id || 0, cname: c ? c.name : '', wait: true }; }));
    rows.sort(function (a, b) { return (a.due || '9999').localeCompare(b.due || '9999'); });
    const groups = { over: [], today: [], later: [] };
    rows.forEach(function (r) { const d = r.due ? new Date(r.due.replace(' ', 'T')).getTime() : NaN; (isNaN(d) ? groups.later : d < now ? groups.over : d <= endToday.getTime() ? groups.today : groups.later).push(r); });
    function row(r) {
      return '<div class="art"><button class="chk" data-act="task-done" data-id="' + r.id + '"' + (r.wait ? ' disabled' : '') + ' aria-label="' + esc(t('doneBtn')) + '"></button>' +
        '<span class="n">' + esc(r.title) + (r.wait ? ' <span class="badge wait">⏳</span>' : '') + '<br><span class="hint">' + esc(dayLabel(r.due)) + (r.cname ? ' · <a href="#/c/' + r.contact + '">' + esc(r.cname) + '</a>' : '') + '</span></span></div>';
    }
    let html = '<a class="btn" href="#/tasks/new" style="display:block;text-align:center;text-decoration:none;margin:0 0 12px">➕ ' + esc(t('newTask')) + '</a>';
    [['over', t('overdue')], ['today', t('today')], ['later', t('later')]].forEach(function (g) {
      if (groups[g[0]].length) html += '<div class="card' + (g[0] === 'over' ? ' red' : '') + '"><h2>' + esc(g[1]) + ' · ' + groups[g[0]].length + '</h2>' + groups[g[0]].map(row).join('') + '</div>';
    });
    if (!rows.length) html += '<div class="empty">' + esc(t('noTasks')) + '</div>';
    shell(t('tasks'), html, { tab: 'tasks' });
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
    if (sessionExpired && !navigator.onLine === false) { /* la bannière d'état explique la situation */ }
    const r = route();
    if (r[0] === 'offers') return renderOffers();
    if (r[0] === 'o' && r[1]) return renderOffer(decodeURIComponent(r[1]));
    if (r[0] === 'contacts') return renderContacts();
    if (r[0] === 'c' && r[1]) return r[2] === 'new' ? renderNoteForm(parseInt(r[1], 10), r[3] || 'CALL') : renderContact(parseInt(r[1], 10));
    if (r[0] === 'tasks') return r[1] === 'new' ? renderTaskForm(parseInt(r[2], 10) || 0) : renderTasks();
    if (!r.length && !hasProjects() && hasCrm()) return renderTasks();
    if (r[0] === 'p' && r[1]) { if (r[2] === 'deliver') renderDeliver(parseInt(r[1], 10)); else renderProject(parseInt(r[1], 10)); }
    else if (r[0] === 'queue') renderQueue();
    else renderList();
  }

  app.addEventListener('click', function (e) {
    const b = e.target.closest('[data-act]'); if (!b) return;
    const act = b.dataset.act, r = route();
    if (act === 'back') { history.length > 1 ? history.back() : go('#/'); }
    else if (act === 'refresh') { sessionExpired = false; sync(true); }
    else if (act === 'logout') { logout(); }
    else if (act === 'deliver') { go('#/p/' + r[1] + '/deliver'); }
    else if (act === 'open-doc') { openDoc(b.dataset.url, b.dataset.mime); }
    else if (act === 'task-done') { const id = parseInt(b.dataset.id, 10); if (id) queueCrm({ kind: 'task_done', task_id: id }, t('taskDone'), render); }
    else if (act === 'discard') { outDel(b.dataset.id).then(function () { outbox = outbox.filter(function (x) { return x.client_id !== b.dataset.id; }); render(); }); }
  });
  window.addEventListener('hashchange', render);
  window.addEventListener('online', function () { renderStatus(); sync(false); });
  window.addEventListener('offline', renderStatus);
  document.addEventListener('visibilitychange', function () { if (!document.hidden) sync(false); });

  // ------------------------------------------------------------------ démarrage
  applyLang();
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
  Promise.all([kvGet('snapshot'), outAll(), kvGet('crm')]).then(function (v) {
    snap = v[0] || null; outbox = v[1] || []; crm = v[2] || null;
    render();
    if (auth) sync(false);
  }).catch(function () { render(); });
})();
