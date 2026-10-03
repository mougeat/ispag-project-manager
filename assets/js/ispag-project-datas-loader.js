window.ispagT = window.ispagT || function (s) { return s; }; // traductions des textes JS (voir includes/js-strings.php)
jQuery(document).ready(function($) {

    // ----------------------------------------------------------------
    // 0. CHARGEMENT EN ARRIÈRE-PLAN (LAZY LOADING GLOBAL AU CHARGEMENT DE LA PAGE)
    // ----------------------------------------------------------------
    var $detailsPane = $('#ispag-tab-details');
    var $activityPane = $('#ispag-tab-activity');
    
    if ($detailsPane.length > 0 || $activityPane.length > 0) {
        // Petit délai de 200ms pour laisser le rendu initial de la page se faire en priorité
        setTimeout(function() {
            if ($detailsPane.length > 0 && !$detailsPane.data('loaded') && !$detailsPane.data('loading')) {
                loadProjectDetailsTab($detailsPane);
            }
        }, 1000);
    }

    // ----------------------------------------------------------------
    // 1. CHARGEMENT DES MONTANTS DU PROJET
    // ----------------------------------------------------------------
    var $project_amount_card = $('#ispag_project_amount');

    if ($project_amount_card.length > 0) {
        var hubspotDealId = $project_amount_card.data('deal-id');
        
        if (hubspotDealId) {
            $.ajax({
                url: ispagNoteData.ajaxurl,
                type: 'POST',
                data: {
                    action: 'ispag_load_project_datas',
                    _ajax_nonce: ispagNoteData.nonce,
                    hubspot_deal_id: hubspotDealId
                },
                success: function(response) {
                    if (response.success && response.data.project_amount) {
                        $project_amount_card.replaceWith(response.data.project_amount);
                    } else {
                        console.warn('ISPAG JS : Error ou données vides pour le montant du projet :', response);
                        var errorMsg = (response.data && response.data.message) ? response.data.message : ispagT('Loading error.');
                        $project_amount_card.html('<p class="error" style="padding: 10px; color: #666;">' + errorMsg + '</p>');
                    }
                },
                error: function(jqXHR, textStatus, errorThrown) {
                    console.error('ISPAG JS : Error AJAX montant projet :', textStatus, errorThrown);
                    $project_amount_card.html('<p class="error" style="padding: 10px; color: #e74c3c;">Error while loading data.</p>');
                }
            });
        }
    }

    // ----------------------------------------------------------------
    // 2. GESTION DES ONGLETS (DETAILS) AU CLIC
    // ----------------------------------------------------------------
    $(document).on('click', '.ispag-tab-btn[data-tab="details"]', function () {
        var $pane = $('#ispag-tab-details');
        if (!$pane.data('loaded') && !$pane.data('loading')) {
            loadProjectDetailsTab($pane);
        }
    });


    // --- Fonction de chargement AJAX : Details ---
    function loadProjectDetailsTab($pane) {
        var dealId = $pane.data('deal-id');
        if (!dealId || typeof ispagNoteData === 'undefined') return;

        $pane.data('loading', true);

        $.post(ispagNoteData.ajaxurl, {
            action: 'ispag_render_project_details_tab',
            _ajax_nonce: ispagNoteData.nonce,
            hubspot_deal_id: dealId
        })
        .done(function (response) {
            if (response.success) {
                $pane.html(response.data.html);
                $pane.data('loaded', true);
            } else {
                $pane.html('<p class="ispag-error-message">' + (response.data.message || 'Error.') + '</p>');
            }
        })
        .fail(function (xhr, status, error) {
            console.error('[ISPAG Details] échec AJAX :', status, error);
            $pane.html('<p class="ispag-error-message">Network error.</p>');
        })
        .always(function() {
            $pane.removeData('loading');
        });
    }


    // ----------------------------------------------------------------
    // 3. CHARGEMENT DU BOUTON D'ACTION DU PROJET
    // ----------------------------------------------------------------
    var $card = $('.ispag-project-btn-card');
    var $bulk_card = $('.ispag-bulk-actions');
    
    if ($card.length > 0) {
        var dealId = $card.data('deal-id');
        if (dealId && typeof ispagNoteData !== 'undefined') {
            $.post(ispagNoteData.ajaxurl, {
                action: 'ispag_render_project_btn',
                _ajax_nonce: ispagNoteData.nonce,
                hubspot_deal_id: dealId
            })
            .done(function (response) {
                if (response.success) {
                    $card.append(response.data.html);
                    $bulk_card.replaceWith(response.data.bulk_html);
                    document.querySelectorAll('.ispag-toggle-chip').forEach(initTristateToggle);
                } else {
                    $card.html('<p class="ispag-error-message">' + (response.data.message || 'Error.') + '</p>');
                }
            })
            .fail(function (xhr, status, error) {
                console.error('[ISPAG Button] échec AJAX :', status, error);
            });
        }
    }
});