/**
 * ISPAG TANK BUILDER - JAVASCRIPT COMPLET
 * Gère l'upload, l'analyse IA, l'extraction DXF et la confirmation des données.
 */

document.addEventListener("DOMContentLoaded", () => {
    const dropzone = document.getElementById("dropzone");
    if (!dropzone) return;

    const fileInput = document.getElementById("file_input");
    const browseBtn = document.getElementById("browse-file");
    const form = document.getElementById("ispag-upload-form");
    const status = document.getElementById("upload-status");

    const defaultDropzoneHTML = ispag_ajax_obj.drag_files_here + ' ' + ispag_ajax_obj.or + ' <button type="button" id="browse-file">' + ispag_ajax_obj.browse + '</button>';

    function resetDropzone() {
        dropzone.innerHTML = defaultDropzoneHTML;
        const newBrowseBtn = document.getElementById("browse-file");
        if(newBrowseBtn) {
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

    if(browseBtn) {
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

    form.addEventListener("submit", async e => {
        e.preventDefault();
        jQuery('body').css('cursor', 'wait'); 

        const files = fileInput.files;
        const select = document.getElementById("doc_type");
        const selectedOption = select.options[select.selectedIndex];

        const articleId = selectedOption.dataset.productId;
        const classCss = selectedOption.dataset.class;
        const dealId = form.deal_id.value;
        const poid = getUrlParam('poid');

        if (!files.length || !classCss) {
            alert(ispag_ajax_obj.please_select_file + ".");
            jQuery('body').css('cursor', 'default');
            return;
        }

        const formData = new FormData();
        formData.append("action", "ispag_upload_document");
        formData.append("doc_type", classCss);
        formData.append("article_id", articleId);
        formData.append("deal_id", dealId);
        formData.append("poid", poid);

        for (let i = 0; i < files.length; i++) {
            formData.append("files[]", files[i]);
        }

        status.innerText = "⏳ " + ispag_ajax_obj.Upload_in_progress + " ...";

        try {
            const res = await fetch(ajaxurl, { method: "POST", body: formData });
            const json = await res.json();

            if (json.success) {
                status.innerText = "✅ " + ispag_ajax_obj.File_added_successfully + " !";
                fileInput.value = "";
                resetDropzone();

                fetch(ajaxurl, {
                    method: "POST",
                    headers: { "Content-Type": "application/x-www-form-urlencoded" },
                    body: new URLSearchParams({
                        action: "ispag_get_documents_list",
                        deal_id: dealId,
                        poid: poid,
                    })
                })
                .then(res => res.json())
                .then(json => {
                    if (json.success) {
                        document.querySelector(".ispag-documents-list").innerHTML = json.data;
                        jQuery(".ispag-articles-list").html(json.data.articles_list);
                        if (typeof reloadArticleList === "function") reloadArticleList();
                    }
                    jQuery('body').css('cursor', 'default');
                });
            } else {
                status.innerText = "❌ Erreur : " + json.data;
                jQuery('body').css('cursor', 'default');
            }
        } catch (error) {
            status.innerText = "❌ Erreur lors de l’upload.";
            jQuery('body').css('cursor', 'default');
        }
    });

    dropzone.addEventListener('dragover', (e) => { e.preventDefault(); dropzone.classList.add('is-dragover'); });
    dropzone.addEventListener('dragleave', () => { dropzone.classList.remove('is-dragover'); });
    dropzone.addEventListener('drop', (e) => {
        e.preventDefault();
        dropzone.classList.remove('is-dragover');
        if (e.dataTransfer.files.length > 0) {
            fileInput.files = e.dataTransfer.files;
            let names = Array.from(fileInput.files).map(f => f.name).join(', ');
            dropzone.innerHTML = `📎 ${names} ` + ispag_ajax_obj.selected;
        }
    });
});

/**
 * GESTION DES ACTIONS ET MODALES (CORE)
 */
jQuery(document).ready(function($) {
    
    // Suppression de document
    $('.ispag-documents-list').on('click', '.delete-doc-btn', function(e) {
        e.preventDefault();
        if (!confirm(ispag_ajax_obj.really_dele_doc + ' ?')) return;
        $('body').css('cursor', 'wait'); 
        const $li = $(this).closest('li.document-item');
        const docId = $li.data('doc-id');

        $.ajax({
            url: ajaxurl,
            method: 'POST',
            data: { action: 'ispag_delete_document', document_id: docId, _ajax_nonce: ispag_ajax_obj.nonce },
            success: function(response) {
                if (response.success) { $li.fadeOut(300, function() { $(this).remove(); }); }
            },
            complete: () => { $('body').css('cursor', 'default'); }
        });
    });

    // Enregistrement historique
    $(document).on('click', 'a[data-media-id]', function() {
        var mediaId = $(this).data('media-id');
        $.post(ajaxurl, { action: 'ispag_save_historique_views', media_id: mediaId, _ajax_nonce: ispag_ajax_obj.nonce });
    });

    // Boutons Analyse / Extraction
    $(document).on('click', '.extract-doc-btn, #sketch-pdf', function() {
        var button = $(this);
        var docId = button.data('doc-id');
        var dealId = button.data('deal-id');
        var purchaseId = button.data('purchase-id');
        var docType = button.data('doc-type');
        var tank_id = button.data('tank-id');
        var originalHtml = button.html();

        button.prop('disabled', true).html('<span class="dashicons dashicons-update spin"></span>');
        sendPdfForAnalysis(docId, dealId, purchaseId, button, docType, originalHtml, tank_id);
    }); 

    // FERMETURE GLOBALE
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

function sendPdfForAnalysis(docId, dealId, purchaseId, button, docType, originalHtml, tank_id) {
    let actionName;
    var tankSketch = button.data('tank-sketch');
    
    if (tankSketch) {
        tank_id = tankSketch;
        console.log('tank_data_extractor', dealId);
        actionName = 'tank_data_extractor';
    } else if (purchaseId) {
        actionName = 'analyze_and_confirm_data';
    } else if (dealId && tank_id == 0) {
        actionName = 'analyze_project_data';
    } else if (docId && tank_id != 0) {
        actionName = 'analyze_drawing';
        let container = jQuery('#ispag-drawing-result-' + tank_id);
        if(container.length > 0) container.html('<p style="font-size:11px; color:#666;">⏳ Analyse...</p>');
    } 
 
    // console.log('Action sendPdfForAnalysis', actionName);

    if (!actionName) {
        button.prop('disabled', false).html(originalHtml);
        return;
    }

    jQuery.ajax({
        url: ajaxurl,
        type: 'POST',
        data: { action: actionName, docId: docId, docType: docType, deal_id: dealId, purchaseId: purchaseId, tankId: tank_id },
        success: function(response) {
            if(response.success) {
                
                const result = response.data;
                
                console.log('sendPdfForAnalysis result', result);
                if (actionName === 'analyze_drawing' && result.comparison) {
                    // console.log('displayDrawingAnalysis');
                    displayDrawingAnalysis(result.comparison, tank_id, button, result.cached || false);
                } else if (actionName === 'tank_data_extractor') {
                    console.log('displayDxfCode avec les données :', result.tank_specs);
                    displayDxfCode(result.tank_specs, result.project, tank_id);
                } else if (result.needs_confirmation) {
                    // console.log('showConfirmationModal');
                    showConfirmationModal(result.datas_to_confirm, result.existing_datas);
                } else {
                    // console.log('updateData');
                    updateData(result.data);
                }
            } else {
                alert("Erreur : " + (response.data.message || "L'API n'a pas pu répondre."));
            }
        },
        complete: () => { button.prop('disabled', false).html(originalHtml); }
    });
}

function displayDxfCode(tankSpecs, project, tank_id) {
    // 1. Générer les entités
    const entities = IspagDxfEngine.generateEntities(tankSpecs, project);
    
    const modal = jQuery("#ispag-analysis-modal");
    const content = modal.find('#ispag-analysis-modal-body');
    
    let html = `<h3>Plan technique : Cuve #${tank_id}</h3>`;
    
    // 2. Création du bouton sans passer le JSON dans le onclick
    html += `<div style="margin-top:20px;">
                <button type="button" id="btn-download-dxf" class="button button-primary">
                    💾 Télécharger le fichier .DXF
                </button>
             </div>`;

    content.html(html);

    // 3. On attache les données et l'événement proprement en JS
    const btn = jQuery('#btn-download-dxf');
    btn.data('dxf-entities', entities); // Stockage sécurisé de l'objet
    
    btn.on('click', function() {
        const storedEntities = jQuery(this).data('dxf-entities');
        downloadDxfFile(storedEntities, tank_id);
    });

    modal.fadeIn(200);
}

function downloadDxfFile(entities, tank_id) {
    let dxf = "0\nSECTION\n2\nENTITIES\n";

    entities.forEach(e => {
        const fx = (val) => (typeof val === 'number') ? val.toFixed(4) : "0.0000";

        if (e.type === 'LINE') {
            dxf += `0\nLINE\n8\n${e.layer}\n62\n${e.color || 7}\n`;
            dxf += `10\n${fx(e.start.x)}\n20\n${fx(e.start.y)}\n`;
            dxf += `11\n${fx(e.end.x)}\n21\n${fx(e.end.y)}\n`;
        } 
        else if (e.type === 'ELLIPSE') {
            dxf += `0\nELLIPSE\n8\n${e.layer}\n62\n${e.color || 7}\n`;
            dxf += `10\n${fx(e.center.x)}\n20\n${fx(e.center.y)}\n`;
            dxf += `11\n${fx(e.major_axis.x)}\n21\n${fx(e.major_axis.y)}\n`;
            dxf += `40\n${fx(e.ratio)}\n41\n${fx(e.start_param)}\n42\n${fx(e.end_param)}\n`;
        }
        else if (e.type === 'MTEXT') {
            dxf += `0\nMTEXT\n8\n${e.layer}\n62\n${e.color || 7}\n`;
            dxf += `10\n${fx(e.point.x)}\n20\n${fx(e.point.y)}\n30\n0.0\n`;
            dxf += `40\n${e.height || 25}\n`; 
            dxf += `50\n${e.rotation || 0}\n`;
            dxf += `1\n${e.text}\n`;
        }
    });

    dxf += "0\nENDSEC\n0\nEOF";

    const blob = new Blob([dxf], { type: 'application/dxf' });
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = `ISPAG_${tank_id}.dxf`;
    document.body.appendChild(link); // Recommandé pour certains navigateurs
    link.click();
    document.body.removeChild(link);
}
/**
 * GESTION DE LA CONFIRMATION DES DONNÉES (MODALE SECONDAIRE)
 */
function showConfirmationModal(datas_to_confirm, existing_datas) {
    const modal = jQuery("#confirmationModal");
    const content = modal.find('#ispag-confirmation-modal-body');
    let currentDataIndex = 0; 
    const existingDataArray = Array.isArray(existing_datas) ? existing_datas : [];
    
    // On récupère les champs extraits par l'IA
    const ai_fields = datas_to_confirm[0].fields;

    const updateExistingValues = (index) => {
        if (existingDataArray.length === 0) return;
        const currentExistingTank = existingDataArray[index];
        
        jQuery('#data-index-display').text(`${index + 1} / ${existingDataArray.length}`);
        
        jQuery('#confirmationForm tbody tr').each(function() {
            const $row = jQuery(this);
            const key = $row.data('key'); 
            const aiValue = $row.find('.new-value-cell').data('new-value');
            const existingVal = currentExistingTank[key] ?? 'Non spécifié';
            
            // Mise à jour de la cellule "Actuel"
            const cell = $row.find('.existing-value-cell');
            cell.text(existingVal);

            // Comparaison visuelle
            if (key !== 'Id') {
                if (String(aiValue) !== String(existingVal)) {
                    $row.addClass('row-different').css('background-color', '#fff3cd');
                } else {
                    $row.removeClass('row-different').css('background-color', 'transparent');
                }
            } else {
                // MISE À JOUR CRITIQUE : On change le texte de la cellule "Nouveau" pour l'ID
                // afin que updateData récupère l'ID de la cuve actuellement affichée à gauche
                $row.find('.new-value-cell').text(existingVal).data('new-value', existingVal);
                $row.find('input[type="checkbox"]').prop('checked', true);
            }
        });

        jQuery('#prev-existing-btn').prop('disabled', index === 0);
        jQuery('#next-existing-btn').prop('disabled', index >= existingDataArray.length - 1);
    };

    // Construction du HTML
    let html = '<h3>Vérification des correspondances :</h3>';
    html += '<div class="navigation-info" style="background:#f1f1f1; padding:10px; border-radius:4px; margin-bottom:10px; display:flex; align-items:center; gap:10px;">' +
            '<span>Cuve projet :</span>' +
            '<button type="button" id="prev-existing-btn" class="button">⬅️</button> ' +
            '<span id="data-index-display" style="font-weight:bold; min-width:40px; text-align:center;"></span> ' +
            '<button type="button" id="next-existing-btn" class="button">➡️</button>' +
            '</div>';
    
    html += '<form id="confirmationForm"><table class="wp-list-table widefat fixed striped">';
    html += '<thead><tr><th width="30"></th><th>Champ</th><th>En base (Projet)</th><th>Trouvé (IA)</th></tr></thead><tbody>';

    ai_fields.forEach(item => {
        const safeKey = item.key || 'unknown';
        const label = safeKey.replace(/_/g, ' ');
        let newValue = item.new ?? '---';
        const isChecked = (safeKey === 'Id' || item.match === false) ? 'checked' : '';

        html += `<tr data-key="${safeKey}">
            <td><input type="checkbox" name="confirm_field[]" value="${safeKey}" ${isChecked}></td>
            <td><strong>${label}</strong></td>
            <td class="existing-value-cell">---</td>
            <td class="new-value-cell" data-new-value="${newValue}">${newValue}</td>
        </tr>`;
    });
    
    html += '</tbody></table></form>';

    content.html(html);
    modal.show();
    updateExistingValues(0);

    // --- GESTION DES ÉVÉNEMENTS ---

    // Navigation
    jQuery('#prev-existing-btn').off('click').on('click', () => { if (currentDataIndex > 0) updateExistingValues(--currentDataIndex); });
    jQuery('#next-existing-btn').off('click').on('click', () => { if (currentDataIndex < existingDataArray.length - 1) updateExistingValues(++currentDataIndex); });

    // BOUTON CONFIRMER (C'est ce bloc qui manquait ou n'était pas actif)
    jQuery('#confirmButton').off('click').on('click', function(e) {
        e.preventDefault();
        
        const dataToUpdate = {};
        let idFound = false;

        // On parcourt uniquement les lignes dont la case est cochée
        jQuery('#confirmationForm input[name="confirm_field[]"]:checked').each(function() {
            const $row = jQuery(this).closest('tr');
            const key = jQuery(this).val();
            const val = $row.find('.new-value-cell').data('new-value');
            
            dataToUpdate[key] = val;
            if (key === 'Id') idFound = true;
        });

        if (!idFound) {
            alert("Erreur : L'ID de la cuve doit être sélectionné pour enregistrer.");
            return;
        }

        console.log("Données envoyées :", dataToUpdate);
        
        // Appel de la fonction de sauvegarde
        updateData(dataToUpdate);
        modal.hide();
    });
}
 
function updateData(dataToUpdate) {
    // console.log('Data to update', dataToUpdate);
    // console.log('Purchase id', jQuery('.extract-doc-btn').data('purchase-id'));
    const postData = {
        action: 'ispag_save_confirmed_data',
        deal_id: jQuery('.extract-doc-btn').data('deal-id'),
        purchase_id: jQuery('.extract-doc-btn').data('purchase-id'), 
        article_id: dataToUpdate['Id'],
        data: dataToUpdate
    };

    if(postData['purchase_id'] != 0){
        jQuery.ajax({
            url: ajaxurl,
            type: 'POST',
            data: postData,
            success: function(response) {
                console.log(response);
                if (response.success) {
                    alert('Données enregistrées !');
                    // location.reload(); // Optionnel : recharger pour voir les changements
                } else {
                    alert('Erreur : ' + response.data);
                }
            }
        });
    }
    else{
        location.reload();
    }
}

/**
 * UTILS
 */
function getUrlParam(param) {
    const urlParams = new URLSearchParams(window.location.search);
    return urlParams.get(param);
}