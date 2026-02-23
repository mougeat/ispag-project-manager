document.addEventListener('DOMContentLoaded', function () {
    let offsetProjects  = 0;
    const limit = 20;
    let loading = false;
    let hasMore = true;
 
    const loader = document.getElementById('scroll-loader');
    const listContainer = document.getElementById('projets-list');

    // On ne lance rien si les éléments ne sont pas là
    if (!loader || !listContainer) return;

    
    loader.innerHTML = '<div class="loading-spinner" style="text-align:center;"><span class="dashicons dashicons-update" style="animation: spin 2s linear infinite;"></span> ' + ispagVars.loading_text + '</div>';

 
    function loadProjects() {
        if (loading || !hasMore) return;
        loading = true;

        // const search = new URLSearchParams(window.location.search).get('search') || '';
        // const qotation = new URLSearchParams(window.location.search).get('qotation') === '1' ? '1' : '0';


        const meta = document.getElementById('projets-meta');
        const contact_id = meta ? meta.dataset.contactid : '0';
        const qotation = meta ? meta.dataset.qotation : '0';
        const only_activ = meta ? meta.dataset.onlyactiv : '0';
        const search = meta ? meta.dataset.search : '';
        const select_state = meta ? meta.dataset.select_state : '';

        // console.log('projets-meta', qotation);
         
        const formData = new FormData();
        formData.append('action', 'ispag_load_more_projects'); 
        formData.append('offset', offsetProjects );
        formData.append('contact_id', contact_id);
        formData.append('qotation', qotation);
        formData.append('only_activ', only_activ);
        formData.append('search', search);
        formData.append('select_state', select_state);
        // formData.append('limit', limit);

        fetch(ajaxurl, {
            method: 'POST',
            credentials: 'same-origin',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
//            console.log(data);
            if (data.success) {
                listContainer.insertAdjacentHTML('beforeend', data.data.html);
                offsetProjects  += limit;
                hasMore = data.data.has_more;
                if (!hasMore) {
                    loader.innerHTML = '<p style="text-align:center; color:#777;">' + ispagVars.all_loaded_text + '.</p>';
                }
            }
        })
        .finally(() => loading = false);
    }

    

    function handleScroll() {
        const loaderTop = loader.getBoundingClientRect().top;
        const windowBottom = window.innerHeight;

        if (loaderTop - windowBottom < 100) {
            loadProjects();
        }
    }

    // Déclenchement au scroll
    window.addEventListener('scroll', handleScroll);

    // Chargement initial
    loadProjects();

    // Pré-chargement si page trop courte
    window.addEventListener('load', () => {
        if (loader.getBoundingClientRect().top < window.innerHeight) {
            loadProjects();
        }
    });

//     /************************Achats************************ */
//     function loadPurchases() {
//         if (loading || !hasMore) return;
//         loading = true;

//         const search = new URLSearchParams(window.location.search).get('search') || '';
//         const select_state = new URLSearchParams(window.location.search).get('select_state') || '';
//         // const qotation = new URLSearchParams(window.location.search).get('qotation') === '1' ? '1' : '0';


//         // const meta = document.getElementById('projets-meta');
//         // const qotation = meta ? meta.dataset.qotation : '0';
//         // const search = meta ? meta.dataset.search : '';
        
//         const formData = new FormData();
//         formData.append('action', 'ispag_load_more_achats');
//         formData.append('offset', offset);
//         formData.append('qotation', qotation);
//         formData.append('search', search);
//         formData.append('select_state', select_state);

//         fetch(ajaxurl, {
//             method: 'POST',
//             credentials: 'same-origin',
//             body: formData
//         })
//         .then(response => response.json())
//         .then(data => {
//             if (data.success) {
//                 listContainer.insertAdjacentHTML('beforeend', data.data.html);
//                 offset += limit;
//                 hasMore = data.data.has_more;
//                 if (!hasMore) {
//                     loader.innerHTML = '<p style="text-align:center; color:#777;">' + ispagVars.all_loaded_text + '.</p>';
//                 }
//             }
//         })
//         .finally(() => loading = false);
//     }
});
