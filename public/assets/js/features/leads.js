(function () {
let leadState = { rows: [], q: '', status: '' };

document.addEventListener('vk:ready', () => loadLeads());
document.addEventListener('input', (event) => {
    if (!document.getElementById('leadsRoot')) return;
    if (event.target.matches('[data-list-search]')) {
        leadState.q = event.target.value.toLowerCase();
        renderLeads();
    }
});
document.addEventListener('change', (event) => {
    if (!document.getElementById('leadsRoot')) return;
    if (event.target.matches('[data-list-status]')) {
        leadState.status = event.target.value;
        renderLeads();
    }
});
document.addEventListener('click', (event) => {
    if (!document.getElementById('leadsRoot')) return;
    if (event.target.matches('[data-create-lead]')) openLeadModal();
    const detail = event.target.closest('[data-lead-detail], tr[data-row-detail]');
    if (detail) openLeadDetail(detail.dataset.leadDetail || detail.dataset.rowDetail);
});

async function loadLeads() {
    if (!document.getElementById('leadsRoot')) return;
    const leads = await VKApi.request('/leads');
    leadState.rows = leads.data || [];
    renderLeads(leads.meta.total);
}

function renderLeads(total = leadState.rows.length) {
    const rows = filterRows();
    document.getElementById('leadsRoot').innerHTML = VKTable.fullList({
        title: 'Danh sách khách hàng tiềm năng',
        subtitle: 'Quản lý khách hàng tiềm năng theo nguồn, người phụ trách và trạng thái chăm sóc.',
        meta: `${VKTable.money(rows.length)} / ${VKTable.money(total)} khách hàng tiềm năng`,
        filters: VKTable.filterBar({
            searchPlaceholder: 'Tìm tên, số điện thoại, email...',
            status: [
                { value: 'new', label: 'Mới' },
                { value: 'assigned', label: 'Đã giao' },
            ],
        }),
        table: renderLeadTable(rows),
    });
    restoreFilters();
}

function renderLeadTable(rows) {
    return VKTable.renderTable([
        { label: 'Mã', render: row => `<span class="mono">${VKTable.escapeHtml(row.code)}</span>` },
        { label: 'Khách hàng tiềm năng', render: row => renderLeadName(row) },
        { label: 'Liên hệ', render: row => renderContact(row) },
        { label: 'Nguồn', render: row => VKTable.escapeHtml(row.source || '-') },
        { label: 'Phụ trách', render: row => VKTable.escapeHtml(row.assignee?.name || 'Chưa phân công') },
        { label: 'Trạng thái', render: row => VKTable.statusBadge(row.status) },
        { label: '', render: row => VKTable.rowActions([VKTable.smallButton('Mở', `data-lead-detail="${row.id}"`)]) },
    ], rows, 'Chưa có khách hàng tiềm năng', { rowAttr: row => `data-row-detail="${row.id}"` });
}

function filterRows() {
    return leadState.rows.filter(row => {
        const haystack = `${row.code || ''} ${row.name || ''} ${row.phone || ''} ${row.email || ''} ${row.source || ''}`.toLowerCase();
        return (!leadState.q || haystack.includes(leadState.q)) && (!leadState.status || row.status === leadState.status);
    });
}

function restoreFilters() {
    const search = document.querySelector('[data-list-search]');
    const status = document.querySelector('[data-list-status]');
    if (search) search.value = leadState.q;
    if (status) status.value = leadState.status;
}

function renderLeadName(row) {
    return `<strong>${VKTable.escapeHtml(row.name)}</strong><span class="row-note">${VKTable.escapeHtml(row.campaign_code || 'Chưa gắn chiến dịch')}</span>`;
}

function renderContact(row) {
    return `${VKTable.escapeHtml(row.phone || '-')}<span class="row-note">${VKTable.escapeHtml(row.email || 'Chưa có email')}</span>`;
}

async function openLeadModal() {
    const me = await VKApi.request('/me');
    VKModal.open('Tạo khách hàng tiềm năng', `
        <div class="form-grid">
            ${VKModal.field('name', 'Tên khách hàng tiềm năng')}
            ${VKModal.field('phone', 'Số điện thoại')}
            ${VKModal.field('email', 'Email', 'email')}
            ${VKModal.field('source', 'Nguồn khách hàng tiềm năng', 'text', 'Website')}
            ${VKModal.field('campaign_code', 'Mã chiến dịch')}
            ${VKModal.select('assigned_to', 'Nhân viên phụ trách', [{ value: me.data.id, label: me.data.name }])}
        </div>
    `, async (form) => {
        await VKApi.request('/leads', { method: 'POST', body: JSON.stringify(Object.fromEntries(new FormData(form))) });
        VKModal.toast('Đã tạo khách hàng tiềm năng và công việc chăm sóc.');
        VKModal.close();
        loadLeads();
    });
}

function openLeadDetail(id) {
    const row = leadState.rows.find(item => String(item.id) === String(id));
    VKRecordPage.open({
        root: '#leadsRoot',
        type: 'lead',
        id,
        path: `/leads/${id}`,
        preview: row || {},
        onBack: () => renderLeads(),
    });
    return;
    VKDetailDrawer.open({ type: 'lead', path: `/leads/${id}` });
}

window.loadLeads = loadLeads;
})();
