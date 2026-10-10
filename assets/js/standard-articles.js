/**
 * Articles standard : liste, fiche (ventes / achats), historiques de prix.
 * Données : ispagStd { ajax_url, nonce, saved, error, confirm_delete_article, confirm_delete_purchase }
 */
(function () {
    'use strict';
    if (typeof ispagStd === 'undefined') return;

    function post(action, data) {
        var body = new URLSearchParams();
        body.append('action', 'ispag_std_' + action);
        body.append('nonce', ispagStd.nonce);
        Object.keys(data).forEach(function (k) { body.append(k, data[k]); });
        return fetch(ispagStd.ajax_url, { method: 'POST', credentials: 'same-origin', body: body })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (!res.success) throw new Error((res.data && res.data.message) || ispagStd.error);
                return res.data || {};
            });
    }

    function say(el, text, isError) {
        var box = el && el.closest('.ispag-std-wrap') ? el.closest('.ispag-std-inline-form, .ispag-std-head, label, .ispag-std-supplier') : null;
        var msg = (box && box.querySelector('.ispag-std-msg')) || document.querySelector('.ispag-std-head .ispag-std-msg');
        if (!msg) return;
        msg.textContent = text;
        msg.classList.toggle('is-error', !!isError);
        clearTimeout(msg._t);
        msg._t = setTimeout(function () { msg.textContent = ''; }, isError ? 6000 : 2000);
    }

    // Liste : clic sur une ligne
    document.addEventListener('click', function (e) {
        var row = e.target.closest('.ispag-std-row');
        if (row && !e.target.closest('a, button, input, select')) window.location.href = row.dataset.href;
    });

    // Nouvel article
    var toggle = document.getElementById('ispag-std-new-toggle');
    var newForm = document.getElementById('ispag-std-new-form');
    if (toggle && newForm) {
        toggle.addEventListener('click', function () { newForm.style.display = newForm.style.display === 'none' ? 'flex' : 'none'; });
        newForm.addEventListener('submit', function (e) {
            e.preventDefault();
            post('create', { title: newForm.title.value, type: newForm.type.value })
                .then(function (d) { window.location.href = d.url; })
                .catch(function (err) { say(newForm, err.message, true); });
        });
    }

    // Onglets de la fiche
    document.addEventListener('click', function (e) {
        var tab = e.target.closest('.ispag-std-tab');
        if (!tab) return;
        var wrap = tab.closest('.ispag-std-sheet');
        wrap.querySelectorAll('.ispag-std-tab').forEach(function (t) { t.classList.toggle('is-active', t === tab); });
        wrap.querySelectorAll('.ispag-std-pane').forEach(function (p) {
            var on = p.dataset.pane === tab.dataset.tab;
            p.classList.toggle('is-active', on);
            p.hidden = !on;
        });
        try { history.replaceState(null, '', '#' + tab.dataset.tab); } catch (err) {}
    });
    var hash = (location.hash || '').replace('#', '');
    if (hash) { var t = document.querySelector('.ispag-std-tab[data-tab="' + hash + '"]'); if (t) t.click(); }

    // Champs enregistrés automatiquement au changement
    document.addEventListener('change', function (e) {
        var f = e.target.closest('.ispag-std-field');
        if (!f || f.disabled || f.readOnly) return;
        var scope = f.dataset.scope;
        var action = scope === 'purchase' ? 'save_purchase_field' : 'save_sales_field';
        f.classList.remove('is-saved', 'is-error');
        post(action, { id: f.dataset.id, field: f.dataset.field, value: f.type === 'checkbox' ? (f.checked ? 1 : 0) : f.value })
            .then(function (d) {
                if (typeof d.value !== 'undefined' && f.tagName !== 'SELECT' && f.type !== 'checkbox') f.value = d.value;
                f.classList.add('is-saved');
                say(f, ispagStd.saved);
                if (f.dataset.field === 'image' || f.dataset.field === 'TypeArticle') location.reload();
            })
            .catch(function (err) { f.classList.add('is-error'); say(f, err.message, true); });
    });

    // Changement de prix (vente / achat) : recharge la fiche pour afficher le nouveau prix et l'historique
    document.addEventListener('submit', function (e) {
        var form = e.target.closest('.ispag-std-price-form');
        if (!form) return;
        e.preventDefault();
        var data = { id: form.dataset.id };
        new FormData(form).forEach(function (v, k) { data[k] = v; });
        post(form.dataset.kind === 'purchase' ? 'save_purchase_price' : 'save_sales_price', data)
            .then(function () { location.reload(); })
            .catch(function (err) { say(form, err.message, true); });
    });

    // Ajout d'un fournisseur
    var add = document.getElementById('ispag-std-add-purchase');
    if (add) {
        add.addEventListener('submit', function (e) {
            e.preventDefault();
            var data = { article_id: add.dataset.articleId };
            new FormData(add).forEach(function (v, k) { data[k] = v; });
            post('add_purchase', data)
                .then(function () { location.reload(); })
                .catch(function (err) { say(add, err.message, true); });
        });
    }

    document.addEventListener('click', function (e) {
        // Retirer un fournisseur
        var del = e.target.closest('.ispag-std-del-purchase');
        if (del) {
            if (!confirm(ispagStd.confirm_delete_purchase)) return;
            post('delete_purchase', { id: del.dataset.id }).then(function () { location.reload(); })
                .catch(function (err) { say(del, err.message, true); });
            return;
        }
        // Supprimer l'article
        var delA = e.target.closest('#ispag-std-delete');
        if (delA && !delA.disabled) {
            if (!confirm(ispagStd.confirm_delete_article)) return;
            post('delete', { id: delA.dataset.id }).then(function (d) { window.location.href = d.url; })
                .catch(function (err) { say(delA, err.message, true); });
            return;
        }
        // Historique des prix (afficher / masquer)
        var h = e.target.closest('.ispag-std-history-btn');
        if (h) {
            var box = h.parentElement.querySelector('.ispag-std-history-box') || h.closest('.ispag-std-supplier, .ispag-card').querySelector('.ispag-std-history-box');
            if (!box.hidden) { box.hidden = true; return; }
            post('history', { kind: h.dataset.kind, id: h.dataset.id })
                .then(function (d) { box.innerHTML = d.html; box.hidden = false; })
                .catch(function (err) { say(h, err.message, true); });
        }
    });

    // ---- Médiathèque WordPress : image de l'article et documents -----------------------------------
    var frames = {};
    function openMedia(key, options, onSelect) {
        if (!ispagStd.can_media || !window.wp || !wp.media) { alert(ispagStd.error); return; }
        if (!frames[key]) {
            frames[key] = wp.media({ title: options.title, button: { text: ispagStd.media_select }, multiple: !!options.multiple, library: options.library || {} });
            frames[key].on('select', function () {
                var sel = frames[key].state().get('selection');
                onSelect(sel.map(function (a) { return a.toJSON(); }));
            });
        }
        frames[key].open();
    }

    document.addEventListener('click', function (e) {
        var pick = e.target.closest('.ispag-std-pick-image');
        if (pick) {
            openMedia('image', { title: ispagStd.media_image_title, library: { type: 'image' } }, function (items) {
                if (!items.length) return;
                post('save_sales_field', { id: pick.dataset.id, field: 'image', value: items[0].id })
                    .then(function () { location.reload(); })
                    .catch(function (err) { say(pick, err.message, true); });
            });
            return;
        }
        var clear = e.target.closest('.ispag-std-clear-image');
        if (clear) {
            post('save_sales_field', { id: clear.dataset.id, field: 'image', value: 0 })
                .then(function () { location.reload(); })
                .catch(function (err) { say(clear, err.message, true); });
            return;
        }
        var addDocs = e.target.closest('.ispag-std-add-docs');
        if (addDocs && !addDocs.disabled) {
            openMedia('docs', { title: ispagStd.media_docs_title, multiple: true }, function (items) {
                if (!items.length) return;
                var body = new URLSearchParams();
                body.append('action', 'ispag_std_add_documents');
                body.append('nonce', ispagStd.nonce);
                body.append('id', addDocs.dataset.id);
                items.forEach(function (it) { body.append('attachment_ids[]', it.id); });
                fetch(ispagStd.ajax_url, { method: 'POST', credentials: 'same-origin', body: body })
                    .then(function (r) { return r.json(); })
                    .then(function (res) {
                        if (!res.success) throw new Error((res.data && res.data.message) || ispagStd.error);
                        location.hash = 'documents'; location.reload();
                    })
                    .catch(function (err) { say(addDocs, err.message, true); });
            });
            return;
        }
        var rm = e.target.closest('.ispag-std-remove-doc');
        if (rm) {
            if (!confirm(ispagStd.confirm_remove_document)) return;
            post('remove_document', { id: rm.dataset.id, attachment_id: rm.dataset.attachment })
                .then(function () { location.hash = 'documents'; location.reload(); })
                .catch(function (err) { say(rm, err.message, true); });
        }
    });
})();
