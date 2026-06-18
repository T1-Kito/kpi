(function () {
let supplierState = { rows: [], q: '', status: '' };

document.addEventListener('vk:ready', () => loadSuppliers());
document.addEventListener('input', (event) => {
    if (!document.getElementById('suppliersRoot')) return;
    if (event.target.matches('[data-list-search]')) {
        supplierState.q = event.target.value.toLowerCase();
        renderSuppliers();
    }
});
document.addEventListener('change', (event) => {
    if (!document.getElementById('suppliersRoot')) return;
    if (event.target.matches('[data-list-status]')) {
        supplierState.status = event.target.value;
        renderSuppliers();
    }
});
document.addEventListener('click', (event) => {
    if (!document.getElementById('suppliersRoot')) return;
    if (event.target.matches('[data-create-supplier]')) openSupplierModal();
    if (event.target.matches('[data-edit-supplier]')) {
        event.stopPropagation();
        openSupplierModal(Number(event.target.dataset.editSupplier));
    }
    if (event.target.closest('.row-action-menu')) return;
    const detail = event.target.closest('[data-supplier-detail], tr[data-row-detail]');
    if (detail) openSupplierDetail(detail.dataset.supplierDetail || detail.dataset.rowDetail);
});

async function loadSuppliers(options = {}) {
    const root = document.getElementById('suppliersRoot');
    if (!root) return;
    if (options.source === 'pjax' && supplierState.rows.length) {
        renderSuppliers();
    }
    const suppliers = await VKApi.request('/suppliers');
    if (document.getElementById('suppliersRoot') !== root) return;
    supplierState.rows = suppliers.data || [];
    renderSuppliers(suppliers.meta.total);
}

function renderSuppliers(total = supplierState.rows.length) {
    const rows = filterRows();
    document.getElementById('suppliersRoot').innerHTML = VKTable.fullList({
        title: 'Danh sách nhà cung cấp',
        subtitle: 'Quản lý nguồn mua hàng, điều khoản, đánh giá và trạng thái sử dụng.',
        meta: `${VKTable.money(rows.length)} / ${VKTable.money(total)} nhà cung cấp`,
        filters: VKTable.filterBar({
            searchPlaceholder: 'Tìm mã, tên, điện thoại, email...',
            status: [
                { value: 'active', label: 'Hoạt động' },
                { value: 'inactive', label: 'Không hoạt động' },
            ],
        }),
        table: renderSupplierTable(rows),
    });
    restoreFilters();
}

function renderSupplierTable(rows) {
    return VKTable.renderTable([
        { label: 'Mã', render: row => `<span class="mono">${VKTable.escapeHtml(row.code)}</span>` },
        { label: 'Nhà cung cấp', render: row => renderName(row) },
        { label: 'Liên hệ', render: row => renderContact(row) },
        { label: 'Điều khoản', render: row => VKTable.escapeHtml(row.terms || '-') },
        { label: 'Đánh giá', render: row => `<span class="badge info">${VKTable.money(row.rating || 0)}/5</span>` },
        { label: 'Trạng thái', render: row => VKTable.statusBadge(row.status) },
        { label: '', render: row => VKTable.rowActions([
            VKTable.smallButton('Mở', `data-supplier-detail="${row.id}"`),
            VKTable.smallButton('Sửa', `data-edit-supplier="${row.id}"`),
        ]) },
    ], rows, 'Chưa có nhà cung cấp', { rowAttr: row => `data-row-detail="${row.id}"` });
}

function filterRows() {
    return supplierState.rows.filter(row => {
        const haystack = `${row.code || ''} ${row.name || ''} ${row.phone || ''} ${row.email || ''}`.toLowerCase();
        return (!supplierState.q || haystack.includes(supplierState.q)) && (!supplierState.status || row.status === supplierState.status);
    });
}

function restoreFilters() {
    const search = document.querySelector('[data-list-search]');
    const status = document.querySelector('[data-list-status]');
    if (search) search.value = supplierState.q;
    if (status) status.value = supplierState.status;
}

function renderName(row) {
    return `<strong>${VKTable.escapeHtml(row.name)}</strong><span class="row-note">${VKTable.escapeHtml(row.supplied_products || 'Chưa khai báo nhóm hàng')}</span>`;
}

function renderContact(row) {
    return `${VKTable.escapeHtml(row.phone || '-')}<span class="row-note">${VKTable.escapeHtml(row.email || 'Chưa có email')}</span>`;
}

function openSupplierModal(id = null) {
    const row = id ? supplierState.rows.find(item => item.id === id) : {};
    VKModal.open(id ? 'Sửa nhà cung cấp' : 'Tạo nhà cung cấp', `
        <div class="form-grid">
            ${VKModal.field('name', 'Tên nhà cung cấp', 'text', row?.name || '')}
            ${VKModal.field('phone', 'Số điện thoại', 'text', row?.phone || '')}
            ${VKModal.field('email', 'Email', 'email', row?.email || '')}
            ${VKModal.field('rating', 'Đánh giá', 'number', row?.rating || '4')}
            ${VKModal.select('status', 'Trạng thái', [
                { value: 'active', label: 'Hoạt động' },
                { value: 'inactive', label: 'Không hoạt động' },
            ], row?.status || 'active')}
            <div class="field full"><label for="terms">Điều khoản</label><textarea id="terms" name="terms">${VKTable.escapeHtml(row?.terms || '')}</textarea></div>
            <div class="field full"><label for="supplied_products">Nhóm hàng cung cấp</label><textarea id="supplied_products" name="supplied_products">${VKTable.escapeHtml(row?.supplied_products || '')}</textarea></div>
        </div>
    `, async (form) => {
        const data = Object.fromEntries(new FormData(form));
        await VKApi.request(id ? `/suppliers/${id}` : '/suppliers', {
            method: id ? 'PUT' : 'POST',
            body: JSON.stringify({ ...data, rating: Number(data.rating || 0) }),
        });
        VKModal.toast(id ? 'Đã cập nhật nhà cung cấp.' : 'Đã tạo nhà cung cấp.');
        VKModal.close();
        loadSuppliers();
    });
}

function openSupplierDetail(id) {
    const row = supplierState.rows.find(item => String(item.id) === String(id));
    if (!row) return;
    VKRecordPage.open({
        root: '#suppliersRoot',
        type: 'supplier',
        id,
        path: `/suppliers/${id}`,
        preview: { ...row, timeline: [{ label: 'Tạo nhà cung cấp', status: row.status, at: row.created_at }] },
        onBack: () => renderSuppliers(),
        actions: () => `<button class="btn primary small" type="button" data-edit-supplier="${id}">Sửa nhà cung cấp</button>`,
    });
    return;
    VKDetailDrawer.open({ type: 'supplier', row: { ...row, timeline: [{ label: 'Tạo nhà cung cấp', status: row.status, at: row.created_at }] } });
}

window.loadSuppliers = loadSuppliers;
})();
