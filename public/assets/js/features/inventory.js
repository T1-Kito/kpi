(function () {
let inventoryState = { balances: [], transactions: [], q: '', status: '' };
let movementState = { page: 1, q: '', type: '', from: '', to: '' }, movementSequence = 0;

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
    const card = event.target.closest('[data-stock-card]');
    if (card) { openStockCard(card.dataset.stockCard); return; }
    const page = event.target.closest('[data-movement-page]');
    if (page) { movementState.page = Number(page.dataset.movementPage); loadMovements(); return; }
    if (event.target.closest('[data-apply-movements]')) {
        for (const key of ['q', 'type', 'from', 'to']) movementState[key] = document.querySelector(`[data-movement-${key}]`)?.value || '';
        movementState.page = 1; loadMovements(); return;
    }
    if (!event.target.closest('[data-retry-inventory]')) return;
    loadInventory();
});

async function loadInventory() {
    const root = document.getElementById('inventoryRoot');
    if (!root) return;
    if (root.dataset.inventoryMode === 'transactions') { await loadMovements(); return; }

    root.innerHTML = VKTable.skeleton ? VKTable.skeleton() : '<div class="list-loading">Đang tải dữ liệu tồn kho...</div>';

    try {
        const balances = await VKApi.request('/inventory-balances?page_size=100');
        inventoryState.balances = balances.data || [];
        renderInventory(balances.meta?.total ?? inventoryState.balances.length);

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
                actions: '<a class="btn small" href="/inventory-transactions">Giao dịch kho</a><a class="btn small" href="/goods-receipts">Nhập kho</a><a class="btn small" href="/goods-issues">Xuất kho</a>',
            }),
            table: renderBalances(rows),
        })}
    `;
    restoreFilters();
}

function renderBalances(rows) {
    return VKTable.renderTable([
        { label: 'Mã hàng', render: row => `<span class="mono" style="color:#e60073;font-weight:600">${VKTable.escapeHtml(row.sku?.sku_code || '—')}</span>` },
        { label: 'Tên hàng', render: row => VKTable.escapeHtml(row.sku?.name || '—') },
        { label: 'Mã kho', render: row => `<span class="mono">${VKTable.escapeHtml(row.warehouse?.code || '—')}</span>` },
        { label: 'Tên kho', render: row => VKTable.escapeHtml(row.warehouse?.name || '—') },
        { label: 'Lô', render: row => `<span class="mono">${VKTable.escapeHtml(row.lot_no || '-')}</span>` },
        { label: 'Tồn thực tế', render: row => VKTable.money(row.on_hand) },
        { label: 'Đã giữ', render: row => renderReserved(row) },
        { label: 'Khả dụng', render: row => renderAvailable(row) },
        { label: 'Mức tồn', render: row => renderStockLevel(row) },
        { label: '', render: row => `<button class="btn small" data-stock-card="${row.id}">Thẻ kho</button>` },
    ], rows, 'Chưa có tồn kho', { rowAttr: row => `data-stock-card="${row.id}"` });
}

function renderTransactions(rows) {
    return VKTable.renderTable([
        { label: 'Loại', render: row => renderMovementType(row) },
        { label: 'Mã hàng', render: row => `<span class="mono">${VKTable.escapeHtml(row.sku?.sku_code || '-')}</span>` },
        { label: 'Tên hàng', render: row => VKTable.escapeHtml(row.sku?.name || '—') },
        { label: 'Kho', render: row => VKTable.escapeHtml(row.warehouse?.name || '-') },
        { label: 'Lô', render: row => VKTable.escapeHtml(row.lot_no || '—') },
        { label: 'Số lượng', render: row => renderQuantity(row) },
        { label: 'Tham chiếu', render: row => renderReference(row) },
        { label: 'Thời gian', render: row => formatDateTime(row.created_at) },
    ], rows, 'Chưa có giao dịch kho trong phạm vi đã chọn');
}

function movementFilters(values, card = false) {
    const esc = VKTable.escapeHtml;
    return `<div class="stock-movement-filters">
        ${card ? '' : `<label>Tìm hàng / lô<input data-movement-q value="${esc(values.q || '')}" placeholder="Mã hàng, tên hàng, lô..."></label><label>Loại giao dịch<select data-movement-type><option value="">Tất cả</option><option value="receipt" ${values.type === 'receipt' ? 'selected' : ''}>Nhập kho</option><option value="issue" ${values.type === 'issue' ? 'selected' : ''}>Xuất kho</option></select></label>`}
        <label>Từ ngày<input type="date" ${card ? 'data-card-from' : 'data-movement-from'} value="${esc(values.from || '')}"></label>
        <label>Đến ngày<input type="date" ${card ? 'data-card-to' : 'data-movement-to'} value="${esc(values.to || '')}"></label>
        <button type="button" class="btn primary small" ${card ? 'data-apply-card' : 'data-apply-movements'}>Áp dụng</button></div>`;
}

function movementPager(meta, card = false) {
    return `<div class="stock-movement-pager"><span>${VKTable.money(meta.total)} giao dịch · Trang ${meta.page} / ${meta.last_page}</span>
        <div><button type="button" class="btn small" ${card ? 'data-card-page' : 'data-movement-page'}="${meta.page - 1}" ${meta.page <= 1 ? 'disabled' : ''}>Trước</button>
        <button type="button" class="btn small" ${card ? 'data-card-page' : 'data-movement-page'}="${meta.page + 1}" ${meta.page >= meta.last_page ? 'disabled' : ''}>Sau</button></div></div>`;
}

async function loadMovements() {
    const root = document.getElementById('inventoryRoot'), sequence = ++movementSequence;
    if (!root) return;
    try {
        const response = await VKApi.request(`/inventory-transactions?${new URLSearchParams({...movementState, page_size: 20})}`, {cache:false});
        if (sequence !== movementSequence || document.getElementById('inventoryRoot') !== root) return;
        root.innerHTML = VKTable.fullList({title:'Giao dịch kho', subtitle:'Lịch sử nhập, xuất và điều chỉnh. Mở chứng từ nguồn để truy vết.', meta:`${VKTable.money(response.meta.total)} giao dịch`, filters:movementFilters(movementState), table:renderTransactions(response.data)}) + movementPager(response.meta);
    } catch (error) { root.innerHTML = `<div class="empty">${VKTable.escapeHtml(error.message)}<button class="btn small" data-retry-inventory>Thử lại</button></div>`; }
}

async function openStockCard(id) {
    const balance = inventoryState.balances.find(row => String(row.id) === String(id));
    if (!balance) return;
    const filters = {sku_id:balance.sku_id, warehouse_id:balance.warehouse_id, lot_no:balance.lot_no || '', card:1, page:1, page_size:20, from:'', to:''};
    VKModal.open('Thẻ kho', `<div class="stock-card-context"><span class="mono">${VKTable.escapeHtml(balance.sku?.sku_code || '')}</span><span>${VKTable.escapeHtml(balance.sku?.name || '')}</span><small>${VKTable.escapeHtml(balance.warehouse?.name || '')} · Lô: ${VKTable.escapeHtml(balance.lot_no || 'Không có')}</small></div>${movementFilters(filters,true)}<div data-stock-card-content>Đang tải thẻ kho...</div>`, null, {hideSubmit:true,cancelText:'Đóng',className:'stock-card-modal'});
    const body = document.querySelector('[data-stock-card-content]');
    let sequence = 0;
    const refresh = async () => {
        const current = ++sequence;
        try {
            const response = await VKApi.request(`/inventory-transactions?${new URLSearchParams(filters)}`, {cache:false});
            if (current !== sequence || !body.isConnected) return;
            const summary = response.meta.summary;
            body.innerHTML = `${summary ? `<div class="stock-card-summary">${[['Tồn đầu kỳ','opening'],['Nhập trong kỳ','in'],['Xuất trong kỳ','out'],['Tồn cuối kỳ','closing']].map(([label,key]) => `<div><span>${label}</span><strong>${VKTable.money(summary[key])}</strong></div>`).join('')}</div>` : ''}${renderTransactions(response.data)}${movementPager(response.meta,true)}`;
        } catch (error) { if (body.isConnected) body.innerHTML = `<div class="empty">${VKTable.escapeHtml(error.message)}</div>`; }
    };
    const modal = document.getElementById('modalBody');
    if (modal._stockCardHandler) modal.removeEventListener('click', modal._stockCardHandler);
    modal._stockCardHandler = event => {
        if (event.target.closest('[data-apply-card]')) {
            filters.from = modal.querySelector('[data-card-from]').value; filters.to = modal.querySelector('[data-card-to]').value; filters.page = 1; refresh();
        }
        const page = event.target.closest('[data-card-page]');
        if (page) {filters.page = Number(page.dataset.cardPage); refresh();}
    };
    modal.addEventListener('click', modal._stockCardHandler);
    await refresh();
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
    const routes = {GoodsReceipt:'/goods-receipts', GoodsIssue:'/goods-issues'};
    if (row.source_code && routes[row.source_type]) return `<a href="${routes[row.source_type]}?open=${encodeURIComponent(row.source_id)}" class="mono">${VKTable.escapeHtml(row.source_code)}</a>`;
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
