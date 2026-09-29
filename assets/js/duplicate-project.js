jQuery(document).ready(function($) {
    // Écoute l'événement de clic sur le bouton de duplication
    $(document).off('click', '#ispag-duplicate-btn').on('click', '#ispag-duplicate-btn', async function(e){
        e.preventDefault();
        e.stopPropagation();
        
        var button = $(this);
        var dealId = button.data('deal-id');
        var statusElement = $('#ispag-status-' + dealId);

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
        
        if (!dealId) {
            statusElement.text('Erreur: ID de projet manquant.').css('color', 'red');
            return;
        }

        // 1. Mise à jour de l'interface utilisateur (UI)
        button.prop('disabled', true).text('Duplication en cours...');
        statusElement.text('Veuillez patienter...').css('color', 'orange');

        // 2. Appel AJAX
        $.ajax({
            url: ispag_ajax.ajax_url, // URL définie par wp_localize_script
            type: 'POST',
            data: {
                action: 'ispag_duplicate_project', // L'action WordPress
                security: ispag_ajax.nonce,        // Le nonce de sécurité
                deal_id: dealId 
            },
            success: function(response) {
                // LIGNE DE LOG CRUCIALE : Affiche la réponse JSON complète du serveur
//                console.log('Réponse AJAX Succès :', response); 
                
                if (response.success) {
                    // Duplication réussie
                    statusElement.text(response.data.message).css('color', 'green');
                    button.text('Project duplicated ✔️');

                    window.location.href = response.data.redirect_url;
                    
                } else {
                    // Duplication échouée (erreur du serveur ou logique PHP)
                    statusElement.text('Erreur: ' + response.data.message).css('color', 'red');
                    button.prop('disabled', false).text('Dupliquer le Projet 🔄');
                }
            },
            error: function(jqXHR, textStatus, errorThrown) {
                // LIGNE DE LOG CRUCIALE : Affiche l'objet XHR en cas d'erreur de connexion HTTP
//                console.log('Réponse AJAX Erreur HTTP :', jqXHR, textStatus, errorThrown); 
                
                // Erreur de connexion ou autre erreur HTTP
                statusElement.text('Erreur de connexion AJAX: ' + textStatus).css('color', 'red');
                button.prop('disabled', false).text('Dupliquer le Projet 🔄');
            }
        });
    });
});