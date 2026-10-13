(function () {
let poState = { rows: [], q: '', status: '', view: 'list', detailCache: {} };

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
    const configure = event.target.closest('[data-configure-po-approval]');
    if (configure) { configurePurchaseApproval(configure.dataset.configurePoApproval); return; }
    const approve = event.target.closest('[data-approve-purchase-order]');
    if (approve) {
        event.stopPropagation();
        approvePurchaseOrder(approve.dataset.approvePurchaseOrder, approve);
        return;
    }
    if (event.target.closest('.row-action-menu') && !event.target.closest('[data-purchase-order-detail]')) return;
    const detail = event.target.closest('[data-purchase-order-detail], tr[data-row-detail]');
    if (detail) openPurchaseOrderDetail(detail.dataset.purchaseOrderDetail || detail.dataset.rowDetail);
});

async function loadPurchaseOrders(options = {}) {
    const root = document.getElementById('purchaseOrdersRoot');
    if (!root) return;
    if (options.source === 'pjax') {
        poState.view = 'list';
        poState.currentId = null;
        if (poState.rows.length) renderPurchaseOrders();
    }
    const orders = await VKApi.request('/purchase-orders');
    if (document.getElementById('purchaseOrdersRoot') !== root) return;
    poState.rows = orders.data || [];
    if (poState.view === 'detail' && poState.currentId) {
        openPurchaseOrderDetail(poState.currentId);
        return;
    }
    const requestedOrder = new URLSearchParams(window.location.search).get('order');
    if (requestedOrder) {
        openPurchaseOrderDetail(requestedOrder);
        return;
    }
    renderPurchaseOrders(orders.meta.total);
}

function renderPurchaseOrders(total = poState.rows.length) {
    const rows = filterRows();
    document.getElementById('purchaseOrdersRoot').innerHTML = VKTable.fullList({
        title: 'Danh sách đơn mua',
        subtitle: 'Đơn tự sinh từ báo giá NCC đã chọn. Duyệt đủ cấp sẽ tự tạo phiếu nhập chờ kho xác nhận.',
        meta: `${VKTable.money(rows.length)} / ${VKTable.money(total)} đơn`,
        filters: VKTable.filterBar({
            searchPlaceholder: 'Tìm mã đơn, báo giá NCC, yêu cầu mua hoặc nhà cung cấp...',
            status: [
                { value: 'draft', label: 'Nháp' },
                { value: 'approved', label: 'Chờ nhập kho' },
                { value: 'received', label: 'Đã nhập kho' },
            ],
        }),
        table: renderPurchaseOrderTable(rows),
    });
    restoreFilters();
}

function renderPurchaseOrderTable(rows) {
    return VKTable.renderTable([
        { label: 'Mã đơn', render: row => `<span class="record-code-chip">${VKTable.escapeHtml(row.code)}</span>` },
        { label: 'Báo giá NCC', render: row => row.supplier_quotation ? `<span class="mono">${VKTable.escapeHtml(row.supplier_quotation.code)}</span>` : '<span class="muted">Tạo thủ công</span>' },
        { label: 'Yêu cầu mua', render: row => `<span class="mono">${VKTable.escapeHtml(row.purchase_request?.code || '-')}</span>` },
        { label: 'Nhà cung cấp', render: row => renderSupplier(row) },
        { label: 'Tổng tiền', render: row => VKTable.money(row.total_amount) },
        { label: 'Trạng thái', render: row => renderReceivingStatus(row) },
        { label: '', render: row => VKTable.rowActions([
            VKTable.smallButton('Mở', `data-purchase-order-detail="${row.id}"`),
            row.can_approve ? VKTable.smallButton('Duyệt', `data-approve-purchase-order="${row.id}"`, 'primary') : '',
            row.goods_receipt ? VKTable.smallButton(row.goods_receipt.status === 'confirmed' ? 'Xem nhập kho' : 'Nhập kho', `onclick="location.href='/goods-receipts?receipt=${row.goods_receipt.id}'"`, row.goods_receipt.status === 'draft' ? 'primary' : '') : '',
        ]) },
    ], rows, 'Chưa có đơn mua', { rowAttr: row => `data-row-detail="${row.id}"` });
}

function filterRows() {
    return poState.rows.filter(row => {
        const haystack = `${row.code || ''} ${row.purchase_request?.code || ''} ${row.supplier_quotation?.code || ''} ${row.supplier?.name || ''}`.toLowerCase();
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

async function approvePurchaseOrder(id, button) {
    if (button?.disabled) return;
    const originalText = button?.textContent;
    if (button) { button.disabled = true; button.textContent = 'Đang duyệt…'; }
    try {
        await VKApi.request(`/purchase-orders/${id}/approve`, { method: 'POST', body: JSON.stringify({ reason: 'Duyệt từ màn hình đơn mua' }) });
        delete poState.detailCache[id];
        VKModal.toast('Đã ghi nhận lượt duyệt của bạn.');
        await loadPurchaseOrders();
    } catch (error) {
        VKModal.toast(error.message || 'Không thể duyệt đơn mua. Vui lòng thử lại.', 'danger');
    } finally {
        if (button) { button.disabled = false; button.textContent = originalText; }
    }
}

function openPurchaseOrderDetail(id) {
    poState.view = 'detail';
    poState.currentId = id;
    VKRecordPage.open({
        root: '#purchaseOrdersRoot',
        type: 'purchaseOrder',
        id,
        path: `/purchase-orders/${id}`,
        preview: poState.rows.find(row => String(row.id) === String(id)),
        cache: poState.detailCache,
        onBack: () => {
            poState.view = 'list';
            poState.currentId = null;
            renderPurchaseOrders();
        },
        actions: row => row.can_approve
            ? `<button class="btn primary small" type="button" data-approve-purchase-order="${row.id}">Duyệt đơn mua</button>`
            : row.goods_receipt ? `<a class="btn ${row.goods_receipt.status === 'draft' ? 'primary' : 'secondary'} small" href="/goods-receipts?receipt=${row.goods_receipt.id}">${row.goods_receipt.status === 'draft' ? 'Nhập kho' : 'Xem phiếu nhập'}</a>` : '',
    });
}

async function configurePurchaseApproval(id) {
    const response = await VKApi.request(`/purchase-orders/${id}`);
    const row = response.data;
    const candidates = row.approval_candidates || [];
    const options = [{value:'', label:'Chọn người duyệt'}, ...candidates.map(user => ({value:user.id, label:user.name}))];
    const selected = row.approval_flow || [];
    VKModal.open('Thiết lập người duyệt đơn mua', `<p class="form-note">Duyệt lần lượt theo thứ tự danh sách. Mỗi người chỉ duyệt khi cấp trước đã hoàn tất; người cuối hoàn tất đơn mua. Chỉ hiển thị nhân sự có quyền duyệt đơn mua.</p><div class="form-grid two">${[0,1,2,3].map(index => VKModal.select(`approver_${index}`, index === 3 ? 'Người duyệt cuối *' : `Người duyệt cấp ${index + 1}${index ? ' (tùy chọn)' : ' *'}`, options, index === 3 ? selected.find(step => step.final)?.user_id || '' : selected.filter(step => !step.final)[index]?.user_id || '')).join('')}</div>`, async form => {
        const values = Object.fromEntries(new FormData(form));
        if (!values.approver_0 || !values.approver_3) throw new Error('Chọn ít nhất một người nội bộ và người duyệt cuối.');
        await VKApi.request(`/purchase-orders/${id}/approval-flow`, {method:'POST',body:JSON.stringify({approvers:[0,1,2,3].map(index => values[`approver_${index}`]).filter(Boolean).map(Number)})});
        delete poState.detailCache[id];
        openPurchaseOrderDetail(id);
        VKModal.toast('Đã thiết lập luồng duyệt cho đơn mua.');
    }, {className:'modal-wide',submitText:'Lưu luồng duyệt'});
}

window.loadPurchaseOrders = loadPurchaseOrders;

function formatDate(value) {
    return value ? new Date(value).toLocaleDateString('vi-VN') : '-';
}

function renderReceivingStatus(row) {
    if (row.status === 'received' || row.goods_receipt?.status === 'confirmed') return '<span class="badge success">Đã nhập kho</span>';
    if (row.status === 'approved') return '<span class="badge warning">Chờ nhập kho</span>';
    return VKTable.statusBadge(row.status);
}
})();
