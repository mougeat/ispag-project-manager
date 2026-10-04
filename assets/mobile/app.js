/* Application ISPAG (PWA) : projets consultables et livraisons signées, y compris hors ligne.
 * - Données : IndexedDB (instantané des projets + file d'attente des livraisons à envoyer).
 * - Jeton d'appareil : localStorage. Aucune donnée de prix n'est transmise à l'application.
 */
(function () {
  'use strict';
  const CFG = window.ISPAG_APP;

  // ------------------------------------------------------------------ textes
  const T = {
    fr: { login: 'Connexion', user: 'Identifiant ou e-mail', pass: 'Mot de passe', signin: 'Se connecter', badLogin: 'Identifiants incorrects.', noRight: "Ce compte n'a pas accès à l'application.",
      projects: 'Projets', search: 'Rechercher…', none: 'Aucun projet.', offline: 'Hors ligne', online: 'En ligne', synced: 'Données du', refresh: 'Actualiser', logout: 'Déconnexion',
      back: 'Retour', delivery: 'Livraison', address: 'Adresse de livraison', contact: 'Contact', customerRef: 'Réf. client', company: 'Client', articles: 'Articles', delivered: 'Livré', waiting: 'En attente de synchro',
      newDelivery: 'Nouvelle livraison', selectArticles: 'Articles livrés', receiver: 'Nom de la personne qui réceptionne', receiverPh: 'Prénom et nom', signature: 'Signature', signHere: 'Signez ici avec le doigt',
      clear: 'Effacer', confirm: 'Confirmer la livraison', nameMissing: 'Saisissez le nom.', signMissing: 'Signature manquante.', pickOne: 'Sélectionnez au moins un article.', saved: 'Livraison enregistrée',
      savedQueued: 'Livraison enregistrée sur le téléphone, elle sera envoyée dès que le réseau sera disponible.', sent: 'Livraison envoyée', queue: 'À envoyer', pending: 'livraison(s) à envoyer',
      receipts: 'Livraisons signées', pdf: 'PDF', allDone: 'Tous les articles sont livrés.', sync: 'Synchronisation…', syncFail: "Synchronisation impossible pour l'instant.", expired: 'Session expirée, reconnectez-vous (vos livraisons en attente sont conservées).',
      refused: 'Refusée par le serveur', retry: 'Réessayer', discard: 'Supprimer', never: 'jamais', install: "Pour installer : bouton Partager de Safari, puis « Sur l'écran d'accueil ».", noData: 'Aucune donnée sur ce téléphone : connectez-vous une première fois avec le réseau.', qty: 'Qté' },
    de: { login: 'Anmeldung', user: 'Benutzername oder E-Mail', pass: 'Passwort', signin: 'Anmelden', badLogin: 'Anmeldedaten falsch.', noRight: 'Dieses Konto hat keinen Zugriff auf die App.',
      projects: 'Projekte', search: 'Suchen…', none: 'Keine Projekte.', offline: 'Offline', online: 'Online', synced: 'Daten vom', refresh: 'Aktualisieren', logout: 'Abmelden',
      back: 'Zurück', delivery: 'Lieferung', address: 'Lieferadresse', contact: 'Kontakt', customerRef: 'Kundenreferenz', company: 'Kunde', articles: 'Artikel', delivered: 'Geliefert', waiting: 'Wartet auf Synchronisierung',
      newDelivery: 'Neue Lieferung', selectArticles: 'Gelieferte Artikel', receiver: 'Name der empfangenden Person', receiverPh: 'Vor- und Nachname', signature: 'Unterschrift', signHere: 'Hier mit dem Finger unterschreiben',
      clear: 'Löschen', confirm: 'Lieferung bestätigen', nameMissing: 'Bitte Namen eingeben.', signMissing: 'Unterschrift fehlt.', pickOne: 'Mindestens einen Artikel auswählen.', saved: 'Lieferung gespeichert',
      savedQueued: 'Lieferung auf dem Telefon gespeichert, sie wird gesendet, sobald ein Netz verfügbar ist.', sent: 'Lieferung gesendet', queue: 'Zu senden', pending: 'Lieferung(en) zu senden',
      receipts: 'Unterschriebene Lieferungen', pdf: 'PDF', allDone: 'Alle Artikel sind geliefert.', sync: 'Synchronisierung…', syncFail: 'Synchronisierung derzeit nicht möglich.', expired: 'Sitzung abgelaufen, bitte neu anmelden (ausstehende Lieferungen bleiben erhalten).',
      refused: 'Vom Server abgelehnt', retry: 'Erneut versuchen', discard: 'Löschen', never: 'nie', install: 'Installieren: Teilen-Taste in Safari, dann «Zum Home-Bildschirm».', noData: 'Keine Daten auf diesem Telefon: bitte einmal mit Netz anmelden.', qty: 'Menge' },
    en: { login: 'Sign in', user: 'Username or email', pass: 'Password', signin: 'Sign in', badLogin: 'Wrong credentials.', noRight: 'This account cannot use the app.',
      projects: 'Projects', search: 'Search…', none: 'No projects.', offline: 'Offline', online: 'Online', synced: 'Data from', refresh: 'Refresh', logout: 'Sign out',
      back: 'Back', delivery: 'Delivery', address: 'Delivery address', contact: 'Contact', customerRef: 'Customer ref.', company: 'Customer', articles: 'Items', delivered: 'Delivered', waiting: 'Waiting to sync',
      newDelivery: 'New delivery', selectArticles: 'Delivered items', receiver: 'Name of the person receiving', receiverPh: 'First and last name', signature: 'Signature', signHere: 'Sign here with your finger',
      clear: 'Clear', confirm: 'Confirm delivery', nameMissing: 'Please enter the name.', signMissing: 'Signature missing.', pickOne: 'Select at least one item.', saved: 'Delivery saved',
      savedQueued: 'Delivery saved on the phone; it will be sent as soon as a connection is available.', sent: 'Delivery sent', queue: 'To send', pending: 'delivery(ies) to send',
      receipts: 'Signed deliveries', pdf: 'PDF', allDone: 'All items are delivered.', sync: 'Syncing…', syncFail: 'Cannot sync right now.', expired: 'Session expired, please sign in again (pending deliveries are kept).',
      refused: 'Refused by the server', retry: 'Retry', discard: 'Delete', never: 'never', install: 'To install: Safari Share button, then “Add to Home Screen”.', noData: 'No data on this phone: sign in once while online.', qty: 'Qty' }
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
  let outbox = [];                           // livraisons en attente
  let syncing = false, syncMsg = '', sessionExpired = false;
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
    const done = function () { auth = null; snap = null; try { localStorage.removeItem('ispag.auth'); } catch (e) {} kvSet('snapshot', null); render(); };
    if (outbox.length && !confirm(outbox.length + ' ' + t('pending') + ' — ' + t('logout') + ' ?')) return;
    api('/logout', { method: 'POST' }).catch(function () {}).then(done);
  }

  // ------------------------------------------------------------------ synchronisation
  function pendingIds(deal) {
    const s = {};
    outbox.forEach(function (o) { if (o.deal_id === deal && !o.error) o.article_ids.forEach(function (id) { s[id] = true; }); });
    return s;
  }

  function sync(manual) {
    if (!auth || syncing || !navigator.onLine) { return Promise.resolve(); }
    syncing = true; syncMsg = t('sync'); renderStatus();
    let chain = Promise.resolve();
    outbox.slice().forEach(function (o) {
      chain = chain.then(function () {
        if (sessionExpired || o.error) return;
        return api('/deliveries', { method: 'POST', body: { client_id: o.client_id, deal_id: o.deal_id, article_ids: o.article_ids, receiver_name: o.receiver_name, signature: o.signature, signed_at: o.signed_at } })
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
    }).then(function () { syncMsg = ''; }).catch(function (e) {
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
    el.innerHTML = '<span>' + (off ? '⚠️ ' + t('offline') : '● ' + t('online')) + (snap ? ' · ' + t('synced') + ' ' + esc(fmtDate(snap.generated_at)) : '') + '</span><span>' + esc(syncMsg || (outbox.length ? '⏳ ' + outbox.length + ' ' + t('pending') : '')) + '</span>';
  }

  function shell(title, inner, opts) {
    opts = opts || {};
    app.innerHTML =
      '<header class="bar">' + (opts.back ? '<button data-act="back" aria-label="' + esc(t('back')) + '">‹</button>' : '') +
      '<h1>' + esc(title) + '</h1>' +
      (opts.noActions ? '' : '<button data-act="refresh" aria-label="' + esc(t('refresh')) + '">↻</button><button data-act="logout" aria-label="' + esc(t('logout')) + '">⎋</button>') +
      '</header><div id="status"></div><div class="wrap">' + inner + '</div>';
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
    if (!snap) { shell(t('projects'), '<div class="empty">' + esc(navigator.onLine ? t('sync') : t('noData')) + '</div>'); return; }
    const q = (sessionStorage.getItem('ispag.q') || '').toLowerCase();
    const list = snap.projects.filter(function (p) { return !q || (p.title + ' ' + p.number + ' ' + p.company + ' ' + p.customer_ref).toLowerCase().indexOf(q) >= 0; });
    let html = '<input type="search" id="q" placeholder="' + esc(t('search')) + '" value="' + esc(q) + '" style="margin-bottom:12px">';
    if (outbox.length) html += '<a class="card item" href="#/queue"><span class="t">⏳ ' + outbox.length + ' ' + esc(t('pending')) + '</span></a>';
    html += list.length ? list.map(function (p) {
      const todo = p.articles.filter(function (a) { return !a.done; }).length;
      return '<a class="card item" href="#/p/' + p.deal_id + '"><div class="t">' + esc(p.title || p.number) + '</div><div class="s">' + esc([p.number, p.company].filter(Boolean).join(' · ')) + '</div>' +
        '<div class="s">' + p.articles.length + ' ' + esc(t('articles').toLowerCase()) + (todo ? '<span class="badge">' + todo + ' ✕</span>' : '<span class="badge ok">✓</span>') + '</div></a>';
    }).join('') : '<div class="empty">' + esc(t('none')) + '</div>';
    shell(t('projects'), html);
    const qi = app.querySelector('#q');
    qi.addEventListener('input', function () { sessionStorage.setItem('ispag.q', qi.value); const pos = qi.selectionStart; renderList(); const n = app.querySelector('#q'); n.focus(); n.setSelectionRange(pos, pos); });
  }

  function addrLine(d) { return [d.address, d.address2, d.address3, [d.zip, d.city].filter(Boolean).join(' ')].filter(Boolean).join(', '); }

  function renderProject(id) {
    const p = findProject(id);
    if (!p) { go('#/'); return; }
    const pend = pendingIds(id);
    const d = p.delivery || {};
    let html = '<div class="card red"><div class="kv"><span>' + esc(t('company')) + '</span><strong>' + esc(p.company) + '</strong></div>' +
      (p.customer_ref ? '<div class="kv"><span>' + esc(t('customerRef')) + '</span><strong>' + esc(p.customer_ref) + '</strong></div>' : '') +
      (addrLine(d) ? '<div class="kv"><span>' + esc(t('address')) + '</span><a href="https://maps.google.com/?q=' + encodeURIComponent(addrLine(d)) + '" target="_blank" rel="noopener">' + esc(addrLine(d)) + '</a></div>' : '') +
      (d.contact || d.phone ? '<div class="kv"><span>' + esc(t('contact')) + '</span><span style="color:inherit">' + esc(d.contact) + ' ' + (d.phone ? '<a href="tel:' + esc(d.phone.replace(/[^+\d]/g, '')) + '">' + esc(d.phone) + '</a>' : '') + '</span></div>' : '') +
      '</div>';
    const todo = p.articles.filter(function (a) { return !a.done && !pend[a.id]; });
    html += '<div class="card"><h2>' + esc(t('articles')) + '</h2>' + (p.articles.map(function (a) {
      const badge = a.done ? '<span class="badge ok">' + esc(t('delivered')) + '</span>' : (pend[a.id] ? '<span class="badge wait">⏳</span>' : '');
      return '<div class="art' + (a.master ? ' sub' : '') + (a.done ? ' done' : '') + '"><span class="n">' + esc(a.name) + (a.ref ? ' <span class="hint">' + esc(a.ref) + '</span>' : '') + badge + '</span><span class="q">' + esc(a.qty) + '</span></div>';
    }).join('') || '<div class="empty">' + esc(t('none')) + '</div>') + '</div>';
    html += todo.length ? '<button class="btn green" data-act="deliver">✍️ ' + esc(t('newDelivery')) + '</button>' : '<p class="hint" style="text-align:center">' + esc(t('allDone')) + '</p>';
    if (p.receipts && p.receipts.length) {
      html += '<div class="card" style="margin-top:12px"><h2>' + esc(t('receipts')) + '</h2>' + p.receipts.map(function (r) {
        return '<div class="kv"><span>' + esc(fmtDate(r.at.replace(' ', 'T'))) + '</span><span style="color:inherit">' + esc(r.by) + (r.pdf ? ' · <a href="' + esc(r.pdf) + '" target="_blank" rel="noopener">' + esc(t('pdf')) + '</a>' : '') + '</span></div>';
      }).join('') + '</div>';
    }
    shell(p.title || p.number, html, { back: true });
  }

  function renderQueue() {
    let html = outbox.length ? outbox.map(function (o) {
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

  // ------------------------------------------------------------------ navigation
  function render() {
    if (!auth) { renderLogin(); return; }
    if (sessionExpired && !navigator.onLine === false) { /* la bannière d'état explique la situation */ }
    const r = route();
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
    else if (act === 'discard') { outDel(b.dataset.id).then(function () { outbox = outbox.filter(function (x) { return x.client_id !== b.dataset.id; }); render(); }); }
  });
  window.addEventListener('hashchange', render);
  window.addEventListener('online', function () { renderStatus(); sync(false); });
  window.addEventListener('offline', renderStatus);
  document.addEventListener('visibilitychange', function () { if (!document.hidden) sync(false); });

  // ------------------------------------------------------------------ démarrage
  applyLang();
  if ('serviceWorker' in navigator) navigator.serviceWorker.register(CFG.base + 'sw.js', { scope: CFG.base }).catch(function () {});
  if (navigator.storage && navigator.storage.persist) navigator.storage.persist().catch(function () {});
  Promise.all([kvGet('snapshot'), outAll()]).then(function (v) {
    snap = v[0] || null; outbox = v[1] || [];
    render();
    if (auth) sync(false);
  }).catch(function () { render(); });
})();
