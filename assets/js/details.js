// =============================================
// 1. INITIALISATION DES VARIABLES GLOBALES
// =============================================

// Variables globales pour la modal
let modalIsDirty = false;
let modal = document.getElementById("ispag-modal-product");
let modalConfirmation = document.getElementById("confirmationModal");


// =============================================
// FONCTIONS GLOBALES (accessibles partout dans le code)
// =============================================

/**
 * Ferme la modal et réinitialise son état.
 */
function closeIspagModal() {
    // console.log("🚪 [UTIL] Fermeture de la modal.");
    $('#ispag-modal-body').html('');
    $('body').removeClass('modal-open');
    if (modal) modal.style.display = "none";
    if (modalConfirmation) modalConfirmation.style.display = "none";
    modalIsDirty = false;
}

/**
 * Marque la modal comme "modifiée" (pour éviter les fermetures accidentelles).
 */
function markModalAsDirty() {
    // console.log("🔄 [UTIL] Modal marquée comme 'dirty'.");
    modalIsDirty = true;
}
/**
 * Gère la demande de fermeture de la modal avec vérification :
 * 1. Champs obligatoires (diamètre, volume, hauteur) non vides (si ils existent).
 * 2. Modifications non enregistrées (modalIsDirty).
 */
async function requestCloseModal() {
// console.log("🔄 [MODAL] Demande de fermeture de la modal. Vérification des conditions...");

    // 1. Vérifier si les champs obligatoires existent dans la modal
    const $diameter = $('select[name="tank[diameter]"]');
    const $volume = $('input[name="tank[volume]"]');
    const $height = $('input[name="tank[height]"]');

    const hasDiameterField = $diameter.length > 0;
    const hasVolumeField = $volume.length > 0;
    const hasHeightField = $height.length > 0;

// console.log(`🔍 [MODAL] Champs détectés : Diamètre=${hasDiameterField}, Volume=${hasVolumeField}, Hauteur=${hasHeightField}`);

    // 2. Vérifier si les champs obligatoires sont vides (uniquement s'ils existent)
    const isDiameterEmpty = hasDiameterField && (!$diameter.val() || $diameter.val() === '');
    const isVolumeEmpty = hasVolumeField && (!$volume.val() || $volume.val() === '' || parseFloat($volume.val()) <= 0);
    const isHeightEmpty = hasHeightField && (!$height.val() || $height.val() === '' || parseFloat($height.val()) <= 0);

    // 3. Si au moins un champ obligatoire existe ET est vide, afficher une alerte
    if ((hasDiameterField && isDiameterEmpty) || (hasVolumeField && isVolumeEmpty) || (hasHeightField && isHeightEmpty)) {
        const message = ispag_texts?.modal_missing_fields_warning ||
                       "The Diameter, Volume, and Height fields are mandatory. Do you want to leave without filling them in?";
 
// console.log("⚠️ [MODAL] Champs obligatoires manquants ou vides.");
        const confirmed = await ispagConfirm(message, {
            labelOk: ispag_texts.continue || "Continuer",
            labelCancel: ispag_texts.cancel || "Annuler",
            danger: true,
        });

        if (!confirmed) {
// console.log("❌ [MODAL] Fermeture annulée : champs obligatoires manquants.");
            return; // Empêcher la fermeture
        }
    }

    // 4. Vérifier s'il y a des modifications non enregistrées
    if (modalIsDirty) {
        const warning = (typeof ispag_texts !== 'undefined')
            ? ispag_texts.modal_unsaved_changes_warning
            : "Do you want to leave without saving your changes?";

// console.log("⚠️ [MODAL] Modifications non enregistrées détectées.");
        const confirmed = await ispagConfirm(warning + ' ?', {
            labelOk: ispag_texts.quit_without_saving || "Quitter sans enregistrer",
            labelCancel: ispag_texts.cancel || "Annuler",
            danger: true,
        });

        if (!confirmed) {
// console.log("❌ [MODAL] Fermeture annulée par l'utilisateur (modifications non enregistrées).");
            return; // Empêcher la fermeture
        }
    }

    // 5. Si tout est OK, fermer la modal
// console.log("✅ [MODAL] Fermeture confirmée.");
    closeIspagModal();
}

// =============================================
// 2. CHARGEMENT INITIAL AVEC JQUERY
// =============================================
document.addEventListener("DOMContentLoaded", function () {
// console.log("✅ [JQUERY] $(document).ready() déclenché !");
    reloadArticleList();
});
 
// =============================================
// 3. ÉCOUTEURS DOMContentLoaded
// =============================================
document.addEventListener("DOMContentLoaded", function () {
// console.log("✅ [DOM] DOMContentLoaded déclenché !");

    // Initialiser les événements de base
    attachEditModalEvents();
    attachViewModalEvents();
    bindStandardTitleListener();

    if (!modal) {
        console.warn("⚠️ [DOM] La modal n'existe pas. Arrêt de l'initialisation des événements de fermeture.");
        return;
    }

    // --- Événements de fermeture de la modal ---
    // 1. Bouton croix (X)
    $(document).on('click', '.ispag-modal-close', function (e) {
        e.preventDefault();
// console.log("🚪 [MODAL] Clic sur le bouton de fermeture (croix).");
        requestCloseModal();
    });

    // 2. Clic sur l'overlay (en dehors de la modal)
    $(window).on('click', function (e) {
        if ($(e.target).is(modal)) {
// console.log("🚪 [MODAL] Clic en dehors de la modal (overlay).");
            requestCloseModal();
        }
        if (modalConfirmation && $(e.target).is(modalConfirmation)) {
// console.log("🚪 [MODAL] Clic en dehors de la modal de confirmation.");
            requestCloseModal();
        }
    });

    // 3. Touche Échap
    $(document).on('keydown', function (e) {
        if (e.key === 'Escape' && modal && modal.style.display === 'block') {
// console.log("🚪 [MODAL] Touche Échap pressée.");
            requestCloseModal();
        }
    });

    // 4. Délégation d'événements pour le bouton "Cancel" (pour les modales dynamiques)
    document.addEventListener('click', function(e) {
        const cancelBtn = e.target.closest('.ispag-btn-cancel');
        if (cancelBtn) {
            e.preventDefault();
// console.log('🔄 [MODAL] Clic sur le bouton Cancel détecté via délégation.');
            requestCloseModal();
        }
    });

    jQuery(document).on('click', '.ispag-toggle-archive', function(e) {
        e.preventDefault();

        const button = jQuery(this);
        const $article = button.closest('.ispag-article'); // Supposons que chaque article est dans un bloc avec la classe `.ispag-article`
        const articleId = button.data('article-id');
        const nonce = button.data('nonce');

        // Ajoute la classe `is-loading` au bloc article pour le foncer
        $article.addClass('is-loading');

        jQuery.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'ispag_toggle_article_archive',
                article_id: articleId,
                nonce: nonce,
            },
            success: function(response) {
                if (response.success) {
                    // Met à jour le texte et l'icône du bouton
                    const newText = response.data.archive == 1 ? ispag_texts.unarchive : ispag_texts.archive;
                    const newIcon = response.data.archive == 1 ? 'dashicons-backup' : 'dashicons-archive';
                    button.html('<span class="dashicons ' + newIcon + '"></span> ' + newText)
                        .toggleClass('archive-button unarchive-button')
                        .prop('disabled', false);

                    // Recharge la liste des articles ou met à jour l'UI
                    reloadArticleList();
                    reload_bottom_btn();
                } else {
                    alert(response.data.message || 'Error');
                    button.prop('disabled', false);
                }
            },
            error: function() {
                alert('AJAX Error');
                button.prop('disabled', false);
            },
            complete: function() {
                // Retire la classe `is-loading` à la fin de la requête (succès ou erreur)
                $article.removeClass('is-loading');
            }
        });
    });

    // --- Écouteur pour les modifications dans la modal ---
    if (modal) {
        modal.addEventListener('change', function (e) {
            if (e.target.matches('input, textarea, select')) {
// console.log("🔄 [MODAL] Modification détectée dans la modal. Marquage comme 'dirty'.");
                markModalAsDirty();
            }
        });
    }

    // ── Soumission du formulaire d'édition ────────────────────────────────
    // console.log("📝 [FORM] Configuration de la soumission du formulaire d'édition...");
    $(document).on('submit', '.ispag-edit-article-form', function (e) {
        e.preventDefault();
        const form = $(this);
        const formId = form.attr('id');
        const submitBtn = $(`button[type="submit"][form="${formId}"]`);
        const cancelBtn = form.find('button[onclick*="closeIspagModal"]');
        const originalBtnHtml = submitBtn.html();
        const articleId = form.data('article-id');
        const is_secondary = form.data('level-secondary'); // Corrigé (sans le préfixe data- en double)
        // const dealId = getUrlParam('deal_id');
        const dealId = form.data('deal-id');
        const poid = form.data('purchase-id'); // Récupère bien data-purchase-id="2694"
        const formData = new URLSearchParams(form.serialize());
        const is_purchase = form.data('source') === 'purchase' ? 'true' : 'false'; // Récupère bien data-source="purchase"
        const $articleList = getArticleListContainer();

// console.log(`📝 [FORM] Soumission du formulaire pour l'article ${articleId}.`);

        submitBtn.prop('disabled', true).addClass('ispag-btn-loading')
                .html('<span class="dashicons dashicons-update spin"></span> Saving...');
        cancelBtn.prop('disabled', true);
        // showSpinner();

        if (dealId) formData.append('deal_id', dealId);
        if (poid) formData.append('poid', poid);

        const payload = { action: 'ispag_save_article', article_id: articleId || 0 };

// console.log('📝 Données envoyées pour sauvegarde :', payload);
        
        
        for (const [key, value] of formData.entries()) {
            payload[key] = value;
        }

        if (form[0]._changeNotes && form[0]._changeNotes.length > 0) {
            payload['change_notes'] = JSON.stringify(form[0]._changeNotes);
        }

        // console.log(payload);


        $articleList.addClass('is-loading');

        $.post(ajaxurl, payload)
            .done(response => {
                if (!response || !response.success || !response.data) {
                    console.error("❌ Réponse serveur invalide ou manquante :", response);
                    const msg = response && response.data && response.data.message;
                    alert("Error: " + (typeof msg === 'string' && msg ? msg : "Invalid server response."));
                    resetButtons(submitBtn, cancelBtn, originalBtnHtml);
                    $articleList.removeClass('is-loading');
                    return;
                }

                const finalArticleId = articleId || response.data.article_id;
                if (!finalArticleId) {
                    console.error("❌ article_id manquant dans la réponse :", response);
                    alert("Error: Missing article ID.");
                    resetButtons(submitBtn, cancelBtn, originalBtnHtml);
                    $articleList.removeClass('is-loading');
                    return;
                }
// console.log(`✅ [FORM] Article ${finalArticleId} enregistré avec succès.`);

                saveTankData(finalArticleId, is_purchase)
                    .done(() => {
                        if (articleId) {
                            const newDiameter = form.find('select[name="tank[diameter]"]').val();
                            const newPressure = form.find('input[name="tank[max_pressure]"]').val();
                            const newTemp = form.find('input[name="tank[temperature]"]').val();
                            const newInsul = form.find('select[name="tank[InsulationThickness]"]').val();

                            // console.log(`🔄 [FORM] Rechargement de la ligne de l'article ${articleId}...`);

                            $.post(ajaxurl, {
                                action: 'ispag_reload_article_row',
                                is_secondary: is_secondary,
                                article_id: articleId,
                                is_purchase: is_purchase
                            }, function (rowHtml) {
                                // Achat : on ne recharge que l'article modifié (pas toute la liste)
                                const $editedRow = $(`.ispag-article[data-article-id="${articleId}"]`);
                                const isPurchaseEdit = (is_purchase === true || is_purchase === 'true');
                                if (isPurchaseEdit && $editedRow.length && typeof rowHtml === 'string' && rowHtml.trim() !== '') {
                                    const $newRow = $(rowHtml);
                                    $editedRow.replaceWith($newRow);
                                    $newRow.filter('.ispag-article').addClass('is-updated');
                                } else {
                                    reloadArticleList();
                                    reload_bottom_btn();
                                }

                                setTimeout(() => {
                                    const $btnRaccords = jQuery(`.ispag-article[data-id="${articleId}"], .ispag-article[data-article-id="${articleId}"]`).find('#open-tank-fittings-modal');
                                    if ($btnRaccords.length) {
                                        $btnRaccords
                                            .attr('data-tank-diameter', newDiameter)
                                            .attr('data-tank-pression', newPressure)
                                            .attr('data-tank-using-temp', newTemp)
                                            .attr('data-tank-insulation-thickness', newInsul);
                                        // console.log(`✅ [FORM] Mise à jour des données du réservoir pour l'article ${articleId}.`);
                                    }
                                }, 100);

                                attachEditModalEvents();
                                attachViewModalEvents();
                                bindStandardTitleListener();
                                $articleList.removeClass('is-loading');
                                closeIspagModal();
                            });
                        } else {
                            // console.log("🔄 [FORM] Rechargement de la liste des articles (nouvel article).");
                            // Assistant de création de réservoir : la fenêtre reste ouverte pour les étapes suivantes
                            const wizardActive = document.body.classList.contains('ispag-wizard-on');
                            reloadArticleList(wizardActive);
                            if (wizardActive) resetButtons(submitBtn, cancelBtn, originalBtnHtml);
                            $articleList.removeClass('is-loading');
                        }

                        form[0]._changeNotes = [];
                    })
                    .fail(err => {
                        console.error('❌ [FORM] Error while saving des données du réservoir :', err);
                        alert('Error while saving the technical data' + (err && err.message ? ' : ' + err.message : ''));
                        resetButtons(submitBtn, cancelBtn, originalBtnHtml);
                        $articleList.removeClass('is-loading');
                    });
            })
            .fail(err => {
                console.error('❌ [FORM] Error lors de l\'enregistrement de l\'article :', err);
                alert('Error while saving');
                resetButtons(submitBtn, cancelBtn, originalBtnHtml);
                $articleList.removeClass('is-loading');
            });
    });
});

// =============================================
// 4. FONCTIONS POUR LES MODALES "VOIR" ET "ÉDITER"
// =============================================

/**
 * Attache les événements pour les boutons "Voir" (View).
 */
function attachViewModalEvents() {
    // console.log("👁️ [UTIL] Attachement des événements 'Voir'...");

    // Supprimer les anciens écouteurs pour éviter les doublons
    document.querySelectorAll('.ispag-btn-view').forEach(btn => {
        btn.removeEventListener("click", handleViewClick);
        btn.addEventListener("click", handleViewClick);
    });

    /**
     * Gère le clic sur un bouton "Voir".
     */
    function handleViewClick(e) {
        const articleId = e.currentTarget.dataset.articleId;
        const source = $(this).data('source') == 'purchase' ? "purchase" : "project";
        const $btn = $(e.currentTarget);
        const originalHtml = $btn.html();

        // console.log(`👁️ [BUTTON] Clic sur 'Voir' pour l'article ${articleId}. Loading...`);

        // Activer le spinner sur le bouton
        $btn.prop('disabled', true)
           .html('<span class="dashicons dashicons-update spin"></span>')
           .css('opacity', '0.5');

        fetch(ajaxurl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({
                action: 'ispag_load_article_modal',
                article_id: articleId,
                source: source
            })
        })
        .then(res => res.json()) // 👈 On attend du JSON maintenant, plus du texte brut
        .then(response => {
            if (response.success) {
                // console.log(`✅ [BUTTON] Modal 'Voir' chargée pour l'article ${articleId}.`);
// console.log(response.data);
                
                // 1. On injecte le Body dans le conteneur principal
                document.getElementById("ispag-modal-body").innerHTML = response.data.body;
                
                // 2. On injecte le Header au bon endroit (adapte le sélecteur si besoin)
                const headerContent = document.querySelector('#ispag-modal-product #ispag-modal-header-content');

                if (headerContent) {
                    headerContent.innerHTML = response.data.header;
                } else {
                    console.error("Élément #ispag-modal-header-content introuvable dans le DOM.");
                }
                // const headerContainer = document.querySelector(".ispag-modal-header ");
                // if (headerContainer) {
                //     headerContainer.innerHTML = response.data.header;
                // }

                $('body').addClass('modal-open');
                modal.style.display = "block";
            } else {
                console.error("Error lors du chargement :", response.data ? response.data.message : "Unknown error");
            }
        })
        .catch(error => {
            console.error(`❌ [BUTTON] Error lors du chargement de la modal 'Voir' :`, error);
            alert('Error while loading the modal.');
        })
        .finally(() => {

            $btn.prop('disabled', false).html(originalHtml).css('opacity', '1');
            // // Réactiver le bouton natif (e.currentTarget)
            // e.currentTarget.innerHTML = originalHtml; // 👈 Utiliser originalHtml natif
            // e.currentTarget.style.pointerEvents = 'auto';

            // // Réactiver aussi la référence jQuery ($btn) si elle existe
            // if ($btn.length) {
            //     $btn.html(originalHtml)
            //        .prop('pointerEvents', 'auto');
            // }
        });
    }
}

/**
 * Attache les événements pour les boutons "Éditer" (Edit).
 */
function attachEditModalEvents() {
    // console.log("✏️ [UTIL] Attachement des événements 'Éditer'...");

    // Supprimer les anciens écouteurs pour éviter les doublons
    $('.ispag-btn-edit').off('click').on('click', async function (e) {
        e.preventDefault();
        const articleId = $(this).data('article-id');
        const dealId = $(this).data('deal-id');
        const source = $(this).data('source') == 'purchase' ? "purchase" : "project";
        const $btn = $(this);
        const originalHtml = $btn.html();

// console.log('Start editing article for deal : ' + dealId);

// console.log(`✏️ [BUTTON] Clic sur 'Éditer' pour l'article ${articleId} Source ${source}. Loading...`);

        // Attendre que les données soient chargées (si nécessaire)
        if (typeof isDataLoaded !== 'undefined' && !isDataLoaded) {
            if (typeof setIspagTankRestrictionsValue === 'function') {
                try {
                    await setIspagTankRestrictionsValue();
                    if (typeof jQuery !== 'undefined') {
                        await new Promise((resolve) => {
                            if (isDataLoaded) {
                                resolve();
                            } else {
                                jQuery(document).one('ispag:restrictions_loaded', resolve);
                            }
                        });
                    }
                } catch (error) {
                    console.error("❌ [BUTTON] Error lors du chargement des restrictions :", error);
                }
            }
        }

        // Activer le spinner sur le bouton
        $btn.prop('disabled', true)
           .html('<span class="dashicons dashicons-update spin"></span>')
           .css('opacity', '0.5');

        // Charger le formulaire d'édition via AJAX
        $.ajax({
            url: ajaxurl,
            method: 'POST',
            dataType: 'json', // 👈 On indique explicitement à jQuery qu'on attend du JSON
            data: {
                action: 'ispag_load_article_edit_modal',
                article_id: articleId,
                source: source
            },
            success: async function (response) {
                if (response.success) {
                    // console.log(`✅ [BUTTON] Modal 'Éditer' chargée pour l'article ${articleId}. data : ${response.data}`);
                    

                    // 1. Insérer le HTML du Body (le formulaire)
                    $('#ispag-modal-body').html(response.data.body);
                    $('#ispag-modal-header-content').html(response.data.header);
                    $('.ispag-modal-footer').html(response.data.btn);

                    // 2. Insérer le HTML du Header (dans le h2 de la modal)
                    const headerContainer = document.querySelector(".ispag-modal-header h2");
                    if (headerContainer) {
                        headerContainer.innerHTML = response.data.header;
                    }

                    // Sélection jQuery du formulaire
                    const $form = $('#ispag-edit-article-form'); // ou $('.ispag-edit-article-form') si c'est une classe
                    
                    if ($form.length) {
                        // Mettre à jour à la fois l'attribut HTML et la mémoire jQuery :
                        $form.attr('data-deal-id', dealId)
                            .data('deal-id', dealId);
                            
                        
// console.log("✅ data-deal-id appliqué au formulaire :", $form.data('deal-id'));
                    } else {
                        console.warn("⚠️ Formulaire #ispag-edit-article-form introuvable dans le DOM.");
                    }
                    
                    $('body').addClass('modal-open');
                    modal.style.display = "block";

                    const $scrollContainer = $('.ispag-modal-body-scroll');
                    if ($scrollContainer.length) {
                        $scrollContainer.scrollTop(0);
                    }

                    $(document).trigger('ispag_tank_modal_loaded');

                    // Initialiser les champs dynamiques (diamètres, etc.)
                    const $matSel = $('#tank-material');
                    const $diamSel = $('#tank-diameter');

                    if ($matSel.length > 0) {
                        const materialId = $matSel.find(':selected').data('id') || $matSel.val();
                        const currentDiamValue = $diamSel.val();

                        // Mettre à jour les diamètres après un délai
                        setTimeout(async () => {
                            if (typeof updateDiameterDatalistByMaterial === 'function') {
                                await updateDiameterDatalistByMaterial(materialId);
                            }
                        }, 50);

                        // Initialiser le Transport Checker après un délai
                        setTimeout(() => {
                            if (typeof initializeTransportCheckerForModal === 'function') {
                                initializeTransportCheckerForModal();
                            }
                        }, 100);
                    }

                    // Réattacher les événements pour les champs dynamiques
                    attachEditModalEvents();
                    bindStandardTitleListener();

                    // Initialiser les valeurs par défaut du type de réservoir
                    const initTypId = $('#tank-typ option:selected').data('id');
                    if (initTypId && typeof updateTankDefaults === 'function') {
                        updateTankDefaults(initTypId);
                    }

                    // 👇 Émettre l'événement modal_loaded après que tout soit prêt
                    setTimeout(() => {
// console.log('[MODAL] Événement modal_loaded déclenché.');
                        document.dispatchEvent(new CustomEvent('modal_loaded'));
                    }, 500); // Délai pour laisser le temps aux scripts tiers
                } else {
                    console.error("❌ Error de données :", response.data ? response.data.message : "Données incorrectes");
                    alert('Error while loading data.');
                }
            },
            error: function (xhr, status, error) {
                console.error(`❌ [BUTTON] Error lors du chargement du formulaire d'édition :`, error);
                alert('Error while loading the form.');
            },
            complete: function () {
                // Réinitialiser le bouton
                $btn.prop('disabled', false).html(originalHtml).css('opacity', '1');
            }
        });
    });
}

/**
 * Attache un écouteur pour les titres standards (sélection d'un article standard).
 */
function bindStandardTitleListener() {
    // console.log("📌 [UTIL] Attachement de l'écouteur pour les titres standards...");

    const titleInput = document.getElementById('article-title');
    if (!titleInput) {
        // console.warn("⚠️ [UTIL] Champ 'article-title' non trouvé.");
        return;
    }

    titleInput.addEventListener('change', () => {
        const selectedTitle = titleInput.value.trim();
        const articleType = titleInput.dataset.type;
        const options = document.querySelectorAll('#standard-titles option');
        const matchedOption = Array.from(options).find(opt => opt.value === selectedTitle);
        const articleId = matchedOption ? matchedOption.dataset.id : '';

        // console.log(`📌 [UTIL] Titre sélectionné : '${selectedTitle}', type : ${articleType}, articleId : ${articleId}`);

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
            if (data.success) {
                // console.log(`✅ [UTIL] Informations de l'article standard chargées pour l'ID ${articleId}.`);
                const article = data.data['article'];

                // Remplir les champs avec les données de l'article standard
                const descriptionField = document.querySelector('[name="description"]');
                const salesPriceField = document.querySelector('[name="sales_price"]');
                const idArticleStandardField = document.querySelector('[name="IdArticleStandard"]');

                if (descriptionField) descriptionField.value = article['description_ispag'] || '';
                if (salesPriceField) salesPriceField.value = article['sales_price'] || '';
                if (idArticleStandardField) idArticleStandardField.value = article['Id_article_standard'] || '';

                // Mettre à jour la liste des fournisseurs
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
                        if (article.suppliers.length === 1) {
                            supplierField.value = article.suppliers[0];
                        }
                    }
                }
            } else if (data.error) {
                console.error("❌ [UTIL] Error lors de la récupération des informations de l'article standard :", data.error);
                alert(data.error);
            }
        })
        .catch(error => {
            console.error("❌ [UTIL] Network error ou serveur lors de la récupération des informations de l'article standard :", error);
            alert('Network or server error.');
        });
    });
}

// =============================================
// 5. GESTION DES BOUTONS SPÉCIFIQUES (Supprimer, Dupliquer, Convertir)
// =============================================

// --- Bouton "Supprimer" ---
$(document).on('click', '.ispag-btn-delete', async function () {
    const $button   = $(this);
    const $article  = $button.closest('.ispag-article');
    const source    = $button.attr('data-source') ?? '';
    const articleId = $(this).data('article-id');

    // console.log(`🗑️ [BUTTON] Clic sur 'Supprimer' pour l'article ${articleId}. Demande de confirmation...`);

    const confirmed = await ispagConfirm(
        ispag_texts.confirm_delete_article + ' ?',
        {
            labelOk: ispag_texts.delete,
            labelCancel: ispag_texts.cancel,
            danger: true,
        }
    );

    if (!confirmed) {
// console.log("❌ [BUTTON] Suppression annulée par l'utilisateur.");
        return;
    }

    $article.addClass('is-loading');
// console.log(`🔄 [BUTTON] Envoi de la requête de suppression pour l'article ${articleId} - ${source}...`);

    $.post(ajaxurl, {
        action: 'ispag_delete_article',
        article_id: articleId,
        danger: true,
        source: source
    })
    .done(response => {
        if (response.success) {
            // console.log(`✅ [BUTTON] Article ${articleId} supprimé avec succès.`);
            $article.remove();
        } else {
            console.error(`❌ [BUTTON] Error while deleting de l'article ${articleId} :`, response.data.message);
            alert(response.data.message || 'Error while deleting');
            $article.removeClass('is-loading');
        }
    })
    .fail(() => {
        console.error(`❌ [BUTTON] Server error lors de la suppression de l'article ${articleId}.`);
        alert('Server error');
        $article.removeClass('is-loading');
    });
});

// --- Bouton "Dupliquer" ---
document.addEventListener('click', async function (e) {
    const btn = e.target.closest('.ispag-btn-copy');
    if (!btn) return;

    const articleId = btn.dataset.articleId;
    if (!articleId) return;

    // console.log(`📋 [BUTTON] Clic sur 'Dupliquer' pour l'article ${articleId}. Demande de confirmation...`);

    const confirmed = await ispagConfirm(
        ispag_texts.would_you_copy + ' ?',
        {
            labelOk: ispag_texts.replicate,
            labelCancel: ispag_texts.cancel,
            danger: false,
        }
    );

    if (!confirmed) {
// console.log("❌ [BUTTON] Duplication annulée par l'utilisateur.");
        return;
    }

    // showSpinner();
    // console.log(`🔄 [BUTTON] Envoi de la requête de duplication pour l'article ${articleId}...`);

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
            // console.log(`✅ [BUTTON] Article ${articleId} dupliqué avec succès. Rechargement de la liste...`);
            reloadArticleList();
        } else {
            console.error(`❌ [BUTTON] Error while duplicating :`, data.data);
            alert('Error: ' + data.data);
        }
    })
    .catch(error => {
        console.error('❌ [BUTTON] Error lors de la requête de duplication:', error);
        alert('A connection error occurred.');
    })
    .finally(() => {
        hideSpinner();
    });
});
// --- Délégation d'événement pour le bouton "Convertir en commande" (injecté dynamiquement) ---
document.addEventListener("click", async function (e) {
    const convertBtn = e.target.closest("#convert-to-project");
    if (!convertBtn) return; // Si le clic ne provient pas du bouton, on ignore

    const deal_id = convertBtn.dataset.id;

    const confirmed = await ispagConfirm(
        ispag_texts.would_you_convert,
        {
            labelOk: ispag_texts.convert,
            labelCancel: ispag_texts.cancel,
            danger: true,
        }
    );

    if (!confirmed) return;

    const numCommandeEl = document.querySelector('.ispag-inline-edit[data-name="NumCommande"]');
    const customerOrderIdEl = document.querySelector('.ispag-inline-edit[data-name="customer_order_id"]');
    const valNumCommande = numCommandeEl ? numCommandeEl.dataset.value.trim() : "";
    const valCustomerOrder = customerOrderIdEl ? customerOrderIdEl.dataset.value.trim() : "";

    if (valNumCommande === "" || valCustomerOrder === "") {
        console.warn("⚠️ [BUTTON] Numéro de commande ISPAG ou référence client manquante.");
        alert("⚠️ " + ispag_texts.need_order_number);
        if (valNumCommande === "") {
            if (numCommandeEl) numCommandeEl.click();
        } else {
            if (customerOrderIdEl) customerOrderIdEl.click();
        }
        return;
    }

    executeConversion(deal_id);
});

/**
 * Exécute la conversion d'une offre en commande.
 */
function executeConversion(deal_id) {
    const btn = document.getElementById("convert-to-project");
    if (!btn) return;

    btn.innerHTML = "🔄 " + ispag_texts.converting + "...";
    btn.disabled = true;

    fetch(ispagVars.ajaxurl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ action: 'ispag_convert_to_project', id: deal_id })
    })
    .then(res => res.json())
    .then(response => {
        if (response.success) {
            const url = new URL(window.location);
            url.searchParams.delete("qotation");
            window.location.href = url.toString();
        } else {
            console.error("❌ [CONVERSION] Server error :", response.data);
            alert("Error: " + response.data);
            if (btn) {
                btn.innerHTML = ispag_texts.transform_to_project;
                btn.disabled = false;
            }
        }
    })
    .catch(error => {
        console.error("🔥 [CONVERSION] Error AJAX :", error);
        alert("AJAX error: " + error.message);
        if (btn) {
            btn.innerHTML = ispag_texts.transform_to_project;
            btn.disabled = false;
        }
    });
}


jQuery(document).on('click', '.ispag-delete-project-btn', async function () {
    const dealId = jQuery(this).data('deal-id');

    // console.log(`🗑️ [PROJECT] Clic sur 'Supprimer le projet' pour le deal ${dealId}. Demande de confirmation...`);

    const confirmed = await ispagConfirm(ispag_texts.txt_delete_project + ' ?', {
        labelOk: ispag_texts.delete,
        labelCancel: ispag_texts.cancel,
        danger: true,
    });

    if (!confirmed) {
        // console.log("❌ [PROJECT] Suppression annulée par l'utilisateur.");
        return;
    }

    // console.log(`🔄 [PROJECT] Envoi de la requête de suppression pour le deal ${dealId}...`);

    jQuery.ajax({
        url: ajaxurl,
        type: 'POST',
        data: { action: 'ispag_delete_project', deal_id: dealId },
        success: function (response) {
            // console.log(`✅ [PROJECT] Projet ${dealId} supprimé avec succès.`);
            alert(response.data.message || 'Project deleted');
            window.close();
        },
        error: function () {
            console.error(`❌ [PROJECT] Error while deleting du projet ${dealId}.`);
            alert(ispag_texts.txt_error_deleting_project + ".");
        }
    });
});

// const addArticleBtn = document.getElementById('ispag-add-article');
// if (addArticleBtn) {
//     addArticleBtn.addEventListener('click', function () {
//         const source = this.dataset.source;
//         const dealId = this.dataset.dealId;
//         const tankConfigurator = this.dataset.tankConfigurator;
//         let cardTitel = $(this).attr('data-card-titel') ?? '';
//         const typeId = $(this).attr('data-id') ?? 0;
//         const poid = this.dataset.poid;
//         const projectDiscount = $('.project_discount .stat-value').text().replace('%', '').trim();

//         const originalHtml = this.innerHTML;
//         this.innerHTML = '<span class="dashicons dashicons-update spin"></span> Loading...';
//         this.disabled = true;

//         let action;

// // console.log(tankConfigurator);

//         if(tankConfigurator){
//             action = 'ispag_select_tank_type';
//         }
//         else{
//             cardTitel = ispag_texts.new_article;
//             action = 'ispag_open_new_article_modal';
//         }

// // console.log(action);

//         fetch(ajaxurl, {
//             method: 'POST',
//             headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
//             body: new URLSearchParams({ action: action })
//         })
//         .then(res => res.text())
//         .then(html => {
            
//             document.getElementById("ispag-modal-body").innerHTML = html;
//             document.getElementById("ispag-modal-header-content").innerHTML = '';

//             $('#ispag-product-modal-content h2')
//                 .text(cardTitel)               // Modifie le texte
//                 .attr('data-id', typeId);     // Ajoute l'attribut data-id avec la valeur de typeId


//             $('body').addClass('modal-open');
//             modal.style.display = "block";
//         })
//         .catch(error => {
//             console.error("Error lors du chargement :", error);
//             alert("An error occurred while loading.");
//         })
//         .finally(() => {
//             // Réactiver le bouton et restaurer son contenu original
//             this.innerHTML = originalHtml;
//             this.disabled = false;
//         });
//     });
// } else {
//     console.warn("⚠️ [BUTTON] Bouton 'Ajouter un article' non trouvé.");
// }
// On écoute les clics sur l'ensemble du document (ou sur un conteneur parent fixe)
document.addEventListener('click', function (event) {
    // On vérifie si l'élément cliqué ou l'un de ses parents est notre bouton
    const addArticleBtn = event.target.closest('#ispag-add-article');
    
    if (!addArticleBtn) return; // Si ce n'est pas notre bouton, on ne fait rien

    const source = addArticleBtn.dataset.source;
    const dealId = addArticleBtn.dataset.dealId;
    const tankConfigurator = addArticleBtn.dataset.tankConfigurator;
    let cardTitel = $(addArticleBtn).attr('data-card-titel') ?? '';
    const typeId = $(addArticleBtn).attr('data-id') ?? 0;
    const poid = addArticleBtn.dataset.poid;
    const projectDiscount = $('.project_discount .stat-value').text().replace('%', '').trim();

    const originalHtml = addArticleBtn.innerHTML;
    addArticleBtn.innerHTML = '<span class="dashicons dashicons-update spin"></span> Loading...';
    addArticleBtn.disabled = true;

    let action;

    if (tankConfigurator) {
        action = 'ispag_select_tank_type';
    } else {
        cardTitel = ispag_texts.new_article;
        action = 'ispag_open_new_article_modal';
    }

    fetch(ajaxurl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ action: action })
    })
    .then(res => res.text())
    .then(html => {
        document.getElementById("ispag-modal-body").innerHTML = html;
        document.getElementById("ispag-modal-header-content").innerHTML = '';

        $('#ispag-product-modal-content h2')
            .text(cardTitel)
            .attr('data-id', typeId);

        $('body').addClass('modal-open');
        modal.style.display = "block";
    })
    .catch(error => {
        console.error("Error lors du chargement :", error);
        alert("An error occurred while loading.");
    })
    .finally(() => {
        // Réactiver le bouton et restaurer son contenu original
        addArticleBtn.innerHTML = originalHtml;
        addArticleBtn.disabled = false;
    });
});
// =============================================
// ÉCOUTEUR UNIQUE POUR TOUS LES .ispag-type-card (DÉLÉGATION)
// =============================================
$(document).on('click', '.ispag-type-card', function() {
    const typeId = $(this).attr('data-id');
    const cardTitel = $(this).attr('data-card-titel');
    const selectorType = $(this).attr('data-selector-type');
    const projectDiscount = $('.project_discount .stat-value').text().replace('%', '').trim();
    
    const isAdmin = $(this).data('is-admin');
    // const isAdmin = false;

    if (!typeId) return;

    let action;
    // if (isAdmin && typeId == 1 && selectorType == 'product_type') {
    //     action = 'ispag_select_tank_type';
    // } else 
        if (isAdmin && selectorType == 'tank_type') {
        action = 'ispag_tank_conception';
    } else {
        action = 'ispag_load_article_create_modal';
    }

// console.log(isAdmin);
// console.log(typeId);
// console.log(selectorType);
// console.log(action);

    // Retirer la classe 'selected' de toutes les cartes
    $('.ispag-type-card').removeClass('selected');
    $(this).addClass('selected');

    // Récupérer dealId et poid depuis le bouton "Ajouter un article"
    const addArticleBtn = document.getElementById('ispag-add-article');
    const dealId = addArticleBtn ? addArticleBtn.dataset.dealId : '';
    const poid = addArticleBtn ? addArticleBtn.dataset.poid : '';
    const source = window.location.href.includes("poid=") ? "purchase" : "project";
    

    fetch(ajaxurl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({
            action: action,
            type_id: typeId,
            deal_id: dealId || '',
            poid: poid || '',
            source: source
        })
    })
    .then(res => res.json()) // 👈 On récupère du JSON
    .then(response => {
        if (!response.success) {
            console.error("Server error");
            return;
        }
// console.log(response.data);
        const { header, body, buttons } = response.data; // 👈 On extrait les éléments

        

        // On simplifie les sélecteurs (sans les ">" trop stricts)
        const $elementsToFade = $('#ispag-product-modal-content .ispag-type-grid, #ispag-product-modal-content .ispag-modal-subtitle');

        // On définit la fonction qui injecte le HTML et configure la modale
        const updateModalContent = () => {
            $('.ispag-modal-header h2')
                .html(header)             // Modifie le texte
                .attr('data-id', typeId);     // Ajoute l'attribut data-id avec la valeur de typeId

            const container = document.getElementById("new-article-form-container");
            if (container) {
                container.innerHTML = body;
                container.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }

            // On reporte le rabais du projet
            const discountInput = document.querySelector('[name="discount"]');
            discountInput && (discountInput.value = projectDiscount || 0);

            console.log(buttons);
            console.log('remove footer');
            $('.ispag-modal-footer').remove();
            // Créer et ajouter le footer à la fin de .ispag-modal-content
            const modalFooter = $('<div>', {
                class: 'ispag-modal-footer',
                html: buttons
            });
            console.log('NEW footer');
            console.log(buttons);

            $('#ispag-modal-product .ispag-modal-content').append(modalFooter);

            // 👇 Déclencher l'événement pour initialiser les champs
            if (action == 'ispag_tank_conception') {
                // Supprimer un éventuel footer existant
                $('.ispag-modal-footer').remove();

                $('#tank-typ').val(typeId);

                // Créer le footer
                const footer = $('<div>', {
                    class: 'ispag-modal-footer',
                    style: 'margin-top: 20px; display: flex; justify-content: space-between; padding: 15px; border-top: 1px solid #eee;'
                });

                // Bouton "Previous"
                const previousBtn = $('<div>', {
                    class: 'ispag-type-card ',
                    'data-id': '1',
                    'data-card-titel': 'Custom tank',
                    'data-selector-type': 'product_type',
                    'data-is-admin': '1',
                    style: 'cursor: pointer; flex: 1; margin-right: 10px;',
                    html: `<span class="ispag-type-label">${ispag_texts.previous || 'Previous'}</span>`
                });

                // Bouton "Valid"
                const validBtn = $('<button>', {
                    class: 'ispag-btn ispag-btn-primary',
                    style: 'flex: 1;',
                    text: ispag_texts.valid || 'Valid'
                }).on('click', function() {
                    // console.log('✅ [BUTTON] Bouton "Valider" cliqué pour le type ID:', typeId);

                    const form = $('#tank-conception-fields');
                    const dealId = getUrlParam('deal_id');
                    const poid = getUrlParam('poid');
                    const formData = new URLSearchParams(form.serialize());
                    const is_purchase = poid ? 'true' : 'false';
                    const articleId = form.data('article-id') ?? 0;
                    const $articleList = getArticleListContainer();

                    if (dealId) formData.append('deal_id', dealId);
                    if (poid) formData.append('poid', poid);

                    const payload = { action: 'ispag_save_article', article_id: articleId || 0 };
                    for (const [key, value] of formData.entries()) {
                        payload[key] = value;
                    }

                    if (form[0]._changeNotes && form[0]._changeNotes.length > 0) {
                        payload['change_notes'] = JSON.stringify(form[0]._changeNotes);
                    }
                    payload['type'] = 1;

                    
// console.log(payload);

                    $articleList.addClass('is-loading');

                    $.post(ajaxurl, payload)
                        .done(response => {
                            if (!response || !response.success || !response.data) {
                                console.error("❌ Réponse serveur invalide ou manquante :", response);
                                alert("Error: Invalid server response.");
                                return;
                            }

                            const finalArticleId = articleId || response.data.article_id;
                            if (!finalArticleId) {
                                console.error("❌ article_id manquant dans la réponse :", response);
                                alert("Error: Missing article ID.");
                                return;
                            }
                            // console.log(`✅ [FORM] Article ${finalArticleId} enregistré avec succès.`);

                            saveTankData(finalArticleId, is_purchase)
                                .done(() => {
                                    if (articleId) {
// console.log("[DEBUG] Affiche formulaire isolation");
                                        renderInsulationForm(articleId);
                                    } else {
                                        // console.log("🔄 [FORM] Rechargement de la liste des articles (nouvel article).");
                                        reloadArticleList();
                                        $articleList.removeClass('is-loading');
                                    }

                                    form[0]._changeNotes = [];
                                })
                                .fail(err => {
                                    console.error('❌ [FORM] Error while saving des données du réservoir :', err);
                                    alert('Error while saving the technical data' + (err && err.message ? ' : ' + err.message : ''));
                                    resetButtons(submitBtn, cancelBtn, originalBtnHtml);
                                    $articleList.removeClass('is-loading');
                                });
                        })
                        .fail(err => {
                            console.error('❌ [FORM] Error lors de l\'enregistrement de l\'article :', err);
                            alert('Error while saving');
                            resetButtons(submitBtn, cancelBtn, originalBtnHtml);
                            $articleList.removeClass('is-loading');
                        });
                });

                // Ajouter les boutons au footer
                footer.append(previousBtn, validBtn);

                // Ajouter le footer à la modale
                $('#ispag-product-modal-content').append(footer);

                $(document).trigger('ispag_new_tank_modal_loaded');
            } 
            // else {
            //     $('.ispag-modal-footer').remove();
            // }

            attachEditModalEvents();
            bindStandardTitleListener();
        };

        // Si les éléments à masquer existent, on fait l'animation puis on met à jour
        if ($elementsToFade.length > 0) {
            $elementsToFade.fadeOut(150, updateModalContent);
        } else {
            // Si pour une raison quelconque ils n'existent pas ou sont déjà masqués, on met à jour directement
            updateModalContent();
        }
    });
});

jQuery(document).on('click', '#generate-purchase-requests', async function () {
    const $btn = jQuery(this);
    const deal_id = $btn.data('deal-id');
    const msgBox = document.getElementById('ispag-bulk-message');
    const originalHtml = $btn.html();

    // console.log(`🛒 [BULK] Clic sur 'Générer les demandes d'achat' pour le deal ${deal_id}. Demande de confirmation...`);

    const confirmed = await ispagConfirm(ispag_texts.generate_purchase, {
        labelOk: ispag_texts.yes,
        labelCancel: ispag_texts.cancel,
        danger: true,
    });

    if (!confirmed) {
        // console.log("❌ [BULK] Génération annulée par l'utilisateur.");
        return;
    }

    $btn.prop('disabled', true).css('opacity', '0.7')
        .html('<span class="dashicons dashicons-update spin"></span> Loading...');

    // console.log(`🔄 [BULK] Envoi de la requête de génération pour le deal ${deal_id}...`);

    jQuery.post(ispagVars.ajaxurl, {
        action: 'ispag_generate_purchase_requests',
        deal_id: deal_id
    }, function (response) {
        if (response.success) {
// console.log(`✅ [BULK] Demandes d'achat générées avec succès pour le deal ${deal_id}.`);
            $btn.remove();
            // msgBox.textContent = response.data.message;
            // msgBox.style.display = 'block';
            // msgBox.style.backgroundColor = '#d4edda';
            // msgBox.style.color = '#155724';
            // msgBox.style.border = '1px solid #c3e6cb';
            // setTimeout(() => { msgBox.style.display = 'none'; }, 1000);
        } else {
            console.error(`❌ [BULK] Error lors de la génération :`, response.data?.message);
            $btn.prop('disabled', false).css('opacity', '1').html(originalHtml);
            msgBox.textContent = response.data?.message || 'Unknown error';
            msgBox.style.display = 'block';
            msgBox.style.backgroundColor = '#f8d7da';
            msgBox.style.color = '#721c24';
            msgBox.style.border = '1px solid #f5c6cb';
        }
        if (typeof reloadArticleList === 'function') reloadArticleList();
    });
});
// =============================================
// 6. GESTION DES ACTIONS EN MASSE (CORRIGÉ POUR CHARGEMENT DYNAMIQUE)
// =============================================

document.addEventListener('DOMContentLoaded', function () {
    const bulkDiv = document.querySelector('.ispag-bulk-actions');
    if (bulkDiv) {
        bulkDiv.style.display = 'none'; // Masqué par défaut au démarrage
    }
});

// Utilisation de la délégation d'événements sur le document global
document.addEventListener('change', function (event) {
    const bulkDiv = document.querySelector('.ispag-bulk-actions');
    const selectAll = document.getElementById('select-all-articles');
    const container = document.querySelector('#display_article_page');

    if (!bulkDiv || !container) return;

    // 1. Si on coche / décoche "Sélectionner tout"
    if (event.target && event.target.id === 'select-all-articles') {
        const currentCheckboxes = container.querySelectorAll('.ispag-article-checkbox');
        currentCheckboxes.forEach(cb => cb.checked = event.target.checked);
        bulkDiv.style.display = event.target.checked ? 'block' : 'none';
    }

    // 2. Si on coche / décoche un article individuel
    if (event.target && event.target.matches('.ispag-article-checkbox')) {
        const currentCheckboxes = container.querySelectorAll('.ispag-article-checkbox');
        if (selectAll) {
            selectAll.checked = [...currentCheckboxes].every(c => c.checked);
        }
        const hasChecked = [...currentCheckboxes].some(c => c.checked);
        bulkDiv.style.display = hasChecked ? 'block' : 'none';
    }
});


// =============================================
// FONCTION UTILITAIRE : Masquer la barre bulk et réinitialiser le bouton
// =============================================
function hideBulkActions() {
    const bulkDiv = document.querySelector('.ispag-bulk-actions');
    const selectAll = document.getElementById('select-all-articles');
    const container = document.querySelector('#display_article_page');
    const applyBulkButton = document.getElementById('apply-bulk-update');

    // 1. Masquer le bloc
    if (bulkDiv) {
        bulkDiv.style.display = 'none';
    }
    
    // 2. Décocher "Sélectionner tout" et les articles
    if (selectAll) {
        selectAll.checked = false;
    }
    if (container) {
        container.querySelectorAll('.ispag-article-checkbox').forEach(cb => cb.checked = false);
    }

    // 3. Réinitialiser le bouton d'action en masse (spinner et état désactivé)
    if (applyBulkButton) {
        applyBulkButton.disabled = false;
        applyBulkButton.classList.remove('ispag-btn-loading');
        
        const spinnerIcon = applyBulkButton.querySelector('.ispag-btn-spinner-icon');
        const btnLabel = applyBulkButton.querySelector('.ispag-btn-label');
        
        if (spinnerIcon) {
            spinnerIcon.style.display = 'none';
            spinnerIcon.classList.remove('spin');
        }
        if (btnLabel) {
            btnLabel.style.display = 'inline';
        }
    }
}

// =============================================
// GESTION DES ACTIONS EN MASSE (Délégation d'événements)
// =============================================
document.addEventListener('change', function (event) {
    const bulkDiv = document.querySelector('.ispag-bulk-actions');
    const selectAll = document.getElementById('select-all-articles');
    const container = document.querySelector('#display_article_page');

    if (!bulkDiv || !container) return;

    // 1. Si on coche / décoche "Sélectionner tout"
    if (event.target && event.target.id === 'select-all-articles') {
        const currentCheckboxes = container.querySelectorAll('.ispag-article-checkbox');
        currentCheckboxes.forEach(cb => cb.checked = event.target.checked);
        bulkDiv.style.display = event.target.checked ? 'block' : 'none';
    }

    // 2. Si on coche / décoche un article individuel
    if (event.target && event.target.matches('.ispag-article-checkbox')) {
        const currentCheckboxes = container.querySelectorAll('.ispag-article-checkbox');
        if (selectAll) {
            selectAll.checked = [...currentCheckboxes].every(c => c.checked);
        }
        const hasChecked = [...currentCheckboxes].some(c => c.checked);
        bulkDiv.style.display = hasChecked ? 'block' : 'none';
    }
});


// Initialisation des toggles tristate (en déléguant ou en l'appelant après l'injection AJAX de vos articles)
function initTristateToggle(label) {
    // Si déjà initialisé, on ne réattache pas les événements
    if (label.dataset.tristateInit === 'true') return;
    label.dataset.tristateInit = 'true';

    const checkbox = label.querySelector('input[type="checkbox"]');
    if (!checkbox) return;

    // État initial : neutre
    label.dataset.state = 'unchanged';
    checkbox.checked = false;
    checkbox.indeterminate = true;

    // Attacher l'événement au clic sur le label en ciblant spécifiquement la logique
    label.addEventListener('click', function (event) {
        // Empêcher le comportement natif de bascule automatique du label vers le checkbox
        event.preventDefault();

        const currentState = label.dataset.state;
        let nextState;

        // Cycle des états : unchanged -> checked -> unchecked -> unchanged
        if (currentState === 'unchanged') {
            nextState = 'checked';
        } else if (currentState === 'checked') {
            nextState = 'unchecked';
        } else {
            nextState = 'unchanged';
        }

        // 1. Mise à jour du dataset sur le label (pour le CSS)
        label.dataset.state = nextState;

        // 2. Mise à jour explicite des propriétés de la checkbox
        if (nextState === 'unchanged') {
            checkbox.checked = false;
            checkbox.indeterminate = true;
        } else if (nextState === 'checked') {
            checkbox.checked = true;
            checkbox.indeterminate = false;
        } else { // unchecked
            checkbox.checked = false;
            checkbox.indeterminate = false;
        }

        // 3. Déclencher un événement 'change' si d'autres scripts réagissent à cette case
        checkbox.dispatchEvent(new Event('change', { bubbles: true }));
    });
}
 
// Appliquer aux éléments déjà présents ET s'assurer de les initialiser si rechargés dynamiquement
document.querySelectorAll('.ispag-toggle-chip').forEach(initTristateToggle);

// =============================================
// ECOUTEUR DU BOUTON D'APPLICATION EN MASSE
// =============================================
document.addEventListener('click', function (event) {
    const applyBulkButton = event.target.closest('#apply-bulk-update');
    if (!applyBulkButton) return;
    // Fiche achat : ses actions groupées sont gérées par ispag-achats (details-achat.js)
    if (applyBulkButton.closest('.ispag-bulk-actions[data-achat-id]')) return;

    event.preventDefault();

    const selectedIds = [...document.querySelectorAll('.ispag-article-checkbox:checked')].map(cb => cb.dataset.articleId);

    if (selectedIds.length === 0) {
        console.warn("⚠️ [BULK] No article selected.");
        alert('No article selected');
        return;
    }

    const spinnerIcon = applyBulkButton.querySelector('.ispag-btn-spinner-icon');
    const btnLabel = applyBulkButton.querySelector('.ispag-btn-label');

    function setButtonLoading(isLoading) {
        applyBulkButton.disabled = isLoading;
        applyBulkButton.classList.toggle('ispag-btn-loading', isLoading);
        if (spinnerIcon) {
            spinnerIcon.style.display = isLoading ? 'inline-block' : 'none';
            spinnerIcon.classList.toggle('spin', isLoading);
        }
        if (btnLabel) {
            btnLabel.style.display = isLoading ? 'none' : 'inline';
        }
    }

    setButtonLoading(true);

    const data = {
        action: 'ispag_bulk_update_articles',
        articles: selectedIds,
        deal_id: document.getElementById('deal-id')?.value,
        date_depart: document.getElementById('bulk-date-depart')?.value,
        date_eta: document.getElementById('bulk-date-eta')?.value,
        livre_date: document.getElementById('bulk-livre-date')?.value,
        invoiced_date: document.getElementById('bulk-invoiced-date')?.value,
        discount: document.getElementById('bulk-discount')?.value,
        _ajax_nonce: ispag_texts.nonce
    };

    const demandeOk = document.getElementById('bulk-demande-ok');
    if (demandeOk && !demandeOk.indeterminate) data.demande_ok = demandeOk.checked ? 1 : 0;

    const drawingOk = document.getElementById('bulk-drawing-ok');
    if (drawingOk && !drawingOk.indeterminate) data.drawing_ok = drawingOk.checked ? 1 : 0;

    fetch(ispag_texts.ajax_url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams(data)
    })
    .then(res => {
        const contentType = res.headers.get("content-type");
        if (contentType && contentType.includes("application/json")) return res.json();
        throw new Error('Invalid server response.');
    })
    .then(response => {
        if (response.success) {
            setTimeout(() => {
                hideBulkActions();
                if (typeof reloadArticleList === 'function') {
                    reloadArticleList();
                }
            }, 50);
        } else {
            console.error("❌ [BULK] Error lors des modifications en masse :", response.data?.message);
            setButtonLoading(false);
        }
    })
    .catch(error => {
        setButtonLoading(false);
        console.error('❌ [BULK] Error lors de la requête fetch :', error);
    });
});
// =============================================
// 7. UTILITAIRES (Spinner, Rechargement, etc.)
// =============================================

/**
 * Affiche le spinner de chargement.
 */
function showSpinner() {
    // console.log("🌀 [UTIL] Affichage du spinner...");
    const spinner = document.getElementById('ispag-loading-spinner');
    if (spinner) {
        $('.ispag-article-list-overlay').show();
    }
}

/**
 * Masque le spinner de chargement.
 */
function hideSpinner() {
    // console.log("🌀 [UTIL] Masquage du spinner...");
    const spinner = document.getElementById('ispag-loading-spinner');
    if (spinner) {
        $('.ispag-article-list-overlay').hide();
    }
}

/**
 * Récupère le conteneur de la liste des articles.
 */
function getArticleListContainer() {
    const container = $('#display_ispag_article_list');
    // console.log("📦 [UTIL] Récupération du conteneur de la liste des articles :", container.length ? "Trouvé" : "Non trouvé");
    return container;
}
/**
 * Écouteur d'événement pour déclencher le rechargement des pièces jointes
 */
const eventsToListen = ['ispag:refresh-attachments', 'ispag:delete-attachments', 'ispag:attachment-uploaded', 'ispag:tank-updated'];

eventsToListen.forEach(eventName => {
    document.addEventListener(eventName, function (e) {
        // console.log(`🔄 [EVENT] Événement détecté : ${eventName}`, e.detail);
        reloadArticleList(); // ou reloadAttachmentList() selon ton besoin
    });
});

jQuery(document).on('ispag:tank-updated', function(e, tankId) {
    // Insérez ici votre appel AJAX pour recharger le bloc HTML de l'article/réservoir concerné, 
    // exactement comme vous le faites pour les pièces jointes avec ispag_refresh_attachments.
});
/**
 * Recharge la liste des articles pour un deal donné.
 */
function reloadArticleList(keepModal = false) {
    const container = document.querySelector('.ispag-articles-list');
    const deal_id = container ? container.getAttribute('data-deal-id') : null;

    if (!deal_id) {
        console.warn("⚠️ [UTIL] Impossible de trouver le deal_id dans l'élément .ispag-articles-list");
        return;
    }

    // console.log(`🔄 [UTIL] Rechargement de la liste des articles pour le deal ${deal_id}...`);

    if (!keepModal) closeIspagModal();
    const $listContainer = getArticleListContainer();
    $listContainer.addClass('is-reloading');

    $.ajax({
        url: ajaxurl,
        type: 'POST', 
        data: { action: 'ispag_reload_article_list', deal_id: deal_id },
        success: function (response) {
            // console.log(`✅ [UTIL] Liste des articles rechargée pour le deal ${deal_id}.`);
            $('.ispag-articles-list').html(response);
            reload_bottom_btn();
            attachViewModalEvents();
            attachEditModalEvents();
            bindStandardTitleListener();
            reloadProjectStats();
            if (typeof refreshCoefNotice === 'function') {
                refreshCoefNotice(deal_id);
            }
            document.dispatchEvent(new CustomEvent('articles_loaded'));
        },
        error: function (error) {
            console.error('❌ [UTIL] Error lors du rechargement des articles :', error);
        },
        complete: function () {
            hideSpinner();
            $listContainer.removeClass('is-reloading');
        }
    });
}

/**
 * Recharge le bouton "Générer les demandes d'achat" en bas de page.
 */
function reload_bottom_btn() {
    const deal_id = getUrlParam('deal_id');
    if (!deal_id) {
        console.warn("⚠️ [UTIL] deal_id non trouvé dans reload_bottom_btn().");
        return;
    }

    // console.log(`🔄 [UTIL] Rechargement du bouton du bas pour le deal ${deal_id}...`);

    const oldBtn = document.getElementById('generate-purchase-requests');
    const requestId = window.lastBtnRequestId = (window.lastBtnRequestId || 0) + 1;

    fetch(ajaxurl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=ajax_get_generate_po_button&deal_id=' + encodeURIComponent(deal_id)
    })
    .then(res => res.text())
    .then(text => {
        try {
            const data = JSON.parse(text);
            if (requestId !== (window.lastBtnRequestId || 0)) {
                // console.log("⏭️ [UTIL] Requête obsolète, ignorée.");
                return;
            }
            if (data.success && data.data.trim()) {
                // console.log("✅ [UTIL] Bouton rechargé avec succès.");
                if (oldBtn) oldBtn.remove();
                const anchor = document.getElementById('ispag-add-article');
                if (anchor) anchor.insertAdjacentHTML('afterend', data.data);
            }
        } catch (e) {
            console.error("❌ [UTIL] Error JSON dans reload_bottom_btn :", e);
            console.error("Réponse reçue :", text);
        }
    })
    .catch(err => console.error("❌ [UTIL] Error Fetch dans reload_bottom_btn :", err));
}

/**
 * Recharge les statistiques du projet.
 */
function reloadProjectStats() {
    const container = document.querySelector('.ispag-articles-list');
    const deal_id = container ? container.getAttribute('data-deal-id') : null;

    if (!deal_id) {
        console.warn("⚠️ [UTIL] deal_id non trouvé dans reloadProjectStats().");
        return;
    }

    // console.log(`🔄 [UTIL] Rechargement des statistiques du projet pour le deal ${deal_id}...`);

    jQuery.ajax({
        url: ajaxurl,
        type: 'POST',
        data: { action: 'ispag_display_deal_stats', deal_id: deal_id },
        success: function (response) {
            if (response.success && response.data && response.data.html) {
                // console.log("✅ [UTIL] Statistiques du projet rechargées.");
                $("#ispag_project_stat").replaceWith(response.data.html);
                ispagApplyStatsCollapsed();
            } else {
                console.error("❌ [UTIL] Réponse AJAX invalide ou erreur renvoyée.", response);
            }
        },
        error: function (error) {
            console.error("❌ [UTIL] Error lors du rechargement des statistiques du projet :", error);
        }
    });
}

/**
 * Récupère un paramètre d'URL (query, route spécifique ou dernier segment).
 * @param {string} key - Le nom du paramètre.
 * @returns {string|null} - La valeur du paramètre ou null.
 */
function getUrlParam(key) {
    // 1. Paramètre de requête (?deal_id=123 ou ?key=123)
    const queryValue = new URLSearchParams(window.location.search).get(key);
    if (queryValue) {
        return queryValue;
    }

    // Nettoyage des segments du pathname (retire les slashes vides)
    const segments = window.location.pathname.split('/').filter(Boolean);

    // 2. Recherche après un segment clé (ex: /project-detail/123 ou /projectdetail/123)
    const keyIndex = segments.findIndex(seg => 
        seg.toLowerCase().includes('project') || seg.toLowerCase() === key.toLowerCase()
    );
    
    if (keyIndex !== -1 && keyIndex + 1 < segments.length) {
        const candidate = segments[keyIndex + 1];
        if (!isNaN(candidate)) return candidate;
    }

    // 3. Fallback : renvoie le dernier segment de l'URL s'il s'agit d'un ID numérique
    const lastSegment = segments[segments.length - 1];
    if (lastSegment && !isNaN(lastSegment)) {
        return lastSegment;
    }

    console.warn(`⚠️ [UTIL] Paramètre '${key}' non trouvé dans l'URL.`);
    return null;
}

// Initialisation du compteur de requêtes pour éviter les conflits
window.lastBtnRequestId = window.lastBtnRequestId || 0;

// =============================================
// 8. MODAL DE CONFIRMATION STYLISÉE
// =============================================

/**
 * Remplace window.confirm() par une modal stylisée.
 * @param {string} message - Le message à afficher.
 * @param {Object} options - Options pour les boutons (labelOk, labelCancel, danger).
 * @returns {Promise<boolean>} - Résout avec true si l'utilisateur confirme, false sinon.
 */
function ispagConfirm(message, options = {}) {
    // console.log(`💬 [CONFIRM] Affichage de la modal de confirmation : "${message}"`);

    return new Promise((resolve) => {
        const {
            labelOk = ispag_texts?.continue || "Continuer",
            labelCancel = ispag_texts?.cancel || 'Annuler',
            danger = false,
        } = options;

        // Créer l'overlay et la boîte de confirmation
        const overlay = document.createElement('div');
        overlay.className = 'ispag-confirm-overlay';
        overlay.innerHTML = `
            <div class="ispag-confirm-box">
                <p>${message}</p>
                <div class="ispag-confirm-actions">
                    <button class="ispag-btn ispag-btn-grey-outlined js-confirm-cancel">${labelCancel}</button>
                    <button class="ispag-btn ${danger ? 'ispag-btn-danger' : 'ispag-btn-danger-outlined'} js-confirm-ok">${labelOk}</button>
                </div>
            </div>
        `;

        let resolved = false;
        const close = (result) => {
            if (resolved) return;
            resolved = true;
            document.removeEventListener('keydown', onKey);
            overlay.remove();
            // console.log(`💬 [CONFIRM] Modal de confirmation fermée avec résultat : ${result ? 'OK' : 'Annulé'}`);
            resolve(result);
        };

        const onKey = (e) => {
            if (e.key === 'Escape') close(false);
        };

        // Ajouter les écouteurs
        overlay.querySelector('.js-confirm-ok').addEventListener('click', () => close(true));
        overlay.querySelector('.js-confirm-cancel').addEventListener('click', () => close(false));
        overlay.addEventListener('click', (e) => { if (e.target === overlay) close(false); });
        document.addEventListener('keydown', onKey);

        // Afficher la modal
        document.body.appendChild(overlay);
        overlay.querySelector('.js-confirm-cancel').focus();
    });
}

// ── Checkbox "prix manuel" ────────────────────────────────────────────
// console.log("💰 [CHECKBOX] Configuration des checkbox 'prix manuel'...");
$(document).on('change', '.js-is-manual-checkbox', function () {
    const $container = $(this).closest('.detail-block');
    const $priceInput = $container.find('.js-sales-price-input');
    const isChecked = $(this).is(':checked');

    // console.log(`💰 [CHECKBOX] Checkbox 'prix manuel' ${isChecked ? 'cochée' : 'décochée'}.`);

    if (isChecked) {
        $priceInput.prop('disabled', false).focus().css('background-color', '#fff');
    } else {
        $priceInput.prop('disabled', true).css('background-color', '#f0f0f0');
    }
});

/**
 * Remet les boutons du formulaire en état après un échec, pour pouvoir refaire un essai sans recharger la page.
 */
function resetButtons(btn, cancel, oldHtml) {
    btn.prop('disabled', false).html(oldHtml).removeClass('ispag-btn-loading');
    cancel.prop('disabled', false);
    jQuery('.is-loading').removeClass('is-loading');
}

// --- Blocs articles (projets et achats) : menu ⋯, clic sur la ligne, surbrillance de la sélection ---
function ispagCloseMoreMenus() {
    $('.ispag-more.is-open').removeClass('is-open').find('.ispag-more-toggle').attr('aria-expanded', 'false');
    $('.ispag-more-menu.is-fixed').removeClass('is-fixed').css({ top: '', left: '' });
}
$(document).on('click', '.ispag-more-toggle', function (e) {
    e.stopPropagation();
    const $more = $(this).closest('.ispag-more');
    const open = !$more.hasClass('is-open');
    ispagCloseMoreMenus();
    if (!open) { return; }
    ispagMoreOpenedAt = Date.now();
    $more.addClass('is-open');
    $(this).attr('aria-expanded', 'true');
    // Menu en position fixe : il n'est pas coupé par le conteneur (dernier article de la liste)
    const $menu = $more.find('.ispag-more-menu');
    const r = this.getBoundingClientRect();
    const mh = $menu.outerHeight(), mw = $menu.outerWidth();
    const top = (r.bottom + mh + 8 > window.innerHeight && r.top - mh - 4 > 0) ? r.top - mh - 4 : r.bottom + 4;
    $menu.addClass('is-fixed').css({ top: top + 'px', left: Math.max(8, r.right - mw) + 'px' });
});
let ispagMoreOpenedAt = 0;
$(window).on('resize', ispagCloseMoreMenus);
document.addEventListener('scroll', function () { if (Date.now() - ispagMoreOpenedAt > 400) { ispagCloseMoreMenus(); } }, true);
$(document).on('click', function (e) {
    if (!$(e.target).closest('.ispag-more').length) { ispagCloseMoreMenus(); }
});
$(document).on('click', '.ispag-article--row', function (e) {
    if ($(e.target).closest('a, button, input, label, .ispag-more, .ispag-loading-overlay').length) { return; }
    $(this).find('.ispag-btn-view').first().trigger('click');
});

$(document).on('change', '.ispag-article-checkbox', function () {
    $('.ispag-article--row').each(function () {
        $(this).toggleClass('is-selected', $(this).find('.ispag-article-checkbox').prop('checked'));
    });
});

$(document).on('click', '.ispag-group-toggle', function () {
    const $wrap = $(this).closest('.ispag-article-group-wrapper');
    const collapsed = !$wrap.hasClass('is-collapsed');
    $wrap.toggleClass('is-collapsed', collapsed);
    $(this).attr('aria-expanded', collapsed ? 'false' : 'true');
});



/* Bloc stats du projet : repliable, état mémorisé par navigateur */
function ispagApplyStatsCollapsed() {
    // Replié par défaut : seul un choix explicite « déplié » (0) est mémorisé
    let collapsed = true;
    try { collapsed = localStorage.getItem('ispag_stats_collapsed') !== '0'; } catch (e) {}
    const box = document.getElementById('ispag_project_stat');
    if (!box) return;
    box.classList.toggle('is-collapsed', collapsed);
    const head = box.querySelector('.ispag-stats-head');
    if (head) head.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
}
(function () {
    function toggle(head) {
        const box = head.closest('#ispag_project_stat');
        if (!box) return;
        const collapsed = box.classList.toggle('is-collapsed');
        head.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
        try { localStorage.setItem('ispag_stats_collapsed', collapsed ? '1' : '0'); } catch (e) {}
    }
    document.addEventListener('click', function (e) {
        const head = e.target.closest && e.target.closest('#ispag_project_stat .ispag-stats-head');
        if (head) toggle(head);
    });
    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Enter' && e.key !== ' ') return;
        const head = e.target.closest && e.target.closest('#ispag_project_stat .ispag-stats-head');
        if (head) { e.preventDefault(); toggle(head); }
    });
})();
document.addEventListener('DOMContentLoaded', ispagApplyStatsCollapsed);


/* Document chargé (dropzone de la colonne de droite ou modal) lié à un article : on recharge le bloc de cet article */
jQuery(document).on('ispag:attachment-uploaded', function (e, data) {
    const articleId = parseInt(data && data.articleId, 10);
    if (!articleId) return;
    const $row = jQuery('.ispag-article[data-article-id="' + articleId + '"]');
    if (!$row.length) return;

    $row.addClass('is-loading');
    jQuery.post(ajaxurl, {
        action: 'ispag_reload_article_row',
        article_id: articleId,
        is_secondary: $row.hasClass('ispag-article-secondary') ? 1 : 0,
        is_archived: $row.hasClass('ispag-archived') ? 'true' : 'false',
        is_purchase: window.location.href.includes('poid=') ? 'true' : 'false'
    }).done(function (rowHtml) {
        if (typeof rowHtml === 'string' && rowHtml.trim() !== '') {
            const $new = jQuery(rowHtml);
            $row.replaceWith($new);
            $new.filter('.ispag-article').addClass('is-updated');
            ['attachEditModalEvents', 'attachViewModalEvents', 'bindStandardTitleListener'].forEach(function (fn) {
                if (typeof window[fn] === 'function') { try { window[fn](); } catch (err) { console.warn(fn, err); } }
            });
        } else {
            $row.removeClass('is-loading');
        }
    }).fail(function () { $row.removeClass('is-loading'); });
});
