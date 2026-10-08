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
    const editOrder = event.target.closest('[data-edit-sales-order]');
    if (editOrder) { event.stopPropagation(); openSalesOrderEditor(editOrder.dataset.editSalesOrder); return; }
    const deleteOrder = event.target.closest('[data-delete-sales-order]');
    if (deleteOrder) { event.stopPropagation(); deleteSalesOrder(deleteOrder.dataset.deleteSalesOrder); return; }
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
    if (event.target.matches('[data-sales-order-word]')) {
        event.stopPropagation();
        downloadSalesOrderWord(event.target.dataset.salesOrderWord);
    }
    if (event.target.closest('.row-action-menu') && !event.target.closest('[data-sales-order-detail]')) return;
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
        title: 'Danh sách đơn hàng',
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
            row.status === 'draft' && can('sales.order.edit') ? VKTable.smallButton('Sửa', `data-edit-sales-order="${row.id}"`) : '',
            row.status === 'draft' && can('sales.order.delete') ? VKTable.smallButton('Xóa', `data-delete-sales-order="${row.id}"`, 'danger') : '',
            row.status === 'draft' && can('sales.order.create') ? VKTable.smallButton('Xác nhận', `data-confirm-sales-order="${row.id}"`, 'primary') : '',
        ]) },
    ], rows, 'Chưa có đơn hàng', { rowAttr: row => `data-row-detail="${row.id}"` });
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
    if (!ready.length) return VKModal.toast('Chưa có báo giá đã duyệt để tạo đơn hàng.', 'warning');
    VKModal.open('Tạo đơn hàng từ báo giá', `
        <div class="form-grid sales-order-create-grid">
            ${VKModal.select('quotation_id', 'Báo giá', ready.map(q => ({ value: q.id, label: `${q.code} - ${q.customer?.name || ''} - ${VKTable.translateStatus(q.status)}` })))}
        </div>
        <div class="sales-order-create-preview" data-sales-order-create-preview></div>
    `, async (form) => {
        const data = Object.fromEntries(new FormData(form));
        await VKApi.request('/sales-orders', { method: 'POST', body: JSON.stringify({ quotation_id: Number(data.quotation_id) }) });
        VKModal.toast('Đã tạo đơn hàng.');
        VKModal.close();
        loadSalesOrders();
    }, { className: 'sales-order-create-modal', submitText: 'Tạo đơn hàng' });

    const select = document.querySelector('#modalBody [name="quotation_id"]');
    const preview = document.querySelector('#modalBody [data-sales-order-create-preview]');
    const renderPreview = () => {
        const quotation = ready.find(item => String(item.id) === String(select?.value));
        if (quotation && preview) preview.innerHTML = renderSalesOrderCreatePreview(quotation);
    };
    select?.addEventListener('change', renderPreview);
    renderPreview();
}

function renderSalesOrderCreatePreview(quotation) {
    const customer = quotation.customer || {};
    const items = quotation.items || [];
    return `
        <div class="sales-order-create-heading"><div><strong>Thông tin đơn hàng sẽ tạo</strong><span>Kiểm tra dữ liệu báo giá trước khi tạo đơn.</span></div><b>${VKTable.escapeHtml(quotation.code || '-')}</b></div>
        <div class="sales-order-create-info">
            <div><span>Khách hàng</span><strong>${VKTable.escapeHtml(customer.name || 'Chưa có khách hàng')}</strong></div>
            <div><span>Người liên hệ</span><strong>${VKTable.escapeHtml([customer.contact_name, customer.phone].filter(Boolean).join(' - ') || 'Chưa khai báo')}</strong></div>
            <div><span>Địa chỉ giao</span><strong>${VKTable.escapeHtml(customer.address || customer.billing_address || 'Chưa khai báo')}</strong></div>
            <div><span>Thanh toán</span><strong>${VKTable.escapeHtml(quotation.payment_terms || 'Chưa khai báo')}</strong></div>
        </div>
        <div class="sales-order-create-items"><div class="sales-order-create-items-head"><strong>Hàng hóa báo giá</strong><span>${items.length} dòng hàng · <b>${VKTable.money(quotation.total_amount || 0)}</b></span></div>
            <table><thead><tr><th>Mã hàng</th><th>Tên hàng</th><th>ĐVT</th><th>SL</th><th>Đơn giá</th><th>Thành tiền</th></tr></thead><tbody>${items.map(item => `<tr><td>${VKTable.escapeHtml(item.sku?.sku_code || '-')}</td><td>${VKTable.escapeHtml(item.sku?.name || '-')}</td><td>${VKTable.escapeHtml(item.sku?.unit || '-')}</td><td>${VKTable.money(item.quantity || 0)}</td><td>${VKTable.money(item.unit_price || 0)}</td><td><strong>${VKTable.money(item.line_total || 0)}</strong></td></tr>`).join('') || '<tr><td colspan="6">Báo giá chưa có hàng hóa.</td></tr>'}</tbody></table>
        </div>
    `;
}

async function confirmSalesOrder(id) {
    VKModal.open('Chọn cách giao hàng', `<div class="field"><label>Cách xử lý đơn hàng</label><select name="fulfillment_type"><option value="from_stock">Có sẵn hàng — gửi yêu cầu xuống kho để xuất</option><option value="supplier_direct">Mua theo đơn — nhà cung cấp giao thẳng khách</option></select><small>Giao thẳng từ nhà cung cấp sẽ không kiểm tra hoặc trừ tồn kho.</small></div><div class="field"><label>Ghi chú giao thẳng</label><textarea name="supplier_delivery_note" rows="3" placeholder="Tên NCC, thời gian giao dự kiến..."></textarea></div>`, async form => { const choice = Object.fromEntries(new FormData(form)); VKModal.close(); await confirmSalesOrderWithChoice(id, choice); }, { submitText: 'Xác nhận và chuyển đơn' });
}

async function confirmSalesOrderWithChoice(id, choice) {
    try {
        const response = await VKApi.request(`/sales-orders/${id}/confirm`, { method: 'POST', body: JSON.stringify(choice) });
        const order = response.data || {};
        const warningDetails = creditWarningDetails(response.meta?.warnings || []);
        salesOrderState.detailCache[String(id)] = null;
        salesOrderState.rows = salesOrderState.rows.map(row => String(row.id) === String(id) ? { ...row, ...order } : row);

        if (order.fulfillment_type === 'supplier_direct') {
            VKModal.notice({ type: 'success', title: 'Đã chuyển đơn giao thẳng', message: 'Đơn không đi qua kho; hệ thống tạo việc theo dõi nhà cung cấp giao hàng.', details: [`Đơn hàng: ${order.code || ''}`, 'Không trừ tồn kho.', ...warningDetails] });
        } else if (order.stock_status === 'reserved') {
            VKModal.notice({
                type: 'success',
                title: 'Đã gửi yêu cầu xuất kho',
                message: 'Đã giữ hàng cho đơn bán và tạo công việc xuất kho.',
                details: [`Đơn hàng: ${order.code || ''}`, 'Tình trạng tồn: Đã giữ hàng', ...warningDetails],
            });
        } else if (order.stock_status === 'shortage') {
            VKModal.notice({
                type: 'warning',
                title: 'Đơn hàng thiếu tồn',
                message: 'Hệ thống đã ghi nhận thiếu tồn, tạo cảnh báo và yêu cầu mua nháp.',
                details: [`Đơn hàng: ${order.code || ''}`, 'Bạn có thể kiểm tra mục Mua hàng hoặc Cảnh báo để xử lý tiếp.', ...warningDetails],
            });
        } else {
            VKModal.notice({
                type: 'success',
                title: 'Đã xác nhận kiểm tồn',
                message: 'Đơn hàng đã được cập nhật trạng thái kiểm tồn.',
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
    const wordButton = `<button class="btn small" type="button" data-sales-order-word="${row.id}">Xuất file</button>`;
    if (row.status === 'draft') {
        return `${wordButton}${can('sales.order.edit') ? `<button class="btn small" type="button" data-edit-sales-order="${row.id}">Sửa</button>` : ''}${can('sales.order.delete') ? `<button class="btn danger small" type="button" data-delete-sales-order="${row.id}">Xóa</button>` : ''}${can('sales.order.create') ? `<button class="btn primary small" type="button" data-confirm-sales-order="${row.id}">Chuyển đơn giao hàng</button>` : ''}`;
    }
    if (row.status === 'awaiting_delivery' && row.delivery_status === 'pending') {
        return `${wordButton}${can('sales.delivery.confirm') ? `<button class="btn primary small" type="button" data-confirm-delivery="${row.id}">Xác nhận giao hàng</button>` : ''}`;
    }
    if (row.delivery_status === 'delivered' && !(row.invoices || []).length) {
        return `${wordButton}${can('finance.invoice.manage') ? `<button class="btn primary small" type="button" data-issue-invoice="${row.id}">Phát hành hóa đơn</button>` : ''}`;
    }
    const invoice = (row.invoices || [])[0];
    if (invoice && Number(invoice.balance_amount || 0) > 0) {
        return `${wordButton}${can('finance.payment.record') ? `<button class="btn primary small" type="button" data-record-payment="${invoice.id}">Ghi nhận thanh toán</button>` : ''}`;
    }
    return wordButton;
}

async function openSalesOrderEditor(id) {
    const response = await VKApi.request(`/sales-orders/${id}`);
    const row = response.data;
    if (row.status !== 'draft') return VKModal.toast('Chỉ được sửa đơn hàng nháp.', 'warning');
    VKModal.open(`Sửa đơn hàng ${row.code}`, `<p class="modal-intro">Thông tin hàng hóa lấy từ báo giá gốc. Phần chỉnh sửa đơn hàng nháp hiện gồm điều khoản thanh toán và ghi chú giao hàng.</p><div class="field"><label for="order_payment_terms">Điều khoản thanh toán</label><input id="order_payment_terms" name="payment_terms" value="${VKTable.escapeHtml(row.payment_terms || '')}"></div><div class="field"><label for="order_delivery_note">Ghi chú giao hàng</label><textarea id="order_delivery_note" name="supplier_delivery_note" rows="3">${VKTable.escapeHtml(row.supplier_delivery_note || '')}</textarea></div>`, async form => {
        await VKApi.request(`/sales-orders/${id}`, { method: 'PUT', body: JSON.stringify(Object.fromEntries(new FormData(form))) });
        VKModal.close(); salesOrderState.detailCache[String(id)] = null;
        await loadSalesOrders();
        VKModal.toast('Đã cập nhật đơn hàng nháp.');
    }, { submitText: 'Lưu thay đổi' });
}

async function deleteSalesOrder(id) {
    const row = salesOrderState.rows.find(item => String(item.id) === String(id));
    if (!window.confirm(`Xóa đơn hàng nháp ${row?.code || ''}? Thao tác này không thể hoàn tác.`)) return;
    try {
        await VKApi.request(`/sales-orders/${id}`, { method: 'DELETE' });
        salesOrderState.view = 'list'; salesOrderState.currentId = null;
        await loadSalesOrders();
        VKModal.toast('Đã xóa đơn hàng nháp.');
    } catch (error) { VKModal.toast(error.message || 'Không xóa được đơn hàng.', 'danger'); }
}

async function downloadSalesOrderWord(id) {
    const row = salesOrderState.detailCache[String(id)] || salesOrderState.rows.find(item => String(item.id) === String(id)) || {};
    const response = await fetch(`/api/v1/sales-orders/${encodeURIComponent(id)}/word`, {
        headers: { Accept: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', Authorization: `Bearer ${VKApi.token()}` },
    });
    if (!response.ok) {
        const body = await response.json().catch(() => ({}));
        VKModal.notice({ type: 'warning', title: 'Chưa thể xuất file', message: body.message || 'Không thể tải file Đơn hàng.' });
        return;
    }
    const url = URL.createObjectURL(await response.blob());
    const link = document.createElement('a');
    link.href = url; link.download = `${row.code || 'don-hang'}.docx`; document.body.appendChild(link); link.click(); link.remove();
    window.setTimeout(() => URL.revokeObjectURL(url), 1000);
    VKModal.toast('Đã tải file Đơn hàng.', 'success');
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
