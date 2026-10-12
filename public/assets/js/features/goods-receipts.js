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
    if (event.target.matches('[data-confirm-goods-receipt]')) {
        event.stopPropagation();
        confirmGoodsReceipt(event.target.dataset.confirmGoodsReceipt);
    }
    if (event.target.closest('.row-action-menu') && !event.target.closest('[data-goods-receipt-detail]')) return;
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
    const requestedReceipt = new URLSearchParams(window.location.search).get('receipt');
    if (requestedReceipt) { openGoodsReceiptDetail(requestedReceipt); return; }
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
        subtitle: 'Phiếu tự sinh từ đơn mua đã duyệt. Kho kiểm tra hàng thực nhận rồi xác nhận để cập nhật tồn.',
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
        { label: 'Tiến độ duyệt', render: row => (row.approval_flow || []).map((step, index) => `<span class="badge ${step.decided_at ? 'success' : 'info'}" title="${VKTable.escapeHtml(`Cấp ${index + 1}: ${step.name}`)}">${step.decided_at ? '✓' : '○'} ${index + 1}</span>`).join(' ') || 'Chưa cấu hình' },
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
            title: receipt.status === 'confirmed' ? 'Nhập kho thành công' : 'Đã ghi nhận lượt duyệt',
            message: receipt.status === 'confirmed' ? 'Đã duyệt đủ các cấp và cập nhật tồn kho.' : 'Đang chờ cấp duyệt tiếp theo. Chưa cộng tồn kho.',
            details: [`Phiếu nhập: ${receipt.code || ''}`, ...(receipt.approval_flow || []).map(step => `${step.decided_at ? '✓' : '○'} ${step.name}`)],
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
            ? `<button class="btn primary small" type="button" data-confirm-goods-receipt="${row.id}">Duyệt nhập kho</button>`
            : '',
    });
}

window.loadGoodsReceipts = loadGoodsReceipts;
})();
