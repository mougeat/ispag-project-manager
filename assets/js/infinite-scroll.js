document.addEventListener('DOMContentLoaded', function () {
    let offsetProjects = 0;
    const limit = 20;
    let loading = false;
    let hasMore = true;

    const loader = document.getElementById('scroll-loader');
    const listContainer = document.getElementById('projets-list');
    const searchInput = document.getElementById('ispag-projects-search');
    const creatorSelect = document.getElementById('ispag-projects-creator-filter');

    if (!loader || !listContainer) return;

    loader.innerHTML = '<div class="loading-spinner" style="text-align:center;"><span class="dashicons dashicons-update" style="animation: spin 2s linear infinite;"></span> ' + ispagVars.loading_text + '</div>';

    function loadProjects(reset = false) {

        if (reset) {
            hasMore = true;
        }
        
        if (loading || !hasMore) return;
        loading = true;

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
            // Modifie la barre d'adresse sans recharger
            window.history.replaceState({}, '', url);
            
            offsetProjects = 0;
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
        formData.append('nonce', ispagVars.nonce);

        fetch(ajaxurl, {
            method: 'POST',
            credentials: 'same-origin',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
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
            console.error('Error AJAX:', error);
        })
        .finally(() => {
            loading = false;
        });
    }

    // Écouteurs d'événements pour les filtres
    if (searchInput) {
        let debounceTimer;
        searchInput.addEventListener('input', function() {
            clearTimeout(debounceTimer);
            debounceTimer = setTimeout(() => {
                loadProjects(true);
            }, 500);
        });
    }

    if (creatorSelect) {
        creatorSelect.addEventListener('change', function() {
            loadProjects(true);
        });
    }

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