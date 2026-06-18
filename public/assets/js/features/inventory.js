(function () {
let inventoryState = { balances: [], transactions: [], q: '', status: '' };

document.addEventListener('vk:ready', () => loadInventory());
document.addEventListener('input', (event) => {
    if (!document.getElementById('inventoryRoot')) return;
    if (event.target.matches('[data-list-search]')) {
        inventoryState.q = event.target.value.toLowerCase();
        renderInventory();
    }
});
document.addEventListener('change', (event) => {
    if (!document.getElementById('inventoryRoot')) return;
    if (event.target.matches('[data-list-status]')) {
        inventoryState.status = event.target.value;
        renderInventory();
    }
});
document.addEventListener('click', (event) => {
    if (!event.target.closest('[data-retry-inventory]')) return;
    loadInventory();
});

async function loadInventory() {
    const root = document.getElementById('inventoryRoot');
    if (!root) return;

    root.innerHTML = VKTable.skeleton ? VKTable.skeleton() : '<div class="list-loading">Đang tải dữ liệu tồn kho...</div>';

    try {
        const balances = await VKApi.request('/inventory-balances');
        inventoryState.balances = balances.data || [];
        renderInventory(balances.meta?.total ?? inventoryState.balances.length);

        try {
            const transactions = await VKApi.request('/inventory-transactions');
            inventoryState.transactions = transactions.data || [];
            renderInventory(balances.meta?.total ?? inventoryState.balances.length);
        } catch (error) {
            inventoryState.transactions = [];
        }
    } catch (error) {
        root.innerHTML = `
            <section class="list-page">
                <div class="empty">
                    <strong>Không tải được dữ liệu tồn kho</strong>
                    <span>${VKTable.escapeHtml(error.message || 'Vui lòng thử tải lại trang.')}</span>
                    <button class="btn small" type="button" data-retry-inventory>Thử lại</button>
                </div>
            </section>
        `;
    }
}

function renderInventory(total = inventoryState.balances.length) {
    const rows = filterBalances();
    document.getElementById('inventoryRoot').innerHTML = `
        ${VKTable.fullList({
            title: 'Tồn kho hiện tại',
            subtitle: 'Theo mã hàng, kho, lô, tồn thực tế, đã giữ và khả dụng.',
            meta: `${VKTable.money(rows.length)} / ${VKTable.money(total)} dòng tồn`,
            filters: VKTable.filterBar({
                searchPlaceholder: 'Tìm mã hàng, tên hàng, kho hoặc lô...',
                status: [
                    { value: 'low', label: 'Dưới mức tối thiểu' },
                    { value: 'reserved', label: 'Có giữ hàng' },
                    { value: 'ok', label: 'Ổn định' },
                ],
                actions: '<a class="btn small" href="/goods-receipts">Nhập kho</a><a class="btn small" href="/goods-issues">Xuất kho</a>',
            }),
            table: renderBalances(rows),
        })}
        <section class="list-page list-page-secondary">
            <div class="list-page-head">
                <div>
                    <h2>Giao dịch kho gần nhất</h2>
                    <span>Lịch sử nhập/xuất theo chứng từ nguồn.</span>
                </div>
                <div class="list-meta">${VKTable.money(inventoryState.transactions.length)} giao dịch</div>
            </div>
            ${renderTransactions(inventoryState.transactions)}
        </section>
    `;
    restoreFilters();
}

function renderBalances(rows) {
    return VKTable.renderTable([
        { label: 'Mã hàng', render: row => renderSku(row) },
        { label: 'Kho', render: row => renderWarehouse(row) },
        { label: 'Lô', render: row => `<span class="mono">${VKTable.escapeHtml(row.lot_no || '-')}</span>` },
        { label: 'Tồn thực tế', render: row => VKTable.money(row.on_hand) },
        { label: 'Đã giữ', render: row => renderReserved(row) },
        { label: 'Khả dụng', render: row => renderAvailable(row) },
        { label: 'Mức tồn', render: row => renderStockLevel(row) },
    ], rows, 'Chưa có tồn kho');
}

function renderTransactions(rows) {
    return VKTable.renderTable([
        { label: 'Loại', render: row => renderMovementType(row) },
        { label: 'Mã hàng', render: row => `<span class="mono">${VKTable.escapeHtml(row.sku?.sku_code || '-')}</span>` },
        { label: 'Kho', render: row => VKTable.escapeHtml(row.warehouse?.name || '-') },
        { label: 'Số lượng', render: row => renderQuantity(row) },
        { label: 'Tham chiếu', render: row => renderReference(row) },
        { label: 'Thời gian', render: row => formatDateTime(row.created_at) },
    ], rows.slice(0, 12), 'Chưa có giao dịch kho');
}

function filterBalances() {
    return inventoryState.balances.filter(row => {
        const haystack = `${row.sku?.sku_code || ''} ${row.sku?.name || ''} ${row.warehouse?.name || ''} ${row.lot_no || ''}`.toLowerCase();
        const matchText = !inventoryState.q || haystack.includes(inventoryState.q);
        const matchStatus = !inventoryState.status
            || (inventoryState.status === 'low' && isLowStock(row))
            || (inventoryState.status === 'reserved' && Number(row.reserved || 0) > 0)
            || (inventoryState.status === 'ok' && !isLowStock(row));
        return matchText && matchStatus;
    });
}

function restoreFilters() {
    const search = document.querySelector('[data-list-search]');
    const status = document.querySelector('[data-list-status]');
    if (search) search.value = inventoryState.q;
    if (status) status.value = inventoryState.status;
}

function renderSku(row) {
    return `<strong>${VKTable.escapeHtml(row.sku?.sku_code || '-')}</strong><span class="row-note">${VKTable.escapeHtml(row.sku?.name || '-')}</span>`;
}

function renderWarehouse(row) {
    return `${VKTable.escapeHtml(row.warehouse?.name || '-')}<span class="row-note">${VKTable.escapeHtml(row.warehouse?.code || '-')}</span>`;
}

function renderReserved(row) {
    const reserved = Number(row.reserved || 0);
    return `<span class="badge ${reserved > 0 ? 'warning' : 'inactive'}">${VKTable.money(reserved)}</span>`;
}

function renderAvailable(row) {
    return `<span class="badge ${isLowStock(row) ? 'warning' : 'success'}">${VKTable.money(row.available)}</span>`;
}

function renderStockLevel(row) {
    if (isLowStock(row)) return '<span class="badge warning">Dưới tối thiểu</span>';
    return '<span class="badge success">Ổn định</span>';
}

function renderMovementType(row) {
    const type = row.transaction_type;
    const badge = type === 'receipt' ? 'success' : type === 'issue' ? 'warning' : 'info';
    return `<span class="badge ${badge}">${VKTable.translateType(type)}</span>`;
}

function renderQuantity(row) {
    const value = Number(row.quantity || 0);
    return `<strong class="${value >= 0 ? 'movement-positive' : 'movement-negative'}">${value > 0 ? '+' : ''}${VKTable.money(value)}</strong>`;
}

function renderReference(row) {
    return `${VKTable.escapeHtml(VKTable.translateEntity(row.source_type || 'System'))}<span class="row-note">ID: ${VKTable.escapeHtml(row.source_id || '-')}</span>`;
}

function isLowStock(row) {
    return Number(row.available || 0) <= Number(row.sku?.min_stock || 0);
}

function formatDateTime(value) {
    return value ? new Date(value).toLocaleString('vi-VN') : '-';
}

window.loadInventory = loadInventory;
})();
