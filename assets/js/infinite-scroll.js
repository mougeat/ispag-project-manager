document.addEventListener('DOMContentLoaded', function () {
    let offsetProjects = 0;
    const limit = 20;
    let loading = false;
    let hasMore = true;
    let currentRequest = null;   // AbortController de la requête en cours
    let requestId = 0;           // numéro de la dernière requête lancée : les réponses plus anciennes sont ignorées

    const loader = document.getElementById('scroll-loader');
    const listContainer = document.getElementById('projets-list');
    const searchInput = document.getElementById('ispag-projects-search');
    const creatorSelect = document.getElementById('ispag-projects-creator-filter');
    const activeSwitch = document.getElementById('ispag-status-active');
    const closedSwitch = document.getElementById('ispag-status-closed');
    // Tant que les interrupteurs ne sont pas touchés : « auto » (actifs ; tous pendant une recherche). Ensuite : le choix explicite.
    let statusTouched = !!(document.getElementById('projets-meta') && ['active', 'closed', 'all'].indexOf(document.getElementById('projets-meta').dataset.status) !== -1);
    function statusValue() {
        if (!activeSwitch || !closedSwitch || !statusTouched) return 'auto';
        if (activeSwitch.checked && closedSwitch.checked) return 'all';
        return closedSwitch.checked ? 'closed' : 'active';
    }

    if (!loader || !listContainer) return;

    // Loader ISPAG (version en ligne) sous la liste ; repli sur l'ancien texte si ispag-dialogs.js n'est pas à jour
    function loaderHtml() { return window.ispagLoader ? '<div style="text-align:center;">' + ispagLoader(ispagVars.loading_text, { compact: true }) + '</div>' : '<div class="loading-spinner" style="text-align:center;">' + ispagVars.loading_text + '</div>'; }
    // Ligne de tableau portant le loader, pendant une nouvelle recherche
    function loaderRowHtml() {
        const table = listContainer.closest ? listContainer.closest('table') : null;
        const cols = table ? table.querySelectorAll('thead th').length : 1;
        return '<tr class="ispag-loader-row"><td colspan="' + Math.max(1, cols) + '" style="text-align:center;padding:26px 10px;">' + (window.ispagLoader ? ispagLoader(ispagVars.loading_text, { compact: true }) : '') + '</td></tr>';
    }
    loader.innerHTML = loaderHtml();

    function loadProjects(reset = false) {

        if (reset) {
            hasMore = true;
            // Une nouvelle recherche remplace celle en cours (sinon, taper pendant un chargement perdait la dernière frappe)
            if (currentRequest) currentRequest.abort();
            loading = false;
        }

        if (loading || !hasMore) return;
        loading = true;
        const myId = ++requestId;
        currentRequest = new AbortController();

        const search = searchInput ? searchInput.value : '';
        const creator = creatorSelect ? creatorSelect.value : 'all';
        const meta = document.getElementById('projets-meta');
        const contact_id = meta ? meta.dataset.contactid : '0';
        const qotation = meta ? meta.dataset.qotation : '0';
        const only_activ = meta ? meta.dataset.onlyactiv : '0';
        const select_state = meta ? meta.dataset.select_state : '';
        const ingenieurId = new URLSearchParams(window.location.search).get('ingenieur_id') || '';

        if (reset) {
            const url = new URL(window.location.href);
            if (search !== '') {
                url.searchParams.set('search', search);
            } else {
                url.searchParams.delete('search'); // Nettoie l'URL si le champ est vidé
            }
            if (statusTouched) {
                url.searchParams.set('status', statusValue());
            } else {
                url.searchParams.delete('status');
            }
            // Modifie la barre d'adresse sans recharger
            window.history.replaceState({}, '', url);
            
            offsetProjects = 0;
            if (window.ispagLoader && listContainer.tagName === 'TBODY') listContainer.innerHTML = loaderRowHtml();   // nouvelle recherche : le loader remplace la liste le temps du chargement
            // listContainer.innerHTML = '';
            // Réinitialiser le texte/loader si nécessaire
            // loader.innerHTML = '<div class="loading-spinner" style="text-align:center;"><span class="dashicons dashicons-update" style="animation: spin 2s linear infinite;"></span> ' + ispagVars.loading_text + '</div>';
        }

        const formData = new FormData();
        formData.append('action', 'ispag_load_more_projects');
        formData.append('offset', offsetProjects);
        formData.append('limit', limit);
        formData.append('contact_id', contact_id);
        formData.append('qotation', qotation);
        formData.append('only_activ', only_activ);
        formData.append('search', search);
        formData.append('select_state', select_state);
        formData.append('ingenieur_id', ingenieurId);
        formData.append('filter_creator', creator);
        formData.append('status', statusValue());
        formData.append('nonce', ispagVars.nonce);

        fetch(ajaxurl, {
            method: 'POST',
            credentials: 'same-origin',
            body: formData,
            signal: currentRequest.signal
        })
        .then(response => response.json())
        .then(data => {
            if (myId !== requestId) return;   // réponse d'une recherche déjà remplacée
            if (data.success) {
                if (reset) {
                    // listContainer.replaceWith(data.data.html)
                    listContainer.innerHTML = data.data.html;
                    
                } else {
                    listContainer.insertAdjacentHTML('beforeend', data.data.html);
                }
                offsetProjects += limit;
                hasMore = data.data.has_more;
                if (!hasMore) {
                    loader.innerHTML = '<p style="text-align:center; color:#777;">' + ispagVars.all_loaded_text + '.</p>';
                }
            }
        })
        .catch(error => {
            if (error && error.name === 'AbortError') return;
            console.error('Error AJAX:', error);
        })
        .finally(() => {
            if (myId === requestId) loading = false;
        });
    }
    window.loadProjects = loadProjects;   // le bouton « Filtrer / Rechercher » l'appelle depuis le HTML (onclick)

    // Écouteurs d'événements pour les filtres
    if (searchInput) {
        let debounceTimer;
        searchInput.addEventListener('input', function() {
            clearTimeout(debounceTimer);
            debounceTimer = setTimeout(() => {
                loadProjects(true);
            }, 500);
        });
        // La recherche se fait en tapant (sans recharger la page) : Entrée ne doit ni envoyer un formulaire ni recharger la page ;
        // elle lance seulement la recherche tout de suite, sans attendre la fin du délai de frappe.
        searchInput.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                clearTimeout(debounceTimer);
                loadProjects(true);
            }
        });
    }

    if (creatorSelect) {
        creatorSelect.addEventListener('change', function() {
            loadProjects(true);
        });
    }

    // Interrupteurs Actifs / Clos : au moins un reste allumé (éteindre le dernier allume l'autre) ; la liste se recharge sans recharger la page
    [activeSwitch, closedSwitch].forEach(function (sw) {
        if (!sw) return;
        sw.addEventListener('change', function () {
            statusTouched = true;
            if (!activeSwitch.checked && !closedSwitch.checked) {
                (sw === activeSwitch ? closedSwitch : activeSwitch).checked = true;
            }
            loadProjects(true);
        });
    });

    // Infinite scroll
    function handleScroll() {
        const loaderTop = loader.getBoundingClientRect().top;
        const windowBottom = window.innerHeight;
        if (loaderTop - windowBottom < 100) {
            loadProjects();
        }
    }

    window.addEventListener('scroll', handleScroll);

    // Chargement initial
    loadProjects(true);

    // Pré-chargement si page trop courte
    window.addEventListener('load', () => {
        if (loader.getBoundingClientRect().top < window.innerHeight) {
            loadProjects();
        }
    });
});