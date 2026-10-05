/* Guide pas à pas ISPAG : étapes « cliquez ici pour… », ignorable et relançable (bouton ? en bas à droite).
 * Données et textes : ISPAG_Guided_Tour (PHP) → window.ispagTour. Aucune dépendance. */
(function () {
  'use strict';
  const C = window.ispagTour;
  if (!C || !C.tours || !C.tours.length) return;
  const T = C.i18n;
  const state = C.state || {};
  // Filet de sécurité : si la requête d'enregistrement est interrompue (changement de page immédiat), l'« ignoré » est retenu localement
  const LS = 'ispag_tour_skipped_' + (C.uid || '0');
  function lsGet() { try { return JSON.parse(localStorage.getItem(LS) || '{}') || {}; } catch (e) { return {}; } }
  function lsSet(o) { try { localStorage.setItem(LS, JSON.stringify(o)); } catch (e) {} }
  (function () { const l = lsGet(); Object.keys(l).forEach(function (k) { if (!state[k]) state[k] = 'skipped'; }); })();
  let running = false, ui = null, steps = [], idx = 0, current = null, auto = false;

  // ------------------------------------------------------------------ utilitaires
  function el(tag, cls, html) { const e = document.createElement(tag); if (cls) e.className = cls; if (html != null) e.innerHTML = html; return e; }
  function esc(s) { return String(s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }

  function visible(e) {
    if (!e) return false;
    const r = e.getBoundingClientRect(), cs = getComputedStyle(e);
    return r.width > 4 && r.height > 4 && cs.visibility !== 'hidden' && cs.display !== 'none' && !e.closest('[hidden]');
  }
  function findTarget(sel) {
    if (!sel) return null;
    let list;
    try { list = document.querySelectorAll(sel); } catch (e) { return null; }
    for (let i = 0; i < list.length; i++) if (visible(list[i])) return list[i];
    return null;
  }
  function save(tour, value) {
    state[tour] = value;
    const fd = new FormData();
    fd.append('action', C.action); fd.append('nonce', C.nonce); fd.append('tour', tour); fd.append('value', value);
    if (value === 'skipped' || value === 'done') {
      const l = lsGet();
      if (tour === '*') C.tours.forEach(function (t) { if (!l[t.id]) l[t.id] = 1; }); else l[tour] = 1;
      lsSet(l);
    } else if (value === 'reset') lsSet({});
    try { fetch(C.ajax, { method: 'POST', body: fd, credentials: 'same-origin', keepalive: true }).catch(function () {}); } catch (e) {}
  }
  function modalOpen() {
    const m = document.getElementById('ispag-notification-settings-modal');
    return !!(m && visible(m));
  }

  // ------------------------------------------------------------------ rendu d'une étape
  function build() {
    const blocker = el('div', 'ispag-tour-blocker');
    const spot = el('div', 'ispag-tour-spot');
    const pop = el('div', 'ispag-tour-pop');
    pop.setAttribute('role', 'dialog'); pop.setAttribute('aria-live', 'polite');
    document.body.appendChild(blocker); document.body.appendChild(spot); document.body.appendChild(pop);
    ui = { blocker: blocker, spot: spot, pop: pop };
    blocker.addEventListener('click', function (e) { e.stopPropagation(); });
  }
  function destroy() {
    if (!ui) return;
    ['blocker', 'spot', 'pop'].forEach(function (k) { if (ui[k].parentNode) ui[k].parentNode.removeChild(ui[k]); });
    ui = null;
    window.removeEventListener('resize', reposition); window.removeEventListener('scroll', reposition, true);
    document.removeEventListener('keydown', onKey, true);
  }

  function place(target, pop, preferred) {
    const vw = window.innerWidth, vh = window.innerHeight, pad = 12;
    if (vw < 640) { pop.style.left = '8px'; pop.style.right = '8px'; pop.style.top = 'auto'; pop.style.bottom = '8px'; pop.style.width = 'auto'; return; }
    pop.style.right = 'auto'; pop.style.bottom = 'auto'; pop.style.width = '';
    const pw = pop.offsetWidth, ph = pop.offsetHeight;
    if (!target) { pop.style.left = Math.max(pad, (vw - pw) / 2) + 'px'; pop.style.top = Math.max(pad, (vh - ph) / 2) + 'px'; return; }
    const r = target.getBoundingClientRect();
    const room = { bottom: vh - r.bottom, top: r.top, right: vw - r.right, left: r.left };
    const need = { bottom: ph + pad * 2, top: ph + pad * 2, right: pw + pad * 2, left: pw + pad * 2 };
    let side = preferred && room[preferred] >= need[preferred] ? preferred : null;
    if (!side) side = ['bottom', 'top', 'right', 'left'].filter(function (s) { return room[s] >= need[s]; })[0] || (room.bottom >= room.top ? 'bottom' : 'top');
    let x, y;
    if (side === 'bottom') { x = r.left + r.width / 2 - pw / 2; y = r.bottom + pad; }
    else if (side === 'top') { x = r.left + r.width / 2 - pw / 2; y = r.top - ph - pad; }
    else if (side === 'right') { x = r.right + pad; y = r.top + r.height / 2 - ph / 2; }
    else { x = r.left - pw - pad; y = r.top + r.height / 2 - ph / 2; }
    pop.style.left = Math.min(Math.max(pad, x), vw - pw - pad) + 'px';
    pop.style.top = Math.min(Math.max(pad, y), vh - ph - pad) + 'px';
  }

  function reposition() {
    if (!ui || !steps[idx]) return;
    const t = steps[idx].target;
    if (t) {
      const r = t.getBoundingClientRect(), m = 6;
      ui.spot.style.cssText = 'display:block;left:' + (r.left - m) + 'px;top:' + (r.top - m) + 'px;width:' + (r.width + m * 2) + 'px;height:' + (r.height + m * 2) + 'px';
    } else {
      ui.spot.style.cssText = 'display:block;left:50%;top:50%;width:0;height:0';
    }
    place(t, ui.pop, steps[idx].place);
  }

  function show(i) {
    idx = i;
    const s = steps[i];
    if (!ui) build();
    const last = i === steps.length - 1;
    ui.pop.innerHTML =
      '<button type="button" class="ispag-tour-x" aria-label="' + esc(T.close) + '">&times;</button>' +
      '<div class="ispag-tour-count">' + esc(T.step.replace('%1$d', i + 1).replace('%2$d', steps.length)) + ' · ' + esc(current.title) + '</div>' +
      '<h4>' + esc(s.title) + '</h4><p>' + esc(s.text) + '</p>' +
      '<div class="ispag-tour-bar"><button type="button" class="ispag-tour-skip">' + esc(T.skip) + '</button><span>' +
      (i > 0 ? '<button type="button" class="ispag-tour-back">' + esc(T.back) + '</button>' : '') +
      '<button type="button" class="ispag-tour-next">' + esc(last ? T.done : T.next) + '</button></span></div>';
    ui.pop.querySelector('.ispag-tour-next').addEventListener('click', next);
    const b = ui.pop.querySelector('.ispag-tour-back'); if (b) b.addEventListener('click', function () { show(i - 1); });
    ui.pop.querySelector('.ispag-tour-skip').addEventListener('click', skip);
    ui.pop.querySelector('.ispag-tour-x').addEventListener('click', skip);

    if (s.target) { try { s.target.scrollIntoView({ block: 'center', behavior: 'smooth' }); } catch (e) { s.target.scrollIntoView(); } }
    ui.pop.style.visibility = 'hidden';
    setTimeout(function () { if (!ui) return; reposition(); ui.pop.style.visibility = ''; ui.pop.querySelector('.ispag-tour-next').focus({ preventScroll: true }); }, s.target ? 380 : 0);
    reposition();
  }

  function onKey(e) {
    if (!ui) return;
    if (e.key === 'Escape') { e.preventDefault(); skip(); }
    else if (e.key === 'ArrowRight' || e.key === 'Enter') { e.preventDefault(); next(); }
    else if (e.key === 'ArrowLeft' && idx > 0) { e.preventDefault(); show(idx - 1); }
  }

  function end() {
    destroy(); running = false; steps = [];
  }
  function next() {
    if (idx < steps.length - 1) { show(idx + 1); return; }
    save(current.id, 'done');
    const finished = current;
    end();
    if (auto) startNextUnseen(finished.id);   // « bienvenue » puis guide de la page
  }
  function skip() {
    // « Ignorer » = ignorer les guides (tous, sur toutes les pages) ; ils reviennent avec le bouton ? → « Montrer à nouveau tous les guides »
    save('*', 'skipped');
    C.tours.forEach(function (t) { if (!state[t.id]) state[t.id] = 'skipped'; });
    end();
    toast(T.later);
  }

  function toast(text) {
    const t = el('div', 'ispag-tour-toast', esc(text));
    document.body.appendChild(t);
    setTimeout(function () { t.classList.add('out'); setTimeout(function () { if (t.parentNode) t.parentNode.removeChild(t); }, 400); }, 3800);
  }

  // ------------------------------------------------------------------ lancement
  /** Attend (jusqu'à 4 s) que les éléments ciblés soient affichés (cartes chargées en AJAX), puis ne garde que les étapes utilisables. */
  function resolveSteps(tour, cb) {
    const start = Date.now();
    (function poll() {
      const all = tour.steps.map(function (s) { return { sel: s.sel, title: s.title, text: s.text, place: s.place, target: s.sel ? findTarget(s.sel) : null }; });
      const pending = all.some(function (s) { return s.sel && !s.target; });
      if (!pending || Date.now() - start > 4000) {
        cb(all.filter(function (s) { return !s.sel || s.target; }));
      } else setTimeout(poll, 400);
    })();
  }

  function run(tour, isAuto) {
    if (running) return;
    running = true; auto = !!isAuto; current = tour;
    resolveSteps(tour, function (list) {
      // les cibles ont pu changer pendant l'attente : on les retrouve au moment d'afficher
      if (!list.length) { running = false; if (auto) startNextUnseen(tour.id); return; }
      steps = list; idx = 0;
      window.addEventListener('resize', reposition); window.addEventListener('scroll', reposition, true);
      document.addEventListener('keydown', onKey, true);
      show(0);
    });
  }

  function startNextUnseen(afterId) {
    const t = C.tours.filter(function (x) { return !x.trigger && !state[x.id] && x.id !== afterId; })[0];
    if (t) setTimeout(function () { run(t, true); }, 500);
  }

  // ------------------------------------------------------------------ bouton « ? » (relancer / réinitialiser)
  function launcher() {
    const btn = el('button', 'ispag-tour-launcher', '?');
    btn.id = 'ispag-tour-launcher'; btn.type = 'button'; btn.title = T.help; btn.setAttribute('aria-label', T.help);
    const menu = el('div', 'ispag-tour-menu'); menu.hidden = true;
    const pageTour = C.tours.filter(function (t) { return t.page === 'page' && !t.trigger; })[0];
    const welcome = C.tours.filter(function (t) { return t.page === 'welcome'; })[0];
    function item(label, fn) { const b = el('button', '', esc(label)); b.type = 'button'; b.addEventListener('click', function () { menu.hidden = true; fn(); }); menu.appendChild(b); }
    if (pageTour) item(T.replay, function () { run(pageTour, false); });
    if (welcome) item(T.welcome, function () { run(welcome, false); });
    item(T.resetAll, function () {
      const fd = new FormData(); fd.append('action', C.action); fd.append('nonce', C.nonce); fd.append('tour', '*'); fd.append('value', 'reset');
      lsSet({});
      try { fetch(C.ajax, { method: 'POST', body: fd, credentials: 'same-origin', keepalive: true }); } catch (e) {}
      Object.keys(state).forEach(function (k) { delete state[k]; });
      toast(T.resetOk);
    });
    btn.addEventListener('click', function (e) { e.stopPropagation(); menu.hidden = !menu.hidden; });
    document.addEventListener('click', function () { menu.hidden = true; });
    document.body.appendChild(menu); document.body.appendChild(btn);
  }

  // ------------------------------------------------------------------ guides déclenchés par l'ouverture d'une fenêtre
  /** Surveille l'apparition de la fenêtre (ex. édition d'un article) : lancement à la première ouverture + petit bouton ? dans son en-tête. */
  function watchTriggers() {
    const trig = C.tours.filter(function (t) { return t.trigger; });
    if (!trig.length) return;
    const shown = {};   // une ouverture = un lancement automatique au plus
    function check() {
      trig.forEach(function (t) {
        const form = findTarget(t.trigger);
        if (!form) { shown[t.id] = false; return; }
        addHelp(t, form);
        if (!state[t.id] && !shown[t.id] && !running && !modalOpen()) {
          shown[t.id] = true;
          setTimeout(function () { if (!running && findTarget(t.trigger)) run(t, false); }, 700);
        }
      });
    }
    let pending = null;
    new MutationObserver(function () { clearTimeout(pending); pending = setTimeout(check, 250); })
      .observe(document.body, { childList: true, subtree: true, attributes: true, attributeFilter: ['style', 'class'] });
  }
  function addHelp(tour, form) {
    const host = form.closest('.ispag-product-modal, .ispag-modal-content, [role=dialog]');
    const head = host && host.querySelector('.ispag-modal-header');
    if (!head || head.querySelector('.ispag-tour-modal-help')) return;
    const b = el('button', 'ispag-tour-modal-help', '?');
    b.type = 'button'; b.title = T.help; b.setAttribute('aria-label', T.help);
    b.addEventListener('click', function (e) { e.preventDefault(); e.stopPropagation(); run(tour, false); });
    head.appendChild(b);
  }

  function boot() {
    launcher();
    // Lancement automatique : seulement les guides jamais vus, une fois la page (et une éventuelle fenêtre d'accueil) prête
    watchTriggers();
    const first = C.tours.filter(function (t) { return !t.trigger && !state[t.id]; })[0];
    if (!first) return;
    const t0 = Date.now();
    (function wait() {
      if (running) return;
      if (modalOpen() && Date.now() - t0 < 90000) { setTimeout(wait, 800); return; }
      run(first, true);
    })();
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', function () { setTimeout(boot, 1200); });
  else setTimeout(boot, 1200);
})();
