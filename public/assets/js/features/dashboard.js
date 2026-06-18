(function () {
document.addEventListener('vk:ready', () => loadDashboard());

async function loadDashboard(options = {}) {
    const root = document.getElementById('dashboardRoot');
    if (!root) return;
    if (options.source !== 'pjax') root.innerHTML = renderDashboardLoading();

    const fallback = { data: [], meta: { total: 0 } };
    const profile = getDashboardProfile();
    const summary = await safeRequest(`/dashboard/summary?type=${encodeURIComponent(profile.type)}`);
    if (summary?.data) {
        root.innerHTML = renderDashboard(withDashboardDefaults(summary.data));
        return;
    }

    const paths = dashboardPaths(profile.type);
    const entries = await Promise.all(Object.entries(paths).map(async ([key, path]) => [key, await safeRequest(path)]));
    const responses = Object.fromEntries(entries);

    root.innerHTML = renderDashboard(withDashboardDefaults({
        tasks: responses.tasks || fallback,
        alerts: responses.alerts || fallback,
        leads: responses.leads || fallback,
        quotations: responses.quotations || fallback,
        pendingQuotes: responses.pendingQuotes || fallback,
        salesOrders: responses.salesOrders || fallback,
        balances: responses.balances || fallback,
        purchaseRequests: responses.purchaseRequests || fallback,
        purchaseOrders: responses.purchaseOrders || fallback,
        receipts: responses.receipts || fallback,
        issues: responses.issues || fallback,
        invoices: responses.invoices || fallback,
        receivables: responses.receivables || fallback,
        notifications: responses.notifications || fallback,
    }));
}

function withDashboardDefaults(data) {
    const fallback = { data: [], meta: { total: 0 } };

    return {
        tasks: data.tasks || fallback,
        alerts: data.alerts || fallback,
        leads: data.leads || fallback,
        quotations: data.quotations || fallback,
        pendingQuotes: data.pendingQuotes || fallback,
        salesOrders: data.salesOrders || fallback,
        balances: data.balances || fallback,
        purchaseRequests: data.purchaseRequests || fallback,
        purchaseOrders: data.purchaseOrders || fallback,
        receipts: data.receipts || fallback,
        issues: data.issues || fallback,
        invoices: data.invoices || fallback,
        receivables: data.receivables || fallback,
        notifications: data.notifications || fallback,
    };
}

function dashboardPaths(type) {
    const base = {
        tasks: '/tasks?mine=1&page_size=25',
        alerts: '/alerts?mine=1&status=open&page_size=10',
    };

    const sharedBusiness = {
        leads: '/leads?page_size=20',
        quotations: '/quotations?page_size=20',
        pendingQuotes: '/quotations?status=pending_approval&page_size=5',
        salesOrders: '/sales-orders?page_size=20',
        purchaseRequests: '/purchase-requests?page_size=20',
        purchaseOrders: '/purchase-orders?page_size=20',
    };

    if (type === 'warehouse') {
        return {
            ...base,
            balances: '/inventory-balances?page_size=50',
            receipts: '/goods-receipts?page_size=20',
            issues: '/goods-issues?page_size=20',
        };
    }

    if (type === 'procurement') {
        return {
            ...base,
            purchaseRequests: '/purchase-requests?page_size=50',
            purchaseOrders: '/purchase-orders?page_size=50',
            receipts: '/goods-receipts?page_size=20',
        };
    }

    if (type === 'finance') {
        return {
            ...base,
            salesOrders: '/sales-orders?page_size=20',
            invoices: '/sales-invoices?page_size=50',
            receivables: '/customer-receivables?page_size=50',
        };
    }

    if (type === 'sales') {
        return {
            ...base,
            leads: '/leads?page_size=30',
            quotations: '/quotations?page_size=30',
            pendingQuotes: '/quotations?status=pending_approval&page_size=10',
            salesOrders: '/sales-orders?page_size=30',
        };
    }

    return {
        ...base,
        ...sharedBusiness,
        balances: '/inventory-balances?page_size=30',
    };
}

async function safeRequest(path) {
    try {
        return await VKApi.request(path);
    } catch (error) {
        return null;
    }
}

function renderDashboard(data) {
    const profile = getDashboardProfile();
    const metrics = buildMetrics(profile, data);
    const tasks = data.tasks.data.filter(row => ['new', 'assigned', 'in_progress', 'overdue'].includes(row.status));
    const alerts = buildAlerts(data);

    return `
        <section class="dashboard-hello role-dashboard-hello">
            <div>
                <h2>${VKTable.escapeHtml(profile.title)}</h2>
                <span>${VKTable.escapeHtml(profile.subtitle)} · ${formatLongDate(new Date())}</span>
            </div>
            <span class="role-pill">${VKTable.escapeHtml(profile.label)}</span>
        </section>

        <div class="dashboard-kpi-row">
            ${metrics.map(metric => heroMetric(metric.label, metric.value, metric.note, metric.icon)).join('')}
        </div>

        <div class="dashboard-modern-grid role-dashboard-grid">
            <section class="dashboard-card">
                <div class="panel-head">
                    <div>
                        <h2>${VKTable.escapeHtml(profile.workTitle)}</h2>
                        <span>${VKTable.escapeHtml(profile.workSubtitle)}</span>
                    </div>
                    <a class="text-link" href="${profile.workHref}">Mở màn hình</a>
                </div>
                ${renderPrimaryWork(profile, data, tasks)}
            </section>

            <section class="dashboard-card">
                <div class="panel-head">
                    <div>
                        <h2>${VKTable.escapeHtml(profile.flowTitle)}</h2>
                        <span>${VKTable.escapeHtml(profile.flowSubtitle)}</span>
                    </div>
                    <a class="text-link" href="${profile.flowHref}">Xem chi tiết</a>
                </div>
                ${renderRoleFlow(profile, data)}
            </section>

            <section class="dashboard-card">
                <div class="panel-head">
                    <div>
                        <h2>Cảnh báo cần chú ý</h2>
                        <span>Các điểm nghẽn đang mở theo phạm vi dữ liệu của bạn.</span>
                    </div>
                    <a class="text-link" href="/alerts">Xem tất cả</a>
                </div>
                ${renderAlerts(alerts)}
            </section>

            <section class="dashboard-card">
                <div class="panel-head">
                    <div>
                        <h2>KPI nhanh</h2>
                        <span>Chỉ số tạm tính từ dữ liệu vận hành hiện có.</span>
                    </div>
                    <a class="text-link" href="/kpi">KPI</a>
                </div>
                ${renderKpiSummary(profile, data)}
            </section>
        </div>
    `;
}

function getDashboardProfile() {
    const user = window.VKUser || {};
    const roles = new Set(user.roles || []);
    const permissions = new Set(user.permissions || []);
    const name = user.name || 'Người dùng';

    if (roles.has('ROLE-ADMIN')) {
        return profile('Admin', `Xin chào, ${name}`, 'Toàn cảnh cấu hình, dữ liệu và luồng vận hành', 'Quản trị hệ thống', 'Việc cần xử lý toàn hệ thống', 'Task, cảnh báo và phê duyệt đang chờ.', '/tasks', 'Tổng quan vận hành', 'Sales, mua hàng, kho và công nợ.', '/dashboard', 'admin');
    }
    if (roles.has('ROLE-DIR')) {
        return profile('Ban giám đốc', `Xin chào, ${name}`, 'Tập trung rủi ro đỏ, doanh thu, công nợ và KPI', 'Điều hành', 'Điểm cần can thiệp', 'Cảnh báo, phê duyệt và task quá hạn.', '/alerts', 'Sức khỏe doanh nghiệp', 'Bán hàng, tồn kho, mua hàng và tài chính.', '/kpi', 'director');
    }
    if (roles.has('ROLE-WH') || permissions.has('inventory.issue.confirm')) {
        return profile('Kho vận', `Xin chào, ${name}`, 'Ưu tiên phiếu xuất, phiếu nhập và tồn kho thấp', 'Kho', 'Việc kho cần xử lý', 'Phiếu nhập/xuất và task kho đang mở.', '/goods-issues', 'Tình trạng tồn kho', 'Tồn khả dụng, giữ hàng và hàng dưới mức tối thiểu.', '/inventory', 'warehouse');
    }
    if (roles.has('ROLE-PUR') || permissions.has('procurement.po.approve')) {
        return profile('Mua hàng', `Xin chào, ${name}`, 'Theo dõi yêu cầu mua, đơn mua và tiến độ nhận hàng', 'Mua hàng', 'PR/PO cần xử lý', 'Nhu cầu mua, đơn mua nháp và PO trễ.', '/purchase-requests', 'Luồng mua hàng', 'PR, PO và nhập kho liên quan.', '/purchase-orders', 'procurement');
    }
    if (roles.has('ROLE-FIN') || permissions.has('finance.payment.view')) {
        return profile('Tài chính', `Xin chào, ${name}`, 'Theo dõi hóa đơn, công nợ và thanh toán', 'Tài chính', 'Công nợ cần xử lý', 'Hóa đơn chưa thanh toán và khách vượt hạn mức.', '/customer-receivables', 'Dòng tiền bán hàng', 'Hóa đơn, thanh toán và trạng thái đơn.', '/sales-invoices', 'finance');
    }
    if (roles.has('ROLE-MGR')) {
        return profile('Trưởng phòng', `Xin chào, ${name}`, 'Theo dõi việc phòng ban, phê duyệt và rủi ro SLA', 'Quản lý', 'Việc phòng ban cần xử lý', 'Task, cảnh báo và hồ sơ chờ phê duyệt.', '/tasks', 'Hiệu suất phòng ban', 'Pipeline, workload và KPI tạm tính.', '/kpi', 'manager');
    }

    return profile('Kinh doanh', `Xin chào, ${name}`, 'Tập trung lead, báo giá, đơn bán và công nợ khách', 'Kinh doanh', 'Việc bán hàng cần làm', 'Lead, báo giá, đơn bán và follow-up.', '/leads', 'Pipeline bán hàng', 'Lead, báo giá, đơn bán và giao hàng.', '/sales-orders', 'sales');
}

function profile(label, title, subtitle, pill, workTitle, workSubtitle, workHref, flowTitle, flowSubtitle, flowHref, type) {
    return { label, title, subtitle, pill, workTitle, workSubtitle, workHref, flowTitle, flowSubtitle, flowHref, type };
}

function buildMetrics(profile, data) {
    const tasks = data.tasks.data;
    const overdueTasks = tasks.filter(row => row.status === 'overdue');
    const openAlerts = data.alerts.meta.total || data.alerts.data.length;
    const lowStock = data.balances.data.filter(row => Number(row.available || 0) <= Number(row.sku?.min_stock || 0));
    const pendingQuotes = data.pendingQuotes.meta.total || data.pendingQuotes.data.length;
    const draftPrs = data.purchaseRequests.data.filter(row => row.status === 'draft');
    const draftPos = data.purchaseOrders.data.filter(row => row.status === 'draft');
    const pendingIssues = data.issues.data.filter(row => row.status === 'draft');
    const pendingReceipts = data.receipts.data.filter(row => row.status === 'draft');
    const unpaidInvoices = data.invoices.data.filter(row => ['issued', 'partially_paid'].includes(row.status));
    const debtRisk = data.receivables.data.filter(row => ['overdue', 'over_limit'].includes(row.status));

    if (profile.type === 'warehouse') {
        return [
            { label: 'Phiếu xuất chờ xử lý', value: pendingIssues.length, note: 'Cần xác nhận xuất kho', icon: 'tasks' },
            { label: 'Phiếu nhập chờ xử lý', value: pendingReceipts.length, note: 'Cần nhập kho', icon: 'approval' },
            { label: 'Tồn thấp', value: lowStock.length, note: 'Dưới mức tối thiểu', icon: 'overdue' },
            { label: 'Cảnh báo kho', value: openAlerts, note: 'Đang mở', icon: 'alert' },
        ];
    }
    if (profile.type === 'procurement') {
        return [
            { label: 'Yêu cầu mua nháp', value: draftPrs.length, note: 'Cần duyệt nhu cầu', icon: 'tasks' },
            { label: 'Đơn mua nháp', value: draftPos.length, note: 'Cần duyệt PO', icon: 'approval' },
            { label: 'PO chưa nhận', value: data.purchaseOrders.data.filter(row => !['received', 'cancelled'].includes(row.status)).length, note: 'Theo dõi giao hàng', icon: 'overdue' },
            { label: 'Cảnh báo', value: openAlerts, note: 'Đang mở', icon: 'alert' },
        ];
    }
    if (profile.type === 'finance') {
        return [
            { label: 'Hóa đơn chưa thu', value: unpaidInvoices.length, note: 'Cần theo dõi thanh toán', icon: 'tasks' },
            { label: 'Công nợ rủi ro', value: debtRisk.length, note: 'Quá hạn hoặc vượt hạn mức', icon: 'overdue' },
            { label: 'Đơn đã giao', value: data.salesOrders.data.filter(row => row.delivery_status === 'delivered').length, note: 'Có thể xuất hóa đơn', icon: 'approval' },
            { label: 'Cảnh báo', value: openAlerts, note: 'Đang mở', icon: 'alert' },
        ];
    }
    if (['admin', 'director', 'manager'].includes(profile.type)) {
        return [
            { label: 'Việc cần xử lý', value: tasks.filter(row => ['new', 'assigned', 'in_progress', 'overdue'].includes(row.status)).length, note: 'Theo phạm vi quyền', icon: 'tasks' },
            { label: 'Việc quá hạn', value: overdueTasks.length, note: overdueTasks.length ? 'Cần can thiệp' : 'Đang ổn', icon: 'overdue' },
            { label: 'Chờ phê duyệt', value: pendingQuotes + draftPrs.length + draftPos.length, note: 'Báo giá, PR, PO', icon: 'approval' },
            { label: 'Cảnh báo', value: openAlerts, note: 'Đang mở', icon: 'alert' },
        ];
    }

    return [
        { label: 'Lead đang theo dõi', value: data.leads.meta.total || data.leads.data.length, note: 'Khách hàng tiềm năng', icon: 'tasks' },
        { label: 'Báo giá chờ duyệt', value: pendingQuotes, note: 'Margin hoặc phê duyệt', icon: 'approval' },
        { label: 'Đơn bán đang mở', value: data.salesOrders.data.filter(row => !['completed', 'cancelled'].includes(row.status)).length, note: 'Cần theo dõi', icon: 'overdue' },
        { label: 'Cảnh báo', value: openAlerts, note: 'Đang mở', icon: 'alert' },
    ];
}

function renderPrimaryWork(profile, data, tasks) {
    if (profile.type === 'warehouse') {
        return renderWorkRows([
            ...data.issues.data.filter(row => row.status === 'draft').map(row => workRow('Phiếu xuất chờ xác nhận', row.code, '/goods-issues', row.status)),
            ...data.receipts.data.filter(row => row.status === 'draft').map(row => workRow('Phiếu nhập chờ xác nhận', row.code, '/goods-receipts', row.status)),
        ], 'Không có phiếu kho cần xử lý', 'Phiếu nhập/xuất mới sẽ xuất hiện tại đây.');
    }
    if (profile.type === 'procurement') {
        return renderWorkRows([
            ...data.purchaseRequests.data.filter(row => row.status === 'draft').map(row => workRow('Yêu cầu mua cần duyệt', row.code, '/purchase-requests', row.status)),
            ...data.purchaseOrders.data.filter(row => row.status === 'draft').map(row => workRow('Đơn mua cần duyệt', row.code, '/purchase-orders', row.status)),
        ], 'Không có PR/PO chờ xử lý', 'Nhu cầu mua mới sẽ hiển thị tại đây.');
    }
    if (profile.type === 'finance') {
        return renderWorkRows([
            ...data.receivables.data.filter(row => ['overdue', 'over_limit'].includes(row.status)).map(row => workRow(row.name, `Công nợ: ${VKTable.money(row.balance_amount)}`, '/customer-receivables', row.status)),
            ...data.invoices.data.filter(row => ['issued', 'partially_paid'].includes(row.status)).map(row => workRow('Hóa đơn chưa thanh toán', row.code, '/sales-invoices', row.status)),
        ], 'Công nợ đang ổn', 'Chưa có hóa đơn hoặc khách hàng cần nhắc.');
    }
    if (profile.type === 'sales') {
        return renderWorkRows([
            ...data.leads.data.slice(0, 3).map(row => workRow(row.name, row.code, '/leads', row.status)),
            ...data.quotations.data.filter(row => row.status === 'pending_approval').map(row => workRow('Báo giá chờ duyệt', row.code, '/quotations', row.status)),
            ...data.salesOrders.data.filter(row => !['completed', 'cancelled'].includes(row.status)).map(row => workRow('Đơn bán đang mở', row.code, '/sales-orders', row.status)),
        ], 'Không có việc bán hàng đang mở', 'Lead và đơn bán mới sẽ hiển thị tại đây.');
    }

    return renderActionTasks(tasks);
}

function renderRoleFlow(profile, data) {
    if (profile.type === 'warehouse') {
        return renderFlow([
            ['Tồn khả dụng', data.balances.data.reduce((sum, row) => sum + Number(row.available || 0), 0), 'SKU/kho'],
            ['Đã nhập kho', data.receipts.data.filter(row => row.status === 'confirmed').length, 'Phiếu'],
            ['Đã xuất kho', data.issues.data.filter(row => row.status === 'confirmed').length, 'Phiếu'],
            ['Tồn thấp', data.balances.data.filter(row => Number(row.available || 0) <= Number(row.sku?.min_stock || 0)).length, 'Mã hàng'],
        ]);
    }
    if (profile.type === 'procurement') {
        return renderFlow([
            ['PR nháp', data.purchaseRequests.data.filter(row => row.status === 'draft').length, 'Yêu cầu'],
            ['PR đã duyệt', data.purchaseRequests.data.filter(row => row.status === 'approved').length, 'Yêu cầu'],
            ['PO nháp', data.purchaseOrders.data.filter(row => row.status === 'draft').length, 'Đơn'],
            ['PO đã nhận', data.purchaseOrders.data.filter(row => row.status === 'received').length, 'Đơn'],
        ]);
    }
    if (profile.type === 'finance') {
        return renderFlow([
            ['Hóa đơn', data.invoices.meta.total || data.invoices.data.length, 'Tổng'],
            ['Đã thanh toán', data.invoices.data.filter(row => row.status === 'paid').length, 'Hóa đơn'],
            ['Còn công nợ', data.receivables.data.filter(row => Number(row.balance_amount || 0) > 0).length, 'Khách'],
            ['Rủi ro', data.receivables.data.filter(row => ['overdue', 'over_limit'].includes(row.status)).length, 'Khách'],
        ]);
    }

    return renderFlow([
        ['Lead', data.leads.meta.total || data.leads.data.length, 'Hồ sơ'],
        ['Báo giá', data.quotations.meta.total || data.quotations.data.length, 'Hồ sơ'],
        ['Đơn bán', data.salesOrders.meta.total || data.salesOrders.data.length, 'Đơn'],
        ['PR/PO', (data.purchaseRequests.meta.total || 0) + (data.purchaseOrders.meta.total || 0), 'Hồ sơ'],
    ]);
}

function renderWorkRows(rows, emptyTitle, emptySubtitle) {
    if (!rows.length) return emptyBlock(emptyTitle, emptySubtitle);

    return `<div class="priority-list">
        ${rows.slice(0, 6).map(row => `
            <a class="priority-item" href="${row.href}">
                <span class="priority-dot ${['overdue', 'over_limit', 'shortage'].includes(row.status) ? 'danger' : row.status === 'draft' ? 'warning' : 'info'}"></span>
                <div>
                    <strong>${VKTable.escapeHtml(row.title)}</strong>
                    <small>${VKTable.escapeHtml(row.note || '-')}</small>
                </div>
                ${VKTable.statusBadge(row.status)}
            </a>
        `).join('')}
    </div>`;
}

function workRow(title, note, href, status) {
    return { title, note, href, status };
}

function renderFlow(rows) {
    return `<div class="flow-track role-flow-track">
        ${rows.map(([label, value, note], index) => `
            <a class="flow-node" href="#">
                <span>${VKTable.escapeHtml(label)}</span>
                <strong>${VKTable.money(value)}</strong>
                <small>${VKTable.escapeHtml(note || '')}</small>
            </a>
        `).join('')}
    </div>`;
}

function heroMetric(label, value, note, icon) {
    return `
        <section class="hero-metric ${icon}">
            <span class="hero-icon" aria-hidden="true"></span>
            <div>
                <span>${VKTable.escapeHtml(label)}</span>
                <strong>${VKTable.money(value)}</strong>
                <small>${VKTable.escapeHtml(note)}</small>
            </div>
        </section>
    `;
}

function renderActionTasks(rows) {
    if (!rows.length) {
        return emptyBlock('Không có việc cần xử lý', 'Các công việc mới sẽ hiển thị tại đây.');
    }

    return `<div class="priority-list">
        ${rows.slice(0, 6).map(row => `
            <a class="priority-item" href="/tasks" data-task-id="${row.id}">
                <span class="priority-dot ${row.status === 'overdue' ? 'danger' : row.priority === 'high' ? 'warning' : 'info'}"></span>
                <div>
                    <strong>${VKTable.escapeHtml(row.title || row.code)}</strong>
                    <small>${VKTable.escapeHtml(row.code || '-')} · ${VKTable.translateStatus(row.status)} · ${formatDate(row.due_at)}</small>
                </div>
                ${VKTable.statusBadge(row.status)}
            </a>
        `).join('')}
    </div>`;
}

function buildAlerts(data) {
    const lowStock = data.balances.data
        .filter(row => Number(row.available || 0) <= Number(row.sku?.min_stock || 0))
        .map(row => ({
            title: `Tồn kho ${row.sku?.sku_code || ''} dưới mức tối thiểu`,
            message: `${row.warehouse?.name || 'Kho'} · Khả dụng: ${VKTable.money(row.available)} · Tối thiểu: ${VKTable.money(row.sku?.min_stock || 0)}`,
            level: 'warning',
            at: row.updated_at,
        }));

    return [
        ...data.alerts.data.map(row => ({
            title: row.title || VKTable.translateType(row.alert_type),
            message: row.message || VKTable.translateStatus(row.status),
            level: row.level || 'warning',
            at: row.created_at,
        })),
        ...lowStock,
    ];
}

function renderAlerts(rows) {
    if (!rows.length) {
        return emptyBlock('Không có cảnh báo mở', 'Hệ thống đang ở trạng thái ổn định.');
    }

    return `<div class="alert-feed">
        ${rows.slice(0, 5).map(row => `
            <a class="alert-feed-item ${row.level === 'high' ? 'danger' : 'warning'}" href="/alerts">
                <span></span>
                <div>
                    <strong>${VKTable.escapeHtml(row.title)}</strong>
                    <small>${VKTable.escapeHtml(row.message || '')}</small>
                </div>
                <em>${formatShortTime(row.at)}</em>
            </a>
        `).join('')}
    </div>`;
}

function renderKpiSummary(profile, data) {
    const orderTotal = data.salesOrders.meta.total || data.salesOrders.data.length;
    const completedOrders = data.salesOrders.data.filter(row => ['completed', 'confirmed'].includes(row.status)).length;
    const quotationTotal = data.quotations.meta.total || data.quotations.data.length;
    const leadTotal = data.leads.meta.total || data.leads.data.length;
    const receivedPos = data.purchaseOrders.data.filter(row => ['received', 'partially_received'].includes(row.status)).length;
    const poTotal = data.purchaseOrders.meta.total || data.purchaseOrders.data.length;
    const taskTotal = data.tasks.data.length;
    const doneTasks = data.tasks.data.filter(row => row.status === 'completed').length;

    const rows = [
        ['Đơn bán xử lý', percent(completedOrders, orderTotal), `${completedOrders} / ${orderTotal} đơn`],
        ['Lead có báo giá', percent(quotationTotal, Math.max(leadTotal, quotationTotal)), `${quotationTotal} / ${Math.max(leadTotal, quotationTotal)} hồ sơ`],
        ['PO đã nhận hàng', percent(receivedPos, poTotal), `${receivedPos} / ${poTotal} đơn`],
        ['Task hoàn thành', percent(doneTasks, Math.max(taskTotal, doneTasks)), `${doneTasks} / ${Math.max(taskTotal, doneTasks)} việc`],
    ];
    const score = average(rows.map(row => row[1]));

    return `
        <div class="kpi-modern">
            <div class="kpi-ring" style="--score:${score}">
                <div>
                    <span>Tổng hợp</span>
                    <strong>${score}%</strong>
                    <small>Tạm tính</small>
                </div>
            </div>
            <div class="kpi-modern-list">
                ${rows.map(([label, value, note]) => `
                    <div>
                        <span>${VKTable.escapeHtml(label)}</span>
                        <strong>${value}%</strong>
                        <small>${VKTable.escapeHtml(note)}</small>
                    </div>
                `).join('')}
            </div>
        </div>
        <div class="kpi-note">
            <strong>Gợi ý xử lý</strong>
            <span>${score >= 70 ? 'Vận hành đang ổn. Tiếp tục xử lý các mục mới trong ngày.' : 'Nên ưu tiên task quá hạn, phê duyệt đang chờ và cảnh báo mở.'}</span>
        </div>
    `;
}

function emptyBlock(title, subtitle) {
    return `<div class="dashboard-empty"><strong>${VKTable.escapeHtml(title)}</strong><span>${VKTable.escapeHtml(subtitle)}</span></div>`;
}

function percent(value, total) {
    if (!total) return 0;
    return Math.min(100, Math.round((Number(value || 0) / Number(total || 1)) * 100));
}

function average(values) {
    const valid = values.filter(value => Number.isFinite(value));
    if (!valid.length) return 0;
    return Math.round(valid.reduce((sum, value) => sum + value, 0) / valid.length);
}

function formatLongDate(value) {
    return new Intl.DateTimeFormat('vi-VN', {
        weekday: 'long',
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
    }).format(value);
}

function formatDate(value) {
    if (!value) return 'Chưa có hạn xử lý';
    return new Date(value).toLocaleString('vi-VN');
}

function formatShortTime(value) {
    if (!value) return '';
    return new Date(value).toLocaleString('vi-VN', { hour: '2-digit', minute: '2-digit', day: '2-digit', month: '2-digit' });
}

function renderDashboardLoading() {
    return `
        <div class="dashboard-kpi-row">
            ${Array.from({ length: 4 }).map(() => '<section class="hero-metric"><div class="skeleton large"></div></section>').join('')}
        </div>
    `;
}

window.loadDashboard = loadDashboard;
})();
