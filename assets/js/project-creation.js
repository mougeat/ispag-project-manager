jQuery(function ($) {
    var $form = $('#ispag-project-form');
    if (!$form.length || typeof ispag_pc === 'undefined') return;
    var T = ispag_pc.i18n;

    function remote(action, ph, extra) {
        return {
            placeholder: ph,
            minimumInputLength: 2,
            allowClear: true,
            language: { inputTooShort: function () { return T.type_more; }, noResults: function () { return T.no_results; } },
            ajax: {
                url: ispag_pc.ajax_url, dataType: 'json', delay: 300, cache: true,
                data: function (p) { return $.extend({ q: p.term, action: action, nonce: ispag_pc.nonce }, extra ? extra() : {}); },
                processResults: function (d) { return { results: d.results || [] }; }
            }
        };
    }
    function setOption($sel, o) {
        if (!o || !o.id) return;
        if (!$sel.find('option[value="' + o.id + '"]').length) $sel.append(new Option(o.text, o.id, true, true));
        $sel.val(String(o.id)).trigger('change');
    }

    var $company = $('#pc-company'), $contact = $('#pc-contact');
    if (ispag_pc.can_manage) {
        $company.select2(remote('search_ispag_companies', T.search_company));
        $contact.select2(remote('search_ispag_contacts', T.search_contact, function () { return { company_id: $company.val() }; }));
        $('#pc-engineer').select2(remote('search_ispag_ingenieurs', T.search_engineer));
        $('#pc-copy').select2(remote('ispag_pc_search_projects', T.search_project));
        // nouveau client choisi : le contact par défaut (soi-même) ne correspond plus
        $company.on('select2:select select2:clear', function () { $contact.val(null).trigger('change'); });
    }

    /* Type Projet / Offre */
    function setQuote(q) {
        $('#pc-isqotation').val(q ? '1' : '0');
        $form.toggleClass('is-quote', !!q);
        $('.pc-type-btn').removeClass('active').filter('[data-quote="' + (q ? 1 : 0) + '"]').addClass('active');
    }
    $('.pc-type-btn').on('click', function () { setQuote($(this).data('quote') == 1); });

    /* Création à la volée */
    function inlineBox(kind) { return $('#pc-new-' + kind); }
    $('[data-pc-open]').on('click', function () {
        var $b = inlineBox($(this).data('pc-open'));
        $b.prop('hidden', !$b.prop('hidden'));
        $b.find('input:first').trigger('focus');
    });
    $('[data-pc-cancel]').on('click', function () { $(this).closest('.pc-inline').prop('hidden', true); });
    $('[data-pc-submit]').on('click', function () {
        var kind = $(this).data('pc-submit'), $box = inlineBox(kind), $btn = $(this), $err = $box.find('.pc-error');
        var f = {};
        $box.find('[data-f]').each(function () { f[$(this).data('f')] = $.trim($(this).val()); });
        var data;
        if (kind === 'company') {
            if (!f.company_name) { $err.text(T.name_required).prop('hidden', false); return; }
            data = $.extend({ action: 'ispag_create_company', nonce: ispag_pc.company_nonce }, f);
        } else {
            if (!f.email) { $err.text(T.error).prop('hidden', false); return; }
            data = $.extend({ action: 'ispag_create_contact', nonce: ispag_pc.contact_nonce, owner_id: ispag_pc.user_id, lead_function: '', phone: '' }, f);
        }
        $err.prop('hidden', true);
        var label = $btn.text();
        $btn.prop('disabled', true).text(T.creating);
        $.post(ispag_pc.ajax_url, data).done(function (r) {
            if (r && r.success && r.data && r.data.id) {
                if (kind === 'company') setOption($company, { id: r.data.id, text: r.data.name });
                else {
                    setOption($contact, { id: r.data.id, text: r.data.text });
                }
                $box.find('input').val('');
                $box.prop('hidden', true);
            } else {
                var m = r && r.data && (r.data.message || (typeof r.data === 'string' ? r.data : '')) || T.error;
                $err.text(m).prop('hidden', false);
            }
        }).fail(function () { $err.text(T.error).prop('hidden', false); })
          .always(function () { $btn.prop('disabled', false).text(label); });
    });

    /* Alerte doublon */
    var timer;
    function esc(s) { return $('<div>').text(s == null ? '' : s).html(); }
    function checkDupes() {
        clearTimeout(timer);
        timer = setTimeout(function () {
            var name = $.trim($('#pc-name').val()), num = $.trim($('#pc-num').val());
            var $d = $('#pc-dupes');
            if (name.length < 3 && num.length < 2) { $d.prop('hidden', true); return; }
            $.get(ispag_pc.ajax_url, { action: 'ispag_pc_duplicates', nonce: ispag_pc.nonce, name: name, num: num, company_id: $company.val() || 0 })
                .done(function (r) {
                    var items = r && r.success && r.data && r.data.items || [];
                    if (!items.length) { $d.prop('hidden', true); return; }
                    var h = '<strong>' + esc(T.similar_title) + '</strong><ul>';
                    items.forEach(function (i) {
                        h += '<li><a href="' + esc(i.url) + '" target="_blank" rel="noopener">' + esc(i.name) + '</a>' +
                            (i.company ? ' — ' + esc(i.company) : '') + (i.date ? ' (' + esc(i.date) + ')' : '') + '</li>';
                    });
                    $d.html(h + '</ul>').prop('hidden', false);
                });
        }, 400);
    }
    $('#pc-name, #pc-num, #pc-order').on('input', checkDupes);
    $company.on('change', checkDupes);

    /* Pré-remplissage depuis un projet existant */
    $('#pc-copy').on('select2:select', function (e) {
        $.get(ispag_pc.ajax_url, { action: 'ispag_pc_prefill', nonce: ispag_pc.nonce, deal_id: e.params.data.id }).done(function (r) {
            if (!r || !r.success) return;
            var d = r.data;
            setQuote(!!d.quote);
            $('#pc-name').val(d.name + ' ' + T.copy_suffix);
            if (d.company) setOption($company, d.company);
            if (d.contact) setOption($contact, d.contact);
            if (d.engineer) setOption($('#pc-engineer'), d.engineer);
            $('#pc-submission').val(d.submission || '');
            $('.pc-notice').remove();
            $('.pc-copy').after('<div class="pc-notice">' + esc(T.copy_done) + '</div>');
            checkDupes();
        });
    });

    /* Validation douce : message près du champ */
    $form.on('submit', function (e) {
        var $n = $('#pc-name');
        if (!$.trim($n.val())) {
            e.preventDefault();
            $('#pc-name-error').text(T.name_required).prop('hidden', false);
            $n.trigger('focus');
            return;
        }
        setTimeout(function () { $('#pc-save').prop('disabled', true); }, 0);
    });
    $('#pc-name').on('input', function () { $('#pc-name-error').prop('hidden', true); });
});
