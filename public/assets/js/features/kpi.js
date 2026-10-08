(function () {
document.addEventListener('vk:ready', () => loadKpi());
document.addEventListener('click', async (event) => {
    const button = event.target.closest('[data-kpi-calculate]');
    if (!button) return;

    button.disabled = true;
    button.textContent = 'Đang tính...';

    try {
        await VKApi.request('/kpi/snapshots/calculate', { method: 'POST', body: JSON.stringify({ period_type: 'day' }) });
        await loadKpi();
    } catch (error) {
        button.disabled = false;
        button.textContent = 'Tính lại KPI';
        VKModal.toast(error.message || 'Không thể tính lại KPI.', 'danger');
    }
});

async function loadKpi(options = {}) {
    const root = document.getElementById('kpiRoot');
    if (!root) return;
    if (options.source !== 'pjax') {
        root.innerHTML = renderLoading();
    }

    const fallback = { data: [], meta: { total: 0 } };
    const [
        kpiOverview,
        snapshots,
        leads,
        quotations,
        salesOrders,
        purchaseRequests,
        purchaseOrders,
        balances,
        receipts,
        issues,
        tasks,
        alerts,
    ] = await Promise.all([
        safeRequest('/kpi/overview'),
        safeRequest('/kpi/snapshots?period_type=day&page_size=30'),
        safeRequest('/leads?page_size=100'),
        safeRequest('/quotations?page_size=100'),
        safeRequest('/sales-orders?page_size=100'),
        safeRequest('/purchase-requests?page_size=100'),
        safeRequest('/purchase-orders?page_size=100'),
        safeRequest('/inventory-balances?page_size=100'),
        safeRequest('/goods-receipts?page_size=100'),
        safeRequest('/goods-issues?page_size=100'),
        safeRequest('/tasks?page_size=100'),
        safeRequest('/alerts?page_size=100'),
    ]);

    const pageData = {
        kpiOverview: kpiOverview || null,
        snapshots: snapshots?.data || [],
        leads: leads || fallback,
        quotations: quotations || fallback,
        salesOrders: salesOrders || fallback,
        purchaseRequests: purchaseRequests || fallback,
        purchaseOrders: purchaseOrders || fallback,
        balances: balances || fallback,
        receipts: receipts || fallback,
        issues: issues || fallback,
        tasks: tasks || fallback,
        alerts: alerts || fallback,
    };
    root.innerHTML = renderKpi(pageData);
    openRequestedKpiException(pageData.kpiOverview?.data?.exceptions || []);
}

async function safeRequest(path) {
    try {
        return await VKApi.request(path);
    } catch (error) {
        return null;
    }
}

function renderKpi(data) {
    const backend = data.kpiOverview?.data || null;
    const metrics = backend?.metrics || buildMetrics(data);
    const bestRows = [
        ['Doanh số', metrics.salesScore, delta(metrics.salesScore, 74), 'success'],
        ['Tồn kho khả dụng', metrics.inventoryScore, delta(metrics.inventoryScore, 72), 'success'],
        ['Tỷ lệ xử lý mua hàng', metrics.procurementScore, delta(metrics.procurementScore, 70), 'info'],
        ['SLA công việc', metrics.workflowScore, delta(metrics.workflowScore, 58), 'warning'],
    ].sort((a, b) => b[1] - a[1]);
    const cautionRows = [
        ['Workflow', metrics.workflowScore, delta(metrics.workflowScore, 58), 'danger'],
        ['Công việc quá hạn', Math.max(0, 100 - metrics.overdueTasks * 10), metrics.overdueTasks ? `-${metrics.overdueTasks} việc` : 'Ổn định', 'danger'],
        ['Tỷ lệ duyệt PO', metrics.poApprovalRate, delta(metrics.poApprovalRate, 65), 'warning'],
        ['Tồn dưới mức tối thiểu', Math.max(0, 100 - metrics.lowStockRows * 12), metrics.lowStockRows ? `-${metrics.lowStockRows} mã` : 'Ổn định', 'warning'],
    ].sort((a, b) => a[1] - b[1]);

    return `
        <div class="kpi-command">
            ${renderOverview(metrics)}
            <div class="kpi-module-grid">
                ${moduleCard({
                    title: 'Kinh doanh',
                    tone: 'green',
                    score: metrics.salesScore,
                    change: delta(metrics.salesScore, 76),
                    href: '/sales-orders',
                    rows: [
                        ['Khách hàng tiềm năng', total(data.leads)],
                        ['Báo giá', total(data.quotations)],
                        ['Đơn bán', total(data.salesOrders)],
                        ['Doanh số', VKTable.money(metrics.revenue)],
                    ],
                })}
                ${moduleCard({
                    title: 'Kho vận',
                    tone: 'blue',
                    score: metrics.inventoryScore,
                    change: delta(metrics.inventoryScore, 72),
                    href: '/inventory',
                    rows: [
                        ['Dòng tồn', total(data.balances)],
                        ['Nhập kho', total(data.receipts)],
                        ['Xuất kho', total(data.issues)],
                        ['Tồn khả dụng', VKTable.money(metrics.available)],
                    ],
                })}
                ${moduleCard({
                    title: 'Mua hàng',
                    tone: 'orange',
                    score: metrics.procurementScore,
                    change: delta(metrics.procurementScore, 70),
                    href: '/purchase-orders',
                    rows: [
                        ['Yêu cầu mua', total(data.purchaseRequests)],
                        ['Đơn mua', total(data.purchaseOrders)],
                        ['PO đã duyệt', metrics.approvedPos],
                        ['Giá trị mua', VKTable.money(metrics.purchaseValue)],
                    ],
                })}
                ${moduleCard({
                    title: 'Workflow',
                    tone: 'red',
                    score: metrics.workflowScore,
                    change: delta(metrics.workflowScore, 58),
                    href: '/tasks',
                    rows: [
                        ['Công việc quá hạn', metrics.overdueTasks],
                        ['Hoàn thành', metrics.completedTasks],
                        ['Cảnh báo mở', metrics.openAlerts],
                        ['SLA đạt', `${metrics.slaRate}%`],
                    ],
                })}
            </div>

            ${renderBackendKpiPanel(backend)}

            <div class="kpi-analytics-grid">
                <section class="kpi-card kpi-trend-card">
                    <div class="kpi-card-head">
                        <div>
                            <h2>Lịch sử điểm KPI</h2>
                            <span>Chỉ hiển thị các lần tính KPI đã lưu; không dùng dữ liệu mô phỏng.</span>
                        </div>
                    </div>
                    ${trendChart(data.snapshots)}
                </section>

                ${rankPanel('KPI tốt nhất', bestRows.slice(0, 4), 'success')}
                ${rankPanel('KPI cần chú ý', cautionRows.slice(0, 4), 'warning')}
            </div>

            <section class="kpi-card kpi-alert-strip">
                <div class="kpi-card-head">
                    <div>
                        <h2>Cảnh báo quan trọng</h2>
                        <span>Các điểm cần ưu tiên theo dữ liệu hiện tại.</span>
                    </div>
                    <a class="text-link" href="/alerts">Xem tất cả cảnh báo</a>
                </div>
                <div class="kpi-alert-row">
                    ${importantAlert('Công việc quá hạn', metrics.overdueTasks, metrics.overdueTasks ? 'Cần xử lý ngay' : 'Không có việc quá hạn', 'red', '/tasks')}
                    ${importantAlert('Báo giá chờ duyệt', metrics.pendingQuotes, metrics.pendingQuotes ? 'Cần duyệt biên lợi nhuận' : 'Không có báo giá chờ', 'orange', '/quotations')}
                    ${importantAlert('Tồn dưới mức tối thiểu', metrics.lowStockRows, metrics.lowStockRows ? 'Cần bổ sung hàng' : 'Tồn kho ổn định', 'yellow', '/inventory')}
                    ${importantAlert('Cảnh báo đang mở', metrics.openAlerts, metrics.openAlerts ? 'Cần kiểm tra alert center' : 'Không có cảnh báo mở', 'blue', '/alerts')}
                </div>
            </section>
        </div>
    `;
}

function buildMetrics(data) {
    const revenue = sum(data.salesOrders.data, 'total_amount');
    const quotationValue = sum(data.quotations.data, 'total_amount');
    const purchaseValue = sum(data.purchaseOrders.data, 'total_amount');
    const available = sum(data.balances.data, 'available');
    const reserved = sum(data.balances.data, 'reserved');
    const orderTotal = total(data.salesOrders);
    const quotationTotal = total(data.quotations);
    const leadTotal = total(data.leads);
    const poTotal = total(data.purchaseOrders);
    const taskTotal = total(data.tasks);
    const completedTasks = countBy(data.tasks.data, row => row.status === 'completed');
    const overdueTasks = countBy(data.tasks.data, row => row.status === 'overdue');
    const resolvedAlerts = countBy(data.alerts.data, row => row.status === 'resolved');
    const openAlerts = countBy(data.alerts.data, row => row.status === 'open');
    const approvedPrs = countBy(data.purchaseRequests.data, row => row.status === 'approved');
    const approvedPos = countBy(data.purchaseOrders.data, row => ['approved', 'received', 'partially_received'].includes(row.status));
    const receivedPos = countBy(data.purchaseOrders.data, row => ['received', 'partially_received'].includes(row.status));
    const pendingQuotes = countBy(data.quotations.data, row => row.status === 'pending_approval');
    const lowStockRows = countBy(data.balances.data, isLowStock);
    const shortageOrders = countBy(data.salesOrders.data, row => row.stock_status === 'shortage');

    const salesScore = score([
        ratio(orderTotal, Math.max(quotationTotal, 1)) * 45,
        ratio(quotationTotal, Math.max(leadTotal, quotationTotal, 1)) * 25,
        ratio(revenue, Math.max(quotationValue, 1)) * 20,
        shortageOrders === 0 ? 10 : Math.max(0, 10 - shortageOrders * 3),
    ]);
    const inventoryScore = score([
        ratio(available, Math.max(available + reserved, 1)) * 45,
        lowStockRows === 0 ? 30 : Math.max(0, 30 - lowStockRows * 8),
        ratio(total(data.receipts) + total(data.issues), Math.max(poTotal + orderTotal, 1)) * 25,
    ]);
    const procurementScore = score([
        ratio(approvedPrs, Math.max(total(data.purchaseRequests), 1)) * 35,
        ratio(approvedPos, Math.max(poTotal, 1)) * 35,
        ratio(receivedPos, Math.max(poTotal, 1)) * 20,
        purchaseValue > 0 ? 10 : 0,
    ]);
    const slaRate = Math.round(ratio(completedTasks, Math.max(taskTotal, 1)) * 100);
    const workflowScore = score([
        ratio(completedTasks, Math.max(taskTotal, 1)) * 45,
        overdueTasks === 0 ? 25 : Math.max(0, 25 - overdueTasks * 6),
        ratio(resolvedAlerts, Math.max(total(data.alerts), 1)) * 20,
        openAlerts === 0 ? 10 : Math.max(0, 10 - openAlerts * 3),
    ]);
    const poApprovalRate = Math.round(ratio(approvedPos, Math.max(poTotal, 1)) * 100);
    const totalScore = average([salesScore, inventoryScore, procurementScore, workflowScore]);

    return {
        revenue,
        purchaseValue,
        available,
        salesScore,
        inventoryScore,
        procurementScore,
        workflowScore,
        totalScore,
        completedTasks,
        overdueTasks,
        openAlerts,
        pendingQuotes,
        lowStockRows,
        approvedPos,
        slaRate,
        poApprovalRate,
    };
}

function renderOverview(metrics) {
    return `
        <section class="kpi-overview">
            <div class="kpi-total-score">
                <span>Điểm vận hành tổng công ty (lũy kế)</span>
                <strong><b>${metrics.totalScore}</b>/100</strong>
                <small>Trung bình bốn nhóm nghiệp vụ, thang 100 điểm</small>
            </div>
            <div class="kpi-donut" style="--score:${metrics.totalScore}">
                <div>${metrics.totalScore}%</div>
            </div>
            <div class="kpi-rank-box">
                <span>Xếp hạng hiệu suất</span>
                <strong class="${rankTone(metrics.totalScore)}">${rankText(metrics.totalScore)}</strong>
                <small>Xếp hạng tham khảo theo điểm hiện tại</small>
                <div class="score-bar"><span style="width:${Math.min(metrics.totalScore, 100)}%"></span></div>
            </div>
            <div class="kpi-mini-chart"><small>Điểm hiện tại được tổng hợp từ bốn nhóm: bán hàng, kho vận, mua hàng và công việc.</small></div>
        </section>
    `;
}

function renderBackendKpiPanel(backend) {
    if (!backend) return '';

    const snapshot = backend.snapshot || null;
    const targets = backend.targets || [];
    const exceptions = backend.exceptions || [];

    return `
        <section class="kpi-card kpi-system-panel">
            <div class="kpi-card-head">
                <div>
                    <h2>Điều hành KPI</h2>
                    <span>Dữ liệu chốt từ hệ thống, mục tiêu và ngoại lệ KPI.</span>
                </div>
                <div class="panel-actions">
                    <a class="btn secondary small" href="/kpi-adjustments">Sổ điểm nhân viên</a>
                    ${canManageKpi() ? '<button class="btn secondary small" type="button" data-kpi-calculate>Tính lại KPI</button>' : ''}
                </div>
            </div>
            <div class="kpi-system-grid">
                <div class="kpi-system-box">
                    <span>Snapshot gần nhất</span>
                    <strong>${snapshot ? `${Number(snapshot.overall_score || 0).toFixed(0)}/100` : 'Chưa chốt'}</strong>
                    <small>${snapshot ? formatDate(snapshot.snapshot_date) : 'Bấm tính lại để tạo snapshot đầu tiên.'}</small>
                </div>
                <div class="kpi-system-box">
                    <span>Mục tiêu đang theo dõi</span>
                    <strong>${targets.length}</strong>
                    <small>${targets.length ? 'Đã có mục tiêu cho kỳ hiện tại.' : 'Chưa có mục tiêu KPI.'}</small>
                </div>
                <div class="kpi-system-box">
                    <span>Ngoại lệ KPI</span>
                    <strong>${exceptions.filter(row => row.status === 'pending').length}</strong>
                    <small>Đang chờ xem xét.</small>
                </div>
            </div>
            <div class="kpi-target-list">
                ${targets.slice(0, 5).map(target => `
                    <div>
                        <span>${VKTable.escapeHtml(target.definition?.name || 'KPI')}</span>
                        <strong>${Number(target.actual_value || 0).toFixed(0)} / ${Number(target.target_value || 0).toFixed(0)}</strong>
                        <small>${Number(target.score || 0).toFixed(0)} điểm</small>
                    </div>
                `).join('') || '<p class="muted">Chưa có mục tiêu KPI.</p>'}
            </div>
            <p class="role-permission-help" style="padding:0 16px 14px">Cách tính hiện tại: Bán hàng = chuyển đổi đơn/báo giá (45đ) + báo giá/lead (25đ) + giá trị đơn/báo giá (20đ) + không thiếu hàng (10đ). Kho = tồn khả dụng (45đ) + không dưới tồn tối thiểu (30đ) + xử lý nhập/xuất (25đ). Mua hàng = duyệt yêu cầu (35đ) + duyệt đơn mua (35đ) + nhận hàng (20đ) + có giá trị mua (10đ). Công việc = hoàn thành (45đ) + không quá hạn (25đ) + giải quyết cảnh báo (20đ) + không còn cảnh báo mở (10đ). Các tỷ lệ được giới hạn 0–100%; tổng điểm là trung bình bốn nhóm. Hiện dữ liệu tính lũy kế, chưa tách đúng từng kỳ hoặc từng nhân viên.</p>
        </section>
    `;
}

function moduleCard({ title, tone, score, change, rows, href }) {
    return `
        <section class="kpi-module-card ${tone}">
            <div class="kpi-module-top">
                <span class="kpi-module-icon"></span>
                <h2>${title}</h2>
                <a href="${href}" aria-label="Xem chi tiết ${title}">›</a>
            </div>
            <div class="kpi-module-score">
                <strong>${score}<small>/100</small></strong>
                <span class="${change.startsWith('-') ? 'down' : 'up'}">${change}</span>
            </div>
            <div class="score-bar"><span style="width:${score}%"></span></div>
            <div class="kpi-module-list">
                ${rows.map(([label, value]) => `<div><span>${label}</span><strong>${value}</strong></div>`).join('')}
            </div>
            <a class="kpi-card-link" href="${href}">Xem chi tiết <span>›</span></a>
        </section>
    `;
}

function rankPanel(title, rows, tone) {
    return `
        <section class="kpi-card">
            <div class="kpi-card-head">
                <div>
                    <h2>${title}</h2>
                    <span>${tone === 'success' ? 'Các chỉ số đang vận hành tốt.' : 'Các chỉ số nên ưu tiên theo dõi.'}</span>
                </div>
                <a class="text-link" href="/kpi">Xem tất cả</a>
            </div>
            <div class="kpi-rank-list">
                ${rows.map(row => rankItem(...row)).join('')}
            </div>
        </section>
    `;
}

function rankItem(label, value, change, tone) {
    return `
        <div class="kpi-rank-item ${tone}">
            <span class="rank-dot"></span>
            <strong>${label}</strong>
            <b>${value}/100</b>
            <small class="${String(change).startsWith('-') ? 'down' : 'up'}">${change}</small>
        </div>
    `;
}

function importantAlert(title, value, note, tone, href) {
    return `
        <a class="important-alert ${tone}" href="${href}">
            <span></span>
            <div>
                <strong>${VKTable.money(value)} ${title}</strong>
                <small>${note}</small>
            </div>
        </a>
    `;
}

function trendChart(snapshots) {
    const rows = [...snapshots].reverse();
    if (!rows.length) return '<p class="muted" style="padding:24px">Chưa có lịch sử KPI. Hãy tính KPI để bắt đầu lưu điểm theo ngày.</p>';
    if (rows.length === 1) return `<p class="muted" style="padding:24px">Đã có một lần tính ngày ${formatDate(rows[0].snapshot_date)}: ${Number(rows[0].overall_score || 0).toFixed(0)}/100. Cần ít nhất hai ngày để vẽ xu hướng.</p>`;
    const width = 560;
    const height = 210;
    const plot = { left: 36, right: 12, top: 16, bottom: 32 };
    const chartW = width - plot.left - plot.right;
    const chartH = height - plot.top - plot.bottom;
    const pathFor = values => values.map((value, index) => {
        const x = plot.left + (index / (values.length - 1)) * chartW;
        const y = plot.top + (1 - Math.max(0, Math.min(100, Number(value || 0))) / 100) * chartH;
        return `${index ? 'L' : 'M'}${x.toFixed(1)} ${y.toFixed(1)}`;
    }).join(' ');
    const values = rows.map(row => row.overall_score);

    return `
        <div class="kpi-trend">
            <div class="kpi-legend"><span><i style="background:#2563eb"></i>Điểm KPI đã lưu (${rows.length} ngày)</span></div>
            <svg viewBox="0 0 ${width} ${height}" role="img" aria-label="Xu hướng điểm KPI thực tế">
                ${[0, 25, 50, 75, 100].map(value => {
                    const y = plot.top + (1 - value / 100) * chartH;
                    return `<g><line x1="${plot.left}" y1="${y}" x2="${width - plot.right}" y2="${y}"></line><text x="4" y="${y + 4}">${value}</text></g>`;
                }).join('')}
                <path d="${pathFor(values)}" stroke="#2563eb"></path>
                ${values.map((value, index) => {
                    const x = plot.left + (index / (values.length - 1)) * chartW;
                    const y = plot.top + (1 - Number(value || 0) / 100) * chartH;
                    return `<circle cx="${x.toFixed(1)}" cy="${y.toFixed(1)}" r="3" fill="#2563eb"><title>${formatDate(rows[index].snapshot_date)}: ${Number(value || 0).toFixed(0)}/100</title></circle>`;
                }).join('')}
                <text x="${plot.left}" y="${height - 8}">${formatDate(rows[0].snapshot_date)}</text>
                <text x="${width - 86}" y="${height - 8}">${formatDate(rows[rows.length - 1].snapshot_date)}</text>
            </svg>
        </div>
    `;
}

function renderLoading() {
    return `
        <div class="kpi-command">
            <section class="kpi-overview"><div class="skeleton large"></div><div class="skeleton large"></div><div class="skeleton large"></div></section>
            <div class="kpi-module-grid">
                ${Array.from({ length: 4 }).map(() => '<section class="kpi-module-card"><div class="skeleton"></div><div class="skeleton large"></div><div class="skeleton"></div></section>').join('')}
            </div>
        </div>
    `;
}

function total(packet) {
    return Number(packet?.meta?.total ?? packet?.data?.length ?? 0);
}

function score(parts) {
    return clamp(Math.round(parts.reduce((totalValue, item) => totalValue + item, 0)), 0, 100);
}

function ratio(value, base) {
    return Math.max(0, Math.min(1, Number(value || 0) / Number(base || 1)));
}

function average(values) {
    const valid = values.filter(value => Number.isFinite(value));
    if (!valid.length) return 0;
    return Math.round(valid.reduce((sumValue, value) => sumValue + value, 0) / valid.length);
}

function sum(rows, field) {
    return rows.reduce((totalValue, row) => totalValue + Number(row[field] || 0), 0);
}

function countBy(rows, predicate) {
    return rows.filter(predicate).length;
}

function isLowStock(row) {
    return Number(row.available || 0) <= Number(row.sku?.min_stock || 0);
}

function clamp(value, min, max) {
    return Math.max(min, Math.min(max, value));
}

function delta(value, base) {
    const diff = Math.round(value - base);
    return `${diff >= 0 ? '+' : ''}${diff} điểm`;
}

function formatDate(value) {
    if (!value) return '-';
    return new Date(value).toLocaleDateString('vi-VN');
}

function rankText(value) {
    if (value >= 85) return 'Xuất sắc';
    if (value >= 70) return 'Khá tốt';
    if (value >= 50) return 'Cần theo dõi';
    return 'Cần cải thiện';
}

function rankTone(value) {
    if (value >= 70) return 'good';
    if (value >= 50) return 'watch';
    return 'risk';
}

function canManageKpi() {
    return Array.isArray(window.VKUser?.permissions) && window.VKUser.permissions.includes('kpi.lock');
}

function openRequestedKpiException(exceptions) {
    const id = new URLSearchParams(window.location.search).get('exception');
    if (!id || !Array.isArray(exceptions)) return;

    const row = exceptions.find(item => String(item.id) === String(id));
    if (!row) {
        VKModal.toast('Không tìm thấy ngoại lệ KPI trong dữ liệu hiện tại.', 'warning');
        return;
    }

    const statusMap = {
        pending: ['warning', 'Chờ duyệt'],
        approved: ['success', 'Đã duyệt'],
        rejected: ['danger', 'Từ chối'],
    };
    const [tone, label] = statusMap[row.status] || ['info', row.status || '-'];

    VKModal.open(`Chi tiết ngoại lệ KPI KPI-EXC-${row.id}`, `
        <div class="kpi-adjustment-detail">
            <section class="kpi-adjustment-hero">
                <div>
                    <span>${VKTable.escapeHtml(row.definition?.name || 'Ngoại lệ KPI')}</span>
                    <strong>${VKTable.escapeHtml(row.user?.name || '-')}</strong>
                    <small>${formatDate(row.period_start)} - ${formatDate(row.period_end)}</small>
                </div>
                <span class="badge ${tone}">${VKTable.escapeHtml(label)}</span>
            </section>
            <div class="detail-grid two">
                ${detailItem('Nhân viên', `${row.user?.name || '-'}${row.user?.email ? ` · ${row.user.email}` : ''}`)}
                ${detailItem('KPI', row.definition?.name || '-')}
                ${detailItem('Kỳ tính', row.period_type || '-')}
                ${detailItem('Thời gian duyệt', row.reviewed_at ? formatDate(row.reviewed_at) : '-')}
            </div>
            <section class="kpi-adjustment-note">
                <h3>Lý do giải trình</h3>
                <p>${VKTable.escapeHtml(row.reason || '-')}</p>
            </section>
            ${row.review_note ? `
                <section class="kpi-adjustment-note">
                    <h3>Ghi chú duyệt</h3>
                    <p>${VKTable.escapeHtml(row.review_note)}</p>
                </section>
            ` : ''}
            ${row.evidence_url ? `<a class="text-link" target="_blank" rel="noopener" href="${VKTable.escapeHtml(row.evidence_url)}">Mở bằng chứng</a>` : ''}
        </div>
    `, null, {
        className: 'wide-modal',
        hideSubmit: true,
        cancelText: 'Đóng',
    });
}

function detailItem(label, value) {
    return `
        <div class="detail-item">
            <span>${VKTable.escapeHtml(label)}</span>
            <strong>${VKTable.escapeHtml(value || '-')}</strong>
        </div>
    `;
}

window.loadKpi = loadKpi;
})();
