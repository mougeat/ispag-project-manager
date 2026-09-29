jQuery(function ($) {
    'use strict';

    // Chargement automatique en arrière-plan 500ms après le chargement de la page
    $(document).ready(function() {
        var $pane = $('#ispag-tab-fallowup');
        if ($pane.length > 0) {
            setTimeout(function() {
                if (!$pane.data('loading')) {
                    loadFollowupTab($pane);
                }
            }, 500);
        }
    });

    function loadFollowupTab($pane) {
        var dealId = $pane.data('deal-id');

        if (!dealId) {
            console.warn('[ISPAG Phase Tracker] STOP : pas de deal-id sur le pane'); 
            return;
        }

        if (typeof ispagPhaseTracker === 'undefined') {
            console.error('[ISPAG Phase Tracker] STOP : ispagPhaseTracker non défini, wp_localize_script n\'a pas tourné');
            return;
        }

        var isLoaded = $pane.data('loaded');
        $pane.data('loading', true);

        // Si l'onglet n'a jamais été chargé, on affiche le squelette
        if (!isLoaded) {
            $pane.html(ispag_texts.fallow_up_squeleton);
        }

        $.post(ispagPhaseTracker.ajaxUrl, {
            action: 'ispag_render_phase_tab',
            _ajax_nonce: ispagPhaseTracker.nonce,
            hubspot_deal_id: dealId
        })
        .done(function (response) {
            if (response.success) {
                if (isLoaded) {
                    // Si déjà chargé : effet de fondu entre l'ancien et le nouveau contenu
                    $pane.fadeOut(150, function() {
                        $pane.html(response.data.html).fadeIn(150);
                    });
                } else {
                    // Première charge : affichage direct et marquage comme chargé
                    $pane.html(response.data.html);
                    $pane.data('loaded', true);
                }
            } else {
                $pane.html('<p class="ispag-error-message">' + (response.data.message || 'Erreur.') + '</p>');
            }
        })
        .fail(function (xhr) {
            $pane.html('<p class="ispag-error-message">Erreur réseau.</p>');
        })
        .always(function() {
            $pane.removeData('loading');
        });
    }

    $(document).on('click', '.ispag-tab-btn[data-tab="fallowup"]', function () {
        var $pane = $('#ispag-tab-fallowup');
        // On recharge au clic si ce n'est pas déjà en cours de chargement
        if (!$pane.data('loading')) {
            loadFollowupTab($pane);
        }
    }); 

    $(document).on('click', '.ispag-phase-tracker__control', function (e) {
        e.stopPropagation();
    });

    $(document).on('change', '[data-action="ispag-update-phase-status"]', function (e) {
        e.stopPropagation();

        var $select = $(this);
        var $row = $select.closest('.ispag-phase-tracker__row');
        var $wrapper = $select.closest('.ispag-phase-tracker');

        var payload = {
            action: 'ispag_update_project_phase_status',
            _ajax_nonce: ispagPhaseTracker.nonce,
            hubspot_deal_id: $wrapper.data('deal-id'),
            slug_phase: $row.data('slug-phase'),
            status_id: $select.val()
        };

        $select.prop('disabled', true);

        $.post(ispagPhaseTracker.ajaxUrl, payload)
            .done(function (response) {
                if (response.success) {
                    $select.css('--status-color', response.data.color);

                    // Second appel AJAX pour rafraîchir le badge "Next step"
                    $.post(ispagPhaseTracker.ajaxUrl, {
                        action: 'ispag_get_next_pending_phase',
                        _ajax_nonce: ispagPhaseTracker.nonce,
                        hubspot_deal_id: $wrapper.data('deal-id')
                    })
                    .done(function (nextResponse) {
                        if (nextResponse.success) {
                            var $badge = $('.ispag-next-step-badge');
                            $badge.text(nextResponse.data.label);
                            $badge.css('color', nextResponse.data.color);
                            $badge.css('border', '1px solid ' + nextResponse.data.color);
                        }
                    })
                    .fail(function (xhr) {
                        console.error('[ISPAG Phase Tracker] échec récupération next_step :', xhr.status, xhr.responseText);
                    });

                } else {
                    alert(response.data.message || 'Erreur lors de la mise à jour.');
                }
            })
            .fail(function (xhr) {
                console.error('[ISPAG Phase Tracker] échec update statut :', xhr.status, xhr.responseText);
                alert('Erreur réseau lors de la mise à jour.');
            })
            .always(function () {
                $select.prop('disabled', false);
            });
    });
});