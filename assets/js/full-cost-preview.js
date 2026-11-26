/* Comparaison « coût complet » (test, administrateurs) : charge le résultat sur demande. */
(function () {
    if (window.ispagFullCostPreviewBound) { return; }
    window.ispagFullCostPreviewBound = true;

    document.addEventListener('click', function (e) {
        var btn = e.target.closest ? e.target.closest('.ispag-fullcost-run') : null;
        if (!btn) { return; }
        e.preventDefault();
        var box = btn.parentNode.querySelector('.ispag-fullcost-result');
        if (!box || btn.disabled) { return; }

        var data = new FormData();
        data.append('action', 'ispag_full_cost_preview');
        data.append('security', btn.getAttribute('data-nonce'));
        data.append('deal_id', btn.getAttribute('data-deal'));

        btn.disabled = true;
        box.textContent = '…';
        fetch(btn.getAttribute('data-url'), { method: 'POST', credentials: 'same-origin', body: data })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (res && res.success && res.data) {
                    box.innerHTML = res.data.html;
                } else {
                    box.textContent = (res && res.data && res.data.message) ? res.data.message : 'Error';
                }
            })
            .catch(function () { box.textContent = 'Error'; })
            .then(function () { btn.disabled = false; });
    });
})();
