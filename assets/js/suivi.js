window.ispagT = window.ispagT || function (s) { return s; }; // traductions des textes JS (voir includes/js-strings.php)
/**
 * Fichier : suivis.js
 * Description : Gestion des suivis de projet, des statuts éditables, et des actions associées.
 * Dépendances : jQuery, ispagStatusChoices (global), ispag_texts (global), ispag_suivis (global)
 */

// =============================================
// 1. FONCTION POUR RENDRE LES STATUTS ÉDITABLES
// =============================================
/**
 * Attache des écouteurs aux boutons `.editable-status` pour permettre leur édition.
 * Utilise `data-status-listener-attached` pour éviter les doublons.
 */
function attachEditableStatusListeners() {
    // console.log("🔧 [STATUS] Attachement des écouteurs aux statuts éditables...");

    // Vérifier que les statuts sont disponibles
    const statuses = window.ispagStatusChoices || [];
    if (statuses.length === 0) {
        console.warn("⚠️ [STATUS] Aucune option de statut disponible (ispagStatusChoices vide).");
        return;
    }

    // Sélectionner les boutons éditables (non déjà traités et non non-editable)
    document.querySelectorAll('.editable-status:not(.non-editable):not([data-status-listener-attached])').forEach(btn => {
        // Marquer comme traité pour éviter les doublons
        btn.setAttribute('data-status-listener-attached', 'true');

        btn.addEventListener('click', (e) => {
            e.stopPropagation(); // Empêcher la propagation des événements
            if (btn.querySelector('select')) return; // Déjà en mode édition

            const current = btn.dataset.current;
            const deal = btn.dataset.deal;
            const phase = btn.dataset.phase;

            // console.log(`🔄 [STATUS] Édition du statut pour deal=${deal}, phase=${phase}, current=${current}`);

            // Créer le menu déroulant
            const select = document.createElement('select');
            select.style.padding = '4px';
            select.style.borderRadius = '4px';
            select.style.width = '100%';
            select.style.border = '1px solid #ddd';

            // Ajouter une option vide
            const empty = document.createElement('option');
            empty.value = '';
            empty.textContent = '— Select a status —';
            select.appendChild(empty);

            // Ajouter les options de statut
            statuses.forEach(st => {
                const opt = document.createElement('option');
                opt.value = st.id;
                opt.textContent = st.name;
                opt.style.backgroundColor = st.color;
                if (st.id === current) opt.selected = true;
                select.appendChild(opt);
            });

            // Gérer le changement de statut
            select.addEventListener('change', () => {
                const selected = select.value;
                if (!selected) {
                    // Réafficher le statut actuel si aucune sélection
                    btn.innerHTML = '';
                    btn.textContent = statuses.find(st => st.id === current)?.name || current;
                    btn.style.backgroundColor = statuses.find(st => st.id === current)?.color || '';
                    return;
                }

                // console.log(`📤 [STATUS] Nouveau statut sélectionné : ${selected} pour phase=${phase}`);

                // Envoyer la requête AJAX pour mettre à jour le statut
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
                .then(res => {
                    if (!res.ok) throw new Error(`Error HTTP ${res.status}`);
                    return res.json();
                })
                .then(res => {
                    if (res.success) {
                        // console.log(`✅ [STATUS] Statut mis à jour : ${res.data.name} (couleur: ${res.data.color})`);
                        btn.innerText = res.data.name;
                        btn.style.backgroundColor = res.data.color;
                        btn.dataset.current = selected;
                    } else {
                        console.error("❌ [STATUS] Error :", res.data?.message || 'Message invalide');
                        alert(ispagT("Error while updating the status: ") + (res.data?.message || 'Inconnu'));
                        // Réafficher l'ancien statut
                        const oldStatus = statuses.find(st => st.id === current);
                        btn.innerText = oldStatus ? oldStatus.name : current;
                        btn.style.backgroundColor = oldStatus ? oldStatus.color : '';
                    }
                })
                .catch(err => {
                    console.error("❌ [STATUS] Network error :", err);
                    alert(ispagT("Network error. Please try again."));
                    // Réafficher l'ancien statut
                    const oldStatus = statuses.find(st => st.id === current);
                    btn.innerText = oldStatus ? oldStatus.name : current;
                    btn.style.backgroundColor = oldStatus ? oldStatus.color : '';
                });
            });

            // Remplacer le contenu du bouton par le select
            btn.innerHTML = '';
            btn.appendChild(select);
            select.focus(); // Focus automatique
        });
    });
}

// =============================================
// 2. FONCTIONS UTILITAIRES (MAIL, AJAX, ETC.)
// =============================================
/**
 * Ouvre un client mail avec les données fournies.
 * @param {Object} data - Contient subject, message, email_contact, email_copy, etc.
 */
async function send_mail(data) {
    const { subject, message, email_contact, email_copy } = data;

    // Commande client à joindre : brouillon .eml généré par le serveur (texte + pièce jointe)
    if (data.eml_url) {
        window.location.href = data.eml_url;
        return;
    }

    // Mail long (liste d'articles…) : mailto: tronquerait le texte -> brouillon .eml (s'ouvre dans Outlook avec tout le texte)
    if (message.length > 1200) {
        ispag_download_eml_draft({ to: email_contact, cc: email_copy, subject, message });
        return;
    }

    let mailto = `mailto:${encodeURIComponent(email_contact)}?`;
    const params = [];
    if (email_copy && email_copy.trim()) params.push(`cc=${encodeURIComponent(email_copy.trim())}`);
    params.push(`subject=${encodeURIComponent(subject)}`);
    params.push(`body=${encodeURIComponent(message)}`);

    mailto += params.join('&');
    window.location.href = mailto;
}

/** Télécharge un brouillon de mail (.eml, non envoyé) : objet, destinataire et texte complets. */
function ispag_download_eml_draft({ to, cc, subject, message }) {
    const enc = new TextEncoder();
    const b64 = (str) => {
        let bin = '';
        enc.encode(str).forEach(b => { bin += String.fromCharCode(b); });
        return btoa(bin);
    };
    const wrap = (str) => str.replace(/(.{76})/g, '$1\r\n');
    const headers = [
        'X-Unsent: 1',
        'To: ' + to,
    ];
    if (cc && cc.trim()) headers.push('Cc: ' + cc.trim());
    headers.push(
        'Subject: =?UTF-8?B?' + b64(subject) + '?=',
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: base64'
    );
    const eml = headers.join('\r\n') + '\r\n\r\n' + wrap(b64(message.replace(/\r?\n/g, '\r\n'))) + '\r\n';
    const blob = new Blob([eml], { type: 'message/rfc822' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = (subject || 'mail').replace(/[^\w\- ]+/g, '').trim().slice(0, 60) + '.eml';
    document.body.appendChild(a);
    a.click();
    a.remove();
    setTimeout(() => URL.revokeObjectURL(url), 5000);
}

/**
 * Fonction générique pour envoyer une requête AJAX et déclencher un callback.
 * @param {Object} options - Contient deal_id, btn, action, sendingText, successCallback, type.
 */
async function ispag_send_project_generic_ajax({
    deal_id,
    btn,
    action = 'ispag_prepare_mail_project',
    sendingText = 'Sending...',
    successCallback = null,
    type,
}) {
    const originalText = btn.innerText;
    btn.disabled = true;
    btn.innerText = sendingText;

    try {
        // console.log(`📤 [AJAX] Envoi de la requête : action=${action}, deal_id=${deal_id}, type=${type}`);
        const response = await fetch(ispag_texts.ajax_url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({
                action: action,
                deal_id: deal_id,
                type: type,
                // Articles cochés dans la liste : seuls ceux-là sont listés dans le mail (aucun coché = tous)
                article_ids: Array.from(document.querySelectorAll('.ispag-article-checkbox:checked'))
                    .map(cb => cb.dataset.articleId).filter(Boolean).join(',')
            })
        });

        if (!response.ok) throw new Error(`Error HTTP ${response.status}`);

        const result = await response.json();
        if (!result.success) {
            console.error("❌ [AJAX] Error :", result.message || 'Message invalide');
            alert(ispagT("Error: ") + (result.message || 'Inconnu'));
            return;
        }

        // console.log("✅ [AJAX] Réponse réussie :", result.data);
        if (typeof successCallback === 'function') {
            successCallback(result.data);
        }
    } catch (e) {
        console.error("❌ [AJAX] Error :", e);
        alert(ispagT("An error occurred: ") + e.message);
    } finally {
        btn.disabled = false;
        btn.innerText = originalText;
    }
}

// =============================================
// 3. GESTION DES BOUTONS D'ACTIONS PROJET
// =============================================
$(document).on('click', '.project-action-btn', function () {
    const hook = $(this).data('hook');
    const deal_id = $(this).data('deal-id');

    // console.log(`🎯 [ACTION] Clic sur un bouton d'action : hook=${hook}, deal_id=${deal_id}`);

    if (typeof window[hook] === 'function') {
        window[hook](deal_id, this);
    } else {
        console.warn('⚠️ [ACTION] Hook JS introuvable :', hook);
        alert(`Action non disponible : ${hook}`);
    }
});

// Après une demande de facture : les articles marqués facturés sont rechargés dans la liste
function ispag_refresh_after_invoice(data) {
    if (data && data.invoiced_count > 0 && typeof reloadArticleList === 'function') {
        reloadArticleList();
    }
}

// Facture partielle
function ispag_send_partial_invoice(deal_id, btn) {
    // console.log(`📄 [ACTION] Envoi de la facture partielle pour le deal ${deal_id}`);
    ispag_send_project_generic_ajax({
        deal_id: deal_id,
        btn: btn,
        action: 'ispag_prepare_mail_project',
        sendingText: 'Preparing the email...',
        type: 'situation',
        successCallback: (data) => { send_mail(data); ispag_refresh_after_invoice(data); }
    });
}

// Facture finale
function ispag_send_final_invoice(deal_id, btn) {
    // console.log(`📄 [ACTION] Envoi de la facture finale pour le deal ${deal_id}`);
    ispag_send_project_generic_ajax({
        deal_id: deal_id,
        btn: btn,
        action: 'ispag_prepare_mail_project',
        sendingText: 'Preparing the email...',
        type: 'facturation',
        successCallback: (data) => { send_mail(data); ispag_refresh_after_invoice(data); }
    });
}

// =============================================
// 4. CHARGEMENT DES SUIVIS ET GESTION DES ONGLETS
// =============================================
jQuery(document).ready(function($) {
    // console.log("🌍 [INIT] jQuery prêt. Initialisation des suivis...");

    // 1. Extraire le deal_id de l'URL
    const urlParams = new URLSearchParams(window.location.search);
    const dealId = urlParams.get('deal_id') || false;
    const projectTitleEl = document.getElementById('editable-project-title');
    const isQuotation = projectTitleEl ? projectTitleEl.dataset.isQuotation === '1' : false;

    // console.log("📊 [SUIVIS] dealId :", dealId);
    // console.log("📊 [SUIVIS] isQuotation :", isQuotation);

    // Attacher les écouteurs aux statuts éditables (au cas où ils seraient déjà dans le DOM)
    attachEditableStatusListeners();

    // Vérifier les problèmes du projet
    if (dealId) {
        // console.log("🔍 [PROBLEMS] Vérification des problèmes pour le deal", dealId);
        $.ajax({
            url: ispag_suivis.ajax_url,
            type: 'POST',
            data: {
                action: 'check_project_problems',
                deal_id: dealId
            },
            success: function(response) {
                if (response.success && response.data.has_problem) {
                    // console.log("⚠️ [PROBLEMS] Problèmes détectés. Affichage de la modal d'analyse...");
                    $('#ispag-analysis-modal-body').html(response.data.html);
                    $('#ispag-analysis-modal').fadeIn();
                }
            },
            error: function(xhr, status, error) {
                console.error("❌ [PROBLEMS] Error lors de la vérification des problèmes :", error);
            }
        });
    }

    // Gestion de la fermeture de la modal d'analyse
    $('.ispag-close-modal').on('click', function() {
        // console.log("🚪 [MODAL] Fermeture de la modal d'analyse.");
        $('#ispag-analysis-modal').fadeOut();
    });

    // Charger les suivis si dealId est disponible
    if (!dealId) {
        return; // pas de suivis sur cette page
    }

    // Fonction pour charger les suivis
    function loadSuivis() {
        // console.log(`🔄 [SUIVIS] Chargement des suivis pour le deal ${dealId}...`);

        $.ajax({
            url: ispag_texts.ajax_url,
            type: 'POST',
            data: {
                action: 'ispag_load_suivis',
                deal_id: dealId,
                is_quotation: isQuotation,
                nonce: ispag_texts.nonce,
            },
            beforeSend: function() {
                // console.log("📤 [SUIVIS] Requête AJAX envoyée.");
                $('#suivi').html(`
                    <div class="loading-spinner" style="text-align: center; padding: 20px;">
                        <span class="dashicons dashicons-update" style="animation: spin 1s linear infinite; font-size: 24px;"></span>
                        <p>Chargement des suivis...</p>
                    </div>
                `);
            },
            success: function(response) {
                if (response.success) {
                    // console.log("✅ [SUIVIS] Suivis chargés avec succès.");
                    $('#suivi').html(response.data.html);

                    // Réattacher les écouteurs après un petit délai
                    setTimeout(() => {
                        attachEditableStatusListeners();
                    }, 100);
                } else {
                    console.error("❌ [SUIVIS] Error :", response.data?.message || 'Message invalide');
                    $('#suivi').html(`
                        <p class="ispag-alert ispag-alert-danger">
                            <i class="dashicons dashicons-warning"></i>
                            ${response.data?.message || 'Unknown error'}
                        </p>
                    `);
                }
            },
            error: function(xhr, status, error) {
                console.error("❌ [SUIVIS] Error AJAX :", status, error);
                $('#suivi').html(`
                    <p class="ispag-alert ispag-alert-danger">
                        <i class="dashicons dashicons-dismiss"></i>
                        Error lors du chargement des suivis.
                    </p>
                `);
            }
        });
    }

    // Charger les suivis au chargement de la page
    loadSuivis();

    // Recharger les suivis si l'utilisateur clique sur l'onglet "Suivi"
    $('.tab-titles li[data-tab="suivi"]').on('click', function() {
        if ($(this).hasClass('loaded')) {
            // console.log("ℹ️ [SUIVIS] Onglet 'Suivi' déjà chargé. Réattachement des écouteurs...");
            setTimeout(() => {
                loadSuivis();
                attachEditableStatusListeners();
            }, 100);
            return;
        }
        // console.log("🔄 [SUIVIS] Clic sur l'onglet 'Suivi'. Rechargement...");
        loadSuivis();
        $(this).addClass('loaded');
    });
});