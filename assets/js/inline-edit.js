document.addEventListener('DOMContentLoaded', function() {
    const titleElement = document.getElementById('editable-project-title');

    // Stocker le titre original
    let originalTitle = titleElement ? titleElement.innerText.trim() : '';

    // On vérifie que titleElement ET l'objet de config existent
    if (titleElement && typeof ispag_texts !== 'undefined') {
        
        titleElement.addEventListener('blur', function() {
            const newTitle = this.innerText.trim();
            const projectId = this.getAttribute('data-project-id');

            // Si vide, demander confirmation avant de restaurer
            if (newTitle === "") {
                const confirmEmpty = confirm(ispag_texts.confirm_empty_title || 'Voulez-vous vraiment laisser le titre vide ?');
                if (!confirmEmpty) {
                    this.innerText = originalTitle;
                } else {
                    // Si l'utilisateur confirme le titre vide, on ne fait rien (ne pas envoyer de requête)
                    this.innerText = originalTitle;
                }
                return;
            }

            // Si le titre n'a pas changé, ne pas envoyer de requête
            if (newTitle === originalTitle) {
                return;
            }

            const formData = new FormData();
            formData.append('action', 'update_project_title');
            formData.append('project_id', projectId);
            formData.append('new_title', newTitle);
            
            // UTILISATION DE L'OBJET LOCALISÉ ICI :
            formData.append('nonce', ispag_texts.nonce); 

            // UTILISATION DE L'URL AJAX LOCALISÉE ICI :
            fetch(ispag_texts.ajax_url, { 
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    originalTitle = newTitle; // Mettre à jour le titre original
                    titleElement.style.color = '#27ae60';
                    setTimeout(() => titleElement.style.color = '', 1000);
                } else {
                    // En cas d'erreur, restaurer l'ancien titre
                    this.innerText = originalTitle;
                    console.error('Erreur: ' + data.data);
                    titleElement.style.color = '#e74c3c'; // Rouge en cas d'erreur
                }
            })
            .catch(error => {
                // En cas d'erreur réseau, restaurer l'ancien titre
                this.innerText = originalTitle;
                console.error('Erreur:', error);
                titleElement.style.color = '#e74c3c';
            });
        });

        titleElement.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                this.blur();
            }
            // Annuler avec Échap
            if (e.key === 'Escape') {
                e.preventDefault();
                this.innerText = originalTitle;
                this.blur();
            }
        });
    }
});