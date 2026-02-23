document.addEventListener('DOMContentLoaded', function() {
    const titleElement = document.getElementById('editable-project-title');

    // On vérifie que titleElement ET l'objet de config existent
    if (titleElement && typeof ispag_texts !== 'undefined') {
        
        titleElement.addEventListener('blur', function() {
            const newTitle = this.innerText.trim();
            const projectId = this.getAttribute('data-project-id');

            if (newTitle === "") return;

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
                    console.log('Titre mis à jour !');
                    titleElement.style.color = '#27ae60';
                    setTimeout(() => titleElement.style.color = '', 1000);
                } else {
                    console.error('Erreur: ' + data.data);
                    titleElement.style.color = '#e74c3c'; // Rouge en cas d'erreur
                }
            })
            .catch(error => console.error('Erreur:', error));
        });

        titleElement.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                this.blur();
            }
        });
    }
});