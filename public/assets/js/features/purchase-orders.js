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
    if (event.target.matches('[name="supplier_quotation_id"]')) {
        toggleManualPurchaseFields(event.target.value);
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
    renderPurchaseOrders(orders.meta.total);
}

function renderPurchaseOrders(total = poState.rows.length) {
    const rows = filterRows();
    document.getElementById('purchaseOrdersRoot').innerHTML = VKTable.fullList({
        title: 'Danh sách đơn mua',
        subtitle: 'Theo dõi nhà cung cấp, báo giá nguồn, ngày dự kiến nhận và trạng thái nhập kho.',
        meta: `${VKTable.money(rows.length)} / ${VKTable.money(total)} đơn`,
        filters: VKTable.filterBar({
            searchPlaceholder: 'Tìm mã đơn, báo giá NCC, yêu cầu mua hoặc nhà cung cấp...',
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
        { label: 'Mã đơn', render: row => `<span class="record-code-chip">${VKTable.escapeHtml(row.code)}</span>` },
        { label: 'Báo giá NCC', render: row => row.supplier_quotation ? `<span class="mono">${VKTable.escapeHtml(row.supplier_quotation.code)}</span>` : '<span class="muted">Tạo thủ công</span>' },
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

async function openPurchaseOrderModal() {
    const [quotes, requests, suppliers] = await Promise.all([
        VKApi.request('/supplier-quotations?status=selected&page_size=100'),
        VKApi.request('/purchase-requests?status=approved&page_size=100'),
        VKApi.request('/suppliers?page_size=100'),
    ]);

    const quoteOptions = [{ value: '', label: 'Không dùng báo giá NCC - tạo thủ công' }].concat((quotes.data || []).map(row => ({
        value: row.id,
        label: `${row.code} - ${row.supplier?.name || '-'} - ${VKTable.money(row.total_amount)} đ`,
    })));

    if (!quoteOptions.length && (!requests.data?.length || !suppliers.data?.length)) {
        return VKModal.toast('Chưa có báo giá NCC đã chọn hoặc yêu cầu mua đã duyệt để tạo đơn mua.', 'warning');
    }

    VKModal.open('Tạo đơn mua', `
        <div class="form-grid">
            ${VKModal.select('supplier_quotation_id', 'Báo giá NCC đã chọn', quoteOptions, quoteOptions[1]?.value || '')}
            ${VKModal.field('expected_delivery_date', 'Ngày dự kiến nhận', 'date')}
            ${VKModal.field('payment_terms', 'Điều khoản thanh toán', 'text')}
            ${VKModal.field('delivery_terms', 'Điều kiện giao hàng', 'text')}
            ${VKModal.field('warranty_terms', 'Bảo hành', 'text')}
            ${VKModal.field('shipping_fee', 'Phí vận chuyển', 'number', '0')}
        </div>
        <p class="form-note" data-quote-note>Hệ thống sẽ lấy nhà cung cấp, dòng hàng và đơn giá từ báo giá NCC đã chọn.</p>
        <div data-manual-purchase-fields>
            <div class="form-grid two">
                ${VKModal.select('purchase_request_id', 'Yêu cầu mua', (requests.data || []).map(row => ({ value: row.id, label: `${row.code} - ${VKTable.translateStatus(row.status)}` })))}
                ${VKModal.select('supplier_id', 'Nhà cung cấp', (suppliers.data || []).map(row => ({ value: row.id, label: `${row.code} - ${row.name}` })))}
            </div>
            <p class="form-note">Chỉ dùng phần này khi chưa có báo giá NCC đã chọn.</p>
        </div>
    `, async (form) => {
        const data = Object.fromEntries(new FormData(form));
        const payload = {
            expected_delivery_date: data.expected_delivery_date || null,
            payment_terms: data.payment_terms || null,
            delivery_terms: data.delivery_terms || null,
            warranty_terms: data.warranty_terms || null,
            shipping_fee: Number(data.shipping_fee || 0),
        };
        if (data.supplier_quotation_id) {
            payload.supplier_quotation_id = Number(data.supplier_quotation_id);
        } else {
            payload.purchase_request_id = Number(data.purchase_request_id);
            payload.supplier_id = Number(data.supplier_id);
        }
        await VKApi.request('/purchase-orders', {
            method: 'POST',
            body: JSON.stringify(payload),
        });
        VKModal.toast('Đã tạo đơn mua.');
        VKModal.close();
        loadPurchaseOrders();
    }, { className: 'modal-wide', submitText: 'Tạo đơn mua' });

    toggleManualPurchaseFields(document.querySelector('[name="supplier_quotation_id"]')?.value || '');
}

function toggleManualPurchaseFields(quoteId) {
    const fields = document.querySelector('[data-manual-purchase-fields]');
    const note = document.querySelector('[data-quote-note]');
    if (!fields) return;
    fields.hidden = Boolean(quoteId);
    if (note) note.hidden = !quoteId;
}

async function approvePurchaseOrder(id) {
    await VKApi.request(`/purchase-orders/${id}/approve`, { method: 'POST', body: JSON.stringify({ reason: 'Duyệt từ màn hình đơn mua' }) });
    VKModal.toast('Đã duyệt đơn mua.');
    loadPurchaseOrders();
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
        actions: row => row.status === 'draft'
            ? `<button class="btn primary small" type="button" data-approve-purchase-order="${row.id}">Duyệt đơn mua</button>`
            : '',
    });
}

window.loadPurchaseOrders = loadPurchaseOrders;

function formatDate(value) {
    return value ? new Date(value).toLocaleDateString('vi-VN') : '-';
}
})();
