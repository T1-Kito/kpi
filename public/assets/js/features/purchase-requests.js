let prState = { rows: [], q: '', status: '' };

document.addEventListener('vk:ready', () => loadPurchaseRequests());
document.addEventListener('vk:flow-updated', () => loadPurchaseRequests());
document.addEventListener('input', (event) => {
    if (!document.getElementById('purchaseRequestsRoot')) return;
    if (event.target.matches('[data-list-search]')) {
        prState.q = event.target.value.toLowerCase();
        renderPurchaseRequests();
    }
});
document.addEventListener('change', (event) => {
    if (!document.getElementById('purchaseRequestsRoot')) return;
    if (event.target.matches('[data-list-status]')) {
        prState.status = event.target.value;
        renderPurchaseRequests();
    }
});
document.addEventListener('click', (event) => {
    if (!document.getElementById('purchaseRequestsRoot')) return;
    if (event.target.matches('[data-approve-purchase-request]')) {
        event.stopPropagation();
        approvePurchaseRequest(event.target.dataset.approvePurchaseRequest);
    }
    if (event.target.closest('.row-action-menu')) return;
    const detail = event.target.closest('[data-purchase-request-detail], tr[data-row-detail]');
    if (detail) openPurchaseRequestDetail(detail.dataset.purchaseRequestDetail || detail.dataset.rowDetail);
});

async function loadPurchaseRequests() {
    if (!document.getElementById('purchaseRequestsRoot')) return;
    const requests = await VKApi.request('/purchase-requests');
    prState.rows = requests.data || [];
    renderPurchaseRequests(requests.meta.total);
}

function renderPurchaseRequests(total = prState.rows.length) {
    const rows = filterRows();
    document.getElementById('purchaseRequestsRoot').innerHTML = VKTable.fullList({
        title: 'Danh sách yêu cầu mua',
        subtitle: 'Theo dõi nhu cầu mua phát sinh từ thiếu tồn hoặc yêu cầu nội bộ.',
        meta: `${VKTable.money(rows.length)} / ${VKTable.money(total)} yêu cầu`,
        filters: VKTable.filterBar({
            searchPlaceholder: 'Tìm mã yêu cầu hoặc nguồn...',
            status: [
                { value: 'draft', label: 'Nháp' },
                { value: 'approved', label: 'Đã duyệt' },
            ],
        }),
        table: renderPurchaseRequestTable(rows),
    });
    restoreFilters();
}

function renderPurchaseRequestTable(rows) {
    return VKTable.renderTable([
        { label: 'Mã yêu cầu', render: row => `<span class="mono">${VKTable.escapeHtml(row.code)}</span>` },
        { label: 'Nguồn', render: row => renderSource(row) },
        { label: 'Số dòng', render: row => String(row.items?.length || 0) },
        { label: 'Nhu cầu', render: row => renderItems(row.items) },
        { label: 'Trạng thái', render: row => VKTable.statusBadge(row.status) },
        { label: '', render: row => VKTable.rowActions([
            VKTable.smallButton('Mở', `data-purchase-request-detail="${row.id}"`),
            row.status === 'draft' ? VKTable.smallButton('Duyệt', `data-approve-purchase-request="${row.id}"`, 'primary') : '',
        ]) },
    ], rows, 'Chưa có yêu cầu mua', { rowAttr: row => `data-row-detail="${row.id}"` });
}

function filterRows() {
    return prState.rows.filter(row => {
        const haystack = `${row.code || ''} ${row.source_type || ''} ${row.reason || ''}`.toLowerCase();
        return (!prState.q || haystack.includes(prState.q)) && (!prState.status || row.status === prState.status);
    });
}

function restoreFilters() {
    const search = document.querySelector('[data-list-search]');
    const status = document.querySelector('[data-list-status]');
    if (search) search.value = prState.q;
    if (status) status.value = prState.status;
}

function renderSource(row) {
    const source = row.source_type === 'SalesOrder'
        ? 'Đơn bán thiếu tồn'
        : VKTable.translateEntity(row.source_type || 'System');
    return `${VKTable.escapeHtml(source)}<span class="row-note">Tham chiếu: ${VKTable.escapeHtml(row.source_id || '-')}</span>`;
}

function renderItems(items = []) {
    if (!items.length) return '-';
    return items.slice(0, 2).map(item => `${VKTable.escapeHtml(item.sku?.sku_code || '-')}: ${VKTable.money(item.quantity)}`).join('<br>');
}

async function approvePurchaseRequest(id) {
    await VKApi.request(`/purchase-requests/${id}/approve`, { method: 'POST', body: JSON.stringify({ reason: 'Duyệt từ màn hình mua hàng' }) });
    VKModal.toast('Đã duyệt yêu cầu mua.');
    loadPurchaseRequests();
}

function openPurchaseRequestDetail(id) {
    VKDetailDrawer.open({ type: 'purchaseRequest', path: `/purchase-requests/${id}` });
}
