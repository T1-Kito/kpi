(function () {
let giState = { rows: [], q: '', status: '', view: 'list', detailCache: {} };

document.addEventListener('vk:ready', () => loadGoodsIssues());
document.addEventListener('vk:flow-updated', () => loadGoodsIssues());
document.addEventListener('input', (event) => {
    if (!document.getElementById('goodsIssuesRoot')) return;
    if (event.target.matches('[data-list-search]')) {
        giState.q = event.target.value.toLowerCase();
        renderGoodsIssues();
    }
});
document.addEventListener('change', (event) => {
    if (!document.getElementById('goodsIssuesRoot')) return;
    if (event.target.matches('[data-list-status]')) {
        giState.status = event.target.value;
        renderGoodsIssues();
    }
});
document.addEventListener('click', (event) => {
    if (!document.getElementById('goodsIssuesRoot')) return;
    if (event.target.matches('[data-goods-issue-word]')) {
        event.stopPropagation();
        downloadGoodsIssueWord(event.target.dataset.goodsIssueWord);
    }
    if (event.target.matches('[data-confirm-goods-issue]')) {
        event.stopPropagation();
        confirmGoodsIssue(event.target.dataset.confirmGoodsIssue);
    }
    if (event.target.closest('.row-action-menu') && !event.target.closest('[data-goods-issue-detail]')) return;
    const detail = event.target.closest('[data-goods-issue-detail], tr[data-row-detail]');
    if (detail) openGoodsIssueDetail(detail.dataset.goodsIssueDetail || detail.dataset.rowDetail);
});

async function loadGoodsIssues(options = {}) {
    if (!document.getElementById('goodsIssuesRoot')) return;
    if (options.source === 'pjax') {
        giState.view = 'list';
        giState.currentId = null;
    }
    const issues = await VKApi.request('/goods-issues');
    giState.rows = issues.data || [];
    if (giState.view === 'detail' && giState.currentId) {
        openGoodsIssueDetail(giState.currentId);
        return;
    }
    renderGoodsIssues(issues.meta.total);
}

function renderGoodsIssues(total = giState.rows.length) {
    const rows = filterRows();
    document.getElementById('goodsIssuesRoot').innerHTML = VKTable.fullList({
        title: 'Danh sách phiếu xuất',
        subtitle: 'Phiếu tự sinh từ đơn bán đã giữ hàng. Kho kiểm tra chứng từ và hàng thực xuất trước khi xác nhận.',
        meta: `${VKTable.money(rows.length)} / ${VKTable.money(total)} phiếu`,
        filters: VKTable.filterBar({
            searchPlaceholder: 'Tìm mã phiếu, đơn bán hoặc kho...',
            status: [
                { value: 'draft', label: 'Chờ xuất kho' },
                { value: 'confirmed', label: 'Đã xác nhận' },
            ],
        }),
        table: renderIssueTable(rows),
    });
    restoreFilters();
}

function renderIssueTable(rows) {
    return VKTable.renderTable([
        { label: 'Mã phiếu', render: row => `<span class="mono">${VKTable.escapeHtml(row.code)}</span>` },
        { label: 'Đơn bán', render: row => `<span class="mono">${VKTable.escapeHtml(row.sales_order?.code || '-')}</span>` },
        { label: 'Đơn tạo lúc', render: row => formatDateTime(row.sales_order?.created_at) },
        { label: 'Kho xuất', render: row => VKTable.escapeHtml(row.warehouse?.name || '-') },
        { label: 'Hàng xuất', render: row => `${row.affects_stock === false ? '<span class="badge info">Không trừ tồn</span><br>' : ''}${renderItems(row.items)}` },
        { label: 'Trạng thái', render: row => row.status === 'draft' ? '<span class="badge info">Chờ xuất kho</span>' : VKTable.statusBadge(row.status) },
        { label: '', render: row => VKTable.rowActions([
            VKTable.smallButton('Mở', `data-goods-issue-detail="${row.id}"`),
            row.status === 'draft' ? VKTable.smallButton('Xác nhận', `data-confirm-goods-issue="${row.id}"`, 'primary') : '',
        ]) },
    ], rows, 'Chưa có phiếu xuất', { rowAttr: row => `data-row-detail="${row.id}"` });
}

function filterRows() {
    return giState.rows.filter(row => {
        const haystack = `${row.code || ''} ${row.sales_order?.code || ''} ${row.sales_order?.customer?.name || ''} ${row.warehouse?.name || ''}`.toLowerCase();
        return (!giState.q || haystack.includes(giState.q)) && (!giState.status || row.status === giState.status);
    });
}

function restoreFilters() {
    const search = document.querySelector('[data-list-search]');
    const status = document.querySelector('[data-list-status]');
    if (search) search.value = giState.q;
    if (status) status.value = giState.status;
}

function renderItems(items = []) {
    if (!items.length) return '-';
    return items.slice(0, 2).map(item => `${VKTable.escapeHtml(item.sku?.sku_code || '-')}: ${VKTable.money(item.quantity)}`).join('<br>');
}

function formatDateTime(value) {
    if (!value) return '-';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return '-';
    return date.toLocaleString('vi-VN', { hour: '2-digit', minute: '2-digit', day: '2-digit', month: '2-digit', year: 'numeric' });
}

async function confirmGoodsIssue(id) {
    try {
        const response = await VKApi.request(`/goods-issues/${id}/confirm`, { method: 'POST' });
        const issue = response.data || {};
        giState.detailCache[String(id)] = null;
        giState.rows = giState.rows.map(row => String(row.id) === String(id) ? { ...row, ...issue } : row);

        VKModal.notice({
            type: 'success',
            title: 'Xuất kho thành công',
            message: 'Đã xác nhận xuất kho và chuyển đơn bán sang bước chờ giao hàng.',
            details: [`Phiếu xuất: ${issue.code || ''}`, 'Bước tiếp theo: xác nhận khách đã nhận hàng tại chi tiết đơn bán.'],
        });

        if (giState.view === 'detail' && String(giState.currentId) === String(id)) {
            openGoodsIssueDetail(id);
            return;
        }
        loadGoodsIssues();
    } catch (error) {
        VKModal.notice({
            type: 'danger',
            title: 'Không thể xác nhận xuất kho',
            message: error.message || 'Có lỗi xảy ra khi xác nhận phiếu xuất.',
        });
    }
}

async function downloadGoodsIssueWord(id) {
    const row = giState.detailCache[String(id)] || giState.rows.find(item => String(item.id) === String(id)) || {};
    const response = await fetch(`/api/v1/goods-issues/${encodeURIComponent(id)}/word`, {
        headers: { Accept: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', Authorization: `Bearer ${VKApi.token()}` },
    });
    if (!response.ok) {
        const body = await response.json().catch(() => ({}));
        VKModal.notice({ type: 'warning', title: 'Chưa thể xuất file', message: body.message || 'Không thể tải file Phiếu xuất kho.' });
        return;
    }
    const url = URL.createObjectURL(await response.blob());
    const link = document.createElement('a');
    link.href = url; link.download = `${row.code || 'phieu-xuat-kho'}.docx`; document.body.appendChild(link); link.click(); link.remove();
    window.setTimeout(() => URL.revokeObjectURL(url), 1000);
    VKModal.toast('Đã tải file Phiếu xuất kho.', 'success');
}

function openGoodsIssueDetail(id) {
    giState.view = 'detail';
    giState.currentId = id;
    VKRecordPage.open({
        root: '#goodsIssuesRoot',
        type: 'goodsIssue',
        id,
        path: `/goods-issues/${id}`,
        preview: giState.rows.find(row => String(row.id) === String(id)),
        cache: giState.detailCache,
        onBack: () => {
            giState.view = 'list';
            giState.currentId = null;
            renderGoodsIssues();
        },
        actions: row => `<button class="btn small" type="button" data-goods-issue-word="${row.id}">Phiếu xuất kho</button>${row.status === 'draft' ? `<button class="btn primary small" type="button" data-confirm-goods-issue="${row.id}">Xác nhận xuất kho</button>` : ''}`,
    });
}

window.loadGoodsIssues = loadGoodsIssues;
})();
