jQuery(document).ready(function($) {
  $('#AssociatedContactIDs').on('change', function() {
    const user_id = $(this).val();
    
    if (!user_id) return;

//    console.log(user_id);

    $.ajax({
      url: ispag_ajax_object.ajax_url,
      type: 'POST',
      dataType: 'json',
      data: {
        action: 'get_company_by_user_id', 
        user_id: user_id,
        nonce: ispag_ajax_object.nonce
      },
      success: function(response) {
        // console.log(response);
        if (response.success) {
                // console.log('client_Id:', response.data.client_id);
                $('select[name="AssociatedCompanyID"]').val(response.data.client_id.toString().trim());
                const dealId = response.data.hubspot_deal_id;
                // console.log(dealId);
                // window.location.href = `${window.location.origin}/liste-des-projets/?deal_id=${dealId}`;
        }
        
      },
        error: function (error){
//            console.log('ERREUR', error);
        }
    
    });
  });
});

// function openFittingsModal() {
//     document.getElementById('tank-fittings-modal').style.display = 'flex';
//     // Appelle ici une fonction pour charger dynamiquement le contenu
//     loadTankFittingsForm(article_id); // à définir
// }

// function closeFittingsModal() {
//     document.getElementById('tank-fittings-modal').style.display = 'none';
// }

// document.addEventListener('DOMContentLoaded', function () {
//     document.getElementById('open-tank-fittings-modal')?.addEventListener('click', openFittingsModal);
// });
jQuery(document).ready(function($) {
    // 1. Initialisation de Select2 pour l'Entreprise
    $('#company-select').select2({
        placeholder: "Taper le nom de l'entreprise ou l'ID...",
        minimumInputLength: 2,
        allowClear: true,
        ajax: {
            url: ispag_ajax_object.ajax_url,
            dataType: 'json',
            delay: 300,
            data: function (params) {
                return {
                    q: params.term,
                    action: 'search_ispag_companies',
                    nonce: ispag_ajax_object.nonce
                };
            },
            processResults: function (data) {
                return { results: data.results };
            },
            cache: true
        }
    });

    // 3. Initialisation de Select2 pour l'Ingénieur
    $('#ingenieur-select').select2({
        placeholder: "Search for an engineering office...",
        minimumInputLength: 2,
        allowClear: true,
        ajax: {
            url: ispag_ajax_object.ajax_url,
            dataType: 'json',
            delay: 300,
            data: function (params) {
                return {
                    q: params.term,
                    action: 'search_ispag_ingenieurs',
                    nonce: ispag_ajax_object.nonce
                };
            },
            processResults: function (data) {
                return { results: data.results };
            },
            cache: true
        }
    });

    // 2. Initialisation de Select2 pour le Contact
    $('#contact-select').select2({
        placeholder: "Chercher un contact actif...",
        minimumInputLength: 2,
        allowClear: true,
        ajax: {
            url: ispag_ajax_object.ajax_url,
            dataType: 'json',
            delay: 300,
            data: function (params) {
                return {
                    q: params.term,
                    company_id: $('#company-select').val(), // On filtre par entreprise si sélectionnée
                    action: 'search_ispag_contacts',
                    nonce: ispag_ajax_object.nonce
                };
            },
            processResults: function (data) {
                return { results: data.results };
            },
            cache: true
        }
    });

    // Initialisation de Select2 pour le Concurrent (NOUVEAU)
    $('#concurrent-select').select2({
        placeholder: "Chercher un concurrent...",
        minimumInputLength: 2,
        allowClear: true,
        ajax: {
            url: ispag_ajax_object.ajax_url,
            dataType: 'json',
            delay: 300,
            data: function(params) {
                return {
                    q: params.term,
                    action: 'search_ispag_concurrents',
                    nonce: ispag_ajax_object.nonce
                };
            },
            processResults: function(data) {
                return { results: data.results };
            },
            cache: true
        }
    }).on('change', function() {
        const dealId = $(this).data('deal');
        const fieldName = $(this).data('field') || $(this).attr('name');
        const selectedValue = $(this).val();
        saveSelect2Field($(this), fieldName, selectedValue, dealId, 'project');
    });

    // 3. Réinitialiser le contact si on change d'entreprise (optionnel)
    $('#company-select').on('change', function() {
        $('#contact-select').val(null).trigger('change');
    });
});

jQuery(document).ready(function($) {
    function initSelect2Details() {
        const dealId = $('#details-project-deal-id').val();

        // Initialisation Entreprise (inchangée)
        $('#edit-project-company').select2({
            ajax: {
                url: ispag_ajax_object.ajax_url,
                dataType: 'json',
                delay: 250,
                data: function(params) {
                    return { q: params.term, action: 'search_ispag_companies', nonce: ispag_ajax_object.nonce };
                },
                processResults: function(data) { return { results: data.results }; }
            }
        }).on('change', function() {
            saveChange('company', $(this).val());
        });

        // Initialisation Contact (inchangée)
        $('#edit-project-contact').select2({
            ajax: {
                url: ispag_ajax_object.ajax_url,
                dataType: 'json',
                delay: 250,
                data: function(params) {
                    return {
                        q: params.term,
                        action: 'search_ispag_contacts',
                        company_id: $('#edit-project-company').val(),
                        nonce: ispag_ajax_object.nonce
                    };
                },
                processResults: function(data) { return { results: data.results }; }
            }
        }).on('change', function() {
            saveChange('contact', $(this).val());
        });

        // Initialisation Abonnés (NOUVEAU)
        const abonnesInput = $('#details-project-abonnes');
        const abonnesValue = abonnesInput.val(); // Ex: ";0;1;6048;1295;"

        // Extraire les IDs existants (sans les ; vides)
        const existingAbonnes = abonnesValue
            ? abonnesValue.split(';').filter(id => id.trim() !== '').map(id => id.trim())
            : [];

        $('#edit-project-abonnes').select2({
            placeholder: ispag_texts.add_subscriber + "...",
            minimumInputLength: 2,
            allowClear: true,
            multiple: true,
            // Personnalisation de l'affichage des options sélectionnées
            templateSelection: function(data) {
                if (!data.id) return data.text; // Cas par défaut

                // Créer un lien vers le profil du contact
                const contactUrl = `/contact/${data.id}/`; // adresse relative : reste sur le site courant
                return $('<a>', {
                    href: contactUrl,
                    target: '_blank', // Ouvre dans un nouvel onglet
                    text: data.text,
                    style: 'color: inherit; text-decoration: none;' // Style pour ressembler au texte normal
                });
            },
            ajax: {
                url: ispag_ajax_object.ajax_url,
                dataType: 'json',
                delay: 300,
                data: function(params) {
                    return {
                        q: params.term,
                        action: 'search_ispag_contacts_for_subscribers',
                        nonce: ispag_ajax_object.nonce
                    };
                },
                processResults: function(data) {
                    return { results: data.results };
                },
                cache: true
            }
        });

        // Pré-sélectionner les abonnés existants
        if (existingAbonnes.length > 0) {
            // Charger les noms des abonnés existants via AJAX
            $.ajax({
                url: ispag_ajax_object.ajax_url,
                data: {
                    action: 'get_contact_names_by_ids',
                    nonce: ispag_ajax_object.nonce,
                    ids: existingAbonnes
                },
                success: function(response) {
                    if (response.success && response.data) {
                        // 👇 Sécurisation : on force la conversion en tableau si c'est un objet associatif PHP
                        const dataArray = Array.isArray(response.data) 
                            ? response.data 
                            : Object.values(response.data);

                        const abonnesData = dataArray.map(contact => ({
                            id: contact.id,
                            text: contact.text
                        }));
                        
                        // Sélectionner les options
                        $('#edit-project-abonnes').val(existingAbonnes).trigger('change');
                    }
                }
            });
        }

        // Sauvegarder les modifications
        $('#edit-project-abonnes').on('change', function() {
            const selectedValues = $(this).val() || [];
            // Formater les IDs avec des ; (ex: ";0;1;6048;1295;")
            const formattedValue = ';' + selectedValues.join(';') + ';';
            saveChange('abonne', formattedValue);
        });
    }

    // Fonction pour sauvegarder les changements
    function saveChange(type, value) {
        const dealId = $('#details-project-deal-id').val();
        $.ajax({
            url: ispag_ajax_object.ajax_url,
            type: 'POST',
            data: {
                action: 'update_project_associations',
                nonce: ispag_ajax_object.nonce,
                deal_id: dealId,
                type: type,
                value: value
            },
            success: function(response) {
                if (response.success) {
                    console.log(type + ' mis à jour');
                } else {
                    console.error('Error:', response.data?.message || 'Unknown error');
                }
            },
            error: function(xhr, status, error) {
                console.error('Error AJAX:', error);
            }
        });
    }

    // Lancement
    if ($('#edit-project-company').length > 0) {
        initSelect2Details();
    }
});