(function () {
let salesOrderState = { rows: [], q: '', status: '', view: 'list', detailCache: {} };

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
    if (event.target.matches('[data-confirm-delivery]')) {
        event.stopPropagation();
        openDeliveryModal(event.target.dataset.confirmDelivery);
    }
    if (event.target.matches('[data-issue-invoice]')) {
        event.stopPropagation();
        openInvoiceModal(event.target.dataset.issueInvoice);
    }
    if (event.target.matches('[data-record-payment]')) {
        event.stopPropagation();
        openPaymentModal(event.target.dataset.recordPayment);
    }
    if (event.target.closest('.row-action-menu')) return;
    const detail = event.target.closest('[data-sales-order-detail], tr[data-row-detail]');
    if (detail) openSalesOrderDetail(detail.dataset.salesOrderDetail || detail.dataset.rowDetail);
});

async function loadSalesOrders(options = {}) {
    if (!document.getElementById('salesOrdersRoot')) return;
    if (options.source === 'pjax') {
        salesOrderState.view = 'list';
        salesOrderState.currentId = null;
    }
    const orders = await VKApi.request('/sales-orders');
    salesOrderState.rows = orders.data || [];
    const openId = new URLSearchParams(window.location.search).get('open');
    if (openId) {
        openSalesOrderDetail(openId);
        return;
    }
    if (salesOrderState.view === 'detail' && salesOrderState.currentId) {
        openSalesOrderDetail(salesOrderState.currentId);
        return;
    }
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
                { value: 'awaiting_delivery', label: 'Chờ giao hàng' },
                { value: 'delivered', label: 'Đã giao hàng' },
                { value: 'invoiced', label: 'Đã xuất hóa đơn' },
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
    try {
        const response = await VKApi.request(`/sales-orders/${id}/confirm`, { method: 'POST' });
        const order = response.data || {};
        const warningDetails = creditWarningDetails(response.meta?.warnings || []);
        salesOrderState.detailCache[String(id)] = null;
        salesOrderState.rows = salesOrderState.rows.map(row => String(row.id) === String(id) ? { ...row, ...order } : row);

        if (order.stock_status === 'reserved') {
            VKModal.notice({
                type: 'success',
                title: 'Kiểm tồn thành công',
                message: 'Đã giữ hàng cho đơn bán và tạo công việc xuất kho.',
                details: [`Đơn bán: ${order.code || ''}`, 'Tình trạng tồn: Đã giữ hàng', ...warningDetails],
            });
        } else if (order.stock_status === 'shortage') {
            VKModal.notice({
                type: 'warning',
                title: 'Đơn bán thiếu tồn',
                message: 'Hệ thống đã ghi nhận thiếu tồn, tạo cảnh báo và yêu cầu mua nháp.',
                details: [`Đơn bán: ${order.code || ''}`, 'Bạn có thể kiểm tra mục Mua hàng hoặc Cảnh báo để xử lý tiếp.', ...warningDetails],
            });
        } else {
            VKModal.notice({
                type: 'success',
                title: 'Đã xác nhận kiểm tồn',
                message: 'Đơn bán đã được cập nhật trạng thái kiểm tồn.',
                details: warningDetails,
            });
        }

        if (salesOrderState.view === 'detail' && String(salesOrderState.currentId) === String(id)) {
            openSalesOrderDetail(id);
            return;
        }
        loadSalesOrders();
    } catch (error) {
        VKModal.notice({
            type: 'danger',
            title: 'Không thể xác nhận kiểm tồn',
            message: error.message || 'Có lỗi xảy ra khi xác nhận đơn bán.',
        });
    }
}

function creditWarningDetails(warnings) {
    return (warnings || []).map((warning) => {
        if (warning.type === 'customer_credit_over_limit') {
            return `Cảnh báo công nợ: khách dự kiến vượt hạn mức ${VKTable.money(warning.credit_limit)}. Dư nợ sau đơn: ${VKTable.money(warning.projected_balance)}.`;
        }
        if (warning.type === 'customer_overdue_debt') {
            return `Cảnh báo công nợ: khách đang có nợ quá hạn ${VKTable.money(warning.overdue_amount)}.`;
        }
        return warning.message || '';
    }).filter(Boolean);
}

function openSalesOrderDetail(id) {
    salesOrderState.view = 'detail';
    salesOrderState.currentId = id;
    VKRecordPage.open({
        root: '#salesOrdersRoot',
        type: 'salesOrder',
        id,
        path: `/sales-orders/${id}`,
        preview: salesOrderState.rows.find(row => String(row.id) === String(id)),
        cache: salesOrderState.detailCache,
        onBack: () => {
            salesOrderState.view = 'list';
            salesOrderState.currentId = null;
            renderSalesOrders();
        },
        actions: row => renderSalesOrderActions(row),
    });
}

function renderSalesOrderActions(row) {
    if (row.status === 'draft') {
        return can('sales.order.create') ? `<button class="btn primary small" type="button" data-confirm-sales-order="${row.id}">Xác nhận kiểm tồn</button>` : '';
    }
    if (row.status === 'awaiting_delivery' && row.delivery_status === 'pending') {
        return can('sales.delivery.confirm') ? `<button class="btn primary small" type="button" data-confirm-delivery="${row.id}">Xác nhận giao hàng</button>` : '';
    }
    if (row.delivery_status === 'delivered' && !(row.invoices || []).length) {
        return can('finance.invoice.manage') ? `<button class="btn primary small" type="button" data-issue-invoice="${row.id}">Phát hành hóa đơn</button>` : '';
    }
    const invoice = (row.invoices || [])[0];
    if (invoice && Number(invoice.balance_amount || 0) > 0) {
        return can('finance.payment.record') ? `<button class="btn primary small" type="button" data-record-payment="${invoice.id}">Ghi nhận thanh toán</button>` : '';
    }
    return '';
}

function openDeliveryModal(id) {
    const order = salesOrderState.detailCache[String(id)]
        || salesOrderState.rows.find(row => String(row.id) === String(id))
        || {};
    const customer = order.customer || {};

    VKModal.open('Xác nhận khách đã nhận hàng', `
        <div class="form-grid">
            ${VKModal.field('recipient_name', 'Người nhận hàng', 'text', customer.contact_name || customer.name || '')}
            ${VKModal.field('recipient_phone', 'Số điện thoại', 'text', customer.phone || '')}
            ${VKModal.field('delivered_at', 'Thời gian giao hàng', 'datetime-local', localDateTimeValue())}
            ${VKModal.field('delivery_address', 'Địa chỉ giao hàng', 'text', customer.address || customer.billing_address || '')}
            <div class="field full"><label for="proof_note">Ghi chú / bằng chứng giao hàng</label><textarea id="proof_note" name="proof_note" rows="3" placeholder="Ví dụ: Anh Minh đã nhận đủ hàng, phiếu giao hàng số..."></textarea></div>
        </div>
    `, async (form) => {
        const data = Object.fromEntries(new FormData(form));
        await VKApi.request(`/sales-orders/${id}/deliveries`, {
            method: 'POST',
            body: JSON.stringify(data),
        });
        VKModal.close();
        VKModal.toast('Đã xác nhận khách nhận hàng.', 'success');
        refreshSalesOrderDetail(id);
    }, { submitText: 'Xác nhận đã giao' });
}

function openInvoiceModal(id) {
    const today = new Date();
    const due = new Date();
    due.setDate(today.getDate() + 30);

    VKModal.open('Phát hành hóa đơn bán hàng', `
        <div class="form-grid">
            ${VKModal.field('invoice_date', 'Ngày hóa đơn', 'date', dateValue(today))}
            ${VKModal.field('due_date', 'Hạn thanh toán', 'date', dateValue(due))}
            ${VKModal.field('tax_invoice_symbol', 'Ký hiệu hóa đơn thuế', 'text')}
            ${VKModal.field('tax_invoice_no', 'Số hóa đơn thuế', 'text')}
            <div class="field full"><label for="note">Ghi chú</label><textarea id="note" name="note" rows="3"></textarea></div>
        </div>
    `, async (form) => {
        const data = Object.fromEntries(new FormData(form));
        await VKApi.request(`/sales-orders/${id}/invoices`, {
            method: 'POST',
            body: JSON.stringify(data),
        });
        VKModal.close();
        VKModal.toast('Đã phát hành hóa đơn bán hàng.', 'success');
        refreshSalesOrderDetail(id);
    }, { submitText: 'Phát hành hóa đơn' });
}

function openPaymentModal(invoiceId) {
    const order = salesOrderState.detailCache[String(salesOrderState.currentId)] || {};
    const invoice = (order.invoices || []).find(item => String(item.id) === String(invoiceId)) || {};

    VKModal.open('Ghi nhận thanh toán', `
        <div class="form-grid">
            ${VKModal.field('amount', 'Số tiền nhận', 'number', Number(invoice.balance_amount || 0))}
            ${VKModal.field('paid_at', 'Thời gian nhận', 'datetime-local', localDateTimeValue())}
            ${VKModal.select('payment_method', 'Phương thức thanh toán', [
                { value: 'bank_transfer', label: 'Chuyển khoản' },
                { value: 'cash', label: 'Tiền mặt' },
                { value: 'card', label: 'Thẻ' },
                { value: 'other', label: 'Khác' },
            ])}
            ${VKModal.field('reference_no', 'Mã tham chiếu', 'text')}
            <div class="field full"><label for="note">Ghi chú</label><textarea id="note" name="note" rows="3"></textarea></div>
        </div>
    `, async (form) => {
        const data = Object.fromEntries(new FormData(form));
        data.amount = Number(data.amount);
        await VKApi.request(`/sales-invoices/${invoiceId}/payments`, {
            method: 'POST',
            body: JSON.stringify(data),
        });
        VKModal.close();
        VKModal.toast('Đã ghi nhận thanh toán của khách hàng.', 'success');
        refreshSalesOrderDetail(salesOrderState.currentId);
    }, { submitText: 'Ghi nhận thanh toán' });
}

function refreshSalesOrderDetail(id) {
    salesOrderState.detailCache[String(id)] = null;
    openSalesOrderDetail(id);
}

function dateValue(date) {
    const offset = date.getTimezoneOffset();
    return new Date(date.getTime() - offset * 60000).toISOString().slice(0, 10);
}

function localDateTimeValue() {
    const date = new Date();
    const offset = date.getTimezoneOffset();
    return new Date(date.getTime() - offset * 60000).toISOString().slice(0, 16);
}

function can(permission) {
    return window.VKLayout?.hasPermission?.(permission) ?? true;
}

window.loadSalesOrders = loadSalesOrders;
})();
