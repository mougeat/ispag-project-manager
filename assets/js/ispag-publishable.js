/* Interrupteur « Publiable » de la page projet : enregistrement AJAX. */
(function () {
    'use strict';
    document.addEventListener('change', function (e) {
        var c = e.target;
        if (!c || !c.classList || !c.classList.contains('ispag-publishable-input')) return;
        var f = new FormData();
        f.append('action', 'ispag_toggle_publishable');
        f.append('deal_id', c.getAttribute('data-deal'));
        f.append('nonce', c.getAttribute('data-nonce'));
        f.append('on', c.checked ? 1 : 0);
        c.disabled = true;
        var revert = function (msg) { c.checked = !c.checked; window.alert(msg); };
        fetch(c.getAttribute('data-url'), { method: 'POST', credentials: 'same-origin', body: f })
            .then(function (r) { return r.text().then(function (t) { var j; try { j = JSON.parse(t); } catch (x) { j = null; } return { ok: r.ok, j: j, t: t, s: r.status }; }); })
            .then(function (r) {
                if (!r.j || !r.j.success) { revert('Error: not saved (HTTP ' + r.s + ') ' + (r.j && r.j.data ? r.j.data : r.t.slice(0, 120))); }
                else if (!!r.j.data.publishable !== c.checked) { c.checked = !!r.j.data.publishable; }
            })
            .catch(function (err) { revert('Network error: ' + err); })
            .then(function () { c.disabled = false; });
    });
})();
