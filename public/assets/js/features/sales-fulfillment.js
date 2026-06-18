(function () {
    const states = {
        deliveries: { rows: [], q: '', status: '', view: 'list', total: 0 },
        invoices: { rows: [], q: '', status: '', view: 'list', total: 0 },
        payments: { rows: [], q: '', method: '', view: 'list', total: 0 },
    };

    document.addEventListener('vk:ready', loadCurrentPage);
    document.addEventListener('vk:flow-updated', loadCurrentPage);

    document.addEventListener('input', (event) => {
        const context = currentContext();
        if (!context || !event.target.matches('[data-list-search]')) return;
        states[context].q = event.target.value.toLowerCase();
        renderList(context);
    });

    document.addEventListener('change', (event) => {
        const context = currentContext();
        if (!context) return;
        if (event.target.matches('[data-list-status]')) {
            states[context].status = event.target.value;
            renderList(context);
        }
        if (event.target.matches('[data-payment-method]')) {
            states.payments.method = event.target.value;
            renderList('payments');
        }
    });

    document.addEventListener('click', (event) => {
        const back = event.target.closest('[data-fulfillment-back]');
        if (back) {
            const context = back.dataset.fulfillmentBack;
            states[context].view = 'list';
            renderList(context);
            return;
        }

        const delivery = event.target.closest('[data-delivery-detail]');
        if (delivery) openDeliveryDetail(delivery.dataset.deliveryDetail);
        const invoice = event.target.closest('[data-invoice-detail]');
        if (invoice) openInvoiceDetail(invoice.dataset.invoiceDetail);
        const payment = event.target.closest('[data-payment-detail]');
        if (payment) openPaymentDetail(payment.dataset.paymentDetail);

        const confirm = event.target.closest('[data-worklist-confirm-delivery]');
        if (confirm) openDeliveryConfirmation(confirm.dataset.worklistConfirmDelivery);
        const invoiceFromDelivery = event.target.closest('[data-delivery-issue-invoice]');
        if (invoiceFromDelivery) openInvoiceFromDeliveryModal(invoiceFromDelivery.dataset.deliveryIssueInvoice);
        const receive = event.target.closest('[data-worklist-record-payment]');
        if (receive) openPaymentModal(receive.dataset.worklistRecordPayment);
    });

    async function loadCurrentPage(options = {}) {
        if (document.getElementById('deliveriesRoot')) return loadDeliveries(options);
        if (document.getElementById('salesInvoicesRoot')) return loadSalesInvoices(options);
        if (document.getElementById('customerPaymentsRoot')) return loadCustomerPayments(options);
    }

    async function loadDeliveries(options = {}) {
        if (!document.getElementById('deliveriesRoot')) return;
        resetView('deliveries', options);
        const response = await VKApi.request('/deliveries?page_size=100');
        states.deliveries.rows = response.data || [];
        states.deliveries.total = response.meta?.total || states.deliveries.rows.length;
        renderList('deliveries');
    }

    async function loadSalesInvoices(options = {}) {
        if (!document.getElementById('salesInvoicesRoot')) return;
        resetView('invoices', options);
        const response = await VKApi.request('/sales-invoices?page_size=100');
        states.invoices.rows = response.data || [];
        states.invoices.total = response.meta?.total || states.invoices.rows.length;
        renderList('invoices');
    }

    async function loadCustomerPayments(options = {}) {
        if (!document.getElementById('customerPaymentsRoot')) return;
        resetView('payments', options);
        const response = await VKApi.request('/customer-payments?page_size=100');
        states.payments.rows = response.data || [];
        states.payments.total = response.meta?.total || states.payments.rows.length;
        renderList('payments');
    }

    function resetView(context, options) {
        if (options.source === 'pjax') states[context].view = 'list';
    }

    function renderList(context) {
        const root = rootFor(context);
        if (!root || states[context].view !== 'list') return;
        if (context === 'deliveries') renderDeliveries(root);
        if (context === 'invoices') renderInvoices(root);
        if (context === 'payments') renderPayments(root);
    }

    function renderDeliveries(root) {
        const rows = states.deliveries.rows.filter((row) => {
            const delivery = row.deliveries?.[0] || {};
            const text = `${row.code} ${row.customer?.name || ''} ${delivery.recipient_name || ''}`.toLowerCase();
            return (!states.deliveries.q || text.includes(states.deliveries.q))
                && (!states.deliveries.status || row.delivery_status === states.deliveries.status);
        });
        const pending = states.deliveries.rows.filter(row => row.delivery_status === 'pending').length;
        const delivered = states.deliveries.rows.filter(row => row.delivery_status === 'delivered').length;

        root.innerHTML = summary([
            ['Chờ giao hàng', pending, 'Cần xác nhận khách đã nhận', 'blue'],
            ['Đã giao hàng', delivered, 'Đã có người nhận và thời gian', 'green'],
        ]) + VKTable.fullList({
            title: 'Sổ giao hàng',
            subtitle: 'Theo dõi từ phiếu xuất kho đến lúc khách hàng xác nhận đã nhận hàng.',
            meta: `${rows.length} / ${states.deliveries.total} đơn`,
            filters: VKTable.filterBar({
                searchPlaceholder: 'Tìm đơn bán, khách hàng hoặc người nhận...',
                status: [
                    { value: 'pending', label: 'Chờ giao hàng' },
                    { value: 'delivered', label: 'Đã giao hàng' },
                ],
            }),
            table: VKTable.renderTable([
                { label: 'Đơn bán', render: row => mono(row.code) },
                { label: 'Khách hàng', render: row => escape(row.customer?.name || '-') },
                { label: 'Người nhận', render: row => escape(row.deliveries?.[0]?.recipient_name || row.customer?.contact_name || 'Chưa xác nhận') },
                { label: 'Thời gian giao', render: row => formatDateTime(row.deliveries?.[0]?.delivered_at) },
                { label: 'Giá trị đơn', render: row => money(row.total_amount) },
                { label: 'Trạng thái', render: row => VKTable.statusBadge(row.delivery_status) },
                { label: '', render: row => `<button class="btn icon-only small" type="button" title="Mở chi tiết" data-delivery-detail="${row.id}">›</button>` },
            ], rows, 'Chưa có đơn cần giao', { rowAttr: row => `data-delivery-detail="${row.id}"` }),
        });
        restoreFilters('deliveries');
    }

    function renderInvoices(root) {
        const rows = states.invoices.rows.filter((row) => {
            const text = `${row.code} ${row.sales_order?.code || ''} ${row.sales_order?.customer?.name || ''}`.toLowerCase();
            return (!states.invoices.q || text.includes(states.invoices.q))
                && (!states.invoices.status || row.status === states.invoices.status);
        });
        const outstanding = states.invoices.rows.reduce((sum, row) => sum + Number(row.balance_amount || 0), 0);
        const overdue = states.invoices.rows.filter(row => row.status !== 'paid' && row.due_date && new Date(row.due_date) < startOfToday()).length;

        root.innerHTML = summary([
            ['Còn phải thu', money(outstanding), 'Tổng dư nợ hóa đơn', 'orange'],
            ['Hóa đơn quá hạn', overdue, 'Chưa thanh toán đủ', overdue ? 'red' : 'green'],
            ['Đã thanh toán', states.invoices.rows.filter(row => row.status === 'paid').length, 'Đã tất toán', 'green'],
        ]) + VKTable.fullList({
            title: 'Sổ hóa đơn bán hàng',
            subtitle: 'Kiểm soát ngày phát hành, hạn thanh toán và số tiền còn phải thu.',
            meta: `${rows.length} / ${states.invoices.total} hóa đơn`,
            filters: VKTable.filterBar({
                searchPlaceholder: 'Tìm mã hóa đơn, đơn bán hoặc khách hàng...',
                status: [
                    { value: 'issued', label: 'Chưa thanh toán' },
                    { value: 'partially_paid', label: 'Thanh toán một phần' },
                    { value: 'paid', label: 'Đã thanh toán' },
                ],
            }),
            table: VKTable.renderTable([
                { label: 'Hóa đơn', render: row => mono(row.code) },
                { label: 'Khách hàng', render: row => escape(row.sales_order?.customer?.name || '-') },
                { label: 'Ngày hóa đơn', render: row => formatDate(row.invoice_date) },
                { label: 'Hạn thanh toán', render: row => dueDate(row) },
                { label: 'Tổng tiền', render: row => money(row.total_amount) },
                { label: 'Còn phải thu', render: row => `<strong class="${Number(row.balance_amount) > 0 ? 'text-warning' : ''}">${money(row.balance_amount)}</strong>` },
                { label: 'Trạng thái', render: row => VKTable.statusBadge(row.status) },
                { label: '', render: row => `<button class="btn icon-only small" type="button" title="Mở chi tiết" data-invoice-detail="${row.id}">›</button>` },
            ], rows, 'Chưa có hóa đơn bán hàng', { rowAttr: row => `data-invoice-detail="${row.id}"` }),
        });
        restoreFilters('invoices');
    }

    function renderPayments(root) {
        const rows = states.payments.rows.filter((row) => {
            const text = `${row.code} ${row.reference_no || ''} ${row.invoice?.code || ''} ${row.sales_order?.customer?.name || ''}`.toLowerCase();
            return (!states.payments.q || text.includes(states.payments.q))
                && (!states.payments.method || row.payment_method === states.payments.method);
        });
        const collected = states.payments.rows.reduce((sum, row) => sum + Number(row.amount || 0), 0);

        root.innerHTML = summary([
            ['Tổng đã thu', money(collected), 'Theo các phiếu thu đã ghi nhận', 'green'],
            ['Số phiếu thu', states.payments.total, 'Mỗi lần nhận tiền là một phiếu', 'blue'],
        ]) + VKTable.fullList({
            title: 'Sổ thu tiền khách hàng',
            subtitle: 'Tra cứu từng lần thanh toán và đối chiếu với hóa đơn, đơn bán.',
            meta: `${rows.length} / ${states.payments.total} phiếu`,
            filters: VKTable.filterBar({
                searchPlaceholder: 'Tìm phiếu thu, hóa đơn, tham chiếu hoặc khách hàng...',
                extra: `<select class="select" data-payment-method>
                    <option value="">Tất cả phương thức</option>
                    <option value="bank_transfer">Chuyển khoản</option>
                    <option value="cash">Tiền mặt</option>
                    <option value="card">Thẻ</option>
                    <option value="other">Khác</option>
                </select>`,
            }),
            table: VKTable.renderTable([
                { label: 'Phiếu thu', render: row => mono(row.code) },
                { label: 'Khách hàng', render: row => escape(row.sales_order?.customer?.name || '-') },
                { label: 'Hóa đơn', render: row => mono(row.invoice?.code || '-') },
                { label: 'Ngày thu', render: row => formatDateTime(row.paid_at) },
                { label: 'Phương thức', render: row => escape(paymentMethod(row.payment_method)) },
                { label: 'Tham chiếu', render: row => escape(row.reference_no || '-') },
                { label: 'Số tiền', render: row => `<strong>${money(row.amount)}</strong>` },
                { label: '', render: row => `<button class="btn icon-only small" type="button" title="Mở chi tiết" data-payment-detail="${row.id}">›</button>` },
            ], rows, 'Chưa có phiếu thu khách hàng', { rowAttr: row => `data-payment-detail="${row.id}"` }),
        });
        restoreFilters('payments');
    }

    async function openDeliveryDetail(id) {
        const root = rootFor('deliveries');
        states.deliveries.view = 'detail';
        root.innerHTML = loading();
        try {
            const row = (await VKApi.request(`/deliveries/${id}`)).data;
            const delivery = row.deliveries?.[0];
            const hasInvoice = (row.invoices || []).length > 0;
            root.innerHTML = detailPage('deliveries', 'Giao hàng', row.code, row.delivery_status, `
                ${detailGrid('Thông tin giao hàng', [
                    ['Đơn bán', row.code],
                    ['Khách hàng', row.customer?.name],
                    ['Người liên hệ', [row.customer?.contact_name, row.customer?.phone].filter(Boolean).join(' - ')],
                    ['Trạng thái', status(row.delivery_status), true],
                    ['Người nhận', delivery?.recipient_name || 'Chưa xác nhận'],
                    ['Số điện thoại nhận', delivery?.recipient_phone || '-'],
                    ['Địa chỉ giao hàng', delivery?.delivery_address || row.customer?.address || row.customer?.billing_address || '-'],
                    ['Thời gian giao', formatDateTime(delivery?.delivered_at)],
                    ['Người xác nhận', delivery?.confirmed_by?.name || '-'],
                    ['Ghi chú giao nhận', delivery?.proof_note || '-'],
                ])}
                ${(row.invoices || []).length ? relatedInvoices(row.invoices) : ''}
                ${itemsTable(row.items || [])}
            `, deliveryActions(row, hasInvoice));
        } catch (error) {
            showError(root, 'Không tải được chi tiết giao hàng', error);
        }
    }

    async function openInvoiceDetail(id) {
        const root = rootFor('invoices');
        states.invoices.view = 'detail';
        root.innerHTML = loading();
        try {
            const row = (await VKApi.request(`/sales-invoices/${id}`)).data;
            root.innerHTML = invoiceDetailPage(row);
        } catch (error) {
            showError(root, 'Không tải được chi tiết hóa đơn', error);
        }
    }

    async function openPaymentDetail(id) {
        const root = rootFor('payments');
        states.payments.view = 'detail';
        root.innerHTML = loading();
        try {
            const row = (await VKApi.request(`/customer-payments/${id}`)).data;
            root.innerHTML = detailPage('payments', 'Phiếu thu khách hàng', row.code, 'paid', `
                ${detailGrid('Thông tin phiếu thu', [
                    ['Mã phiếu thu', row.code],
                    ['Khách hàng', row.sales_order?.customer?.name],
                    ['Hóa đơn', row.invoice?.code],
                    ['Đơn bán', row.sales_order?.code],
                    ['Số tiền thu', money(row.amount)],
                    ['Thời gian thu', formatDateTime(row.paid_at)],
                    ['Phương thức', paymentMethod(row.payment_method)],
                    ['Mã tham chiếu', row.reference_no || '-'],
                    ['Người ghi nhận', row.received_by?.name || '-'],
                    ['Ghi chú', row.note || '-'],
                ])}
                ${detailGrid('Đối chiếu hóa đơn', [
                    ['Tổng hóa đơn', money(row.invoice?.total_amount)],
                    ['Đã thanh toán', money(row.invoice?.paid_amount)],
                    ['Còn phải thu', money(row.invoice?.balance_amount)],
                    ['Trạng thái hóa đơn', status(row.invoice?.status), true],
                ])}
            `);
        } catch (error) {
            showError(root, 'Không tải được chi tiết phiếu thu', error);
        }
    }

    function openDeliveryConfirmation(orderId) {
        const row = states.deliveries.rows.find(item => String(item.id) === String(orderId)) || {};
        const customer = row.customer || {};
        VKModal.open('Xác nhận khách đã nhận hàng', `
            <div class="form-grid">
                ${VKModal.field('recipient_name', 'Người nhận hàng', 'text', customer.contact_name || customer.name || '')}
                ${VKModal.field('recipient_phone', 'Số điện thoại', 'text', customer.phone || '')}
                ${VKModal.field('delivered_at', 'Thời gian giao hàng', 'datetime-local', localDateTimeValue())}
                ${VKModal.field('delivery_address', 'Địa chỉ giao hàng', 'text', customer.address || customer.billing_address || '')}
                <div class="field full"><label for="proof_note">Ghi chú giao nhận</label><textarea id="proof_note" name="proof_note" rows="3" placeholder="Người nhận, số phiếu giao, tình trạng hàng..."></textarea></div>
            </div>
        `, async (form) => {
            await VKApi.request(`/sales-orders/${orderId}/deliveries`, {
                method: 'POST',
                body: JSON.stringify(Object.fromEntries(new FormData(form))),
            });
            VKModal.close();
            VKModal.toast('Đã xác nhận giao hàng thành công.', 'success');
            states.deliveries.view = 'list';
            await loadDeliveries();
        }, { submitText: 'Xác nhận đã giao' });
    }

    function openInvoiceFromDeliveryModal(orderId) {
        const today = new Date();
        const due = new Date();
        due.setDate(today.getDate() + 30);

        VKModal.open('Phát hành hóa đơn bán hàng', `
            <div class="form-grid">
                ${VKModal.field('invoice_date', 'Ngày hóa đơn', 'date', dateValue(today))}
                ${VKModal.field('due_date', 'Hạn thanh toán', 'date', dateValue(due))}
                <div class="field full"><label for="note">Ghi chú</label><textarea id="note" name="note" rows="3" placeholder="Thông tin hóa đơn, điều khoản thanh toán..."></textarea></div>
            </div>
        `, async (form) => {
            const data = Object.fromEntries(new FormData(form));
            await VKApi.request(`/sales-orders/${orderId}/invoices`, {
                method: 'POST',
                body: JSON.stringify(data),
            });
            VKModal.close();
            VKModal.toast('Đã phát hành hóa đơn bán hàng.', 'success');
            states.deliveries.view = 'list';
            await loadDeliveries();
        }, { submitText: 'Phát hành hóa đơn' });
    }

    async function openPaymentModal(invoiceId) {
        const invoice = states.invoices.rows.find(item => String(item.id) === String(invoiceId))
            || (await VKApi.request(`/sales-invoices/${invoiceId}`)).data;
        VKModal.open('Ghi nhận thanh toán khách hàng', `
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
            VKModal.toast('Đã ghi nhận thanh toán thành công.', 'success');
            states.invoices.view = 'list';
            await loadSalesInvoices();
        }, { submitText: 'Ghi nhận thanh toán' });
    }

    function deliveryActions(row, hasInvoice) {
        if (row.delivery_status === 'pending' && can('sales.delivery.confirm')) {
            return `<button class="btn primary small" type="button" data-worklist-confirm-delivery="${row.id}">Xác nhận đã giao hàng</button>`;
        }
        if (row.delivery_status === 'delivered' && !hasInvoice && can('finance.invoice.manage')) {
            return `<button class="btn primary small" type="button" data-delivery-issue-invoice="${row.id}">Phát hành hóa đơn</button>`;
        }
        if (hasInvoice) {
            return '<a class="btn small" href="/sales-invoices">Xem hóa đơn</a>';
        }
        return '';
    }

    function relatedInvoices(rows) {
        return `<section class="record-panel"><h3>Hóa đơn liên quan</h3><div class="record-related">
            ${rows.map(row => `<div><strong>${escape(row.code)}</strong><span>${escape(VKTable.translateStatus(row.status))} · Còn phải thu ${money(row.balance_amount)}</span></div>`).join('')}
        </div></section>`;
    }

    function invoiceDetailPage(row) {
        const customer = row.sales_order?.customer || {};
        const actions = Number(row.balance_amount || 0) > 0 && can('finance.payment.record')
            ? `<button class="btn primary small" type="button" data-worklist-record-payment="${row.id}">Ghi nhận thanh toán</button>`
            : '';

        return `<section class="invoice-detail-page">
            <header class="invoice-detail-head">
                <button class="btn small" type="button" data-fulfillment-back="invoices">Quay lại danh sách</button>
                <div class="invoice-title">
                    <span>Hóa đơn bán hàng</span>
                    <h2>${escape(row.code || '-')} ${status(row.status)}</h2>
                    <div class="invoice-tags">
                        <span>Khách hàng: ${escape(customer.name || '-')}</span>
                        <span>MST: ${escape(customer.tax_code || 'Chưa khai báo')}</span>
                        <span>Người tạo: ${escape(row.issued_by?.name || '-')}</span>
                        <span>Ngày tạo: ${formatDate(row.invoice_date)}</span>
                    </div>
                </div>
                <div class="invoice-actions">
                    <button class="btn small" type="button">In hóa đơn</button>
                    <button class="btn small" type="button">Tải xuống</button>
                    ${actions}
                </div>
            </header>

            <section class="invoice-metrics">
                ${invoiceMetric('Tổng tiền', money(row.total_amount), 'Giá trị hóa đơn', 'blue', 'invoice')}
                ${invoiceMetric('Đã thu', money(row.paid_amount), 'Tiền đã ghi nhận', 'green', 'wallet')}
                ${invoiceMetric('Còn phải thu', money(row.balance_amount), 'Dư nợ hiện tại', Number(row.balance_amount) > 0 ? 'orange' : 'green', 'card')}
                ${invoiceMetric('Trạng thái', VKTable.translateStatus(row.status), 'Tình trạng thanh toán', 'violet', 'check')}
            </section>

            <div class="invoice-detail-grid">
                ${invoiceInfoPanel(row)}
                ${invoicePaymentPanel(row)}
            </div>

            ${invoiceItemsPanel(row.sales_order?.items || [])}
        </section>`;
    }

    function invoiceMetric(label, value, note, tone, icon) {
        return `<article class="invoice-metric ${tone}">
            <div>
                <span>${escape(label)}</span>
                <strong>${escape(value)}</strong>
                <small>${escape(note)}</small>
            </div>
            <i class="${icon}" aria-hidden="true"></i>
        </article>`;
    }

    function invoiceInfoPanel(row) {
        const customer = row.sales_order?.customer || {};
        const fields = [
            ['Mã hóa đơn', row.code, 'invoice'],
            ['Đơn bán', row.sales_order?.code, 'cart', true],
            ['Khách hàng', customer.name, 'user'],
            ['Người phát hành', row.issued_by?.name || '-', 'user'],
            ['Mã số thuế', customer.tax_code || 'Chưa khai báo', 'building'],
            ['Phương thức thanh toán', paymentMethod(row.payments?.[0]?.payment_method) || 'Chưa ghi nhận', 'card'],
            ['Ngày hóa đơn', formatDate(row.invoice_date), 'calendar'],
            ['Trạng thái', status(row.status), 'check', true],
            ['Hạn thanh toán', formatDate(row.due_date), 'clock'],
            ['Ghi chú', row.note || '-', 'note'],
        ];

        return `<section class="invoice-panel">
            <h3>Thông tin hóa đơn</h3>
            <div class="invoice-info-list">
                ${fields.map(([label, value, icon, html]) => `
                    <div>
                        <i class="${icon}" aria-hidden="true"></i>
                        <span>${escape(label)}</span>
                        <strong>${html ? value : escape(value || '-')}</strong>
                    </div>
                `).join('')}
            </div>
        </section>`;
    }

    function invoicePaymentPanel(row) {
        const rows = row.payments || [];
        return `<section class="invoice-panel">
            <div class="invoice-panel-head">
                <h3>Lịch sử thanh toán (${rows.length})</h3>
                ${Number(row.balance_amount || 0) > 0 && can('finance.payment.record')
                    ? `<button class="btn small" type="button" data-worklist-record-payment="${row.id}">+ Thu tiền</button>`
                    : ''}
            </div>
            <div class="invoice-payment-list">
                ${rows.length ? rows.map(payment => `
                    <div class="invoice-payment-row">
                        <i aria-hidden="true"></i>
                        <div>
                            <strong>${escape(payment.code)}</strong>
                            <span>${formatDateTime(payment.paid_at)}</span>
                        </div>
                        <span>${escape(paymentMethod(payment.payment_method))}</span>
                        <strong>${money(payment.amount)}</strong>
                    </div>
                `).join('') : '<div class="empty compact"><strong>Chưa có thanh toán</strong><span>Các lần thu tiền sẽ hiển thị tại đây.</span></div>'}
            </div>
        </section>`;
    }

    function invoiceItemsPanel(items) {
        const rows = items.map((item, index) => ({ ...item, _rowNo: index + 1 }));
        const subtotal = items.reduce((sum, item) => sum + Number(item.quantity || 0) * Number(item.unit_price || 0), 0);
        const total = items.reduce((sum, item) => sum + Number(item.line_total || 0), 0);
        const vat = Math.max(0, total - subtotal);

        return `<section class="invoice-items-panel">
            <h3>Dòng hàng (${items.length})</h3>
            ${VKTable.renderTable([
                { label: 'STT', render: item => String(item._rowNo) },
                { label: 'Mã hàng', render: item => mono(item.sku?.sku_code || '-') },
                { label: 'Tên hàng', render: item => escape(item.sku?.name || '-') },
                { label: 'ĐVT', render: item => escape(item.sku?.unit || '-') },
                { label: 'Số lượng', render: item => money(item.quantity) },
                { label: 'Đơn giá', render: item => money(item.unit_price) },
                { label: 'Thành tiền', render: item => `<strong>${money(item.line_total)}</strong>` },
            ], rows, 'Chưa có dòng hàng')}
            <div class="invoice-total-box">
                <div><span>Tổng tiền hàng</span><strong>${money(subtotal)}</strong></div>
                <div><span>Thuế VAT</span><strong>${money(vat)}</strong></div>
                <div class="total"><span>Tổng cộng</span><strong>${money(total)}</strong></div>
            </div>
        </section>`;
    }

    function detailPage(context, eyebrow, code, state, content, actions = '') {
        return `<section class="fulfillment-record">
            <header class="fulfillment-record-head">
                <button class="btn small" type="button" data-fulfillment-back="${context}">Quay lại danh sách</button>
                <div><span>${escape(eyebrow)}</span><h2>${escape(code || '-')} ${status(state)}</h2></div>
                <div class="fulfillment-record-actions">${actions}</div>
            </header>
            <div class="fulfillment-record-body">${content}</div>
        </section>`;
    }

    function detailGrid(title, fields) {
        return `<section class="record-panel fulfillment-info-panel">
            <h3>${escape(title)}</h3>
            <div class="fulfillment-info-grid">${fields.map(([label, value, html]) => `
                <div><span>${escape(label)}</span><strong>${html ? value : escape(value || '-')}</strong></div>
            `).join('')}</div>
        </section>`;
    }

    function itemsTable(items) {
        return `<section class="record-panel"><h3>Dòng hàng (${items.length})</h3>${VKTable.renderTable([
            { label: 'Mã hàng', render: item => mono(item.sku?.sku_code || '-') },
            { label: 'Tên hàng', render: item => escape(item.sku?.name || '-') },
            { label: 'ĐVT', render: item => escape(item.sku?.unit || '-') },
            { label: 'Số lượng', render: item => money(item.quantity) },
            { label: 'Đơn giá', render: item => money(item.unit_price) },
            { label: 'Thành tiền', render: item => money(item.line_total) },
        ], items, 'Chưa có dòng hàng')}</section>`;
    }

    function paymentHistory(rows) {
        return `<section class="record-panel"><h3>Lịch sử thanh toán (${rows.length})</h3>${VKTable.renderTable([
            { label: 'Phiếu thu', render: row => mono(row.code) },
            { label: 'Ngày thu', render: row => formatDateTime(row.paid_at) },
            { label: 'Phương thức', render: row => escape(paymentMethod(row.payment_method)) },
            { label: 'Tham chiếu', render: row => escape(row.reference_no || '-') },
            { label: 'Người ghi nhận', render: row => escape(row.received_by?.name || '-') },
            { label: 'Số tiền', render: row => `<strong>${money(row.amount)}</strong>` },
        ], rows, 'Chưa có lần thanh toán')}</section>`;
    }

    function summary(items) {
        return `<section class="fulfillment-summary">${items.map(([label, value, note, tone]) => `
            <div class="fulfillment-summary-item ${tone || ''}">
                <span>${escape(label)}</span><strong>${escape(value)}</strong><small>${escape(note)}</small>
            </div>
        `).join('')}</section>`;
    }

    function restoreFilters(context) {
        const search = document.querySelector('[data-list-search]');
        const statusNode = document.querySelector('[data-list-status]');
        const method = document.querySelector('[data-payment-method]');
        if (search) search.value = states[context].q || '';
        if (statusNode) statusNode.value = states[context].status || '';
        if (method) method.value = states.payments.method || '';
    }

    function currentContext() {
        if (document.getElementById('deliveriesRoot')) return 'deliveries';
        if (document.getElementById('salesInvoicesRoot')) return 'invoices';
        if (document.getElementById('customerPaymentsRoot')) return 'payments';
        return null;
    }

    function rootFor(context) {
        return document.getElementById({
            deliveries: 'deliveriesRoot',
            invoices: 'salesInvoicesRoot',
            payments: 'customerPaymentsRoot',
        }[context]);
    }

    function loading() {
        return '<div class="record-loading"><span></span><span></span><span></span></div>';
    }

    function showError(root, title, error) {
        root.innerHTML = `<div class="empty"><strong>${escape(title)}</strong><span>${escape(error.message || 'Vui lòng thử lại.')}</span></div>`;
    }

    function dueDate(row) {
        if (!row.due_date) return '-';
        const overdue = row.status !== 'paid' && new Date(row.due_date) < startOfToday();
        return `<span class="${overdue ? 'text-danger' : ''}">${formatDate(row.due_date)}${overdue ? ' · Quá hạn' : ''}</span>`;
    }

    function startOfToday() {
        const date = new Date();
        date.setHours(0, 0, 0, 0);
        return date;
    }

    function localDateTimeValue() {
        const date = new Date();
        return new Date(date.getTime() - date.getTimezoneOffset() * 60000).toISOString().slice(0, 16);
    }

    function dateValue(date) {
        return new Date(date.getTime() - date.getTimezoneOffset() * 60000).toISOString().slice(0, 10);
    }

    function paymentMethod(value) {
        return {
            bank_transfer: 'Chuyển khoản',
            cash: 'Tiền mặt',
            card: 'Thẻ',
            other: 'Khác',
        }[value] || value || '-';
    }

    function formatDate(value) {
        return value ? new Date(value).toLocaleDateString('vi-VN') : '-';
    }

    function formatDateTime(value) {
        return value ? new Date(value).toLocaleString('vi-VN', {
            hour: '2-digit', minute: '2-digit', day: '2-digit', month: '2-digit', year: 'numeric',
        }) : '-';
    }

    function status(value) {
        return VKTable.statusBadge(value || '-');
    }

    function money(value) {
        return VKTable.money(value || 0);
    }

    function mono(value) {
        return `<span class="mono">${escape(value)}</span>`;
    }

    function escape(value) {
        return VKTable.escapeHtml(value ?? '');
    }

    function can(permission) {
        return window.VKLayout?.hasPermission?.(permission) ?? true;
    }

    window.loadDeliveries = loadDeliveries;
    window.loadSalesInvoices = loadSalesInvoices;
    window.loadCustomerPayments = loadCustomerPayments;
})();
