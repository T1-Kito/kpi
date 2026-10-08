(function () {
let state = { stages: [], sources: [], priceBooks: [], paymentTerms: [] };
document.addEventListener('vk:ready', load);
document.addEventListener('click', event => {
    if (!document.getElementById('salesMasterDataRoot')) return;
    const create = event.target.closest('[data-sales-master-create]');
    const edit = event.target.closest('[data-sales-master-edit]');
    if (create) openForm(create.dataset.salesMasterCreate);
    if (edit) openForm(edit.dataset.salesMasterEdit, Number(edit.dataset.id));
});
async function load() {
    const root = document.getElementById('salesMasterDataRoot'); if (!root) return;
    const response = await VKApi.request('/sales-master-data');
    state.stages = response.data.pipeline_stages || []; state.sources = response.data.opportunity_sources || []; state.priceBooks = response.data.price_books || []; state.paymentTerms = response.data.payment_terms || [];
    root.innerHTML = `<section class="sales-master-page">
        <div class="sales-master-intro"><strong>Dùng dữ liệu nền một lần, toàn bộ luồng bán hàng dùng chung.</strong><span>Pipeline quyết định bước xử lý và dự báo; nguồn cơ hội giúp đo hiệu quả marketing và giới thiệu.</span></div>
        <div class="sales-master-grid">
            ${renderStages()}${renderSources()}${renderPriceBooks()}${renderPaymentTerms()}
        </div>
    </section>`;
}
function renderStages() { return `<section class="record-panel"><div class="panel-head"><div><h3>Giai đoạn Pipeline</h3><p>Áp dụng cho mọi cơ hội bán hàng.</p></div><button class="btn small primary" data-sales-master-create="stage">+ Thêm giai đoạn</button></div>${VKTable.renderTable([
    { label: 'Giai đoạn', render: r => `<strong>${VKTable.escapeHtml(r.name)}</strong><span class="row-note mono">${VKTable.escapeHtml(r.code)}</span>` },
    { label: 'Xác suất', render: r => `${r.probability}%` }, { label: 'Kết quả', render: r => r.type === 'won' ? 'Thắng' : (r.type === 'lost' ? 'Mất' : 'Đang theo đuổi') }, { label: 'Trạng thái', render: r => VKTable.statusBadge(r.is_active ? 'active' : 'inactive') }, { label: '', render: r => VKTable.rowActions([VKTable.smallButton('Sửa', `data-sales-master-edit="stage" data-id="${r.id}"`)]) },
], state.stages, 'Chưa có giai đoạn')}</section>`; }
function renderSources() { return `<section class="record-panel"><div class="panel-head"><div><h3>Nguồn cơ hội</h3><p>Đo nguồn nào mang lại cơ hội và doanh thu.</p></div><button class="btn small primary" data-sales-master-create="source">+ Thêm nguồn</button></div>${VKTable.renderTable([
    { label: 'Tên nguồn', render: r => `<strong>${VKTable.escapeHtml(r.name)}</strong>` }, { label: 'Mã', render: r => `<span class="mono">${VKTable.escapeHtml(r.code)}</span>` }, { label: 'Trạng thái', render: r => VKTable.statusBadge(r.is_active ? 'active' : 'inactive') }, { label: '', render: r => VKTable.rowActions([VKTable.smallButton('Sửa', `data-sales-master-edit="source" data-id="${r.id}"`)]) },
], state.sources, 'Chưa có nguồn cơ hội')}</section>`; }
function renderPriceBooks() { return `<section class="record-panel"><div class="panel-head"><div><h3>Chính sách giá</h3><p>Áp dụng mức chiết khấu chuẩn khi lập báo giá.</p></div><button class="btn small primary" data-sales-master-create="priceBook">+ Thêm chính sách</button></div>${VKTable.renderTable([
    { label: 'Chính sách', render: r => `<strong>${VKTable.escapeHtml(r.name)}</strong><span class="row-note mono">${VKTable.escapeHtml(r.code)}</span>` }, { label: 'Chiết khấu', render: r => `${Number(r.discount_percent || 0).toFixed(2)}%` }, { label: 'Hiệu lực', render: r => `${date(r.effective_from)} → ${date(r.effective_to)}` }, { label: 'Mặc định', render: r => r.is_default ? '<span class="status-badge active">Mặc định</span>' : '-' }, { label: '', render: r => VKTable.rowActions([VKTable.smallButton('Sửa', `data-sales-master-edit="priceBook" data-id="${r.id}"`)]) },
], state.priceBooks, 'Chưa có chính sách giá')}</section>`; }
function renderPaymentTerms() { return `<section class="record-panel"><div class="panel-head"><div><h3>Điều khoản thanh toán</h3><p>Dùng chung cho báo giá, đơn hàng và hợp đồng.</p></div><button class="btn small primary" data-sales-master-create="paymentTerm">+ Thêm điều khoản</button></div>${VKTable.renderTable([
    { label: 'Điều khoản', render: r => `<strong>${VKTable.escapeHtml(r.name)}</strong><span class="row-note mono">${VKTable.escapeHtml(r.code)}</span>` }, { label: 'Hạn thanh toán', render: r => r.due_days ? `${r.due_days} ngày` : 'Thanh toán ngay' }, { label: 'Mặc định', render: r => r.is_default ? '<span class="status-badge active">Mặc định</span>' : '-' }, { label: 'Trạng thái', render: r => VKTable.statusBadge(r.is_active ? 'active' : 'inactive') }, { label: '', render: r => VKTable.rowActions([VKTable.smallButton('Sửa', `data-sales-master-edit="paymentTerm" data-id="${r.id}"`)]) },
], state.paymentTerms, 'Chưa có điều khoản')}</section>`; }
function openForm(kind, id = null) {
    const rows = { stage: state.stages, source: state.sources, priceBook: state.priceBooks, paymentTerm: state.paymentTerms };
    const row = id ? rows[kind].find(item => item.id === id) : null;
    const isStage = kind === 'stage';
    const status = [{ value: '1', label: 'Đang dùng' }, { value: '0', label: 'Ngừng dùng' }];
    const defaults = [{ value: '1', label: 'Có' }, { value: '0', label: 'Không' }];
    let fields, title;
    if (isStage) { title = 'giai đoạn Pipeline'; fields = `<div class="form-grid">${row ? '' : VKModal.field('code', 'Mã giai đoạn (không dấu)', 'text')} ${VKModal.field('name', 'Tên giai đoạn', 'text', row?.name || '')} ${VKModal.field('probability', 'Xác suất (%)', 'number', row?.probability ?? 10)} ${VKModal.field('sort_order', 'Thứ tự hiển thị', 'number', row?.sort_order ?? state.stages.length * 10 + 10)} ${VKModal.select('type', 'Kết quả', [{value:'open',label:'Đang theo đuổi'},{value:'won',label:'Thắng'},{value:'lost',label:'Mất'}], row?.type || 'open')} ${VKModal.select('is_active', 'Trạng thái', status, row?.is_active === false ? '0' : '1')}</div>`; }
    else if (kind === 'source') { title = 'nguồn cơ hội'; fields = `<div class="form-grid">${row ? '' : VKModal.field('code', 'Mã nguồn (không dấu)', 'text')} ${VKModal.field('name', 'Tên nguồn', 'text', row?.name || '')} ${VKModal.select('is_active', 'Trạng thái', status, row?.is_active === false ? '0' : '1')}</div>`; }
    else if (kind === 'priceBook') { title = 'chính sách giá'; fields = `<div class="form-grid">${row ? '' : VKModal.field('code', 'Mã chính sách', 'text')} ${VKModal.field('name', 'Tên chính sách', 'text', row?.name || '')} ${VKModal.field('discount_percent', 'Chiết khấu (%)', 'number', row?.discount_percent ?? 0)} ${VKModal.field('effective_from', 'Hiệu lực từ', 'date', row?.effective_from || '')} ${VKModal.field('effective_to', 'Hiệu lực đến', 'date', row?.effective_to || '')} ${VKModal.select('is_default', 'Dùng mặc định', defaults, row?.is_default ? '1' : '0')} ${VKModal.select('is_active', 'Trạng thái', status, row?.is_active === false ? '0' : '1')}</div>`; }
    else { title = 'điều khoản thanh toán'; fields = `<div class="form-grid">${row ? '' : VKModal.field('code', 'Mã điều khoản', 'text')} ${VKModal.field('name', 'Tên điều khoản', 'text', row?.name || '')} ${VKModal.field('due_days', 'Số ngày thanh toán', 'number', row?.due_days ?? 0)} ${VKModal.select('is_default', 'Dùng mặc định', defaults, row?.is_default ? '1' : '0')} ${VKModal.select('is_active', 'Trạng thái', status, row?.is_active === false ? '0' : '1')}</div>`; }
    VKModal.open(`${row ? 'Sửa' : 'Thêm'} ${title}`, fields, async form => {
        const data = Object.fromEntries(new FormData(form)); data.is_active = data.is_active === '1'; if (isStage) { data.probability = Number(data.probability); data.sort_order = Number(data.sort_order); }
        if (['priceBook', 'paymentTerm'].includes(kind)) { data.is_default = data.is_default === '1'; if (kind === 'priceBook') data.discount_percent = Number(data.discount_percent); else data.due_days = Number(data.due_days); }
        const paths = { stage: 'stages', source: 'sources', priceBook: 'price-books', paymentTerm: 'payment-terms' };
        await VKApi.request(row ? `/sales-master-data/${paths[kind]}/${row.id}` : `/sales-master-data/${paths[kind]}`, { method: row ? 'PUT' : 'POST', body: JSON.stringify(data) });
        VKModal.close(); VKModal.toast('Đã lưu dữ liệu nền.'); load();
    }, { submitText: 'Lưu' });
}
function date(value) { return value ? new Date(value).toLocaleDateString('vi-VN') : 'Không giới hạn'; }
window.loadSalesMasterData = load;
})();
