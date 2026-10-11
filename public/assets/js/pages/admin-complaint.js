/* Admin > Manage complaint: status / assignment / classification forms (AJAX JSON). The server enforces every rule. */
document.addEventListener('DOMContentLoaded', () => {
    const { http, validate } = ICARS;
    const panel = document.getElementById('manage-panel');
    if (!panel) return;

    /* Statuses that require a note make the note field required (mirrors STATUS_NEEDS_NOTE on the server). */
    const statusSelect = document.getElementById('s-status');
    if (statusSelect) {
        const needsNote = JSON.parse(statusSelect.dataset.needsNote || '[]');
        const note = document.getElementById('s-note');
        const hint = document.getElementById('s-note-hint');
        statusSelect.addEventListener('change', () => {
            const required = needsNote.includes(statusSelect.value);
            note.dataset.rules = required ? 'required|max:500' : 'max:500';
            hint.textContent = required ? '(required, visible to the complainant)' : '(optional, visible to the complainant)';
            if (!required) validate.field(note, statusSelect.form);
        });
    }

    panel.querySelectorAll('form[data-endpoint]').forEach((form) => {
        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            const alertBox = form.querySelector('.form-alert');
            alertBox.classList.add('d-none');
            if (!validate.form(form)) return;

            const payload = {};
            [...form.elements].forEach((el) => {
                if (!el.name) return;
                if (el.name === 'id') payload.id = Number(el.value);
                else if (el.tagName === 'SELECT' && el.name.endsWith('_id')) payload[el.name] = el.value === '' ? null : Number(el.value);
                else payload[el.name] = el.value;
            });

            const btn = form.querySelector('[type="submit"]');
            btn.disabled = true;
            try {
                await http.post(form.dataset.endpoint, payload);
                window.location.reload();                       // server rendered page + flash message show the new state
            } catch (err) {
                if (err.payload?.errors) validate.showServerErrors(form, err.payload.errors, alertBox);
                else { alertBox.textContent = err.message; alertBox.classList.remove('d-none'); }
                btn.disabled = false;
            }
        });
    });
});
