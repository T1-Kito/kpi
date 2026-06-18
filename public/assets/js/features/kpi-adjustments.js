(function () {
let state = {
    rows: [],
    summary: [],
    users: [],
    status: '',
    periodType: 'month',
};

window.loadKpiAdjustments = loadKpiAdjustments;

document.addEventListener('change', (event) => {
    if (!document.getElementById('kpiAdjustmentsRoot')) return;
    if (event.target.matches('[data-kpi-adjustment-status]')) {
        state.status = event.target.value;
        loadKpiAdjustments({ keepView: true });
    }
    if (event.target.matches('[data-kpi-adjustment-period]')) {
        state.periodType = event.target.value;
        loadKpiAdjustments({ keepView: true });
    }
});

document.addEventListener('click', (event) => {
    if (!document.getElementById('kpiAdjustmentsRoot')) return;

    if (event.target.matches('[data-create-kpi-adjustment]')) {
        openAdjustmentModal();
        return;
    }

    const reviewButton = event.target.closest('[data-review-kpi-adjustment]');
    if (reviewButton) {
        reviewAdjustment(reviewButton.dataset.reviewKpiAdjustment, reviewButton.dataset.status);
    }
});

async function loadKpiAdjustments(options = {}) {
    const root = document.getElementById('kpiAdjustmentsRoot');
    if (!root) return;
    if (root.dataset.serverRendered === '1') return;
    if (!options.keepView) {
        root.innerHTML = renderLoading();
    }

    const query = new URLSearchParams({ page_size: '100', period_type: state.periodType });
    if (state.status) query.set('status', state.status);

    const [adjustments, summary, users] = await Promise.all([
        safeRequest(`/kpi/adjustments?${query.toString()}`),
        safeRequest(`/kpi/adjustments/summary?period_type=${encodeURIComponent(state.periodType)}`),
        safeRequest('/lookups/users'),
    ]);

    state.rows = adjustments?.data || [];
    state.summary = summary?.data || [];
    state.users = users?.data || [];
    root.innerHTML = renderPage();
}

async function safeRequest(path) {
    try {
        return await VKApi.request(path, { cache: false });
    } catch (error) {
        return null;
    }
}

function renderPage() {
    return `
        <div class="kpi-ledger-page">
            <div class="section-tabs">
                <a class="section-tab" href="/kpi">Tổng quan KPI</a>
                <a class="section-tab active" href="/kpi-adjustments">Sổ điểm nhân viên</a>
            </div>

            <section class="module-panel">
                <div class="panel-head">
                    <div>
                        <h2>Bảng điểm điều chỉnh</h2>
                        <span>Cộng/trừ điểm có lý do, trạng thái duyệt và người phê duyệt để tránh sửa KPI trực tiếp.</span>
                    </div>
                    <button class="btn primary" type="button" data-create-kpi-adjustment>Tạo phiếu điểm</button>
                </div>
                <div class="toolbar">
                    <select data-kpi-adjustment-period>
                        ${option('day', 'Theo ngày', state.periodType)}
                        ${option('week', 'Theo tuần', state.periodType)}
                        ${option('month', 'Theo tháng', state.periodType)}
                        ${option('quarter', 'Theo quý', state.periodType)}
                        ${option('year', 'Theo năm', state.periodType)}
                    </select>
                    <select data-kpi-adjustment-status>
                        ${option('', 'Tất cả trạng thái', state.status)}
                        ${option('pending', 'Chờ duyệt', state.status)}
                        ${option('approved', 'Đã duyệt', state.status)}
                        ${option('rejected', 'Từ chối', state.status)}
                    </select>
                </div>
                ${renderSummary()}
                ${renderTable()}
            </section>
        </div>
    `;
}

function renderSummary() {
    if (!state.summary.length) {
        return `
            <div class="kpi-ledger-summary empty">
                <strong>Chưa có điểm đã duyệt trong kỳ này</strong>
                <span>Khi phiếu cộng/trừ điểm được duyệt, tổng điểm từng nhân viên sẽ hiển thị ở đây.</span>
            </div>
        `;
    }

    return `
        <div class="kpi-ledger-summary">
            ${state.summary.slice(0, 6).map((row) => `
                <article class="kpi-ledger-score-card ${Number(row.net_points) < 0 ? 'negative' : 'positive'}">
                    <span>${escape(row.user?.department?.name || 'Chưa gán phòng ban')}</span>
                    <strong>${escape(row.user?.name || 'Nhân viên')} <b>${formatSigned(row.net_points)}</b></strong>
                    <small>Cộng ${formatPoint(row.bonus_points)} · Trừ ${formatPoint(row.penalty_points)} · ${row.adjustment_count} phiếu</small>
                </article>
            `).join('')}
        </div>
    `;
}

function renderTable() {
    if (!state.rows.length) {
        return `
            <div class="empty-state">
                <strong>Chưa có phiếu điểm</strong>
                <span>Tạo phiếu điểm để ghi nhận thưởng/phạt KPI có kiểm soát.</span>
            </div>
        `;
    }

    return `
        <div class="table-wrap">
            <table class="list-table">
                <thead>
                    <tr>
                        <th>Nhân viên</th>
                        <th>Kỳ tính</th>
                        <th>Loại</th>
                        <th>Điểm</th>
                        <th>Lý do</th>
                        <th>Người tạo</th>
                        <th>Trạng thái</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    ${state.rows.map(renderRow).join('')}
                </tbody>
            </table>
        </div>
    `;
}

function renderRow(row) {
    const pending = row.status === 'pending';
    return `
        <tr>
            <td>
                <strong>${escape(row.user?.name || '-')}</strong>
                <small>${escape(row.user?.email || '')}</small>
            </td>
            <td>${periodLabel(row)}</td>
            <td>${typeBadge(row.adjustment_type)}</td>
            <td><strong class="${row.adjustment_type === 'penalty' ? 'text-danger' : 'text-success'}">${row.adjustment_type === 'penalty' ? '-' : '+'}${formatPoint(row.points)}</strong></td>
            <td class="kpi-ledger-reason">${escape(row.reason || '-')}</td>
            <td>${escape(row.creator?.name || '-')}</td>
            <td>${statusBadge(row.status)}</td>
            <td class="row-actions-cell">
                ${pending ? `
                    <button class="btn small primary" type="button" data-review-kpi-adjustment="${row.id}" data-status="approved">Duyệt</button>
                    <button class="btn small danger-soft" type="button" data-review-kpi-adjustment="${row.id}" data-status="rejected">Từ chối</button>
                ` : `<span class="muted">${escape(row.reviewer?.name || '')}</span>`}
            </td>
        </tr>
    `;
}

async function openAdjustmentModal() {
    if (!state.users.length) {
        const users = await safeRequest('/lookups/users');
        state.users = users?.data || [];
    }

    const now = new Date();
    const periodStart = new Date(now.getFullYear(), now.getMonth(), 1).toISOString().slice(0, 10);
    const periodEnd = new Date(now.getFullYear(), now.getMonth() + 1, 0).toISOString().slice(0, 10);
    const userOptions = state.users.map((user) => ({ value: user.id, label: `${user.name} - ${user.email}` }));

    VKModal.open('Tạo phiếu cộng/trừ điểm', `
        <div class="form-grid two">
            ${VKModal.select('user_id', 'Nhân viên', userOptions, '')}
            ${VKModal.select('adjustment_type', 'Loại điểm', [
                { value: 'bonus', label: 'Cộng điểm' },
                { value: 'penalty', label: 'Trừ điểm' },
            ], 'penalty')}
            ${VKModal.field('points', 'Số điểm', 'number', '5')}
            ${VKModal.select('period_type', 'Kỳ tính', [
                { value: 'day', label: 'Ngày' },
                { value: 'week', label: 'Tuần' },
                { value: 'month', label: 'Tháng' },
                { value: 'quarter', label: 'Quý' },
                { value: 'year', label: 'Năm' },
            ], state.periodType)}
            ${VKModal.field('period_start', 'Từ ngày', 'date', periodStart)}
            ${VKModal.field('period_end', 'Đến ngày', 'date', periodEnd)}
        </div>
        <div class="field">
            <label for="reason">Lý do</label>
            <textarea id="reason" name="reason" rows="4" placeholder="Ví dụ: Trễ SLA xử lý báo giá / hoàn thành vượt chỉ tiêu / xử lý cảnh báo quan trọng..."></textarea>
        </div>
        <div class="field">
            <label for="evidence_url">Link bằng chứng</label>
            <input id="evidence_url" name="evidence_url" type="text" placeholder="Không bắt buộc">
        </div>
    `, async (form) => {
        const data = Object.fromEntries(new FormData(form));
        data.user_id = Number(data.user_id);
        data.points = Number(data.points);
        await VKApi.request('/kpi/adjustments', {
            method: 'POST',
            body: JSON.stringify(data),
        });
        VKModal.close();
        VKModal.toast('Đã tạo phiếu điểm, đang chờ duyệt.');
        window.setTimeout(() => window.location.reload(), 500);
    }, { className: 'wide-modal', submitText: 'Lưu phiếu' });
}

async function reviewAdjustment(id, status) {
    const label = status === 'approved' ? 'duyệt' : 'từ chối';
    if (!confirm(`Bạn chắc chắn muốn ${label} phiếu điểm này?`)) return;

    await VKApi.request(`/kpi/adjustments/${id}/review`, {
        method: 'POST',
        body: JSON.stringify({
            status,
            review_note: status === 'approved' ? 'Duyệt điều chỉnh KPI.' : 'Từ chối điều chỉnh KPI.',
        }),
    });
    VKModal.toast(status === 'approved' ? 'Đã duyệt phiếu điểm.' : 'Đã từ chối phiếu điểm.');
    window.setTimeout(() => window.location.reload(), 500);
}

function option(value, label, selected) {
    return `<option value="${escape(value)}" ${String(value) === String(selected) ? 'selected' : ''}>${escape(label)}</option>`;
}

function periodLabel(row) {
    return `${escape(periodTypeLabel(row.period_type))}<small>${formatDate(row.period_start)} - ${formatDate(row.period_end)}</small>`;
}

function periodTypeLabel(value) {
    return {
        day: 'Ngày',
        week: 'Tuần',
        month: 'Tháng',
        quarter: 'Quý',
        year: 'Năm',
    }[value] || value || '-';
}

function typeBadge(value) {
    return value === 'bonus'
        ? '<span class="badge success">Cộng điểm</span>'
        : '<span class="badge danger">Trừ điểm</span>';
}

function statusBadge(value) {
    const map = {
        pending: ['warning', 'Chờ duyệt'],
        approved: ['success', 'Đã duyệt'],
        rejected: ['danger', 'Từ chối'],
    };
    const [tone, label] = map[value] || ['info', value || '-'];
    return `<span class="badge ${tone}">${escape(label)}</span>`;
}

function formatDate(value) {
    if (!value) return '-';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return value;
    return date.toLocaleDateString('vi-VN');
}

function formatPoint(value) {
    return Number(value || 0).toLocaleString('vi-VN', { maximumFractionDigits: 2 });
}

function formatSigned(value) {
    const number = Number(value || 0);
    return `${number > 0 ? '+' : ''}${formatPoint(number)} điểm`;
}

function renderLoading() {
    return `
        <div class="kpi-ledger-page">
            <div class="section-tabs">
                <span class="section-tab active">Sổ điểm nhân viên</span>
            </div>
            <section class="module-panel">
                <div class="panel-head">
                    <div>
                        <h2>Đang tải sổ điểm</h2>
                        <span>Hệ thống đang lấy dữ liệu cộng/trừ điểm KPI.</span>
                    </div>
                </div>
                <div class="kpi-ledger-summary">
                    <article class="kpi-ledger-score-card"><span class="skeleton"></span><strong class="skeleton large"></strong><small class="skeleton"></small></article>
                    <article class="kpi-ledger-score-card"><span class="skeleton"></span><strong class="skeleton large"></strong><small class="skeleton"></small></article>
                    <article class="kpi-ledger-score-card"><span class="skeleton"></span><strong class="skeleton large"></strong><small class="skeleton"></small></article>
                </div>
            </section>
        </div>
    `;
}

function escape(value) {
    return VKTable.escapeHtml(value ?? '');
}
})();
