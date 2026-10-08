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
    if (event.target.matches('[data-create-goods-issue]')) openGoodsIssueModal();
    if (event.target.matches('[data-goods-issue-word]')) {
        event.stopPropagation();
        downloadGoodsIssueWord(event.target.dataset.goodsIssueWord);
    }
    if (event.target.matches('[data-confirm-goods-issue]')) {
        event.stopPropagation();
        confirmGoodsIssue(event.target.dataset.confirmGoodsIssue);
    }
    if (event.target.closest('.row-action-menu')) return;
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
        subtitle: 'Xuất kho theo đơn bán đã giữ hàng và hoàn tất giao dịch kho.',
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

async function openGoodsIssueModal() {
    const [orders, warehouses] = await Promise.all([
        VKApi.request('/sales-orders'),
        VKApi.request('/warehouses'),
    ]);
    const activeIssueOrderIds = new Set(giState.rows
        .filter(issue => ['draft', 'confirmed'].includes(issue.status))
        .map(issue => String(issue.sales_order_id)));
    const reservedOrders = orders.data
        .filter(row => ['reserved', 'not_required'].includes(row.stock_status) && !activeIssueOrderIds.has(String(row.id)))
        .sort((a, b) => new Date(b.created_at || 0) - new Date(a.created_at || 0));
    if (!reservedOrders.length) return VKModal.toast('Chưa có đơn bán cần lập phiếu xuất.');
    if (!warehouses.data.length) return VKModal.toast('Chưa có kho xuất hàng.');

    const newestOrderId = reservedOrders[0]?.id;
    VKModal.open('Tạo phiếu xuất kho', `
        <div class="form-grid goods-issue-create-grid">
            ${VKModal.select('sales_order_id', 'Đơn bán cần lập phiếu xuất', reservedOrders.map(row => ({ value: row.id, label: goodsIssueOrderLabel(row, row.id === newestOrderId) })))}
            ${VKModal.select('warehouse_id', 'Kho xuất', warehouses.data.map(row => ({ value: row.id, label: `${row.code} - ${row.name}` })))}
        </div>
        <div class="goods-issue-document" data-goods-issue-document></div>
    `, async (form) => {
        const data = Object.fromEntries(new FormData(form));
        await VKApi.request('/goods-issues', {
            method: 'POST',
            body: JSON.stringify({
                sales_order_id: Number(data.sales_order_id),
                warehouse_id: Number(data.warehouse_id),
                recipient_name: data.recipient_name,
                recipient_phone: data.recipient_phone,
                recipient_address: data.recipient_address,
                delivery_location: data.delivery_location,
                issue_reason: data.issue_reason,
                source_document: data.source_document,
            }),
        });
        VKModal.toast('Đã tạo phiếu xuất, kho có thể kiểm tra và xác nhận.');
        VKModal.close();
        loadGoodsIssues();
    }, { className: 'goods-issue-create-modal', submitText: 'Lưu phiếu xuất kho' });

    const orderSelect = document.querySelector('#modalBody [name="sales_order_id"]');
    const documentRoot = document.querySelector('#modalBody [data-goods-issue-document]');
    const showDocument = () => {
        const order = reservedOrders.find(row => String(row.id) === String(orderSelect.value));
        if (order && documentRoot) documentRoot.innerHTML = renderGoodsIssueDocument(order);
    };
    orderSelect?.addEventListener('change', showDocument);
    showDocument();
}

function renderGoodsIssueDocument(order) {
    const customer = order.customer || {};
    const recipient = customer.contact_name || customer.name || '';
    const address = customer.address || customer.billing_address || '';
    const source = order.quotation?.code || order.code;
    const isDirectDelivery = order.fulfillment_type === 'supplier_direct';
    return `
        <div class="goods-issue-document-heading">
            <div><strong>Thông tin giao / xuất kho</strong><span>${isDirectDelivery ? 'Nhà cung cấp giao thẳng khách. Kho kiểm tra chứng từ, phiếu này không trừ tồn.' : 'Kho kiểm tra thông tin này trước khi xác nhận xuất hàng.'}</span></div>
            <span class="goods-issue-order-code">Đơn: ${VKTable.escapeHtml(order.code || '')}</span>
        </div>
        <div class="form-grid goods-issue-document-grid">
            ${VKModal.field('recipient_name', 'Họ và tên người nhận', 'text', recipient)}
            ${VKModal.field('recipient_phone', 'Số điện thoại', 'text', customer.phone || '')}
            ${VKModal.field('recipient_address', 'Địa chỉ', 'text', address)}
            ${VKModal.field('delivery_location', 'Địa điểm giao hàng', 'text', address)}
            ${VKModal.field('issue_reason', 'Lý do xuất', 'text', isDirectDelivery ? `NCC giao thẳng khách cho đơn ${order.code || ''} (không trừ tồn)` : `Xuất kho bán hàng cho ${customer.name || 'khách hàng'} (${order.code || ''})`)}
            ${VKModal.field('source_document', 'Số chứng từ gốc kèm theo', 'text', source)}
        </div>
        <div class="goods-issue-items-wrap">
            <div class="goods-issue-items-title">Hàng cần xuất</div>
            <table class="goods-issue-items-table">
                <thead><tr><th>Sản phẩm</th><th>ĐVT</th><th>SL đặt</th><th>Đã xuất</th><th>Còn lại</th><th>SL xuất lần này</th></tr></thead>
                <tbody>${(order.items || []).map(item => `
                    <tr>
                        <td><strong>${VKTable.escapeHtml(item.sku?.name || '-')}</strong><small>${VKTable.escapeHtml(item.sku?.sku_code || '')}</small></td>
                        <td>${VKTable.escapeHtml(item.sku?.unit || '-')}</td>
                        <td>${VKTable.money(item.quantity || 0)}</td><td>0</td><td>${VKTable.money(item.quantity || 0)}</td>
                        <td><span class="goods-issue-quantity">${VKTable.money(item.quantity || 0)}</span></td>
                    </tr>`).join('') || '<tr><td colspan="6">Đơn bán chưa có hàng hóa.</td></tr>'}</tbody>
            </table>
        </div>`;
}

function renderItems(items = []) {
    if (!items.length) return '-';
    return items.slice(0, 2).map(item => `${VKTable.escapeHtml(item.sku?.sku_code || '-')}: ${VKTable.money(item.quantity)}`).join('<br>');
}

function goodsIssueOrderLabel(row, newest = false) {
    const customer = row.customer?.name || 'Chưa có khách hàng';
    const createdAt = formatDateTime(row.created_at);
    const items = renderOrderItemsText(row.items || []);
    const amount = VKTable.money(row.total_amount || 0);
    const direct = row.fulfillment_type === 'supplier_direct' ? ' - [Giao thẳng, không trừ tồn]' : '';
    return `${newest ? '[Mới nhất] ' : ''}${row.code} - ${customer}${direct} - Tạo ${createdAt} - ${amount}${items ? ` - ${items}` : ''}`;
}

function renderOrderItemsText(items = []) {
    return items.slice(0, 2)
        .map(item => `${item.sku?.sku_code || '-'}:${VKTable.money(item.quantity)}`)
        .join(', ');
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
