(function () {
    const labels = {
        lead: 'Khách hàng tiềm năng',
        supplier: 'Nhà cung cấp',
        sku: 'Mã hàng',
        user: 'Người dùng',
        alert: 'Cảnh báo',
        customer: 'Khách hàng',
        salesOrder: 'Đơn hàng',
        purchaseRequest: 'Yêu cầu mua',
        purchaseOrder: 'Đơn mua',
        goodsReceipt: 'Nhập kho',
        goodsIssue: 'Phiếu xuất kho',
        deal: 'Cơ hội bán hàng',
    };

    const tabLabels = {
        overview: 'Tổng quan',
        items: 'Dòng hàng',
        history: 'Lịch sử',
        tasks: 'Công việc',
        notes: 'Ghi chú',
    };

    const states = {};

    document.addEventListener('click', (event) => {
        const back = event.target.closest('[data-record-page-back]');
        if (back) {
            const key = back.dataset.recordPageBack;
            const state = states[key];
            if (state?.onBack) state.onBack();
            return;
        }

        const tab = event.target.closest('[data-record-page-tab]');
        if (tab) {
            const key = tab.dataset.recordPageKey;
            const state = states[key];
            if (!state) return;
            state.tab = tab.dataset.recordPageTab || 'overview';
            render(key);
        }
    });

    async function open(config) {
        const key = config.root;
        const root = document.querySelector(config.root);
        if (!root) return;

        const id = String(config.id);
        states[key] = {
            ...config,
            tab: 'overview',
            row: normalize(config.type, config.preview || {}),
            cache: config.cache || {},
        };

        render(key);

        try {
            const cached = states[key].cache[id];
            const row = cached || (config.path ? (await VKApi.request(config.path)).data : (config.preview || {}));
            states[key].cache[id] = row;
            states[key].row = normalize(config.type, row);
            render(key);
        } catch (error) {
            VKModal.toast(error.message || 'Không tải được chi tiết.');
        }
    }

    function render(key) {
        const state = states[key];
        const root = document.querySelector(key);
        if (!state || !root) return;
        const row = state.row || {};

        const pageClass = state.type === 'purchaseRequest'
            ? ' purchase-request-record-page'
            : (state.type === 'salesOrder' ? ' sales-order-record-page' : (state.type === 'goodsReceipt' ? ' goods-receipt-record-page' : (state.type === 'purchaseOrder' ? ' purchase-order-record-page' : '')));

        root.innerHTML = `
            <section class="record-page${pageClass}">
                ${state.type === 'salesOrder' ? '' : renderHead(key, state, row)}
                ${renderTabs(key, state, row)}
                <div class="record-page-body">
                    ${renderContent(state, row)}
                </div>
            </section>
        `;
    }

    function renderHead(key, state, row) {
        if (['sku', 'purchaseOrder', 'goodsReceipt'].includes(state.type)) return '';
        if (state.type === 'purchaseRequest') {
            return renderPurchaseRequestHead(key, state, row);
        }
        if (state.type === 'salesOrder') {
            return renderSalesOrderHead(key, state, row);
        }
        if (state.type === 'goodsReceipt') {
            return renderGoodsReceiptHead(key, state, row);
        }

        const title = labels[state.type] || 'Chi tiết';
        const primary = row.customer?.name
            || row.supplier?.name
            || row.warehouse?.name
            || row.product?.name
            || row.name
            || row.title
            || row.source_type
            || '-';
        return `
            <div class="record-page-head">
                <button class="btn small" type="button" data-record-page-back="${key}">Quay lại danh sách</button>
                <div class="record-page-title">
                    <span>${escape(title)}</span>
                    <h2>${escape(row.code || row.sku_code || row.name || row.title || '-')} ${VKTable.statusBadge(row.status || (row.is_active === false ? 'inactive' : 'active'))}</h2>
                    <p><strong>${escape(primary)}</strong>${row.stock_status ? ` · ${escape(VKTable.translateStatus(row.stock_status))}` : ''}</p>
                </div>
                <div class="record-page-meta">
                    <div><span>Ngày tạo</span><strong>${formatDateTime(row.created_at)}</strong></div>
                    <div><span>Cập nhật</span><strong>${formatDateTime(row.updated_at)}</strong></div>
                </div>
                <div class="record-page-actions">${state.actions ? state.actions(row) : ''}</div>
            </div>
        `;
    }

    function renderPurchaseRequestHead(key, state, row) {
        return `
            <div class="record-page-head purchase-request-head">
                <div class="purchase-request-breadcrumb">
                    <button type="button" data-record-page-back="${key}" aria-label="Quay lại danh sách">‹</button>
                    <span>Yêu cầu mua</span>
                    <i>›</i>
                    <strong>${escape(row.code || '-')}</strong>
                </div>
                <div class="record-page-actions">${state.actions ? state.actions(row) : ''}</div>
            </div>
        `;
    }

    function renderSalesOrderHead(key, state, row) {
        return `
            <div class="sales-order-detail-toolbar">
                <button class="btn small" type="button" data-record-page-back="${key}">Quay lại danh sách</button>
                <div class="record-page-actions">${state.actions ? state.actions(row) : ''}</div>
            </div>
        `;
    }

    function renderGoodsReceiptHead(key, state, row) {
        const order = row.purchase_order || {};
        const supplier = order.supplier || {};
        const receiptStatus = row.status === 'confirmed' ? 'Đã xác nhận nhập kho' : 'Chờ xác nhận nhập kho';
        return `
            <div class="record-page-head goods-receipt-head">
                <button class="btn small" type="button" data-record-page-back="${key}">Quay lại danh sách</button>
                <div class="record-page-title">
                    <span>Phiếu nhập kho</span>
                    <h2>${escape(row.code || '-')} ${VKTable.statusBadge(row.status || 'draft')}</h2>
                    <p><strong>${escape(row.warehouse?.name || 'Chưa chọn kho')}</strong> · ${escape(receiptStatus)}${supplier.name ? ` · ${escape(supplier.name)}` : ''}</p>
                </div>
                <div class="record-page-meta">
                    <div><span>Ngày tạo</span><strong>${formatDateTime(row.created_at)}</strong></div>
                    <div><span>Cập nhật</span><strong>${formatDateTime(row.updated_at)}</strong></div>
                </div>
                <div class="record-page-actions">${state.actions ? state.actions(row) : ''}</div>
            </div>
        `;
    }

    function renderTabs(key, state, row) {
        const simpleTypes = ['lead', 'supplier', 'sku', 'user', 'alert'];
        const tabs = state.type === 'salesOrder' ? [
            ['overview', tabLabels.overview],
            ['items', `${tabLabels.items} (${row.items?.length || 0})`],
            ['history', tabLabels.history],
            ['fulfillment', 'Giao hàng & thu tiền'],
            ['notes', tabLabels.notes],
        ] : simpleTypes.includes(state.type) ? [
            ['overview', tabLabels.overview],
            ['history', tabLabels.history],
            ['notes', tabLabels.notes],
        ] : [
            ['overview', tabLabels.overview],
            ['items', `${tabLabels.items} (${row.items?.length || 0})`],
            ['history', tabLabels.history],
            ['tasks', tabLabels.tasks],
            ['notes', tabLabels.notes],
        ];
        return `
            <div class="record-tabs">
                ${['salesOrder', 'purchaseOrder', 'goodsReceipt'].includes(state.type) ? `<button class="btn small" type="button" data-record-page-back="${key}">← Quay lại</button>` : ''}
                ${tabs.map(([tab, label]) => `
                    <button class="${state.tab === tab ? 'active' : ''}" type="button" data-record-page-key="${key}" data-record-page-tab="${tab}">
                        ${escape(label)}
                    </button>
                `).join('')}
                ${['sku', 'salesOrder', 'purchaseOrder', 'goodsReceipt'].includes(state.type) ? `<div class="record-page-actions" style="margin-left:auto">${state.actions ? state.actions(row) : ''}</div>` : ''}
            </div>
        `;
    }

    function renderContent(state, row) {
        if (state.type === 'customer') return renderCustomerContent(state, row);
        if (state.type === 'salesOrder') return renderSalesOrderContentV2(state, row);
        if (state.type === 'purchaseRequest') return renderPurchaseRequestContent(state, row);
        if (state.type === 'goodsReceipt') return renderGoodsReceiptContent(state, row);
        if (state.type === 'goodsIssue') return renderGoodsIssueContent(state, row);
        if (['lead', 'supplier', 'sku', 'user', 'alert'].includes(state.type)) {
            return renderSimpleEntityContent(state, row);
        }
        if (state.tab === 'items') return `${renderItems(row.items || [])}${renderTotals(row)}`;
        if (state.tab === 'history') return renderTimeline(row.timeline || []);
        if (state.tab === 'tasks') return renderRelated('Công việc liên quan', row.tasks || []);
        if (state.tab === 'notes') return renderNotes();

        return `
            ${renderMetrics(state.type, row)}
            ${state.type === 'purchaseOrder' && row.approval_flow?.length ? renderPurchaseApproval(row) : ''}
            <div class="record-page-grid">
                ${renderInfo(state.type, row)}
                ${renderFlow(row)}
            </div>
            ${renderLinkedDocuments(row.related_documents || [])}
            ${renderItems(row.items || [])}
            ${renderTotals(row)}
        `;
    }

    function renderPurchaseApproval(row) {
        const flow = row.approval_flow || [];
        const complete = flow.filter(step => step.decided_at).length;
        return `<section class="record-panel po-approval"><div class="po-approval-heading"><div><h3>Duyệt đơn mua <span>${complete}/${flow.length || '—'}</span></h3><p>${flow.length ? 'Đủ người duyệt nội bộ mới chuyển đến người duyệt cuối.' : 'Chưa thiết lập luồng nhiều người. Đơn đang dùng cơ chế duyệt một người.'}</p></div>${row.can_configure_approval && row.status === 'draft' && !complete ? `<button class="btn secondary small" data-configure-po-approval="${row.id}">Thiết lập người duyệt</button>` : ''}</div><div class="po-approval-people">${flow.map((step, index) => {
            const locked = step.final && flow.some(other => !other.final && !other.decided_at);
            return `<div class="po-approval-person ${step.decided_at ? 'approved' : locked ? 'locked' : 'pending'}"><i>${step.decided_at ? '✓' : locked ? '⌑' : index + 1}</i><div><strong>${escape(step.name)}</strong><small>${step.final ? 'Duyệt cuối · ' : ''}${step.decided_at ? formatDateTime(step.decided_at) : locked ? 'Chờ đủ các người duyệt trước' : 'Chờ duyệt'}</small></div></div>`;
        }).join('')}</div></section>`;
    }

    function renderGoodsReceiptContent(state, row) {
        if (state.tab === 'items') return renderGoodsReceiptItems(row.items || []);
        if (state.tab === 'history') return renderTimeline(row.timeline || []);
        if (state.tab === 'tasks') return renderRelated('Công việc liên quan', row.tasks || []);
        if (state.tab === 'notes') return renderNotes();

        const order = row.purchase_order || {};
        const supplier = order.supplier || {};
        const status = row.status === 'confirmed' ? 'Đã nhập kho' : 'Chờ xác nhận';
        return `
            <section class="goods-receipt-overview-banner ${row.status === 'confirmed' ? 'is-confirmed' : ''}">
                <div class="goods-receipt-overview-icon" aria-hidden="true">↓</div>
                <div class="goods-receipt-overview-copy">
                    <span>Phiếu nhận hàng</span>
                    <strong>${escape(status)}</strong>
                    <p>${row.status === 'confirmed' ? 'Hàng đã được ghi nhận vào tồn kho.' : 'Kiểm tra chứng từ và xác nhận số lượng thực nhận.'}</p>
                </div>
                <div class="goods-receipt-overview-chips">
                    <div><span>Kho nhận</span><strong>${escape(row.warehouse?.name || '-')}</strong></div>
                    <div><span>Đơn mua</span><strong>${escape(order.code || '-')}</strong></div>
                    <div><span>Số dòng</span><strong>${formatQuantity(row.items?.length || 0)}</strong></div>
                </div>
            </section>
            <div class="goods-receipt-detail-grid">
                ${renderGoodsReceiptInfo(row, supplier)}
                <section class="record-panel goods-receipt-flow-panel">
                    <h3>Tiến độ xử lý</h3>
                    ${renderTimeline(row.timeline || [])}
                </section>
            </div>
            ${renderGoodsReceiptDocuments(row.related_documents || [])}
            ${renderGoodsReceiptItems(row.items || [])}
        `;
    }

    function renderGoodsReceiptInfo(row, supplier) {
        const order = row.purchase_order || {};
        const fields = [
            ['Mã phiếu nhập', row.code || '-'],
            ['Trạng thái', VKTable.statusBadge(row.status || 'draft'), true],
            ['Đơn mua', order.code || '-'],
            ['Nhà cung cấp', supplier.name || 'Chưa khai báo'],
            ['Mã nhà cung cấp', supplier.code || '-'],
            ['Kho nhận', row.warehouse?.name || '-'],
            ['Ngày dự kiến nhận', formatDate(order.expected_delivery_date)],
            ['Xác nhận lúc', formatDateTime(row.confirmed_at)],
        ];
        return `
            <section class="record-panel goods-receipt-info-panel">
                <h3>Thông tin chứng từ</h3>
                <div class="goods-receipt-detail-list">
                    ${fields.map(([label, value, html]) => `
                        <div><span>${escape(label)}</span><strong>${html ? value : escape(value)}</strong></div>
                    `).join('')}
                </div>
            </section>
        `;
    }

    function renderGoodsReceiptDocuments(rows) {
        if (!rows.length) return '';
        return `
            <section class="record-panel goods-receipt-documents">
                <h3>Chứng từ liên quan</h3>
                <div class="goods-receipt-document-list">
                    ${rows.map(document => `
                        <a href="${escape(document.path || '#')}">
                            <span class="goods-receipt-document-mark" aria-hidden="true">▤</span>
                            <span><small>${escape(document.label || 'Chứng từ')}</small><strong>${escape(document.code || '-')}</strong></span>
                            ${VKTable.statusBadge(document.status || '-')}
                            <b aria-hidden="true">›</b>
                        </a>
                    `).join('')}
                </div>
            </section>
        `;
    }

    function renderGoodsReceiptItems(items) {
        return `
            <section class="record-panel goods-receipt-lines">
                <h3>Dòng hàng <small>${formatQuantity(items.length)} mặt hàng đã nhận</small></h3>
                ${items.length ? `
                    <div class="table-wrap list-table-wrap">
                        <table class="list-table">
                            <thead><tr><th>#</th><th>Mã hàng</th><th>Tên hàng</th><th>ĐVT</th><th>Lô hàng</th><th class="text-right">Số lượng nhập</th></tr></thead>
                            <tbody>${items.map((item, index) => `
                                <tr>
                                    <td>${index + 1}</td>
                                    <td><span class="mono">${escape(item.sku?.sku_code || '-')}</span></td>
                                    <td><strong>${escape(item.sku?.name || '-')}</strong></td>
                                    <td>${escape(item.sku?.unit || '-')}</td>
                                    <td>${escape(item.lot_no || 'Chưa có số lô')}</td>
                                    <td class="text-right"><span class="goods-receipt-quantity-pill">${formatQuantity(item.quantity)}</span></td>
                                </tr>
                            `).join('')}</tbody>
                        </table>
                    </div>
                ` : '<div class="empty compact"><strong>Chưa có dòng hàng</strong><span>Hàng hóa nhận kho sẽ hiển thị tại đây.</span></div>'}
            </section>
        `;
    }

    function renderGoodsIssueContent(state, row) {
        if (state.tab === 'items') return renderGoodsIssueItems(row.items || []);
        if (state.tab === 'history') return renderTimeline(row.timeline || []);
        if (state.tab === 'tasks') return renderRelated('Công việc liên quan', row.tasks || []);
        if (state.tab === 'notes') return renderNotes();

        return `
            <div class="goods-issue-summary">
                ${goodsIssueSummaryCard('Số dòng', String(row.items?.length || 0), 'Dòng hàng liên quan', 'count')}
                ${goodsIssueSummaryCard('Giá trị đơn', VKTable.money(row.sales_order?.total_amount || 0), 'Tổng tiền đơn bán', 'amount')}
                ${goodsIssueSummaryCard('Trạng thái', VKTable.translateStatus(row.status || 'draft'), 'Trạng thái hiện tại', 'status')}
            </div>
            ${renderGoodsIssueSalesOrder(row)}
            ${renderGoodsIssueInfo(row)}
            ${renderGoodsIssueDocuments(row.related_documents || [])}
            ${renderGoodsIssueItems(row.items || [])}
        `;
    }

    function goodsIssueSummaryCard(label, value, note, tone) {
        return `
            <div class="goods-issue-summary-card ${tone}">
                <span class="goods-issue-summary-icon" aria-hidden="true">${tone === 'count' ? '✓' : (tone === 'amount' ? '₫' : '▤')}</span>
                <span>
                    <small>${escape(label)}</small>
                    <strong>${escape(value)}</strong>
                    <em>${escape(note)}</em>
                </span>
            </div>
        `;
    }

    function renderGoodsIssueSalesOrder(row) {
        const order = row.sales_order || {};
        const customer = order.customer || {};
        const quotation = (row.related_documents || []).find(item => item.type === 'quotation') || {};
        const fields = [
            ['Đơn hàng', order.code || '-'],
            ['Ngày tạo đơn', formatDateTime(order.created_at)],
            ['Khách hàng', customer.name || '-'],
            ['Liên hệ', [customer.contact_name, customer.phone].filter(Boolean).join(' - ') || '-'],
            ['Tổng tiền đơn', VKTable.money(order.total_amount || 0)],
            ['Trạng thái đơn', VKTable.statusBadge(order.status || '-'), true],
            ['Tình trạng tồn', VKTable.statusBadge(order.stock_status || '-'), true],
            ['Báo giá gốc', quotation.code || '-'],
        ];

        return `
            <section class="record-panel goods-issue-info-panel">
                <h3>Thông tin đơn bán</h3>
                <div class="goods-issue-info-list">
                    ${fields.map(([label, value, html]) => `
                        <div>
                            <span>${escape(label)}</span>
                            <i>:</i>
                            <strong>${html ? value : escape(value)}</strong>
                        </div>
                    `).join('')}
                </div>
            </section>
        `;
    }

    function renderGoodsIssueInfo(row) {
        const fields = [
            ['Mã', row.code || '-'],
            ['Trạng thái', VKTable.statusBadge(row.status || 'draft'), true],
            ['Đơn hàng', row.sales_order?.code || '-'],
            ['Kho xuất', row.warehouse?.name || '-'],
            ['Ngày tạo phiếu', formatDateTime(row.created_at)],
            ['Cập nhật phiếu', formatDateTime(row.updated_at)],
            ['Xác nhận lúc', formatDateTime(row.confirmed_at)],
        ];

        return `
            <section class="record-panel goods-issue-info-panel">
                <h3>Thông tin chi tiết</h3>
                <div class="goods-issue-info-list">
                    ${fields.map(([label, value, html]) => `
                        <div>
                            <span>${escape(label)}</span>
                            <i>:</i>
                            <strong>${html ? value : escape(value)}</strong>
                        </div>
                    `).join('')}
                </div>
            </section>
        `;
    }

    function renderGoodsIssueDocuments(rows) {
        if (!rows.length) return '';

        return `
            <section class="record-panel goods-issue-documents">
                <h3>Chứng từ liên quan</h3>
                <div>
                    ${rows.map(row => `
                        <a href="${escape(goodsIssueDocumentHref(row))}">
                            <span class="goods-issue-document-icon ${escape(row.type || 'document')}" aria-hidden="true">
                                ${row.type === 'salesOrder' ? '▣' : '▤'}
                            </span>
                            <strong>${escape(row.label || row.type || 'Chứng từ')}</strong>
                            <em>${escape(row.code || '-')} · ${escape(VKTable.translateStatus(row.status || '-'))}</em>
                            <b aria-hidden="true">›</b>
                        </a>
                    `).join('')}
                </div>
            </section>
        `;
    }

    function goodsIssueDocumentHref(row) {
        const paths = {
            salesOrder: '/sales-orders',
            quotation: '/quotations',
        };
        return paths[row.type] || row.path || '#';
    }

    function renderGoodsIssueItems(items) {
        return `
            <section class="record-panel goods-issue-lines">
                <h3>Dòng hàng (${items.length})</h3>
                ${items.length ? `
                    <div class="table-wrap list-table-wrap">
                        <table class="list-table">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Mã hàng</th>
                                    <th>Tên hàng</th>
                                    <th>ĐVT</th>
                                    <th>Số lượng</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${items.map((item, index) => `
                                    <tr>
                                        <td>${index + 1}</td>
                                        <td><span class="mono">${escape(item.sku?.sku_code || '-')}</span></td>
                                        <td>${escape(item.sku?.name || '-')}</td>
                                        <td>${escape(item.sku?.unit || '-')}</td>
                                        <td>${formatQuantity(item.quantity)}</td>
                                    </tr>
                                `).join('')}
                            </tbody>
                        </table>
                    </div>
                ` : `
                    <div class="empty compact">
                        <strong>Chưa có dòng hàng</strong>
                        <span>Hàng hóa xuất kho sẽ hiển thị tại đây.</span>
                    </div>
                `}
            </section>
        `;
    }

    function renderSimpleEntityContent(state, row) {
        if (state.tab === 'history') return renderTimeline(row.timeline || []);
        if (state.tab === 'notes') return renderNotes();

        return `
            ${renderSimpleEntityInfo(state.type, row)}
            ${state.type === 'sku' ? renderSkuProductInfo(row) : ''}
            ${row.tasks?.length ? renderRelated('Công việc liên quan', row.tasks) : ''}
            ${row.alerts?.length ? renderRelated('Cảnh báo liên quan', row.alerts) : ''}
        `;
    }

    function renderSkuProductInfo(row) {
        const product = row.product || {};
        const specs = Array.isArray(product.technical_specs) ? product.technical_specs : [];
        const fields = [
            ['Mã sản phẩm', product.code],
            ['Tên sản phẩm', product.name],
            ['Danh mục', product.category?.name],
            ['Hãng sản phẩm', product.brand?.name],
        ];
        const imagePath = String(product.image_path || '');
        const imageUrl = imagePath && !imagePath.includes('..') && /^[a-zA-Z0-9_/.\-]+$/.test(imagePath)
            ? `/storage/${imagePath}` : '';
        return `
            <section class="record-panel">
                <h3>Thông tin sản phẩm</h3>
                <div class="record-info-list">
                    ${fields.map(([label, value]) => `<div><span>${escape(label)}</span><strong>${escape(value || 'Chưa khai báo')}</strong></div>`).join('')}
                    <div><span>Mô tả sản phẩm</span><strong style="white-space:pre-wrap;overflow-wrap:anywhere">${escape(product.description || 'Chưa khai báo')}</strong></div>
                    <div><span>Hình ảnh sản phẩm</span>${imageUrl ? `<img src="${escape(imageUrl)}" alt="${escape(product.name || 'Sản phẩm')}" style="max-width:100%;width:180px;height:150px;object-fit:contain" loading="lazy">` : '<strong>Chưa khai báo</strong>'}</div>
                </div>
            </section>
            <section class="record-panel">
                <h3>Thông số kỹ thuật</h3>
                <div class="record-info-list">
                    ${specs.length ? specs.map(item => `<div><span style="white-space:pre-wrap;overflow-wrap:anywhere">${escape(item.name || 'Thông số')}</span><strong style="white-space:pre-wrap;overflow-wrap:anywhere">${escape(item.value || '—')}</strong></div>`).join('') : '<div><span>Thông số kỹ thuật</span><strong>Chưa khai báo</strong></div>'}
                </div>
            </section>
        `;
    }

    function renderSimpleEntityInfo(type, row) {
        const roleNames = (row.roles || [])
            .map(role => role.name || VKTable.translateRole(role.code))
            .join(', ');
        const fields = {
            lead: [
                ['Mã khách hàng tiềm năng', row.code || '-'],
                ['Ngày tạo', formatDateTime(row.created_at)],
                ['Tên khách hàng', row.name || '-'],
                ['Trạng thái', VKTable.statusBadge(row.status || 'new'), true],
                ['Số điện thoại', row.phone || 'Chưa khai báo'],
                ['Email', row.email || 'Chưa khai báo'],
                ['Nguồn', row.source || 'Chưa khai báo'],
                ['Người phụ trách', row.assignee?.name || 'Chưa phân công'],
                ['Chiến dịch', row.campaign_code || 'Chưa gắn chiến dịch'],
            ],
            supplier: [
                ['Mã nhà cung cấp', row.code || '-'],
                ['Ngày tạo', formatDateTime(row.created_at)],
                ['Tên nhà cung cấp', row.name || '-'],
                ['Trạng thái', VKTable.statusBadge(row.status || 'active'), true],
                ['Số điện thoại', row.phone || 'Chưa khai báo'],
                ['Email', row.email || 'Chưa khai báo'],
                ['Điều khoản', row.terms || 'Chưa khai báo'],
                ['Đánh giá', `${Number(row.rating || 0)}/5`],
                ['Nhóm hàng cung cấp', row.supplied_products || 'Chưa khai báo'],
            ],
            sku: [
                ['Mã hàng', row.sku_code || '-'],
                ['Ngày tạo', formatDateTime(row.created_at)],
                ['Tên hàng', row.name || '-'],
                ['Trạng thái', VKTable.statusBadge(row.status || 'active'), true],
                ['Sản phẩm', row.product?.name || '-'],
                ['Mã vạch', row.barcode || 'Chưa khai báo'],
                ['Đơn vị tính', row.unit || '-'],
                ['Giá vốn', VKTable.money(row.cost_price || 0)],
                ['Giá bán', VKTable.money(row.sale_price || 0)],
                ['Tồn tối thiểu', VKTable.money(row.min_stock || 0)],
                ['Tồn tối đa', VKTable.money(row.max_stock || 0)],
            ],
            user: [
                ['Họ tên', row.name || '-'],
                ['Ngày tạo', formatDateTime(row.created_at)],
                ['Email', row.email || '-'],
                ['Trạng thái', VKTable.statusBadge(row.is_active ? 'active' : 'inactive'), true],
                ['Phòng ban', row.department?.name || 'Chưa gắn phòng ban'],
                ['Chức vụ', row.position?.name || 'Chưa gắn chức vụ'],
                ['Vai trò', roleNames || 'Chưa có vai trò'],
                ['Người quản lý', row.manager?.name || 'Chưa khai báo'],
            ],
            alert: [
                ['Tiêu đề', row.title || '-'],
                ['Ngày tạo', formatDateTime(row.created_at)],
                ['Loại cảnh báo', VKTable.translateType(row.alert_type || '-')],
                ['Trạng thái', VKTable.statusBadge(row.status || 'open'), true],
                ['Mức độ', VKTable.statusBadge(row.level || 'info'), true],
                ['Người nhận', row.recipient?.name || 'Chưa gán'],
                ['Nguồn phát sinh', VKTable.translateEntity(row.source_type || 'System')],
                ['Mã tham chiếu', row.source_id || '-'],
                ['Nội dung', row.message || 'Chưa có nội dung'],
            ],
        };

        return `
            <section class="record-panel sales-order-info-panel">
                <h3>Thông tin ${escape(labels[type] || 'chi tiết').toLowerCase()}</h3>
                <div class="sales-order-info-grid">
                    ${(fields[type] || []).map(([label, value, html]) => `
                        <div>
                            <span>${escape(label)}</span>
                            <strong>${html ? value : escape(value)}</strong>
                        </div>
                    `).join('')}
                </div>
            </section>
        `;
    }

    function renderPurchaseRequestContent(state, row) {
        if (state.tab === 'history') return renderTimeline(row.timeline || []);
        if (state.tab === 'tasks') return renderRelated('Công việc liên quan', row.tasks || []);
        if (state.tab === 'notes') return renderNotes();
        if (state.tab === 'items') return renderPurchaseRequestItems(row);

        return `
            ${renderPurchaseRequestInfo(row)}
            ${renderPurchaseRequestItems(row)}
        `;
    }

    function renderPurchaseRequestInfo(row) {
        const requester = row.requester?.name || row.requestedBy?.name || 'Chưa xác định';
        const fields = [
            ['Mã yêu cầu mua', row.code || '-'],
            ['Ngày tạo', formatDateTime(row.created_at)],
            ['Trạng thái', VKTable.statusBadge(row.status || 'draft'), true],
            ['Cập nhật', formatDateTime(row.updated_at)],
            ['Nguồn', translatePurchaseRequestSource(row.source_type)],
            ['Người tạo', requester],
            ['Tham chiếu nguồn', row.source_id || '-'],
            ['Lý do', row.reason || '-'],
        ];

        return `
            <section class="record-panel purchase-request-info-panel">
                <h3>Thông tin yêu cầu mua</h3>
                <div class="purchase-request-info-grid">
                    ${fields.map(([label, value, html]) => `
                        <div>
                            <span>${escape(label)}</span>
                            <i>:</i>
                            <strong>${html ? value : escape(value)}</strong>
                        </div>
                    `).join('')}
                </div>
            </section>
        `;
    }

    function renderPurchaseRequestItems(row) {
        const items = row.items || [];
        const totalQuantity = items.reduce((sum, item) => sum + Number(item.quantity || 0), 0);
        const totalShortage = items.reduce((sum, item) => {
            return sum + Math.max(Number(item.quantity || 0) - Number(item.available_qty || 0), 0);
        }, 0);

        return `
            <section class="record-panel purchase-request-lines-panel">
                <div class="purchase-request-section-head">
                    <h3>Dòng hàng (${items.length})</h3>
                </div>
                ${items.length ? `
                    <div class="table-wrap list-table-wrap purchase-request-items-table">
                        <table class="list-table">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Mã hàng</th>
                                    <th>Tên hàng</th>
                                    <th>ĐVT</th>
                                    <th>Số lượng cần mua</th>
                                    <th>Tồn khả dụng</th>
                                    <th>Thiếu hụt</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${items.map((item, index) => {
                                    const quantity = Number(item.quantity || 0);
                                    const available = Number(item.available_qty || 0);
                                    const shortage = Math.max(quantity - available, 0);
                                    return `
                                        <tr>
                                            <td>${index + 1}</td>
                                            <td><span class="mono">${escape(item.sku?.sku_code || '-')}</span></td>
                                            <td>${escape(item.sku?.name || '-')}</td>
                                            <td>${escape(item.sku?.unit || '-')}</td>
                                            <td>${formatQuantity(quantity)}</td>
                                            <td>${formatQuantity(available)}</td>
                                            <td>${formatQuantity(shortage)}</td>
                                        </tr>
                                    `;
                                }).join('')}
                            </tbody>
                            <tfoot>
                                <tr>
                                    <td colspan="6">Tổng số lượng cần mua</td>
                                    <td>${formatQuantity(totalQuantity)}</td>
                                </tr>
                                <tr class="purchase-request-table-total">
                                    <td colspan="6">Tổng thiếu hụt</td>
                                    <td>${formatQuantity(totalShortage)}</td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                ` : `
                    <div class="empty compact">
                        <strong>Chưa có dòng hàng</strong>
                        <span>Hàng hóa cần mua sẽ hiển thị tại đây.</span>
                    </div>
                `}
            </section>
        `;
    }

    function translatePurchaseRequestSource(source) {
        const sources = {
            Manual: 'Tạo thủ công',
            SalesOrder: 'Đơn hàng thiếu tồn',
            System: 'Hệ thống',
        };
        return sources[source] || source || 'Hệ thống';
    }

    function formatQuantity(value) {
        return Number(value || 0).toLocaleString('vi-VN', {
            minimumFractionDigits: 0,
            maximumFractionDigits: 3,
        });
    }

    function renderCustomerContent(state, row) {
        if (state.tab === 'history') return renderTimeline(row.timeline || []);
        if (state.tab === 'tasks') return renderRelated('Công việc liên quan', row.tasks || []);
        if (state.tab === 'notes') return renderNotes();
        if (state.tab === 'items') return renderLinkedDocuments(row.related_documents || []);

        return `
            <div class="record-page-grid">
                ${renderCustomerInfo(row)}
                ${renderCustomerBilling(row)}
            </div>
            ${renderRelated('Cảnh báo liên quan', row.alerts || [])}
        `;
    }

    function renderCustomerInfo(row) {
        const fields = [
            ['Mã khách hàng', row.code],
            ['Trạng thái', VKTable.statusBadge(row.status || 'active'), true],
            ['Tên khách hàng', row.contact_name || row.name],
            ['Số điện thoại', row.phone || 'Chưa khai báo'],
            ['Email', row.email || 'Chưa khai báo'],
            ['Địa chỉ', row.address || 'Chưa khai báo'],
            ['Người phụ trách', row.sales_owner?.name || row.salesOwner?.name || 'Chưa phân công'],
            ['Ngày tạo', formatDateTime(row.created_at)],
        ];

        return renderFieldPanel('Thông tin khách hàng', fields);
    }

    function renderCustomerBilling(row) {
        const fields = [
            ['Mã số thuế', row.tax_code || 'Chưa khai báo'],
            ['Tên công ty', row.name || 'Chưa khai báo'],
            ['Địa chỉ công ty', row.billing_address || 'Chưa khai báo'],
            ['Hạn mức công nợ', VKTable.money(row.credit_limit || 0)],
        ];

        return renderFieldPanel('Thông tin xuất hóa đơn', fields);
    }

    function renderFieldPanel(title, fields) {
        return `
            <section class="record-panel">
                <h3>${escape(title)}</h3>
                <div class="record-info-list">
                    ${fields.map(([label, value, html]) => `
                        <div>
                            <span>${escape(label)}</span>
                            <strong>${html ? value : escape(value ?? '-')}</strong>
                        </div>
                    `).join('')}
                </div>
            </section>
        `;
    }

    function renderSalesOrderContentV2(state, row) {
        if (state.tab === 'history') return renderTimeline(row.timeline || []);
        if (state.tab === 'fulfillment') return `
            ${renderSalesFulfillment(row)}
            ${renderLinkedDocuments(row.related_documents || [])}
            ${row.tasks?.length ? renderRelated('Công việc liên quan', row.tasks || []) : ''}
        `;
        if (state.tab === 'notes') return renderNotes();
        if (state.tab === 'items') return renderSalesOrderItems(row);

        return `
            ${renderSalesOrderHighlights(row)}
            ${renderSalesOrderInfoV2(row)}
            ${renderSalesOrderItems(row)}
        `;
    }

    function renderSalesOrderHighlights(row) {
        const items = row.items || [];
        const total = Number(row.total_amount || 0);
        const tax = Number(row.tax_amount || 0);
        const paid = Number(row.invoices?.[0]?.paid_amount || 0);
        const balance = Math.max(0, total - paid);
        return `
            <div class="sales-order-highlights">
                <div class="sales-order-highlight total"><span>Tổng giá trị đơn</span><strong>${VKTable.money(total)}</strong><small>Đã gồm VAT ${VKTable.money(tax)}</small></div>
                <div class="sales-order-highlight quantity"><span>Hàng hóa đặt</span><strong>${formatQuantity(items.reduce((sum, item) => sum + Number(item.quantity || 0), 0))}</strong><small>${items.length} dòng hàng</small></div>
                <div class="sales-order-highlight payment"><span>Còn cần thu</span><strong>${VKTable.money(balance)}</strong><small>${paid ? `Đã thu ${VKTable.money(paid)}` : 'Chưa ghi nhận thanh toán'}</small></div>
                <div class="sales-order-highlight delivery"><span>Giao hàng</span><strong>${escape(VKTable.translateStatus(row.status || 'draft'))}</strong><small>${escape(VKTable.translateStatus(row.stock_status || 'unchecked'))}</small></div>
            </div>
        `;
    }

    function renderSalesOrderInfoV2(row) {
        const customer = row.customer || {};
        const contact = [customer.contact_name, customer.phone].filter(Boolean).join(' - ') || customer.email || 'Chưa khai báo';
        const owner = row.sales_owner?.name || row.salesOwner?.name || 'Chưa phân công';
        const paymentTerms = row.payment_terms || row.quotation?.payment_terms || 'Chưa khai báo';
        const orderFields = [
            ['Mã đơn hàng', row.code || '-'],
            ['Trạng thái', VKTable.statusBadge(row.status || 'draft'), true],
            ['Tình trạng tồn', VKTable.statusBadge(row.stock_status || 'unchecked'), true],
            ['Ngày tạo', formatDateTime(row.created_at)],
            ['Cập nhật', formatDateTime(row.updated_at)],
        ];
        const customerFields = [
            ['Khách hàng', customer.name || '-'],
            ['Mã khách hàng', customer.code || '-'],
            ['Người liên hệ', contact],
            ['Người phụ trách', owner],
            ['Phương thức TT', paymentTerms],
            ['Ghi chú', row.note || '-'],
        ];

        return `
            <div class="sales-order-detail-cards">
                ${renderSalesOrderDetailCard('Thông tin đơn hàng', orderFields)}
                ${renderSalesOrderDetailCard('Khách hàng & thanh toán', customerFields)}
            </div>
        `;
    }

    function renderSalesOrderDetailCard(title, fields) {
        return `
            <section class="sales-order-detail-card">
                <h3>${escape(title)}</h3>
                <div class="sales-order-info-grid">
                    ${fields.map(([label, value, html]) => `
                        <div>
                            <span>${escape(label)}</span>
                            <strong>${html ? value : escape(value)}</strong>
                        </div>
                    `).join('')}
                </div>
            </section>
        `;
    }

    function renderSalesOrderContent(state, row) {
        if (state.tab === 'history') return renderTimeline(row.timeline || []);
        if (state.tab === 'tasks') return renderRelated('Công việc liên quan', row.tasks || []);
        if (state.tab === 'notes') return renderNotes();
        if (state.tab === 'items') return renderSalesOrderItems(row);

        return `
            ${renderSalesOrderInfo(row)}
            ${renderSalesFulfillment(row)}
            ${renderSalesOrderItems(row)}
        `;
    }

    function renderSalesOrderInfo(row) {
        const customer = row.customer || {};
        const contact = [customer.contact_name, customer.phone].filter(Boolean).join(' - ') || customer.email || 'Chưa khai báo';
        const owner = row.sales_owner?.name || row.salesOwner?.name || 'Chưa phân công';
        const paymentTerms = row.payment_terms || row.quotation?.payment_terms || 'Chưa khai báo';
        const fields = [
            ['Mã đơn hàng', row.code || '-'],
            ['Ngày tạo', formatDateTime(row.created_at)],
            ['Trạng thái', VKTable.statusBadge(row.status || 'draft'), true],
            ['Ngày cập nhật', formatDateTime(row.updated_at)],
            ['Khách hàng', customer.name || '-'],
            ['Tình trạng tồn', VKTable.statusBadge(row.stock_status || 'unchecked'), true],
            ['Mã khách hàng', customer.code || '-'],
            ['Phương thức TT', paymentTerms],
            ['Người liên hệ', contact],
            ['Ghi chú', row.note || '-'],
            ['Người phụ trách', owner],
        ];

        return `
            <section class="record-panel sales-order-info-panel">
                <h3>Thông tin đơn hàng</h3>
                <div class="sales-order-info-grid">
                    ${fields.map(([label, value, html]) => `
                        <div>
                            <span>${escape(label)}</span>
                            <strong>${html ? value : escape(value)}</strong>
                        </div>
                    `).join('')}
                </div>
            </section>
        `;
    }

    function renderSalesOrderItems(row) {
        const items = row.items || [];
        if (!items.length) {
            return `<section class="record-panel"><h3>Dòng hàng (0)</h3><div class="empty"><strong>Chưa có dòng hàng</strong><span>Dữ liệu mới sẽ hiển thị tại đây.</span></div></section>`;
        }

        const subtotal = Number(row.subtotal_amount || row.total_amount || 0);
        const tax = Number(row.tax_amount || 0);
        const total = Number(row.total_amount || subtotal + tax);
        const discount = Number(row.discount_amount || 0);
        const discountPercent = subtotal > 0 ? (discount / subtotal) * 100 : 0;

        return `
            <section class="record-panel sales-order-lines-panel">
                <h3>Dòng hàng (${items.length})</h3>
                <div class="table-wrap list-table-wrap sales-order-items-table">
                    <table class="list-table">
                        <thead>
                            <tr>
                                <th>#</th><th>Mã hàng</th><th>Tên hàng</th><th>ĐVT</th>
                                <th>Số lượng</th><th>Đơn giá</th><th>CK (%)</th><th>VAT (%)</th><th>Thành tiền</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${items.map((item, index) => `
                                <tr>
                                    <td>${index + 1}</td>
                                    <td><span class="mono">${escape(item.sku?.sku_code || '-')}</span></td>
                                    <td>${escape(item.sku?.name || '-')}</td>
                                    <td>${escape(item.sku?.unit || '-')}</td>
                                    <td><span class="sales-order-quantity">${formatQuantity(item.quantity)}</span></td>
                                    <td><span class="sales-order-price">${VKTable.money(item.unit_price || 0)}</span></td>
                                    <td>${Number(item.discount_rate || 0).toFixed(0)}</td>
                                    <td>${Number(item.vat_rate || 0).toFixed(0)}</td>
                                    <td><strong class="sales-order-line-total">${VKTable.money(item.line_total || 0)}</strong></td>
                                </tr>
                            `).join('')}
                        </tbody>
                        <tfoot>
                            <tr><td colspan="8">Tạm tính</td><td>${VKTable.money(subtotal)}</td></tr>
                            <tr><td colspan="8">Chiết khấu (${discountPercent.toFixed(2)}%)</td><td>${VKTable.money(discount)}</td></tr>
                            <tr><td colspan="8">Thuế VAT</td><td>${VKTable.money(tax)}</td></tr>
                            <tr class="sales-order-table-total"><td colspan="8">Tổng cộng</td><td>${VKTable.money(total)}</td></tr>
                        </tfoot>
                    </table>
                </div>
            </section>
        `;
    }

    function renderSalesFulfillment(row) {
        const delivery = (row.deliveries || [])[0];
        const invoice = (row.invoices || [])[0];
        const paidAmount = Number(invoice?.paid_amount || 0);
        const balanceAmount = Number(invoice?.balance_amount ?? row.total_amount ?? 0);

        return `
            <section class="record-panel sales-fulfillment-panel">
                <h3>Tiến độ giao hàng và thanh toán</h3>
                <div class="sales-fulfillment-steps">
                    ${fulfillmentStep('Xuất kho', row.delivery_status !== 'not_ready', row.delivery_status === 'not_ready' ? 'Chưa xuất kho' : 'Đã rời kho')}
                    ${fulfillmentStep('Giao hàng', row.delivery_status === 'delivered', delivery ? `${delivery.recipient_name} · ${formatDateTime(delivery.delivered_at)}` : 'Chờ khách xác nhận nhận hàng')}
                    ${fulfillmentStep('Hóa đơn', Boolean(invoice), invoice ? `${invoice.code} · ${VKTable.translateStatus(invoice.status)}` : 'Chưa phát hành hóa đơn', 'invoice')}
                    ${fulfillmentStep('Thanh toán', row.payment_status === 'paid', invoice ? `Đã thu ${VKTable.money(paidAmount)} · Còn ${VKTable.money(balanceAmount)}` : 'Chưa phát sinh công nợ', 'payment')}
                </div>
            </section>
        `;
    }

    function fulfillmentStep(label, done, note, tone = '') {
        return `
            <div class="sales-fulfillment-step ${done ? 'done' : ''} ${tone}">
                <i aria-hidden="true"></i>
                <span>
                    <strong>${escape(label)}</strong>
                    <small>${escape(note)}</small>
                </span>
            </div>
        `;
    }

    function renderMetrics(type, row) {
        const total = Number(row.total_amount || row.subtotal_amount || 0);
        const tax = Number(row.tax_amount || 0);
        const subtotal = Number(row.subtotal_amount || (total - tax) || total);
        const cost = Number(row.total_cost || 0);
        const profit = subtotal - cost;
        const hasMoney = ['salesOrder', 'purchaseOrder'].includes(type) || total > 0;

        if (type === 'purchaseOrder') {
            return `<div class="record-metrics po-metrics">
                ${purchaseMetric('Giá trị đơn mua', `${VKTable.money(total)} đ`, 'Theo giá mua trên chứng từ', 'blue', '<path d="M3 3h2l3 12h10l3-9H6M9 20h.01M18 20h.01"/>')}
                ${purchaseMetric('Ngày dự kiến nhận', row.expected_delivery_date ? formatDate(row.expected_delivery_date) : 'Chưa xác định', 'Lịch giao hàng của nhà cung cấp', 'orange', '<rect x="4" y="5" width="16" height="16" rx="2"/><path d="M8 3v4m8-4v4M4 11h16"/>')}
                ${purchaseMetric('Hàng hóa', String(row.items?.length || 0), 'Số dòng hàng cần nhận', 'green', '<path d="m12 3 9 5v9l-9 5-9-5V8l9-5Zm0 10 9-5M12 13 3 8m9 5v9M7.5 5.5l9 5"/>')}
            </div>`;
        }

        if (!hasMoney) {
            return `
                <div class="record-metrics compact">
                    ${metricBox('Số dòng', String(row.items?.length || 0), 'Dòng hàng liên quan')}
                    ${metricBox('Trạng thái', VKTable.translateStatus(row.status || 'draft'), 'Trạng thái hiện tại', 'blue')}
                    ${row.stock_status ? metricBox('Tình trạng tồn', VKTable.translateStatus(row.stock_status), 'Kiểm tra kho', 'blue') : ''}
                </div>
            `;
        }

        return `
            <div class="record-metrics">
                ${metricBox('Tiền trước thuế', VKTable.money(subtotal), 'Giá trị hàng hóa')}
                ${metricBox('Thuế VAT', VKTable.money(tax), 'Tổng thuế')}
                ${metricBox('Tổng thanh toán', VKTable.money(total), 'Giá trị chứng từ', 'blue')}
                ${metricBox('Giá vốn', VKTable.money(cost), 'Chi phí hàng')}
                ${metricBox('Lợi nhuận gộp', VKTable.money(profit), profit >= 0 ? 'Dương' : 'Âm', profit >= 0 ? 'green' : 'orange')}
                ${metricBox('Số dòng', String(row.items?.length || 0), 'Dòng hàng')}
            </div>
        `;
    }

    function purchaseMetric(label, value, note, tone, icon) {
        return `<div class="record-metric po-metric ${tone}"><div class="po-metric-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${icon}</svg></div><div class="po-metric-content"><span>${escape(label)}</span><strong>${escape(value)}</strong><small>${escape(note)}</small></div></div>`;
    }

    function metricBox(label, value, note, tone = '') {
        return `
            <div class="record-metric ${tone}">
                <span>${escape(label)}</span>
                <strong>${escape(value)}</strong>
                <small>${escape(note)}</small>
            </div>
        `;
    }

    function renderInfo(type, row) {
        const fields = infoFields(type, row);
        return `
            <section class="record-panel">
                <h3>Thông tin chi tiết</h3>
                <div class="record-info-list">
                    ${fields.map(([label, value, html]) => `
                        <div>
                            <span>${escape(label)}</span>
                            <strong>${html ? value : escape(value ?? '-')}</strong>
                        </div>
                    `).join('')}
                </div>
            </section>
        `;
    }

    function infoFields(type, row) {
        const common = [
            ['Mã', row.code],
            ['Trạng thái', VKTable.statusBadge(row.status || 'draft'), true],
        ];
        const maps = {
            salesOrder: [
                ['Khách hàng', row.customer?.name],
                ['Tình trạng tồn', VKTable.statusBadge(row.stock_status || 'unchecked'), true],
                ['Tổng tiền', VKTable.money(row.total_amount || 0)],
            ],
            purchaseRequest: [
                ['Nguồn', row.source_type || 'System'],
                ['Tham chiếu nguồn', row.source_id],
                ['Lý do', row.reason],
            ],
            purchaseOrder: [
                ['Yêu cầu mua', row.purchase_request?.code],
                ['Nhà cung cấp', row.supplier?.name],
                ['Ngày dự kiến nhận', formatDate(row.expected_delivery_date)],
                ['Tổng tiền', VKTable.money(row.total_amount || 0)],
            ],
            deal: [
                ['Khách hàng', row.customer?.name || row.lead?.name || 'Chưa gắn'],
                ['Người phụ trách', row.owner?.name || '-'],
                ['Giai đoạn', row.stage || '-'],
                ['Giá trị dự kiến', VKTable.money(row.amount || 0)],
                ['Dự kiến chốt', formatDate(row.expected_close_date)],
                ['Nguồn', row.source || '-'],
                ['Nhu cầu', row.description || '-'],
            ],
            goodsReceipt: [
                ['Đơn mua', row.purchase_order?.code],
                ['Kho nhận', row.warehouse?.name],
            ],
            goodsIssue: [
                ['Đơn hàng', row.sales_order?.code],
                ['Kho xuất', row.warehouse?.name],
            ],
        };
        return [...common, ...(maps[type] || [])];
    }

    function renderFlow(row) {
        return `
            <section class="record-panel">
                <h3>Luồng xử lý</h3>
                ${renderTimeline(row.timeline || [])}
            </section>
        `;
    }

    function renderTimeline(rows) {
        if (!rows.length) return `<div class="empty compact"><strong>Chưa có lịch sử</strong><span>Lịch sử xử lý sẽ hiển thị tại đây.</span></div>`;
        return `
            <div class="record-timeline">
                ${rows.map(item => `
                    <div class="${timelineTone(item.status)}">
                        <i></i>
                        <span>
                            <strong>${escape(item.label || '-')}</strong>
                            <small>${formatDateTime(item.at)}</small>
                        </span>
                        <em>${escape(VKTable.translateStatus(item.status || '-'))}</em>
                    </div>
                `).join('')}
            </div>
        `;
    }

    function renderLinkedDocuments(rows) {
        if (!rows.length) return '';
        return `
            <section class="record-panel">
                <h3>Chứng từ liên quan</h3>
                <div class="record-related">
                    ${rows.map(row => `
                        <div>
                            <strong>${escape(row.label || row.type || 'Chứng từ')}</strong>
                            <span>${escape(row.code || '-')} · ${escape(row.status === 'selected' ? 'Đã chọn làm căn cứ mua hàng' : VKTable.translateStatus(row.status || '-'))}</span>
                        </div>
                    `).join('')}
                </div>
            </section>
        `;
    }

    function renderItems(items) {
        return `
            <section class="record-panel">
                <h3>Dòng hàng</h3>
                ${VKTable.renderTable([
                    { label: 'Mã hàng', render: item => `<span class="mono">${escape(item.sku?.sku_code || '-')}</span>` },
                    { label: 'Tên hàng', render: item => escape(item.sku?.name || '-') },
                    { label: 'ĐVT', render: item => escape(item.sku?.unit || '-') },
                    { label: 'Số lượng', render: item => VKTable.money(item.quantity) },
                    { label: 'Đơn giá', render: item => VKTable.money(item.unit_price || 0) },
                    { label: 'VAT', render: item => item.vat_rate === undefined ? '-' : `${Number(item.vat_rate || 0).toFixed(0)}%` },
                    { label: 'Thành tiền', render: item => VKTable.money(item.line_total || 0) },
                ], items, 'Chưa có dòng hàng')}
            </section>
        `;
    }

    function renderTotals(row) {
        const total = Number(row.total_amount || 0);
        if (!total) return '';
        const tax = Number(row.tax_amount || 0);
        const subtotal = Number(row.subtotal_amount || (total - tax) || total);
        return `
            <section class="record-total-bar">
                <div><span>Tiền trước thuế</span><strong>${VKTable.money(subtotal)}</strong></div>
                <div><span>Thuế VAT</span><strong>${VKTable.money(tax)}</strong></div>
                <div><span>Tổng tiền</span><strong>${VKTable.money(total)}</strong></div>
                <div><span>Số dòng</span><strong>${VKTable.money(row.items?.length || 0)}</strong></div>
                <div class="grand"><span>Tổng cộng</span><strong>${VKTable.money(total)}</strong><small>VND</small></div>
            </section>
        `;
    }

    function renderRelated(title, rows) {
        if (!rows.length) return `<section class="record-panel"><h3>${escape(title)}</h3><div class="empty compact"><strong>Chưa có dữ liệu</strong><span>Nội dung liên quan sẽ hiển thị tại đây.</span></div></section>`;
        return `
            <section class="record-panel">
                <h3>${escape(title)}</h3>
                <div class="record-related">
                    ${rows.map(row => `<div><strong>${escape(row.title || row.code || '-')}</strong><span>${escape(VKTable.translateStatus(row.status || '-'))}</span></div>`).join('')}
                </div>
            </section>
        `;
    }

    function renderNotes() {
        return `<section class="record-panel"><h3>Ghi chú</h3><div class="empty compact"><strong>Chưa có ghi chú</strong><span>Nội dung ghi chú sẽ hiển thị tại đây.</span></div></section>`;
    }

    function normalize(type, row) {
        return {
            ...row,
            items: row.items || [],
            tasks: row.tasks || [],
            alerts: row.alerts || [],
            related_documents: row.related_documents || [],
            timeline: row.timeline || fallbackTimeline(type, row),
        };
    }

    function fallbackTimeline(type, row) {
        return [
            { label: `Tạo ${labels[type] || 'chứng từ'}`, status: 'completed', at: row.created_at || row.updated_at },
            { label: 'Trạng thái hiện tại', status: row.status || row.stock_status || 'draft', at: row.updated_at || row.created_at },
        ];
    }

    function timelineTone(status) {
        if (['approved', 'ready', 'completed', 'confirmed', 'received', 'done', 'delivered', 'paid'].includes(status)) return 'done';
        if (['pending', 'pending_approval', 'draft', 'new', 'unchecked', 'reserved', 'awaiting_delivery', 'invoiced', 'unpaid', 'not_ready'].includes(status)) return 'waiting';
        if (['rejected', 'overdue', 'shortage'].includes(status)) return 'risk';
        return '';
    }

    function formatDate(value) {
        return value ? new Date(value).toLocaleDateString('vi-VN') : '-';
    }

    function formatDateTime(value) {
        if (!value) return '-';
        const date = new Date(value);
        if (Number.isNaN(date.getTime())) return '-';
        return date.toLocaleString('vi-VN', { hour: '2-digit', minute: '2-digit', day: '2-digit', month: '2-digit', year: 'numeric' });
    }

    function escape(value) {
        return VKTable.escapeHtml(value ?? '');
    }

    window.VKRecordPage = { open };
})();
