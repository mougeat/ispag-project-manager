/**
 * GESTION DE LA VISIBILITÉ DES PRIX - VERSION COOKIE (1H)
 * Sans modales/alerts, avec boutons de bascule manuelle.
 */

const locations = {
    maison:          { lat: 46.33817, lng: 6.64759, radius: 5.0 },
    issa:            { lat: 46.61920, lng: 6.97957, radius: 0.25 },
    werner:          { lat: 46.21883, lng: 6.06390, radius: 0.25 },
    Lambda_lausanne: { lat: 46.52242, lng: 6.61694, radius: 0.1 },
    Lambda_sion:     { lat: 46.22387, lng: 6.35675, radius: 0.5 },
    claudio:         { lat: 46.65684, lng: 6.60274, radius: 5.0 },
    claudio2:        { lat: 46.941100, lng: 7.385600, radius: 2.0 },
    localisation_ip_depuis_lambda: { lat: 47.2098, lng: 7.4889, radius: 0.25 }
};

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
 * Calcul de distance (Haversine)
 */
function calculateDistance(lat1, lon1, lat2, lon2) {
    const R = 6371;
    const dLat = (lat2 - lat1) * Math.PI / 180;
    const dLon = (lon2 - lon1) * Math.PI / 180;
    const a = Math.sin(dLat / 2) * Math.sin(dLat / 2) +
              Math.cos(lat1 * Math.PI / 180) * Math.cos(lat2 * Math.PI / 180) *
              Math.sin(dLon / 2) * Math.sin(dLon / 2);
    const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
    return R * c;
}

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

    // ── Pas de cookie → on attend le résultat GPS/IP ─────────────────────────
    if (window.ispagSafeZoneStatus === null) return;

    // ── Zone de confiance détectée → on affiche et on pose le cookie ─────
    if (window.ispagSafeZoneStatus === true) {
        console.log("[GEO] Zone de confiance OK. Création du cookie 1h.");
        IspagCookie.set('ispag_allow_prices', 'true', 60);
        elements.forEach(el => el.classList.add('prices-visible'));
        updateButtonVisibility();
        return;
    }

    // ── Hors zone et pas de cookie → on cache par défaut (plus de confirm)
    console.log("[GEO] Hors zone → Prix masqués par défaut.");
    IspagCookie.set('ispag_allow_prices', 'false', 60);
    elements.forEach(el => el.classList.remove('prices-visible'));
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
            console.log("[GEO] Prix forcés à visible (cookie = true)");
        });
    }

    if (forceHideBtn) {
        forceHideBtn.addEventListener('click', () => {
            IspagCookie.set('ispag_allow_prices', 'false', 60);
            window.ispagSafeZoneStatus = false;
            applyPriceVisibility();
            console.log("[GEO] Prix forcés à masqué (cookie = false)");
        });
    }

    // Met à jour la visibilité des boutons au chargement
    updateButtonVisibility();
}

/**
 * Point d'entrée
 */
function checkLocation() {
    console.groupCollapsed('🔧 ISPAG : checkLocation');

    // Cas 1 : Cookie déjà présent
    if (IspagCookie.get('ispag_allow_prices') === 'true') {
        console.log("✅ Accès déjà autorisé par cookie.");
        window.ispagSafeZoneStatus = true;
        applyPriceVisibility();
        console.groupEnd();
        return;
    }

    // Cas 2 : Pas de support Géoloc
    if (!navigator.geolocation) {
        console.log("⚠️ Géoloc non supportée, passage par IP.");
        checkLocationByIP();
        console.groupEnd();
        return;
    }

    // Cas 3 : Géoloc supportée (Processus asynchrone)
    navigator.geolocation.getCurrentPosition(
        (position) => {
            processCoordinates(position.coords.latitude, position.coords.longitude, "GPS");
            console.groupEnd();
        },
        (error) => {
            console.log("❌ Error GPS, repli sur IP.");
            checkLocationByIP();
            console.groupEnd();
        },
        { enableHighAccuracy: true, timeout: 5000 }
    );
}

async function checkLocationByIP() {
    try {
        const response = await fetch('https://ipapi.co/json/');
        const data = await response.json();
        if (data && !data.error) {
            processCoordinates(data.latitude, data.longitude, "IP");
        } else {
            throw new Error("Error IP API");
        }
    } catch (err) {
        console.log("[GEO] Error IP API → Prix masqués par défaut.");
        window.ispagSafeZoneStatus = false;
        applyPriceVisibility();
    }
}

function processCoordinates(uLat, uLng, source) {
    console.log(`[GEO][${source}] Position détectée : lat=${uLat.toFixed(6)}, lng=${uLng.toFixed(6)}`);
    console.log(`[GEO][${source}] Google Maps : https://maps.google.com/?q=${uLat},${uLng}`);

    let foundZone = false;
    for (const [name, loc] of Object.entries(locations)) {
        const dist = calculateDistance(uLat, uLng, loc.lat, loc.lng);
        console.log(`[GEO] → Zone "${name}" : ${dist.toFixed(3)} km (rayon ${loc.radius} km) ${dist <= loc.radius ? '✅' : '❌'}`);
        if (dist <= loc.radius) {
            foundZone = true;
            console.log(`[GEO] ✅ Zone de confiance trouvée : "${name}"`);
            break;
        }
    }

    if (!foundZone) console.log('[GEO] ❌ Aucune zone de confiance trouvée.');

    window.ispagSafeZoneStatus = foundZone;
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