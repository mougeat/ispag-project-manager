window.ispagT = window.ispagT || function (s) { return s; }; // traductions des textes JS (voir includes/js-strings.php)
jQuery(document).ready(function($) {
    // Écoute l'événement de clic sur le bouton de duplication
    $(document).off('click', '#ispag-duplicate-btn').on('click', '#ispag-duplicate-btn', async function(e){
        e.preventDefault();
        e.stopPropagation();
        
        var button = $(this);
        var dealId = button.data('deal-id');
        var statusElement = $('#ispag-status-' + dealId);

        const confirmed = await ispagConfirm(
            ispag_texts.would_you_replicate_project + ' ?',
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
            statusElement.text(ispagT('Error: Missing project ID.')).css('color', 'red');
            return;
        }

        // 1. Mise à jour de l'interface utilisateur (UI)
        button.prop('disabled', true).text(ispagT('Duplication in progress...'));
        statusElement.text(ispagT('Please wait...')).css('color', 'orange');

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
                    button.text(ispagT('Project duplicated ✔️'));

                    window.location.href = response.data.redirect_url;
                    
                } else {
                    // Duplication échouée (erreur du serveur ou logique PHP)
                    statusElement.text(ispagT('Error: ') + response.data.message).css('color', 'red');
                    button.prop('disabled', false).text(ispagT('Dupliquer le Projet 🔄'));
                }
            },
            error: function(jqXHR, textStatus, errorThrown) {
                // LIGNE DE LOG CRUCIALE : Affiche l'objet XHR en cas d'erreur de connexion HTTP
//                console.log('Réponse AJAX Error HTTP :', jqXHR, textStatus, errorThrown); 
                
                // Error de connexion ou autre erreur HTTP
                statusElement.text(ispagT('AJAX connection error: ') + textStatus).css('color', 'red');
                button.prop('disabled', false).text(ispagT('Dupliquer le Projet 🔄'));
            }
        });
    });
});