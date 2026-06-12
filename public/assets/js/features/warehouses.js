document.addEventListener('vk:ready', () => loadWarehouses());
document.addEventListener('click', (event) => {
    if (!document.getElementById('warehousesRoot')) return;
    if (event.target.matches('[data-create-warehouse]')) openWarehouseModal();
    if (event.target.matches('[data-edit-warehouse]')) openWarehouseModal(Number(event.target.dataset.editWarehouse));
});

let warehouseRows = [];

async function loadWarehouses() {
    if (!document.getElementById('warehousesRoot')) return;
    const warehouses = await VKApi.request('/warehouses');
    warehouseRows = warehouses.data;
    const active = warehouseRows.filter(row => row.status === 'active').length;
    const locations = warehouseRows.reduce((sum, row) => sum + (row.locations?.length || 0), 0);

    document.getElementById('warehousesRoot').innerHTML = `
        <div class="module-summary">
            ${summaryCard('Tổng kho', warehouses.meta.total, 'Kho đang khai báo')}
            ${summaryCard('Đang hoạt động', active, 'Có thể nhập xuất')}
            ${summaryCard('Vị trí lưu trữ', locations, 'Theo kho')}
        </div>
        <section class="module-panel">
            <div class="panel-head">
                <div>
                    <h2>Danh sách kho</h2>
                    <span>Dữ liệu dùng cho tồn kho, phiếu nhập và phiếu xuất.</span>
                </div>
            </div>
            ${renderWarehouseTable(warehouseRows)}
        </section>
    `;
}

function renderWarehouseTable(rows) {
    return VKTable.renderTable([
        { label: 'Mã', render: row => `<span class="mono">${VKTable.escapeHtml(row.code)}</span>` },
        { label: 'Kho', render: row => renderName(row) },
        { label: 'Địa chỉ', render: row => VKTable.escapeHtml(row.address || '-') },
        { label: 'Vị trí', render: row => renderLocations(row.locations) },
        { label: 'Trạng thái', render: row => VKTable.statusBadge(row.status) },
        { label: 'Thao tác', render: row => `<button class="btn" type="button" data-edit-warehouse="${row.id}">Sửa</button>` },
    ], rows, 'Chưa có kho');
}

function renderName(row) {
    return `<strong>${VKTable.escapeHtml(row.name)}</strong><span class="row-note">ID quản lý: ${VKTable.escapeHtml(row.manager_id || '-')}</span>`;
}

function renderLocations(locations = []) {
    if (!locations.length) return '-';
    return locations.map(item => `<span class="badge info">${VKTable.escapeHtml(item.code)}</span>`).join(' ');
}

function openWarehouseModal(id = null) {
    const row = id ? warehouseRows.find(item => item.id === id) : {};
    VKModal.open(id ? 'Sửa kho' : 'Tạo kho', `
        <div class="form-grid">
            ${VKModal.field('name', 'Tên kho', 'text', row?.name || '')}
            ${VKModal.field('address', 'Địa chỉ', 'text', row?.address || '')}
            ${id ? '' : VKModal.field('location_name', 'Tên vị trí đầu tiên', 'text', 'Kệ A1')}
            ${VKModal.select('status', 'Trạng thái', [
                { value: 'active', label: 'Hoạt động' },
                { value: 'inactive', label: 'Không hoạt động' },
            ])}
        </div>
    `, async (form) => {
        const data = Object.fromEntries(new FormData(form));
        const payload = id ? {
            name: data.name,
            address: data.address || null,
            status: data.status,
        } : {
            name: data.name,
            address: data.address || null,
            status: data.status,
            locations: data.location_name ? [{ name: data.location_name }] : [],
        };
        await VKApi.request(id ? `/warehouses/${id}` : '/warehouses', { method: id ? 'PUT' : 'POST', body: JSON.stringify(payload) });
        VKModal.toast(id ? 'Đã cập nhật kho.' : 'Đã tạo kho.');
        VKModal.close();
        loadWarehouses();
    });
    if (id) document.getElementById('status').value = row.status || 'active';
}

function summaryCard(label, value, note) {
    return `<section class="summary-card"><span>${label}</span><strong>${value}</strong><small>${note}</small></section>`;
}
