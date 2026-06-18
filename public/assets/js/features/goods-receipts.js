(function () {
let grState = { rows: [], q: '', status: '', view: 'list', detailCache: {} };

document.addEventListener('vk:ready', () => loadGoodsReceipts());
document.addEventListener('vk:flow-updated', () => loadGoodsReceipts());
document.addEventListener('input', (event) => {
    if (!document.getElementById('goodsReceiptsRoot')) return;
    if (event.target.matches('[data-list-search]')) {
        grState.q = event.target.value.toLowerCase();
        renderGoodsReceipts();
    }
});
document.addEventListener('change', (event) => {
    if (!document.getElementById('goodsReceiptsRoot')) return;
    if (event.target.matches('[data-list-status]')) {
        grState.status = event.target.value;
        renderGoodsReceipts();
    }
});
document.addEventListener('click', (event) => {
    if (!document.getElementById('goodsReceiptsRoot')) return;
    if (event.target.matches('[data-create-goods-receipt]')) openGoodsReceiptModal();
    if (event.target.matches('[data-confirm-goods-receipt]')) {
        event.stopPropagation();
        confirmGoodsReceipt(event.target.dataset.confirmGoodsReceipt);
    }
    if (event.target.closest('.row-action-menu')) return;
    const detail = event.target.closest('[data-goods-receipt-detail], tr[data-row-detail]');
    if (detail) openGoodsReceiptDetail(detail.dataset.goodsReceiptDetail || detail.dataset.rowDetail);
});

async function loadGoodsReceipts(options = {}) {
    if (!document.getElementById('goodsReceiptsRoot')) return;
    if (options.source === 'pjax') {
        grState.view = 'list';
        grState.currentId = null;
    }
    const receipts = await VKApi.request('/goods-receipts');
    grState.rows = receipts.data || [];
    if (grState.view === 'detail' && grState.currentId) {
        openGoodsReceiptDetail(grState.currentId);
        return;
    }
    renderGoodsReceipts(receipts.meta.total);
}

function renderGoodsReceipts(total = grState.rows.length) {
    const rows = filterRows();
    document.getElementById('goodsReceiptsRoot').innerHTML = VKTable.fullList({
        title: 'Danh sách phiếu nhập',
        subtitle: 'Xác nhận nhập kho, cập nhật tồn và hoàn tất nhận hàng từ đơn mua.',
        meta: `${VKTable.money(rows.length)} / ${VKTable.money(total)} phiếu`,
        filters: VKTable.filterBar({
            searchPlaceholder: 'Tìm mã phiếu, đơn mua hoặc kho...',
            status: [
                { value: 'draft', label: 'Nháp' },
                { value: 'confirmed', label: 'Đã xác nhận' },
            ],
        }),
        table: renderReceiptTable(rows),
    });
    restoreFilters();
}

function renderReceiptTable(rows) {
    return VKTable.renderTable([
        { label: 'Mã phiếu', render: row => `<span class="mono">${VKTable.escapeHtml(row.code)}</span>` },
        { label: 'Đơn mua', render: row => `<span class="mono">${VKTable.escapeHtml(row.purchase_order?.code || '-')}</span>` },
        { label: 'Kho nhận', render: row => VKTable.escapeHtml(row.warehouse?.name || '-') },
        { label: 'Hàng nhập', render: row => renderItems(row.items) },
        { label: 'Trạng thái', render: row => VKTable.statusBadge(row.status) },
        { label: '', render: row => VKTable.rowActions([
            VKTable.smallButton('Mở', `data-goods-receipt-detail="${row.id}"`),
            row.status === 'draft' ? VKTable.smallButton('Xác nhận', `data-confirm-goods-receipt="${row.id}"`, 'primary') : '',
        ]) },
    ], rows, 'Chưa có phiếu nhập', { rowAttr: row => `data-row-detail="${row.id}"` });
}

function filterRows() {
    return grState.rows.filter(row => {
        const haystack = `${row.code || ''} ${row.purchase_order?.code || ''} ${row.warehouse?.name || ''}`.toLowerCase();
        return (!grState.q || haystack.includes(grState.q)) && (!grState.status || row.status === grState.status);
    });
}

function restoreFilters() {
    const search = document.querySelector('[data-list-search]');
    const status = document.querySelector('[data-list-status]');
    if (search) search.value = grState.q;
    if (status) status.value = grState.status;
}

async function openGoodsReceiptModal() {
    const [orders, warehouses] = await Promise.all([
        VKApi.request('/purchase-orders?status=approved'),
        VKApi.request('/warehouses'),
    ]);
    if (!orders.data.length) return VKModal.toast('Chưa có đơn mua đã duyệt để nhập kho.');
    if (!warehouses.data.length) return VKModal.toast('Chưa có kho nhận hàng.');

    VKModal.open('Tạo phiếu nhập kho', `
        <div class="form-grid">
            ${VKModal.select('purchase_order_id', 'Đơn mua', orders.data.map(row => ({ value: row.id, label: `${row.code} - ${VKTable.money(row.total_amount)}` })))}
            ${VKModal.select('warehouse_id', 'Kho nhận', warehouses.data.map(row => ({ value: row.id, label: `${row.code} - ${row.name}` })))}
        </div>
    `, async (form) => {
        const data = Object.fromEntries(new FormData(form));
        await VKApi.request('/goods-receipts', {
            method: 'POST',
            body: JSON.stringify({ purchase_order_id: Number(data.purchase_order_id), warehouse_id: Number(data.warehouse_id) }),
        });
        VKModal.toast('Đã tạo phiếu nhập.');
        VKModal.close();
        loadGoodsReceipts();
    });
}

function renderItems(items = []) {
    if (!items.length) return '-';
    return items.slice(0, 2).map(item => `${VKTable.escapeHtml(item.sku?.sku_code || '-')}: ${VKTable.money(item.quantity)}`).join('<br>');
}

async function confirmGoodsReceipt(id) {
    try {
        const response = await VKApi.request(`/goods-receipts/${id}/confirm`, { method: 'POST' });
        const receipt = response.data || {};
        grState.detailCache[String(id)] = null;
        grState.rows = grState.rows.map(row => String(row.id) === String(id) ? { ...row, ...receipt } : row);

        VKModal.notice({
            type: 'success',
            title: 'Nhập kho thành công',
            message: 'Đã xác nhận nhập kho và cập nhật tồn kho.',
            details: [`Phiếu nhập: ${receipt.code || ''}`, receipt.purchase_order?.code ? `Đơn mua: ${receipt.purchase_order.code}` : 'Tồn kho liên quan đã được cập nhật.'],
        });

        if (grState.view === 'detail' && String(grState.currentId) === String(id)) {
            openGoodsReceiptDetail(id);
            return;
        }
        loadGoodsReceipts();
    } catch (error) {
        VKModal.notice({
            type: 'danger',
            title: 'Không thể xác nhận nhập kho',
            message: error.message || 'Có lỗi xảy ra khi xác nhận phiếu nhập.',
        });
    }
}

function openGoodsReceiptDetail(id) {
    grState.view = 'detail';
    grState.currentId = id;
    VKRecordPage.open({
        root: '#goodsReceiptsRoot',
        type: 'goodsReceipt',
        id,
        path: `/goods-receipts/${id}`,
        preview: grState.rows.find(row => String(row.id) === String(id)),
        cache: grState.detailCache,
        onBack: () => {
            grState.view = 'list';
            grState.currentId = null;
            renderGoodsReceipts();
        },
        actions: row => row.status === 'draft'
            ? `<button class="btn primary small" type="button" data-confirm-goods-receipt="${row.id}">Xác nhận nhập kho</button>`
            : '',
    });
}

window.loadGoodsReceipts = loadGoodsReceipts;
})();
