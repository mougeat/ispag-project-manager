(function () {
    'use strict';

    var cfg = window.ispagCalendar;
    var root = document.querySelector('.ispag-calendar-wrapper');
    if (!cfg || !root) return;

    var MAX_VISIBLE = 3;
    var body = root.querySelector('.ispag-cal-body');
    var titleEl = root.querySelector('[data-cal-title]');
    var legendEl = root.querySelector('[data-cal-legend]');
    var searchEl = root.querySelector('[data-cal-search]');
    var viewBtns = root.querySelectorAll('[data-cal-view]');

    var state = {
        month: parseInt(root.dataset.month, 10),
        year: parseInt(root.dataset.year, 10),
        view: 'month',
        hidden: {},      // types masqués
        query: ''
    };
    var cache = {};      // "YYYY-M" => {title, html}
    var requestId = 0;

    try { state.view = localStorage.getItem('ispagCalView') || 'month'; } catch (e) {}
    if (window.matchMedia('(max-width: 768px)').matches) state.view = 'list';

    function key(m, y) { return y + '-' + m; }

    function shift(m, y, delta) {
        m += delta;
        if (m < 1) { m = 12; y--; }
        if (m > 12) { m = 1; y++; }
        return { month: m, year: y };
    }

    /* ---------- Chargement ---------- */
    function fetchMonth(m, y) {
        var k = key(m, y);
        if (cache[k]) return Promise.resolve(cache[k]);

        var fd = new FormData();
        fd.append('action', 'ispag_calendar_month');
        fd.append('nonce', cfg.nonce);
        fd.append('month', m);
        fd.append('year', y);

        return fetch(cfg.ajaxurl, { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (!res.success) throw new Error('bad response');
                cache[k] = res.data;
                return res.data;
            });
    }

    function prefetchAround() {
        [-1, 1].forEach(function (d) {
            var n = shift(state.month, state.year, d);
            fetchMonth(n.month, n.year).catch(function () {});
        });
    }

    function go(m, y, push) {
        var id = ++requestId;
        state.month = m;
        state.year = y;
        root.classList.add('is-loading');

        fetchMonth(m, y).then(function (data) {
            if (id !== requestId) return; // navigation plus récente en cours
            body.innerHTML = data.html;
            titleEl.textContent = data.title;
            root.dataset.month = m;
            root.dataset.year = y;
            renderLegend();
            apply();
            root.classList.remove('is-loading');
            if (push) {
                var url = new URL(window.location.href);
                url.searchParams.set('cal_month', m);
                url.searchParams.set('cal_year', y);
                history.pushState({ m: m, y: y }, '', url.toString());
            }
            prefetchAround();
        }).catch(function () {
            if (id !== requestId) return;
            root.classList.remove('is-loading');
            var err = document.createElement('div');
            err.className = 'ispag-notice error';
            err.textContent = cfg.i18n.error;
            body.prepend(err);
        });
    }

    /* ---------- Légende / filtres ---------- */
    function renderLegend() {
        var tpl = body.querySelector('[data-cal-legend-tpl]');
        legendEl.innerHTML = tpl ? tpl.innerHTML : '';
        legendEl.querySelectorAll('.ispag-cal-chip').forEach(function (chip) {
            var off = !!state.hidden[chip.dataset.type];
            chip.classList.toggle('is-off', off);
            chip.setAttribute('aria-pressed', off ? 'false' : 'true');
        });
    }

    legendEl.addEventListener('click', function (e) {
        var chip = e.target.closest('.ispag-cal-chip');
        if (!chip) return;
        var t = chip.dataset.type;
        if (state.hidden[t]) delete state.hidden[t]; else state.hidden[t] = true;
        renderLegend();
        apply();
    });

    var searchTimer;
    searchEl.addEventListener('input', function () {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(function () {
            state.query = searchEl.value.trim().toLowerCase();
            apply();
        }, 120);
    });

    /* ---------- Application filtres + vue + "+N autres" ---------- */
    function matches(ev) {
        if (state.hidden[ev.dataset.type]) return false;
        return !state.query || ev.dataset.search.indexOf(state.query) !== -1;
    }

    function apply() {
        var monthView = body.querySelector('.ispag-cal-view-month');
        var listView = body.querySelector('.ispag-cal-view-list');
        if (!monthView || !listView) return;

        monthView.hidden = state.view !== 'month';
        listView.hidden = state.view !== 'list';
        viewBtns.forEach(function (b) {
            b.classList.toggle('is-active', b.dataset.calView === state.view);
        });

        // Vue mois : filtre + repli au-delà de MAX_VISIBLE
        monthView.querySelectorAll('.calendar-day').forEach(function (cell) {
            var expanded = cell.classList.contains('is-expanded');
            var old = cell.querySelector('.evt-more');
            if (old) old.remove();

            var shown = 0, extra = 0;
            cell.querySelectorAll('.delivery-event').forEach(function (ev) {
                if (!matches(ev)) { ev.hidden = true; return; }
                shown++;
                ev.hidden = !expanded && shown > MAX_VISIBLE;
                if (ev.hidden) extra++;
            });

            if (extra > 0 || (expanded && shown > MAX_VISIBLE)) {
                var btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'evt-more';
                btn.textContent = expanded ? '−' : '+' + extra + ' ' + cfg.i18n.more;
                cell.querySelector('.day-events').appendChild(btn);
            }
            cell.classList.toggle('has-events', shown > 0);
        });

        // Vue liste : filtre + masque les jours vides
        var anyVisible = false;
        listView.querySelectorAll('.ispag-cal-list-day').forEach(function (sec) {
            var n = 0;
            sec.querySelectorAll('.delivery-event').forEach(function (ev) {
                var ok = matches(ev);
                ev.hidden = !ok;
                if (ok) n++;
            });
            sec.hidden = n === 0;
            if (n) anyVisible = true;
        });
        var noMatch = listView.querySelector('.ispag-cal-nomatch');
        if (noMatch && listView.querySelector('.ispag-cal-list-day')) noMatch.hidden = anyVisible;
    }

    body.addEventListener('click', function (e) {
        var more = e.target.closest('.evt-more');
        if (!more) return;
        more.closest('.calendar-day').classList.toggle('is-expanded');
        apply();
    });

    /* ---------- Navigation ---------- */
    root.querySelector('[data-cal-prev]').addEventListener('click', function () {
        var n = shift(state.month, state.year, -1); go(n.month, n.year, true);
    });
    root.querySelector('[data-cal-next]').addEventListener('click', function () {
        var n = shift(state.month, state.year, 1); go(n.month, n.year, true);
    });
    root.querySelector('[data-cal-today]').addEventListener('click', function () {
        var d = new Date(); go(d.getMonth() + 1, d.getFullYear(), true);
    });

    viewBtns.forEach(function (b) {
        b.addEventListener('click', function () {
            state.view = b.dataset.calView;
            try { localStorage.setItem('ispagCalView', state.view); } catch (e) {}
            apply();
            if (state.view === 'list') scrollToToday();
        });
    });

    document.addEventListener('keydown', function (e) {
        if (/^(input|textarea|select)$/i.test(e.target.tagName)) return;
        if (e.key === 'ArrowLeft') { var p = shift(state.month, state.year, -1); go(p.month, p.year, true); }
        if (e.key === 'ArrowRight') { var n = shift(state.month, state.year, 1); go(n.month, n.year, true); }
    });

    window.addEventListener('popstate', function (e) {
        var s = e.state;
        if (s && s.m) { go(s.m, s.y, false); return; }
        var p = new URL(window.location.href).searchParams;
        var d = new Date();
        go(parseInt(p.get('cal_month'), 10) || d.getMonth() + 1,
           parseInt(p.get('cal_year'), 10) || d.getFullYear(), false);
    });

    // Swipe gauche/droite sur mobile
    var x0 = null;
    body.addEventListener('touchstart', function (e) { x0 = e.touches[0].clientX; }, { passive: true });
    body.addEventListener('touchend', function (e) {
        if (x0 === null) return;
        var dx = e.changedTouches[0].clientX - x0;
        x0 = null;
        if (Math.abs(dx) < 80) return;
        var n = shift(state.month, state.year, dx < 0 ? 1 : -1);
        go(n.month, n.year, true);
    }, { passive: true });

    function scrollToToday() {
        var t = body.querySelector('.ispag-cal-list-day.is-today') || body.querySelector('.calendar-day.is-today');
        if (t && t.offsetParent !== null) t.scrollIntoView({ block: 'center', behavior: 'smooth' });
    }

    /* ---------- Init ---------- */
    history.replaceState({ m: state.month, y: state.year }, '', window.location.href);
    cache[key(state.month, state.year)] = { title: titleEl.textContent, html: body.innerHTML };
    renderLegend();
    apply();
    prefetchAround();
    if (state.view === 'list') scrollToToday();
})();
