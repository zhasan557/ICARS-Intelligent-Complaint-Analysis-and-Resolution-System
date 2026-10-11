/* My Complaints: AJAX list with search, status filter and pagination. Data is rendered via textContent. */
document.addEventListener('DOMContentLoaded', () => {
    const { el, http } = ICARS;
    if (!document.getElementById('complaints-app')) return;

    const $ = (id) => document.getElementById(id);
    const state = { page: 1, q: '', status: '', pages: 1 };
    let metaLoaded = false;

    const badge = {
        'Submitted': 'text-bg-primary', 'AI Analyzed': 'text-bg-info', 'Under Review': 'text-bg-warning',
        'Assigned': 'text-bg-warning', 'In Progress': 'text-bg-warning', 'Resolved': 'text-bg-success',
        'User Verification': 'text-bg-success', 'Closed': 'text-bg-secondary', 'Reopened': 'text-bg-danger',
    };
    const fmtDate = (s) => new Date(s.replace(' ', 'T')).toLocaleString(undefined, { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });

    async function load() {
        const body = $('complaints-body');
        try {
            const qs = new URLSearchParams({ page: state.page, q: state.q, status: state.status });
            const { data } = await http.get('api/complaints/list.php?' + qs);
            if (!metaLoaded) {
                data.meta.statuses.forEach((s) => $('filter-status').append(el('option', { value: s }, s)));
                metaLoaded = true;
            }
            state.pages = Math.max(1, Math.ceil(data.total / data.per_page));
            render(data);
        } catch (err) {
            body.replaceChildren(el('tr', {}, el('td', { colspan: 6, class: 'text-center text-danger py-4' }, err.message)));
        }
    }

    function render(data) {
        const rows = data.complaints.map((c) => el('tr', {},
            el('td', { class: 'text-nowrap small' }, el('a', { href: ICARS.url('complaints/view.php?id=' + Number(c.id)) }, c.complaint_no)),
            el('td', {}, c.title, c.attachment_count ? el('span', { class: 'ms-2 badge text-bg-light', title: 'Attachments' }, '📎 ' + c.attachment_count) : ''),
            el('td', { class: 'small' }, c.category || el('span', { class: 'text-muted' }, 'Pending')),
            el('td', {}, el('span', { class: 'badge ' + (badge[c.status] || 'text-bg-light') }, c.status)),
            el('td', { class: 'small text-muted text-nowrap' }, fmtDate(c.created_at)),
            el('td', { class: 'small text-muted text-nowrap' }, fmtDate(c.updated_at))));

        $('complaints-body').replaceChildren(...(rows.length ? rows : [el('tr', {},
            el('td', { colspan: 6, class: 'text-center text-muted py-4' }, state.q || state.status ? 'No complaints match your filters.' : 'You have not submitted any complaints yet.'))]));
        $('complaints-summary').textContent = data.total + ' complaint(s) · page ' + data.page + ' of ' + state.pages;
        $('btn-prev').disabled = data.page <= 1;
        $('btn-next').disabled = data.page >= state.pages;
    }

    let timer;
    $('filter-q').addEventListener('input', (e) => {
        clearTimeout(timer);
        timer = setTimeout(() => { state.q = e.target.value.trim(); state.page = 1; load(); }, 300);
    });
    $('filter-status').addEventListener('change', (e) => { state.status = e.target.value; state.page = 1; load(); });
    $('filter-form').addEventListener('submit', (e) => e.preventDefault());
    $('btn-prev').addEventListener('click', () => { if (state.page > 1) { state.page--; load(); } });
    $('btn-next').addEventListener('click', () => { if (state.page < state.pages) { state.page++; load(); } });

    load();
});
