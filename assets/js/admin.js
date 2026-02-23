jQuery(document).ready(function($) {

    // Sauvegarde la position des lignes au drag & drop
    $('#ispag-doc-types tbody').sortable({
        update: function(event, ui) {
            const order = [];
            $('#ispag-doc-types tbody tr').each(function(index, row) {
                order.push({
                    id: $(row).data('id'),
                    sort: index + 1
                });
            });

            $.post(ajaxurl, {
                action: 'ispag_sort_doc_types',
                order: order,
                _ajax_nonce: ispag_admin_doc_type.nonce
            }, function(response) {
                if (response.success) {
                    // console.log('Ordre mis à jour');
                } else {
                    alert('Erreur mise à jour ordre');
                }
            });
        }
    });

    // Sauvegarde modif inline
    $('.save-row').on('click', function() {
        const row = $(this).closest('tr');
        const data = {
            id: row.data('id'),
            label: row.find('input[name="doc-label[]"]').val(),
            slug: row.find('input[name="doc-slug[]"]').val(),
            restricted: row.find('input[name="restricted[]"]').is(':checked') ? 1 : 0,
            _ajax_nonce: ispag_admin_doc_type.nonce,
            action: 'ispag_update_doc_type'
        };

        $.post(ajaxurl, data, function(response) {
            if (!response.success) {
                alert('Erreur lors de la sauvegarde');
            }
        });
    });

    // Envoi du formulaire d'ajout
    $('#add-doc-type-form').on('submit', function(e) {
        e.preventDefault();

        const data = {
            label: $(this).find('input[name="new-doc-label"]').val(),
            slug: $(this).find('input[name="vdoc-slug"]').val(),
            restricted: $(this).find('input[name="new-restricted"]').is(':checked') ? 1 : 0,
            _ajax_nonce: ispag_admin_doc_type.nonce,
            action: 'ispag_add_doc_type'
        };

        $.post(ajaxurl, data, function(response) {
            if (response.success) {
                location.reload();
            } else {
                alert('Erreur ajout');
            }
        });
    });

});


jQuery(document).ready(function($) {
    // Activer sortable sur le tbody du tableau
    $('#ispag-doc-type-table tbody').sortable({
        handle: '.sort-handle',
        update: function(event, ui) {
            // Récupérer l’ordre des IDs dans l’ordre actuel
            let order = [];
            $('#ispag-doc-type-table tbody tr').each(function() {
                order.push($(this).data('id'));
            });

            // Envoyer AJAX pour enregistrer l'ordre
            $.post(ISPAG_DOC_TYPES.ajax_url, {
                action: 'ispag_reorder_doc_types',
                order: order,
                _ajax_nonce: ISPAG_DOC_TYPES.nonce
            }, function(response) {
                if (!response.success) {
                    alert('Erreur lors de la sauvegarde de l’ordre : ' + response.data.message);
                }
            });
        }
    });

    // Sauvegarder un doc type existant
    $('#ispag-doc-type-table').on('click', '.save-doc-type', function() {
        let row = $(this).closest('tr');
        let id = row.data('id');
        let label = row.find('.doc-label').val();
        let slug = row.find('.doc-slub').val();

        $.post(ISPAG_DOC_TYPES.ajax_url, {
            action: 'ispag_save_doc_type',
            id: id,
            label: label,
            class: slug,
            _ajax_nonce: ISPAG_DOC_TYPES.nonce
        }, function(response) {
            if (response.success) {
                alert('Enregistré avec succès');
            } else {
                alert('Erreur lors de la sauvegarde');
            }
        });
    });

    // Ajouter un nouveau doc type
    $('#add-doc-type').on('click', function() {
        let label = $('#new-doc-label').val();
        let slug = $('#new-doc-slug').val();

        if (!label) {
            alert('Le nom est requis');
            return;
        }

        $.post(ISPAG_DOC_TYPES.ajax_url, {
            action: 'ispag_save_doc_type',
            label: label,
            class: slug,
            _ajax_nonce: ISPAG_DOC_TYPES.nonce
        }, function(response) {
            if (response.success) {
                // Recharge la page pour afficher le nouveau type (ou faire un append dynamique)
                location.reload();
            } else {
                alert('Erreur lors de l\'ajout');
            }
        });
    });
});
