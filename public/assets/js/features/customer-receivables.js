(function () {
let receivableState = { rows: [], q: '', status: '', total: 0 };

document.addEventListener('vk:ready', () => loadCustomerReceivables());
document.addEventListener('vk:flow-updated', () => loadCustomerReceivables());

document.addEventListener('input', (event) => {
    if (!document.getElementById('customerReceivablesRoot')) return;
    if (!event.target.matches('[data-list-search]')) return;
    receivableState.q = event.target.value.toLowerCase();
    renderCustomerReceivables();
});

document.addEventListener('change', (event) => {
    if (!document.getElementById('customerReceivablesRoot')) return;
    if (!event.target.matches('[data-list-status]')) return;
    receivableState.status = event.target.value;
    renderCustomerReceivables();
});

async function loadCustomerReceivables() {
    const root = document.getElementById('customerReceivablesRoot');
    if (!root) return;

    root.innerHTML = VKTable.skeletonRows ? VKTable.skeletonRows() : '<p>Đang tải công nợ...</p>';
    const response = await VKApi.request('/customer-receivables?page_size=100');
    receivableState.rows = response.data || [];
    receivableState.total = response.meta?.total || receivableState.rows.length;
    renderCustomerReceivables();
}

function renderCustomerReceivables() {
    const root = document.getElementById('customerReceivablesRoot');
    if (!root) return;

    const rows = filteredRows();
    const openDebt = receivableState.rows.reduce((sum, row) => sum + Number(row.balance_amount || 0), 0);
    const overdueDebt = receivableState.rows.reduce((sum, row) => sum + Number(row.overdue_amount || 0), 0);
    const overLimit = receivableState.rows.filter(row => row.status === 'over_limit').length;

    root.innerHTML = `
        <div class="summary-grid compact">
            ${metricCard('Còn phải thu', money(openDebt), 'Tổng dư nợ khách hàng', 'blue')}
            ${metricCard('Nợ quá hạn', money(overdueDebt), 'Hóa đơn đã quá hạn thanh toán', overdueDebt > 0 ? 'orange' : 'green')}
            ${metricCard('Vượt hạn mức', overLimit, 'Khách vượt hạn mức công nợ', overLimit > 0 ? 'red' : 'green')}
        </div>
        ${VKTable.fullList({
            title: 'Danh sách công nợ',
            subtitle: 'Theo dõi dư nợ, hạn mức và hóa đơn quá hạn theo từng khách hàng.',
            meta: `${rows.length} / ${receivableState.total} khách hàng`,
            filters: VKTable.filterBar({
                searchPlaceholder: 'Tìm khách hàng, mã số thuế, số điện thoại...',
                status: [
                    { value: 'unpaid', label: 'Còn nợ' },
                    { value: 'overdue', label: 'Quá hạn' },
                    { value: 'over_limit', label: 'Vượt hạn mức' },
                    { value: 'paid', label: 'Không còn nợ' },
                ],
            }),
            table: renderReceivableTable(rows),
        })}
    `;
    restoreFilters();
}

function renderReceivableTable(rows) {
    return VKTable.renderTable([
        { label: 'Mã khách hàng', render: row => `<span class="mono" style="color:#e60073;font-weight:600">${escape(row.code || '—')}</span>` },
        { label: 'Tên khách hàng', render: row => `<span style="color:#245a78;font-weight:500">${escape(row.name || '—')}</span>` },
        { label: 'Mã số thuế', render: row => escape(row.tax_code || '—') },
        { label: 'Số CCCD', render: row => escape(row.identity_number || '—') },
        { label: 'Người liên hệ', render: row => escape(row.contact_name || '—') },
        { label: 'Số điện thoại', render: row => escape(row.phone || '—') },
        { label: 'Email', render: row => escape(row.email || '—') },
        { label: 'Hạn mức', render: row => money(row.credit_limit) },
        { label: 'Tổng hóa đơn', render: row => money(row.total_amount) },
        { label: 'Đã thu', render: row => money(row.paid_amount) },
        { label: 'Còn nợ', render: row => `<strong class="${Number(row.balance_amount || 0) > 0 ? 'text-warning' : ''}">${money(row.balance_amount)}</strong>` },
        { label: 'Quá hạn', render: row => overdueCell(row) },
        { label: 'Số hóa đơn quá hạn', render: row => money(row.overdue_count) },
        { label: 'Trạng thái', render: row => receivableBadge(row.status) },
    ], rows, 'Chưa có dữ liệu công nợ');
}

function filteredRows() {
    return receivableState.rows.filter((row) => {
        const text = `${row.code || ''} ${row.name || ''} ${row.contact_name || ''} ${row.phone || ''} ${row.email || ''} ${row.tax_code || ''}`.toLowerCase();
        return (!receivableState.q || text.includes(receivableState.q))
            && (!receivableState.status || row.status === receivableState.status);
    });
}

function restoreFilters() {
    const search = document.querySelector('[data-list-search]');
    const status = document.querySelector('[data-list-status]');
    if (search) search.value = receivableState.q;
    if (status) status.value = receivableState.status;
}

function overdueCell(row) {
    if (Number(row.overdue_amount || 0) <= 0) return '-';
    return `<strong class="text-danger">${money(row.overdue_amount)}</strong>`;
}

function receivableBadge(status) {
    const map = {
        paid: ['success', 'Không còn nợ'],
        unpaid: ['warning', 'Còn nợ'],
        overdue: ['danger', 'Quá hạn'],
        over_limit: ['danger', 'Vượt hạn mức'],
    };
    const item = map[status] || ['neutral', status || '-'];
    return `<span class="badge ${item[0]}">${item[1]}</span>`;
}

function metricCard(label, value, hint, tone) {
    const tones = { blue: 'info', orange: 'warning', red: 'danger', green: 'success' };
    return `<div class="metric-card ${tones[tone] || 'info'}">
        <span>${escape(label)}</span>
        <strong>${escape(String(value))}</strong>
        <small>${escape(hint)}</small>
    </div>`;
}

function money(value) {
    return VKTable.money(value || 0);
}

function escape(value) {
    return VKTable.escapeHtml(value == null ? '' : String(value));
}

window.loadCustomerReceivables = loadCustomerReceivables;
})();
