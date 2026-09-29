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

    // --- 3. FORMATAGE DES NUMÉROS DE TÉLÉPHONE AU CHARGEMENT ---
    // Fonction pour formater un numéro de téléphone avec intlTelInputUtils
    function formatPhoneNumber(rawValue, element) {
        if (!rawValue || rawValue === '---' || rawValue === '-') {
            element.textContent = rawValue;
            return;
        }

        // Vérifiez que intlTelInputUtils est chargé
        if (typeof intlTelInputUtils !== 'undefined') {
            try {
                // Formatage en mode international (ex: +41 12 345 67 89)
                const formatted = intlTelInputUtils.formatNumber(
                    rawValue,
                    "CH", // Pays par défaut (Suisse)
                    intlTelInputUtils.numberFormat.INTERNATIONAL
                );
                element.textContent = formatted;
            } catch (e) {
                console.error("Erreur de formatage du téléphone :", e);
                element.textContent = rawValue; // Fallback
            }
        } else {
            // Si intlTelInputUtils n'est pas chargé, on affiche la valeur brute
            element.textContent = rawValue;
        }
    }

    // Appliquez le formatage à tous les éléments .ispag-phone-display
    function formatAllPhones() {
        const phoneDisplays = document.querySelectorAll('.ispag-phone-display');
        phoneDisplays.forEach(el => {
            const rawValue = el.textContent.trim();
            formatPhoneNumber(rawValue, el);
        });
    }

    // Attendez que intlTelInputUtils soit chargé
    const checkUtils = setInterval(() => {
        if (typeof intlTelInputUtils !== 'undefined') {
            clearInterval(checkUtils);
            formatAllPhones();
        }
    }, 100);

    // --- 4. GESTION DE L'ÉDITION INLINE (jQuery) ---
    jQuery(document).ready(function($) {

        // ── Lookup code postal → ville ────────────────────────────────────────
        function fetchCityFromPostalCode(postalCode, dealId, source) {
            const country = postalCode.toString().length <= 4 ? 'CH' : 'FR';
            const url     = `https://api.zippopotam.us/${country}/${postalCode}`;

            fetch(url)
                .then(res => res.ok ? res.json() : null)
                .then(data => {
                    const city = data?.places?.[0]?.['place name'];
                    if (!city) return;

                    // ── Mise à jour visuelle du champ City ───────────────────────
                    const $cityEl = $(`.ispag-inline-edit[data-name="City"][data-deal="${dealId}"]`);
                    if ($cityEl.length) {
                        $cityEl.attr('data-value', city);
                        $cityEl.html(city + ' <span class="edit-icon">✏️</span>');
                    }

                    // ── Sauvegarde automatique en base ───────────────────────────
                    fetch(ajaxurl, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: new URLSearchParams({
                            action:  'ispag_inline_edit_field',
                            field:   'City',
                            value:   city,
                            deal_id: dealId,
                            source:  source
                        })
                    });
                })
                .catch(err => console.warn('[GEO] Code postal introuvable :', err));
        }

        // $('.ispag-inline-edit').on('click', function(e) {
        
        $(document).on('click', '.ispag-inline-edit', function(e) {
            
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

            // --- CAS SPÉCIFIQUE POUR LES CHAMPS SELECT2 (INGÉNIEUR / CONCURRENT) ---// Dans la partie où tu gères le clic sur .ispag-inline-edit
            if (fieldType.startsWith('select2-')) {
                const selectType = fieldType.replace('select2-', '');
                const $selectContainer = $('<div class="ispag-select2-container" style="min-width: 200px;"></div>');
                const $select = $('<select class="ispag-select2-inline" style="min-width: 200px;"></select>');

                $el.empty().append($selectContainer.append($select));

                // Configuration de Select2 avec un placeholder correctement formaté
                const placeholderText = (selectType === 'ingenieur_id')
                    ? "Chercher un bureau d'ingénieur..."
                    : "Chercher un concurrent...";

                const select2Config = {
                    placeholder: placeholderText,
                    minimumInputLength: 2,
                    allowClear: true,
                    width: '100%',
                    dropdownParent: $selectContainer, // Force le dropdown à rester dans le conteneur
                    ajax: {
                        url: ispag_ajax_object.ajax_url,
                        dataType: 'json',
                        delay: 300,
                        data: function(params) {
                            return {
                                q: params.term,
                                action: (selectType === 'ingenieur_id') ? 'search_ispag_ingenieurs' : 'search_ispag_concurrents',
                                nonce: ispag_ajax_object.nonce
                            };
                        },
                        processResults: function(data) {
                            return { results: data.results };
                        },
                        cache: true
                    }
                };

                $select.select2(select2Config);

                // Précharge la valeur existante (ID + Nom)
                if (currentValue) {
                    const displayText = $el.text().trim().replace(/[✏️\s]+/g, '').trim();
                    $select.append(new Option(displayText, currentValue, true, true)).trigger('change');
                }

                // Gestion de la sauvegarde
                const triggerSave = () => {
                    if (isSaving) return;
                    const selectedValue = $select.val();
                    if (!selectedValue) {
                        // Si vide, on sauvegarde une chaîne vide
                        isSaving = true;
                        saveField('');
                    } else {
                        isSaving = true;
                        saveField(selectedValue);
                    }
                };

                // Sauvegarde quand on sélectionne une option
                $select.on('select2:select', triggerSave);

                // Sauvegarde quand on ferme le dropdown (clic hors du champ ou appui sur Échap)
                $select.on('select2:close', triggerSave);

                // Sauvegarde aussi sur blur (au cas où)
                $select.on('blur', () => {
                    setTimeout(triggerSave, 100);
                });
            } else{
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

                } else if (fieldType === 'tel') {
                    // Créer un conteneur pour intl-tel-input
                    const telContainer = document.createElement('div');
                    telContainer.className = 'ispag-tel-container';

                    const telInput = document.createElement('input');
                    telInput.type = 'tel';
                    telInput.className = 'ispag-inline-input ispag-tel-input';
                    telInput.style.width = '250px'; // Largeur adaptée pour le téléphone
                    telInput.value = currentValue;

                    telContainer.appendChild(telInput);
                    $el.empty().append(telContainer);

                    // Initialiser intl-tel-input
                    const iti = window.intlTelInput(telInput, {
                        initialCountry: "ch",
                        preferredCountries: ["ch", "fr"],
                        separateDialCode: true,
                        utilsScript: ispag_params.utils_url // Assurez-vous que cette variable est définie
                    });

                    // Focus sur l'input
                    setTimeout(() => telInput.focus(), 100);

                    // Gestion de la sauvegarde
                    const triggerSave = () => {
                        if (isSaving) return;
                        if (!iti.isValidNumber()) {
                            alert("Numéro de téléphone invalide");
                            return;
                        }
                        isSaving = true;
                        const fullNumber = iti.getNumber(); // Récupère le numéro au format international (ex: +41123456789)
                        saveField(fullNumber);
                    };

                    telInput.addEventListener('blur', () => {
                        setTimeout(() => {
                            if ($el.find(telInput).length > 0 && !isSaving) {
                                triggerSave();
                            }
                        }, 250);
                    });

                    telInput.addEventListener('keydown', (e) => {
                        if (e.key === 'Enter') {
                            e.preventDefault();
                            triggerSave();
                        }
                        if (e.key === 'Escape') {
                            isSaving = true;
                            restoreOriginal();
                        }
                    });

                }  else {
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
                } else if (fieldType === 'tel') {
                    if (typeof intlTelInputUtils !== 'undefined') {
                        try {
                            // Formatage pour l'affichage (ex: +41 12 345 67 89)
                            displayValue = intlTelInputUtils.formatNumber(newValue, "CH", intlTelInputUtils.numberFormat.INTERNATIONAL);
                        } catch (e) {
                            console.error("Erreur de formatage du téléphone :", e);
                            displayValue = newValue; // Fallback
                        }
                    } else {
                        displayValue = newValue; // Fallback si intlTelInputUtils n'est pas chargé
                    }
                }
                // Gestion des Select2 (ingénieur/concurrent)
                else if (fieldType.startsWith('select2-')) {
                    const selectType = fieldType.replace('select2-', '');
                    valueToSend = newValue || ''; // Force une chaîne vide si null/undefined

                    // Récupère le nom à afficher
                    if (newValue) {
                        if (selectType === 'ingenieur_id') {
                            displayValue = window.ispagIngenieurNames?.[newValue] || newValue;
                        } else if (selectType === 'EnSoumission') {
                            displayValue = window.ispagConcurrentNames?.[newValue] || newValue;
                        }
                    } else {
                        displayValue = '---'; // Affichage par défaut si vide
                    }
                }


                // ===== LOG DES DONNÉES =====
                console.log('🔹 [ISPAG DEBUG] Sauvegarde du champ:', {
                    field: fieldName,
                    value: valueToSend,
                    deal_id: dealId,
                    source: source,
                    fieldType: fieldType,
                    displayValue: displayValue
                });

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
                    // ===== LOG DE LA RÉPONSE =====
                    console.log('🔹 [ISPAG DEBUG] Réponse du serveur:', res);

                    if (res.success) {
                        $el.attr('data-value', valueToSend);
                        // $el.html((displayValue || '---') + ' <span class="edit-icon">✏️</span>');
                        const finalDisplayValue = res.data?.display_value || displayValue || '---';
                        $el.html(finalDisplayValue + ' <span class="edit-icon">✏️</span>');
                        // Lookup ville automatique après sauvegarde du NIP
                        if (fieldName === 'NIP' && newValue) {
                            fetchCityFromPostalCode(newValue, dealId, source);
                        }
                    } else {
                        console.error('❌ [ISPAG DEBUG] Erreur de sauvegarde:', res);
                        alert('Erreur lors de la sauvegarde');
                        restoreOriginal();
                    }
                })
                .catch((error) => {
                    console.error('❌ [ISPAG DEBUG] Erreur AJAX:', error);
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