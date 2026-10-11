/* Admin > All Complaints: AJAX list, search, 5 filters, sorting, pagination. Filters live in the URL. */
document.addEventListener('DOMContentLoaded', () => {
    const { el, http } = ICARS;
    if (!document.getElementById('admin-complaints-app')) return;

    const $ = (id) => document.getElementById(id);
    const KEYS = ['q', 'status', 'category', 'priority', 'department', 'assignee', 'sort'];
    const url = new URLSearchParams(window.location.search);
    const state = { page: Math.max(1, Number(url.get('page')) || 1), pages: 1, sort: 'newest' };
    KEYS.forEach((k) => { state[k] = url.get(k) || state[k] || ''; });
    let metaReady = false;

    const statusBadge = { 'Submitted': 'text-bg-primary', 'AI Analyzed': 'text-bg-info', 'Under Review': 'text-bg-warning', 'Assigned': 'text-bg-warning',
        'In Progress': 'text-bg-warning', 'Resolved': 'text-bg-success', 'User Verification': 'text-bg-success', 'Closed': 'text-bg-secondary', 'Reopened': 'text-bg-danger' };
    const levelBadge = { Low: 'text-bg-secondary', Medium: 'text-bg-info', High: 'text-bg-warning', Critical: 'text-bg-danger' };
    const fmtDate = (s) => new Date(s.replace(' ', 'T')).toLocaleString(undefined, { day: '2-digit', month: 'short', year: 'numeric' });

    function params() {
        const p = new URLSearchParams();
        KEYS.forEach((k) => { if (state[k] && !(k === 'sort' && state[k] === 'newest')) p.set(k, state[k]); });   // defaults stay out of the URL
        if (state.page > 1) p.set('page', state.page);
        return p;
    }

    function fillSelect(id, items, valueKey, labelKey) {
        items.forEach((i) => $(id).append(el('option', { value: i[valueKey] }, i[labelKey])));
    }

    function initMeta(m) {
        fillSelect('filter-status', m.statuses.map((s) => ({ v: s, l: s })), 'v', 'l');
        fillSelect('filter-category', m.categories, 'id', 'name');
        fillSelect('filter-priority', m.priorities, 'id', 'name');
        fillSelect('filter-department', m.departments, 'id', 'name');
        fillSelect('filter-assignee', m.staff, 'id', 'name');
        $('filter-q').value = state.q;
        ['status', 'category', 'priority', 'department', 'assignee', 'sort'].forEach((k) => { $('filter-' + k).value = state[k]; });
        metaReady = true;
    }

    async function load() {
        const body = $('complaints-body');
        try {
            const { data } = await http.get('api/admin/complaints/list.php?' + params());
            if (!metaReady) initMeta(data.meta);
            state.pages = Math.max(1, Math.ceil(data.total / data.per_page));
            render(data);
            const qs = params().toString();
            window.history.replaceState(null, '', window.location.pathname + (qs ? '?' + qs : ''));
        } catch (err) {
            body.replaceChildren(el('tr', {}, el('td', { colspan: 7, class: 'text-center text-danger py-4' }, err.message)));
        }
    }

    function render(data) {
        const rows = data.complaints.map((c) => el('tr', {},
            el('td', { class: 'text-nowrap small' }, el('a', { href: ICARS.url('admin/complaint.php?id=' + Number(c.id)) }, c.complaint_no)),
            el('td', {}, el('div', { class: 'fw-semibold' }, c.title), el('div', { class: 'small text-muted' }, c.submitter)),
            el('td', { class: 'small' }, c.category || el('span', { class: 'text-muted' }, '—')),
            el('td', {}, c.priority ? el('span', { class: 'badge ' + (levelBadge[c.priority] || 'text-bg-light') }, c.priority) : el('span', { class: 'text-muted' }, '—')),
            el('td', { class: 'small' }, c.department || el('span', { class: 'text-muted' }, 'No department'),
                el('div', { class: 'text-muted' }, c.assignee ? '→ ' + c.assignee : 'Unassigned')),
            el('td', {}, el('span', { class: 'badge ' + (statusBadge[c.status] || 'text-bg-light') }, c.status)),
            el('td', { class: 'small text-muted text-nowrap' }, fmtDate(c.created_at))));

        $('complaints-body').replaceChildren(...(rows.length ? rows :
            [el('tr', {}, el('td', { colspan: 7, class: 'text-center text-muted py-4' }, 'No complaints match these filters.'))]));
        $('complaints-summary').textContent = data.total + ' complaint(s) · page ' + data.page + ' of ' + state.pages;
        $('btn-prev').disabled = data.page <= 1;
        $('btn-next').disabled = data.page >= state.pages;
    }

    let timer;
    $('filter-q').addEventListener('input', (e) => {
        clearTimeout(timer);
        timer = setTimeout(() => { state.q = e.target.value.trim(); state.page = 1; load(); }, 300);
    });
    ['status', 'category', 'priority', 'department', 'assignee', 'sort'].forEach((k) =>
        $('filter-' + k).addEventListener('change', (e) => { state[k] = e.target.value; state.page = 1; load(); }));
    $('filter-form').addEventListener('submit', (e) => e.preventDefault());
    $('btn-prev').addEventListener('click', () => { if (state.page > 1) { state.page--; load(); } });
    $('btn-next').addEventListener('click', () => { if (state.page < state.pages) { state.page++; load(); } });
    $('btn-reset').addEventListener('click', () => {
        KEYS.forEach((k) => { state[k] = ''; });
        state.sort = 'newest'; state.page = 1;
        $('filter-q').value = '';
        ['status', 'category', 'priority', 'department', 'assignee'].forEach((k) => { $('filter-' + k).value = ''; });
        $('filter-sort').value = 'newest';
        load();
    });

    load();
});
