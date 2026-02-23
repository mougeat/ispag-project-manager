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

    // 3. Réinitialiser le contact si on change d'entreprise (optionnel)
    $('#company-select').on('change', function() {
        $('#contact-select').val(null).trigger('change');
    });
});

jQuery(document).ready(function($) {
    
    function initSelect2Details() {
        const dealId = $('#details-project-deal-id').val();

        // Initialisation Entreprise
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

        // Initialisation Contact
        $('#edit-project-contact').select2({
            ajax: {
                url: ispag_ajax_object.ajax_url,
                dataType: 'json',
                delay: 250,
                data: function(params) {
                    return { 
                        q: params.term, 
                        action: 'search_ispag_contacts', 
                        company_id: $('#edit-project-company').val(), // Filtre par entreprise actuelle
                        nonce: ispag_ajax_object.nonce 
                    };
                },
                processResults: function(data) { return { results: data.results }; }
            }
        }).on('change', function() {
            saveChange('contact', $(this).val());
        });

        function saveChange(type, value) {
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
                    if(response.success) {
                        // Optionnel : Notification visuelle de succès
                        console.log(type + ' mis à jour');
                    }
                }
            });
        }
    }

    // Lancement
    if ($('#edit-project-company').length > 0) {
        initSelect2Details();
    }
});