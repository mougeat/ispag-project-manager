/**
 * ISPAG : confort mobile / tablette (sans effet sur PC).
 *  - tableaux : pose data-label (en-tête de colonne) sur chaque cellule, classe ispag-cards-mobile (cartes sur mobile) ;
 *  - fiches projet / achat : les actions du résumé sont repliables sur mobile ;
 *  - onglets : l'onglet actif est ramené dans la zone visible.
 */
(function () {
    'use strict';
    var mq = window.matchMedia ? window.matchMedia('(max-width: 640px)') : { matches: false };

    // 1. Tableaux en cartes sur mobile
    var TABLES = 'table.ispag-company-list-table, table.ispag-contact-list-table, table.ispag-task-table, table.widefat, table.wp-list-table, table.ispag-table';
    function labelTable(t) {
        if (t.dataset.ispagLabelled === '1') return;
        var heads = [];
        t.querySelectorAll('thead tr:last-child th, thead tr:last-child td').forEach(function (th) {
            var txt = (th.textContent || '').replace(/\s+/g, ' ').trim();
            var span = parseInt(th.getAttribute('colspan') || '1', 10);
            for (var i = 0; i < span; i++) heads.push(txt);
        });
        if (!heads.length) return;
        t.querySelectorAll('tbody tr').forEach(function (tr) {
            var i = 0;
            Array.prototype.forEach.call(tr.children, function (td) {
                var span = parseInt(td.getAttribute('colspan') || '1', 10);
                if (!td.hasAttribute('data-label')) td.setAttribute('data-label', heads[i] || '');
                i += span;
            });
        });
        t.dataset.ispagLabelled = '1';
    }
    function cardify() {
        document.querySelectorAll(TABLES).forEach(function (t) {
            // Tableaux ayant déjà leur propre mise en cartes (listes projets / achats) : on n'y touche pas
            if (t.classList.contains('ispag-project-table')) return;
            labelTable(t);
            t.classList.add('ispag-cards-mobile');
        });
    }

    // 2. Actions repliables (fiche projet / achat)
    function collapsibleActions() {
        document.querySelectorAll('.ispag-project-btn-card').forEach(function (card) {
            if (card.dataset.ispagCollapsible === '1') return;
            card.dataset.ispagCollapsible = '1';
            card.classList.add('ispag-actions-collapsible');
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'ispag-actions-toggle';
            btn.setAttribute('aria-expanded', 'false');
            btn.textContent = (window.ispag_texts && window.ispag_texts.actions) || 'Actions';
            btn.addEventListener('click', function () {
                var open = !card.classList.contains('is-open');
                card.classList.toggle('is-open', open);
                btn.setAttribute('aria-expanded', open ? 'true' : 'false');
            });
            card.parentNode.insertBefore(btn, card);
        });
    }

    // 3. Onglet actif visible
    document.addEventListener('click', function (e) {
        var tab = e.target.closest && e.target.closest('.ispag-tab-btn, .tab-titles li');
        if (tab && tab.scrollIntoView) {
            setTimeout(function () { tab.scrollIntoView({ inline: 'center', block: 'nearest', behavior: 'smooth' }); }, 30);
        }
    });

    function init() { cardify(); collapsibleActions(); }
    if (document.readyState !== 'loading') init(); else document.addEventListener('DOMContentLoaded', init);
    // Contenus injectés après coup (listes chargées en AJAX)
    if (window.MutationObserver) {
        var timer = null;
        new MutationObserver(function () { clearTimeout(timer); timer = setTimeout(init, 120); }).observe(document.body, { childList: true, subtree: true });
    }
})();
