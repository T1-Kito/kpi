(function () {
let skuState = { rows: [], q: '', status: '' };

document.addEventListener('vk:ready', () => loadSkus());
document.addEventListener('input', (event) => {
    if (!document.getElementById('skusRoot')) return;
    if (event.target.matches('[data-list-search]')) {
        skuState.q = event.target.value.toLowerCase();
        renderSkus();
    }
});
document.addEventListener('change', (event) => {
    if (!document.getElementById('skusRoot')) return;
    if (event.target.matches('[data-list-status]')) {
        skuState.status = event.target.value;
        renderSkus();
    }
});
document.addEventListener('click', (event) => {
    if (!document.getElementById('skusRoot')) return;
    if (event.target.matches('[data-create-sku]')) openSkuModal();
    if (event.target.matches('[data-edit-sku]')) {
        event.stopPropagation();
        openSkuModal(Number(event.target.dataset.editSku));
    }
    if (event.target.closest('.row-action-menu')) return;
    const detail = event.target.closest('[data-sku-detail], tr[data-row-detail]');
    if (detail) openSkuDetail(detail.dataset.skuDetail || detail.dataset.rowDetail);
});

async function loadSkus() {
    if (!document.getElementById('skusRoot')) return;
    const skus = await VKApi.request('/skus');
    skuState.rows = skus.data || [];
    renderSkus(skus.meta.total);
}

function renderSkus(total = skuState.rows.length) {
    const rows = filterRows();
    document.getElementById('skusRoot').innerHTML = VKTable.fullList({
        title: 'Danh sách mã hàng',
        subtitle: 'Dữ liệu dùng cho báo giá, mua hàng, nhập xuất tồn và KPI kho.',
        meta: `${VKTable.money(rows.length)} / ${VKTable.money(total)} mã hàng`,
        filters: VKTable.filterBar({
            searchPlaceholder: 'Tìm SKU, tên hàng, barcode...',
            status: [
                { value: 'active', label: 'Hoạt động' },
                { value: 'inactive', label: 'Không hoạt động' },
            ],
        }),
        table: renderSkuTable(rows),
    });
    restoreFilters();
}

function renderSkuTable(rows) {
    return VKTable.renderTable([
        { label: 'Mã hàng', render: row => renderSku(row) },
        { label: 'Sản phẩm', render: row => VKTable.escapeHtml(row.product?.name || '-') },
        { label: 'Đơn vị', render: row => VKTable.escapeHtml(row.unit || '-') },
        { label: 'Giá vốn', render: row => VKTable.money(row.cost_price) },
        { label: 'Giá bán', render: row => VKTable.money(row.sale_price) },
        { label: 'Tồn min/max', render: row => `${VKTable.money(row.min_stock)} / ${VKTable.money(row.max_stock)}` },
        { label: 'Trạng thái', render: row => VKTable.statusBadge(row.status) },
        { label: '', render: row => VKTable.rowActions([
            VKTable.smallButton('Mở', `data-sku-detail="${row.id}"`),
            VKTable.smallButton('Sửa', `data-edit-sku="${row.id}"`),
        ]) },
    ], rows, 'Chưa có mã hàng', { rowAttr: row => `data-row-detail="${row.id}"` });
}

function filterRows() {
    return skuState.rows.filter(row => {
        const haystack = `${row.sku_code || ''} ${row.name || ''} ${row.barcode || ''} ${row.product?.name || ''}`.toLowerCase();
        return (!skuState.q || haystack.includes(skuState.q)) && (!skuState.status || row.status === skuState.status);
    });
}

function restoreFilters() {
    const search = document.querySelector('[data-list-search]');
    const status = document.querySelector('[data-list-status]');
    if (search) search.value = skuState.q;
    if (status) status.value = skuState.status;
}

function renderSku(row) {
    return `<strong class="mono">${VKTable.escapeHtml(row.sku_code)}</strong><span class="row-note">${VKTable.escapeHtml(row.name)}</span>`;
}

function openSkuModal(id = null) {
    const row = id ? skuState.rows.find(item => item.id === id) : {};
    VKModal.open(id ? 'Sửa mã hàng' : 'Tạo mã hàng', `
        <div class="form-grid">
            ${VKModal.field('product_name', 'Tên sản phẩm', 'text', row?.product?.name || '')}
            ${VKModal.field('name', 'Tên mã hàng', 'text', row?.name || '')}
            ${VKModal.field('barcode', 'Mã vạch', 'text', row?.barcode || '')}
            ${VKModal.field('unit', 'Đơn vị tính', 'text', row?.unit || 'pcs')}
            ${VKModal.field('min_stock', 'Tồn tối thiểu', 'number', row?.min_stock || '0')}
            ${VKModal.field('max_stock', 'Tồn tối đa', 'number', row?.max_stock || '0')}
            ${VKModal.field('cost_price', 'Giá vốn', 'number', row?.cost_price || '0')}
            ${VKModal.field('sale_price', 'Giá bán', 'number', row?.sale_price || '0')}
            ${VKModal.select('status', 'Trạng thái', [
                { value: 'active', label: 'Hoạt động' },
                { value: 'inactive', label: 'Không hoạt động' },
            ], row?.status || 'active')}
        </div>
    `, async (form) => {
        const data = Object.fromEntries(new FormData(form));
        await VKApi.request(id ? `/skus/${id}` : '/skus', {
            method: id ? 'PUT' : 'POST',
            body: JSON.stringify({
                ...data,
                min_stock: Number(data.min_stock || 0),
                max_stock: Number(data.max_stock || 0),
                cost_price: Number(data.cost_price || 0),
                sale_price: Number(data.sale_price || 0),
            }),
        });
        VKModal.toast(id ? 'Đã cập nhật mã hàng.' : 'Đã tạo mã hàng.');
        VKModal.close();
        loadSkus();
    });
}

function openSkuDetail(id) {
    const row = skuState.rows.find(item => String(item.id) === String(id));
    if (!row) return;
    VKRecordPage.open({
        root: '#skusRoot',
        type: 'sku',
        id,
        path: `/skus/${id}`,
        preview: { ...row, timeline: [{ label: 'Tạo mã hàng', status: row.status, at: row.created_at }] },
        onBack: () => renderSkus(),
        actions: () => `<button class="btn primary small" type="button" data-edit-sku="${id}">Sửa mã hàng</button>`,
    });
    return;
    VKDetailDrawer.open({ type: 'sku', row: { ...row, timeline: [{ label: 'Tạo mã hàng', status: row.status, at: row.created_at }] } });
}

window.loadSkus = loadSkus;
})();
