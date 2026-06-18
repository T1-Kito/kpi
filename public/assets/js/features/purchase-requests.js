(function () {
let prState = { rows: [], q: '', status: '', view: 'list', detailCache: {} };

document.addEventListener('vk:ready', () => loadPurchaseRequests());
document.addEventListener('vk:flow-updated', () => loadPurchaseRequests());
document.addEventListener('input', (event) => {
    if (!document.getElementById('purchaseRequestsRoot')) return;
    if (event.target.matches('[data-list-search]')) {
        prState.q = event.target.value.toLowerCase();
        renderPurchaseRequests();
    }
});
document.addEventListener('change', (event) => {
    if (!document.getElementById('purchaseRequestsRoot')) return;
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
    return VKTable.renderTable([
        { label: 'Mã yêu cầu', render: row => `<span class="mono">${VKTable.escapeHtml(row.code)}</span>` },
        { label: 'Nguồn', render: row => renderSource(row) },
        { label: 'Số dòng', render: row => String(row.items?.length || 0) },
        { label: 'Nhu cầu', render: row => renderItems(row.items) },
        { label: 'Trạng thái', render: row => VKTable.statusBadge(row.status) },
        { label: '', render: row => VKTable.rowActions([
            VKTable.smallButton('Mở', `data-purchase-request-detail="${row.id}"`),
            row.status === 'draft' ? VKTable.smallButton('Duyệt', `data-approve-purchase-request="${row.id}"`, 'primary') : '',
        ]) },
    ], rows, 'Chưa có yêu cầu mua', { rowAttr: row => `data-row-detail="${row.id}"` });
}

function filterRows() {
    return prState.rows.filter(row => {
        const haystack = `${row.code || ''} ${row.source_type || ''} ${row.reason || ''}`.toLowerCase();
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
    return `${VKTable.escapeHtml(source)}<span class="row-note">Tham chiếu: ${VKTable.escapeHtml(reference)}</span>`;
}

function translatePurchaseRequestSource(source) {
    const map = {
        Manual: 'Tạo thủ công',
        SalesOrder: 'Từ đơn bán thiếu tồn',
        System: 'Hệ thống',
    };

    return map[source] || VKTable.translateEntity(source || 'System');
}

function renderItems(items = []) {
    if (!items.length) return '-';
    return items.slice(0, 2).map(item => `${VKTable.escapeHtml(item.sku?.sku_code || '-')}: ${VKTable.money(item.quantity)}`).join('<br>');
}

async function openPurchaseRequestModal() {
    const skus = await VKApi.request('/skus?page_size=100');
    if (!skus.data?.length) {
        VKModal.notice({
            type: 'warning',
            title: 'Chưa có mã hàng',
            message: 'Bạn cần tạo mã hàng trước khi lập yêu cầu mua.',
        });
        return;
    }

    VKModal.open('Tạo yêu cầu mua', `
        <div class="form-grid">
            ${VKModal.select('sku_id', 'Mã hàng cần mua', skus.data.map(row => ({
                value: row.id,
                label: `${row.sku_code} - ${row.name || ''}`,
            })))}
            ${VKModal.field('quantity', 'Số lượng cần mua', 'number', '1')}
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
            details: [`Mã yêu cầu: ${response.data?.code || ''}`, 'Bước tiếp theo: duyệt yêu cầu rồi tạo đơn mua.'],
        });
        loadPurchaseRequests();
    }, {
        submitText: 'Tạo yêu cầu',
    });
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
            message: 'Yêu cầu mua đã sẵn sàng để tạo đơn mua.',
            details: [`Mã yêu cầu: ${request.code || ''}`, 'Bước tiếp theo: qua tab Đơn mua để tạo PO.'],
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
            : '',
    });
}

window.loadPurchaseRequests = loadPurchaseRequests;
})();
