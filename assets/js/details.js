//Aucun modification realisée dans la modal
let modalIsDirty = false;
let modal = document.getElementById("ispag-modal-product");

if (modal) {
  modal.addEventListener('change', function (e) {
    if (e.target.matches('input, textarea, select')) {
      markModalAsDirty();
    }
  });
}

$(document).ready(function() {
    reloadArticleList();
});

document.addEventListener("DOMContentLoaded", function () {
    attachEditModalEvents();
    attachViewModalEvents();
    bindStandardTitleListener();

    

    // ferme la modale
    
    if (!modal) return;
    const modalContent = modal.querySelector('.ispag-modal-content');
    const closeBtn = document.querySelector(".ispag-modal-close");
    
    // window.onclick = (e) => { if (e.target === modal) modal.style.display = "none"; };

    // Utilisation de la délégation de clic (fonctionne même si la modale est rechargée en AJAX)
    $(document).on('click', '.ispag-modal-close', function(e) {
        e.preventDefault();

        if (modalIsDirty) {
            // Utilisation d'une chaîne de secours si ispag_texts n'est pas chargé
            const warning = (typeof ispag_texts !== 'undefined') 
                ? ispag_texts.modal_unsaved_changes_warning 
                : "Voulez-vous quitter sans enregistrer les modifications";
            
            if (!confirm(warning + " ?")) {
                return;
            }
        }
        
        closeIspagModal();
    });
    // Fermeture en cliquant en dehors
    window.onclick = (e) => {
        if (e.target === modal) {
            if (modalIsDirty) {
            const confirmClose = confirm(ispag_texts.modal_unsaved_changes_warning + " ?"); 
            if (!confirmClose) return;
            }
            closeIspagModal();
        }
    };
    
    $(document).on('keydown', function(e) {

        if (e.key === 'Escape' && modal.is(':visible')) {
            if (!modalContent.contains(e.target)) {
                if (modalIsDirty) {
                const confirmClose = confirm(ispag_texts.modal_unsaved_changes_warning + " ?"); 
                if (!confirmClose) return;
                }

                closeIspagModal();
            }
        }
    });

    // Bouton "Voir"
    document.querySelectorAll('.ispag-btn-view').forEach(btn => {
        btn.addEventListener("click", () => {
            const articleId = btn.dataset.articleId;
            const originalHtml = btn.innerHTML;

            // Activation du Spinner
            btn.innerHTML = '<span class="dashicons dashicons-update spin"></span>';
            btn.style.pointerEvents = 'none'; // Désactive les clics

            // Vérifie si l’URL contient "poid="
            const currentUrl = window.location.href;
            const source = currentUrl.includes("poid=") ? "purchase" : "project";

            fetch(ajaxurl, {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: new URLSearchParams({
                    action: 'ispag_load_article_modal',
                    article_id: articleId,
                    source: source // 👈 on envoie la source
                })
            })
            .then(res => res.text())
            .then(html => {
                // console.log(`[ISPAG] Retour reçu pour l'article ${articleId}:`, {
                //     source_demandee: source,
                //     taille_html: html.length,
                //     apercu: html.substring(0, 100) + "..." // Affiche le début pour debug
                // });
                document.getElementById("ispag-modal-body").innerHTML = html;
                $('body').addClass('modal-open');
                modal.style.display = "block";
                attachViewModalEvents();
                attachEditModalEvents();
                bindStandardTitleListener();
            })
            .finally(() => {
                // Restauration du bouton
                btn.innerHTML = originalHtml;
                btn.style.pointerEvents = 'auto';
            });
        });
    });


    // Bouton "Éditer"
    $(document).on('click', '.ispag-btn-edit', function (e) {
        e.preventDefault();
        
        const $btn = $(this);
        const articleId = $(this).data('article-id');
        const originalHtml = $btn.html();

        // Activation du Spinner et état désactivé
        $btn.prop('disabled', true).html('<span class="dashicons dashicons-update spin"></span>');

        // Vérifie si l’URL contient "poid="
        const currentUrl = window.location.href;
        const source = currentUrl.includes("poid=") ? "purchase" : "project";

        $btn.css('opacity', '0.5');
 
        $.ajax({
            url: ajaxurl,
            method: 'POST',
            data: {
                action: 'ispag_load_article_edit_modal',
                article_id: articleId,
                source: source // 👈 on envoie la source
            },
            success: function (response) {

                $btn.css('opacity', '1');

                // $('#ispag-modal-body').html(response);
                // document.getElementById("ispag-modal-body").innerHTML = html;
                // $('#ispag-modal-product').show();
                $('body').addClass('modal-open');
                modal.style.display = "block";

                attachEditModalEvents(); // 🔥 Ajout ici
                attachViewModalEvents();
                bindStandardTitleListener();

                // 🔥 EXECUTION DU CALCUL INITIAL DU PRIX
                setTimeout(function() {
                    updateTankPrice();
                    
                }, 50);
            },
            error: function () {
                alert('Erreur lors du chargement du formulaire.');
            },
            complete: function() {
                // Restauration du bouton (succès ou erreur)
                $btn.prop('disabled', false).html(originalHtml);
            }
        });
    });

    $(document).on('submit', '.ispag-edit-article-form', function (e) {
        e.preventDefault();
        

        const form = $(this);
        const submitBtn = form.find('button[type="submit"]');
        const cancelBtn = form.find('button[onclick*="closeIspagModal"]');
        const originalBtnHtml = submitBtn.html();

        const articleId = form.data('article-id');
        const is_secondary = form.data('data-level-secondary');
        const dealId = getUrlParam('deal_id');
        const poid = getUrlParam('poid');
        const formData = new URLSearchParams(form.serialize());
        let is_purchase = poid ? 'true' : 'false';

        const $articleList = getArticleListContainer();

        // --- ÉTAT DE CHARGEMENT ---
        // Désactive les boutons
        submitBtn.prop('disabled', true).addClass('ispag-btn-loading');
        cancelBtn.prop('disabled', true);
        
        // Change l'icône et le texte (on ajoute une classe de rotation à l'icône)
        submitBtn.html('<span class="dashicons dashicons-update spin"></span> Enregistrement...');

        showSpinner();

        if (dealId) formData.append('deal_id', dealId);
        if (poid) formData.append('poid', poid);

        const payload = {
            action: 'ispag_save_article',
            article_id: articleId || 0
        };

        for (const [key, value] of formData.entries()) {
            payload[key] = value;
        }

        $articleList.addClass('is-loading');

        // 1. Sauvegarde des données générales de l'article
        $.post(ajaxurl, payload)
            .done(response => {
                
                const finalArticleId = articleId || response.data.article_id;

                // 2. ON ATTEND que les données techniques (diamètre, etc.) soient sauvées
                saveTankData(finalArticleId, is_purchase)
                    .done(() => {
                        // console.log('✅ Données techniques sauvegardées, rechargement...');
                        
                        if (articleId) {
                            // --- ÉTAPE A : RÉCUPÉRER LES VALEURS AVANT TOUT RECHARGEMENT ---
                            const newDiameter = form.find('select[name="tank[diameter]"]').val();
                            const newPressure = form.find('input[name="tank[max_pressure]"]').val();
                            const newTemp     = form.find('input[name="tank[temperature]"]').val();
                            const newInsul    = form.find('select[name="tank[InsulationThickness]"]').val();


                            // Cas édition : on recharge la ligne proprement
                            $.post(ajaxurl, {
                                action: 'ispag_reload_article_row',
                                is_secondary: is_secondary,
                                article_id: articleId,
                                is_purchase: is_purchase
                            }, function (html) {
                                // --- ÉTAPE B : MISE À JOUR DU DOM ---
                                reloadArticleList();
                                reload_bottom_btn();

                                // --- ÉTAPE C : APPLIQUER LES DATAS SUR LE NOUVEAU BOUTON ---
                                // On attend un tout petit peu que le DOM soit injecté par reloadArticleList
                                setTimeout(() => {
                                    // Note : vérifiez si c'est [data-id] ou [data-article-id] dans votre liste
                                    const $btnRaccords = jQuery(`.ispag-article[data-id="${articleId}"], .ispag-article[data-article-id="${articleId}"]`).find('#open-tank-fittings-modal');

                                    if ($btnRaccords.length) {
                                        $btnRaccords.attr('data-tank-diameter', newDiameter);
                                        $btnRaccords.attr('data-tank-pression', newPressure);
                                        $btnRaccords.attr('data-tank-using-temp', newTemp);
                                        $btnRaccords.attr('data-tank-insulation-thickness', newInsul);
                                        // console.log(`✅ Attributs mis à jour pour l'article ${articleId}`);
                                    }
                                }, 100);
                                
                                attachEditModalEvents();
                                attachViewModalEvents();
                                bindStandardTitleListener();
                                $articleList.removeClass('is-loading');
                                

                                closeIspagModal();
                            });
                        } else {
                            // Cas création : on rafraîchit la liste globale
                            reloadArticleList();
                            $articleList.removeClass('is-loading');
                        }
                    })
                    .fail(err => {
                        console.error('Erreur saveTankData :', err);
                        alert('Erreur lors de la sauvegarde du diamètre');
                        resetButtons(submitBtn, cancelBtn, originalBtnHtml);
                        $articleList.removeClass('is-loading');
                    });
            })
            .fail(err => {
                console.error('Erreur enregistrement article :', err);
                alert('Erreur lors de la sauvegarde');
                resetButtons(submitBtn, cancelBtn, originalBtnHtml);
                $articleList.removeClass('is-loading');
            });
    });

    // Petite fonction utilitaire pour remettre les boutons en état si ça échoue
    function resetButtons(btn, cancel, oldHtml) {
        btn.prop('disabled', false).html(oldHtml).removeClass('ispag-btn-loading');
        cancel.prop('disabled', false);
        const $articleList = getArticleListContainer();
        $articleList.removeClass('is-loading');
    }
    document.getElementById('ispag-add-article').addEventListener('click', function () {
        // Vérifie si l’URL contient "poid="
        const currentUrl = window.location.href;
        const source = currentUrl.includes("poid=") ? "purchase" : "project";

        fetch(ajaxurl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({
                action: 'ispag_open_new_article_modal'
            })
        })
        .then(res => res.text())
        .then(html => {
            document.getElementById("ispag-modal-body").innerHTML = html;
            $('body').addClass('modal-open');
            modal.style.display = "block";


            // 🟡 Attacher l'écouteur après le rendu du select
            const typeSelect = document.getElementById("new-article-type");
            // const dealId = getUrlParam('deal_id');
            const dealId = this.dataset.dealId;
            const poid = this.dataset.poid;
            if (typeSelect) {
                typeSelect.addEventListener('change', function () {

                    const typeId = this.value;
                    if (!typeId || (!dealId && !poid)) return;

//                    console.log('typeId', typeId);
//                    console.log('dealId', dealId);

                    fetch(ajaxurl, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: new URLSearchParams({
                            action: 'ispag_load_article_create_modal',
                            type_id: typeId,
                            deal_id: dealId,
                            poid: poid,
                            source: source
                        }) 
                    })
                    .then(res => res.text())
                    .then(html => {
                        $('#ispag-product-type-selector').fadeOut();
                        document.getElementById("new-article-form-container").innerHTML = html;
                        attachEditModalEvents();
                        bindStandardTitleListener();
                    });
                });
            }
        });
    });

    $(document).on('click', '.ispag-btn-delete', function () {
        const $button = $(this);
        // 1. Trouver l'article parent
        const $article = $button.closest('.ispag-article');

        // Vérifie si l’URL contient "poid="
        const currentUrl = window.location.href;
        const source = currentUrl.includes("poid=") ? "purchase" : "project";
        const articleId = $(this).data('article-id');
        if (!confirm(ispag_texts.confirm_delete_article + ' ?')) return;

        // 2. **Afficher le spinner/overlay** en ajoutant une classe à l'article parent
        $article.addClass('is-loading');

        $.post(ajaxurl, {
            action: 'ispag_delete_article',
            article_id: articleId,
            source: source
        })
        .done(response => {
            if (response.success) {
                // $(`[data-article-id="${articleId}"]`).remove(); // supprime le bloc HTML
                // Supprime l'article directement (le spinner disparaîtra avec l'élément)
                $article.remove();
            } else {
                // alert(response.data.message || 'Erreur lors de la suppression');
                alert(response.data.message || 'Erreur lors de la suppression');
                // En cas d'échec de l'action, **masquer le spinner**
                $article.removeClass('is-loading');
            }
        })
        .fail(() => {
            alert('Erreur serveur');
        });
    });

    document.addEventListener('click', (e) => {
        const btn = e.target.closest('.ispag-btn-copy-description');

        if (btn) {
            const targetSelector = btn.getAttribute('data-target');
            const text = document.querySelector(targetSelector)?.innerText;
            if (text) {
                navigator.clipboard.writeText(text).then(() => {
                    btn.innerText = '✅';
                    setTimeout(() => btn.innerText = '📋', 1000);
                });
            }
        }
    });

    document.addEventListener('click', function (e) {
        const btn = e.target.closest('.ispag-btn-copy');
        if (!btn) return;

        const articleId = btn.dataset.articleId;
        if (!articleId) return;

        if (!confirm(ispag_texts.would_you_copy + " ?")) return;

        // 1. **Afficher le Spinner** juste avant l'appel Fetch
        showSpinner();

        fetch(ispag_texts.ajax_url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({
                action: 'ispag_duplicate_article',
                article_id: articleId,
                _ajax_nonce: ispag_texts.nonce
            })
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                alert(ispag_texts.article_duplicated );
                // location.reload(); // ou mets à jour ta liste dynamiquement
                reloadArticleList();
            } else {
                alert('Erreur : ' + data.data);
            }
        }).catch(error => {
            // Gérer les erreurs de réseau (ex: pas de connexion)
            console.error('Erreur lors de la requête de duplication:', error);
            alert('Une erreur de connexion est survenue.');
        })
        .finally(() => {
            // 2. **Masquer le Spinner** dans le bloc finally (s'exécute après .then ou .catch)
            hideSpinner(); 
        });
    });

    const convertBtn = document.getElementById("convert-to-project");

    if (convertBtn) {
        convertBtn.addEventListener("click", function () {
            const deal_id = this.dataset.id;

            // 1. Demander confirmation initiale
            if (!confirm("Voulez-vous vraiment convertir cette offre en commande ?")) {
                return;
            }

            // 2. Vérification des champs obligatoires (Numéro de commande ISPAG et Client)
            const numCommandeEl = document.querySelector('.ispag-inline-edit[data-name="NumCommande"]');
            const customerOrderIdEl = document.querySelector('.ispag-inline-edit[data-name="customer_order_id"]');

            // On récupère les valeurs proprement (en enlevant les espaces et ignorant le placeholder '---')
            const valNumCommande = numCommandeEl ? numCommandeEl.dataset.value.trim() : "";
            const valCustomerOrder = customerOrderIdEl ? customerOrderIdEl.dataset.value.trim() : "";

            if (valNumCommande === "" || valCustomerOrder === "") {
                alert("⚠️ Attention : Le numéro de commande ISPAG et la référence client sont obligatoires avant la conversion.");
                
                // Si l'un des deux manque, on déclenche le clic sur le premier champ vide pour ouvrir la modal d'édition inline
                if (valNumCommande === "") {
                    numCommandeEl.click();
                } else {
                    customerOrderIdEl.click();
                }
                return; // On arrête la conversion ici
            }

            // 3. Si tout est OK, on lance le fetch original
            executeConversion(deal_id);
        });
    }

    /**
     * Fonction isolée pour exécuter l'appel AJAX de conversion
     */
    function executeConversion(deal_id) {
        // On peut ajouter un petit spinner sur le bouton pour faire propre
        const btn = document.getElementById("convert-to-project");
        btn.innerHTML = "🔄 Conversion...";
        btn.disabled = true;

        fetch(ispagVars.ajaxurl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({
                action: 'ispag_convert_to_project',
                id: deal_id,
            })
        })
        .then(res => res.json())
        .then(response => {
            if (response.success) {
                const url = new URL(window.location);
                url.searchParams.delete("qotation");
                window.location.href = url.toString();
            } else {
                console.error("❌ Erreur serveur:", response.data);
                alert("Erreur : " + response.data);
                btn.innerHTML = "Convertir en commande"; // Reset bouton
                btn.disabled = false;
            }
        })
        .catch(error => {
            console.error("🔥 Erreur AJAX:", error);
            alert("Erreur AJAX : " + error.message);
        });
    }


});

jQuery(document).on('click', '#generate-purchase-requests', function () {
    const deal_id = jQuery(this).data('deal-id');
    const msgBox = document.getElementById('ispag-bulk-message');

    jQuery.post(ispagVars.ajaxurl, {
        action: 'ispag_generate_purchase_requests',
        deal_id: deal_id
    }, function (response) {
//        console.log('ispag_generate_purchase_requests', response);
        reloadArticleList();
        if (response.success) {
            msgBox.textContent = response.data.message;
            msgBox.style.display = 'block';
            msgBox.style.backgroundColor = '#d4edda';
            msgBox.style.color = '#155724';
            msgBox.style.border = '1px solid #c3e6cb';

            // Disparait au bout de 3 secondes
            setTimeout(() => {
                msgBox.style.display = 'none';
                location.reload();
            }, 1000);
        } else {
            msgBox.textContent = response.data?.message || 'Erreur inconnue';
            msgBox.style.display = 'block';
            msgBox.style.backgroundColor = '#f8d7da';
            msgBox.style.color = '#721c24';
            msgBox.style.border = '1px solid #f5c6cb';
        }
        // location.reload(); // ou refresh partiel
    });
});





function getUrlParam(key) {
    const params = new URLSearchParams(window.location.search);
    return params.get(key);
}


function closeIspagModal(){
    
    // modal.fadeOut();
    $('#ispag-modal-body').html('');
    $('body').removeClass('modal-open');
    modal.style.display = "none"
    modalIsDirty = false;
}

let lastBtnRequestId = 0; // ID pour suivre la dernière requête envoyée

function reload_bottom_btn() {
    const deal_id = getUrlParam('deal_id');

    // Supprime l’ancien bouton si existant
    const oldBtn = document.getElementById('generate-purchase-requests');
    if (oldBtn) oldBtn.remove();

    // Incrémente l’ID de requête
    const requestId = ++lastBtnRequestId;

    fetch(ajaxurl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=ajax_get_generate_po_button&deal_id=' + deal_id
    })
    .then(res => res.json())
    .then(data => {
        // Si ce n’est pas la dernière requête envoyée, on ignore la réponse
        if (requestId !== lastBtnRequestId) return;

        if (data.success && data.data.trim()) {
            document
                .getElementById('ispag-add-article')
                .insertAdjacentHTML('afterend', data.data);
        }
    });
}


function reloadArticleList() {
    // const deal_id = getUrlParam('deal_id');
    const container = document.querySelector('.ispag-articles-list');
    const deal_id = container ? container.getAttribute('data-deal-id') : null;

    if (!deal_id) {
        console.warn("Impossible de trouver le deal_id dans l'élément .ispag-articles-list");
        return;
    }
    // console.log("Deal ID récupéré :", deal_id);
    closeIspagModal();
    const $listContainer = getArticleListContainer();
    
    // 1. Afficher le spinner sur la liste
    $listContainer.addClass('is-reloading');
    
 
    $.ajax({
        url: ajaxurl,
        type: 'POST',
        data: {
            action: 'ispag_reload_article_list',
            deal_id: deal_id
        },
        success: function(response) {
            // console.log('ispag_reload_article_list : ', response);
            $('.ispag-articles-list').html(response);
            reload_bottom_btn();
            attachViewModalEvents();
            attachEditModalEvents();
            bindStandardTitleListener();
            reloadProjectStats();
        },
        error: function(error) {
            console.error('Erreur lors du rechargement des articles :', error);
        },
        // 2. ⭐ RETIRER LE SPINNER : Cette fonction s'exécute après la fin de la requête (succès ou erreur).
        complete: function() {
             hideSpinner();
        }
    });
}

function attachViewModalEvents() {
    

    document.querySelectorAll('.ispag-btn-view').forEach(btn => {
        btn.removeEventListener("click", handleViewClick); // évite les doublons
        btn.addEventListener("click", handleViewClick);
    });

    function handleViewClick(e) {
        const articleId = e.currentTarget.dataset.articleId;

        // Vérifie si l’URL contient "poid="
        const currentUrl = window.location.href;
        const source = currentUrl.includes("poid=") ? "purchase" : "project";

        fetch(ajaxurl, {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: new URLSearchParams({
                action: 'ispag_load_article_modal',
                article_id: articleId,
                source: source // 👈 on envoie la source
            })
        })
        .then(res => res.text())
        .then(html => {

            // console.log(`[ISPAG] Retour reçu pour HANDLEs l'article ${articleId}:`, {
            //         source_demandee: source,
            //         taille_html: html.length,
            //         apercu: html.substring(0, 100) + "..." // Affiche le début pour debug
            //     });


            document.getElementById("ispag-modal-body").innerHTML = html;
            $('body').addClass('modal-open');
            modal.style.display = "block";
        });
    }
}

function attachEditModalEvents() {

    // Rebind des boutons éditer (jQuery)
    $('.ispag-btn-edit').off('click').on('click', function () {
        const articleId = $(this).data('article-id');
        const currentUrl = window.location.href;
        const source = currentUrl.includes("poid=") ? "purchase" : "project";

        $.ajax({
            url: ajaxurl,
            method: 'POST',
            data: {
                action: 'ispag_load_article_edit_modal',
                article_id: articleId,
                source: source // 👈 on envoie la source
            },
            success: async function (response) { // Notez le 'async' ici
                $('#ispag-modal-body').html(response); // 1. On injecte le HTML
                $('body').addClass('modal-open');
                modal.style.display = "block";
                // $('#ispag-modal').show();

                // 2. RÉCUPÉRATION DES PARAMÈTRES POUR LE DIAMÈTRE
                // On récupère les valeurs APRÈS l'injection
                const $typeSel = $('#tank-typ');
                const $matSel = $('#tank-material');
                const $diamSel = $('#tank-diameter');

                if ($matSel.length > 0) {
                    const materialId = $matSel.find(':selected').data('id') || $matSel.val();
                    const currentDiamValue = $diamSel.val(); // Récupère la valeur pré-remplie par PHP
                    
                    // 3. REMPLISSAGE FORCÉ DES DIAMÈTRES
                    // On attend que les données soient prêtes et on remplit
                    await updateDiameterDatalistByType(materialId, currentDiamValue);
                }

                // 4. RÉATTACHEMENT DES AUTRES ÉVÉNEMENTS
                attachEditModalEvents(); 
                bindStandardTitleListener();
                
                // Si vous avez d'autres initialisations de réservoir :
                const initTypId = $('#tank-typ option:selected').data('id');
                if (initTypId) {
                    updateTankDefaults(initTypId);
                }
            },
            error: function () {
                alert('Erreur lors du chargement du formulaire.');
            }
        });
    });
}

function bindStandardTitleListener() {
    const titleInput = document.getElementById('article-title');
    if (!titleInput) return;

    titleInput.addEventListener('change', () => {

        const selectedTitle = titleInput.value.trim();
        const articleType = titleInput.dataset.type;
        const options = document.querySelectorAll('#standard-titles option');

        const matchedOption = Array.from(options).find(opt => opt.value === selectedTitle);
        const articleId = matchedOption ? matchedOption.dataset.id : '';

        if (!selectedTitle || !articleType || !articleId) return;


        fetch(ajaxurl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({
                action: 'ispag_get_standard_article_info',
                id: articleId,
                type: articleType
            })
        })
        .then(res => res.json())
        .then(data => {
            // console.log(data);
            if (data.success) {
                const article = data.data['article'];
                document.querySelector('[name="description"]').value = article['description_ispag'] || '';
                document.querySelector('[name="sales_price"]').value = article['sales_price'] || '';
                document.querySelector('[name="IdArticleStandard"]').value = article['Id_article_standard'] || '';

                // Cocher la case DemandeAchatOk
                const demandeCheckbox = document.querySelector('[name="DemandeAchatOk"]');
                if (demandeCheckbox) {
                    demandeCheckbox.checked = true;
                }

                const supplierField = document.querySelector('[name="supplier"]');
                const supplierList = document.getElementById('supplier-list');
                if (supplierField && supplierList) {
                    supplierList.innerHTML = '';
                    if (Array.isArray(article.suppliers)) {
                        article.suppliers.forEach(supplier => {
                            const option = document.createElement('option');
                            option.value = supplier;
                            supplierList.appendChild(option);
                        });
                        if (article.suppliers.length == 1) {
                            supplierField.value = article.suppliers[0];
                        }
                    }
                }
            } else if (data.error) {
                alert(data.error);
            }
        })
        .catch(() => alert('Erreur réseau ou serveur.'));
    });
}


// Appelle ceci quand quelque chose est modifié dans la modal
function markModalAsDirty() {
  modalIsDirty = true;
//  console.log('modalIsDirty', modalIsDirty);
}

jQuery(document).on('click', '.ispag-delete-project-btn', function () {
    const dealId = jQuery(this).data('deal-id');
    if (!confirm(ispag_texts.txt_delete_project + " ?")) return;

    jQuery.ajax({
        url: ajaxurl,
        type: 'POST',
        data: {
            action: 'ispag_delete_project',
            deal_id: dealId
        },
        success: function (response) {
            // console.log(response.data);
            alert(response.data.message || 'Projet supprimé');
            window.close();
        },
        error: function () {
            alert(ispag_texts.txt_error_deleting_project + ".");
        }
    });
});


function reloadProjectStats(){
    // const deal_id = getUrlParam('deal_id');
    const container = document.querySelector('.ispag-articles-list');
    const deal_id = container ? container.getAttribute('data-deal-id') : null;

    jQuery.ajax({
        url: ajaxurl,
        type: 'POST',
        data: {
            action: 'ispag_display_deal_stats',
            // IMPORTANT: Vous devrez peut-être aussi envoyer le deal_id dans les données POST
            // Si le deal_id est uniquement dans l'URL (GET) lors de l'appel initial de la page.
            deal_id: deal_id 
        },
        success: function (response) {
        //    console.log('reloadProjectStats', response);
            
            if (response.success && response.data && response.data.html) {
                // Utilisez .html() si vous voulez remplacer le contenu à l'intérieur de #ispag_project_stat
                // OU utilisez .replaceWith() si vous voulez remplacer l'élément lui-même
                // J'utilise response.data.html car c'est là que se trouve votre HTML après wp_send_json_success
                $("#ispag_project_stat").replaceWith(response.data.html); 
            } else {
                 console.error("Réponse AJAX invalide ou erreur renvoyée.", response);
            }
        },
        error: function () {
            alert(ispag_texts.txt_error_deleting_project + ".");
        }
    });
}

/***************** BULK *********************** */

// Bloc 1 & 3 fusionnés et optimisés : Gestion de la sélection et de la visibilité (support dynamique)
document.addEventListener('DOMContentLoaded', function () {
    const selectAll = document.getElementById('select-all-articles');
    const container = document.querySelector('#display_article_page'); // Élément statique parent
    const bulkDiv = document.querySelector('.ispag-bulk-actions');

    if (!selectAll || !container) {
        // Un des éléments clés n'est pas présent, on sort
        console.warn('Elements for bulk actions or article container not found.');
        return;
    }

    // --- 1. Logique "Tout sélectionner" (appliquée à tous les éléments actuels) ---
    selectAll.addEventListener('change', function () {
        const currentCheckboxes = container.querySelectorAll('.ispag-article-checkbox');
        const shouldBeChecked = selectAll.checked;
        
        // Coche/Décoche toutes les cases trouvées dans le conteneur
        currentCheckboxes.forEach(cb => cb.checked = shouldBeChecked);

        // Affiche/Masque la barre d'actions
        if (bulkDiv) {
            bulkDiv.style.display = shouldBeChecked ? 'block' : 'none';
        }
    });

    // --- 2. Délégation d'événement (Gère les cases cochées individuellement, y compris les nouvelles) ---
    container.addEventListener('change', function(event) {
        // Vérifie si l'événement provient d'une case d'article
        if (event.target && event.target.matches('.ispag-article-checkbox')) {
            const currentCheckboxes = container.querySelectorAll('.ispag-article-checkbox');
            
            // Met à jour la case "Tout sélectionner"
            const allChecked = [...currentCheckboxes].every(c => c.checked);
            selectAll.checked = allChecked;

            // Met à jour la visibilité de la barre d'actions
            const anyChecked = [...currentCheckboxes].some(c => c.checked);
            if (bulkDiv) {
                bulkDiv.style.display = anyChecked ? 'block' : 'none';
            }
        }
    });

    // 🛑 Le bloc "document.querySelectorAll('.ispag-article-checkbox').forEach(...)" 
    // EST SUPPRIMÉ car sa logique est désormais gérée par la délégation ci-dessus.
});

// -----------------------------------------------------------------------------

// Bloc 2 : Initialisation des états indéterminés (Aucun changement nécessaire)
document.addEventListener('DOMContentLoaded', function () {
    const cb = document.getElementById('bulk-demande-ok');
    const db = document.getElementById('bulk-drawing-ok');

    // S'assurer que les deux existent
    if(cb && db) { 
        cb.indeterminate = true; // état par défaut indéterminé
        db.indeterminate = true; // état par défaut indéterminé
    }
});

// -----------------------------------------------------------------------------

// Bloc 4 : Logique de l'application de la mise à jour en masse
const applyBulkButton = document.getElementById('apply-bulk-update');
if (applyBulkButton) {
    applyBulkButton.addEventListener('click', function () {
        // Récupère les IDs des cases cochées (recherche dynamique et à jour)
        const selectedIds = [...document.querySelectorAll('.ispag-article-checkbox:checked')].map(cb => cb.dataset.articleId);

        if (selectedIds.length === 0) {
            // CORRECTION: Utilisation d'une chaîne JS simple. 
            // La construction PHP/JS dans l'alerte n'est pas valide dans un fichier .js seul.
            alert('Aucun article sélectionné'); 
            return;
        }

        const data = {
            action: 'ispag_bulk_update_articles',
            articles: selectedIds,
            deal_id: document.getElementById('deal-id')?.value, // Utilisation de l'opérateur optionnel chaining
            date_depart: document.getElementById('bulk-date-depart')?.value,
            date_eta: document.getElementById('bulk-date-eta')?.value,
            livre_date: document.getElementById('bulk-livre-date')?.value,
            invoiced_date: document.getElementById('bulk-invoiced-date')?.value,
            discount: document.getElementById('bulk-discount')?.value,
            _ajax_nonce: ispag_texts.nonce
        };

        const demandeOk = document.getElementById('bulk-demande-ok');
        if (demandeOk && !demandeOk.indeterminate) {
            data.demande_ok = demandeOk.checked ? 1 : 0;
        }
        const drawingOk = document.getElementById('bulk-drawing-ok');
        if (drawingOk && !drawingOk.indeterminate) {
            data.drawing_ok = drawingOk.checked ? 1 : 0;
        }

        fetch(ispag_texts.ajax_url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams(data)
        })
        .then(res => {
             // Vérifier si la réponse est JSON avant de la parser
             const contentType = res.headers.get("content-type");
             if (contentType && contentType.indexOf("application/json") !== -1) {
                 return res.json();
             } else {
                 console.error('Response was not JSON. Full response:', res);
                 throw new Error('Réponse du serveur invalide.');
             }
        })
        .then(response => {
//            console.log('response:', response);
            const msgBox = document.getElementById('ispag-bulk-message');
            if (!msgBox) return;

            if (response.success) {
                msgBox.textContent = response.data.message;
                msgBox.style.display = 'block';
                msgBox.style.backgroundColor = '#d4edda';
                msgBox.style.color = '#155724';
                msgBox.style.border = '1px solid #c3e6cb';

                // Disparait au bout de 1 seconde (ajusté)
                setTimeout(() => {
                    msgBox.style.display = 'none';
                    location.reload(); // Rechargement après succès
                }, 1000); 
            } else {
                msgBox.textContent = response.data?.message || 'Erreur inconnue (Vérifiez la console).';
                msgBox.style.display = 'block';
                msgBox.style.backgroundColor = '#f8d7da';
                msgBox.style.color = '#721c24';
                msgBox.style.border = '1px solid #f5c6cb';
            }
        })
        .catch(error => {
            console.error('Erreur lors de la requête fetch:', error);
            const msgBox = document.getElementById('ispag-bulk-message');
             if (msgBox) {
                msgBox.textContent = 'Erreur de connexion : ' + error.message;
                msgBox.style.display = 'block';
                msgBox.style.backgroundColor = '#f8d7da';
                msgBox.style.color = '#721c24';
                msgBox.style.border = '1px solid #f5c6cb';
             }
        });
    });
}



// **********************************************
// NOTE : Il faut que vous définissiez ces fonctions
// pour gérer l'affichage/masquage de votre élément spinner.
// **********************************************
function showSpinner() {
    // Par exemple, si votre spinner a l'ID 'ispag-loading-spinner':
    const spinner = document.getElementById('ispag-loading-spinner');
    if (spinner) {
        // spinner.style.display = 'block'; 
        $('.ispag-article-list-overlay').show();
        // Ou mieux : spinner.classList.remove('hidden');
    }
}

function hideSpinner() {
    const spinner = document.getElementById('ispag-loading-spinner');
    if (spinner) {
        // spinner.style.display = 'none';
        $('.ispag-article-list-overlay').hide();
        // Ou mieux : spinner.classList.add('hidden');
    }
}
function getArticleListContainer() {
    // Assurez-vous que ce sélecteur correspond à votre structure HTML.
    return $('#display_ispag_article_list'); 
}



jQuery(document).ready(function($) {
    // On écoute le clic sur le document, mais on ne déclenche que si c'est la croix
    $(document).on('click', '.ispag-modal-close', function() {
        // On remonte au parent qui a la classe de l'overlay ou du conteneur modal
        // Ajuste '.ispag-modal-overlay' selon ton HTML parent
        $('.ispag-modal-overlay').fadeOut(200, function() {
            $(this).remove(); // Ou .hide() si elle est statique
        });
    });

    // Optionnel : Fermer en cliquant en dehors du contenu (sur l'overlay)
    $(document).on('click', '.ispag-modal-overlay', function(e) {
        if ($(e.target).hasClass('ispag-modal-overlay')) {
            $(this).fadeOut(200);
        }
    });
});