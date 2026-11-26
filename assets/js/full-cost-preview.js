/* Comparaison « coût complet » (test, administrateurs) : charge le résultat sur demande, puis applique les prix cochés. */
(function () {
    if (window.ispagFullCostPreviewBound) { return; }
    window.ispagFullCostPreviewBound = true;

    function post(url, data) {
        return fetch(url, { method: 'POST', credentials: 'same-origin', body: data }).then(function (r) { return r.json(); });
    }

    document.addEventListener('click', function (e) {
        var target = e.target;
        if (!target || !target.closest) { return; }

        // 1) Comparaison
        var btn = target.closest('.ispag-fullcost-run');
        if (btn) {
            e.preventDefault();
            var box = btn.parentNode.querySelector('.ispag-fullcost-result');
            if (!box || btn.disabled) { return; }

            var data = new FormData();
            data.append('action', 'ispag_full_cost_preview');
            data.append('security', btn.getAttribute('data-nonce'));
            data.append('deal_id', btn.getAttribute('data-deal'));

            btn.disabled = true;
            box.textContent = '…';
            post(btn.getAttribute('data-url'), data)
                .then(function (res) {
                    if (res && res.success && res.data) {
                        box.innerHTML = res.data.html;
                    } else {
                        box.textContent = (res && res.data && res.data.message) ? res.data.message : 'Error';
                    }
                })
                .catch(function () { box.textContent = 'Error'; })
                .then(function () { btn.disabled = false; });
            return;
        }

        // 2) Application des prix cochés (prix manuels)
        var apply = target.closest('.ispag-fullcost-apply');
        if (apply) {
            e.preventDefault();
            if (apply.disabled) { return; }
            var holder = apply.closest('.ispag-fullcost-result');
            var picks = holder ? holder.querySelectorAll('.ispag-fullcost-pick:checked') : [];
            if (!picks.length) {
                window.alert(apply.getAttribute('data-none'));
                return;
            }
            if (!window.confirm((apply.getAttribute('data-confirm') || '').replace('%d', picks.length))) { return; }

            var form = new FormData();
            form.append('action', 'ispag_full_cost_apply');
            form.append('security', apply.getAttribute('data-nonce'));
            form.append('deal_id', apply.getAttribute('data-deal'));
            Array.prototype.forEach.call(picks, function (p) { form.append('article_ids[]', p.value); });

            var wrap = holder.parentNode;
            function notify(ok, msg) {
                var old = wrap.querySelector('.ispag-fullcost-note');
                if (old) { old.parentNode.removeChild(old); }
                var note = document.createElement('div');
                note.className = 'ispag-fullcost-note';
                note.style.cssText = 'padding:10px 12px;margin:8px 0;border-left:4px solid ' + (ok ? '#00a32a' : '#d63638') + ';background:' + (ok ? '#edfaef' : '#fcf0f1') + ';color:#1d2327;font-weight:600';
                note.textContent = msg;
                wrap.insertBefore(note, holder);
            }

            apply.disabled = true;
            apply.textContent = '…';
            post(apply.getAttribute('data-url'), form)
                .then(function (res) {
                    var ok = !!(res && res.success);
                    notify(ok, (res && res.data && res.data.message) ? res.data.message : 'Error');
                    if (ok) {
                        // Recharge la comparaison : les lignes appliquées passent en « Prix manuel »
                        var run = wrap.querySelector('.ispag-fullcost-run');
                        if (run) { run.click(); }
                    } else {
                        apply.disabled = false;
                    }
                })
                .catch(function () { apply.disabled = false; notify(false, 'Error'); });
        }
    });
})();
