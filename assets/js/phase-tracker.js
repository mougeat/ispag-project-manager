window.ispagT = window.ispagT || function (s) { return s; }; // traductions des textes JS (voir includes/js-strings.php)
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

    // Règle des onglets : chargé une fois à l'ouverture de la page (squelette), jamais rechargé pendant qu'il est affiché ;
    // le rafraîchissement se fait en arrière-plan à l'instant où l'on quitte l'onglet (voir ispag-tab-lifecycle.js).
    function loadFollowupTab($pane, silent) {
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
                    // Rafraîchissement : l'onglet est masqué, le contenu est remplacé sans effet
                    $pane.html(response.data.html);
                } else {
                    // Première charge : affichage direct et marquage comme chargé
                    $pane.html(response.data.html);
                    $pane.data('loaded', true);
                }
            } else if (!silent) {
                $pane.html('<p class="ispag-error-message">' + (response.data.message || 'Error.') + '</p>');
            }   // rafraîchissement en arrière-plan raté : on garde l'ancien contenu
        })
        .fail(function (xhr) {
            if (!silent) $pane.html('<p class="ispag-error-message">Network error.</p>');
        })
        .always(function() {
            $pane.removeData('loading');
        });
    }

    // Document ajouté ou supprimé : le serveur a recalculé les étapes (ex. plan fournisseur) ; on rafraîchit le tableau
    // en arrière-plan, sans l'afficher s'il ne l'est pas (ni le charger s'il n'a jamais été ouvert).
    $(document).off('ispag:documents-changed.phase').on('ispag:documents-changed.phase', function () {
        var $pane = $('#ispag-tab-fallowup');
        if ($pane.data('loaded') && !$pane.data('loading')) {
            loadFollowupTab($pane, true);
        }
    });

    // On quitte l'onglet : son contenu est masqué, on le recharge maintenant (en arrière-plan)
    $(document).on('ispag:tab-left', function (e, tab) {
        var $pane = $('#ispag-tab-fallowup');
        if (tab === 'fallowup' && $pane.data('loaded') && !$pane.data('loading')) {
            loadFollowupTab($pane, true);
        }
    });
    // Repli : onglet ouvert avant la fin (ou l'échec) du chargement initial
    $(document).on('ispag:tab-shown', function (e, tab) {
        var $pane = $('#ispag-tab-fallowup');
        if (tab === 'fallowup' && !$pane.data('loaded') && !$pane.data('loading')) {
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
                    $row.css('--status-color', response.data.color);
                    var complete = parseInt($select.find('option:selected').data('complete'), 10) === 1;
                    $row.toggleClass('is-done', complete);
                    var $family = $row.closest('.ispag-phase-family');
                    if ($family.length) {
                        var total = $family.find('.ispag-phase-tracker__row').length;
                        var done = $family.find('.ispag-phase-tracker__row.is-done').length;
                        $family.find('.ispag-phase-family__count').text(done + '/' + total);
                        $family.find('.ispag-phase-family__bar span').css('width', (total ? Math.round(100 * done / total) : 0) + '%');
                    }

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
                            window.ispagSetNextMailButton(nextResponse.data.mail_button);
                        }
                    })
                    .fail(function (xhr) {
                        console.error('[ISPAG Phase Tracker] échec récupération next_step :', xhr.status, xhr.responseText);
                    });

                } else {
                    ispagAlert(response.data.message || 'Error during update.');
                }
            })
            .fail(function (xhr) {
                console.error('[ISPAG Phase Tracker] échec update statut :', xhr.status, xhr.responseText);
                ispagAlert(ispagT('Network error during update.'));
            })
            .always(function () {
                $select.prop('disabled', false);
            });
    });
});

// Bouton « étape suivante » de la page projet : télécharge le brouillon .eml puis valide l'étape (sans recharger la page).
jQuery(function ($) {
    $(document).off('click.nextMail').on('click.nextMail', '.ispag-next-mail-btn', async function () {
        var $btn = $(this);
        var title = $btn.data('title');
        var ok = await ispagConfirm(ispagT('Download the draft email for "%s" and mark this step as done?').replace('%s', title), {
            labelOk: ispagT('Download and validate'),
            labelCancel: ispagT('Cancel')
        });
        if (!ok) return;

        $btn.prop('disabled', true);
        // Téléchargement via un iframe caché : la page reste en place.
        var frame = document.createElement('iframe');
        frame.style.display = 'none';
        frame.src = $btn.data('eml-url');
        document.body.appendChild(frame);
        setTimeout(function () { frame.remove(); }, 15000);

        $.post(ispagPhaseTracker.ajaxUrl, {
            action: 'ispag_update_project_phase_status',
            _ajax_nonce: ispagPhaseTracker.nonce,
            hubspot_deal_id: $btn.data('deal-id'),
            slug_phase: $btn.data('slug'),
            status_id: 1 // Done
        }).done(function (r) {
            if (!r.success) { ispagAlert((r.data && r.data.message) || 'Error during update.'); $btn.prop('disabled', false); return; }
            $.post(ispagPhaseTracker.ajaxUrl, {
                action: 'ispag_get_next_pending_phase',
                _ajax_nonce: ispagPhaseTracker.nonce,
                hubspot_deal_id: $btn.data('deal-id')
            }).done(function (n) {
                if (n.success) {
                    var $badge = $('.ispag-next-step-badge');
                    $badge.text(n.data.label).css({color: n.data.color, border: '1px solid ' + n.data.color});
                    window.ispagSetNextMailButton(n.data.mail_button);
                }
            }).always(function () { $btn.prop('disabled', false); });
        }).fail(function () {
            ispagAlert(ispagT('Network error during update.'));
            $btn.prop('disabled', false);
        });
    });
});

// Met à jour le bouton « étape suivante » du groupe projet (html vide = pas de bouton) ; le groupe se masque s'il est vide.
window.ispagSetNextMailButton = function (html) {
    var $group = jQuery('.ispag-group-projet');
    if (!$group.length) return;
    $group.find('.ispag-next-mail-btn').remove();
    if (html) $group.prepend(html);
    $group.css('display', $group.children().length ? 'flex' : 'none');
};

// Document ajouté/supprimé : les étapes ont été recalculées côté serveur, le bouton suit la nouvelle étape suivante.
jQuery(function ($) {
    $(document).off('ispag:documents-changed.nextmail').on('ispag:documents-changed.nextmail', function () {
        var dealId = $('.ispag-group-projet').data('deal-id');
        if (!dealId || typeof ispagPhaseTracker === 'undefined') return;
        $.post(ispagPhaseTracker.ajaxUrl, {action: 'ispag_get_next_pending_phase', _ajax_nonce: ispagPhaseTracker.nonce, hubspot_deal_id: dealId})
            .done(function (n) {
                if (!n.success) return;
                $('.ispag-next-step-badge').text(n.data.label).css({color: n.data.color, border: '1px solid ' + n.data.color});
                window.ispagSetNextMailButton(n.data.mail_button);
            });
    });
});
