/**
 * Cycle de vie des onglets des fiches (projet, offre, entreprise, contact…).
 *
 * Annonce, sur document (événements jQuery) :
 *   ispag:tab-left  [onglet]  quand on quitte un onglet : son contenu vient d'être masqué, c'est le bon moment pour le rafraîchir en arrière-plan ;
 *   ispag:tab-shown [onglet]  quand un onglet est affiché.
 * Règle suivie par les onglets chargés par AJAX : chargement une fois au chargement de la page (avec un squelette), jamais de rechargement
 * pendant que le contenu est affiché ; le rechargement se fait à l'instant où l'onglet est quitté, pour que le contenu soit déjà à jour
 * à la prochaine ouverture.
 */
(function () {
    'use strict';
    if (window.__ispagTabLifecycle) return;
    window.__ispagTabLifecycle = true;

    function activeTab() {
        var b = document.querySelector('.ispag-tabs-navigation .ispag-tab-btn.active');
        return b ? b.getAttribute('data-tab') : null;
    }

    // Phase de capture : lue avant que les autres gestionnaires ne changent l'onglet actif
    document.addEventListener('click', function (e) {
        var btn = e.target.closest && e.target.closest('.ispag-tabs-navigation .ispag-tab-btn');
        if (!btn) return;
        var next = btn.getAttribute('data-tab'), prev = activeTab();
        if (!next || next === prev) return;
        // Après le changement d'onglet (l'ancien est alors masqué)
        setTimeout(function () {
            if (!window.jQuery) return;
            if (prev) window.jQuery(document).trigger('ispag:tab-left', [prev]);
            window.jQuery(document).trigger('ispag:tab-shown', [next]);
        }, 0);
    }, true);
})();
