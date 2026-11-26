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

            apply.disabled = true;
            post(apply.getAttribute('data-url'), form)
                .then(function (res) {
                    var msg = (res && res.data && res.data.message) ? res.data.message : 'Error';
                    var note = document.createElement('p');
                    note.className = 'notice ' + (res && res.success ? 'notice-success' : 'notice-error');
                    note.style.cssText = 'padding:8px;margin:8px 0';
                    note.textContent = msg;
                    apply.parentNode.parentNode.insertBefore(note, apply.parentNode.nextSibling);
                    if (!(res && res.success)) { apply.disabled = false; }
                })
                .catch(function () { apply.disabled = false; window.alert('Error'); });
        }
    });
})();
