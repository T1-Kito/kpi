(function () {
let alertState = { rows: [], q: '', status: '' };

document.addEventListener('vk:ready', () => loadAlerts());
document.addEventListener('input', (event) => {
    if (!document.getElementById('alertsRoot')) return;
    if (event.target.matches('[data-list-search]')) {
        alertState.q = event.target.value.toLowerCase();
        renderAlerts();
    }
});
document.addEventListener('change', (event) => {
    if (!document.getElementById('alertsRoot')) return;
    if (event.target.matches('[data-list-status]')) {
        alertState.status = event.target.value;
        renderAlerts();
    }
});
document.addEventListener('click', (event) => {
    if (!document.getElementById('alertsRoot')) return;
    if (event.target.matches('[data-alert-status]')) {
        event.stopPropagation();
        updateAlertStatus(event.target.dataset.alertStatus, event.target.dataset.status);
    }
    const detail = event.target.closest('[data-alert-detail], tr[data-row-detail]');
    if (detail) openAlertDetail(detail.dataset.alertDetail || detail.dataset.rowDetail);
});

async function loadAlerts() {
    if (!document.getElementById('alertsRoot')) return;
    const alerts = await VKApi.request('/alerts');
    alertState.rows = alerts.data || [];
    renderAlerts(alerts.meta.total);
}

function renderAlerts(total = alertState.rows.length) {
    const rows = filterRows();
    document.getElementById('alertsRoot').innerHTML = VKTable.fullList({
        title: 'Danh sách cảnh báo',
        subtitle: 'Ghi nhận, xử lý và đóng vòng cảnh báo theo nguồn phát sinh.',
        meta: `${VKTable.money(rows.length)} / ${VKTable.money(total)} cảnh báo`,
        filters: VKTable.filterBar({
            searchPlaceholder: 'Tìm tiêu đề, nội dung hoặc nguồn...',
            status: [
                { value: 'open', label: 'Đang mở' },
                { value: 'acknowledged', label: 'Đã ghi nhận' },
                { value: 'resolved', label: 'Đã xử lý' },
            ],
        }),
        table: renderAlertTable(rows),
    });
    restoreFilters();
}

function renderAlertTable(rows) {
    return VKTable.renderTable([
        { label: 'Loại', render: row => renderType(row) },
        { label: 'Cảnh báo', render: row => renderAlertInfo(row) },
        { label: 'Người nhận', render: row => VKTable.escapeHtml(row.recipient?.name || 'Chưa gán') },
        { label: 'Mức độ', render: row => VKTable.statusBadge(row.level) },
        { label: 'Trạng thái', render: row => VKTable.statusBadge(row.status) },
        { label: '', render: row => renderAlertActions(row) },
    ], rows, 'Chưa có cảnh báo', { rowAttr: row => `data-row-detail="${row.id}"` });
}

function filterRows() {
    return alertState.rows.filter(row => {
        const haystack = `${row.title || ''} ${row.message || ''} ${row.alert_type || ''} ${row.source_type || ''}`.toLowerCase();
        return (!alertState.q || haystack.includes(alertState.q)) && (!alertState.status || row.status === alertState.status);
    });
}

function restoreFilters() {
    const search = document.querySelector('[data-list-search]');
    const status = document.querySelector('[data-list-status]');
    if (search) search.value = alertState.q;
    if (status) status.value = alertState.status;
}

function renderType(row) {
    return `<span class="badge info">${VKTable.translateType(row.alert_type)}</span><span class="row-note">${VKTable.escapeHtml(VKTable.translateEntity(row.source_type || 'System'))} #${VKTable.escapeHtml(row.source_id || '-')}</span>`;
}

function renderAlertInfo(row) {
    return `<strong>${VKTable.escapeHtml(row.title)}</strong><span class="row-note">${VKTable.escapeHtml(row.message || 'Chưa có nội dung chi tiết')}</span>`;
}

function renderAlertActions(row) {
    const actions = [VKTable.smallButton('Mở', `data-alert-detail="${row.id}"`)];
    if (row.status === 'open') actions.push(VKTable.smallButton('Ghi nhận', `data-alert-status="${row.id}" data-status="acknowledged"`));
    if (row.status === 'open' || row.status === 'acknowledged') actions.push(VKTable.smallButton('Đã xử lý', `data-alert-status="${row.id}" data-status="resolved"`, 'primary'));
    return VKTable.rowActions(actions);
}

async function updateAlertStatus(id, status) {
    await VKApi.request(`/alerts/${id}/status`, { method: 'POST', body: JSON.stringify({ status }) });
    VKModal.toast(status === 'resolved' ? 'Đã xử lý cảnh báo.' : 'Đã ghi nhận cảnh báo.');
    loadAlerts();
}

function openAlertDetail(id) {
    const row = alertState.rows.find(item => String(item.id) === String(id));
    if (!row) return;
    VKRecordPage.open({
        root: '#alertsRoot',
        type: 'alert',
        id,
        preview: { ...row, timeline: [{ label: 'Tạo cảnh báo', status: row.status, at: row.created_at }] },
        onBack: () => renderAlerts(),
    });
    return;
    VKDetailDrawer.open({ type: 'alert', row: { ...row, timeline: [{ label: 'Tạo cảnh báo', status: row.status, at: row.created_at }] } });
}

window.loadAlerts = loadAlerts;
})();
