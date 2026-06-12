let customerState = { rows: [], q: '', status: '' };

document.addEventListener('vk:ready', () => loadCustomers());
document.addEventListener('input', (event) => {
    if (!document.getElementById('customersRoot')) return;
    if (event.target.matches('[data-list-search]')) {
        customerState.q = event.target.value.toLowerCase();
        renderCustomers();
    }
});
document.addEventListener('change', (event) => {
    if (!document.getElementById('customersRoot')) return;
    if (event.target.matches('[data-list-status]')) {
        customerState.status = event.target.value;
        renderCustomers();
    }
});
document.addEventListener('click', (event) => {
    if (!document.getElementById('customersRoot')) return;
    if (event.target.matches('[data-create-customer]')) openCustomerModal();
    if (event.target.matches('[data-edit-customer]')) {
        event.stopPropagation();
        openCustomerModal(Number(event.target.dataset.editCustomer));
    }
    if (event.target.closest('.row-action-menu')) return;
    const detail = event.target.closest('[data-customer-detail], tr[data-row-detail]');
    if (detail) openCustomerDetail(detail.dataset.customerDetail || detail.dataset.rowDetail);
});

async function loadCustomers() {
    if (!document.getElementById('customersRoot')) return;
    const customers = await VKApi.request('/customers');
    customerState.rows = customers.data || [];
    renderCustomers(customers.meta.total);
}

function renderCustomers(total = customerState.rows.length) {
    const rows = filterRows();
    document.getElementById('customersRoot').innerHTML = VKTable.fullList({
        title: 'Danh sách khách hàng',
        subtitle: 'Dữ liệu dùng cho khách hàng tiềm năng, báo giá, đơn bán và công nợ.',
        meta: `${VKTable.money(rows.length)} / ${VKTable.money(total)} khách hàng`,
        filters: VKTable.filterBar({
            searchPlaceholder: 'Tìm mã, tên, số điện thoại, email...',
            status: [
                { value: 'active', label: 'Hoạt động' },
                { value: 'inactive', label: 'Không hoạt động' },
            ],
        }),
        table: renderCustomerTable(rows),
    });
    restoreFilters();
}

function renderCustomerTable(rows) {
    return VKTable.renderTable([
        { label: 'Mã', render: row => `<span class="mono">${VKTable.escapeHtml(row.code)}</span>` },
        { label: 'Khách hàng', render: row => renderName(row) },
        { label: 'Liên hệ', render: row => renderContact(row) },
        { label: 'Hạn mức', render: row => VKTable.money(row.credit_limit) },
        { label: 'Phụ trách', render: row => VKTable.escapeHtml(row.sales_owner?.name || 'Chưa gán') },
        { label: 'Trạng thái', render: row => VKTable.statusBadge(row.status) },
        { label: '', render: row => VKTable.rowActions([
            VKTable.smallButton('Mở', `data-customer-detail="${row.id}"`),
            VKTable.smallButton('Sửa', `data-edit-customer="${row.id}"`),
        ]) },
    ], rows, 'Chưa có khách hàng', { rowAttr: row => `data-row-detail="${row.id}"` });
}

function filterRows() {
    return customerState.rows.filter(row => {
        const haystack = `${row.code || ''} ${row.name || ''} ${row.phone || ''} ${row.email || ''}`.toLowerCase();
        return (!customerState.q || haystack.includes(customerState.q)) && (!customerState.status || row.status === customerState.status);
    });
}

function restoreFilters() {
    const search = document.querySelector('[data-list-search]');
    const status = document.querySelector('[data-list-status]');
    if (search) search.value = customerState.q;
    if (status) status.value = customerState.status;
}

function renderName(row) {
    return `<strong>${VKTable.escapeHtml(row.name)}</strong><span class="row-note">${VKTable.escapeHtml(row.email || 'Chưa có email')}</span>`;
}

function renderContact(row) {
    return `${VKTable.escapeHtml(row.contact_name || '-')}<span class="row-note">${VKTable.escapeHtml(row.phone || 'Chưa có số điện thoại')}</span>`;
}

function openCustomerModal(id = null) {
    const row = id ? customerState.rows.find(item => item.id === id) : {};
    VKModal.open(id ? 'Sửa khách hàng' : 'Tạo khách hàng', `
        <div class="form-grid">
            ${VKModal.field('name', 'Tên khách hàng', 'text', row?.name || '')}
            ${VKModal.field('contact_name', 'Người liên hệ', 'text', row?.contact_name || '')}
            ${VKModal.field('phone', 'Số điện thoại', 'text', row?.phone || '')}
            ${VKModal.field('email', 'Email', 'email', row?.email || '')}
            ${VKModal.field('credit_limit', 'Hạn mức công nợ', 'number', row?.credit_limit || '0')}
            ${VKModal.select('status', 'Trạng thái', [
                { value: 'active', label: 'Hoạt động' },
                { value: 'inactive', label: 'Không hoạt động' },
            ], row?.status || 'active')}
        </div>
    `, async (form) => {
        const data = Object.fromEntries(new FormData(form));
        await VKApi.request(id ? `/customers/${id}` : '/customers', {
            method: id ? 'PUT' : 'POST',
            body: JSON.stringify({ ...data, credit_limit: Number(data.credit_limit || 0) }),
        });
        VKModal.toast(id ? 'Đã cập nhật khách hàng.' : 'Đã tạo khách hàng.');
        VKModal.close();
        loadCustomers();
    });
}

function openCustomerDetail(id) {
    const row = customerState.rows.find(item => String(item.id) === String(id));
    VKDetailDrawer.open({ type: 'customer', row: { ...row, timeline: [{ label: 'Tạo khách hàng', status: row.status, at: row.created_at }] } });
}
