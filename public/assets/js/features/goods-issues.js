let giState = { rows: [], q: '', status: '' };

document.addEventListener('vk:ready', () => loadGoodsIssues());
document.addEventListener('vk:flow-updated', () => loadGoodsIssues());
document.addEventListener('input', (event) => {
    if (!document.getElementById('goodsIssuesRoot')) return;
    if (event.target.matches('[data-list-search]')) {
        giState.q = event.target.value.toLowerCase();
        renderGoodsIssues();
    }
});
document.addEventListener('change', (event) => {
    if (!document.getElementById('goodsIssuesRoot')) return;
    if (event.target.matches('[data-list-status]')) {
        giState.status = event.target.value;
        renderGoodsIssues();
    }
});
document.addEventListener('click', (event) => {
    if (!document.getElementById('goodsIssuesRoot')) return;
    if (event.target.matches('[data-create-goods-issue]')) openGoodsIssueModal();
    if (event.target.matches('[data-confirm-goods-issue]')) {
        event.stopPropagation();
        confirmGoodsIssue(event.target.dataset.confirmGoodsIssue);
    }
    if (event.target.closest('.row-action-menu')) return;
    const detail = event.target.closest('[data-goods-issue-detail], tr[data-row-detail]');
    if (detail) openGoodsIssueDetail(detail.dataset.goodsIssueDetail || detail.dataset.rowDetail);
});

async function loadGoodsIssues() {
    if (!document.getElementById('goodsIssuesRoot')) return;
    const issues = await VKApi.request('/goods-issues');
    giState.rows = issues.data || [];
    renderGoodsIssues(issues.meta.total);
}

function renderGoodsIssues(total = giState.rows.length) {
    const rows = filterRows();
    document.getElementById('goodsIssuesRoot').innerHTML = VKTable.fullList({
        title: 'Danh sách phiếu xuất',
        subtitle: 'Xuất kho theo đơn bán đã giữ hàng và hoàn tất giao dịch kho.',
        meta: `${VKTable.money(rows.length)} / ${VKTable.money(total)} phiếu`,
        filters: VKTable.filterBar({
            searchPlaceholder: 'Tìm mã phiếu, đơn bán hoặc kho...',
            status: [
                { value: 'draft', label: 'Nháp' },
                { value: 'confirmed', label: 'Đã xác nhận' },
            ],
        }),
        table: renderIssueTable(rows),
    });
    restoreFilters();
}

function renderIssueTable(rows) {
    return VKTable.renderTable([
        { label: 'Mã phiếu', render: row => `<span class="mono">${VKTable.escapeHtml(row.code)}</span>` },
        { label: 'Đơn bán', render: row => `<span class="mono">${VKTable.escapeHtml(row.sales_order?.code || '-')}</span>` },
        { label: 'Kho xuất', render: row => VKTable.escapeHtml(row.warehouse?.name || '-') },
        { label: 'Hàng xuất', render: row => renderItems(row.items) },
        { label: 'Trạng thái', render: row => VKTable.statusBadge(row.status) },
        { label: '', render: row => VKTable.rowActions([
            VKTable.smallButton('Mở', `data-goods-issue-detail="${row.id}"`),
            row.status === 'draft' ? VKTable.smallButton('Xác nhận', `data-confirm-goods-issue="${row.id}"`, 'primary') : '',
        ]) },
    ], rows, 'Chưa có phiếu xuất', { rowAttr: row => `data-row-detail="${row.id}"` });
}

function filterRows() {
    return giState.rows.filter(row => {
        const haystack = `${row.code || ''} ${row.sales_order?.code || ''} ${row.warehouse?.name || ''}`.toLowerCase();
        return (!giState.q || haystack.includes(giState.q)) && (!giState.status || row.status === giState.status);
    });
}

function restoreFilters() {
    const search = document.querySelector('[data-list-search]');
    const status = document.querySelector('[data-list-status]');
    if (search) search.value = giState.q;
    if (status) status.value = giState.status;
}

async function openGoodsIssueModal() {
    const [orders, warehouses] = await Promise.all([
        VKApi.request('/sales-orders'),
        VKApi.request('/warehouses'),
    ]);
    const reservedOrders = orders.data.filter(row => row.stock_status === 'reserved');
    if (!reservedOrders.length) return VKModal.toast('Chưa có đơn bán đã giữ hàng để xuất kho.');
    if (!warehouses.data.length) return VKModal.toast('Chưa có kho xuất hàng.');

    VKModal.open('Tạo phiếu xuất kho', `
        <div class="form-grid">
            ${VKModal.select('sales_order_id', 'Đơn bán đã giữ hàng', reservedOrders.map(row => ({ value: row.id, label: `${row.code} - ${row.customer?.name || ''}` })))}
            ${VKModal.select('warehouse_id', 'Kho xuất', warehouses.data.map(row => ({ value: row.id, label: `${row.code} - ${row.name}` })))}
        </div>
    `, async (form) => {
        const data = Object.fromEntries(new FormData(form));
        await VKApi.request('/goods-issues', {
            method: 'POST',
            body: JSON.stringify({ sales_order_id: Number(data.sales_order_id), warehouse_id: Number(data.warehouse_id) }),
        });
        VKModal.toast('Đã tạo phiếu xuất.');
        VKModal.close();
        loadGoodsIssues();
    });
}

function renderItems(items = []) {
    if (!items.length) return '-';
    return items.slice(0, 2).map(item => `${VKTable.escapeHtml(item.sku?.sku_code || '-')}: ${VKTable.money(item.quantity)}`).join('<br>');
}

async function confirmGoodsIssue(id) {
    await VKApi.request(`/goods-issues/${id}/confirm`, { method: 'POST' });
    VKModal.toast('Đã xác nhận xuất kho.');
    loadGoodsIssues();
}

function openGoodsIssueDetail(id) {
    VKDetailDrawer.open({ type: 'goodsIssue', path: `/goods-issues/${id}` });
}
