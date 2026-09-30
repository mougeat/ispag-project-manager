// Fonction réutilisable pour gérer les titres éditables
async function handleEditableTitleBlur(event) {
// console.log('🔹 [JS DEBUG] --- Début de handleEditableTitleBlur ---');

    const field = event.target;
// console.log('🔹 [JS DEBUG] Élément ciblé :', field.id);

    // 1. Récupération des valeurs
    const newTitle = field.innerText.trim().replace('🧾 ', '').trim();
    const dealId = field.getAttribute('data-project-id') || field.getAttribute('data-deal');
    const source = field.getAttribute('data-source') || (field.id === 'editable-project-title' ? 'project' : 'purchase');
    const fieldName = field.getAttribute('data-name') || (field.id === 'editable-project-title' ? 'NumCommande' : 'RefCommande');
    let originalTitle = field.getAttribute('data-value') || field.innerText.trim().replace('🧾 ', '').trim();

    console.log('🔹 [JS DEBUG] Valeurs récupérées :', {
        newTitle,
        dealId,
        source,
        fieldName,
        originalTitle
    });

    // 2. Vérification des changements
    if (newTitle === "" || newTitle === originalTitle) {
// console.log('🔹 [JS DEBUG] Aucune modification détectée ou titre vide. Restauration.');
        // Restaurer le texte du titre (l'icône 🧾 est affichée hors de la zone éditable)
        field.innerText = originalTitle;
        return;
    }
// console.log('🔹 [JS DEBUG] Modification détectée. Préparation de la requête AJAX.');

    // 3. Mise à jour de l'attribut data-value
    field.setAttribute('data-value', newTitle);
// console.log('🔹 [JS DEBUG] Mise à jour de data-value :', newTitle);

    // 4. Envoi de la requête AJAX
    try {
        console.log('🔹 [JS DEBUG] Envoi de la requête AJAX avec les données :', {
            action: 'ispag_inline_edit_field',
            field: fieldName,
            value: newTitle,
            deal_id: dealId,
            source: source,
        });

        const response = await fetch(ajaxurl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({
                action: 'ispag_inline_edit_field',
                field: fieldName,
                value: newTitle,
                deal_id: dealId,
                source: source,
            }),
        });

// console.log('🔹 [JS DEBUG] Réponse AJAX reçue. Statut HTTP :', response.status);

        const data = await response.json();
// console.log('🔹 [JS DEBUG] Données JSON reçues :', data);

        // 5. Traitement de la réponse
        if (data.success) {
// console.log('🔹 [JS DEBUG] Succès : Champ mis à jour.');
            originalTitle = newTitle;
            // Mettre à jour le titre affiché
            field.innerText = newTitle;
            field.style.color = '#27ae60';
            setTimeout(() => {
                field.style.color = '';
// console.log('🔹 [JS DEBUG] Réinitialisation de la couleur.');
            }, 1000);
        } else {
            console.error('❌ [JS DEBUG] Error côté serveur :', data.data?.message || 'Message d\'erreur non spécifié');
            // Restaurer le texte du titre (l'icône 🧾 est affichée hors de la zone éditable)
            field.innerText = originalTitle;
            field.style.color = '#e74c3c';
        }
    } catch (error) {
        console.error('❌ [JS DEBUG] Network error ou AJAX :', error);
        // Restaurer le texte du titre (l'icône 🧾 est affichée hors de la zone éditable)
        field.innerText = originalTitle;
        field.style.color = '#e74c3c';
    }
// console.log('🔹 [JS DEBUG] --- Fin de handleEditableTitleBlur ---');
}

// Appliquer la logique aux deux éléments
document.addEventListener('DOMContentLoaded', function() {
// console.log('🔹 [JS DEBUG] DOM chargé. Recherche des éléments éditables.');

    const projectTitleElement = document.getElementById('editable-project-title');
    const purchaseTitleElement = document.getElementById('editable-purchase-title');

    if (projectTitleElement) {
// console.log('🔹 [JS DEBUG] Élément editable-project-title trouvé. Ajout des écouteurs.');
        projectTitleElement.addEventListener('blur', handleEditableTitleBlur);
        projectTitleElement.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
// console.log('🔹 [JS DEBUG] Touche Entrée pressée sur editable-project-title. Déclenchement de blur.');
                e.preventDefault();
                this.blur();
            }
        });
    } else {
// console.log('⚠️ [JS DEBUG] Élément editable-project-title non trouvé.');
    }

    if (purchaseTitleElement) {
// console.log('🔹 [JS DEBUG] Élément editable-purchase-title trouvé. Ajout des écouteurs.');
        purchaseTitleElement.addEventListener('blur', handleEditableTitleBlur);
        purchaseTitleElement.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
// console.log('🔹 [JS DEBUG] Touche Entrée pressée sur editable-purchase-title. Déclenchement de blur.');
                e.preventDefault();
                this.blur();
            }
        });
    } else {
// console.log('⚠️ [JS DEBUG] Élément editable-purchase-title non trouvé.');
    }
});


document.addEventListener('blur', function(e) {
    if (!e.target.classList.contains('ispag-editable-title')) return;

    const titleElement = e.target;
    const oldGroup = titleElement.getAttribute('data-value');
    const newGroup = titleElement.innerText.trim();
    const dealId = titleElement.getAttribute('data-deal-id');

    // Ne rien faire si la valeur n'a pas changé
    if (oldGroup === newGroup) return;

    // Données à envoyer
    const formData = new URLSearchParams();
    formData.append('action', 'update_group_name');
    formData.append('security', ispagVars.nonce);
    formData.append('deal_id', dealId);
    formData.append('old_group', oldGroup);
    formData.append('new_group', newGroup);

    fetch(ispagVars.ajaxurl, {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Mettre à jour l'attribut data-value pour la prochaine modif
            titleElement.setAttribute('data-value', newGroup);
// console.log('Update successful : ' + data.data.updated_rows + ' ligne(s) modifiée(s).');
        } else {
            alert('Error during update: ' + (data.data.message || 'Unknown error'));
            titleElement.innerText = oldGroup; // Revenir à l'ancienne valeur en cas d'erreur
        }
    })
    .catch(error => {
        console.error('Error AJAX:', error);
        titleElement.innerText = oldGroup;
    });
}, true);