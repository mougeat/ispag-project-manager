window.ispagT = window.ispagT || function (s) { return s; }; // traductions des textes JS (voir includes/js-strings.php)

/**
 * Bouton « Request an offer » (ingénieur, page d'une offre) : fenêtre avec un message libre, puis envoi AJAX.
 * Le bouton est rendu par ISPAG_Offer_Request::button_html() ; cette page ne le gère qu'à travers un clic délégué.
 */
jQuery(document).ready(function ($) {
    'use strict';

    function showInfo(message) {
        if (typeof window.ispagAlert === 'function') { window.ispagAlert(message); }
        else { window.alert(message); }
    }

    function injectStyle() {
        if (document.getElementById('ispag-offer-request-style')) return;
        var st = document.createElement('style');
        st.id = 'ispag-offer-request-style';
        st.textContent =
            '.ispag-or-overlay{position:fixed;inset:0;background:rgba(0,0,0,.7);z-index:9999998;display:flex;align-items:center;justify-content:center}' +
            '.ispag-or-box{background:#fff;border-radius:10px;padding:24px 28px;max-width:520px;width:92%;box-shadow:0 8px 32px rgba(0,0,0,.18);box-sizing:border-box}' +
            '.ispag-or-box h3{margin:0 0 12px;font-size:18px}' +
            '.ispag-or-box p{margin:0 0 14px;font-size:14px;color:#444;line-height:1.5}' +
            '.ispag-or-box label{display:block;font-size:13px;font-weight:600;margin-bottom:6px}' +
            '.ispag-or-box textarea{width:100%;min-height:120px;box-sizing:border-box;font:inherit;padding:8px;border:1px solid #bbb;border-radius:6px;resize:vertical}' +
            '.ispag-or-error{color:#b32d2e;font-size:13px;margin-top:8px;min-height:18px}' +
            '.ispag-or-actions{display:flex;gap:12px;justify-content:flex-end;margin-top:14px}';
        (document.head || document.documentElement).appendChild(st);
    }

    function openModal(onSend) {
        injectStyle();
        var $overlay = $('<div class="ispag-or-overlay" role="dialog" aria-modal="true"></div>');
        var $box = $('<div class="ispag-or-box"></div>').appendTo($overlay);
        $('<h3></h3>').text(ispagT('Request an offer')).appendTo($box);
        $('<p></p>').text(ispagT('Your request will be sent to the ISPAG team. You can add a message (quantities, deadline, special requirements...).')).appendTo($box);
        $('<label for="ispag-or-message"></label>').text(ispagT('Message (optional)')).appendTo($box);
        var $text = $('<textarea id="ispag-or-message"></textarea>').attr('maxlength', ispag_offer_request.max || 2000).appendTo($box);
        var $error = $('<div class="ispag-or-error"></div>').appendTo($box);
        var $actions = $('<div class="ispag-or-actions"></div>').appendTo($box);
        var $cancel = $('<button type="button" class="ispag-btn ispag-btn-grey-outlined"></button>').text(ispagT('Cancel')).appendTo($actions);
        var $send = $('<button type="button" class="ispag-btn ispag-btn-secondary-outlined"></button>').text(ispagT('Send request')).appendTo($actions);

        function close() { $overlay.remove(); $(document).off('keydown.ispagor'); }
        $cancel.on('click', close);
        $(document).on('keydown.ispagor', function (e) { if (e.key === 'Escape') close(); });
        $overlay.on('mousedown', function (e) { if (e.target === $overlay[0]) close(); });

        $send.on('click', function () {
            $send.prop('disabled', true).text(ispagT('Sending...'));
            $cancel.prop('disabled', true);
            $error.text('');
            onSend($text.val(), function (ok, message) {
                if (ok) { close(); return; }
                $error.text(message);
                $send.prop('disabled', false).text(ispagT('Send request'));
                $cancel.prop('disabled', false);
            });
        });

        $('body').append($overlay);
        $text.trigger('focus');
    }

    $(document).off('click.ispagor', '#ispag-request-offer').on('click.ispagor', '#ispag-request-offer', function (e) {
        e.preventDefault();
        var $btn = $(this);
        if ($btn.prop('disabled')) return;
        var dealId = $btn.data('deal-id');
        if (!dealId) { showInfo(ispagT('Error: Missing project ID.')); return; }

        openModal(function (message, done) {
            $.ajax({
                url: ispag_offer_request.ajax_url,
                type: 'POST',
                dataType: 'json',
                data: {
                    action: 'ispag_request_offer',
                    security: ispag_offer_request.nonce,
                    deal_id: dealId,
                    message: message
                }
            }).done(function (response) {
                if (response && response.success) {
                    $btn.prop('disabled', true).html('<span class="dashicons dashicons-yes-alt"></span> ').append(document.createTextNode(response.data.label || ispagT('Request sent')));
                    done(true);
                    showInfo(response.data.message);
                } else {
                    done(false, (response && response.data && response.data.message) || ispagT('A connection error occurred.'));
                }
            }).fail(function (jqXHR) {
                var msg = jqXHR && jqXHR.responseJSON && jqXHR.responseJSON.data && jqXHR.responseJSON.data.message;
                done(false, msg || ispagT('A connection error occurred.'));
            });
        });
    });
});
