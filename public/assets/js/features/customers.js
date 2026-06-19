(function () {
let customerState = { rows: [], q: '', status: '', view: 'list', currentId: null, detailCache: {} };

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

async function loadCustomers(options = {}) {
    if (!document.getElementById('customersRoot')) return;
    if (options.source === 'pjax') {
        customerState.view = 'list';
        customerState.currentId = null;
    }
    const customers = await VKApi.request('/customers');
    customerState.rows = customers.data || [];
    if (customerState.view === 'detail' && customerState.currentId) {
        openCustomerDetail(customerState.currentId);
        return;
    }
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
        const haystack = `${row.code || ''} ${row.name || ''} ${row.tax_code || ''} ${row.billing_address || ''} ${row.address || ''} ${row.phone || ''} ${row.email || ''}`.toLowerCase();
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
    return `<strong>${VKTable.escapeHtml(row.name)}</strong><span class="row-note">${VKTable.escapeHtml(row.tax_code ? `MST: ${row.tax_code}` : (row.email || 'Chưa có mã số thuế'))}</span>`;
}

function renderContact(row) {
    return `${VKTable.escapeHtml(row.contact_name || '-')}<span class="row-note">${VKTable.escapeHtml(row.phone || 'Chưa có số điện thoại')}</span>`;
}

function openCustomerModal(id = null) {
    const row = id ? customerState.rows.find(item => item.id === id) : {};
    VKModal.open(id ? 'Sửa khách hàng' : 'Tạo khách hàng', `
        <div class="customer-form-sections">
            <section class="quick-customer-section">
                <h4><span aria-hidden="true">01</span>Thông tin khách hàng</h4>
                <div class="quick-customer-grid">
                    ${customerField('contact_name', 'Tên khách hàng', 'text', row?.contact_name || '', 'KH')}
                    ${customerField('phone', 'Số điện thoại', 'text', row?.phone || '', 'SDT')}
                    ${customerField('email', 'Email', 'email', row?.email || '', '@')}
                    ${customerField('address', 'Địa chỉ', 'text', row?.address || '', 'DC')}
                </div>
            </section>
            <section class="quick-customer-section">
                <h4><span aria-hidden="true">02</span>Thông tin xuất hóa đơn</h4>
                <div class="quick-customer-grid">
                    ${customerField('tax_code', 'Mã số thuế', 'text', row?.tax_code || '', 'MST')}
                    ${customerField('name', 'Tên công ty', 'text', row?.name || '', 'CT')}
                    ${customerField('billing_address', 'Địa chỉ công ty', 'text', row?.billing_address || '', 'DC')}
                    ${customerField('legal_representative', 'Người đại diện pháp luật', 'text', row?.legal_representative || '', 'ĐD')}
                    ${customerField('representative_position', 'Chức vụ đại diện', 'text', row?.representative_position || '', 'CV')}
                    ${customerField('credit_limit', 'Hạn mức công nợ', 'number', row?.credit_limit || '0', 'VND')}
                    ${customerField('payment_terms', 'Điều khoản thanh toán', 'text', row?.payment_terms || '', 'TT')}
                    ${customerField('bank_name', 'Ngân hàng', 'text', row?.bank_name || '', 'NH')}
                    ${customerField('bank_account_no', 'Số tài khoản', 'text', row?.bank_account_no || '', 'STK')}
                    ${customerField('bank_account_name', 'Tên tài khoản', 'text', row?.bank_account_name || '', 'TK')}
                    ${customerSelect('status', 'Trạng thái', [
                        { value: 'active', label: 'Hoạt động' },
                        { value: 'inactive', label: 'Không hoạt động' },
                    ], row?.status || 'active', 'TT')}
                </div>
            </section>
        </div>
    `, async (form) => {
        const data = Object.fromEntries(new FormData(form));
        if (!data.name && data.contact_name) data.name = data.contact_name;
        await VKApi.request(id ? `/customers/${id}` : '/customers', {
            method: id ? 'PUT' : 'POST',
            body: JSON.stringify({ ...data, credit_limit: Number(data.credit_limit || 0) }),
        });
        VKModal.toast(id ? 'Đã cập nhật khách hàng.' : 'Đã tạo khách hàng.');
        VKModal.close();
        if (id) customerState.detailCache[String(id)] = null;
        loadCustomers();
    }, {
        className: 'customer-form-modal',
        submitText: id ? 'Cập nhật' : 'Lưu khách hàng',
    });
}

function customerField(name, label, type, value = '', icon = '', full = false) {
    return `
        <div class="field customer-field ${full ? 'full' : ''}">
            <label for="${name}">${VKTable.escapeHtml(label)}</label>
            <div class="customer-input-wrap">
                <span aria-hidden="true">${VKTable.escapeHtml(icon)}</span>
                <input id="${name}" name="${name}" type="${type}" value="${VKTable.escapeHtml(value)}">
            </div>
        </div>
    `;
}

function customerSelect(name, label, options, selected = '', icon = '') {
    return `
        <div class="field customer-field">
            <label for="${name}">${VKTable.escapeHtml(label)}</label>
            <div class="customer-input-wrap">
                <span aria-hidden="true">${VKTable.escapeHtml(icon)}</span>
                <select id="${name}" name="${name}">
                    ${options.map(item => `<option value="${VKTable.escapeHtml(item.value)}" ${String(item.value) === String(selected) ? 'selected' : ''}>${VKTable.escapeHtml(item.label)}</option>`).join('')}
                </select>
            </div>
        </div>
    `;
}

function openCustomerDetail(id) {
    customerState.view = 'detail';
    customerState.currentId = id;
    VKRecordPage.open({
        root: '#customersRoot',
        type: 'customer',
        id,
        path: `/customers/${id}`,
        preview: customerState.rows.find(row => String(row.id) === String(id)),
        cache: customerState.detailCache,
        onBack: () => {
            customerState.view = 'list';
            customerState.currentId = null;
            renderCustomers();
        },
        actions: row => `<button class="btn small" type="button" data-edit-customer="${row.id}">Sửa khách hàng</button>`,
    });
}

window.loadCustomers = loadCustomers;
})();
