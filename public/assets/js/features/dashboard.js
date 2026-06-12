document.addEventListener('vk:ready', () => loadDashboard());

async function loadDashboard() {
    const root = document.getElementById('dashboardRoot');
    if (!root) return;
    root.innerHTML = renderDashboardLoading();

    const fallback = { data: [], meta: { total: 0 } };
    const [
        tasks,
        alerts,
        leads,
        quotations,
        pendingQuotes,
        salesOrders,
        balances,
        purchaseRequests,
        purchaseOrders,
        receipts,
        issues,
        notifications,
    ] = await Promise.all([
        safeRequest('/tasks?mine=1&page_size=100'),
        safeRequest('/alerts?mine=1&status=open&page_size=100'),
        safeRequest('/leads?page_size=100'),
        safeRequest('/quotations?page_size=100'),
        safeRequest('/quotations?status=pending_approval&page_size=100'),
        safeRequest('/sales-orders?page_size=100'),
        safeRequest('/inventory-balances?page_size=100'),
        safeRequest('/purchase-requests?page_size=100'),
        safeRequest('/purchase-orders?page_size=100'),
        safeRequest('/goods-receipts?page_size=100'),
        safeRequest('/goods-issues?page_size=100'),
        safeRequest('/notifications?page_size=5'),
    ]);

    root.innerHTML = renderDashboard({
        tasks: tasks || fallback,
        alerts: alerts || fallback,
        leads: leads || fallback,
        quotations: quotations || fallback,
        pendingQuotes: pendingQuotes || fallback,
        salesOrders: salesOrders || fallback,
        balances: balances || fallback,
        purchaseRequests: purchaseRequests || fallback,
        purchaseOrders: purchaseOrders || fallback,
        receipts: receipts || fallback,
        issues: issues || fallback,
        notifications: notifications || fallback,
    });
}

async function safeRequest(path) {
    try {
        return await VKApi.request(path);
    } catch (error) {
        return null;
    }
}

function renderDashboard(data) {
    const activeTasks = data.tasks.data.filter(row => ['new', 'assigned', 'in_progress', 'overdue'].includes(row.status));
    const overdueTasks = data.tasks.data.filter(row => row.status === 'overdue');
    const waitingApprovals = data.pendingQuotes.meta.total
        + data.purchaseRequests.data.filter(row => row.status === 'draft').length
        + data.purchaseOrders.data.filter(row => row.status === 'draft').length;
    const openAlerts = data.alerts.meta.total;
    const shortageRows = data.balances.data.filter(row => Number(row.available || 0) <= Number(row.sku?.min_stock || 0));

    return `
        <section class="dashboard-hello">
            <div>
                <h2>Xin chào, ${VKTable.escapeHtml(window.VKUser?.name || 'Người dùng')}</h2>
                <span>Hôm nay là ${formatLongDate(new Date())}</span>
            </div>
        </section>

        <div class="dashboard-kpi-row">
            ${heroMetric('Việc cần xử lý', activeTasks.length, 'Ưu tiên trong ngày', 'tasks')}
            ${heroMetric('Việc quá hạn', overdueTasks.length, overdueTasks.length ? 'Cần xử lý ngay' : 'Không có việc quá hạn', 'overdue')}
            ${heroMetric('Chờ phê duyệt', waitingApprovals, 'Báo giá, PR, PO', 'approval')}
            ${heroMetric('Cảnh báo', openAlerts, openAlerts ? 'Có điểm cần chú ý' : 'Hệ thống ổn định', 'alert')}
        </div>

        <div class="dashboard-modern-grid">
            <section class="dashboard-card">
                <div class="panel-head">
                    <div>
                        <h2>Việc cần xử lý ngay</h2>
                        <span>Công việc đang mở theo người phụ trách.</span>
                    </div>
                    <a class="text-link" href="/tasks">Xem tất cả</a>
                </div>
                ${renderActionTasks(activeTasks)}
            </section>

            <section class="dashboard-card">
                <div class="panel-head">
                    <div>
                        <h2>KPI vận hành</h2>
                        <span>Tổng hợp từ dữ liệu hiện có.</span>
                    </div>
                    <a class="text-link" href="/kpi">Xem chi tiết</a>
                </div>
                ${renderKpiSummary(data)}
            </section>

            <section class="dashboard-card">
                <div class="panel-head">
                    <div>
                        <h2>Thông báo mới</h2>
                        <span>Các sự kiện vừa phát sinh.</span>
                    </div>
                    <a class="text-link" href="/alerts">Xem tất cả</a>
                </div>
                ${renderNotifications(data.notifications.data)}
            </section>

            <section class="dashboard-card">
                <div class="panel-head">
                    <div>
                        <h2>Cảnh báo</h2>
                        <span>Tồn kho, công việc và biên lợi nhuận cần chú ý.</span>
                    </div>
                    <a class="text-link" href="/alerts">Xem tất cả</a>
                </div>
                ${renderAlerts(data.alerts.data, shortageRows)}
            </section>
        </div>
    `;
}

function heroMetric(label, value, note, icon) {
    return `
        <section class="hero-metric ${icon}">
            <span class="hero-icon" aria-hidden="true"></span>
            <div>
                <span>${label}</span>
                <strong>${VKTable.money(value)}</strong>
                <small>${note}</small>
            </div>
        </section>
    `;
}

function renderActionTasks(rows) {
    if (!rows.length) {
        return emptyBlock('Không có việc cần xử lý', 'Các công việc mới sẽ hiển thị tại đây.');
    }

    return `<div class="priority-list">
        ${rows.slice(0, 5).map(row => `
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

function renderKpiSummary(data) {
    const orderTotal = data.salesOrders.meta.total || 0;
    const completedOrders = data.salesOrders.data.filter(row => ['completed', 'confirmed'].includes(row.status)).length;
    const leadTotal = data.leads.meta.total || 0;
    const quotationTotal = data.quotations.meta.total || 0;
    const reservedOrders = data.salesOrders.data.filter(row => row.stock_status === 'reserved').length;
    const receivedPos = data.purchaseOrders.data.filter(row => ['received', 'partially_received'].includes(row.status)).length;
    const poTotal = data.purchaseOrders.meta.total || 0;
    const score = average([
        percent(completedOrders, orderTotal),
        percent(quotationTotal, Math.max(leadTotal, quotationTotal)),
        percent(reservedOrders, orderTotal),
        percent(receivedPos, poTotal),
    ]);

    const rows = [
        ['Đơn bán xử lý', percent(completedOrders, orderTotal), `${completedOrders} / ${orderTotal} đơn`],
        ['Chuyển đổi khách hàng tiềm năng', percent(quotationTotal, Math.max(leadTotal, quotationTotal)), `${quotationTotal} / ${Math.max(leadTotal, quotationTotal)} hồ sơ`],
        ['Đã giữ hàng', percent(reservedOrders, orderTotal), `${reservedOrders} / ${orderTotal} đơn`],
        ['PO đã nhận hàng', percent(receivedPos, poTotal), `${receivedPos} / ${poTotal} đơn`],
    ];

    return `
        <div class="kpi-modern">
            <div class="kpi-ring" style="--score:${score}">
                <div>
                    <span>Tổng hợp</span>
                    <strong>${score}%</strong>
                    <small>Hoàn thành</small>
                </div>
            </div>
            <div class="kpi-modern-list">
                ${rows.map(([label, value, note]) => `
                    <div>
                        <span>${label}</span>
                        <strong>${value}%</strong>
                        <small>${note}</small>
                    </div>
                `).join('')}
            </div>
        </div>
        <div class="kpi-note">
            <strong>Đánh giá</strong>
            <span>${score >= 70 ? 'Vận hành đang ổn. Tiếp tục duy trì nhịp xử lý trong ngày.' : 'Cần ưu tiên các mục đang chờ và cảnh báo mở.'}</span>
        </div>
    `;
}

function renderNotifications(rows) {
    if (!rows.length) {
        return emptyBlock('Chưa có thông báo mới', 'Thông báo nghiệp vụ sẽ xuất hiện tại đây.');
    }

    return `<div class="simple-feed">
        ${rows.slice(0, 4).map(row => `
            <a class="feed-item" href="${row.action_url || '/alerts'}">
                <span class="feed-icon"></span>
                <div>
                    <strong>${VKTable.escapeHtml(row.title || 'Thông báo')}</strong>
                    <small>${formatDate(row.created_at)}</small>
                </div>
            </a>
        `).join('')}
    </div>`;
}

function renderAlerts(alerts, shortages) {
    const rows = [
        ...alerts.slice(0, 3).map(row => ({
            title: row.title || VKTable.translateType(row.alert_type),
            message: row.message || VKTable.translateStatus(row.status),
            level: row.level || 'warning',
            at: row.created_at,
        })),
        ...shortages.slice(0, Math.max(0, 3 - alerts.length)).map(row => ({
            title: `Tồn kho ${row.sku?.sku_code || ''} dưới mức tối thiểu`,
            message: `${row.warehouse?.name || 'Kho'} · Khả dụng: ${VKTable.money(row.available)} · Tối thiểu: ${VKTable.money(row.sku?.min_stock || 0)}`,
            level: 'warning',
            at: row.updated_at,
        })),
    ];

    if (!rows.length) {
        return emptyBlock('Không có cảnh báo mở', 'Hệ thống đang ở trạng thái ổn định.');
    }

    return `<div class="alert-feed">
        ${rows.slice(0, 4).map(row => `
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

function emptyBlock(title, subtitle) {
    return `<div class="dashboard-empty"><strong>${title}</strong><span>${subtitle}</span></div>`;
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
