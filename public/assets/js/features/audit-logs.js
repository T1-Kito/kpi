(function () {
let auditState = {
    rows: [],
    users: [],
    q: '',
    userId: '',
    entityType: '',
    action: '',
    from: '',
    to: '',
    total: 0,
};

document.addEventListener('vk:ready', () => loadAuditLogs());

document.addEventListener('input', (event) => {
    if (!document.getElementById('auditLogsRoot')) return;
    if (event.target.matches('[data-audit-search]')) {
        auditState.q = event.target.value.toLowerCase();
        renderAuditLogs();
    }
});

document.addEventListener('change', (event) => {
    if (!document.getElementById('auditLogsRoot')) return;
    if (event.target.matches('[data-audit-user]')) auditState.userId = event.target.value;
    if (event.target.matches('[data-audit-entity]')) auditState.entityType = event.target.value;
    if (event.target.matches('[data-audit-action]')) auditState.action = event.target.value;
    if (event.target.matches('[data-audit-from]')) auditState.from = event.target.value;
    if (event.target.matches('[data-audit-to]')) auditState.to = event.target.value;
    loadAuditLogs();
});

document.addEventListener('click', (event) => {
    if (!document.getElementById('auditLogsRoot')) return;

    if (event.target.matches('[data-audit-reset]')) {
        auditState = { ...auditState, q: '', userId: '', entityType: '', action: '', from: '', to: '' };
        loadAuditLogs();
        return;
    }

    const detail = event.target.closest('[data-audit-detail], tr[data-row-detail]');
    if (detail) openAuditDetail(detail.dataset.auditDetail || detail.dataset.rowDetail);
});

async function loadAuditLogs(options = {}) {
    const root = document.getElementById('auditLogsRoot');
    if (!root) return;
    if (options.source !== 'pjax') {
        root.innerHTML = renderLoading();
    }

    const params = new URLSearchParams({ page_size: '100' });
    if (auditState.userId) params.set('user_id', auditState.userId);
    if (auditState.entityType) params.set('entity_type', auditState.entityType);
    if (auditState.action) params.set('action', auditState.action);
    if (auditState.from) params.set('from', auditState.from);
    if (auditState.to) params.set('to', auditState.to);

    const [logs, users] = await Promise.all([
        VKApi.request(`/audit-logs?${params.toString()}`),
        VKApi.request('/lookups/users'),
    ]);

    auditState.rows = logs.data || [];
    auditState.total = logs.meta?.total || auditState.rows.length;
    auditState.users = users.data || [];
    renderAuditLogs();
}

function renderAuditLogs() {
    const rows = filteredRows();
    const root = document.getElementById('auditLogsRoot');
    if (!root) return;

    root.innerHTML = VKTable.fullList({
        title: 'Danh sách nhật ký',
        subtitle: 'Tra cứu thao tác vận hành, dữ liệu trước/sau và lý do xử lý.',
        meta: `${VKTable.money(rows.length)} / ${VKTable.money(auditState.total)} dòng`,
        filters: renderAuditFilters(),
        table: renderAuditTable(rows),
    });

    restoreAuditFilters();
}

function renderAuditFilters() {
    return `
        <div class="list-filter audit-filter">
            <label class="list-search">
                <span aria-hidden="true">⌕</span>
                <input type="search" data-audit-search placeholder="Tìm người thao tác, hành động, đối tượng..." value="${VKTable.escapeHtml(auditState.q)}">
            </label>
            <select data-audit-user>
                ${option('', 'Tất cả người thao tác', auditState.userId)}
                ${auditState.users.map(user => option(String(user.id), `${user.name} - ${user.email}`, auditState.userId)).join('')}
            </select>
            <select data-audit-entity>
                ${option('', 'Tất cả đối tượng', auditState.entityType)}
                ${entityOptions().map(value => option(value, VKTable.translateEntity(value), auditState.entityType)).join('')}
            </select>
            <select data-audit-action>
                ${option('', 'Tất cả hành động', auditState.action)}
                ${actionOptions().map(value => option(value, VKTable.translateAction(value), auditState.action)).join('')}
            </select>
            <input type="date" data-audit-from value="${VKTable.escapeHtml(auditState.from)}" aria-label="Từ ngày">
            <input type="date" data-audit-to value="${VKTable.escapeHtml(auditState.to)}" aria-label="Đến ngày">
            <div class="list-filter-actions">
                <button class="btn small" type="button" data-audit-reset>Xóa lọc</button>
            </div>
        </div>
    `;
}

function renderAuditTable(rows) {
    return VKTable.renderTable([
        { label: 'Thời gian', render: row => `<span>${formatDateTime(row.created_at)}</span>` },
        { label: 'Người thao tác', render: row => renderActor(row) },
        { label: 'Đối tượng', render: row => renderEntity(row) },
        { label: 'Hành động', render: row => `<span class="audit-action">${VKTable.escapeHtml(VKTable.translateAction(row.action))}</span>` },
        { label: 'Lý do', render: row => `<span class="row-note">${VKTable.escapeHtml(row.reason || '-')}</span>` },
        { label: '', render: row => VKTable.rowActions([VKTable.smallButton('Mở', `data-audit-detail="${row.id}"`)]) },
    ], rows, 'Chưa có nhật ký hệ thống', { rowAttr: row => `data-row-detail="${row.id}"` });
}

function renderActor(row) {
    return `
        <span class="audit-actor">
            <strong>${VKTable.escapeHtml(row.user?.name || 'Hệ thống')}</strong>
            <small>${VKTable.escapeHtml(row.user?.email || '-')}</small>
        </span>
    `;
}

function renderEntity(row) {
    return `
        <span>
            ${VKTable.escapeHtml(VKTable.translateEntity(row.entity_type))}
            <span class="row-note">#${VKTable.escapeHtml(row.entity_id || '-')}</span>
        </span>
    `;
}

function filteredRows() {
    return auditState.rows.filter(row => {
        const haystack = [
            row.user?.name,
            row.user?.email,
            row.entity_type,
            row.entity_id,
            row.action,
            VKTable.translateEntity(row.entity_type),
            VKTable.translateAction(row.action),
            row.reason,
        ].join(' ').toLowerCase();

        return !auditState.q || haystack.includes(auditState.q);
    });
}

async function openAuditDetail(id) {
    const drawer = getAuditDrawer();
    if (!drawer.backdrop) return;

    drawer.backdrop.classList.add('open');
    drawer.kicker.textContent = 'Nhật ký hệ thống';
    drawer.title.textContent = 'Đang tải...';
    drawer.body.innerHTML = renderLoading();

    try {
        const response = await VKApi.request(`/audit-logs/${id}`);
        renderAuditDetail(response.data);
    } catch (error) {
        drawer.title.textContent = 'Không tải được nhật ký';
        drawer.body.innerHTML = `<div class="empty"><strong>Có lỗi xảy ra</strong><span>${VKTable.escapeHtml(error.message)}</span></div>`;
    }
}

function renderAuditDetail(row) {
    const drawer = getAuditDrawer();
    drawer.kicker.textContent = 'Nhật ký hệ thống';
    drawer.title.textContent = VKTable.translateAction(row.action);
    drawer.body.innerHTML = `
        <div class="detail-drawer-body audit-detail">
            <section class="detail-section">
                <h3>Thông tin thao tác</h3>
                <div class="detail-grid">
                    ${detailField('Người thao tác', row.user?.name || 'Hệ thống')}
                    ${detailField('Email', row.user?.email || '-')}
                    ${detailField('Thời gian', formatDateTime(row.created_at))}
                    ${detailField('Đối tượng', `${VKTable.translateEntity(row.entity_type)} #${row.entity_id || '-'}`)}
                    ${detailField('IP', row.ip_address || '-')}
                    ${detailField('Lý do', row.reason || '-')}
                </div>
            </section>
            <section class="detail-section">
                <h3>Dữ liệu thay đổi</h3>
                <div class="audit-change-grid">
                    ${auditJsonBox('Trước thay đổi', row.old_value)}
                    ${auditJsonBox('Sau thay đổi', row.new_value)}
                </div>
            </section>
            <section class="detail-section">
                <h3>Trình duyệt</h3>
                <div class="detail-list-item"><span>${VKTable.escapeHtml(row.user_agent || '-')}</span></div>
            </section>
        </div>
    `;
}

function detailField(label, value) {
    return `
        <div class="detail-field">
            <span>${VKTable.escapeHtml(label)}</span>
            <strong>${VKTable.escapeHtml(value)}</strong>
        </div>
    `;
}

function auditJsonBox(title, value) {
    const rows = objectRows(value);
    return `
        <div class="audit-json-box">
            <h4>${VKTable.escapeHtml(title)}</h4>
            ${rows.length ? rows.map(([key, item]) => `
                <div>
                    <span>${VKTable.escapeHtml(key)}</span>
                    <strong>${VKTable.escapeHtml(formatValue(item))}</strong>
                </div>
            `).join('') : '<p>Không có dữ liệu.</p>'}
        </div>
    `;
}

function objectRows(value) {
    if (!value || typeof value !== 'object') return [];
    return Object.entries(value).filter(([, item]) => item !== null && item !== undefined && item !== '');
}

function formatValue(value) {
    if (typeof value === 'object') return JSON.stringify(value);
    return String(value);
}

function entityOptions() {
    return unique(auditState.rows.map(row => row.entity_type).filter(Boolean));
}

function actionOptions() {
    return unique(auditState.rows.map(row => row.action).filter(Boolean));
}

function unique(values) {
    return [...new Set(values)].sort((a, b) => String(a).localeCompare(String(b)));
}

function restoreAuditFilters() {
    const search = document.querySelector('[data-audit-search]');
    if (search) search.value = auditState.q;
}

function option(value, label, current) {
    return `<option value="${VKTable.escapeHtml(value)}" ${String(value) === String(current) ? 'selected' : ''}>${VKTable.escapeHtml(label)}</option>`;
}

function formatDateTime(value) {
    if (!value) return '-';
    return new Date(value).toLocaleString('vi-VN');
}

function getAuditDrawer() {
    return {
        backdrop: document.querySelector('[data-detail-drawer-backdrop]'),
        kicker: document.querySelector('[data-detail-kicker]'),
        title: document.querySelector('[data-detail-title]'),
        body: document.querySelector('[data-detail-body]'),
    };
}

function renderLoading() {
    return `<section class="module-panel"><div class="skeleton"></div><div class="skeleton large"></div><div class="skeleton"></div></section>`;
}

window.loadAuditLogs = loadAuditLogs;
})();
