let salesOrderState = { rows: [], q: '', status: '' };

document.addEventListener('vk:ready', () => loadSalesOrders());
document.addEventListener('vk:flow-updated', () => loadSalesOrders());
document.addEventListener('input', (event) => {
    if (!document.getElementById('salesOrdersRoot')) return;
    if (event.target.matches('[data-list-search]')) {
        salesOrderState.q = event.target.value.toLowerCase();
        renderSalesOrders();
    }
});
document.addEventListener('change', (event) => {
    if (!document.getElementById('salesOrdersRoot')) return;
    if (event.target.matches('[data-list-status]')) {
        salesOrderState.status = event.target.value;
        renderSalesOrders();
    }
});
document.addEventListener('click', (event) => {
    if (!document.getElementById('salesOrdersRoot')) return;
    if (event.target.matches('[data-create-sales-order]')) openSalesOrderModal();
    if (event.target.matches('[data-confirm-sales-order]')) {
        event.stopPropagation();
        confirmSalesOrder(event.target.dataset.confirmSalesOrder);
    }
    if (event.target.closest('.row-action-menu')) return;
    const detail = event.target.closest('[data-sales-order-detail], tr[data-row-detail]');
    if (detail) openSalesOrderDetail(detail.dataset.salesOrderDetail || detail.dataset.rowDetail);
});

async function loadSalesOrders() {
    if (!document.getElementById('salesOrdersRoot')) return;
    const orders = await VKApi.request('/sales-orders');
    salesOrderState.rows = orders.data || [];
    renderSalesOrders(orders.meta.total);
}

function renderSalesOrders(total = salesOrderState.rows.length) {
    const rows = filterRows();
    document.getElementById('salesOrdersRoot').innerHTML = VKTable.fullList({
        title: 'Danh sách đơn bán',
        subtitle: 'Kiểm tra tồn, giữ hàng, xử lý thiếu tồn và theo dõi xuất kho.',
        meta: `${VKTable.money(rows.length)} / ${VKTable.money(total)} đơn`,
        filters: VKTable.filterBar({
            searchPlaceholder: 'Tìm mã đơn hoặc khách hàng...',
            status: [
                { value: 'draft', label: 'Nháp' },
                { value: 'confirmed', label: 'Đã xác nhận' },
                { value: 'completed', label: 'Hoàn thành' },
            ],
        }),
        table: renderSalesOrderTable(rows),
    });
    restoreFilters();
}

function renderSalesOrderTable(rows) {
    return VKTable.renderTable([
        { label: 'Mã đơn', render: row => `<span class="mono">${VKTable.escapeHtml(row.code)}</span>` },
        { label: 'Khách hàng', render: row => VKTable.escapeHtml(row.customer?.name || '-') },
        { label: 'Tổng tiền', render: row => VKTable.money(row.total_amount) },
        { label: 'Hàng bán', render: row => renderItems(row.items) },
        { label: 'Tồn kho', render: row => VKTable.statusBadge(row.stock_status) },
        { label: 'Trạng thái', render: row => VKTable.statusBadge(row.status) },
        { label: '', render: row => VKTable.rowActions([
            VKTable.smallButton('Mở', `data-sales-order-detail="${row.id}"`),
            row.status === 'draft' ? VKTable.smallButton('Xác nhận', `data-confirm-sales-order="${row.id}"`, 'primary') : '',
        ]) },
    ], rows, 'Chưa có đơn bán', { rowAttr: row => `data-row-detail="${row.id}"` });
}

function filterRows() {
    return salesOrderState.rows.filter(row => {
        const haystack = `${row.code || ''} ${row.customer?.name || ''}`.toLowerCase();
        const matchText = !salesOrderState.q || haystack.includes(salesOrderState.q);
        const matchStatus = !salesOrderState.status || row.status === salesOrderState.status;
        return matchText && matchStatus;
    });
}

function restoreFilters() {
    const search = document.querySelector('[data-list-search]');
    const status = document.querySelector('[data-list-status]');
    if (search) search.value = salesOrderState.q;
    if (status) status.value = salesOrderState.status;
}

function renderItems(items = []) {
    if (!items.length) return '-';
    return items.slice(0, 2).map(item => `${VKTable.escapeHtml(item.sku?.sku_code || '-')}: ${VKTable.money(item.quantity)}`).join('<br>');
}

async function openSalesOrderModal() {
    const quotations = await VKApi.request('/quotations');
    const ready = quotations.data.filter(q => ['ready', 'approved'].includes(q.status));
    VKModal.open('Tạo đơn bán từ báo giá', `
        <div class="form-grid">
            ${VKModal.select('quotation_id', 'Báo giá', ready.map(q => ({ value: q.id, label: `${q.code} - ${q.customer?.name || ''} - ${VKTable.translateStatus(q.status)}` })))}
        </div>
    `, async (form) => {
        const data = Object.fromEntries(new FormData(form));
        await VKApi.request('/sales-orders', { method: 'POST', body: JSON.stringify({ quotation_id: Number(data.quotation_id) }) });
        VKModal.toast('Đã tạo đơn bán.');
        VKModal.close();
        loadSalesOrders();
    });
}

async function confirmSalesOrder(id) {
    await VKApi.request(`/sales-orders/${id}/confirm`, { method: 'POST' });
    VKModal.toast('Đã xác nhận đơn bán.');
    loadSalesOrders();
}

function openSalesOrderDetail(id) {
    VKDetailDrawer.open({ type: 'salesOrder', path: `/sales-orders/${id}` });
}
