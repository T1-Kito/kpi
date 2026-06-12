let quotationState = { rows: [], q: '', status: '' };

document.addEventListener('vk:ready', () => loadQuotations());
document.addEventListener('vk:flow-updated', () => loadQuotations());
document.addEventListener('input', (event) => {
    if (!document.getElementById('quotationsRoot')) return;
    if (event.target.matches('[data-list-search]')) {
        quotationState.q = event.target.value.toLowerCase();
        renderQuotations();
    }
});
document.addEventListener('change', (event) => {
    if (!document.getElementById('quotationsRoot')) return;
    if (event.target.matches('[data-list-status]')) {
        quotationState.status = event.target.value;
        renderQuotations();
    }
});
document.addEventListener('click', (event) => {
    if (!document.getElementById('quotationsRoot')) return;
    if (event.target.matches('[data-create-quotation]')) openQuotationModal();
    if (event.target.matches('[data-add-quotation-line]')) {
        event.preventDefault();
        addQuotationLine(event.target.dataset.skus || '[]');
    }
    if (event.target.matches('[data-remove-quotation-line]')) {
        event.preventDefault();
        event.target.closest('[data-quotation-line]')?.remove();
        ensureQuotationLine();
    }
    if (event.target.matches('[data-approve-quotation]')) {
        event.stopPropagation();
        approveQuotation(event.target.dataset.approveQuotation, 'approved');
    }
    if (event.target.matches('[data-reject-quotation]')) {
        event.stopPropagation();
        approveQuotation(event.target.dataset.rejectQuotation, 'rejected');
    }
    if (event.target.closest('.row-action-menu')) return;
    const detail = event.target.closest('[data-quotation-detail], tr[data-row-detail]');
    if (detail) openQuotationDetail(detail.dataset.quotationDetail || detail.dataset.rowDetail);
});
document.addEventListener('change', (event) => {
    if (!document.getElementById('quotationsRoot')) return;
    if (event.target.matches('[data-quotation-sku]')) {
        const option = event.target.selectedOptions[0];
        const line = event.target.closest('[data-quotation-line]');
        const price = line?.querySelector('[name="unit_price[]"]');
        const unit = line?.querySelector('[data-quotation-unit]');
        if (price && option?.dataset.price) price.value = option.dataset.price;
        if (unit) unit.textContent = option?.dataset.unit || '-';
    }
});

async function loadQuotations() {
    if (!document.getElementById('quotationsRoot')) return;
    const quotations = await VKApi.request('/quotations');
    quotationState.rows = quotations.data || [];
    renderQuotations(quotations.meta.total);
}

function renderQuotations(total = quotationState.rows.length) {
    const rows = filterRows();
    document.getElementById('quotationsRoot').innerHTML = VKTable.fullList({
        title: 'Danh sách báo giá',
        subtitle: 'Theo dõi biên lợi nhuận, trạng thái duyệt và khả năng tạo đơn bán.',
        meta: `${VKTable.money(rows.length)} / ${VKTable.money(total)} báo giá`,
        filters: VKTable.filterBar({
            searchPlaceholder: 'Tìm mã báo giá hoặc khách hàng...',
            status: [
                { value: 'draft', label: 'Nháp' },
                { value: 'pending_approval', label: 'Chờ duyệt' },
                { value: 'ready', label: 'Sẵn sàng' },
                { value: 'approved', label: 'Đã duyệt' },
                { value: 'rejected', label: 'Từ chối' },
            ],
        }),
        table: renderQuotationTable(rows),
    });
    restoreFilters();
}

function renderQuotationTable(rows) {
    return VKTable.renderTable([
        { label: 'Mã báo giá', render: row => `<span class="mono">${VKTable.escapeHtml(row.code)}</span>` },
        { label: 'Khách hàng', render: row => VKTable.escapeHtml(row.customer?.name || '-') },
        { label: 'Tổng tiền', render: row => VKTable.money(row.total_amount) },
        { label: 'Biên lợi nhuận', render: row => renderMargin(row.margin_percent) },
        { label: 'Dòng hàng', render: row => renderItems(row.items) },
        { label: 'Trạng thái', render: row => VKTable.statusBadge(row.status) },
        { label: '', render: row => VKTable.rowActions([
            VKTable.smallButton('Mở', `data-quotation-detail="${row.id}"`),
            ...quotationActions(row),
        ]) },
    ], rows, 'Chưa có báo giá', { rowAttr: row => `data-row-detail="${row.id}"` });
}

function filterRows() {
    return quotationState.rows.filter(row => {
        const haystack = `${row.code || ''} ${row.customer?.name || ''}`.toLowerCase();
        return (!quotationState.q || haystack.includes(quotationState.q)) && (!quotationState.status || row.status === quotationState.status);
    });
}

function restoreFilters() {
    const search = document.querySelector('[data-list-search]');
    const status = document.querySelector('[data-list-status]');
    if (search) search.value = quotationState.q;
    if (status) status.value = quotationState.status;
}

function renderMargin(value) {
    const margin = Number(value || 0);
    return `<span class="badge ${margin < 15 ? 'warning' : 'success'}">${margin.toFixed(2)}%</span>`;
}

function renderItems(items = []) {
    if (!items.length) return '-';
    return items.slice(0, 2).map(item => `${VKTable.escapeHtml(item.sku?.sku_code || '-')}: ${VKTable.money(item.quantity)}`).join('<br>');
}

function quotationActions(row) {
    if (row.status !== 'pending_approval' || !window.VKLayout?.hasPermission('sales.margin.approve')) return [];
    return [
        VKTable.smallButton('Duyệt', `data-approve-quotation="${row.id}"`, 'primary'),
        VKTable.smallButton('Từ chối', `data-reject-quotation="${row.id}"`, 'danger'),
    ];
}

async function openQuotationModal() {
    const [customers, skus] = await Promise.all([VKApi.request('/customers'), VKApi.request('/skus')]);
    const encodedSkus = encodeURIComponent(JSON.stringify(skus.data || []));
    VKModal.open('Tạo báo giá', `
        <div class="form-grid">
            ${VKModal.select('customer_id', 'Khách hàng', customers.data.map(c => ({ value: c.id, label: `${c.code} - ${c.name}` })))}
            <div class="field full">
                <label>Dòng hàng báo giá</label>
                <div class="quotation-lines" data-quotation-lines>
                    ${quotationLineHtml(skus.data || [], 0)}
                </div>
                <button class="btn small" type="button" data-add-quotation-line data-skus="${encodedSkus}">Thêm dòng hàng</button>
            </div>
        </div>
    `, async (form) => {
        const data = Object.fromEntries(new FormData(form));
        const formData = new FormData(form);
        const skuIds = formData.getAll('sku_id[]');
        const quantities = formData.getAll('quantity[]');
        const prices = formData.getAll('unit_price[]');
        const vatRates = formData.getAll('vat_rate[]');
        const items = skuIds.map((skuId, index) => ({
            sku_id: Number(skuId),
            quantity: Number(quantities[index] || 0),
            unit_price: Number(prices[index] || 0),
            vat_rate: Number(vatRates[index] || 0),
        })).filter(item => item.sku_id && item.quantity > 0);

        await VKApi.request('/quotations', {
            method: 'POST',
            body: JSON.stringify({
                customer_id: Number(data.customer_id),
                items,
            }),
        });
        VKModal.toast('Đã tạo báo giá.');
        VKModal.close();
        loadQuotations();
    });
}

function quotationLineHtml(skus, index) {
    const options = skus.map(sku => `
        <option value="${sku.id}" data-price="${Number(sku.sale_price || 0)}" data-unit="${VKTable.escapeHtml(sku.unit || '-')}">${VKTable.escapeHtml(`${sku.sku_code} - ${sku.name}`)}</option>
    `).join('');
    const price = Number(skus[0]?.sale_price || 0);
    const unit = skus[0]?.unit || '-';

    return `
        <div class="quotation-line" data-quotation-line>
            <div class="field">
                <label>Mã hàng</label>
                <select name="sku_id[]" data-quotation-sku>${options}</select>
            </div>
            <div class="field quotation-unit-field">
                <label>ĐVT</label>
                <span data-quotation-unit>${VKTable.escapeHtml(unit)}</span>
            </div>
            <div class="field">
                <label>Số lượng</label>
                <input name="quantity[]" type="number" min="0.001" step="0.001" value="${index === 0 ? 1 : ''}">
            </div>
            <div class="field">
                <label>Đơn giá bán</label>
                <input name="unit_price[]" type="number" min="0" step="1000" value="${price}">
            </div>
            <div class="field">
                <label>VAT</label>
                <select name="vat_rate[]">
                    <option value="0">0%</option>
                    <option value="5">5%</option>
                    <option value="8" selected>8%</option>
                    <option value="10">10%</option>
                </select>
            </div>
            <button class="btn small danger" type="button" data-remove-quotation-line>Xóa</button>
        </div>
    `;
}

function addQuotationLine(encodedSkus) {
    const root = document.querySelector('[data-quotation-lines]');
    if (!root) return;
    const skus = JSON.parse(decodeURIComponent(encodedSkus));
    root.insertAdjacentHTML('beforeend', quotationLineHtml(skus, root.querySelectorAll('[data-quotation-line]').length));
}

function ensureQuotationLine() {
    const root = document.querySelector('[data-quotation-lines]');
    const add = document.querySelector('[data-add-quotation-line]');
    if (root && add && !root.querySelector('[data-quotation-line]')) addQuotationLine(add.dataset.skus || '[]');
}

async function approveQuotation(id, status) {
    await VKApi.request(`/quotations/${id}/approve`, {
        method: 'POST',
        body: JSON.stringify({ status, reason: status === 'approved' ? 'Duyệt từ màn hình báo giá' : 'Từ chối từ màn hình báo giá' }),
    });
    VKModal.toast(status === 'approved' ? 'Đã duyệt báo giá.' : 'Đã từ chối báo giá.');
    loadQuotations();
}

function openQuotationDetail(id) {
    VKDetailDrawer.open({ type: 'quotation', path: `/quotations/${id}` });
}
