let poState = { rows: [], q: '', status: '' };

document.addEventListener('vk:ready', () => loadPurchaseOrders());
document.addEventListener('vk:flow-updated', () => loadPurchaseOrders());
document.addEventListener('input', (event) => {
    if (!document.getElementById('purchaseOrdersRoot')) return;
    if (event.target.matches('[data-list-search]')) {
        poState.q = event.target.value.toLowerCase();
        renderPurchaseOrders();
    }
});
document.addEventListener('change', (event) => {
    if (!document.getElementById('purchaseOrdersRoot')) return;
    if (event.target.matches('[data-list-status]')) {
        poState.status = event.target.value;
        renderPurchaseOrders();
    }
});
document.addEventListener('click', (event) => {
    if (!document.getElementById('purchaseOrdersRoot')) return;
    if (event.target.matches('[data-create-purchase-order]')) openPurchaseOrderModal();
    if (event.target.matches('[data-approve-purchase-order]')) {
        event.stopPropagation();
        approvePurchaseOrder(event.target.dataset.approvePurchaseOrder);
    }
    if (event.target.closest('.row-action-menu')) return;
    const detail = event.target.closest('[data-purchase-order-detail], tr[data-row-detail]');
    if (detail) openPurchaseOrderDetail(detail.dataset.purchaseOrderDetail || detail.dataset.rowDetail);
});

async function loadPurchaseOrders() {
    if (!document.getElementById('purchaseOrdersRoot')) return;
    const orders = await VKApi.request('/purchase-orders');
    poState.rows = orders.data || [];
    renderPurchaseOrders(orders.meta.total);
}

function renderPurchaseOrders(total = poState.rows.length) {
    const rows = filterRows();
    document.getElementById('purchaseOrdersRoot').innerHTML = VKTable.fullList({
        title: 'Danh sách đơn mua',
        subtitle: 'Theo dõi nhà cung cấp, ngày dự kiến nhận và trạng thái nhập kho.',
        meta: `${VKTable.money(rows.length)} / ${VKTable.money(total)} đơn`,
        filters: VKTable.filterBar({
            searchPlaceholder: 'Tìm mã đơn, yêu cầu mua hoặc nhà cung cấp...',
            status: [
                { value: 'draft', label: 'Nháp' },
                { value: 'approved', label: 'Đã duyệt' },
                { value: 'received', label: 'Đã nhập kho' },
            ],
        }),
        table: renderPurchaseOrderTable(rows),
    });
    restoreFilters();
}

function renderPurchaseOrderTable(rows) {
    return VKTable.renderTable([
        { label: 'Mã đơn', render: row => `<span class="mono">${VKTable.escapeHtml(row.code)}</span>` },
        { label: 'Yêu cầu mua', render: row => `<span class="mono">${VKTable.escapeHtml(row.purchase_request?.code || '-')}</span>` },
        { label: 'Nhà cung cấp', render: row => renderSupplier(row) },
        { label: 'Tổng tiền', render: row => VKTable.money(row.total_amount) },
        { label: 'Trạng thái', render: row => VKTable.statusBadge(row.status) },
        { label: '', render: row => VKTable.rowActions([
            VKTable.smallButton('Mở', `data-purchase-order-detail="${row.id}"`),
            row.status === 'draft' ? VKTable.smallButton('Duyệt', `data-approve-purchase-order="${row.id}"`, 'primary') : '',
        ]) },
    ], rows, 'Chưa có đơn mua', { rowAttr: row => `data-row-detail="${row.id}"` });
}

function filterRows() {
    return poState.rows.filter(row => {
        const haystack = `${row.code || ''} ${row.purchase_request?.code || ''} ${row.supplier?.name || ''}`.toLowerCase();
        return (!poState.q || haystack.includes(poState.q)) && (!poState.status || row.status === poState.status);
    });
}

function restoreFilters() {
    const search = document.querySelector('[data-list-search]');
    const status = document.querySelector('[data-list-status]');
    if (search) search.value = poState.q;
    if (status) status.value = poState.status;
}

function renderSupplier(row) {
    return `${VKTable.escapeHtml(row.supplier?.name || '-')}<span class="row-note">${row.expected_delivery_date ? `Dự kiến: ${formatDate(row.expected_delivery_date)}` : 'Chưa có ngày dự kiến'}</span>`;
}

async function openPurchaseOrderModal() {
    const [requests, suppliers] = await Promise.all([
        VKApi.request('/purchase-requests?status=approved'),
        VKApi.request('/suppliers'),
    ]);

    if (!requests.data.length) return VKModal.toast('Chưa có yêu cầu mua đã duyệt để tạo đơn mua.');
    if (!suppliers.data.length) return VKModal.toast('Chưa có nhà cung cấp để tạo đơn mua.');

    VKModal.open('Tạo đơn mua từ yêu cầu', `
        <div class="form-grid">
            ${VKModal.select('purchase_request_id', 'Yêu cầu mua', requests.data.map(row => ({ value: row.id, label: `${row.code} - ${VKTable.translateStatus(row.status)}` })))}
            ${VKModal.select('supplier_id', 'Nhà cung cấp', suppliers.data.map(row => ({ value: row.id, label: `${row.code} - ${row.name}` })))}
            ${VKModal.field('expected_delivery_date', 'Ngày dự kiến nhận', 'date')}
        </div>
    `, async (form) => {
        const data = Object.fromEntries(new FormData(form));
        await VKApi.request('/purchase-orders', {
            method: 'POST',
            body: JSON.stringify({
                purchase_request_id: Number(data.purchase_request_id),
                supplier_id: Number(data.supplier_id),
                expected_delivery_date: data.expected_delivery_date || null,
            }),
        });
        VKModal.toast('Đã tạo đơn mua.');
        VKModal.close();
        loadPurchaseOrders();
    });
}

async function approvePurchaseOrder(id) {
    await VKApi.request(`/purchase-orders/${id}/approve`, { method: 'POST', body: JSON.stringify({ reason: 'Duyệt từ màn hình đơn mua' }) });
    VKModal.toast('Đã duyệt đơn mua.');
    loadPurchaseOrders();
}

function openPurchaseOrderDetail(id) {
    VKDetailDrawer.open({ type: 'purchaseOrder', path: `/purchase-orders/${id}` });
}

function formatDate(value) {
    return value ? new Date(value).toLocaleDateString('vi-VN') : '-';
}
