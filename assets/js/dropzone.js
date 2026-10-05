window.ispagT = window.ispagT || function (s) { return s; }; // traductions des textes JS (voir includes/js-strings.php)
/**
 * ISPAG TANK BUILDER - JAVASCRIPT COMPLET
 * Gère l'upload asynchrone, l'analyse IA, l'extraction DXF et la confirmation des données.
 */

// Initialisation dans une fonction pour pouvoir la rejouer quand le bloc documents
// est injecté après coup (onglets chargés à la demande, événement 'ispag:loaded').
function ispagInitDropzone() {
    const dropzone = document.getElementById("dropzone");
    if (!dropzone || dropzone.dataset.ispagInit) return;
    dropzone.dataset.ispagInit = "1";

    const fileInput = document.getElementById("file_input");
    const browseBtn = document.getElementById("browse-file");
    const form = document.getElementById("ispag-upload-form");
    const status = document.getElementById("upload-status");

    const defaultDropzoneHTML = ispag_ajax_obj.drag_files_here + ' ' + ispag_ajax_obj.or + ' <button type="button" id="browse-file">' + ispag_ajax_obj.browse + '</button>';

    function resetDropzone() {
        dropzone.innerHTML = defaultDropzoneHTML;
        const newBrowseBtn = document.getElementById("browse-file");
        if (newBrowseBtn) {
            newBrowseBtn.addEventListener("click", e => {
                e.preventDefault();
                fileInput.value = null;
                fileInput.click();
            });
        }
    }

    dropzone.addEventListener("click", (e) => {
        if (e.target.id !== "browse-file") {
            fileInput.value = null;
            fileInput.click();
        }
    });

    if (browseBtn) {
        browseBtn.addEventListener("click", e => {
            e.preventDefault();
            fileInput.value = null;
            fileInput.click();
        });
    }

    fileInput.addEventListener("change", () => {
        if (fileInput.files.length > 0) {
            let names = Array.from(fileInput.files).map(f => f.name).join(', ');
            dropzone.innerHTML = `📎 ${names} ` + ispag_ajax_obj.selected;
        }
    });

    // --- NOUVELLE VERSION ASYNCHRONE DE L'UPLOAD ---
    form.addEventListener("submit", async e => {
        e.preventDefault(); 

        const files = fileInput.files;
        const select = document.getElementById("doc_type");
        const selectedOption = select.options[select.selectedIndex];

        const articleId = selectedOption.dataset.productId;
        const classCss = selectedOption.dataset.class;
        const dealId = form.deal_id.value;
        const poid = form.purchase_id.value;

        if (!files.length || !classCss) {
            ispagConfirm(ispag_ajax_obj.please_select_file + ".");
            return;
        }

        const submitBtn = form.querySelector('button[type="submit"]');
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<span class="dashicons dashicons-update spin"></span> ' + ispag_ajax_obj.Upload_in_progress + '...';

        const formData = new FormData();
        formData.append("action", "ispag_start_async_upload");
        formData.append("doc_type", classCss);
        formData.append("article_id", articleId);
        formData.append("deal_id", dealId);
        formData.append("poid", poid);
        formData.append("_ajax_nonce", ispag_ajax_obj.nonce);

        for (let i = 0; i < files.length; i++) {
            formData.append("files[]", files[i]);
        }

        status.innerHTML = "⏳ " + ispag_ajax_obj.Upload_in_progress + " ...";

        try {
            const res = await fetch(ajaxurl, {
                method: "POST",
                body: formData,
            });
            const json = await res.json();

            if (json.success) {
                const taskId = json.data.task_id;
                status.innerHTML = `
                    <div class="ispag-notice ispag-notice-info">
                        <span class="dashicons dashicons-update spin"></span>
                        ${ispag_ajax_obj.Upload_in_progress} (ID: ${taskId})
                    </div>
                `;

                // Réinitialiser le formulaire
                submitBtn.disabled = false;
                submitBtn.textContent = ispag_ajax_obj.Upload_all_files;
                fileInput.value = "";
                resetDropzone();

                // Démarrer le polling pour vérifier l'état de la tâche
                pollUploadStatus(taskId, dealId, poid);

            } else {
                resetDropzone();
                status.innerHTML = `
                    <div class="ispag-notice ispag-notice-error">
                        ❌ Error: ${json.data || 'Unknown error.'}
                    </div>
                `;
                submitBtn.disabled = false;
                submitBtn.textContent = ispag_ajax_obj.Upload_all_files;
            }
        } catch (error) {
            resetDropzone();
            status.innerHTML = `
                <div class="ispag-notice ispag-notice-error">
                    ❌ Error lors de l’upload : ${error.message || 'Network error.'}
                </div>
            `;
            submitBtn.disabled = false;
            submitBtn.textContent = ispag_ajax_obj.Upload_all_files;
        }
    });

    dropzone.addEventListener('dragover', (e) => {
        e.preventDefault();
        dropzone.classList.add('is-dragover');
    });
    dropzone.addEventListener('dragleave', () => {
        dropzone.classList.remove('is-dragover');
    });
    dropzone.addEventListener('drop', (e) => {
        e.preventDefault();
        dropzone.classList.remove('is-dragover');

        const droppedFiles = e.dataTransfer.files;
        if (droppedFiles.length > 0) {
            const dataTransfer = new DataTransfer();
            for (let i = 0; i < droppedFiles.length; i++) {
                if (droppedFiles[i].size > 0) {
                    dataTransfer.items.add(droppedFiles[i]);
                }
            }
            fileInput.files = dataTransfer.files;
            let names = Array.from(fileInput.files).map(f => f.name).join(', ');
            if (fileInput.files.length > 0) {
                dropzone.innerHTML = `📎 ${names} ` + ispag_ajax_obj.selected;
            } else {
                ispagConfirm("Le fichier semble vide ou inaccessible.");
                resetDropzone();
            }
        }
    });
}
document.addEventListener("DOMContentLoaded", ispagInitDropzone);
jQuery(document).on("ispag:loaded", ispagInitDropzone);

/**
 * GESTION DES ACTIONS ET MODALES (CORE)
 */
jQuery(document).ready(function($) {
    // Suppression de document
    $(document).on('click', '.ispag-documents-list .delete-doc-btn', async function(e) {
        e.preventDefault();
        const confirmed = await ispagConfirm(ispag_ajax_obj.really_dele_doc + ' ?', {
            labelOk: ispag_texts.continue || "Continuer",
            labelCancel: ispag_texts.cancel || "Annuler",
            danger: true,
        });
        if (!confirmed) return;

        const $btn = $(this);
        const $li = $btn.closest('li.document-item');
        const docId = $li.data('doc-id');
        const originalHtml = $btn.html();

        $btn.prop('disabled', true).css('opacity', '0.6');
        $btn.html('<span class="dashicons dashicons-update spin"></span>');

        if (!$('#ispag-spin-style').length) {
            $('head').append('<style id="ispag-spin-style">.spin { animation: ispag-spin 1s infinite linear; display: inline-block; } @keyframes ispag-spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }</style>');
        }

        $.ajax({
            url: ajaxurl,
            method: 'POST',
            data: {
                action: 'ispag_delete_document',
                document_id: docId,
                _ajax_nonce: ispag_ajax_obj.nonce
            },
            success: function(response) {
                if (response.success) {
                    $li.fadeOut(300, function() { $(this).remove(); });
                } else {
                    ispagConfirm('Error: ' + (response.data || 'Unable to delete the document.'), {
                        labelOk: "OK",
                        danger: true,
                    });
                    $btn.prop('disabled', false).css('opacity', '1').html(originalHtml);
                }
            },
            error: function() {
                ispagConfirm('Network error while deleting the document.', {
                    labelOk: "OK",
                    danger: true,
                });
                $btn.prop('disabled', false).css('opacity', '1').html(originalHtml);
            },
            complete: () => {
                // $('body').css('cursor', 'default');
            }
        });
    });

    // Enregistrement historique
    $(document).on('click', 'a[data-media-id]', function() {
        var mediaId = $(this).data('media-id');
        $.post(ajaxurl, { action: 'ispag_save_historique_views', media_id: mediaId, _ajax_nonce: ispag_ajax_obj.nonce });
    });

    // Boutons Analyse / Extraction avec demande de confirmation stylisée
    $(document).on('click', '.extract-doc-btn, #sketch-pdf', function(e) {
        e.preventDefault();
        e.stopPropagation();

        var button = $(this);
        var docId = button.data('doc-id');
        var dealId = button.data('deal-id');
        var purchaseId = button.data('purchase-id');
        var docType = button.data('doc-type');
        var tank_id = button.data('tank-id');
        var ajaxAction = button.data('ajax-action');
        var originalHtml = button.html();

        if (ajaxAction == 'tank_data_extractor') {
            button.prop('disabled', true).html('<span class="dashicons dashicons-update spin"></span>');
            sendPdfForAnalysis(ajaxAction, docId, dealId, purchaseId, button, docType, originalHtml, tank_id);
        } else {
            ispagConfirm(ispag_texts.ai_start_analyses, {
                labelOk: ispag_texts.start_analyse,
                labelCancel: ispag_texts.later,
                danger: false
            }).then((confirmed) => {
                if (!confirmed) {
                    console.log("❌ [DEBUG] Analyse annulée par l'utilisateur.");
                    return;
                }
                console.log("🚀 [DEBUG] Confirmation reçue. Lancement de l'analyse asynchrone.");
                button.prop('disabled', true).html('<span class="dashicons dashicons-update spin"></span>');
                sendPdfForAnalysis(ajaxAction, docId, dealId, purchaseId, button, docType, originalHtml, tank_id);
            });
        }
    });

    // Fermeture globale
    $(document).on('click', '.ispag-close-modal, #cancelButton', function(e) {
        e.preventDefault();
        closeIspagModal();
    });

    $(document).on('click', '.ispag-modal-overlay', function(e) {
        if (e.target === this) closeIspagModal();
    });
});

/**
 * FONCTIONS TECHNIQUES
 */
function closeIspagModal() {
    jQuery('#ispag-analysis-modal, #confirmationModal').fadeOut(200);
    jQuery('body').removeClass('modal-open').css('cursor', 'default');
}

function sendPdfForAnalysis(ajaxAction, docId, dealId, purchaseId, button, docType, originalHtml, tank_id) {
    let actionName;
    var tankSketch = button.data('tank-sketch');

    // Forcer le type de document sur 'project' si on lance une analyse globale sur un deal
    if (actionName === 'analyze_project_data') {
        docType = 'project';
    }

    actionName = ajaxAction;
    if (tankSketch) {
        tank_id = tankSketch;
    } else if (purchaseId && docType == 'invoice') {
        // actionName = 'invoice_analyse';
    } else if (purchaseId) {
        // actionName = 'analyze_and_confirm_data';
    } else if (dealId && tank_id == 0) {
        // actionName = 'analyze_project_data';
    } else if (docId && tank_id != 0) {
        // actionName = 'analyze_drawing';
        let container = jQuery('#ispag-drawing-result-' + tank_id);
        if (container.length > 0) container.html('<p style="font-size:11px; color:#666;">⏳ Analyse...</p>');
    }

    console.log(`🚀 [DEBUG] Envoi PDF. Action: ${actionName}, TankID: ${tank_id}, DealID: ${dealId}, docType: ${docType}, docId: ${docId}`);

    if (!actionName) {
        console.warn("⚠️ [DEBUG] Aucune action déterminée pour ce bouton.");
        button.prop('disabled', false).html(originalHtml);
        return;
    }

    jQuery.ajax({
        url: ajaxurl,
        type: 'POST',
        data: { action: actionName, docId: docId, docType: docType, deal_id: dealId, purchaseId: purchaseId, tankId: tank_id },
        success: function(response) {
            console.group("📡 [DEBUG] Réponse AJAX : " + actionName);
            console.log("Success:", response.success);
            console.log("Full Data:", response.data);

            if (response.success) {
                const result = response.data;
                if (result && result.async && result.task_id) {
                    console.log("⏳ [DEBUG] Analyse planifiée en tâche de fond. ID de tâche :", result.task_id);
                    button.html('<span class="dashicons dashicons-update spin"></span> ' + ispag_texts.pending_ai_analyse + ' ...');
                    surveillerAnalyse(result.task_id, actionName, tank_id, button, originalHtml);
                    console.groupEnd();
                    return;
                }
                traiterResultatAnalyse(actionName, result, tank_id, button, originalHtml);
            } else {
                console.error("❌ [DEBUG] Error API:", response.data);
                ispagConfirm("Error: " + (response.data.message || "The API could not respond."), {
                    labelOk: "OK",
                    danger: true,
                });
                button.prop('disabled', false).html(originalHtml);
            }
            console.groupEnd();
        },
        error: (xhr) => {
            console.error("🔥 [DEBUG] CRASH AJAX 500 ou réseau", xhr.responseText);
            ispagConfirm("Network error while analysing the document.", {
                labelOk: "OK",
                danger: true,
            });
            button.prop('disabled', false).html(originalHtml);
        }
    });
}

/**
 * Fonction de surveillance (Polling) pour vérifier régulièrement l'état de l'analyse Mistral
 */
function surveillerAnalyse(taskId, actionName, tank_id, button, originalHtml) {
    const interval = setInterval(() => {
        jQuery.post(ajaxurl, {
            action: 'check_analysis_status',
            task_id: taskId
        }, function(response) {
            if (response.success) {
                const data = response.data;
                if (data.status === 'completed') {
                    clearInterval(interval);
                    console.log("✅ [DEBUG] Analyse terminée en tâche de fond ! Données reçues :", data);
                    
                    // Selon votre backend, le résultat final peut être dans data.result ou directement dans data
                    const analysisResult = data.result !== undefined ? data.result : data;
                    
                    // On appelle le traitement (qui gère lui-même la réactivation du bouton)
                    traiterResultatAnalyse(actionName, analysisResult, tank_id, button, originalHtml);

                } else if (data.status === 'failed') {
                    clearInterval(interval);
                    console.error("❌ [DEBUG] L'analyse en tâche de fond a échoué :", data.error);
                    ispagConfirm("Error lors de l'analyse : " + (data.error || 'Unknown error'), {
                        labelOk: "OK",
                        danger: true,
                    });
                    button.prop('disabled', false).html(originalHtml);
                } else {
                    console.log("⏳ [DEBUG] Analyse toujours en cours dans le Cron WordPress...");
                }
            } else {
                clearInterval(interval);
                console.error("❌ [DEBUG] Error lors de l'appel au statut de l'analyse.");
                ispagConfirm("Error while checking the analysis status.", {
                    labelOk: "OK",
                    danger: true,
                });
                button.prop('disabled', false).html(originalHtml);
            }
        });
    }, 4000);
}

/**
 * Traite et affiche le résultat final de l'analyse
 */
function traiterResultatAnalyse(actionName, result, tank_id, button, originalHtml) {
    console.log("🔍 [DEBUG] traiterResultatAnalyse appelé avec actionName =", actionName);


    if (actionName === 'analyze_drawing' && result.comparison) {
        console.log("✅ [DEBUG] analyze_drawing");
        displayDrawingAnalysis(result.comparison, tank_id, button, result.cached || false);
    } else if (actionName === 'drawing_approval_control') {
        console.log("✅ [DEBUG] drawing_approval_control");
        displayDrawingApprovalModal(result, tank_id, button);
    } else if (actionName === 'tank_data_extractor') {
        if (!result.tank_specs) {
            console.error("❌ [DEBUG] tank_specs est VIDE dans la réponse serveur !");
            ispagConfirm("Error: The tank specifications are missing.", {
                labelOk: "OK",
                danger: true,
            });
        } else {
            console.log("✅ [DEBUG] Specs reçues, appel de displayDxfCode");
            displayDxfCode(result.tank_specs, result.project, tank_id);
        }
    } else if (actionName === 'invoice_analyse') {
        console.log('invoice_analyse avec les données :', result.data);
    } else if (actionName === 'analyze_and_confirm_data' && typeof window.ispagQuoteCompare === 'function') {
        // Offre fournisseur : fenêtre de comparaison avec les cuves de la commande (plugin Achats, quote-compare.js)
        window.ispagQuoteCompare(result, button.data('purchase-id'), button.data('deal-id'));
    } else if (result && result.needs_confirmation) {
        showConfirmationModal(result.datas_to_confirm, result.existing_datas);
    } else if (result) {
        updateData(result.data || result);
    }
    button.prop('disabled', false).html(originalHtml);
}

/**
 * Affiche la modale de comparaison entre le plan validé/extrait et la base de données
 */
function displayDrawingApprovalModal(data, tankId, button) {
    console.group("🔍 [DEBUG MODALE] Début de displayDrawingApprovalModal");
    console.log("1. Paramètre 'tankId' reçu :", tankId);
    console.log("2. Paramètre 'data' brut reçu :", data);

    // Récupération intelligente des données extraites selon la structure reçue
    var drawingData = {};
    if (data && data.extracted_drawing_data) {
        drawingData = data.extracted_drawing_data;
    } else if (data && data.tanks && Array.isArray(data.tanks) && data.tanks.length > 0) {
        drawingData = data.tanks[0];
    } else {
        drawingData = data || {};
    }

    const modal = document.getElementById("ispag-drawing-comparison-modal");
    if (!modal) {
        console.error("❌ [ERREUR] La modale #ispag-drawing-comparison-modal n'existe pas dans la page HTML !");
        console.groupEnd();
        return;
    }

    const content = modal.querySelector('#ispag-drawing-comparison-modal-body');
    const titleContent = modal.querySelector('.ispag-modal-header');
    if (!content) {
        console.error("❌ [ERREUR] Le body #ispag-drawing-comparison-modal-body n'existe pas dans la page HTML !");
        console.groupEnd();
        return;
    }

    if (titleContent) {
        let h4 = titleContent.querySelector('h4');
        if (h4) {
            h4.textContent = `Comparison - Extracted data vs Database (Réservoir #${tankId})`;
        }
    }

    // Affichage d'un loader temporaire le temps de récupérer la BDD
    if (content) {
        
        content.innerHTML = '<p style="text-align:center; padding:20px;">Loading current data from the database...</p>';
        console.log('Affichage d\'un loader temporaire : <p style="text-align:center; padding:20px;">Loading current data from the database...</p>'  );
    }

    // OUVERTURE DE LA MODALE
    modal.style.display = "flex";
    modal.style.zIndex = "999999";
    modal.classList.add('is-open');

    // Appel AJAX pour récupérer les données de la BDD pour ce tankId
    jQuery.post(ajaxurl, {
        action: 'get_tank_db_data',
        tank_id: tankId
    }, function(response) {
        var dbData = {};
        if (response.success && response.data) {
            dbData = response.data;
        } else {
            dbData = (data && data.current_db_data) ? data.current_db_data : {};
        }

        console.log("3. dbData récupéré :", dbData);

        // Extraction sécurisée des dimensions de la BDD (gestion de l'objet imbriqué dimensions_principales)
        var dims = dbData.dimensions_principales || dbData;

        // console.log('dims' + dims);

        // Construction du tableau comparatif une fois les données BDD reçues
        var comparisonHtml = '<table class="wp-list-table widefat fixed striped">' +
            '<thead><tr><th>Parameter</th><th>Current value (DB)</th><th>Extracted value / Drawing</th><th style="text-align:center;">Apply</th></tr></thead>' +
            '<tbody>' +
                '<tr><td><strong>Materials</strong></td><td>' + (dims.Matiere_ID || dims.Matiere_ID || '-') + '</td><td>' + (drawingData.materiau || '-') + '</td><td style="text-align:center;"><input type="checkbox" class="ispag-update-field" data-field="materiau" value="' + (drawingData.materiau || '') + '" checked></td></tr>' +
                '<tr><td><strong>Volume</strong></td><td>' + (dims.Volume_L || dims.volume || '-') + '</td><td>' + (drawingData.volume || '-') + '</td><td style="text-align:center;"><input type="checkbox" class="ispag-update-field" data-field="volume" value="' + (drawingData.volume || '') + '" checked></td></tr>' +
                '<tr><td><strong>Diameter</strong></td><td>' + (dims.Diametre_mm || dims.diameter || '-') + '</td><td>' + (drawingData.diameter || '-') + '</td><td style="text-align:center;"><input type="checkbox" class="ispag-update-field" data-field="diameter" value="' + (drawingData.diameter || '') + '" checked></td></tr>' +
                '<tr><td><strong>Hauteur</strong></td><td>' + (dims.Hauteur_mm || dims.height || '-') + '</td><td>' + (drawingData.height || '-') + '</td><td style="text-align:center;"><input type="checkbox" class="ispag-update-field" data-field="height" value="' + (drawingData.height || '') + '" checked></td></tr>' +
                '<tr><td><strong>Pression</strong></td><td>' + (dims.Pression_Max_bar || dims.pressure || dims.max_pressure || '-') + '</td><td>' + (drawingData.pressure || drawingData.max_pressure || '-') + '</td><td style="text-align:center;"><input type="checkbox" class="ispag-update-field" data-field="pressure" value="' + (drawingData.pressure || drawingData.max_pressure || '') + '" checked></td></tr>' +
                '<tr><td><strong>Temperature</strong></td><td>' + (dims.Temperature_Max || dims.temperature || '-') + '</td><td>' + (drawingData.temperature || '-') + '</td><td style="text-align:center;"><input type="checkbox" class="ispag-update-field" data-field="temperature" value="' + (drawingData.temperature || '') + '" checked></td></tr>' +
            '</tbody>' +
        '</table>';

        let html = '<div style="margin-bottom: 15px;">' + comparisonHtml + '</div>';
        html += '<div style="text-align: right; display:flex; justify-content:flex-end; gap:10px;">';
        html += '<button type="button" id="btn-update-tank-db" class="button button-primary">Update the DB</button>';
        html += '<button type="button" class="button button-secondary ispag-close-modal">Close</button>';
        html += '</div>';

        if (content) {
            content.innerHTML = html;
            // console.log('Affichage du contenu', html  );
        }

        // Réassignation des événements sur les boutons recréés dynamiquement
        const closeButtons = modal.querySelectorAll('.ispag-close-modal');
        closeButtons.forEach(btn => {
            btn.onclick = function() {
                modal.style.display = "none";
                modal.classList.remove('is-open');
            };
        });

        const updateBtn = document.getElementById('btn-update-tank-db');
        if (updateBtn) {
            updateBtn.onclick = function() {
                // Collecte uniquement des champs cochés par l'utilisateur
                var selectedData = {};
                modal.querySelectorAll('.ispag-update-field:checked').forEach(function(checkbox) {
                    var fieldName = checkbox.getAttribute('data-field');
                    var fieldValue = checkbox.value;
                    if (fieldName && fieldValue) {
                        selectedData[fieldName] = fieldValue;
                    }
                });

                console.log("🚀 Données sélectionnées pour mise à jour :", selectedData);

                if (Object.keys(selectedData).length === 0) {
                    alert(ispagT("Please select at least one parameter to update."));
                    return;
                }

                if (typeof updateTankFromDrawing === 'function') {
                    // On passe l'objet filtré contenant uniquement les valeurs cochées
                    updateTankFromDrawing(tankId, selectedData, jQuery(modal));
                } else {
                    console.warn("⚠️ Fonction updateTankFromDrawing non définie.");
                    modal.style.display = "none";
                    modal.classList.remove('is-open');
                }
            };
        }
    });

    console.groupEnd();
}

/**
 * Envoie les données sélectionnées au serveur pour mettre à jour le réservoir en BDD
 */
function updateTankFromDrawing(tankId, selectedData, modal) {
    console.group("💾 [DEBUG UPDATE] Début de updateTankFromDrawing");
    console.log("Tank ID :", tankId);
    console.log("Données à mettre à jour :", selectedData);

    // Confirmation visuelle optionnelle sur le bouton
    const updateBtn = document.getElementById('btn-update-tank-db');
    const originalText = updateBtn ? updateBtn.textContent : '';
    if (updateBtn) {
        updateBtn.disabled = true;
        updateBtn.textContent = 'Update in progress...';
    }

    jQuery.post(ajaxurl, {
        action: 'ispag_update_tank_from_drawing',
        article_id: tankId,
        tank_data: selectedData
    }, function(response) {
        console.log("📡 Réponse AJAX update :", response);

        if (response.success) {
            alert(ispagT('Tank updated successfully!'));
            // Fermeture de la modale
            if (modal && modal.length > 0) {
                modal.css('display', 'none').removeClass('is-open');
            }
            // Optionnel : recharger la page ou actualiser les blocs d'affichage
            // location.reload();  
            // reloadArticleList();
            const $tankContainer = jQuery('.ispag-tank-item[data-tank-id="' + tankId + '"], .ispag-article-row[data-article-id="' + tankId + '"]');
            $tankContainer.trigger('ispag:refresh-tank', [tankId]);
        } else {
            alert(ispagT('Error during update: ') + (response.data.message || 'Unknown error'));
            if (updateBtn) {
                updateBtn.disabled = false;
                updateBtn.textContent = originalText;
            }
        }
    }).fail(function(xhr, status, error) {
        console.error("❌ Error AJAX critique :", error);
        alert(ispagT('A network error occurred.'));
        if (updateBtn) {
            updateBtn.disabled = false;
            updateBtn.textContent = originalText;
        }
    });

    console.groupEnd();
}
/**
 * Fonction pour vérifier l'état d'un upload asynchrone via polling
 * @param {string} taskId - L'ID de la tâche d'upload
 * @param {number} dealId - L'ID du deal
 * @param {number} poid - L'ID de la commande d'achat (optionnel)
 */
function pollUploadStatus(taskId, dealId, poid) {
    const interval = setInterval(async () => {
        try {
            const controller = new AbortController();
            const timeoutId = setTimeout(() => controller.abort(), 10000);

            

            const res = await fetch(ajaxurl, {
                method: "POST",
                headers: {
                    "Content-Type": "application/x-www-form-urlencoded",
                },
                body: new URLSearchParams({
                    action: "ispag_check_upload_status",
                    task_id: taskId,
                    _ajax_nonce: ispag_ajax_obj.nonce,
                }),
                signal: controller.signal,
            });

            clearTimeout(timeoutId);

            if (!res.ok) {
                throw new Error(`Server error: ${res.status} ${res.statusText}`);
            }

            const json = await res.json();
            const statusElement = document.getElementById("upload-status");

            if (json.data && json.data.status) {
                if (json.data.status === 'completed') {
                    clearInterval(interval);

                    try {
                        const listRes = await fetch(ajaxurl, {
                            method: "POST",
                            headers: {
                                "Content-Type": "application/x-www-form-urlencoded",
                            },
                            body: new URLSearchParams({
                                action: "ispag_get_documents_list",
                                deal_id: dealId,
                                poid: poid,
                            }),
                        });

                        if (!listRes.ok) {
                            throw new Error(`Server error: ${listRes.status} ${listRes.statusText}`);
                        }

                        const listJson = await listRes.json();
                        if (listJson.success) {
                            document.querySelector(".ispag-documents-list").innerHTML = listJson.data;
                            if (typeof reloadArticleList === "function") reloadArticleList();
                        }
                    } catch (listError) {
                        console.error("Error during update de la liste des documents:", listError);
                    }

                    statusElement.innerHTML = `
                        <div class="ispag-notice ispag-notice-success">
                            ✅ ${ispag_ajax_obj.File_added_successfully}
                        </div>
                    `;
                } else if (json.data.status === 'failed') {
                    clearInterval(interval);
                    statusElement.innerHTML = `
                        <div class="ispag-notice ispag-notice-error">
                            ❌ Error: ${json.data.error || 'Task failed.'}
                        </div>
                    `;
                } else {
                    console.log(`[DEBUG] Tâche en cours: ${json.data.status}`);
                }
            } else {
                clearInterval(interval);
                statusElement.innerHTML = `
                    <div class="ispag-notice ispag-notice-error">
                        ❌ Error: ${json.data?.error || json.message || 'Statut de tâche invalide.'}
                    </div>
                `;
            }

        } catch (error) {
            clearInterval(interval);
            console.error("[DEBUG] Error lors du polling:", error);
            const statusElement = document.getElementById("upload-status");

            if (error.name === 'AbortError') {
                statusElement.innerHTML = `
                    <div class="ispag-notice ispag-notice-error">
                        ❌ Timeout: Le serveur a mis trop de temps à répondre.
                    </div>
                `;
            } else if (error.message.includes('Server error')) {
                statusElement.innerHTML = `
                    <div class="ispag-notice ispag-notice-error">
                        ❌ ${error.message}
                    </div>
                `;
            } else {
                statusElement.innerHTML = `
                    <div class="ispag-notice ispag-notice-error">
                        ❌ Network error: ${error.message}
                    </div>
                `;
            }
        }
    }, 2000);
}

// --- Fonctions existantes (inchangées) ---
function displayDxfCode(tankSpecs, project, tank_id) {
    console.group("📐 [DEBUG] Moteur DXF - Display");
    if (typeof window.IspagDxfEngine === 'undefined') {
        console.error("❌ Moteur IspagDxfEngine introuvable.");
        ispagConfirm("Error: The drawing engine is not loaded.", {
            labelOk: "OK",
            danger: true,
        });
        console.groupEnd();
        return;
    }

    try {
        const entities = window.IspagDxfEngine.generateEntities(tankSpecs, project);
        console.log("✅ Entités générées, nombre :", entities ? entities.length : 0);

        const modal = jQuery("#ispag-analysis-modal");
        const content = modal.find('#ispag-analysis-modal-body');
        const titleContent = modal.find('.ispag-modal-header');

        let htmlTitle = `<h3>${ispag_texts.tank_sketch} : ${ispag_texts.tank} #${tank_id}</h3>`;
        let html = `
            <div style="margin-top:20px; text-align:center; display:flex; justify-content:center; gap:10px;">
                <a id="btn-download-dxf" class="button button-primary">${ispag_texts.download} DXF</a>
                <button type="button" id="btn-download-pdf" class="button button-primary">${ispag_texts.download} PDF</button>
            </div>`;

        titleContent.html(htmlTitle);
        content.html(html);

        jQuery('#btn-download-dxf').off('click').on('click', function(e) {
            console.log("🖱️ Clic sur Télécharger DXF");
            downloadDxfFile(entities, tank_id);
        });

        jQuery('#btn-download-pdf').off('click').on('click', function(e) {
            downloadPdfFile(tank_id);
        });

        modal.fadeIn(200);
    } catch (e) {
        console.error("🔥 Error Moteur DXF:", e);
        ispagConfirm("Error while generating the DXF: " + e.message, {
            labelOk: "OK",
            danger: true,
        });
    }
    console.groupEnd();
}

function downloadDxfFile(entities, tank_id) {
    console.group("📥 [DEBUG] downloadDxfFile");
    const btnDxf = jQuery('#btn-download-dxf');
    const originalHtml = btnDxf.html();
    btnDxf.prop('disabled', true).html('⏳ ' + (typeof ispag_texts !== 'undefined' ? ispag_texts.generating : 'Génération') + '...');

    let dxf = "0\nSECTION\n2\nHEADER\n9\n$ACADVER\n1\nAC1015\n0\nENDSEC\n";
    dxf += "0\nSECTION\n2\nTABLES\n0\nTABLE\n2\nLTYPE\n70\n1\n0\nLTYPE\n2\nCONTINUOUS\n70\n0\n3\nSolid line\n72\n65\n73\n0\n40\n0.0\n0\nENDTAB\n0\nTABLE\n2\nLAYER\n70\n10\n";
    const layers = ["CADRE", "FONDS", "VIROLE", "SUPPORTS", "PIQUAGES", "PIQUAGES_ARRIERE", "COTATIONS", "TABLEAU", "CARTOUCHE", "SOUDURES", "INTERNES"];
    layers.forEach(lyr => dxf += `0\nLAYER\n2\n${lyr}\n70\n0\n62\n7\n6\nCONTINUOUS\n`);
    dxf += "0\nENDTAB\n0\nENDSEC\n0\nSECTION\n2\nENTITIES\n";

    const engineConfig = (window.IspagDxfEngine && window.IspagDxfEngine.config) ? window.IspagDxfEngine.config : { layers: {} };
    let addedCount = 0;
    entities.forEach((e, index) => {
        const fx = (val) => (typeof val === 'number') ? val.toFixed(4) : "0.0000";
        const type = (e.type || '').toUpperCase();
        const color = e.color || (engineConfig.layers[e.layer] ? engineConfig.layers[e.layer].color : 7);

        if (type === 'LINE') {
            dxf += `0\nLINE\n8\n${e.layer}\n62\n${color}\n10\n${fx(e.start.x)}\n20\n${fx(e.start.y)}\n30\n0.0\n11\n${fx(e.end.x)}\n21\n${fx(e.end.y)}\n31\n0.0\n`;
            addedCount++;
        } else if (type === 'ELLIPSE') {
            dxf += `0\nELLIPSE\n8\n${e.layer}\n62\n${color}\n10\n${fx(e.center.x)}\n20\n${fx(e.center.y)}\n30\n0.0\n11\n${fx(e.major_axis.x)}\n21\n${fx(e.major_axis.y)}\n31\n0.0\n40\n${fx(e.ratio)}\n41\n${fx(e.start_param)}\n42\n${fx(e.end_param)}\n`;
            addedCount++;
        } else if (type === 'MTEXT') {
            dxf += `0\nMTEXT\n8\n${e.layer}\n62\n${color}\n10\n${fx(e.point.x)}\n20\n${fx(e.point.y)}\n30\n0.0\n40\n${fx(e.height || 25)}\n41\n1000.0\n71\n${e.attachment || 1}\n50\n${fx(e.rotation || 0)}\n1\n${e.text}\n`;
            addedCount++;
        } else {
            console.warn(`⚠️ Entité ignorée à l'index ${index}, type reçu :`, e.type);
        }
    });

    dxf += "0\nENDSEC\n0\nEOF";
    console.log(`📊 Entités ajoutées au DXF : ${addedCount} / ${entities.length}`);
    console.log(`📄 Taille finale du DXF : ${dxf.length} octets`);

    try {
        const blob = new Blob([dxf], { type: 'application/dxf;charset=utf-8' });
        const url = URL.createObjectURL(blob);
        const filename = `ISPAG_PLAN_V3_${tank_id}.dxf`;
        const btnDxf = jQuery('#btn-download-dxf');
        btnDxf.prop('disabled', false)
              .html('✅ ' + (typeof ispag_texts !== 'undefined' ? ispag_texts.download_now : 'Télécharger maintenant'));

        btnDxf.off('click').on('click', function(e) {
            const link = document.createElement('a');
            link.href = url;
            link.download = filename;
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            setTimeout(() => URL.revokeObjectURL(url), 100);
        });
        console.log("🔗 Action de téléchargement attachée au bouton.");
    } catch (err) {
        console.error("❌ Error:", err);
        ispagConfirm("Error while downloading the DXF: " + err.message, {
            labelOk: "OK",
            danger: true,
        });
    }
}

function downloadPdfFile(tank_id) {
    const btnPdf = jQuery('#btn-download-pdf');
    const originalHtml = btnPdf.html();
    btnPdf.prop('disabled', true).html('⏳ ' + (typeof ispag_texts !== 'undefined' ? ispag_texts.generating_pdf : 'Génération') + '...');
    window.location.href = ajaxurl + '?action=ispag_export_pdf&article_id=' + tank_id;
    setTimeout(function() {
        btnPdf.prop('disabled', false).html(originalHtml);
    }, 3000);
}

function showConfirmationModal(datas_to_confirm, existing_datas) {
    console.groupCollapsed('🔧 ISPAG : showConfirmationModal');
    const modal = jQuery("#confirmationModal");
    const content = modal.find('#ispag-confirmation-modal-body');
    let currentDataIndex = 0;
    const existingDataArray = Array.isArray(existing_datas) ? existing_datas : [];
    const ai_fields = (datas_to_confirm && datas_to_confirm[0]) ? datas_to_confirm[0].fields : [];

    const updateExistingValues = (index) => {
        if (existingDataArray.length === 0) return;
        const currentExistingTank = existingDataArray[index];
        jQuery('#data-index-display').text(`${index + 1} / ${existingDataArray.length}`);

        jQuery('#confirmationForm tbody tr').each(function() {
            const $row = jQuery(this);
            const key = $row.data('key');
            const aiValue = $row.find('.new-value-cell').data('new-value');
            const existingVal = currentExistingTank[key] ?? 'Not specified';
            $row.find('.existing-value-cell').text(existingVal);

            if (key === 'Id') {
                $row.find('.new-value-cell').text(existingVal).data('new-value', existingVal);
            } else {
                if (String(aiValue) !== String(existingVal)) {
                    $row.addClass('row-different').css('background-color', '#fff3cd');
                } else {
                    $row.removeClass('row-different').css('background-color', 'transparent');
                }
            }
        });

        jQuery('#prev-existing-btn').prop('disabled', index === 0);
        jQuery('#next-existing-btn').prop('disabled', index >= existingDataArray.length - 1);
    };

    let html = '<h3>Checking matches:</h3>';
    html += '<div class="navigation-info" style="background:#f1f1f1; padding:10px; border-radius:4px; margin-bottom:10px; display:flex; align-items:center; gap:10px;">' +
            '<span>Cuve projet cible :</span>' +
            '<button type="button" id="prev-existing-btn" class="button">⬅️</button> ' +
            '<span id="data-index-display" style="font-weight:bold; min-width:40px; text-align:center;"></span> ' +
            '<button type="button" id="next-existing-btn" class="button">➡️</button>' +
            '</div>';
    html += '<div class="ispag-modal-body-scroll" style="max-height: 65vh; overflow-y: auto; border: 1px solid #ddd; padding: 5px; border-radius: var(--ispag-btn-border-radius); background: #fff;">';
    html += '<form id="confirmationForm"><table class="wp-list-table widefat fixed striped">';
    html += '<thead><tr><th width="30"></th><th>Field</th><th>In database (Project)</th><th>Found (AI)</th></tr></thead><tbody>';
    html += `<tr data-key="Id" style="background: #f0f0f0;">
        <td><input type="checkbox" name="confirm_field[]" value="Id" checked onclick="return false;"></td>
        <td><strong>ID Article</strong></td>
        <td class="existing-value-cell">---</td>
        <td class="new-value-cell" data-new-value="">---</td>
    </tr>`;

    ai_fields.forEach(item => {
        if (item.key === 'Id') return;
        const safeKey = item.key || 'unknown';
        const label = safeKey.replace(/_/g, ' ');
        let newValue = item.new ?? '---';
        const isChecked = (item.match === false) ? 'checked' : '';
        html += `<tr data-key="${safeKey}">
            <td><input type="checkbox" name="confirm_field[]" value="${safeKey}" ${isChecked}></td>
            <td><strong>${label}</strong></td>
            <td class="existing-value-cell">---</td>
            <td class="new-value-cell" data-new-value="${newValue}">${newValue}</td>
        </tr>`;
    });
    html += '</tbody></table></form></div>';
    content.html(html);
    modal.show();
    updateExistingValues(0);
    console.groupEnd();

    jQuery('#prev-existing-btn').off('click').on('click', function() {
        if (currentDataIndex > 0) updateExistingValues(--currentDataIndex);
    });
    jQuery('#next-existing-btn').off('click').on('click', function() {
        if (currentDataIndex < existingDataArray.length - 1) updateExistingValues(++currentDataIndex);
    });

    jQuery('#confirmButton').off('click').on('click', function(e) {
        e.preventDefault();
        const dataToUpdate = {};
        jQuery('#confirmationForm input[name="confirm_field[]"]:checked').each(function() {
            const $row = jQuery(this).closest('tr');
            const key = jQuery(this).val();
            const val = $row.find('.new-value-cell').data('new-value');
            dataToUpdate[key] = val;
        });
        if (!dataToUpdate['Id']) {
            ispagConfirm("Error: Unable to find the article ID.", {
                labelOk: "OK",
                danger: true,
            });
            return;
        }
        updateData(dataToUpdate);
        modal.hide();
    });
}

function updateData(dataToUpdate) {
    const postData = {
        action: 'ispag_save_confirmed_data',
        deal_id: jQuery('.extract-doc-btn').data('deal-id'),
        purchase_id: jQuery('.extract-doc-btn').data('purchase-id'),
        article_id: dataToUpdate ? dataToUpdate['Id'] : null,
        data: dataToUpdate
    };
    if (postData['article_id'] && postData['purchase_id'] != 0) {
        jQuery.ajax({
            url: ajaxurl,
            type: 'POST',
            data: postData,
            success: function(response) {
                if (response.success) {
                    ispagConfirm('Data saved successfully!', {
                        labelOk: "OK",
                        danger: false,
                    }).then(() => {
                        if (typeof reloadArticleList === "function") {
                            reloadArticleList();
                        } else {
                            // location.reload();
                            const $tankContainer = jQuery('.ispag-tank-item[data-tank-id="' + tankId + '"], .ispag-article-row[data-article-id="' + tankId + '"]');
                            $tankContainer.trigger('ispag:refresh-tank', [tankId]);
                        }
                    });
                } else {
                    ispagConfirm('Error: ' + response.data, {
                        labelOk: "OK",
                        danger: true,
                    });
                }
            },
            complete: () => { jQuery('body').css('cursor', 'default'); }
        });
    } else {
        if (typeof reloadArticleList === "function") {
            reloadArticleList();
        } else {
            // location.reload();
            const $tankContainer = jQuery('.ispag-tank-item[data-tank-id="' + tankId + '"], .ispag-article-row[data-article-id="' + tankId + '"]');
            $tankContainer.trigger('ispag:refresh-tank', [tankId]);
        }
    }
}

function displayDrawingAnalysis(comparison, tankId, button, cached) {
    const t = window.ispag_ajax_obj?.drawing_analysis || {
        title: ispagT('Drawing analysis'),
        discrepancies_detected: 'Discrepancies detected',
        tank_id: 'Tank ID',
        status: 'Status',
        perfect_match: 'Perfect match',
        discrepancies: 'Discrepancies detected',
        cached_results: 'Cached results',
        summary: 'Summary',
        no_critical_discrepancies: 'No critical discrepancies detected.',
        type: 'Type',
        expected: 'Expected',
        found: 'Found',
        ok: 'OK',
        missing: 'Missing',
        discrepancy: 'Discrepancy',
        unknown: 'Unknown',
        not_specified: 'Not specified',
        close: 'Close'
    };

    const $modal = jQuery('#ispag-drawing-analysis-modal');
    const $modalContent = $modal.find('.ispag-modal-content');
    const $summary = jQuery('#ispag-drawing-analysis-summary');
    const $results = jQuery('#ispag-drawing-analysis-results');

    $modalContent.find('h2').text(`${t.title}: ${t.discrepancies_detected}`);

    let summaryHtml = `
        <p>
            <strong>${t.tank_id}:</strong> ${tankId} |
            <strong>${t.status}:</strong>
            ${comparison.match
                ? `<span style="color: #10b981;">✅ ${t.perfect_match}</span>`
                : `<span style="color: #ef4444;">❌ ${t.discrepancies}</span>`
            }
        </p>
        ${cached ? `<p style="color: #f59e0b;">⚠️ ${t.cached_results}</p>` : ''}
        <p><strong>${t.summary}:</strong> ${comparison.length_check || t.no_critical_discrepancies}</p>
    `;
    $summary.html(summaryHtml);

    let resultsHtml = `
        <div class="ispag-task-toggle-section">
            <table style="width: 100%; border-collapse: collapse; font-size: 14px;">
                <thead>
                    <tr style="border-bottom: 1px solid #e2e8f0;">
                        <th style="text-align: left; padding: 10px; color: #64748b; font-weight: 600;">${t.type}</th>
                        <th style="text-align: left; padding: 10px; color: #64748b; font-weight: 600;">${t.expected}</th>
                        <th style="text-align: left; padding: 10px; color: #64748b; font-weight: 600;">${t.found}</th>
                        <th style="text-align: left; padding: 10px; color: #64748b; font-weight: 600;">${t.status}</th>
                    </tr>
                </thead>
                <tbody>
    `;

    comparison.discrepancies.forEach((discrepancy) => {
        let statusClass = '';
        let statusText = '';
        let statusIcon = '';

        if (discrepancy.includes('OK')) {
            statusClass = 'style="color: #10b981;"';
            statusText = t.ok;
            statusIcon = '✅';
        } else if (discrepancy.includes('manquant')) {
            statusClass = 'style="color: #ef4444;"';
            statusText = t.missing;
            statusIcon = '❌';
        } else if (discrepancy.includes('différence') || discrepancy.includes('inversion')) {
            statusClass = 'style="color: #f59e0b;"';
            statusText = t.discrepancy;
            statusIcon = '⚠️';
        } else {
            statusClass = 'style="color: #64748b;"';
            statusText = t.unknown;
            statusIcon = '❓';
        }

        const match = discrepancy.match(/(.+?)\s*:\s*(.+?)\s*attendu,\s*(.+)/);
        let type = match ? match[1].trim() : discrepancy;
        let expected = match ? match[2].trim() : '';
        let found = match ? match[3].trim() : '';

        resultsHtml += `
            <tr style="border-bottom: 1px solid #e2e8f0;">
                <td style="padding: 10px; vertical-align: top;">${type}</td>
                <td style="padding: 10px; vertical-align: top;">${expected}</td>
                <td style="padding: 10px; vertical-align: top;">${found || `<span style="color: #94a3b8;">${t.not_specified}</span>`}</td>
                <td style="padding: 10px; vertical-align: top; ${statusClass}">${statusIcon} ${statusText}</td>
            </tr>
        `;
    });

    resultsHtml += `
                </tbody>
            </table>
        </div>
    `;
    $results.html(resultsHtml);
    $modal.fadeIn(300);
    $modal.off('click').on('click', function(e) {
        if (e.target === $modal[0]) {
            $modal.fadeOut(300);
        }
    });
}

function getUrlParam(param) {
    const urlParams = new URLSearchParams(window.location.search);
    return urlParams.get(param);
}