(function () {
let dealState = { rows: [], q: '', stage: '', stages: {} };

document.addEventListener('vk:ready', () => loadDeals());
document.addEventListener('input', event => {
    if (!document.getElementById('dealsRoot') || !event.target.matches('[data-list-search]')) return;
    dealState.q = event.target.value.toLowerCase(); renderDeals();
});
document.addEventListener('change', event => {
    if (!document.getElementById('dealsRoot') || !event.target.matches('[data-deal-stage]')) return;
    dealState.stage = event.target.value; renderDeals();
});
document.addEventListener('click', event => {
    if (!document.getElementById('dealsRoot')) return;
    const create = event.target.closest('[data-create-deal]');
    const transition = event.target.closest('[data-transition-deal]');
    const detail = event.target.closest('[data-deal-detail]');
    const quotation = event.target.closest('[data-deal-create-quotation]');
    const editItems = event.target.closest('[data-deal-edit-items]');
    const addActivity = event.target.closest('[data-deal-add-activity]');
    const addLine = event.target.closest('[data-deal-add-line]');
    const removeLine = event.target.closest('[data-deal-remove-line]');
    if (create) openDealModal();
    if (transition) openTransitionModal(transition.dataset.transitionDeal, transition._dealRow);
    if (detail) openDealDetail(detail.dataset.dealDetail);
    if (quotation) {
        window.location.href = `/quotations?deal_id=${encodeURIComponent(quotation.dataset.dealCreateQuotation)}`;
    }
    if (editItems) openDealItemsModal(editItems.dataset.dealEditItems);
    if (addActivity) openDealActivityModal(addActivity.dataset.dealAddActivity);
    if (addLine) addDealItemLine();
    if (removeLine) removeLine.closest('[data-deal-item-line]')?.remove();
});

async function loadDeals() {
    const root = document.getElementById('dealsRoot');
    if (!root) return;
    const response = await VKApi.request('/deals?page_size=100');
    if (document.getElementById('dealsRoot') !== root) return;
    dealState.rows = response.data || [];
    dealState.stages = response.meta?.stages || {};
    renderDeals(response.meta?.total || dealState.rows.length);
    const requestedId = new URLSearchParams(window.location.search).get('open');
    if (requestedId && /^\d+$/.test(requestedId)) openDealDetail(requestedId);
}

function renderDeals(total = dealState.rows.length) {
    const rows = dealState.rows.filter(row => {
        const text = `${row.code || ''} ${row.name || ''} ${row.customer?.name || ''} ${row.lead?.name || ''} ${row.owner?.name || ''}`.toLowerCase();
        return (!dealState.q || text.includes(dealState.q)) && (!dealState.stage || row.stage === dealState.stage);
    });
    const stages = Object.keys(dealState.stages).map(key => ({ value: key, label: stageLabel(key) }));
    VKTable.updateList(document.getElementById('dealsRoot'), VKTable.fullList({
        title: 'Danh sách cơ hội bán hàng',
        subtitle: 'Mỗi cơ hội có người phụ trách, giá trị dự kiến, giai đoạn và bước tiếp theo.',
        meta: `${VKTable.money(rows.length)} / ${VKTable.money(total)} cơ hội`,
        filters: VKTable.filterBar({
            searchPlaceholder: 'Tìm mã, tên cơ hội hoặc khách hàng...',
            extra: `<select data-deal-stage><option value="">Tất cả giai đoạn</option>${stages.map(item => `<option value="${item.value}">${item.label}</option>`).join('')}</select>`,
        }),
        table: VKTable.renderTable([
            { label: 'Cơ hội', render: row => `<strong>${VKTable.escapeHtml(row.name)}</strong><span class="row-note mono">${VKTable.escapeHtml(row.code)}</span>` },
            { label: 'Khách hàng', render: row => VKTable.escapeHtml(row.customer?.name || row.lead?.name || 'Chưa gắn khách hàng') },
            { label: 'Giá trị dự kiến', render: row => `<strong>${VKTable.money(row.amount || 0)}</strong><span class="row-note">${Number(row.probability || 0).toFixed(0)}% xác suất</span>` },
            { label: 'Giai đoạn', render: row => `<span class="deal-stage stage-${row.stage}">${stageLabel(row.stage)}</span>` },
            { label: 'Dự kiến chốt', render: row => formatDate(row.expected_close_date) },
            { label: 'Phụ trách', render: row => VKTable.escapeHtml(row.owner?.name || '-') },
            { label: '', render: row => row.status === 'open' ? VKTable.rowActions([
                VKTable.smallButton('Mở', `data-deal-detail="${row.id}"`),
                ['qualified', 'proposal', 'negotiation'].includes(row.stage) ? VKTable.smallButton('Tạo báo giá', `data-deal-create-quotation="${row.id}"`) : '',
                VKTable.smallButton('Chuyển giai đoạn', `data-transition-deal="${row.id}"`, 'primary'),
            ].filter(Boolean)) : VKTable.statusBadge(row.status) },
        ], rows, 'Chưa có cơ hội bán hàng'),
    }));
    const search = document.querySelector('[data-list-search]'); if (search && document.activeElement !== search) search.value = dealState.q;
    const stage = document.querySelector('[data-deal-stage]'); if (stage) stage.value = dealState.stage;
}

async function openDealModal() {
    const [customers, leads, me, masterData] = await Promise.all([VKApi.request('/customers?page_size=100'), VKApi.request('/leads?page_size=100'), VKApi.request('/me'), VKApi.request('/sales-master-data')]);
    const sources = (masterData.data?.opportunity_sources || []).filter(row => row.is_active).map(row => ({ value: row.code, label: row.name }));
    VKModal.open('Tạo cơ hội bán hàng', `
        <div class="form-grid">
            ${VKModal.field('name', 'Tên cơ hội', 'text', '', { required: true })}
            ${VKModal.select('customer_id', 'Khách hàng', [{ value: '', label: 'Chưa chọn khách hàng' }, ...(customers.data || []).map(row => ({ value: row.id, label: `${row.code} - ${row.name}` }))])}
            ${VKModal.select('lead_id', 'Khách hàng tiềm năng', [{ value: '', label: 'Không gắn khách hàng tiềm năng' }, ...(leads.data || []).map(row => ({ value: row.id, label: `${row.code} - ${row.name}` }))])}
            ${VKModal.select('source', 'Nguồn cơ hội', [{ value: '', label: 'Chưa chọn nguồn' }, ...sources])}
            ${VKModal.field('amount', 'Giá trị dự kiến', 'number', '0')}
            ${VKModal.field('expected_close_date', 'Ngày dự kiến chốt', 'date')}
            ${VKModal.field('next_activity_at', 'Hoạt động tiếp theo', 'datetime-local')}
            ${VKModal.select('owner_id', 'Người phụ trách', [{ value: me.data.id, label: me.data.name }])}
            <div class="field full"><label for="description">Nhu cầu / ghi chú cơ hội</label><textarea id="description" name="description" rows="3" placeholder="Sản phẩm quan tâm, yêu cầu của khách hoặc thông tin cần lưu ý..."></textarea></div>
        </div>
    `, async form => {
        const data = Object.fromEntries(new FormData(form));
        ['customer_id', 'lead_id', 'owner_id'].forEach(key => { data[key] = data[key] ? Number(data[key]) : null; });
        data.amount = Number(data.amount || 0);
        await VKApi.request('/deals', { method: 'POST', body: JSON.stringify(data) });
        VKModal.close(); VKModal.toast('Đã tạo cơ hội bán hàng.', 'success'); loadDeals();
    }, { submitText: 'Tạo cơ hội' });
}

function openTransitionModal(id, sourceRow = null) {
    const deal = sourceRow || dealState.rows.find(row => String(row.id) === String(id));
    if (!deal) return;
    const choices = Object.keys(dealState.stages).filter(stage => stage !== deal.stage).map(stage => ({ value: stage, label: stageLabel(stage) }));
    VKModal.open(`Chuyển giai đoạn ${deal.code}`, `
        <div class="form-grid">
            ${VKModal.select('stage', 'Giai đoạn mới', choices)}
            <div class="field full"><label for="reason">Lý do / ghi chú</label><textarea id="reason" name="reason" rows="3" placeholder="Bắt buộc khi đánh dấu mất cơ hội"></textarea></div>
        </div>
    `, async form => {
        const data = Object.fromEntries(new FormData(form));
        data.version = Number(deal.version);
        await VKApi.request(`/deals/${id}/transition`, { method: 'POST', body: JSON.stringify(data) });
        VKModal.close(); VKModal.toast('Đã chuyển giai đoạn cơ hội.', 'success'); loadDeals();
    }, { submitText: 'Xác nhận chuyển' });
}

function openDealDetail(id) {
    const preview = dealState.rows.find(row => String(row.id) === String(id)) || {};
    VKRecordPage.open({
        root: '#dealsRoot', type: 'deal', id, path: `/deals/${id}`, preview,
        onBack: () => renderDeals(),
        actions: row => row.status === 'open' ? `
            <button class="btn secondary" type="button" data-deal-add-activity="${row.id}">+ Chăm sóc</button>
            <button class="btn secondary" type="button" data-deal-edit-items="${row.id}">Hàng hóa</button>
            <button class="btn secondary" type="button" data-deal-create-quotation="${row.id}">Tạo báo giá</button>
            <button class="btn primary" type="button" data-transition-deal="${row.id}">Chuyển giai đoạn</button>
        ` : '<span class="badge muted">Cơ hội đã kết thúc</span>',
    });
}

async function openDealItemsModal(id) {
    const [detail, skuResponse] = await Promise.all([
        VKApi.request(`/deals/${id}`),
        VKApi.request('/skus?page_size=100'),
    ]);
    const deal = detail.data;
    const skus = skuResponse.data || [];
    const content = `
        <div class="deal-items-editor">
            <div class="deal-items-editor-head"><span>Hàng hóa dự kiến cho cơ hội</span><button class="btn small" type="button" data-deal-add-line>+ Thêm hàng</button></div>
            <div data-deal-item-lines>${(deal.items || []).map(item => dealItemLine(skus, item)).join('') || dealItemLine(skus)}</div>
            <p class="deal-items-editor-note">Giá trị dự kiến của cơ hội sẽ tự tính lại theo các dòng hàng này.</p>
        </div>`;
    VKModal.open(`Hàng hóa · ${deal.code}`, content, async () => {
        const items = [...document.querySelectorAll('[data-deal-item-line]')].map(line => ({
            sku_id: Number(line.querySelector('[data-deal-item-sku]').value),
            quantity: Number(line.querySelector('[data-deal-item-quantity]').value || 0),
            unit_price: Number(line.querySelector('[data-deal-item-price]').value || 0),
        })).filter(item => item.sku_id && item.quantity > 0);
        await VKApi.request(`/deals/${id}/items`, { method: 'PUT', body: JSON.stringify({ items }) });
        VKModal.close();
        VKModal.toast('Đã cập nhật hàng hóa và giá trị cơ hội.', 'success');
        await reloadDealDetail(id);
    }, { submitText: 'Lưu hàng hóa', className: 'modal-wide' });
    window.dealSkuOptions = skus;
}

function dealItemLine(skus, item = {}) {
    const options = [{ id: '', sku_code: '', name: 'Chọn hàng hóa' }, ...skus].map(sku => `<option value="${sku.id}" ${String(sku.id) === String(item.sku_id) ? 'selected' : ''}>${VKTable.escapeHtml(sku.id ? `${sku.sku_code} - ${sku.name}` : sku.name)}</option>`).join('');
    return `<div class="deal-item-line" data-deal-item-line>
        <select data-deal-item-sku>${options}</select>
        <input data-deal-item-quantity type="number" min="0.001" step="0.001" value="${VKTable.escapeHtml(String(item.quantity || 1))}" aria-label="Số lượng">
        <input data-deal-item-price type="number" min="0" step="1000" value="${VKTable.escapeHtml(String(item.unit_price || 0))}" aria-label="Đơn giá">
        <button class="btn small danger" type="button" data-deal-remove-line aria-label="Xóa dòng">×</button>
    </div>`;
}

function addDealItemLine() {
    const container = document.querySelector('[data-deal-item-lines]');
    if (container) container.insertAdjacentHTML('beforeend', dealItemLine(window.dealSkuOptions || []));
}

async function openDealActivityModal(id) {
    const detail = await VKApi.request(`/deals/${id}`);
    const deal = detail.data;
    VKModal.open(`Thêm lần chăm sóc · ${deal.code}`, `<div class="form-grid">
        ${VKModal.field('title', 'Nội dung cần thực hiện', 'text', `Chăm sóc cơ hội ${deal.name}`)}
        ${VKModal.field('due_at', 'Thời gian thực hiện', 'datetime-local')}
        ${VKModal.select('priority', 'Mức ưu tiên', [{ value: 'normal', label: 'Bình thường' }, { value: 'high', label: 'Cao' }, { value: 'urgent', label: 'Khẩn' }, { value: 'low', label: 'Thấp' }])}
        <div class="field full"><label for="description">Nội dung trao đổi / ghi chú</label><textarea id="description" name="description" rows="4" placeholder="Ví dụ: gọi xác nhận nhu cầu, hẹn demo, gửi tài liệu..."></textarea></div>
    </div>`, async form => {
        const data = Object.fromEntries(new FormData(form));
        await VKApi.request(`/deals/${id}/activities`, { method: 'POST', body: JSON.stringify(data) });
        VKModal.close();
        VKModal.toast('Đã tạo công việc chăm sóc cho cơ hội.', 'success');
        await reloadDealDetail(id);
    }, { submitText: 'Tạo lần chăm sóc' });
}

async function reloadDealDetail(id) {
    await loadDeals();
    openDealDetail(id);
}

function stageLabel(stage) { return ({ new: 'Mới', qualified: 'Đã xác minh', proposal: 'Đề xuất', negotiation: 'Đàm phán', won: 'Thắng', lost: 'Mất' })[stage] || stage || '-'; }
function formatDate(value) { if (!value) return '-'; const date = new Date(value); return Number.isNaN(date.getTime()) ? '-' : date.toLocaleDateString('vi-VN'); }
window.loadDeals = loadDeals;
})();
