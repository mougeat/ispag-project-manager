document.addEventListener('DOMContentLoaded', function () {

    // --- 1. GESTION DES ONGLETS (Indépendante des droits d'édition) ---
    const tabs = document.querySelectorAll(".tab-titles li");
    const contents = document.querySelectorAll(".tab-content");

    tabs.forEach(tab => {
        tab.addEventListener("click", function () {
            const targetId = this.dataset.tab;
            if (!targetId) return;

            // Retirer les classes actives
            tabs.forEach(t => t.classList.remove("active"));
            contents.forEach(c => c.classList.remove("active"));

            // Ajouter les classes actives
            this.classList.add("active");
            const targetContent = document.getElementById(targetId);
            if (targetContent) {
                targetContent.classList.add("active");
            }
        });
    });

    // --- 2. GESTION DE L'URL (Paramètre ?delivery=true) ---
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.get('delivery') === 'true') {
        const detailTab = document.querySelector('.tab-titles li[data-tab="details"]');
        if (detailTab) detailTab.click(); // Utilise le clic simulé pour activer proprement
    }

    // --- 3. GESTION DE L'ÉDITION INLINE (Adaptée pour Select2 et Fetch) ---
    jQuery(document).ready(function($) {

        $('.ispag-inline-edit').on('click', function(e) {
            const $el = $(this);
            
            // Sécurité : déjà en édition ou lecture seule
            if ($el.find('input, select').length > 0 || $el.data('readonly')) return;
            if (!$el.find('.edit-icon').length) return;

            const currentValue = $el.attr('data-value') || ''; 
            const fieldName    = $el.data('name');
            const dealId       = $el.data('deal');
            const source       = $el.data('source') || 'delivery';
            const fieldType    = $el.data('field-type') || 'text';
            const isSupplier   = $el.data('is-supplier');

            let isSaving = false;

            if (isSupplier) {
                // --- CAS FOURNISSEUR (SELECT2) ---
                let $select = $('<select class="ispag-select2-inline"></select>');
                $select.append('<option value="">Sélectionner...</option>');
                
                $('#ispag-fournisseurs-source option').each(function() {
                    let val = $(this).val();
                    let isSelected = (val === currentValue) ? 'selected' : '';
                    $select.append(`<option value="${val}" ${isSelected}>${val}</option>`);
                });

                $el.empty().append($select);

                $select.select2({
                    width: '250px',
                    dropdownParent: $el.parent()
                }).select2('open');

                $select.on('select2:select', function(e) {
                    if (!isSaving) {
                        isSaving = true;
                        saveField(e.params.data.id);
                    }
                });

                $select.on('select2:close', function() {
                    setTimeout(() => {
                        if ($el.find('select').length > 0 && !isSaving) {
                            restoreOriginal();
                        }
                    }, 300);
                });

            } else {
                // --- CAS STANDARD (INPUT TEXT / DATE) ---
                const nativeInput = document.createElement('input');
                nativeInput.type = fieldType;
                nativeInput.className = 'ispag-inline-input';
                nativeInput.style.width = (fieldType === 'date') ? '150px' : '200px';

                // Conversion format date (DD.MM.YYYY -> YYYY-MM-DD) pour l'input HTML5
                if (fieldType === 'date' && currentValue.includes('.')) {
                    const parts = currentValue.split('.');
                    if(parts.length === 3) {
                        nativeInput.value = `${parts[2]}-${parts[1]}-${parts[0]}`;
                    }
                } else {
                    nativeInput.value = currentValue;
                }

                $el.empty().append(nativeInput);
                nativeInput.focus();

                const triggerSave = () => {
                    if (isSaving) return;
                    isSaving = true;
                    saveField(nativeInput.value);
                };

                if (fieldType === 'date') {
                    // Sauvegarde quand on choisit une date dans le calendrier
                    nativeInput.addEventListener('change', triggerSave);
                    
                    // Gestion du focus pour restaurer si aucune modif
                    nativeInput.addEventListener('blur', () => {
                        setTimeout(() => {
                            if (!isSaving) {
                                // Si l'utilisateur a effacé le champ, on déclenche la sauvegarde du vide
                                if (nativeInput.value !== currentValue) {
                                    triggerSave();
                                } else {
                                    restoreOriginal();
                                }
                            }
                        }, 300);
                    });
                } else {
                    // Pour le texte : sauvegarde au "blur" (quand on clique ailleurs)
                    nativeInput.addEventListener('blur', () => { 
                        setTimeout(() => { 
                            if ($el.find(nativeInput).length > 0 && !isSaving) {
                                triggerSave();
                            }
                        }, 250); 
                    });
                }

                nativeInput.addEventListener('keydown', (e) => {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        triggerSave();
                    }
                    if (e.key === 'Escape') {
                        isSaving = true; 
                        restoreOriginal();
                    }
                });
            }

            // --- FONCTION DE SAUVEGARDE ---
            function saveField(newValue) {
                let displayValue = newValue;
                let valueToSend = newValue;

                // Gestion spécifique du format Date pour le serveur (Timestamp)
                if (fieldType === 'date') {
                    if (newValue === '' || !newValue) {
                        valueToSend = '';
                        displayValue = '---';
                    } else {
                        const dateObj = new Date(newValue);
                        if (isNaN(dateObj.getTime())) {
                            isSaving = false;
                            restoreOriginal();
                            return;
                        }
                        // On envoie le timestamp au serveur
                        valueToSend = Math.floor(dateObj.getTime() / 1000);
                        // On formate l'affichage en DD.MM.YYYY
                        const parts = newValue.split('-');
                        displayValue = `${parts[2]}.${parts[1]}.${parts[0]}`;
                    }
                }

                fetch(ajaxurl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({
                        action: 'ispag_inline_edit_field',
                        field: fieldName,
                        value: valueToSend,
                        deal_id: dealId,
                        source: source
                    })
                })
                .then(res => res.json())
                .then(res => {
                    if (res.success) {
                        $el.attr('data-value', displayValue);
                        $el.html(displayValue + ' <span class="edit-icon">✏️</span>');
                    } else {
                        alert('Erreur lors de la sauvegarde');
                        restoreOriginal();
                    }
                })
                .catch(() => {
                    restoreOriginal();
                })
                .finally(() => {
                    isSaving = false;
                });
            }

            function restoreOriginal() {
                $el.html((currentValue || '---') + ' <span class="edit-icon">✏️</span>');
            }
        });
    });
});