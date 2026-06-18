(function () {
    const labels = {
        lead: 'Khách hàng tiềm năng',
        quotation: 'Báo giá',
        salesOrder: 'Đơn bán',
        purchaseRequest: 'Yêu cầu mua',
        purchaseOrder: 'Đơn mua',
        goodsReceipt: 'Phiếu nhập kho',
        goodsIssue: 'Phiếu xuất kho',
        task: 'Công việc',
        alert: 'Cảnh báo',
        customer: 'Khách hàng',
        supplier: 'Nhà cung cấp',
        sku: 'Mã hàng',
        user: 'Người dùng',
    };
    const state = { config: null, row: null, tab: 'info' };

    function qs(selector) {
        return document.querySelector(selector);
    }

    function escapeHtml(value) {
        return VKTable.escapeHtml(value ?? '');
    }

    async function open(config) {
        const drawer = getElements();
        if (!drawer.backdrop) return;

        state.config = config;
        state.row = null;
        state.tab = 'info';
        drawer.backdrop.classList.add('open');
        drawer.kicker.textContent = config.kicker || labels[config.type] || 'Chi tiết';
        drawer.title.textContent = 'Đang tải...';
        drawer.body.innerHTML = renderLoading();

        try {
            const response = config.path ? await VKApi.request(config.path) : { data: config.row };
            state.row = response.data;
            render();
        } catch (error) {
            drawer.title.textContent = 'Không tải được chi tiết';
            drawer.body.innerHTML = `<div class="empty"><strong>Có lỗi xảy ra</strong><span>${escapeHtml(error.message)}</span></div>`;
        }
    }

    function render() {
        const drawer = getElements();
        const row = state.row || {};
        if (drawer.panel) drawer.panel.dataset.detailType = state.config?.type || '';
        drawer.title.textContent = row.code || row.name || row.title || `#${row.id}`;
        drawer.kicker.textContent = state.config?.kicker || labels[state.config?.type] || 'Chi tiết';
        drawer.body.innerHTML = `
            ${state.config?.type === 'quotation' ? renderQuotationHeader(row) : ''}
            ${renderTabs()}
            <div class="detail-drawer-body">
                ${renderTabContent()}
            </div>
        `;
    }

    function renderTabs() {
        const tabs = state.config?.type === 'quotation' ? [
            ['info', 'Tổng quan'],
            ['items', `Dòng hàng (${state.row?.items?.length || 0})`],
            ['history', 'Lịch sử'],
            ['tasks', 'Công việc'],
            ['notes', 'Ghi chú'],
        ] : state.config?.type === 'task' ? [
            ['info', 'Thông tin'],
            ['history', 'Lịch sử'],
            ['files', 'Tệp đính kèm'],
            ['notes', 'Ghi chú'],
        ] : [
            ['info', 'Thông tin'],
            ['history', 'Lịch sử'],
            ['tasks', 'Công việc'],
            ['notes', 'Ghi chú'],
        ];
        return `<div class="detail-tabs">${tabs.map(([key, label]) => `
            <button class="detail-tab ${state.tab === key ? 'active' : ''}" type="button" data-detail-tab="${key}">${label}</button>
        `).join('')}</div>`;
    }

    function renderTabContent() {
        if (state.tab === 'history') return state.config?.type === 'task' ? renderTaskHistory(state.row?.history || []) : renderTimeline(state.row?.timeline || []);
        if (state.tab === 'tasks') return renderRelated('Công việc liên quan', state.row?.tasks || [], 'task_type');
        if (state.tab === 'items') return `${renderQuotationItems(state.row?.items || [])}${renderQuotationTotals(state.row || {})}`;
        if (state.tab === 'files') return renderFiles();
        if (state.tab === 'notes') return renderNotes();
        if (state.config?.type === 'quotation') return renderQuotationDetail(state.row || {});

        return `
            ${renderOverview(state.config?.type, state.row || {})}
            ${renderNextActions(state.config?.type, state.row || {})}
            ${renderLinkedDocuments(state.row?.related_documents || [])}
            ${renderFlowHint(state.config?.type, state.row || {})}
            ${renderItems(state.row?.items || [])}
            ${renderRelated('Cảnh báo liên quan', state.row?.alerts || [], 'alert_type')}
        `;
    }

    function renderQuotationDetail(row) {
        return `
            ${renderQuotationFinance(row)}
            <div class="quotation-overview-columns">
                ${renderQuotationMain(row)}
                ${renderQuotationFlow(row)}
            </div>
            ${renderQuotationActions(row)}
            ${row.related_documents?.length ? renderLinkedDocuments(row.related_documents) : ''}
            ${renderQuotationItems(row.items || [])}
            ${renderQuotationTotals(row)}
            ${row.alerts?.length ? renderRelated('Cảnh báo liên quan', row.alerts, 'alert_type') : ''}
        `;
    }

    function renderQuotationHeader(row) {
        const customer = row.customer || {};
        const contact = [customer.contact_name, customer.phone, customer.email].filter(Boolean).join(' · ');

        return `
            <section class="quotation-header-summary">
                <div class="quotation-customer">
                    <div>
                        <strong>${escapeHtml(customer.name || 'Chưa có khách hàng')}</strong>
                        ${VKTable.statusBadge(row.status || 'draft')}
                    </div>
                    <span>${escapeHtml(contact || customer.code || '-')}</span>
                </div>
                <div>
                    <span>Ngày tạo</span>
                    <strong>${formatDate(row.created_at)}</strong>
                </div>
                <div>
                    <span>Cập nhật</span>
                    <strong>${formatDate(row.updated_at)}</strong>
                </div>
            </section>
        `;
    }

    function renderQuotationMain(row) {
        const approval = row.approval || {};
        const approvalText = approval.status
            ? `${VKTable.translateStatus(approval.status)}${approval.reason ? ` - ${approval.reason}` : ''}`
            : (Number(row.margin_percent || 0) < 15 ? 'Cần duyệt biên lợi nhuận' : 'Không cần duyệt thêm');
        const fields = [
            { label: 'Mã báo giá', value: row.code || '-' },
            { label: 'Khách hàng', value: row.customer?.name || '-' },
            { label: 'Mã khách hàng', value: row.customer?.code || '-' },
            { label: 'Người phụ trách', value: row.sales_owner?.name || row.salesOwner?.name || '-' },
            { label: 'Trạng thái', value: VKTable.statusBadge(row.status || 'draft'), html: true },
            { label: 'Duyệt biên lợi nhuận', value: approvalText },
        ];

        return `
            <section class="quotation-overview-panel">
                <h3>Thông tin chi tiết</h3>
                <div class="quotation-info-list">
                    ${fields.map(field => `
                        <div>
                            <span>${escapeHtml(field.label)}</span>
                            <strong>${field.html ? field.value : escapeHtml(field.value)}</strong>
                        </div>
                    `).join('')}
                </div>
            </section>
        `;
    }

    function renderQuotationFlow(row) {
        const steps = row.timeline || [];

        return `
            <section class="quotation-overview-panel">
                <h3>Luồng xử lý</h3>
                <div class="quotation-flow">
                    ${steps.map(item => `
                        <div class="${statusClass(item.status)}">
                            <i></i>
                            <span>
                                <strong>${escapeHtml(item.label)}</strong>
                                <small>${formatDate(item.at)}</small>
                            </span>
                            <em>${escapeHtml(VKTable.translateStatus(item.status))}</em>
                        </div>
                    `).join('')}
                </div>
            </section>
        `;
    }

    function renderQuotationFinance(row) {
        const subtotal = Number(row.subtotal_amount || row.total_amount || 0);
        const tax = Number(row.tax_amount || 0);
        const amount = Number(row.total_amount || subtotal + tax);
        const cost = Number(row.total_cost || 0);
        const margin = Number(row.margin_percent || 0);
        const grossProfit = subtotal - cost;

        return `
            <section class="detail-section quotation-finance-section">
                <h3>Tài chính báo giá</h3>
                <div class="quotation-finance-grid">
                    ${financeBox('Tiền trước thuế', VKTable.money(subtotal), 'Giá trị hàng hóa')}
                    ${financeBox('Thuế VAT', VKTable.money(tax), 'Tổng thuế các dòng')}
                    ${financeBox('Tổng thanh toán', VKTable.money(amount), 'Đã gồm VAT')}
                    ${financeBox('Giá vốn', VKTable.money(cost), 'Tổng chi phí hàng')}
                    ${financeBox('Lợi nhuận gộp', VKTable.money(grossProfit), grossProfit >= 0 ? 'Dương' : 'Âm', grossProfit < 0 ? 'risk' : 'good')}
                    ${financeBox('Biên lợi nhuận', `${margin.toFixed(2)}%`, margin < 15 ? 'Cần duyệt' : 'Đạt ngưỡng', margin < 15 ? 'risk' : 'good')}
                </div>
            </section>
        `;
    }

    function financeBox(label, value, note, tone = '') {
        return `
            <div class="quotation-finance-box ${tone}">
                <span>${escapeHtml(label)}</span>
                <strong>${escapeHtml(value)}</strong>
                <small>${escapeHtml(note)}</small>
            </div>
        `;
    }

    function renderQuotationActions(row) {
        const actions = nextActions('quotation', row).filter(Boolean);
        if (!actions.length) return '';

        return `
            <section class="detail-section quotation-actions">
                <h3>Hành động</h3>
                <div>
                    ${actions.map(action => `
                        <button class="btn ${action.variant || ''}" type="button" data-flow-action="${action.action}">${escapeHtml(action.label)}</button>
                    `).join('')}
                </div>
            </section>
        `;
    }

    function renderQuotationItems(items) {
        if (!items.length) return '';

        return `
            <section class="detail-section">
                <h3>Dòng hàng</h3>
                ${VKTable.renderTable([
                    { label: 'Mã hàng', render: item => `<span class="mono">${escapeHtml(item.sku?.sku_code || '-')}</span>` },
                    { label: 'Tên hàng', render: item => escapeHtml(item.sku?.name || '-') },
                    { label: 'ĐVT', render: item => escapeHtml(item.sku?.unit || '-') },
                    { label: 'Số lượng', render: item => VKTable.money(item.quantity) },
                    { label: 'Đơn giá', render: item => VKTable.money(item.unit_price) },
                    { label: 'VAT', render: item => `${Number(item.vat_rate || 0).toFixed(0)}%` },
                    { label: 'Thành tiền', render: item => VKTable.money(item.line_total) },
                ], items, 'Chưa có dòng hàng')}
            </section>
        `;
    }

    function renderQuotationTotals(row) {
        const subtotal = Number(row.subtotal_amount || row.total_amount || 0);
        const tax = Number(row.tax_amount || 0);
        const amount = Number(row.total_amount || subtotal + tax);
        const cost = Number(row.total_cost || 0);
        const profit = subtotal - cost;

        return `
            <section class="quotation-totals">
                <div><span>Tiền trước thuế</span><strong>${VKTable.money(subtotal)}</strong></div>
                <div><span>Thuế VAT</span><strong>${VKTable.money(tax)}</strong></div>
                <div><span>Giá vốn</span><strong>${VKTable.money(cost)}</strong></div>
                <div><span>Lợi nhuận gộp</span><strong>${VKTable.money(profit)}</strong></div>
                <div><span>Biên lợi nhuận</span><strong>${Number(row.margin_percent || 0).toFixed(2)}%</strong></div>
                <div class="grand-total"><span>Tổng cộng</span><strong>${VKTable.money(amount)}</strong><small>VND</small></div>
            </section>
        `;
    }

    function renderLinkedDocuments(rows) {
        if (!rows.length) return '';

        return `
            <section class="detail-section">
                <h3>Chứng từ liên quan</h3>
                <div class="linked-docs">
                    ${rows.map((row, index) => `
                        <button class="linked-doc" type="button" data-linked-doc="${index}">
                            <span>
                                <strong>${escapeHtml(row.label || labels[row.type] || 'Chứng từ')}</strong>
                                <small>${escapeHtml(row.code || '-')}</small>
                            </span>
                            ${VKTable.statusBadge(row.status || 'active')}
                        </button>
                    `).join('')}
                </div>
            </section>
        `;
    }

    function renderNextActions(type, row) {
        const actions = nextActions(type, row).filter(Boolean);
        if (!actions.length) return '';

        return `
            <section class="detail-section next-actions">
                <div>
                    <h3>Bước tiếp theo</h3>
                    <p>${escapeHtml(nextActionHint(type, row))}</p>
                </div>
                <div class="next-action-buttons">
                    ${actions.map(action => `
                        <button class="btn ${action.variant || ''}" type="button" data-flow-action="${action.action}">${escapeHtml(action.label)}</button>
                    `).join('')}
                </div>
            </section>
        `;
    }

    function nextActions(type, row) {
        const can = window.VKLayout?.hasPermission || (() => false);
        const actions = {
            quotation: [
                row.status === 'pending_approval' && can('sales.margin.approve') ? { action: 'approve-quotation', label: 'Duyệt báo giá', variant: 'primary' } : null,
                row.status === 'pending_approval' && can('sales.margin.approve') ? { action: 'reject-quotation', label: 'Từ chối', variant: 'danger' } : null,
                ['ready', 'approved'].includes(row.status) && can('sales.order.create') ? { action: 'create-sales-order', label: 'Tạo đơn bán', variant: 'primary' } : null,
            ],
            salesOrder: [
                row.status === 'draft' && can('sales.order.create') ? { action: 'confirm-sales-order', label: 'Xác nhận kiểm tồn', variant: 'primary' } : null,
                row.stock_status === 'reserved' && row.status !== 'completed' && can('inventory.issue.confirm') ? { action: 'create-goods-issue', label: 'Tạo phiếu xuất', variant: 'primary' } : null,
                row.stock_status === 'shortage' && can('procurement.pr.approve') ? { action: 'open-purchase-requests', label: 'Mở yêu cầu mua' } : null,
            ],
            purchaseRequest: [
                row.status === 'draft' && can('procurement.pr.approve') ? { action: 'approve-purchase-request', label: 'Duyệt yêu cầu mua', variant: 'primary' } : null,
                row.status === 'approved' && can('procurement.po.approve') ? { action: 'create-purchase-order', label: 'Tạo đơn mua', variant: 'primary' } : null,
            ],
            purchaseOrder: [
                row.status === 'draft' && can('procurement.po.approve') ? { action: 'approve-purchase-order', label: 'Duyệt đơn mua', variant: 'primary' } : null,
                row.status === 'approved' && can('inventory.receipt.confirm') ? { action: 'create-goods-receipt', label: 'Tạo phiếu nhập', variant: 'primary' } : null,
            ],
            goodsReceipt: [
                row.status === 'draft' && can('inventory.receipt.confirm') ? { action: 'confirm-goods-receipt', label: 'Xác nhận nhập kho', variant: 'primary' } : null,
            ],
            goodsIssue: [
                row.status === 'draft' && can('inventory.issue.confirm') ? { action: 'confirm-goods-issue', label: 'Xác nhận xuất kho', variant: 'primary' } : null,
            ],
        };

        return actions[type] || [];
    }

    function nextActionHint(type, row) {
        if (type === 'quotation' && row.status === 'pending_approval') return 'Báo giá đang chờ duyệt biên lợi nhuận trước khi tạo đơn bán.';
        if (type === 'quotation') return 'Báo giá đã sẵn sàng để chuyển thành đơn bán nếu khách chốt.';
        if (type === 'salesOrder' && row.status === 'draft') return 'Xác nhận đơn để hệ thống kiểm tồn, giữ hàng hoặc tự sinh yêu cầu mua.';
        if (type === 'salesOrder' && row.stock_status === 'shortage') return 'Đơn đang thiếu tồn, cần xử lý yêu cầu mua phát sinh.';
        if (type === 'salesOrder') return 'Hàng đã giữ, kho có thể tạo phiếu xuất và hoàn tất đơn.';
        if (type === 'purchaseRequest') return row.status === 'draft' ? 'Duyệt nhu cầu mua trước khi lập đơn mua.' : 'Yêu cầu đã duyệt, có thể chọn nhà cung cấp để tạo đơn mua.';
        if (type === 'purchaseOrder') return row.status === 'draft' ? 'Đơn mua cần duyệt trước khi nhập kho.' : 'Đơn mua đã duyệt, kho có thể tạo phiếu nhập.';
        if (type === 'goodsReceipt') return 'Xác nhận nhập kho để cập nhật tồn và tự giữ hàng cho đơn bán thiếu tồn nếu đủ.';
        if (type === 'goodsIssue') return 'Xác nhận xuất kho để trừ hàng đã giữ và hoàn tất đơn bán.';
        return 'Chọn thao tác phù hợp để đẩy luồng sang bước tiếp theo.';
    }

    function renderOverview(type, row) {
        const fields = overviewFields(type, row);
        return `
            <section class="detail-section">
                <h3>Thông tin chính</h3>
                <div class="detail-grid">
                    ${fields.map(field => `
                        <div class="detail-field">
                            <span>${escapeHtml(field.label)}</span>
                            <strong>${field.html ? field.value : escapeHtml(field.value)}</strong>
                        </div>
                    `).join('')}
                </div>
            </section>
        `;
    }

    function overviewFields(type, row) {
        const common = [
            { label: 'Mã', value: row.code || row.id || '-' },
            { label: 'Trạng thái', value: VKTable.statusBadge(row.status || row.stock_status || 'active'), html: true },
        ];

        const byType = {
            customer: [
                { label: 'Tên khách hàng', value: row.name || '-' },
                { label: 'Mã số thuế', value: row.tax_code || 'Chưa khai báo' },
                { label: 'Địa chỉ hóa đơn', value: row.billing_address || 'Chưa khai báo' },
                { label: 'Người liên hệ', value: row.contact_name || 'Chưa khai báo' },
                { label: 'Số điện thoại', value: row.phone || 'Chưa khai báo' },
                { label: 'Email', value: row.email || 'Chưa khai báo' },
                { label: 'Địa chỉ', value: row.address || 'Chưa khai báo' },
            ],
            salesOrder: [
                { label: 'Khách hàng', value: row.customer?.name || '-' },
                { label: 'Tình trạng tồn', value: VKTable.statusBadge(row.stock_status), html: true },
                { label: 'Tổng tiền', value: VKTable.money(row.total_amount) },
            ],
            purchaseRequest: [
                { label: 'Nguồn phát sinh', value: sourceLabel(row.source_type, row.source_id) },
                { label: 'Lý do', value: row.reason || '-' },
            ],
            purchaseOrder: [
                { label: 'Yêu cầu mua', value: row.purchase_request?.code || row.purchaseRequest?.code || '-' },
                { label: 'Nhà cung cấp', value: row.supplier?.name || '-' },
                { label: 'Ngày dự kiến nhận', value: formatDate(row.expected_delivery_date) },
                { label: 'Tổng tiền', value: VKTable.money(row.total_amount) },
            ],
            goodsReceipt: [
                { label: 'Đơn mua', value: row.purchase_order?.code || row.purchaseOrder?.code || '-' },
                { label: 'Kho nhận', value: row.warehouse?.name || '-' },
                { label: 'Ngày xác nhận', value: formatDate(row.confirmed_at) },
            ],
            goodsIssue: [
                { label: 'Đơn bán', value: row.sales_order?.code || row.salesOrder?.code || '-' },
                { label: 'Kho xuất', value: row.warehouse?.name || '-' },
                { label: 'Ngày xác nhận', value: formatDate(row.confirmed_at) },
            ],
            task: [
                { label: 'Phụ trách', value: row.assignee?.name || 'Chưa phân công' },
                { label: 'Phòng ban', value: row.department?.name || 'Chưa gắn phòng ban' },
                { label: 'Ưu tiên', value: VKTable.statusBadge(row.priority), html: true },
                { label: 'Hạn xử lý', value: formatDate(row.due_at) },
                { label: 'Loại công việc', value: VKTable.translateType(row.task_type) },
                { label: 'Nguồn', value: sourceLabel(row.source_type, row.source_id) },
            ],
        };

        return [...common, ...(byType[type] || []), { label: 'Ngày tạo', value: formatDate(row.created_at) }];
    }

    function renderFlowHint(type, row) {
        const hints = {
            salesOrder: [
                ['Tạo đơn', row.created_at, 'completed'],
                ['Kiểm tồn', row.updated_at, row.stock_status],
                ['Xuất kho', row.updated_at, row.status === 'completed' ? 'completed' : 'pending'],
            ],
            purchaseRequest: [
                ['Tạo yêu cầu mua', row.created_at, 'completed'],
                ['Duyệt yêu cầu mua', row.updated_at, row.status === 'approved' ? 'approved' : 'pending'],
                ['Lập đơn mua', row.updated_at, row.status === 'approved' ? 'pending' : 'inactive'],
            ],
            purchaseOrder: [
                ['Tạo đơn mua', row.created_at, 'completed'],
                ['Duyệt đơn mua', row.updated_at, row.status === 'draft' ? 'pending' : 'approved'],
                ['Nhập kho', row.updated_at, row.status === 'received' ? 'completed' : 'pending'],
            ],
            goodsReceipt: [
                ['Tạo phiếu nhập kho', row.created_at, 'completed'],
                ['Xác nhận nhập kho', row.confirmed_at || row.updated_at, row.status],
                ['Cập nhật tồn kho', row.confirmed_at, row.status === 'confirmed' ? 'completed' : 'pending'],
            ],
            goodsIssue: [
                ['Tạo phiếu xuất kho', row.created_at, 'completed'],
                ['Xác nhận xuất kho', row.confirmed_at || row.updated_at, row.status],
                ['Hoàn tất giao hàng', row.confirmed_at, row.status === 'confirmed' ? 'completed' : 'pending'],
            ],
            task: [
                ['Tạo việc', row.created_at, 'completed'],
                ['Bắt đầu xử lý', row.updated_at, ['in_progress', 'completed'].includes(row.status) ? 'in_progress' : 'pending'],
                ['Hoàn thành', row.updated_at, row.status === 'completed' ? 'completed' : 'pending'],
            ],
        }[type];

        if (!hints) return '';

        return `
            <section class="detail-section">
                <h3>Luồng xử lý</h3>
                <div class="flow-steps">
                    ${hints.map(([label, at, status]) => `
                        <div class="flow-step ${statusClass(status)}">
                            <span></span>
                            <strong>${escapeHtml(label)}</strong>
                            <small>${formatDate(at)}</small>
                        </div>
                    `).join('')}
                </div>
            </section>
        `;
    }

    function renderItems(items) {
        if (!items.length) return '';
        return `
            <section class="detail-section">
                <h3>Dòng hàng</h3>
                ${VKTable.renderTable([
                    { label: 'Mã hàng', render: item => `<span class="mono">${escapeHtml(item.sku?.sku_code || '-')}</span>` },
                    { label: 'Tên hàng', render: item => escapeHtml(item.sku?.name || '-') },
                    { label: 'Số lượng', render: item => VKTable.money(item.quantity) },
                    { label: 'Đơn giá', render: item => item.unit_price !== undefined ? VKTable.money(item.unit_price) : '-' },
                ], items, 'Chưa có dòng hàng')}
            </section>
        `;
    }

    function renderTimeline(timeline) {
        if (!timeline.length) {
            return '<section class="detail-section"><h3>Lịch sử</h3><div class="empty"><strong>Chưa có lịch sử</strong><span>Dữ liệu trạng thái sẽ hiển thị tại đây.</span></div></section>';
        }

        return `
            <section class="detail-section">
                <h3>Lịch sử trạng thái</h3>
                <div class="detail-timeline">
                    ${timeline.map(item => `
                        <div class="timeline-item ${statusClass(item.status)}">
                            <span class="timeline-dot"></span>
                            <div class="timeline-content">
                                <strong>${escapeHtml(item.label)}</strong>
                                <span>${VKTable.translateStatus(item.status)}${item.at ? ` - ${formatDate(item.at)}` : ''}</span>
                            </div>
                        </div>
                    `).join('')}
                </div>
            </section>
        `;
    }

    function renderTaskHistory(rows) {
        if (!rows.length) {
            return '<section class="detail-section"><h3>Lịch sử</h3><div class="empty"><strong>Chưa có lịch sử</strong><span>Các lần đổi trạng thái sẽ hiển thị tại đây.</span></div></section>';
        }

        return `
            <section class="detail-section">
                <h3>Hoạt động gần đây</h3>
                <div class="detail-timeline">
                    ${rows.map(item => `
                        <div class="timeline-item ${statusClass(item.to_status)}">
                            <span class="timeline-dot"></span>
                            <div class="timeline-content">
                                <strong>${VKTable.translateStatus(item.from_status || 'new')} → ${VKTable.translateStatus(item.to_status)}</strong>
                                <span>${escapeHtml(item.reason || 'Cập nhật trạng thái')}${item.created_at ? ` - ${formatDate(item.created_at)}` : ''}</span>
                            </div>
                        </div>
                    `).join('')}
                </div>
            </section>
        `;
    }

    function renderRelated(title, rows, typeField) {
        return `
            <section class="detail-section">
                <h3>${escapeHtml(title)}</h3>
                ${rows.length ? `<div class="detail-list">
                    ${rows.map(row => `
                        <div class="detail-list-item">
                            <strong>${escapeHtml(row.title || row.code || row.alert_type || '-')}</strong>
                            <span>${VKTable.translateType(row[typeField])} - ${VKTable.translateStatus(row.status)}</span>
                            ${row.message || row.description ? `<span>${escapeHtml(row.message || row.description)}</span>` : ''}
                        </div>
                    `).join('')}
                </div>` : '<div class="empty"><strong>Chưa có dữ liệu liên quan</strong><span>Khi phát sinh dữ liệu, hệ thống sẽ hiển thị tại đây.</span></div>'}
            </section>
        `;
    }

    function renderNotes() {
        if (state.config?.type === 'task' && state.row?.description) {
            return `<section class="detail-section"><h3>Mô tả</h3><div class="detail-list-item"><span>${escapeHtml(state.row.description)}</span></div></section>`;
        }
        return '<section class="detail-section"><h3>Ghi chú</h3><div class="empty"><strong>Chưa có ghi chú</strong><span>Khi có API ghi chú, dữ liệu sẽ hiển thị tại đây.</span></div></section>';
    }

    function renderFiles() {
        return '<section class="detail-section"><h3>Tệp đính kèm</h3><div class="empty"><strong>Chưa có tệp đính kèm</strong><span>Chức năng tệp công việc sẽ được bổ sung khi có API lưu file.</span></div></section>';
    }

    function close() {
        const drawer = getElements();
        drawer.backdrop?.classList.remove('open');
        if (drawer.panel) delete drawer.panel.dataset.detailType;
    }

    function renderLoading() {
        return '<div class="detail-drawer-body"><div class="empty"><strong>Đang tải dữ liệu</strong><span>Vui lòng chờ trong giây lát.</span></div></div>';
    }

    function getElements() {
        return {
            backdrop: qs('[data-detail-drawer-backdrop]'),
            panel: qs('.detail-drawer'),
            title: qs('[data-detail-title]'),
            kicker: qs('[data-detail-kicker]'),
            body: qs('[data-detail-body]'),
        };
    }

    function statusClass(status) {
        const key = String(status || '').toLowerCase();
        if (['completed', 'confirmed', 'approved', 'received', 'issued', 'reserved'].includes(key)) return 'done';
        if (['pending', 'draft', 'in_progress', 'open', 'unchecked'].includes(key)) return 'waiting';
        if (['overdue', 'warning', 'shortage', 'rejected', 'cancelled'].includes(key)) return 'risk';
        return 'neutral';
    }

    function formatDate(value) {
        if (!value) return '-';
        const date = new Date(value);
        if (Number.isNaN(date.getTime())) return '-';
        return new Intl.DateTimeFormat('vi-VN', {
            day: '2-digit',
            month: '2-digit',
            year: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
        }).format(date);
    }

    function sourceLabel(type, id) {
        return `${VKTable.translateEntity(type)}${id ? ` #${id}` : ''}`;
    }

    async function handleFlowAction(action, button) {
        if (!state.row?.id) return;
        button.disabled = true;
        try {
            const handlers = {
                'approve-quotation': () => approveQuotation('approved'),
                'reject-quotation': () => approveQuotation('rejected'),
                'create-sales-order': createSalesOrder,
                'confirm-sales-order': confirmSalesOrder,
                'open-purchase-requests': () => navigateTo('/purchase-requests'),
                'approve-purchase-request': approvePurchaseRequest,
                'create-purchase-order': openCreatePurchaseOrderModal,
                'approve-purchase-order': approvePurchaseOrder,
                'create-goods-receipt': openCreateGoodsReceiptModal,
                'confirm-goods-receipt': confirmGoodsReceipt,
                'create-goods-issue': openCreateGoodsIssueModal,
                'confirm-goods-issue': confirmGoodsIssue,
            };

            await handlers[action]?.();
        } catch (error) {
            VKModal.toast(error.message);
        } finally {
            button.disabled = false;
        }
    }

    async function approveQuotation(status) {
        await VKApi.request(`/quotations/${state.row.id}/approve`, {
            method: 'POST',
            body: JSON.stringify({ status, reason: status === 'approved' ? 'Duyệt trong luồng chi tiết' : 'Từ chối trong luồng chi tiết' }),
        });
        VKModal.toast(status === 'approved' ? 'Đã duyệt báo giá.' : 'Đã từ chối báo giá.');
        await reloadCurrentDetail();
    }

    async function createSalesOrder() {
        const response = await VKApi.request('/sales-orders', {
            method: 'POST',
            body: JSON.stringify({ quotation_id: Number(state.row.id) }),
        });
        VKModal.toast('Đã tạo đơn bán từ báo giá.');
        await open({ type: 'salesOrder', path: `/sales-orders/${response.data.id}` });
        notifyFlowUpdated();
    }

    async function confirmSalesOrder() {
        await VKApi.request(`/sales-orders/${state.row.id}/confirm`, { method: 'POST' });
        VKModal.toast('Đã xác nhận đơn bán.');
        await reloadCurrentDetail();
    }

    async function approvePurchaseRequest() {
        await VKApi.request(`/purchase-requests/${state.row.id}/approve`, {
            method: 'POST',
            body: JSON.stringify({ reason: 'Duyệt trong luồng chi tiết' }),
        });
        VKModal.toast('Đã duyệt yêu cầu mua.');
        await reloadCurrentDetail();
    }

    async function openCreatePurchaseOrderModal() {
        const suppliers = await VKApi.request('/suppliers');
        if (!suppliers.data?.length) {
            VKModal.toast('Chưa có nhà cung cấp để tạo đơn mua.');
            return;
        }

        VKModal.open('Tạo đơn mua', `
            <div class="form-grid">
                ${VKModal.select('supplier_id', 'Nhà cung cấp', suppliers.data.map(row => ({ value: row.id, label: `${row.code} - ${row.name}` })))}
                ${VKModal.field('expected_delivery_date', 'Ngày dự kiến nhận', 'date')}
            </div>
        `, async (form) => {
            const data = Object.fromEntries(new FormData(form));
            const response = await VKApi.request('/purchase-orders', {
                method: 'POST',
                body: JSON.stringify({
                    purchase_request_id: Number(state.row.id),
                    supplier_id: Number(data.supplier_id),
                    expected_delivery_date: data.expected_delivery_date || null,
                }),
            });
            VKModal.toast('Đã tạo đơn mua.');
            VKModal.close();
            await open({ type: 'purchaseOrder', path: `/purchase-orders/${response.data.id}` });
            notifyFlowUpdated();
        });
    }

    async function approvePurchaseOrder() {
        await VKApi.request(`/purchase-orders/${state.row.id}/approve`, {
            method: 'POST',
            body: JSON.stringify({ reason: 'Duyệt trong luồng chi tiết' }),
        });
        VKModal.toast('Đã duyệt đơn mua.');
        await reloadCurrentDetail();
    }

    async function openCreateGoodsReceiptModal() {
        const warehouses = await VKApi.request('/warehouses');
        if (!warehouses.data?.length) {
            VKModal.toast('Chưa có kho nhận hàng.');
            return;
        }

        VKModal.open('Tạo phiếu nhập kho', `
            <div class="form-grid">
                ${VKModal.select('warehouse_id', 'Kho nhận', warehouses.data.map(row => ({ value: row.id, label: `${row.code} - ${row.name}` })))}
            </div>
        `, async (form) => {
            const data = Object.fromEntries(new FormData(form));
            const response = await VKApi.request('/goods-receipts', {
                method: 'POST',
                body: JSON.stringify({ purchase_order_id: Number(state.row.id), warehouse_id: Number(data.warehouse_id) }),
            });
            VKModal.toast('Đã tạo phiếu nhập kho.');
            VKModal.close();
            await open({ type: 'goodsReceipt', path: `/goods-receipts/${response.data.id}` });
            notifyFlowUpdated();
        });
    }

    async function confirmGoodsReceipt() {
        try {
            const response = await VKApi.request(`/goods-receipts/${state.row.id}/confirm`, { method: 'POST' });
            VKModal.notice({
                type: 'success',
                title: 'Nhập kho thành công',
                message: 'Đã xác nhận nhập kho và cập nhật tồn kho.',
                details: [`Phiếu nhập: ${response.data?.code || state.row.code || ''}`],
            });
            await reloadCurrentDetail();
        } catch (error) {
            VKModal.notice({
                type: 'danger',
                title: 'Không thể xác nhận nhập kho',
                message: error.message || 'Có lỗi xảy ra khi xác nhận phiếu nhập.',
            });
        }
    }

    async function openCreateGoodsIssueModal() {
        const warehouses = await VKApi.request('/warehouses');
        if (!warehouses.data?.length) {
            VKModal.toast('Chưa có kho xuất hàng.');
            return;
        }

        VKModal.open('Tạo phiếu xuất kho', `
            <div class="form-grid">
                ${VKModal.select('warehouse_id', 'Kho xuất', warehouses.data.map(row => ({ value: row.id, label: `${row.code} - ${row.name}` })))}
            </div>
        `, async (form) => {
            const data = Object.fromEntries(new FormData(form));
            const response = await VKApi.request('/goods-issues', {
                method: 'POST',
                body: JSON.stringify({ sales_order_id: Number(state.row.id), warehouse_id: Number(data.warehouse_id) }),
            });
            VKModal.toast('Đã tạo phiếu xuất kho.');
            VKModal.close();
            await open({ type: 'goodsIssue', path: `/goods-issues/${response.data.id}` });
            notifyFlowUpdated();
        });
    }

    async function confirmGoodsIssue() {
        try {
            const response = await VKApi.request(`/goods-issues/${state.row.id}/confirm`, { method: 'POST' });
            VKModal.notice({
                type: 'success',
                title: 'Xuất kho thành công',
                message: 'Đã xác nhận xuất kho, trừ hàng đã giữ và cập nhật đơn bán liên quan.',
                details: [`Phiếu xuất: ${response.data?.code || state.row.code || ''}`],
            });
            await reloadCurrentDetail();
        } catch (error) {
            VKModal.notice({
                type: 'danger',
                title: 'Không thể xác nhận xuất kho',
                message: error.message || 'Có lỗi xảy ra khi xác nhận phiếu xuất.',
            });
        }
    }

    async function reloadCurrentDetail() {
        const path = state.config?.path;
        if (!path) {
            notifyFlowUpdated();
            return render();
        }

        const response = await VKApi.request(path);
        state.row = response.data;
        render();
        notifyFlowUpdated();
    }

    function notifyFlowUpdated() {
        document.dispatchEvent(new CustomEvent('vk:flow-updated', { detail: { type: state.config?.type, id: state.row?.id } }));
    }

    function navigateTo(path) {
        close();
        const link = document.querySelector(`#mainNav a[href="${path}"]`);
        if (link) {
            link.click();
            return;
        }
        window.location.href = path;
    }

    document.addEventListener('click', (event) => {
        if (event.target.matches('[data-detail-close]') || event.target.matches('[data-detail-drawer-backdrop]')) close();
        if (event.target.matches('[data-detail-tab]')) {
            state.tab = event.target.dataset.detailTab;
            render();
        }
        const linkedDoc = event.target.closest('[data-linked-doc]');
        if (linkedDoc) {
            const row = state.row?.related_documents?.[Number(linkedDoc.dataset.linkedDoc)];
            if (row?.path && row?.type) {
                open({ type: row.type, path: row.path });
            }
        }
        if (event.target.matches('[data-flow-action]')) {
            handleFlowAction(event.target.dataset.flowAction, event.target);
        }
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') close();
    });

    window.VKDetailDrawer = { open, close };
})();
