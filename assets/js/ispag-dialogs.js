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


    /**
     * Loader ISPAG (pastille animée + points + lignes squelette) : à utiliser à la place d'un texte « Chargement... ».
     *   ispagLoader('Analyse IA')                    -> HTML de la carte complète (à mettre dans un conteneur : el.innerHTML = ispagLoader(...))
     *   ispagLoader('Chargement', { compact: true }) -> version en ligne, sans carte ni lignes (listes, fenêtres, menus)
     * Le style est injecté ici ; le thème en a la même copie pour les gabarits PHP (voir ispag_loader_html() et templates/ai-loader.php).
     */
    function injectLoaderStyle() {
        if (document.getElementById('ispag-loader-style')) return;
        var st = document.createElement('style');
        st.id = 'ispag-loader-style';
        st.textContent =
            '.ispag-ai-loader-v2{--ispag-lp:var(--ispag-color-primary,#b32d2e);display:flex;align-items:center;gap:16px;padding:16px 18px;border:1px solid var(--ispag-border,#e5e7eb);border-radius:12px;background:linear-gradient(135deg,#fff 0%,#faf7f7 100%);color:var(--ispag-lp)}' +
            '.ispag-ai-orb{position:relative;flex:0 0 auto;width:46px;height:46px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;background:conic-gradient(from 0deg,var(--ispag-lp),rgba(179,45,46,.08) 40%,var(--ispag-lp));animation:ispag-ai-orb-spin 2.4s linear infinite;box-shadow:0 0 0 4px rgba(179,45,46,.07)}' +
            '.ispag-ai-orb::before{content:"";position:absolute;inset:3px;border-radius:50%;background:#fff}' +
            '.ispag-ai-orb svg{position:relative;z-index:1;animation:ispag-ai-orb-counter 2.4s linear infinite}' +
            '.ispag-ai-orb svg .s1{transform-origin:11px 9px;animation:ispag-ai-twinkle 1.6s ease-in-out infinite}' +
            '.ispag-ai-orb svg .s2{transform-origin:18.5px 16px;animation:ispag-ai-twinkle 1.6s ease-in-out .4s infinite}' +
            '.ispag-ai-orb svg .s3{transform-origin:5.5px 18px;animation:ispag-ai-twinkle 1.6s ease-in-out .8s infinite}' +
            '.ispag-ai-loader-body{flex:1 1 auto;min-width:0;display:flex;flex-direction:column;gap:10px}' +
            '.ispag-ai-loader-v2 .loader-text{font-size:13px;font-weight:600;letter-spacing:.3px;color:var(--ispag-text-primary,#333)}' +
            '.ispag-ai-dots{display:inline-flex;gap:3px;margin-left:4px;vertical-align:middle}' +
            '.ispag-ai-dots i{width:4px;height:4px;border-radius:50%;background:var(--ispag-lp);animation:ispag-ai-dot 1.2s ease-in-out infinite}' +
            '.ispag-ai-dots i:nth-child(2){animation-delay:.2s}.ispag-ai-dots i:nth-child(3){animation-delay:.4s}' +
            '.ispag-ai-lines{display:flex;flex-direction:column;gap:7px}' +
            '.ispag-ai-lines i{display:block;height:8px;border-radius:4px;background:linear-gradient(90deg,#eceef1 25%,#f8f9fb 45%,#eceef1 65%);background-size:220% 100%;animation:ispag-ai-shimmer 1.6s ease-in-out infinite}' +
            '.ispag-ai-lines i:nth-child(1){width:92%}.ispag-ai-lines i:nth-child(2){width:78%;animation-delay:.15s}.ispag-ai-lines i:nth-child(3){width:56%;animation-delay:.3s}' +
            /* version compacte : en ligne, sans carte ni lignes */
            '.ispag-ai-loader-v2.is-compact{display:inline-flex;gap:10px;padding:6px 2px;border:0;background:none;border-radius:0}' +
            '.is-compact .ispag-ai-orb{width:26px;height:26px;box-shadow:none}.is-compact .ispag-ai-orb::before{inset:2px}.is-compact .ispag-ai-orb svg{width:14px;height:14px}' +
            '.is-compact .ispag-ai-lines{display:none}.is-compact .ispag-ai-loader-body{gap:0}' +
            '@keyframes ispag-ai-orb-spin{to{transform:rotate(360deg)}}@keyframes ispag-ai-orb-counter{to{transform:rotate(-360deg)}}' +
            '@keyframes ispag-ai-twinkle{0%,100%{transform:scale(.55);opacity:.45}50%{transform:scale(1);opacity:1}}' +
            '@keyframes ispag-ai-dot{0%,80%,100%{opacity:.25;transform:translateY(0)}40%{opacity:1;transform:translateY(-3px)}}' +
            '@keyframes ispag-ai-shimmer{0%{background-position:120% 0}100%{background-position:-120% 0}}' +
            '@media (prefers-reduced-motion:reduce){.ispag-ai-orb,.ispag-ai-orb svg,.ispag-ai-orb svg path,.ispag-ai-dots i,.ispag-ai-lines i{animation:none!important}.ispag-ai-dots i{opacity:.7}}';
        (document.head || document.documentElement).appendChild(st);
    }
    function ispagLoader(label, options) {
        options = options || {};
        injectLoaderStyle();
        var text = esc(String(label == null ? '' : label).replace(/\s*(\.{2,}|…)\s*$/, ''));
        return '<div class="ispag-ai-loader-v2' + (options.compact ? ' is-compact' : '') + '" role="status" aria-live="polite">' +
            '<span class="ispag-ai-orb" aria-hidden="true"><svg viewBox="0 0 24 24" width="22" height="22" fill="currentColor"><path class="s1" d="M11 2.5l1.7 4.6 4.6 1.7-4.6 1.7L11 15.1l-1.7-4.6-4.6-1.7 4.6-1.7z"/><path class="s2" d="M18.5 13l.9 2.4 2.4.9-2.4.9-.9 2.4-.9-2.4-2.4-.9 2.4-.9z"/><path class="s3" d="M5.5 15.5l.6 1.6 1.6.6-1.6.6-.6 1.6-.6-1.6-1.6-.6 1.6-.6z"/></svg></span>' +
            '<span class="ispag-ai-loader-body"><span class="loader-text">' + text + '<span class="ispag-ai-dots" aria-hidden="true"><i></i><i></i><i></i></span></span>' +
            '<span class="ispag-ai-lines" aria-hidden="true"><i></i><i></i><i></i></span></span></div>';
    }
    // La feuille de style est injectée dès le chargement : le même loader fonctionne aussi quand il est écrit côté serveur (ispag_loader_html)
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', injectLoaderStyle); else injectLoaderStyle();
    window.ispagConfirm = ispagConfirm;
    window.ispagAlert = ispagAlert;
    window.ispagConfirmGate = ispagConfirmGate;
    window.ispagLoader = ispagLoader;

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
