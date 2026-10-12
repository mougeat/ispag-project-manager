/**
 * Boîtes de dialogue ISPAG, partagées par tous les plugins : remplacent window.confirm() et window.alert().
 *
 *  - ispagConfirm(message, options) : Promise<true|false|'alt'>. Le message est du HTML, sauf avec { text: true }.
 *    Options : labelOk, labelCancel, labelAlt (3e bouton, résout 'alt'), danger, text, hideCancel.
 *  - ispagAlert(message, options)   : information avec un seul bouton ; le message est du texte (retours à la ligne conservés).
 *  - Attribut data-ispag-confirm="message" sur un lien, un bouton ou un formulaire : confirmation avant d'agir
 *    (remplace onclick="return confirm(…)" / onsubmit="return confirm(…)").
 * Le style est injecté ici : la boîte fonctionne aussi dans l'administration et sur les pages sans la feuille de style du plugin.
 */
(function () {
    'use strict';
    if (window.ispagConfirm && window.ispagConfirm.__shared) return;

    function injectStyle() {
        if (document.getElementById('ispag-dialogs-style')) return;
        var st = document.createElement('style');
        st.id = 'ispag-dialogs-style';
        st.textContent =
            '.ispag-confirm-overlay{position:fixed;inset:0;background:rgba(0,0,0,.7);z-index:9999999;display:flex;align-items:center;justify-content:center}' +
            '.ispag-confirm-box{background:#fff;border-radius:10px;padding:28px 32px;max-width:420px;width:90%;box-shadow:0 8px 32px rgba(0,0,0,.18);text-align:center;box-sizing:border-box}' +
            '.ispag-confirm-box p{font-size:15px;color:#333;margin:0 0 24px;line-height:1.5;word-wrap:break-word}' +
            '.ispag-confirm-actions{display:flex;gap:12px;justify-content:center;flex-wrap:wrap}' +
            '.ispag-confirm-actions button{cursor:pointer;font:inherit;font-size:14px;padding:8px 18px;border-radius:6px;border:1px solid #b32d2e;background:#fff;color:#b32d2e}' +
            '.ispag-confirm-actions button.ispag-dlg-grey{border-color:#bbb;color:#444}' +
            '.ispag-confirm-actions button.ispag-dlg-danger{background:#b32d2e;color:#fff}';
        (document.head || document.documentElement).appendChild(st);
    }

    function esc(t) {
        return String(t).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    function texts() { return (window.ispag_texts) || {}; }

    function ispagConfirm(message, options) {
        options = options || {};
        injectStyle();
        return new Promise(function (resolve) {
            var labelOk = options.labelOk || texts()['continue'] || 'Continuer';
            var labelCancel = options.labelCancel || texts().cancel || 'Annuler';
            var labelAlt = options.labelAlt || '';
            var danger = !!options.danger;
            var body = options.text ? esc(message).replace(/\n/g, '<br>') : message;

            var overlay = document.createElement('div');
            overlay.className = 'ispag-confirm-overlay';
            overlay.innerHTML =
                '<div class="ispag-confirm-box" role="dialog" aria-modal="true"><p>' + body + '</p>' +
                '<div class="ispag-confirm-actions">' +
                (options.hideCancel ? '' : '<button type="button" class="ispag-btn ispag-btn-grey-outlined ispag-dlg-grey js-confirm-cancel">' + labelCancel + '</button>') +
                (labelAlt ? '<button type="button" class="ispag-btn ispag-btn-grey-outlined ispag-dlg-grey js-confirm-alt">' + labelAlt + '</button>' : '') +
                '<button type="button" class="ispag-btn ' + (danger ? 'ispag-btn-danger ispag-dlg-danger' : 'ispag-btn-danger-outlined') + ' js-confirm-ok">' + labelOk + '</button>' +
                '</div></div>';

            var resolved = false;
            function close(result) {
                if (resolved) return;
                resolved = true;
                document.removeEventListener('keydown', onKey, true);
                overlay.remove();
                resolve(result);
            }
            function onKey(e) {
                if (e.key === 'Escape') { e.stopPropagation(); close(false); }
            }
            overlay.querySelector('.js-confirm-ok').addEventListener('click', function () { close(true); });
            var cancelBtn = overlay.querySelector('.js-confirm-cancel');
            if (cancelBtn) cancelBtn.addEventListener('click', function () { close(false); });
            var altBtn = overlay.querySelector('.js-confirm-alt');
            if (altBtn) altBtn.addEventListener('click', function () { close('alt'); });
            overlay.addEventListener('click', function (e) { if (e.target === overlay) close(false); });
            document.addEventListener('keydown', onKey, true);

            document.body.appendChild(overlay);
            (cancelBtn || overlay.querySelector('.js-confirm-ok')).focus();
        });
    }
    ispagConfirm.__shared = true;

    /** Information : un seul bouton. Le message est du texte brut. */
    function ispagAlert(message, options) {
        options = options || {};
        return ispagConfirm(message == null ? '' : String(message), {
            labelOk: options.labelOk || 'OK',
            hideCancel: true,
            text: options.html ? false : true,
            danger: false
        }).then(function () { return true; });
    }

    /**
     * Garde de confirmation pour un gestionnaire de clic : remplace `if (!confirm(m)) return;`
     * par `if (!ispagConfirmGate(this, m)) return;`. Premier passage : affiche la boîte et renvoie false ;
     * si l'utilisateur confirme, l'élément est cliqué de nouveau et le gestionnaire repasse avec true.
     */
    function ispagConfirmGate(el, message, options) {
        if (el.__ispagConfirmed) { el.__ispagConfirmed = false; return true; }
        var opts = { text: true, danger: true };
        for (var k in (options || {})) opts[k] = options[k];
        ispagConfirm(message, opts).then(function (ok) {
            if (!ok) return;
            el.__ispagConfirmed = true;
            el.click();
            setTimeout(function () { el.__ispagConfirmed = false; }, 0);
        });
        return false;
    }

    window.ispagConfirm = ispagConfirm;
    window.ispagAlert = ispagAlert;
    window.ispagConfirmGate = ispagConfirmGate;

    // data-ispag-confirm="message" : confirmation avant de suivre un lien, d'envoyer un formulaire ou d'activer un bouton.
    var bypass = false;
    document.addEventListener('click', function (e) {
        if (bypass) return;
        var el = e.target.closest ? e.target.closest('[data-ispag-confirm]') : null;
        if (!el || el.tagName === 'FORM') return;
        e.preventDefault();
        e.stopImmediatePropagation();
        ispagConfirm(el.getAttribute('data-ispag-confirm'), { text: true, danger: true }).then(function (ok) {
            if (!ok) return;
            bypass = true;
            try { el.click(); } finally { bypass = false; }
        });
    }, true);
    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (bypass || !form || !form.getAttribute || !form.hasAttribute('data-ispag-confirm')) return;
        e.preventDefault();
        e.stopImmediatePropagation();
        var sub = e.submitter;
        ispagConfirm(form.getAttribute('data-ispag-confirm'), { text: true, danger: true }).then(function (ok) {
            if (!ok) return;
            bypass = true;
            try { if (form.requestSubmit) { form.requestSubmit(sub || undefined); } else { form.submit(); } } finally { bypass = false; }
        });
    }, true);
})();
