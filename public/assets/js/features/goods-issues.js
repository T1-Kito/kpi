(function () {
let giState = { rows: [], q: '', status: '', view: 'list', detailCache: {} };

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

async function loadGoodsIssues(options = {}) {
    if (!document.getElementById('goodsIssuesRoot')) return;
    if (options.source === 'pjax') {
        giState.view = 'list';
        giState.currentId = null;
    }
    const issues = await VKApi.request('/goods-issues');
    giState.rows = issues.data || [];
    if (giState.view === 'detail' && giState.currentId) {
        openGoodsIssueDetail(giState.currentId);
        return;
    }
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
        { label: 'Đơn tạo lúc', render: row => formatDateTime(row.sales_order?.created_at) },
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
        const haystack = `${row.code || ''} ${row.sales_order?.code || ''} ${row.sales_order?.customer?.name || ''} ${row.warehouse?.name || ''}`.toLowerCase();
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
    const activeIssueOrderIds = new Set(giState.rows
        .filter(issue => ['draft', 'confirmed'].includes(issue.status))
        .map(issue => String(issue.sales_order_id)));
    const reservedOrders = orders.data
        .filter(row => row.stock_status === 'reserved' && !activeIssueOrderIds.has(String(row.id)))
        .sort((a, b) => new Date(b.created_at || 0) - new Date(a.created_at || 0));
    if (!reservedOrders.length) return VKModal.toast('Chưa có đơn bán đã giữ hàng để xuất kho.');
    if (!warehouses.data.length) return VKModal.toast('Chưa có kho xuất hàng.');

    const newestOrderId = reservedOrders[0]?.id;
    VKModal.open('Tạo phiếu xuất kho', `
        <div class="form-grid goods-issue-create-grid">
            ${VKModal.select('sales_order_id', 'Đơn bán đã giữ hàng', reservedOrders.map(row => ({ value: row.id, label: goodsIssueOrderLabel(row, row.id === newestOrderId) })))}
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
    }, { className: 'goods-issue-create-modal' });
}

function renderItems(items = []) {
    if (!items.length) return '-';
    return items.slice(0, 2).map(item => `${VKTable.escapeHtml(item.sku?.sku_code || '-')}: ${VKTable.money(item.quantity)}`).join('<br>');
}

function goodsIssueOrderLabel(row, newest = false) {
    const customer = row.customer?.name || 'Chưa có khách hàng';
    const createdAt = formatDateTime(row.created_at);
    const items = renderOrderItemsText(row.items || []);
    const amount = VKTable.money(row.total_amount || 0);
    return `${newest ? '[Mới nhất] ' : ''}${row.code} - ${customer} - Tạo ${createdAt} - ${amount}${items ? ` - ${items}` : ''}`;
}

function renderOrderItemsText(items = []) {
    return items.slice(0, 2)
        .map(item => `${item.sku?.sku_code || '-'}:${VKTable.money(item.quantity)}`)
        .join(', ');
}

function formatDateTime(value) {
    if (!value) return '-';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return '-';
    return date.toLocaleString('vi-VN', { hour: '2-digit', minute: '2-digit', day: '2-digit', month: '2-digit', year: 'numeric' });
}

async function confirmGoodsIssue(id) {
    try {
        const response = await VKApi.request(`/goods-issues/${id}/confirm`, { method: 'POST' });
        const issue = response.data || {};
        giState.detailCache[String(id)] = null;
        giState.rows = giState.rows.map(row => String(row.id) === String(id) ? { ...row, ...issue } : row);

        VKModal.notice({
            type: 'success',
            title: 'Xuất kho thành công',
            message: 'Đã xác nhận xuất kho và chuyển đơn bán sang bước chờ giao hàng.',
            details: [`Phiếu xuất: ${issue.code || ''}`, 'Bước tiếp theo: xác nhận khách đã nhận hàng tại chi tiết đơn bán.'],
        });

        if (giState.view === 'detail' && String(giState.currentId) === String(id)) {
            openGoodsIssueDetail(id);
            return;
        }
        loadGoodsIssues();
    } catch (error) {
        VKModal.notice({
            type: 'danger',
            title: 'Không thể xác nhận xuất kho',
            message: error.message || 'Có lỗi xảy ra khi xác nhận phiếu xuất.',
        });
    }
}

function openGoodsIssueDetail(id) {
    giState.view = 'detail';
    giState.currentId = id;
    VKRecordPage.open({
        root: '#goodsIssuesRoot',
        type: 'goodsIssue',
        id,
        path: `/goods-issues/${id}`,
        preview: giState.rows.find(row => String(row.id) === String(id)),
        cache: giState.detailCache,
        onBack: () => {
            giState.view = 'list';
            giState.currentId = null;
            renderGoodsIssues();
        },
        actions: row => row.status === 'draft'
            ? `<button class="btn primary small" type="button" data-confirm-goods-issue="${row.id}">Xác nhận xuất kho</button>`
            : '',
    });
}

window.loadGoodsIssues = loadGoodsIssues;
})();
