/**
 * DÉTECTION DES CHANGEMENTS SUR LES RACCORDS ET SOUDURES (#fittings-form)
 * Gestion des modifications, ajouts, duplications et suppressions avec envoi de texte lisible
 */

// ── Fonction utilitaire pour extraire le texte affiché d'un champ ──────────
function getFieldDisplayValue(field) {
    if (!field) return '';
    if (field.tagName === 'SELECT') {
        const selectedOption = field.options[field.selectedIndex];
        return selectedOption ? selectedOption.text.trim() : '';
    } else if (field.type === 'checkbox') {
        return field.checked;
    } else {
        return field.value ? field.value.trim() : '';
    }
}

// ── Snapshot initial des lignes de raccords / soudures ────────────────────
function snapshotFittingsFields() {
    const snapshot = {};
    const form = document.getElementById('fittings-form');
    if (!form) {
        console.warn('[FITTINGS TRACKER] Formulaire #fittings-form introuvable.');
        return snapshot;
    }

    const rows = form.querySelectorAll('.fitting-row');
    rows.forEach(row => {
        const fittingId = row.getAttribute('data-id');
        if (!fittingId) return;

        const rowData = {};
        const fields = row.querySelectorAll('input:not([type="hidden"]), select, textarea');

        fields.forEach(field => {
            const name = field.name || field.id;
            if (!name) return;

            const match = name.match(/\[(.*?)\]/);
            const key = match ? match[1] : name;

            rowData[key] = getFieldDisplayValue(field);
        });

        snapshot[fittingId] = rowData;
    });

    console.log('[FITTINGS TRACKER] Snapshot initial capturé (en texte) :', snapshot);
    return snapshot;
}

// Stockage de la référence initiale pour les fittings
let fittingsSnapshot = {};

document.addEventListener('modal_fitting_loaded', () => {
    console.log('[FITTINGS TRACKER] Événement modal_fitting_loaded détecté. Initialisation du snapshot...');
    setTimeout(() => {
        fittingsSnapshot = snapshotFittingsFields();
    }, 500);
});

// ── Détection des changements de champs dans le formulaire ────────────────
document.addEventListener('change', async function(e) {
    const form = e.target.closest('#fittings-form');
    if (!form) return;

    const projectTitleEl = document.getElementById('editable-project-title');
    const notPurchase = projectTitleEl && !projectTitleEl.getAttribute('data-source') === '1';
    const isQuotation = projectTitleEl && projectTitleEl.getAttribute('data-is-quotation') === '1';

    const row = e.target.closest('.fitting-row');
    if (!row) return;

    const fittingId = row.getAttribute('data-id');
    if (!fittingId) {
        console.log('[FITTINGS TRACKER] Modification sur une nouvelle ligne (sans data-id).');
        return;
    }

    const field = e.target;
    const name = field.name || field.id;
    if (!name || field.type === 'hidden') return;

    const match = name.match(/\[(.*?)\]/);
    const key = match ? match[1] : name;

    const originalValue = fittingsSnapshot[fittingId]?.[key] ?? '';
    const currentValue = getFieldDisplayValue(field);

    console.log(`[FITTINGS TRACKER] Changement détecté sur [Ligne ID: ${fittingId}] [Champ: ${key}]`, {
        originalValue,
        currentValue
    });

    const labelEl = row.querySelector(`[name="${name}"]`)
        ?.closest('.ispag-input-wrapper')
        ?.querySelector('label');
    
    const fieldLabel = labelEl ? labelEl.textContent.trim() : key;
    const rowTitle = row.querySelector('select[name="fitting[accessories][]"], select[name="fitting[type][]"]')
        ?.selectedOptions?.[0]?.text || `Élément #${fittingId}`;

    const fullLabel = `${rowTitle} (${fieldLabel})`;

    if (isClientModifyingQuotation()) {
        console.log('[FITTINGS TRACKER] Mode client détecté - Enregistrement silencieux du texte.');
        registerOrUpdateFittingChange(form, fittingId, key, fullLabel, originalValue, currentValue);
        return;
    }

    if(isQuotation || !notPurchase){
        return;
    }

    if (String(currentValue) === String(originalValue)) {
        console.log('[FITTINGS TRACKER] Valeur identique à l\'origine, aucune action requise.');
        return;
    }

    const reason = await ispagAskReason(
        `${ispag_texts.you_are_modifying} <strong>${fullLabel}</strong>`,
        `<em>${originalValue || '(vide)'}</em> → <em>${currentValue || '(vide)'}</em>`
    );

    if (reason === null) {
        console.log('[FITTINGS TRACKER] Modification annulée par l\'utilisateur. Restauration.');
        if (field.type === 'checkbox') {
            field.checked = (originalValue === true || originalValue === 'true');
        } else if (field.tagName === 'SELECT') {
            let targetOption = Array.from(field.options).find(opt => opt.text.trim() === originalValue);
            if (targetOption) {
                field.value = targetOption.value;
                if (typeof $ !== 'undefined') {
                    $(field).val(targetOption.value).trigger('change.restore');
                }
            }
        } else {
            field.value = originalValue;
        }
        return;
    }

    if (!fittingsSnapshot[fittingId]) {
        fittingsSnapshot[fittingId] = {};
    }
    fittingsSnapshot[fittingId][key] = currentValue;

    if (reason.trim()) {
        console.log('[FITTINGS TRACKER] Raison enregistrée :', reason);
        registerFittingChangeNote(form, fittingId, key, fullLabel, originalValue, currentValue, reason);
    }
});

// ── Détection de la SUPPRESSION d'une ligne ──────────────────────────────
document.addEventListener('click', function(e) {
    const deleteBtn = e.target.closest('.btn-delete-fitting');
    if (!deleteBtn) return;

    const form = document.getElementById('fittings-form');
    if (!form) return;

    const row = deleteBtn.closest('.fitting-row');
    if (!row) return;

    const fittingId = row.getAttribute('data-id') || deleteBtn.getAttribute('data-fitting-id');
    if (!fittingId) return;

    // Récupérer le titre de la ligne pour l'intitulé de la note
    const rowTitle = row.querySelector('select[name="fitting[accessories][]"], select[name="fitting[type][]"]')
        ?.selectedOptions?.[0]?.text || `Élément #${fittingId}`;

    const label = `${rowTitle} (Ligne supprimée)`;
    const noteId = `fitting_delete_${fittingId}`;

    if (!form._changeNotes) {
        form._changeNotes = [];
    }

    // Ajouter ou mettre à jour la note de suppression dans le tableau
    const existingIndex = form._changeNotes.findIndex(note => note.id === noteId);
    const note = {
        id: noteId,
        field: `fitting[${fittingId}][deleted]`,
        label: label,
        old_value: 'Présent',
        new_value: 'Supprimé',
        reason: isClientModifyingQuotation() ? "Suppression par le client sur les raccords/soudures" : "Suppression de ligne",
        at: new Date().toISOString(),
    };

    if (existingIndex !== -1) {
        form._changeNotes[existingIndex] = note;
    } else {
        form._changeNotes.push(note);
    }

    console.log('[FITTINGS TRACKER] Ligne marquée comme supprimée. _changeNotes :', form._changeNotes);
});

// ── Gestionnaires de notes pour les fittings ──────────────────────────────
function registerOrUpdateFittingChange(form, fittingId, fieldKey, label, oldValue, newValue) {
    if (!form._changeNotes) {
        form._changeNotes = [];
    }

    const noteId = `fitting_${fittingId}_${fieldKey}`;

    if (String(oldValue) === String(newValue)) {
        form._changeNotes = form._changeNotes.filter(note => note.id !== noteId);
        console.log('[FITTINGS TRACKER] Valeur initiale rétablie, note supprimée du panier.');
        return;
    }

    const existingIndex = form._changeNotes.findIndex(note => note.id === noteId);

    const note = {
        id: noteId,
        field: `fitting[${fittingId}][${fieldKey}]`,
        label: label,
        old_value: oldValue,
        new_value: newValue,
        reason: "Modification par le client sur les raccords/soudures",
        at: new Date().toISOString(),
    };

    if (existingIndex !== -1) {
        form._changeNotes[existingIndex] = note;
    } else {
        form._changeNotes.push(note);
    }

    console.log('[FITTINGS TRACKER] Liste actuelle des _changeNotes :', form._changeNotes);
}

function registerFittingChangeNote(form, fittingId, fieldKey, label, oldValue, newValue, reason) {
    if (!form._changeNotes) {
        form._changeNotes = [];
    }

    form._changeNotes.push({
        id: `fitting_${fittingId}_${fieldKey}`,
        field: `fitting[${fittingId}][${fieldKey}]`,
        label: label,
        old_value: oldValue,
        new_value: newValue,
        reason: reason,
        at: new Date().toISOString(),
    });

    console.log('[FITTINGS TRACKER] Liste actuelle des _changeNotes (Interne) :', form._changeNotes);
}

/**
 * SOUMISSION DU FORMULAIRE FITTINGS & ENVOI AJAX À L'ADMIN
 */
jQuery(document).ready(function($) {
    document.addEventListener('modal_fitting_closed', async function (e) {
        
        const form = document.getElementById('fittings-form');
        
        if (!form) {
            console.warn('[FITTINGS TRACKER] Formulaire #fittings-form introuvable lors de la fermeture.');
            return;
        }
        
        const articleId = $('#current-editing-article-id').val() || $(form).data('article-id') || 0;
        const projectTitleEl = document.getElementById('editable-project-title');
        const dealId = projectTitleEl ? (projectTitleEl.getAttribute('data-deal') || 0) : 0;
        const dealName = projectTitleEl ? projectTitleEl.textContent.trim() : 'Updating fittings';

        const isClientModifying = isClientModifyingQuotation();
        const hasNotes = form._changeNotes && form._changeNotes.length > 0;

        console.log('[FITTINGS TRACKER] Tentative de soumission du formulaire #fittings-form', {
            articleId,
            dealId,
            dealName,
            isClientModifying,
            hasNotes,
            changeNotes: form._changeNotes || []
        });

        if (hasNotes && isClientModifying) {
            const payload = {
                action: 'ispag_notify_admin_quotation_changes',
                article_id: articleId,
                deal_id: dealId,
                deal_name: dealName,
                change_notes: JSON.stringify(form._changeNotes),
                _ajax_nonce: typeof ispag_suivis !== 'undefined' ? ispag_suivis.nonce : ''
            };

            console.log('[FITTINGS TRACKER] Envoi de la notification AJAX à l\'admin...', payload);

            try {
                const ajaxUrl = typeof ispag_suivis !== 'undefined' ? ispag_suivis.ajax_url : ajaxurl;
                const response = await $.post(ajaxUrl, payload);

                console.log('[FITTINGS TRACKER] Réponse du serveur reçue :', response);
                form._changeNotes = [];
                console.log('[FITTINGS TRACKER] Notification envoyée avec succès.');

            } catch (err) {
                console.error('[FITTINGS TRACKER] Error lors de l\'envoi de la notification :', err);
            }
        } else {
            console.log('[FITTINGS TRACKER] Pas de modification client à notifier ou mode non-client. Soumission standard.');
        }
    });
});