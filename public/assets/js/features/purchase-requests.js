(function () {
let prState = { rows: [], q: '', status: '', view: 'list', detailCache: {} };
let purchaseRequestSkus = [];

document.addEventListener('vk:ready', () => loadPurchaseRequests());
document.addEventListener('vk:flow-updated', () => loadPurchaseRequests());
document.addEventListener('input', (event) => {
    if (!document.getElementById('purchaseRequestsRoot')) return;
    if (event.target.matches('[name="sku_id"], [name="quantity"]')) renderSelectedPurchaseItem();
    if (event.target.matches('[data-list-search]')) {
        prState.q = event.target.value.toLowerCase();
        renderPurchaseRequests();
    }
});
document.addEventListener('change', (event) => {
    if (!document.getElementById('purchaseRequestsRoot')) return;
    if (event.target.matches('[name="sku_id"], [name="quantity"]')) renderSelectedPurchaseItem();
    if (event.target.matches('[data-list-status]')) {
        prState.status = event.target.value;
        renderPurchaseRequests();
    }
});
document.addEventListener('click', (event) => {
    if (!document.getElementById('purchaseRequestsRoot')) return;
    if (event.target.matches('[data-create-purchase-request]')) openPurchaseRequestModal();
    if (event.target.matches('[data-approve-purchase-request]')) {
        event.stopPropagation();
        approvePurchaseRequest(event.target.dataset.approvePurchaseRequest);
    }
    if (event.target.closest('.row-action-menu')) return;
    const detail = event.target.closest('[data-purchase-request-detail], tr[data-row-detail]');
    if (detail) openPurchaseRequestDetail(detail.dataset.purchaseRequestDetail || detail.dataset.rowDetail);
});

async function loadPurchaseRequests(options = {}) {
    const root = document.getElementById('purchaseRequestsRoot');
    if (!root) return;
    if (options.source === 'pjax') {
        prState.view = 'list';
        prState.currentId = null;
        if (prState.rows.length) {
            renderPurchaseRequests();
        }
    }
    const requests = await VKApi.request('/purchase-requests');
    if (document.getElementById('purchaseRequestsRoot') !== root) return;
    prState.rows = requests.data || [];
    if (prState.view === 'detail' && prState.currentId) {
        openPurchaseRequestDetail(prState.currentId);
        return;
    }
    renderPurchaseRequests(requests.meta.total);
}

function renderPurchaseRequests(total = prState.rows.length) {
    const rows = filterRows();
    document.getElementById('purchaseRequestsRoot').innerHTML = VKTable.fullList({
        title: 'Danh sách yêu cầu mua',
        subtitle: 'Theo dõi nhu cầu mua phát sinh từ thiếu tồn hoặc yêu cầu nội bộ.',
        meta: `${VKTable.money(rows.length)} / ${VKTable.money(total)} yêu cầu`,
        filters: VKTable.filterBar({
            searchPlaceholder: 'Tìm mã yêu cầu hoặc nguồn...',
            status: [
                { value: 'draft', label: 'Nháp' },
                { value: 'approved', label: 'Đã duyệt' },
            ],
        }),
        table: renderPurchaseRequestTable(rows),
    });
    restoreFilters();
}

function renderPurchaseRequestTable(rows) {
    const itemRows = rows.flatMap(row => (row.items?.length ? row.items : [null]).map(item => ({ ...row, item })));
    return VKTable.renderTable([
        { label: 'Mã yêu cầu', render: row => `<span class="mono" style="color:#e60073;font-weight:600">${VKTable.escapeHtml(row.code)}</span>` },
        { label: 'Lý do mua', render: row => VKTable.escapeHtml(row.reason || '—') },
        { label: 'Mã hàng', render: row => `<span class="mono">${VKTable.escapeHtml(row.item?.sku?.sku_code || '—')}</span>` },
        { label: 'Tên hàng', render: row => VKTable.escapeHtml(row.item?.sku?.name || '—') },
        { label: 'Số lượng', render: row => row.item ? VKTable.money(row.item.quantity) : '—' },
        { label: 'ĐVT', render: row => VKTable.escapeHtml(row.item?.sku?.unit || '—') },
        { label: 'Nguồn', render: row => VKTable.escapeHtml(translatePurchaseRequestSource(row.source_type)) },
        { label: 'Người yêu cầu', render: row => VKTable.escapeHtml(row.requester?.name || '—') },
        { label: 'Tham chiếu', render: row => VKTable.escapeHtml(String(row.source_id || '—')) },
        { label: 'Trạng thái', render: row => VKTable.statusBadge(row.status) },
        { label: '', render: row => VKTable.rowActions([
            VKTable.smallButton('Mở', `data-purchase-request-detail="${row.id}"`),
            row.status === 'draft' ? VKTable.smallButton('Duyệt', `data-approve-purchase-request="${row.id}"`, 'primary') : '',
        ]) },
    ], itemRows, 'Chưa có yêu cầu mua', { rowAttr: row => `data-row-detail="${row.id}"` });
}

function filterRows() {
    return prState.rows.filter(row => {
        const items = (row.items || []).map(item => `${item.sku?.sku_code || ''} ${item.sku?.name || ''}`).join(' ');
        const haystack = `${row.code || ''} ${row.source_type || ''} ${row.reason || ''} ${row.requester?.name || ''} ${items}`.toLowerCase();
        return (!prState.q || haystack.includes(prState.q)) && (!prState.status || row.status === prState.status);
    });
}

function restoreFilters() {
    const search = document.querySelector('[data-list-search]');
    const status = document.querySelector('[data-list-status]');
    if (search) search.value = prState.q;
    if (status) status.value = prState.status;
}

function renderSource(row) {
    const source = translatePurchaseRequestSource(row.source_type);
    const reference = row.source_id ? row.source_id : 'Không có tham chiếu';
    const requester = row.requester?.name || 'Chưa xác định';
    return `${VKTable.escapeHtml(source)}<span class="row-note">Người yêu cầu: ${VKTable.escapeHtml(requester)} · Tham chiếu: ${VKTable.escapeHtml(reference)}</span>`;
}

function translatePurchaseRequestSource(source) {
    const map = {
        Manual: 'Tạo thủ công',
        SalesOrder: 'Từ đơn bán thiếu tồn',
        System: 'Hệ thống',
    };

    return map[source] || VKTable.translateEntity(source || 'System');
}

function renderRequestSummary(row) {
    const items = row.items || [];
    const reason = row.reason || 'Chưa ghi lý do mua';
    const itemLines = items.map(item => {
        const code = item.sku?.sku_code || '-';
        const name = item.sku?.name || 'Chưa có tên hàng';
        const unit = item.sku?.unit ? ` ${item.sku.unit}` : '';
        return `<span class="purchase-request-item-summary"><b>${VKTable.escapeHtml(code)}</b> · ${VKTable.escapeHtml(name)} · SL: <strong>${VKTable.money(item.quantity)}${VKTable.escapeHtml(unit)}</strong></span>`;
    }).join('');
    return `<div class="purchase-request-summary"><strong>${VKTable.escapeHtml(reason)}</strong><div>${itemLines || '<span class="row-note">Chưa có dòng hàng</span>'}</div></div>`;
}

async function openPurchaseRequestModal() {
    const skus = await VKApi.request('/skus?page_size=100');
    purchaseRequestSkus = skus.data || [];
    for (let page = 2; purchaseRequestSkus.length < Number(skus.meta?.total || 0); page++) {
        const next = await VKApi.request(`/skus?page_size=100&page=${page}`);
        if (!next.data?.length) break;
        purchaseRequestSkus.push(...next.data);
    }

    VKModal.open('Tạo yêu cầu mua', `
        <div class="form-grid">
            ${VKModal.select('sku_id', 'Mã hàng cần mua', purchaseRequestSkus.map(row => ({
                value: row.id,
                label: `${row.sku_code} - ${row.name || ''}`,
            })))}
            ${VKModal.field('quantity', 'Số lượng cần mua', 'number', '1')}
            <div class="field full pr-selected-item" data-pr-selected-item aria-live="polite"></div>
            <details class="field full procurement-quick-product"><summary>+ Tạo mặt hàng nhanh nếu chưa có trong danh mục</summary><p>Mã hàng được cấp tự động. Cần quyền quản lý dữ liệu nền; không làm tăng tồn kho.</p><div class="form-grid">${VKModal.field('quick_name', 'Tên mặt hàng mới', 'text', '')}${VKModal.field('quick_unit', 'Đơn vị tính', 'text', 'cái')}</div><button class="btn small" type="button" data-pr-quick-product>Tạo và chọn mặt hàng</button><span data-pr-quick-feedback role="status"></span></details>
            <div class="field full">
                <label for="reason">Lý do mua</label>
                <input id="reason" name="reason" type="text" value="Yêu cầu mua bổ sung">
            </div>
        </div>
    `, async (form) => {
        const data = Object.fromEntries(new FormData(form));
        const response = await VKApi.request('/purchase-requests', {
            method: 'POST',
            body: JSON.stringify({
                reason: data.reason || 'Yêu cầu mua bổ sung',
                items: [{
                    sku_id: Number(data.sku_id),
                    quantity: Number(data.quantity || 0),
                }],
            }),
        });

        VKModal.close();
        VKModal.notice({
            type: 'success',
            title: 'Đã tạo yêu cầu mua',
            message: 'Yêu cầu mua đã được tạo ở trạng thái nháp.',
            details: [`Mã yêu cầu: ${response.data?.code || ''}`, 'Bước tiếp theo: duyệt yêu cầu → lấy báo giá NCC → tạo đơn mua.'],
        });
        loadPurchaseRequests();
    }, {
        submitText: 'Tạo yêu cầu',
        className: 'modal-wide purchase-request-create-modal',
    });
    renderSelectedPurchaseItem();
}

function renderSelectedPurchaseItem() {
    const box = document.querySelector('[data-pr-selected-item]');
    if (!box) return;
    const form = box.closest('form');
    const sku = purchaseRequestSkus.find(row => String(row.id) === form.elements.sku_id.value);
    if (!sku) { box.innerHTML = '<p>Chọn mặt hàng hoặc tạo nhanh mặt hàng mới để xem thông tin.</p>'; return; }
    const quantity = Number(form.elements.quantity.value || 0);
    const value = text => VKTable.escapeHtml(text || 'Chưa khai báo');
    box.innerHTML = `<div class="pr-item-heading"><span>${value(sku.sku_code)}</span><div>${value(sku.name)}</div></div>
        <dl><div><dt>Đơn vị tính</dt><dd>${value(sku.unit)}</dd></div><div><dt>Số lượng đề nghị</dt><dd>${VKTable.money(quantity)} ${value(sku.unit)}</dd></div><div><dt>Giá mua tham khảo</dt><dd>${Number(sku.cost_price) > 0 ? `${VKTable.money(sku.cost_price)} đ` : 'Chưa có giá'}</dd></div><div><dt>Giá trị dự kiến</dt><dd>${Number(sku.cost_price) > 0 ? `${VKTable.money(quantity * Number(sku.cost_price))} đ` : 'Chờ báo giá NCC'}</dd></div></dl>
        ${sku.product?.description ? `<p>${value(sku.product.description)}</p>` : ''}<small>Giá tham khảo từ danh mục, không phải giá đã được nhà cung cấp xác nhận.</small>`;
}

async function approvePurchaseRequest(id) {
    try {
        const response = await VKApi.request(`/purchase-requests/${id}/approve`, { method: 'POST', body: JSON.stringify({ reason: 'Duyệt từ màn hình mua hàng' }) });
        const request = response.data || {};
        prState.detailCache[String(id)] = null;
        prState.rows = prState.rows.map(row => String(row.id) === String(id) ? { ...row, ...request } : row);
        VKModal.notice({
            type: 'success',
            title: 'Đã duyệt yêu cầu mua',
            message: 'Yêu cầu đã xuất hiện ở mục Chờ lấy báo giá nhà cung cấp.',
            details: [`Mã yêu cầu: ${request.code || ''}`, 'Mở Báo giá nhà cung cấp → Lấy báo giá để tiếp tục.'],
        });
        if (prState.view === 'detail' && String(prState.currentId) === String(id)) {
            openPurchaseRequestDetail(id);
            return;
        }
        loadPurchaseRequests();
    } catch (error) {
        VKModal.notice({
            type: 'danger',
            title: 'Không thể duyệt yêu cầu mua',
            message: error.message || 'Có lỗi xảy ra khi duyệt yêu cầu mua.',
        });
    }
}

function openPurchaseRequestDetail(id) {
    prState.view = 'detail';
    prState.currentId = id;
    VKRecordPage.open({
        root: '#purchaseRequestsRoot',
        type: 'purchaseRequest',
        id,
        path: `/purchase-requests/${id}`,
        preview: prState.rows.find(row => String(row.id) === String(id)),
        cache: prState.detailCache,
        onBack: () => {
            prState.view = 'list';
            prState.currentId = null;
            renderPurchaseRequests();
        },
        actions: row => row.status === 'draft'
            ? `<button class="btn primary small" type="button" data-approve-purchase-request="${row.id}">Duyệt yêu cầu</button>`
            : `<a class="btn small" href="/supplier-quotations?request=${row.id}">Lấy báo giá NCC</a>`,
    });
}

window.loadPurchaseRequests = loadPurchaseRequests;
document.addEventListener('click', async event => {
    const button = event.target.closest('[data-pr-quick-product]');
    if (!button || !document.getElementById('purchaseRequestsRoot')) return;
    const form = button.closest('form');
    const feedback = form.querySelector('[data-pr-quick-feedback]');
    const name = form.elements.quick_name.value.trim();
    const unit = form.elements.quick_unit.value.trim();
    if (!name || !unit) { feedback.textContent = 'Nhập tên hàng và đơn vị tính trước.'; return; }
    button.disabled = true;
    try {
        const response = await VKApi.request('/skus', { method:'POST', body:JSON.stringify({name, unit, quick_create:true}) });
        const sku = response.data;
        purchaseRequestSkus.push(sku);
        const option = new Option(`${sku.sku_code} - ${sku.name}`, sku.id, true, true);
        form.elements.sku_id.add(option);
        renderSelectedPurchaseItem();
        feedback.textContent = `Đã tạo ${sku.sku_code} và chọn cho yêu cầu mua.`;
        form.elements.quick_name.value = '';
    } catch (error) { feedback.textContent = error.message || 'Không thể tạo mặt hàng.'; }
    finally { button.disabled = false; }
});
})();
