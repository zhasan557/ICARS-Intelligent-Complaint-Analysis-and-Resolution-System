/* Submit Complaint: client-side validation + AJAX multipart upload. The server re-validates everything. */
document.addEventListener('DOMContentLoaded', () => {
    const { http, validate, el } = ICARS;
    const form = document.getElementById('complaint-form');
    if (!form) return;

    const $ = (id) => document.getElementById(id);
    const alertBox = $('complaint-alert');
    const fileInput = $('f-attachments');
    const fileError = $('attachments-error');
    const fileList = $('attachments-list');
    const submitBtn = $('btn-submit');

    const maxBytes = Number(form.dataset.maxBytes);
    const maxFiles = Number(form.dataset.maxFiles);
    const allowed = form.dataset.allowed.split(',');

    const fmt = (b) => (b >= 1048576 ? (b / 1048576).toFixed(1) + ' MB' : b >= 1024 ? Math.round(b / 1024) + ' KB' : b + ' B');

    /* Description character counter */
    const desc = $('f-description');
    const counter = $('desc-count');
    const updateCount = () => { counter.textContent = desc.value.length; };
    desc.addEventListener('input', updateCount);
    updateCount();

    /* Attachment checks (UX only; the server is authoritative) */
    function showFileError(msg) {
        fileError.textContent = msg || '';
        fileError.classList.toggle('d-none', !msg);
        fileInput.classList.toggle('is-invalid', !!msg);
    }

    function checkFiles() {
        const files = [...fileInput.files];
        const problems = [];
        if (files.length > maxFiles) problems.push('You can attach at most ' + maxFiles + ' files.');
        files.forEach((f) => {
            const ext = f.name.includes('.') ? f.name.split('.').pop().toLowerCase() : '';
            if (!allowed.includes(ext)) problems.push('"' + f.name + '" is not an allowed file type.');
            else if (f.size > maxBytes) problems.push('"' + f.name + '" exceeds ' + fmt(maxBytes) + '.');
            else if (f.size === 0) problems.push('"' + f.name + '" is empty.');
        });
        showFileError(problems.join(' '));
        return problems.length === 0;
    }

    function renderFiles() {
        fileList.replaceChildren(...[...fileInput.files].map((f) =>
            el('li', { class: 'file-item' }, el('span', {}, f.name), el('span', { class: 'text-muted' }, fmt(f.size)))));
    }
    fileInput.addEventListener('change', () => { renderFiles(); checkFiles(); });

    /* Submit via AJAX */
    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        alertBox.classList.add('d-none');

        const fieldsOk = validate.form(form);
        const filesOk = checkFiles();
        if (!fieldsOk || !filesOk) return;

        submitBtn.disabled = true;
        submitBtn.textContent = 'Submitting…';
        try {
            const res = await http.post('api/complaints/create.php', new FormData(form));
            window.location.assign(ICARS.url(res.data.redirect));      // flash message is shown on the details page
        } catch (err) {
            const errors = { ...(err.payload?.errors || {}) };
            if (errors.attachments) { showFileError(errors.attachments); delete errors.attachments; }
            if (Object.keys(errors).length) {
                validate.showServerErrors(form, errors, alertBox);
            } else if (!err.payload?.errors) {
                alertBox.textContent = err.message;
                alertBox.classList.remove('d-none');
            }
            submitBtn.disabled = false;
            submitBtn.textContent = 'Submit complaint';
        }
    });
});
