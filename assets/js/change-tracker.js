/**
 * DÉTECTION DES CHANGEMENTS SUR DES CHAMPS PRÉ-REMPLIS
 * Demande une raison avant de valider la modification (équipe interne),
 * ou enregistre silencieusement les modifications pour l'admin (clients sur devis/offres).
 */

// ── Vérifie si l'utilisateur est un client modifiant une offre ────────────
function isClientModifyingQuotation() {
    const projectTitleEl = document.getElementById('editable-project-title');
    const purchaseTitleEl = document.getElementById('editable-purchase-title');

    // Vérifier si c'est une offre (quotation) ou un achat (purchase)
    const isQuotation = projectTitleEl && projectTitleEl.getAttribute('data-is-quotation') === '1';
    const isPurchase = purchaseTitleEl && purchaseTitleEl.getAttribute('data-source') === 'purchase';

    // Utilisation de la variable globale transmise par wp_localize_script
    const isClient = typeof ispag_suivis !== 'undefined' && Boolean(ispag_suivis.is_client);
    // const isClient = true;

    return (isQuotation || isPurchase) && isClient;
}

// ── Snapshot des valeurs initiales au chargement de la modal ─────────────
function snapshotModalFields() {
    const snapshot = {};

    const allFields = document.querySelectorAll(`
        .ispag-edit-article-form input:not([type="hidden"]):not([type="checkbox"]),
        .ispag-edit-article-form select,
        .ispag-edit-article-form textarea
    `);

    allFields.forEach(field => {
        const name = field.name || field.id;
        if (!name) return;

        let value;
        if (field.tagName === 'SELECT') {
            if (field.hasAttribute('data-current-diameter')) {
                value = field.getAttribute('data-current-diameter');
            } else {
                value = field.options[field.selectedIndex]?.value;
                if (!value) {
                    const firstNonEmptyOption = Array.from(field.options).find(opt => opt.value && opt.value !== '');
                    value = firstNonEmptyOption?.value;
                }
            }
        } else {
            value = field.value?.trim();
        }

        if (value !== undefined && value !== null) {
            snapshot[name] = value;
        }
    });

    document.querySelectorAll('.ispag-edit-article-form input[type="checkbox"]').forEach(cb => {
        if (cb.name && !snapshot.hasOwnProperty(cb.name)) {
            snapshot[cb.name] = cb.checked;
        }
    });

    return snapshot;
}

// Stockage de la référence initiale
let fieldSnapshot = {};

document.addEventListener('modal_loaded', () => {
    const form = document.querySelector('.ispag-edit-article-form');
    if (!form) return;

    setTimeout(() => {
        fieldSnapshot = snapshotModalFields();
    }, 500);
});

// ── Détection des changements ─────────────────────────────────────────────
document.addEventListener('change', async function(e) {

    
    const form = e.target.closest('.ispag-edit-article-form');
    const projectTitleEl = document.getElementById('editable-project-title');
    const notPurchase = projectTitleEl && !projectTitleEl.getAttribute('data-source') === '1';
    const isQuotation = projectTitleEl && projectTitleEl.getAttribute('data-is-quotation') === '1';
    if (!form) return;

    const field = e.target;
    const name = field.name || field.id;
    if (!name || field.type === 'hidden') return;

    // Champs surveillés
    const watchedFields = [
        'tank[diameter]', 'tank[volume]', 'tank[height]', 'tank[max_pressure]',
        'tank[test_pressure]', 'tank[temperature]', 'tank[clearance]',
        'tank[materiau]', 'tank[support]', 'tank[type]',
        'tank[insulation]', 'tank[insulationCover]', 'tank[InsulationThickness]',
        'sales_price', 'discount', 'qty',
        'supplier', 'date_depart', 'date_eta',
    ];

    if (!watchedFields.includes(name)) return;

    // Valeur d'origine (fixe)
    const originalValue = fieldSnapshot[name];
    // Valeur actuelle
    const currentValue = field.type === 'checkbox' ? field.checked : field.value?.trim();

    const label = field.closest('.field-group, .ispag-field')
        ?.querySelector('label, strong')
        ?.textContent?.trim()
        ?? name;

    // ── CAS 1 : Client modifiant une offre (Aucune modale de raison) ──────
    if (isClientModifyingQuotation()) {
        registerOrUpdateClientChange(form, name, label, originalValue, currentValue);
        return;
    }
    if(isQuotation || !notPurchase){
        return;
    }

    // ── CAS 2 : Équipe interne (Demande de raison) ────────────────────────
    if (String(currentValue) === String(originalValue)) return;


    const reason = await ispagAskReason(
        `${ispag_texts.you_are_modifying}} <strong>${label}</strong> is client modifying : ${isClientModifyingQuotation()} `,
        `<em>${originalValue}</em> → <em>${currentValue}</em>`
    );

    if (reason === null) {
        // Restauration de la valeur initiale
        if (field.type === 'checkbox') {
            field.checked = originalValue;
        } else {
            field.value = originalValue;
            if (field.tagName === 'SELECT' && typeof $ !== 'undefined') {
                $(field).val(originalValue).trigger('change.restore');
            }
        }
        return;
    }

    fieldSnapshot[name] = currentValue;

    if (reason.trim()) {
        registerFieldChangeNote(form, name, label, originalValue, currentValue, reason);
    }
});

// ── Gestionnaire des notes pour les clients (Mise à jour / Suppresion) ───
function registerOrUpdateClientChange(form, fieldName, label, oldValue, newValue) {
    if (!form._changeNotes) {
        form._changeNotes = [];
    }

    // Si le client remet la valeur initiale, on retire la note
    if (String(oldValue) === String(newValue)) {
        form._changeNotes = form._changeNotes.filter(note => note.field !== fieldName);
        return;
    }

    // Index de la modification existante si déjà modifiée dans la même session
    const existingIndex = form._changeNotes.findIndex(note => note.field === fieldName);

    const note = {
        field: fieldName,
        label: label,
        old_value: oldValue,
        new_value: newValue,
        reason: "Modification par le client sur l'offre",
        at: new Date().toISOString(),
    };

    if (existingIndex !== -1) {
        form._changeNotes[existingIndex] = note; // Mettre à jour avec la dernière valeur
    } else {
        form._changeNotes.push(note); // Ajouter la nouvelle note
    }
}

// ── Collecteur de notes générique ────────────────────────────────────────
function registerFieldChangeNote(form, fieldName, label, oldValue, newValue, reason) {
    if (!form._changeNotes) {
        form._changeNotes = [];
    }

    form._changeNotes.push({
        field: fieldName,
        label: label,
        old_value: oldValue,
        new_value: newValue,
        reason: reason,
        at: new Date().toISOString(),
    });
}

// ── Modal de saisie de raison (interne) ───────────────────────────────────
function ispagAskReason(title, change) {
    return new Promise((resolve) => {
        let resolved = false;

        const close = (value) => {
            if (resolved) return;
            resolved = true;
            document.removeEventListener('keydown', onKey);
            overlay.remove();
            resolve(value);
        };

        const overlay = document.createElement('div');
        overlay.className = 'ispag-confirm-overlay';
        overlay.innerHTML = `
            <div class="ispag-confirm-box" style="max-width: 480px; background: white; padding: 20px; border-radius: var(--ispag-badge-border-radius); box-shadow: 0 4px 12px rgba(0,0,0,0.15);">
                <h4 style="margin: 0 0 10px; color: var(--ispag-red, #c0392b); font-size: 1.2em;">
                    ✏️ ${ispag_texts.modification_detected}
                </h4>
                <p style="margin: 0 0 10px; font-size: 1em;">${title}</p>
                <p style="margin: 0 0 20px; font-size: 0.9em; color: #666;">${change}</p>
                <label style="display: block; font-weight: 600; margin-bottom: 8px; font-size: 0.9em;">
                    ${ispag_texts.modification_reason} <span style="color: #c0392b;">*</span>
                </label>
                <textarea
                    class="js-reason-input"
                    rows="3"
                    placeholder="Ex :${ispag_texts.modification_reason_exemple}"
                    style="width: 100%; box-sizing: border-box; padding: 10px; border: 1px solid #ddd; border-radius: var(--ispag-btn-border-radius); font-size: 0.9em; resize: vertical;"
                ></textarea>
                <p class="js-reason-error" style="color: #c0392b; font-size: 0.85em; margin: 8px 0 0; display: none;">
                    ${ispag_texts.modification_pls_enter_reason}
                </p>
                <div class="ispag-confirm-actions" style="margin-top: 20px; display: flex; gap: 10px; justify-content: flex-end;">
                    <button class="ispag-btn ispag-btn-secondary-outlined js-reason-cancel" style="padding: 8px 16px;">
                        ${ispag_texts.cancel}
                    </button>
                    <button class="ispag-btn ispag-btn-red-outlined js-reason-confirm" style="padding: 8px 16px;">
                        ${ispag_texts.confirm}
                    </button>
                </div>
            </div>
        `;

        const textarea = overlay.querySelector('.js-reason-input');
        const errorMsg = overlay.querySelector('.js-reason-error');
        const confirmBtn = overlay.querySelector('.js-reason-confirm');
        const cancelBtn = overlay.querySelector('.js-reason-cancel');

        confirmBtn.addEventListener('click', () => {
            const reason = textarea.value.trim();
            if (!reason) {
                errorMsg.style.display = 'block';
                textarea.focus();
                return;
            }
            close(reason);
        });

        cancelBtn.addEventListener('click', () => close(null));

        overlay.addEventListener('click', (e) => {
            if (e.target === overlay) close(null);
        });

        const onKey = (e) => {
            if (e.key === 'Escape') close(null);
            if (e.key === 'Enter' && e.ctrlKey) confirmBtn.click();
        };
        document.addEventListener('keydown', onKey);

        document.body.appendChild(overlay);
        setTimeout(() => textarea.focus(), 50);
    });
}
// ── Soumission du formulaire & Envoi AJAX à l'admin ───────────────────────
jQuery(document).ready(function($) {
    $(document).on('submit', '.ispag-edit-article-form', async function (e) {
        const form = this;
        const articleId = $(form).data('article-id') || 0;

        const projectTitleEl = document.getElementById('editable-project-title');
    
        const dealId = projectTitleEl.getAttribute('data-deal') || 0;
        const dealName = projectTitleEl.textContent.trim();

        const isClientModifying = isClientModifyingQuotation();
        const hasNotes = form._changeNotes && form._changeNotes.length > 0;

        console.log('[CHANGE TRACKER] Tentative de soumission du formulaire', {
            articleId,
            dealId,
            isClientModifying,
            hasNotes,
            changeNotes: form._changeNotes || []
        });

        // Si des modifications client doivent être notifiées
        if (hasNotes && isClientModifying) {
            e.preventDefault(); // Suspend la soumission le temps d'envoyer la notification

            const payload = {
                action: 'ispag_notify_admin_quotation_changes',
                article_id: articleId,
                deal_id: dealId,
                deal_name: dealName,
                change_notes: JSON.stringify(form._changeNotes),
                _ajax_nonce: ispag_suivis.nonce
            };

            console.log('[CHANGE TRACKER] Envoi de la notification AJAX...', payload);

            try {
                const response = await $.post(ispag_suivis.ajax_url, payload);

                console.log('[CHANGE TRACKER] Réponse du serveur reçue :', response);

                // Vider les notes une fois envoyées
                form._changeNotes = [];

                console.log('[CHANGE TRACKER] Soumission finale du formulaire...');
                // form.submit();

            } catch (err) {
                console.error('[CHANGE TRACKER] Erreur lors de l\'envoi de la notification :', err);
                
                // Soumettre quand même le formulaire pour ne pas bloquer l'utilisateur en cas d'erreur AJAX
                // form.submit();
            }
        } else {
            console.log('[CHANGE TRACKER] Pas de modification client à notifier. Soumission standard.');
        }
    });
});