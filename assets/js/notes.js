const addNoteBtn = document.getElementById('add-note-btn');
const noteModal = document.getElementById('note-modal');

if (addNoteBtn && noteModal) {
  addNoteBtn.addEventListener('click', () => {
    noteModal.style.display = 'block';
  });
}

document.getElementById('save-note').addEventListener('click', () => {
  const note = document.getElementById('note-content').value.trim();
  const is_task = document.getElementById('note-is-task').checked ? 1 : 0;

  const params = new URLSearchParams(window.location.search);
  const deal_id = params.get('deal_id') || 0;
  const achat_id = params.get('achat_id') || 0;

  if (!note) return alert(ispagNotes.noteSaved + ".");

  fetch(ajaxurl, {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams({
      action: 'ispag_add_note_ajax',
      note,
      is_task,
      deal_id,
      achat_id,
    })
  }).then(res => res.json()).then(data => {
    alert(data.success ? ispagNotes.noteSaved + "!" : ispagNotes.noteError);
    location.reload();
  });
});


document.addEventListener('click', function (e) {
  if (e.target.classList.contains('ispag-toggle-task')) {
    const btn = e.target;
    const noteId = btn.dataset.noteId;
    const current = parseInt(btn.dataset.current);

    const newStatus = current === 1 ? 0 : 1;

    fetch(ajaxurl, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({
        action: 'ispag_toggle_task_done',
        note_id: noteId,
        is_done: newStatus
      })
    })
    .then(res => res.json())
    .then(data => {
      if (data.success) {
        // ✅ Met à jour le bouton localement
        btn.dataset.current = newStatus;
        btn.style.backgroundColor = newStatus ? '#4caf50' : '#ff9800';
        btn.textContent = newStatus ? '✓ ' + ispagNotes.doneLabel : '☐ ' + ispagNotes.taskLabel;
      } else {
        alert('Erreur lors du changement de statut');
      }
    });
  }
});


document.addEventListener('submit', function (e) {
  if (e.target.classList.contains('ispag-delete-note-form')) {
    e.preventDefault();

    if (!confirm(ispagNotes.confirmDeleteNote + ' ?')) return;

    const form = e.target;
    const noteId = form.querySelector('[name="note_id"]').value;

    fetch(ispagNotes.ajaxurl, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({
        action: 'ispag_delete_note',
        note_id: noteId
      })
    })
    .then(res => res.json())
    .then(data => {
      if (data.success) {
        form.closest('.ispag-note-card').remove();
      } else {
        alert('Erreur lors de la suppression');
      }
    });
  }
});
