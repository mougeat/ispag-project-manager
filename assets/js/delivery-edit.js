// --- ADRESSE DE LIVRAISON : lecture / édition de tous les champs d'un coup ---
(function ($) {
    const AJAX_URL = (window.ispag_texts && ispag_texts.ajax_url) || window.ajaxurl;
    function box(el) { return $(el).closest('#ispag-delivery-box'); }

    $(document).on('click', '.ispag-delivery-edit-btn', function () {
        const $b = box(this);
        $b.find('.ispag-delivery-view').prop('hidden', true);
        $b.find('.ispag-delivery-actions').prop('hidden', true);
        $b.find('.ispag-delivery-edit-btn').prop('hidden', true);
        $b.find('.ispag-delivery-form').prop('hidden', false).find('input:first').trigger('focus');
        initDeliveryPhone($b);
    });

    // Téléphone : sélecteur de pays + formatage (intl-tel-input, comme dans le CRM ; chargé par le thème)
    function initDeliveryPhone($b) {
        const input = $b.find('input[name="num_tel_contact"]').get(0);
        if (!input || $(input).data('iti') || typeof window.intlTelInput === 'undefined') return;
        const utils = (window.ispag_params && ispag_params.utils_url) || 'https://cdn.jsdelivr.net/npm/intl-tel-input@20.0.5/build/js/utils.js';
        const iti = window.intlTelInput(input, {
            initialCountry: 'ch',
            preferredCountries: ['ch', 'fr', 'be', 'de'],
            separateDialCode: true,
            allowDropdown: true,
            dropdownContainer: document.body,
            utilsScript: utils
        });
        $(input).data('iti', iti);
    }

    function destroyDeliveryPhone($form) {
        const $input = $form.find('input[name="num_tel_contact"]');
        const iti = $input.data('iti');
        if (iti) { iti.destroy(); $input.removeData('iti'); }
    }

    $(document).on('click', '.ispag-delivery-cancel-btn', function () {
        const $b = box(this);
        const $f = $b.find('.ispag-delivery-form');
        destroyDeliveryPhone($f);
        $f.prop('hidden', true).get(0).reset();
        $b.find('.ispag-delivery-view, .ispag-delivery-actions, .ispag-delivery-edit-btn').prop('hidden', false);
    });

    // Échap = annuler
    $(document).on('keydown', '.ispag-delivery-form input', function (e) {
        if (e.key === 'Escape') { $(this).closest('#ispag-delivery-box').find('.ispag-delivery-cancel-btn').trigger('click'); }
    });

    // Code postal → ville (si la ville est vide)
    $(document).on('blur', '.ispag-delivery-form input[name="NIP"]', function () {
        const zip = $.trim(this.value);
        const $city = $(this).closest('form').find('input[name="City"]');
        if (!zip || $.trim($city.val())) return;
        const country = zip.length <= 4 ? 'CH' : 'FR';
        fetch('https://api.zippopotam.us/' + country + '/' + encodeURIComponent(zip))
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (d) {
                const city = d && d.places && d.places[0] && d.places[0]['place name'];
                if (city && !$.trim($city.val())) { $city.val(city); }
            })
            .catch(function () {});
    });

    $(document).on('submit', '.ispag-delivery-form', function (e) {
        e.preventDefault();
        const $form = $(this);
        const $b = box(this);
        const $btn = $form.find('button[type="submit"]');
        const $status = $form.find('.ispag-delivery-status');

        // Téléphone : validation + enregistrement au format international lisible (+41 79 123 45 67)
        const $phone = $form.find('input[name="num_tel_contact"]');
        const iti = $phone.data('iti');
        if (iti && $.trim($phone.val())) {
            if (!iti.isValidNumber()) {
                $status.text('❌ ' + (window.ispag_texts && ispag_texts.invalid_phone ? ispag_texts.invalid_phone : 'Invalid phone number'));
                $phone.trigger('focus');
                return;
            }
            const fmt = (window.intlTelInputUtils && intlTelInputUtils.numberFormat) ? intlTelInputUtils.numberFormat.INTERNATIONAL : undefined;
            $phone.val(fmt !== undefined ? iti.getNumber(fmt) : iti.getNumber());
        } else if (iti) {
            $phone.val('');
        }
        const data = $form.serializeArray();
        data.push({ name: 'action', value: 'ispag_project_save_delivery' });
        data.push({ name: 'deal_id', value: $b.data('deal') });
        data.push({ name: 'nonce', value: $b.data('nonce') });

        $btn.prop('disabled', true);
        $status.text('⏳ …');
        $.post(AJAX_URL, $.param(data)).done(function (response) {
            if (response && response.success && response.data && response.data.html) {
                $b.replaceWith(response.data.html);
            } else {
                $btn.prop('disabled', false);
                $status.text('❌ ' + ((response && response.data) || 'Error'));
            }
        }).fail(function () {
            $btn.prop('disabled', false);
            $status.text('❌ Network error');
        });
    });
})(jQuery);
