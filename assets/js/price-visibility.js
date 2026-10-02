/**
 * GESTION DE LA VISIBILITÉ DES PRIX - VERSION COOKIE (1H)
 * Sans modales/alerts, avec boutons de bascule manuelle. Aucune géolocalisation.
 */

window.ispagSafeZoneStatus = null;

/**
 * Utilitaires Cookies
 */
const IspagCookie = {
    set: function(name, value, minutes) {
        let expires = "";
        if (minutes) {
            const date = new Date();
            date.setTime(date.getTime() + (minutes * 60 * 1000));
            expires = "; expires=" + date.toUTCString();
        }
        document.cookie = name + "=" + (value || "") + expires + "; path=/";
    },
    get: function(name) {
        const nameEQ = name + "=";
        const ca = document.cookie.split(';');
        for(let i = 0; i < ca.length; i++) {
            let c = ca[i];
            while (c.charAt(0) === ' ') c = c.substring(1, c.length);
            if (c.indexOf(nameEQ) === 0) return c.substring(nameEQ.length, c.length);
        }
        return null;
    }
};

/**
 * Met à jour la visibilité des boutons en fonction de l'état des prix
 */
function updateButtonVisibility() {
    const isVisible = document.querySelector('.fields-prices.prices-visible') !== null;
    const forceShowBtn = document.getElementById('ispag-force-show-prices');
    const forceHideBtn = document.getElementById('ispag-force-hide-prices');

    if (forceShowBtn && forceHideBtn) {
        forceShowBtn.style.display = isVisible ? 'none' : 'inline-block';
        forceHideBtn.style.display = isVisible ? 'inline-block' : 'none';
    }
}

/**
 * Applique la visibilité des prix
 */
window.applyPriceVisibility = function() {
    const elements = document.querySelectorAll('.fields-prices');
    if (elements.length === 0) return;

    const cookieValue = IspagCookie.get('ispag_allow_prices');

    // ── Cookie "je refuse" → on cache ──────────────────────
    if (cookieValue === 'false') {
        elements.forEach(el => el.classList.remove('prices-visible'));
        updateButtonVisibility();
        return;
    }

    // ── Cookie "j'autorise" → on affiche ─────────────────────
    if (cookieValue === 'true') {
        elements.forEach(el => el.classList.add('prices-visible'));
        updateButtonVisibility();
        return;
    }

    // ── Pas de cookie → prix affichés par défaut (masqués seulement après un clic sur « masquer les prix »)
    elements.forEach(el => el.classList.add('prices-visible'));
    updateButtonVisibility();
};

/**
 * Gestion des boutons de bascule manuelle des prix
 */
function setupPriceToggleButtons() {
    const forceShowBtn = document.getElementById('ispag-force-show-prices');
    const forceHideBtn = document.getElementById('ispag-force-hide-prices');

    if (forceShowBtn) {
        forceShowBtn.addEventListener('click', () => {
            IspagCookie.set('ispag_allow_prices', 'true', 60);
            window.ispagSafeZoneStatus = true;
            applyPriceVisibility();
            console.log("Prix forcés à visible (cookie = true)");
        });
    }

    if (forceHideBtn) {
        forceHideBtn.addEventListener('click', () => {
            IspagCookie.set('ispag_allow_prices', 'false', 60);
            window.ispagSafeZoneStatus = false;
            applyPriceVisibility();
            console.log("Prix forcés à masqué (cookie = false)");
        });
    }

    // Met à jour la visibilité des boutons au chargement
    updateButtonVisibility();
}

/**
 * Point d'entrée : plus de géolocalisation (ni GPS, ni IP).
 * Prix visibles par défaut ; ils ne sont masqués que si l'utilisateur clique sur « masquer » (cookie « false », 1 h).
 */
function checkLocation() {
    window.ispagSafeZoneStatus = (IspagCookie.get('ispag_allow_prices') !== 'false');
    applyPriceVisibility();
}

// Initialisation
document.addEventListener('DOMContentLoaded', () => {
    checkLocation();
    setupPriceToggleButtons();
    updateButtonVisibility();
});

document.addEventListener('modal_loaded', () => {
    applyPriceVisibility();
    setupPriceToggleButtons();
    updateButtonVisibility();
});

document.addEventListener('articles_loaded', applyPriceVisibility);