(function () {
const kanban = [
    {
        title: 'Kinh doanh',
        metric: 'Lead → Báo giá → Đơn bán',
        cards: [
            ['Tạo lead', 'Tiếp nhận nhu cầu, ghi nhận khách hàng và người phụ trách.', '/leads'],
            ['Lập và duyệt báo giá', 'Kiểm giá bán, VAT, biên lợi nhuận và trạng thái duyệt.', '/quotations'],
            ['Tạo đơn bán', 'Chuyển báo giá đã sẵn sàng thành đơn bán.', '/sales-orders'],
        ],
    },
    {
        title: 'Kho vận',
        metric: 'Kiểm tồn → Xuất kho → Giao hàng',
        cards: [
            ['Kiểm tồn', 'Xác định đủ tồn, thiếu tồn hoặc cần giữ hàng.', '/sales-orders'],
            ['Xuất kho', 'Xác nhận phiếu xuất và cập nhật tồn kho.', '/goods-issues'],
            ['Giao hàng', 'Theo dõi chờ giao, đã giao và hoàn tất giao hàng.', '/deliveries'],
        ],
    },
    {
        title: 'Mua hàng',
        metric: 'PR → PO → Nhập kho',
        cards: [
            ['Duyệt yêu cầu mua', 'Xác nhận nhu cầu mua phát sinh từ thiếu tồn.', '/purchase-requests'],
            ['Duyệt đơn mua', 'Chốt nhà cung cấp, số lượng và đơn mua.', '/purchase-orders'],
            ['Nhập kho', 'Nhận hàng về kho rồi quay lại xử lý đơn bán.', '/goods-receipts'],
        ],
    },
    {
        title: 'Tài chính',
        metric: 'Hóa đơn → Công nợ → Thu tiền',
        cards: [
            ['Phát hành hóa đơn', 'Ghi nhận hóa đơn sau khi giao hàng.', '/sales-invoices'],
            ['Theo dõi công nợ', 'Kiểm tra dư nợ, quá hạn và hạn mức khách hàng.', '/customer-receivables'],
            ['Thu tiền', 'Ghi nhận thanh toán và đóng công nợ.', '/customer-payments'],
        ],
    },
    {
        title: 'Kiểm soát',
        metric: 'Task / Alert / KPI / Nhật ký',
        cards: [
            ['Công việc', 'Nhận việc tự sinh từ các bước nghiệp vụ.', '/tasks'],
            ['Cảnh báo', 'Theo dõi thiếu tồn, quá hạn, rủi ro công nợ.', '/alerts'],
            ['KPI và nhật ký', 'Đo hiệu suất và truy vết thao tác hệ thống.', '/kpi'],
        ],
    },
];

document.addEventListener('vk:ready', () => loadWorkflows());

function loadWorkflows() {
    const root = document.getElementById('workflowsRoot');
    if (!root) return;

    root.innerHTML = `
        <section class="workflow-page erp-workflow">
            <div class="workflow-hero">
                <div>
                    <span>Process Map ERP</span>
                    <h2>Lead → Báo giá → Đơn bán → Kiểm tồn → Kho → Hóa đơn → Thu tiền</h2>
                    <p>Luồng chuẩn thể hiện đầy đủ nhánh đủ tồn, thiếu tồn, mua hàng bổ sung, giao hàng, công nợ và lớp kiểm soát bằng task, cảnh báo, KPI, nhật ký.</p>
                </div>
                <div class="workflow-health">
                    <strong>Luồng chuẩn ERP</strong>
                    <span>Sales + Inventory + Procurement + Finance + Control</span>
                </div>
            </div>

            <div class="workflow-kpi-row">
                ${metricCard('14 bước', 'Từ lead đến hoàn tất đơn', 'blue')}
                ${metricCard('2 nhánh', 'Đủ tồn hoặc thiếu tồn', 'orange')}
                ${metricCard('5 bộ phận', 'Kinh doanh, kho, mua hàng, tài chính, kiểm soát', 'green')}
                ${metricCard('4 kiểm soát', 'Task, cảnh báo, KPI, nhật ký', 'violet')}
            </div>

            <div class="erp-process-map">
                ${renderProcessMap()}
            </div>

            <div class="workflow-kanban">
                ${kanban.map(renderKanbanColumn).join('')}
            </div>
        </section>
    `;
}

function renderProcessMap() {
    const sales = [
        node('lead', 'Lead', 'Tiếp nhận nhu cầu', '/leads', 'blue'),
        node('quote', 'Báo giá', 'Tính giá và VAT', '/quotations', 'indigo'),
        node('approval', 'Duyệt báo giá', 'Duyệt margin thấp nếu có', '/quotations', 'violet'),
        node('order', 'Đơn bán', 'Chốt đơn và kiểm tồn', '/sales-orders', 'blue'),
        node('stock_check', 'Kiểm tồn', 'Đủ tồn / thiếu tồn / rủi ro công nợ', '/sales-orders', 'orange'),
    ];
    const enoughStock = [
        node('stock_ok', 'Đủ tồn', 'Giữ hàng tự động', '/sales-orders', 'green'),
        node('reserve', 'Giữ hàng', 'Khóa tồn cho đơn bán', '/inventory', 'green'),
        node('issue', 'Xuất kho', 'Xác nhận phiếu xuất', '/goods-issues', 'green'),
        node('delivery', 'Giao hàng', 'Cập nhật đã giao', '/deliveries', 'green'),
    ];
    const shortage = [
        node('stock_low', 'Thiếu tồn', 'Tạo cảnh báo / PR nháp', '/alerts', 'orange'),
        node('pr', 'Yêu cầu mua', 'Duyệt nhu cầu mua', '/purchase-requests', 'orange'),
        node('po', 'Đơn mua', 'Duyệt PO nhà cung cấp', '/purchase-orders', 'orange'),
        node('receipt', 'Nhập kho', 'Tăng tồn rồi quay lại xuất kho', '/goods-receipts', 'orange'),
    ];
    const finance = [
        node('invoice', 'Hóa đơn', 'Ghi nhận công nợ', '/sales-invoices', 'violet'),
        node('receivable', 'Công nợ', 'Theo dõi hạn mức và quá hạn', '/customer-receivables', 'violet'),
        node('payment', 'Thu tiền', 'Ghi nhận thanh toán', '/customer-payments', 'green'),
        node('complete', 'Hoàn tất', 'Đóng đơn và công nợ', '/sales-orders', 'green'),
    ];
    const control = [
        node('tasks', 'Công việc', 'Việc tự sinh theo luồng', '/tasks', 'blue'),
        node('alert', 'Cảnh báo', 'Thiếu tồn, quá hạn, rủi ro', '/alerts', 'orange'),
        node('kpi_node', 'KPI', 'Đo hiệu suất vận hành', '/kpi', 'violet'),
        node('audit', 'Nhật ký', 'Truy vết thao tác', '/audit-logs', 'blue'),
    ];

    return `
        <div class="process-stage">
            <div class="process-stage-title">Bán hàng</div>
            <div class="process-chain columns-5">${sales.map((item, index) => `${processNode(item)}${index < sales.length - 1 ? arrow() : ''}`).join('')}</div>
        </div>
        <div class="process-branch-title">Sau bước kiểm tồn, hệ thống tách thành 2 nhánh xử lý</div>
        <div class="process-split-grid">
            <section class="process-lane ok">
                <h3>Nhánh đủ tồn</h3>
                <div class="process-chain columns-4">${enoughStock.map((item, index) => `${processNode(item)}${index < enoughStock.length - 1 ? arrow() : ''}`).join('')}</div>
            </section>
            <section class="process-lane warning">
                <h3>Nhánh thiếu tồn</h3>
                <div class="process-chain columns-4">${shortage.map((item, index) => `${processNode(item)}${index < shortage.length - 1 ? arrow() : ''}`).join('')}</div>
            </section>
        </div>
        <div class="process-stage">
            <div class="process-stage-title">Tài chính và hoàn tất</div>
            <div class="process-chain columns-4">${finance.map((item, index) => `${processNode(item)}${index < finance.length - 1 ? arrow() : ''}`).join('')}</div>
        </div>
        <div class="process-stage control">
            <div class="process-stage-title">Kiểm soát song song</div>
            <div class="process-chain columns-4">${control.map((item, index) => `${processNode(item)}${index < control.length - 1 ? arrow() : ''}`).join('')}</div>
        </div>
    `;
}

function node(key, label, note, href, tone) {
    return { key, label, note, href, tone };
}

function processNode(item) {
    return `
        <a class="process-node ${item.tone} ${item.key}" href="${item.href}">
            <i aria-hidden="true"></i>
            <strong>${VKTable.escapeHtml(item.label)}</strong>
            <span>${VKTable.escapeHtml(item.note)}</span>
        </a>
    `;
}

function arrow() {
    return `<span class="process-arrow" aria-hidden="true"></span>`;
}

function metricCard(value, label, tone) {
    return `
        <div class="workflow-metric ${tone}">
            <strong>${VKTable.escapeHtml(value)}</strong>
            <span>${VKTable.escapeHtml(label)}</span>
        </div>
    `;
}

function renderKanbanColumn(column) {
    return `
        <section class="workflow-kanban-column">
            <div class="workflow-kanban-head">
                <h3>${VKTable.escapeHtml(column.title)}</h3>
                <span>${VKTable.escapeHtml(column.metric)}</span>
            </div>
            <div class="workflow-kanban-cards">
                ${column.cards.map(([title, note, href], index) => `
                    <a class="workflow-kanban-card" href="${href}">
                        <b>${index + 1}</b>
                        <div>
                            <strong>${VKTable.escapeHtml(title)}</strong>
                            <span>${VKTable.escapeHtml(note)}</span>
                        </div>
                    </a>
                `).join('')}
            </div>
        </section>
    `;
}

window.loadWorkflows = loadWorkflows;
})();
