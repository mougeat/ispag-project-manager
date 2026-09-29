document.addEventListener('click', async (event) => {
    
    // 1. Cas du bouton "Copier le titre du groupe"
    const btnGroup = event.target.closest('.ispag-btn-copy-group');
    if (btnGroup) {
        const targetId = btnGroup.getAttribute('data-target');
        const element = document.getElementById(targetId);
        if (element) {
            // On récupère le texte du H3 sans le span de l'icône si possible
            const textToCopy = element.innerText.trim();
            copyToClipboard(textToCopy, btnGroup);
        }
    }

    // 2. Cas du bouton "Copier la description" (dans la modal)
    const btnCopy = event.target.closest('.ispag-btn-copy-description');

    if (btnCopy) {
        // On récupère la cible (ex: "#delivery-info-copy")
        const targetSelector = btnCopy.getAttribute('data-target');
        
        if (targetSelector) {
            // On cherche l'élément dans le DOM
            const elementToCopy = document.querySelector(targetSelector);
            
            if (elementToCopy) {
                // Utilisation de innerText pour préserver le formatage (sauts de ligne)
                const textToCopy = elementToCopy.innerText.trim();
                copyToClipboard(textToCopy, btnCopy);
            } else {
                console.warn(`Élément cible "${targetSelector}" introuvable.`);
            }
        }
    }

});

// Fonction de copie universelle avec feedback
async function copyToClipboard(text, btn) {
    try {
        await navigator.clipboard.writeText(text);
        
        // Feedback visuel rapide
        const img = btn.querySelector('img');
        const originalSrc = img ? img.src : null;
        
        if (img) {
            // On peut soit changer l'image, soit ajouter un effet visuel
            const originalHTML = btn.innerHTML;
            btn.innerHTML = "✅"; // Change l'icône temporairement
            setTimeout(() => {
                btn.innerHTML = originalHTML;
            }, 1500);
        }
    } catch (err) {
        console.error('Erreur technique lors de la copie :', err);
    }
}