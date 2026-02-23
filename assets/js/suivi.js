document.addEventListener('DOMContentLoaded', function () {
    const statuses = window.ispagStatusChoices || [];

    document.querySelectorAll('.editable-status').forEach(btn => {
        btn.addEventListener('click', () => {
            if (btn.classList.contains('non-editable')) return; // 🛑 stop si non autorisé
            if (btn.querySelector('select')) return;

            const current = btn.dataset.current;
            const deal = btn.dataset.deal;
            const phase = btn.dataset.phase;

            const select = document.createElement('select');
            select.style.padding = '4px';
            select.style.borderRadius = '4px';

            // Ajouter une option vide si rien sélectionné
            const empty = document.createElement('option');
            empty.value = '';
            empty.textContent = '—';
            select.appendChild(empty);

            statuses.forEach(st => {
                const opt = document.createElement('option');
                opt.value = st.id;
                opt.textContent = st.name;
                opt.style.backgroundColor = st.color;
                if (st.id === current) opt.selected = true;
                select.appendChild(opt);
            });

            select.addEventListener('change', () => {
                const selected = select.value;
                fetch(ajaxurl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({
                        action: 'ispag_update_phase_status',
                        deal_id: deal,
                        slug_phase: phase,
                        status_id: selected
                    })
                })
                .then(res => res.json())
                .then(res => {
                    if (res.success) {
                        
                        btn.innerText = res.data.name;
                        btn.style.backgroundColor = res.data.color;
                        btn.dataset.current = selected;
                    }
                });
            });

            btn.innerHTML = '';
            btn.appendChild(select);
        });
    });
});
 

async function send_mail(data) {
    const { subject, message, email_contact, email_copy, achat_id, next_status } = data;

    let mailto = `mailto:${encodeURIComponent(email_contact)}?`;

    const params = [];

    if (email_copy) {
        params.push(`cc=${encodeURIComponent(email_copy)}`);
    }

    params.push(`subject=${encodeURIComponent(subject)}`);

    if (message.length > 1200) {
        const fallbackBody = "** Merci de presser Ctrl-A Ctrl-V OU click droit coller sur le contenu de ce mail **\n";
        params.push(`body=${encodeURIComponent(fallbackBody)}`);

        // Copier le message complet dans le presse-papiers
        try {
            await navigator.clipboard.writeText(message);
        } catch (err) {
            alert("Le message est trop long et la copie dans le presse-papiers a échoué.");
        }
    } else {
        params.push(`body=${encodeURIComponent(message)}`);
    }

    mailto += params.join('&');

    // Ouvre la fenêtre mail même si c’est le message fallback
    window.location.href = mailto;

}

async function ispag_send_project_generic_ajax({ 
    deal_id, 
    btn, 
    action = 'ispag_prepare_mail_project', 
    sendingText = 'Envoi...', 
    successCallback = null,
    type, 
}) {
    const originalText = btn.innerText;
    btn.disabled = true;
    btn.innerText = sendingText;
console.log(ispag_texts.ajax_url);
    try {
        const response = await fetch(ispag_texts.ajax_url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({
                action: action,
                deal_id: deal_id,
                type: type
            })
        });

        const result = await response.json();
        if (!result.success) {
            alert("Erreur : " + result.message);
            return;
        }

        // Appel de ta logique personnalisée (ex: ouvrir mailto)
        if (typeof successCallback === 'function') {
            successCallback(result.data);
        }

    } catch (e) {
        console.error(e);
        alert("Une erreur est survenue.");
    } finally {
        btn.disabled = false;
        btn.innerText = originalText;
    }
}

$(document).on('click', '.project-action-btn', function () {
    const hook = $(this).data('hook');
    const deal_id = $(this).data('deal-id');

    if (typeof window[hook] === 'function') {
        window[hook](deal_id, this);
        console.log(hook, deal_id, this);
    } else {
        console.warn('Hook JS introuvable :', hook);
    }
});

function ispag_send_partial_invoice(deal_id, btn) {
    ispag_send_project_generic_ajax({
        deal_id: deal_id,
        btn: btn,
        action: 'ispag_prepare_mail_project',
        sendingText: 'Envoi de l\'email...',
        type: 'situation',
        successCallback: (data) => {
//            console.log(data);
            send_mail(data);
            
        }
    });
}
function ispag_send_final_invoice(deal_id, btn) {
    ispag_send_project_generic_ajax({
        deal_id: deal_id,
        btn: btn,
        action: 'ispag_prepare_mail_project',
        sendingText: 'Envoi de l\'email...',
        type: 'facturation',
        successCallback: (data) => {
//            console.log(data);
            send_mail(data);
            
        }
    });
}


jQuery(document).ready(function($) {
    // 1. Extraire le deal_id de l'URL
    const urlParams = new URLSearchParams(window.location.search);
    const dealId = urlParams.get('deal_id');

    console.log('START ANALYSING PROBLEMS');
    console.log(ispag_suivis.ajax_url);

    if (dealId) {
        $.ajax({
            url: ispag_suivis.ajax_url, // Assure-toi que l'objet ajax_url est localisé
            type: 'POST',
            data: {
                action: 'check_project_problems',
                deal_id: dealId
            },
            success: function(response) {
                console.log('problems analyses', response.data);
                if (response.success && response.data.has_problem) {
                    
                    // Injecter le contenu et ouvrir la modal
                    $('#ispag-analysis-modal-body').html(response.data.html);
                    $('#ispag-analysis-modal').fadeIn();
                }
            }
        });
    }

    // Gestion de la fermeture de la modal
    $('.ispag-close-modal').on('click', function() {
        $('#ispag-analysis-modal').fadeOut();
    });
});