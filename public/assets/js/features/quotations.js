(function () {
let quotationState = { rows: [], q: '', status: '', view: 'list', detail: null, detailTab: 'overview', detailCache: {} };
let quotationTemplateCache = null;
let customerSearchTimer = null;
let customerSearchResults = [];
let quotationCustomers = [];

document.addEventListener('vk:ready', async () => {
    await loadQuotations();
    const params = new URLSearchParams(location.search);
    const requested = params.get('open') || params.get('record');
    if (requested && /^\d+$/.test(requested)) await openQuotationDetail(requested);
    openQuotationFromDeal();
});
document.addEventListener('vk:flow-updated', () => loadQuotations());
document.addEventListener('input', (event) => {
    if (!document.getElementById('quotationsRoot')) return;
    if (event.target.matches('[data-list-search]')) {
        quotationState.q = event.target.value.toLowerCase();
        renderQuotations();
    }
    if (event.target.matches('[name="quantity[]"], [name="unit_price[]"]')) {
        updateQuotationDraftTotals();
    }
    if (event.target.matches('[name="discount_percent"]')) updateQuotationDraftTotals();
    if (event.target.matches('[data-quotation-customer-search]')) {
        searchQuotationCustomers(event.target.value);
    }
    if (event.target.matches('[data-quotation-sku-search]')) {
        searchQuotationSkus(event.target);
    }
});
document.addEventListener('change', (event) => {
    if (!document.getElementById('quotationsRoot')) return;
    if (event.target.matches('[data-list-status]')) {
        quotationState.status = event.target.value;
        renderQuotations();
    }
    if (event.target.matches('[name="price_book_id"]')) {
        const field = document.querySelector('[name="discount_percent"]');
        if (field) field.value = event.target.selectedOptions[0]?.dataset.discount || 0;
        updateQuotationDraftTotals();
    }
    if (event.target.matches('[name="vat_rate[]"]')) updateQuotationDraftTotals();
});
document.addEventListener('click', (event) => {
    if (!document.getElementById('quotationsRoot')) return;
    const workflowButton = event.target.closest('[data-quote-workflow]');
    if (workflowButton) { event.preventDefault(); openQuotationWorkflow(workflowButton.dataset.quoteWorkflow); return; }
    if (event.target.closest('[data-quote-revision]')) { openQuotationDetail(event.target.closest('[data-quote-revision]').dataset.quoteRevision); return; }
    if (event.target.closest('[data-quote-request-change]')) { approveQuotation(quotationState.detail.id, 'change_requested'); return; }
    if (event.target.closest('[data-quote-workflow-download]')) {
        downloadIssuedQuotation().catch(error => VKModal.toast(error.message, 'danger')); return;
    }
    if (event.target.matches('[data-create-quotation]')) openQuotationModal();
    if (event.target.matches('[data-delete-quotation]')) {
        event.preventDefault(); event.stopPropagation(); deleteQuotation(event.target.dataset.deleteQuotation);
    }
    if (event.target.matches('[data-edit-quotation]')) {
        event.preventDefault();
        event.stopPropagation();
        openQuotationEditor(event.target.dataset.editQuotation);
    }
    if (event.target.matches('[data-toggle-quick-customer]')) {
        event.preventDefault();
        const panel = document.querySelector('[data-quick-customer-panel]');
        panel?.classList.toggle('hidden');
        if (!panel?.classList.contains('hidden')) {
            document.getElementById('customer_id').value = '';
            updateQuotationCustomerSummary();
            panel.classList.remove('completed');
        }
    }
    if (event.target.matches('[data-save-quick-customer]')) {
        event.preventDefault();
        createQuickCustomer(event.target);
    }
    if (event.target.matches('[data-lookup-tax-code]')) {
        event.preventDefault();
        lookupQuickCustomerTaxCode();
    }
    const customerSuggestion = event.target.closest('[data-select-quotation-customer]');
    if (customerSuggestion) {
        event.preventDefault();
        selectQuotationCustomer(customerSearchResults[Number(customerSuggestion.dataset.selectQuotationCustomer)]);
    }
    const skuSuggestion = event.target.closest('[data-select-quotation-sku]');
    if (skuSuggestion) {
        event.preventDefault();
        selectQuotationSku(skuSuggestion.closest('[data-quotation-line]'), skuSuggestion.dataset.selectQuotationSku);
    }
    if (event.target.matches('[data-add-quotation-line]')) {
        event.preventDefault();
        addQuotationLine(event.target.dataset.skus || '[]');
    }
    if (event.target.matches('[data-remove-quotation-line]')) {
        event.preventDefault();
        event.target.closest('[data-quotation-line]')?.remove();
        ensureQuotationLine();
        updateQuotationDraftTotals();
    }
    if (event.target.matches('[data-approve-quotation]')) {
        event.stopPropagation();
        approveQuotation(event.target.dataset.approveQuotation, 'approved');
    }
    if (event.target.matches('[data-reject-quotation]')) {
        event.stopPropagation();
        approveQuotation(event.target.dataset.rejectQuotation, 'rejected');
    }
    if (event.target.matches('[data-back-quotation-list]')) {
        event.preventDefault();
        quotationState.view = 'list';
        quotationState.detail = null;
        renderQuotations();
    }
    if (event.target.matches('[data-quotation-page-tab]')) {
        event.preventDefault();
        quotationState.detailTab = event.target.dataset.quotationPageTab;
        renderQuotationDetailPage();
    }
    if (event.target.matches('[data-page-approve-quotation]')) {
        event.preventDefault();
        approveQuotation(event.target.dataset.pageApproveQuotation, 'approved');
    }
    if (event.target.matches('[data-page-reject-quotation]')) {
        event.preventDefault();
        approveQuotation(event.target.dataset.pageRejectQuotation, 'rejected');
    }
    if (event.target.matches('[data-duplicate-quotation]')) {
        event.preventDefault();
        event.stopPropagation();
        duplicateQuotation(event.target.dataset.duplicateQuotation);
    }
    if (event.target.matches('[data-page-create-sales-order]')) {
        event.preventDefault();
        createSalesOrderFromQuotation(event.target.dataset.pageCreateSalesOrder);
    }
    if (event.target.matches('[data-open-sales-order]')) {
        event.preventDefault();
        window.location.href = event.target.dataset.openSalesOrder;
    }
    if (event.target.matches('[data-quotation-preview]')) {
        event.preventDefault();
        openQuotationTemplate();
    }
    if (event.target.matches('[data-quotation-document-preview]')) {
        event.preventDefault();
        previewQuotationWord().catch(error => VKModal.toast(error.message, 'danger'));
    }
    if (event.target.matches('[data-quotation-template]')) {
        event.preventDefault();
        openQuotationTemplate();
    }
    if (event.target.matches('[data-quotation-word]')) {
        event.preventDefault();
        openQuotationPrintPicker('word');
    }
    if (event.target.matches('[data-quotation-email]')) {
        event.preventDefault();
        openQuotationEmail();
    }
    if (event.target.matches('[data-quotation-zalo]')) {
        event.preventDefault();
        openQuotationZalo();
    }
    const printTemplate = event.target.closest('[data-select-print-template]');
    if (printTemplate) {
        event.preventDefault();
        document.querySelector('[data-selected-print-template]')?.setAttribute('value', printTemplate.dataset.selectPrintTemplate);
        document.querySelectorAll('[data-select-print-template]').forEach(item => item.classList.remove('active'));
        printTemplate.classList.add('active');
    }
    if (event.target.closest('.row-action-menu')) return;
    const detail = event.target.closest('[data-quotation-detail], tr[data-row-detail]');
    if (detail) openQuotationDetail(detail.dataset.quotationDetail || detail.dataset.rowDetail);
});
document.addEventListener('change', (event) => {
    if (!document.getElementById('quotationsRoot')) return;
    if (event.target.matches('[data-quick-customer-tax-code]')) {
        lookupQuickCustomerTaxCode();
    }
    if (event.target.matches('#customer_id')) {
        updateQuotationCustomerSummary();
    }
    if (event.target.matches('[name="vat_rate[]"]')) {
        updateQuotationDraftTotals();
    }
});

async function loadQuotations(options = {}) {
    if (!document.getElementById('quotationsRoot')) return;
    if (options.source === 'pjax') {
        quotationState.view = 'list';
        quotationState.detail = null;
        quotationState.detailTab = 'overview';
    }
    const quotations = await VKApi.request('/quotations');
    quotationState.rows = quotations.data || [];
    if (quotationState.view === 'detail' && quotationState.detail?.id) {
        await openQuotationDetail(quotationState.detail.id);
        return;
    }
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
                { value: 'issued', label: 'Đã phát hành' },
                { value: 'accepted', label: 'Khách đồng ý' },
                { value: 'change_requested', label: 'Yêu cầu sửa' },
                { value: 'customer_rejected', label: 'Khách từ chối' },
                { value: 'superseded', label: 'Đã thay thế' },
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
        { label: 'Mã báo giá', render: row => renderQuotationCode(row.code) },
        { label: 'Thời gian tạo', render: row => formatDate(row.created_at) },
        { label: 'Khách hàng', render: row => VKTable.escapeHtml(row.customer?.name || '-') },
        { label: 'Nhân bản từ', render: row => renderDuplicatedFrom(row) },
        { label: 'Tổng tiền', render: row => VKTable.money(row.total_amount) },
        { label: 'Biên lợi nhuận', render: row => renderMargin(row.margin_percent) },
        { label: 'Dòng hàng', render: row => renderItems(row.items) },
        { label: 'Trạng thái', render: row => VKTable.statusBadge(row.status) },
        { label: '', render: row => VKTable.rowActions([
            VKTable.smallButton('Mở', `data-quotation-detail="${row.id}"`),
            canEditQuotation(row) ? VKTable.smallButton('Sửa', `data-edit-quotation="${row.id}"`, 'primary') : '',
            canDeleteQuotation(row) ? VKTable.smallButton('Xóa', `data-delete-quotation="${row.id}"`, 'danger') : '',
            window.VKLayout?.hasPermission?.('sales.quotation.create') ? VKTable.smallButton('Nhân bản', `data-duplicate-quotation="${row.id}"`) : '',
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

function renderDuplicatedFrom(row) {
    if (!row.duplicated_from_id) return '<span class="record-code-chip neutral">Tạo mới</span>';
    const source = row.duplicated_from || row.duplicatedFrom || {};
    return source.code
        ? renderQuotationCode(source.code, 'soft')
        : `<span class="record-code-chip soft">Báo giá #${VKTable.escapeHtml(row.duplicated_from_id)}</span>`;
}

function renderQuotationCode(code, tone = '') {
    const toneClass = tone ? ` ${tone}` : '';
    return `<span class="record-code-chip${toneClass}">${VKTable.escapeHtml(code || '-')}</span>`;
}

function quotationActions(row) {
    if (row.status !== 'pending_approval' || !window.VKLayout?.hasPermission('sales.margin.approve')) return [];
    if (Number(row.workflow_version) === 2 && !row.can_approve) return [];
    return [
        VKTable.smallButton('Duyệt', `data-approve-quotation="${row.id}"`, 'primary'),
        VKTable.smallButton('Từ chối', `data-reject-quotation="${row.id}"`, 'danger'),
    ];
}

function canEditQuotation(row) {
    if (Number(row.workflow_version) === 2 && row.status !== 'draft') return false;
    const canCreate = window.VKLayout?.hasPermission?.('sales.quotation.edit');
    const hasSalesOrder = (row.related_documents || row.sales_orders || []).some?.(item => item.type === 'salesOrder') || Number(row.sales_orders_count || 0) > 0;
    return Boolean(canCreate) && !hasSalesOrder && ['draft', 'pending_approval', 'ready', 'rejected'].includes(row.status);
}

function canDeleteQuotation(row) {
    return Boolean(window.VKLayout?.hasPermission?.('sales.quotation.delete')) && !row.revision_root_id && row.status === 'draft' && Number(row.sales_orders_count || 0) === 0;
}

async function deleteQuotation(id) {
    const row = quotationState.rows.find(item => String(item.id) === String(id)) || quotationState.detail;
    if (!window.confirm(`Xóa báo giá nháp ${row?.code || ''}? Thao tác này không thể hoàn tác.`)) return;
    try {
        await VKApi.request(`/quotations/${id}`, { method: 'DELETE' });
        quotationState.view = 'list'; quotationState.detail = null;
        await loadQuotations();
        VKModal.toast('Đã xóa báo giá nháp.');
    } catch (error) { VKModal.toast(error.message || 'Không xóa được báo giá.', 'danger'); }
}

async function openQuotationEditor(id) {
    const cached = quotationState.detail?.id === Number(id)
        ? quotationState.detail
        : quotationState.detailCache[id] || quotationState.rows.find(row => String(row.id) === String(id));
    let row = cached;
    if (!row?.items?.length || !row.customer) {
        const response = await VKApi.request(`/quotations/${id}`);
        row = response.data;
        quotationState.detailCache[id] = row;
    }
    if (!canEditQuotation(row)) {
        VKModal.toast('Báo giá này đã chốt hoặc đã tạo đơn bán nên không thể sửa.', 'warning');
        return;
    }
    openQuotationModal(row);
}

async function openQuotationModal(row = null, preset = {}) {
    const [customers, skus, masterData] = await Promise.all([VKApi.request('/customers?page_size=100'), VKApi.request('/skus?page_size=100'), VKApi.request('/sales-master-data')]);
    quotationCustomers = customers.data || [];
    const encodedSkus = encodeURIComponent(JSON.stringify(skus.data || []));
    const isEdit = Boolean(row?.id);
    VKModal.open(isEdit ? `Sửa báo giá ${row.code || ''}` : 'Tạo báo giá', `
        <div class="quotation-create">
            <section class="quotation-create-section quotation-customer-section">
                <div class="quotation-create-section-head">
                    <h3>Khách hàng</h3>
                    <button class="btn small" type="button" data-toggle-quick-customer>+ Thêm nhanh</button>
                </div>
                <div class="quotation-customer-card">
                    <div class="quotation-customer-search"><input type="search" data-quotation-customer-search autocomplete="off" placeholder="Tìm theo tên khách hàng hoặc mã số thuế..."><div class="quotation-customer-results hidden" data-quotation-customer-results></div><small>Tìm theo tên, mã khách, mã số thuế hoặc số điện thoại.</small></div>
                    <label class="quotation-customer-select">
                        <span class="quotation-customer-icon" aria-hidden="true">◎</span>
                        <select id="customer_id" name="customer_id">
                            <option value="">Chọn khách hàng</option>
                            ${quotationCustomers.map(customer => `
                                <option
                                    value="${customer.id}"
                                    ${String(customer.id) === String(row?.customer_id || row?.customer?.id || preset.customer_id || '') ? 'selected' : ''}
                                    data-code="${VKTable.escapeHtml(customer.code || '')}"
                                    data-name="${VKTable.escapeHtml(customer.name || '')}"
                                    data-contact="${VKTable.escapeHtml(customer.contact_name || '')}"
                                    data-phone="${VKTable.escapeHtml(customer.phone || '')}"
                                    data-email="${VKTable.escapeHtml(customer.email || '')}"
                                    data-billing-address="${VKTable.escapeHtml(customer.billing_address || '')}"
                                    data-address="${VKTable.escapeHtml(customer.address || '')}"
                                >${VKTable.escapeHtml(`${customer.code} - ${customer.name}`)}</option>
                            `).join('')}
                        </select>
                    </label>
                    <div class="quotation-customer-meta" data-quotation-customer-summary></div>
                </div>
                <div class="quick-customer-panel hidden" data-quick-customer-panel>
                    <section class="quick-customer-section">
                        <h4>Thông tin khách hàng</h4>
                        <div class="quick-customer-grid">
                            <div class="field">
                                <label for="quick_customer_contact">Tên khách hàng</label>
                                <input id="quick_customer_contact" type="text" data-quick-customer-contact placeholder="Người liên hệ">
                            </div>
                            <div class="field">
                                <label for="quick_customer_phone">Số điện thoại</label>
                                <input id="quick_customer_phone" type="tel" data-quick-customer-phone placeholder="VD: 0901234567">
                            </div>
                            <div class="field">
                                <label for="quick_customer_email">Email</label>
                                <input id="quick_customer_email" type="email" data-quick-customer-email>
                            </div>
                            <div class="field">
                                <label for="quick_customer_address">Địa chỉ</label>
                                <input id="quick_customer_address" type="text" data-quick-customer-address>
                            </div>
                        </div>
                    </section>
                    <section class="quick-customer-section">
                        <h4>Thông tin xuất hóa đơn</h4>
                        <div class="quick-customer-grid">
                            <div class="field quick-tax-code-field">
                                <label for="quick_customer_tax_code">Mã số thuế</label>
                                <div class="input-action">
                                    <input id="quick_customer_tax_code" type="text" data-quick-customer-tax-code placeholder="Nhập mã số thuế">
                                    <button class="btn small" type="button" data-lookup-tax-code>Tra cứu</button>
                                </div>
                            </div>
                            <div class="field">
                                <label for="quick_customer_name">Tên công ty</label>
                                <input id="quick_customer_name" type="text" data-quick-customer-name placeholder="Tên doanh nghiệp hoặc cá nhân">
                            </div>
                            <div class="field">
                                <label for="quick_customer_credit_limit">Hạn mức công nợ</label>
                                <input id="quick_customer_credit_limit" type="number" min="0" step="100000" data-quick-customer-credit-limit value="0">
                            </div>
                            <div class="field">
                                <label for="quick_customer_billing_address">Địa chỉ công ty</label>
                                <input id="quick_customer_billing_address" type="text" data-quick-customer-billing-address>
                            </div>
                        </div>
                    </section>
                    <div class="quick-customer-actions">
                        <span data-quick-customer-message>Mã khách hàng sẽ được tự động tạo.</span>
                        <button class="btn primary small" type="button" data-save-quick-customer>Lưu và chọn khách này</button>
                    </div>
                </div>
            </section>

            <input type="hidden" name="deal_id" value="${VKTable.escapeHtml(String(row?.deal_id || preset.deal_id || ''))}">
            ${preset.deal_code ? `<div class="quotation-linked-deal">Báo giá này thuộc cơ hội <strong>${VKTable.escapeHtml(preset.deal_code)}</strong>.</div>` : ''}
            <section class="quotation-create-section">
                <div class="quotation-create-section-head">
                    <h3>Điều khoản báo giá</h3>
                </div>
                <div class="form-grid two">
                    ${VKModal.field('valid_until', 'Hiệu lực đến', 'date', defaultQuotationValidUntil(row))}
                    ${VKModal.field('payment_terms', 'Thanh toán (bỏ trống để lấy từ khách hàng / điều khoản mặc định)', 'text', row?.payment_terms || quotationCustomers.find(customer => String(customer.id) === String(preset.customer_id))?.payment_terms || '')}
                    ${VKModal.select('payment_term_id', 'Điều khoản thanh toán', [{ value: '', label: 'Điều khoản mặc định' }, ...(masterData.data?.payment_terms || []).filter(item => item.is_active).map(item => ({ value: item.id, label: `${item.name}${item.is_default ? ' (mặc định)' : ''}` }))], row?.payment_term_id || (masterData.data?.payment_terms || []).find(item => item.is_active && item.is_default)?.id || '')}
                    ${VKModal.select('price_book_id', 'Chính sách giá', [{ value: '', label: 'Giá bán tiêu chuẩn' }, ...(masterData.data?.price_books || []).filter(item => item.is_active).map(item => ({ value: item.id, label: `${item.name} · giảm ${Number(item.discount_percent || 0).toFixed(0)}%` }))], row?.price_book_id || (masterData.data?.price_books || []).find(item => item.is_active && item.is_default)?.id || '')}
                    ${VKModal.field('discount_percent', 'Chiết khấu (%) — tự lấy theo chính sách giá', 'number', row?.discount_percent ?? (masterData.data?.price_books || []).find(item => item.is_active && item.is_default)?.discount_percent ?? 0)}
                    <div class="field full"><label for="note">Ghi chú báo giá</label><textarea id="note" name="note" rows="3">${VKTable.escapeHtml(row?.note || '')}</textarea></div>
                </div>
            </section>
            <section class="quotation-create-section quotation-lines-section">
                <div class="quotation-create-section-head">
                    <h3>Danh sách hàng hóa</h3>
                    <button class="btn small" type="button" data-add-quotation-line data-skus="${encodedSkus}">+ Thêm dòng hàng</button>
                </div>
                <div class="quotation-line-header" aria-hidden="true">
                    <span>#</span><span>Mã hàng</span><span>Tên hàng</span><span>ĐVT</span><span>Số lượng</span>
                    <span>Đơn giá</span><span>VAT (%)</span><span>Thành tiền</span><span></span>
                </div>
                <div class="quotation-lines quotation-create-lines" data-quotation-lines>
                    ${(row?.items?.length ? row.items : preset.items?.length ? preset.items : [null]).map((item, index) => quotationLineHtml(skus.data || [], index, item)).join('')}
                </div>
                <div class="quotation-create-bottom">
                    <div class="quotation-draft-summary">
                        <div><span>Tạm tính</span><strong data-quotation-subtotal>0</strong></div>
                        <div><span>Thuế VAT</span><strong data-quotation-tax>0</strong></div>
                        <div class="quotation-draft-total"><span>Tổng cộng</span><strong data-quotation-total>0</strong></div>
                    </div>
                </div>
            </section>
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

        if (!data.customer_id) { VKModal.toast('Vui lòng chọn khách hàng từ danh sách tìm kiếm.', 'warning'); return; }
        if (!data.customer_id) throw new Error('Vui lòng tìm và chọn một khách hàng trước khi lưu báo giá.');
        await VKApi.request(isEdit ? `/quotations/${row.id}` : '/quotations', {
            method: isEdit ? 'PUT' : 'POST',
            body: JSON.stringify({
                customer_id: Number(data.customer_id),
                deal_id: data.deal_id ? Number(data.deal_id) : null,
                price_book_id: data.price_book_id ? Number(data.price_book_id) : null,
                discount_percent: Number(data.discount_percent || 0),
                payment_term_id: data.payment_term_id ? Number(data.payment_term_id) : null,
                valid_until: data.valid_until || null,
                payment_terms: data.payment_terms || null,
                delivery_terms: null,
                note: data.note || null,
                items,
            }),
        });
        VKModal.close();
        VKModal.toast(isEdit ? 'Đã cập nhật báo giá.' : 'Đã tạo báo giá thành công.', 'success');
        quotationState.detailCache = {};
        if (isEdit) {
            await openQuotationDetail(row.id);
        } else {
            loadQuotations();
        }
    }, {
        className: 'quotation-create-modal',
        submitText: isEdit ? 'Lưu thay đổi' : 'Lưu báo giá',
    });
    document.querySelectorAll('[name="price_book_id"] option').forEach(option => { option.dataset.discount = (masterData.data?.price_books || []).find(item => String(item.id) === option.value)?.discount_percent || 0; });
    const discountField = document.querySelector('[name="discount_percent"]');
    if (discountField) { discountField.min = 0; discountField.max = 99.99; discountField.step = 0.01; }
    updateQuotationCustomerSummary();
    updateQuotationDraftTotals();
}

async function openQuotationFromDeal() {
    const dealId = new URLSearchParams(window.location.search).get('deal_id');
    if (!dealId || !/^[0-9]+$/.test(dealId)) return;
    try {
        const response = await VKApi.request(`/deals/${dealId}`);
        const deal = response.data;
        if (!deal.customer_id) {
            VKModal.toast('Cơ hội này chưa có khách hàng; hãy cập nhật khách hàng trước khi lập báo giá.', 'warning');
            return;
        }
        await openQuotationModal(null, { deal_id: deal.id, deal_code: deal.code, customer_id: deal.customer_id, items: deal.items || [] });
    } finally {
        window.history.replaceState({}, '', '/quotations');
    }
}

function customerOptionHtml(customer) {
    return `<option value="${customer.id}" data-code="${VKTable.escapeHtml(customer.code || '')}" data-name="${VKTable.escapeHtml(customer.name || '')}" data-contact="${VKTable.escapeHtml(customer.contact_name || '')}" data-phone="${VKTable.escapeHtml(customer.phone || '')}" data-email="${VKTable.escapeHtml(customer.email || '')}" data-billing-address="${VKTable.escapeHtml(customer.billing_address || '')}" data-address="${VKTable.escapeHtml(customer.address || '')}">${VKTable.escapeHtml(`${customer.code} - ${customer.name}${customer.tax_code ? ` · MST: ${customer.tax_code}` : ''}`)}</option>`;
}

function applyCustomerOption(select, customer) {
    let option = [...select.options].find(item => String(item.value) === String(customer.id));
    if (!option) { option = new Option(`${customer.code} - ${customer.name}`, customer.id); select.add(option, 1); }
    option.dataset.code = customer.code || ''; option.dataset.name = customer.name || ''; option.dataset.contact = customer.contact_name || ''; option.dataset.phone = customer.phone || ''; option.dataset.email = customer.email || ''; option.dataset.billingAddress = customer.billing_address || ''; option.dataset.address = customer.address || '';
    return option;
}

function selectQuotationCustomer(customer) {
    if (!customer) return;
    const select = document.getElementById('customer_id');
    applyCustomerOption(select, customer); select.value = String(customer.id);
    const search = document.querySelector('[data-quotation-customer-search]'); if (search) search.value = `${customer.code} - ${customer.name}`;
    const results = document.querySelector('[data-quotation-customer-results]'); if (results) { results.innerHTML = ''; results.classList.add('hidden'); }
    updateQuotationCustomerSummary();
}

function searchQuotationCustomers(query) {
    window.clearTimeout(customerSearchTimer);
    customerSearchTimer = window.setTimeout(() => {
        const results = document.querySelector('[data-quotation-customer-results]');
        if (!results) return;
        if (!query.trim()) { results.innerHTML = ''; results.classList.add('hidden'); return; }
        const keyword = query.trim().toLowerCase();
        customerSearchResults = quotationCustomers.filter(customer => `${customer.code || ''} ${customer.name || ''} ${customer.tax_code || ''} ${customer.phone || ''}`.toLowerCase().includes(keyword)).slice(0, 20);
        results.innerHTML = customerSearchResults.length ? customerSearchResults.map((customer, index) => `<button type="button" data-select-quotation-customer="${index}"><strong>${VKTable.escapeHtml(customer.name)}</strong><span>${VKTable.escapeHtml(`${customer.code}${customer.tax_code ? ` · MST: ${customer.tax_code}` : ''}${customer.phone ? ` · ${customer.phone}` : ''}`)}</span></button>`).join('') : '<div class="quotation-customer-no-result">Không tìm thấy khách phù hợp.</div>';
        results.classList.remove('hidden');
    }, 250);
}

async function createQuickCustomer(button) {
    const taxCode = document.querySelector('[data-quick-customer-tax-code]')?.value.trim() || '';
    const companyName = document.querySelector('[data-quick-customer-name]')?.value.trim() || '';
    const phone = document.querySelector('[data-quick-customer-phone]')?.value.trim() || '';
    const contactName = document.querySelector('[data-quick-customer-contact]')?.value.trim() || '';
    const email = document.querySelector('[data-quick-customer-email]')?.value.trim() || '';
    const billingAddress = document.querySelector('[data-quick-customer-billing-address]')?.value.trim() || '';
    const address = document.querySelector('[data-quick-customer-address]')?.value.trim() || '';
    const creditLimit = Number(document.querySelector('[data-quick-customer-credit-limit]')?.value || 0);
    const message = document.querySelector('[data-quick-customer-message]');

    const name = companyName || contactName;
    if (!name || !phone) {
        if (message) {
            message.textContent = 'Vui lòng nhập tên khách hàng hoặc tên công ty, kèm số điện thoại.';
            message.classList.add('error');
        }
        return;
    }

    button.disabled = true;
    if (message) {
        message.textContent = 'Đang kiểm tra và tạo khách hàng...';
        message.classList.remove('error', 'success');
    }

    try {
        const response = await VKApi.request('/customers/quick', {
            method: 'POST',
            body: JSON.stringify({
                tax_code: taxCode || null,
                name,
                phone,
                contact_name: contactName || null,
                email: email || null,
                billing_address: billingAddress || null,
                address: address || null,
                credit_limit: creditLimit,
            }),
        });
        const customer = response.data;
        const select = document.getElementById('customer_id');
        let option = [...select.options].find(item => String(item.value) === String(customer.id));
        if (!option) {
            option = new Option(`${customer.code} - ${customer.name}`, customer.id);
            option.dataset.code = customer.code || '';
            option.dataset.name = customer.name || '';
            option.dataset.contact = customer.contact_name || '';
            option.dataset.phone = customer.phone || '';
            option.dataset.email = customer.email || '';
            option.dataset.billingAddress = customer.billing_address || '';
            option.dataset.address = customer.address || '';
            select.add(option, 0);
        }
        select.value = String(customer.id);
        updateQuotationCustomerSummary();

        if (message) {
            message.textContent = response.meta?.duplicate_tax_code || response.meta?.duplicate_phone
                ? `Khách hàng đã tồn tại. Đã chọn ${customer.code} - ${customer.name}.`
                : `Đã tạo ${customer.code} và chọn vào báo giá.`;
            message.classList.remove('error');
            message.classList.add('success');
        }
        document.querySelector('[data-quick-customer-panel]')?.classList.add('completed');
    } catch (error) {
        if (message) {
            message.textContent = error.message || 'Không thể tạo khách hàng.';
            message.classList.remove('success');
            message.classList.add('error');
        }
    } finally {
        button.disabled = false;
    }
}

async function lookupQuickCustomerTaxCode() {
    const input = document.querySelector('[data-quick-customer-tax-code]');
    const message = document.querySelector('[data-quick-customer-message]');
    const taxCode = input?.value.trim() || '';
    if (!taxCode || input?.dataset.lookupBusy === '1') return;
    if (input.dataset.lookedUp === taxCode) return;

    input.dataset.lookupBusy = '1';
    if (message) {
        message.textContent = 'Đang tra cứu mã số thuế...';
        message.classList.remove('error', 'success');
    }

    try {
        const response = await VKApi.request(`/customers/tax-lookup/${encodeURIComponent(taxCode)}`);
        const data = response.data || {};
        fillQuickCustomerField('[data-quick-customer-name]', data.name);
        fillQuickCustomerField('[data-quick-customer-billing-address]', data.billing_address || data.address);
        fillQuickCustomerField('[data-quick-customer-address]', data.address);
        fillQuickCustomerField('[data-quick-customer-contact]', data.contact_name);
        fillQuickCustomerField('[data-quick-customer-phone]', data.phone);
        fillQuickCustomerField('[data-quick-customer-email]', data.email);
        input.dataset.lookedUp = taxCode;

        if (data.customer_id) {
            const select = document.getElementById('customer_id');
            let option = [...select.options].find(item => String(item.value) === String(data.customer_id));
            if (!option) {
                option = new Option(`${data.tax_code || taxCode} - ${data.name || 'Khách hàng'}`, data.customer_id);
                option.dataset.name = data.name || '';
                option.dataset.contact = data.contact_name || '';
                option.dataset.phone = data.phone || '';
                option.dataset.email = data.email || '';
                option.dataset.billingAddress = data.billing_address || data.address || '';
                option.dataset.address = data.address || '';
                select.add(option, 0);
            }
            select.value = String(data.customer_id);
            updateQuotationCustomerSummary();
        }

        if (message) {
            message.textContent = data.customer_id
                ? 'Khách hàng đã có trong hệ thống và đã được chọn.'
                : 'Đã điền thông tin doanh nghiệp từ mã số thuế.';
            message.classList.add('success');
        }
    } catch (error) {
        if (message) {
            message.textContent = 'Chưa tìm thấy dữ liệu. Bạn có thể nhập thông tin thủ công.';
            message.classList.add('error');
        }
    } finally {
        input.dataset.lookupBusy = '0';
    }
}

function fillQuickCustomerField(selector, value) {
    const field = document.querySelector(selector);
    if (field && value && !field.value.trim()) field.value = value;
}

function quotationLineHtml(skus, index, item = null) {
    const selectedSkuId = item?.sku_id || item?.sku?.id || skus[0]?.id;
    const selectedSku = skus.find(sku => String(sku.id) === String(selectedSkuId)) || skus[0] || {};
    const encodedSkus = encodeURIComponent(JSON.stringify(skus));
    const price = Number(item?.list_unit_price ?? item?.unit_price ?? selectedSku.sale_price ?? 0);
    const quantity = Number(item?.quantity ?? 1);
    const vatRate = Number(item?.vat_rate ?? 8);

    const unit = item?.unit || item?.sku?.unit || selectedSku.unit || '-';

    return `
        <div class="quotation-line quotation-create-line" data-quotation-line>
            <span class="quotation-line-number" data-quotation-line-number>${index + 1}</span>
            <div class="quotation-product-field quotation-sku-code-field" data-quotation-sku-options="${encodedSkus}">
                <input type="hidden" name="sku_id[]" value="${VKTable.escapeHtml(String(selectedSkuId || ''))}" data-quotation-sku-id>
                <input type="search" autocomplete="off" data-quotation-sku-search="code" value="${VKTable.escapeHtml(selectedSku.sku_code || '')}" placeholder="Nhập mã hàng...">
                <div class="quotation-sku-results hidden" data-quotation-sku-results></div>
            </div>
            <div class="quotation-product-field quotation-sku-name-field">
                <input type="search" autocomplete="off" data-quotation-sku-search="name" value="${VKTable.escapeHtml(selectedSku.name || '')}" placeholder="Nhập tên sản phẩm...">
                <div class="quotation-sku-results hidden" data-quotation-sku-results></div>
            </div>
            <div class="quotation-unit-field">
                <span data-quotation-unit>${VKTable.escapeHtml(unit)}</span>
            </div>
            <div class="quotation-quantity-control">
                <input name="quantity[]" type="number" min="1" step="1" value="${quantity}">
            </div>
            <input class="quotation-price-input" name="unit_price[]" type="number" min="0" step="1000" value="${price}">
            <select class="quotation-vat-select" name="vat_rate[]">
                ${[0, 5, 8, 10].map(value => `<option value="${value}" ${value === vatRate ? 'selected' : ''}>${value}%</option>`).join('')}
            </select>
            <strong class="quotation-line-total" data-quotation-line-total>0</strong>
            <button class="quotation-remove-line" type="button" data-remove-quotation-line aria-label="Xóa dòng">×</button>
        </div>
    `;
}

async function searchQuotationSkus(input) {
    const line = input.closest('[data-quotation-line]');
    const field = line?.querySelector('[data-quotation-sku-options]');
    const results = input.parentElement?.querySelector('[data-quotation-sku-results]');
    if (!field || !results) return;
    let skus = JSON.parse(decodeURIComponent(field.dataset.quotationSkuOptions || '[]'));
    const keyword = input.value.trim().toLowerCase();
    const searchByName = input.dataset.quotationSkuSearch === 'name';
    let matches = keyword
        ? skus.filter(sku => String(searchByName ? sku.name : sku.sku_code).toLowerCase().includes(keyword)).slice(0, 10)
        : skus.slice(0, 10);
    if (keyword.length >= 2) {
        try {
            const response = await VKApi.request(`/skus?q=${encodeURIComponent(keyword)}&page_size=20`, { cache: false });
            if (input.value.trim().toLowerCase() !== keyword) return;
            const remoteSkus = response.data || [];
            const known = new Map(skus.map(sku => [String(sku.id), sku]));
            remoteSkus.forEach(sku => known.set(String(sku.id), sku));
            skus = [...known.values()];
            field.dataset.quotationSkuOptions = encodeURIComponent(JSON.stringify(skus));
            matches = remoteSkus.slice(0, 10);
        } catch (error) {
            // Khi mạng tạm chậm vẫn cho chọn trong các sản phẩm đã tải sẵn.
        }
    }
    results.innerHTML = matches.length ? matches.map(sku => `
        <button type="button" data-select-quotation-sku="${VKTable.escapeHtml(String(sku.id))}">
            <strong>${VKTable.escapeHtml(sku.sku_code || '-')}</strong><span>${VKTable.escapeHtml(sku.name || '')} · ${VKTable.escapeHtml(sku.unit || '-')} · ${VKTable.money(sku.sale_price || 0)}</span>
        </button>`).join('') : '<div class="quotation-sku-no-result">Không tìm thấy sản phẩm phù hợp.</div>';
    results.classList.remove('hidden');
    if (!line) results.classList.add('hidden');
}

function selectQuotationSku(line, skuId) {
    const field = line?.querySelector('[data-quotation-sku-options]');
    if (!field) return;
    const skus = JSON.parse(decodeURIComponent(field.dataset.quotationSkuOptions || '[]'));
    const sku = skus.find(item => String(item.id) === String(skuId));
    if (!sku) return;
    const hidden = field.querySelector('[data-quotation-sku-id]');
    const inputs = line.querySelectorAll('[data-quotation-sku-search]');
    const results = line.querySelectorAll('[data-quotation-sku-results]');
    const price = line.querySelector('[name="unit_price[]"]');
    const unit = line.querySelector('[data-quotation-unit]');
    if (hidden) hidden.value = String(sku.id);
    inputs.forEach(input => { input.value = input.dataset.quotationSkuSearch === 'code' ? (sku.sku_code || '') : (sku.name || ''); });
    if (price) price.value = Number(sku.sale_price || 0);
    if (unit) unit.textContent = sku.unit || '-';
    results.forEach(result => { result.innerHTML = ''; result.classList.add('hidden'); });
    updateQuotationDraftTotals();
}

function addQuotationLine(encodedSkus) {
    const root = document.querySelector('[data-quotation-lines]');
    if (!root) return;
    const skus = JSON.parse(decodeURIComponent(encodedSkus));
    root.insertAdjacentHTML('beforeend', quotationLineHtml(skus, root.querySelectorAll('[data-quotation-line]').length));
    renumberQuotationLines();
    updateQuotationDraftTotals();
}

function ensureQuotationLine() {
    const root = document.querySelector('[data-quotation-lines]');
    const add = document.querySelector('[data-add-quotation-line]');
    if (root && add && !root.querySelector('[data-quotation-line]')) addQuotationLine(add.dataset.skus || '[]');
    renumberQuotationLines();
}

function renumberQuotationLines() {
    document.querySelectorAll('[data-quotation-line]').forEach((line, index) => {
        const number = line.querySelector('[data-quotation-line-number]');
        if (number) number.textContent = String(index + 1);
    });
}

function updateQuotationDraftTotals() {
    let subtotal = 0;
    let tax = 0;
    const discount = Number(document.querySelector('[name="discount_percent"]')?.value || 0);
    document.querySelectorAll('[data-quotation-line]').forEach(line => {
        const quantity = Number(line.querySelector('[name="quantity[]"]')?.value || 0);
        const listPrice = Number(line.querySelector('[name="unit_price[]"]')?.value || 0);
        const price = Math.round(listPrice * (100 - discount)) / 100;
        const vatRate = Number(line.querySelector('[name="vat_rate[]"]')?.value || 0);
        const lineSubtotal = quantity * price;
        const lineTax = lineSubtotal * vatRate / 100;
        const lineTotal = lineSubtotal + lineTax;
        subtotal += lineSubtotal;
        tax += lineTax;
        const totalNode = line.querySelector('[data-quotation-line-total]');
        if (totalNode) totalNode.textContent = formatDraftMoney(lineTotal);
    });
    setDraftMoney('[data-quotation-subtotal]', subtotal);
    setDraftMoney('[data-quotation-tax]', tax);
    setDraftMoney('[data-quotation-total]', subtotal + tax);
}

function updateQuotationCustomerSummary() {
    const option = document.getElementById('customer_id')?.selectedOptions?.[0];
    const root = document.querySelector('[data-quotation-customer-summary]');
    if (!option || !root) return;
    const contact = [option.dataset.contact, option.dataset.phone].filter(Boolean).join(' · ') || 'Chưa có thông tin liên hệ';
    root.innerHTML = `
        <span>${VKTable.escapeHtml(contact)}</span>
        <span>${VKTable.escapeHtml(option.dataset.email || 'Chưa có email')}</span>
        <span>${VKTable.escapeHtml(option.dataset.billingAddress || option.dataset.address || 'Chưa có địa chỉ hóa đơn')}</span>
    `;
}

function setDraftMoney(selector, value) {
    const node = document.querySelector(selector);
    if (node) node.textContent = formatDraftMoney(value);
}

function formatDraftMoney(value) {
    return `${VKTable.money(Math.round(Number(value || 0)))} đ`;
}

async function approveQuotation(id, status) {
    const label = status === 'approved' ? 'Duyệt' : status === 'change_requested' ? 'Yêu cầu sửa' : 'Từ chối';
    VKModal.open(`${label} báo giá`, `<p class="form-note">Quyết định được lưu vào lịch sử. Khi đủ lượt duyệt, báo giá mới được phát hành.</p><div class="field"><label>Lý do ${status === 'approved' ? '(tùy chọn)' : '*'}</label><textarea name="reason" ${status !== 'approved' ? 'required' : ''} maxlength="500"></textarea></div>`, async data => {
        const reason = String(new FormData(data).get('reason') || '').trim();
        await VKApi.request(`/quotations/${id}/approve`, {method:'POST', body:JSON.stringify({status,reason:reason || null})});
        VKModal.close(); quotationState.detailCache = {}; await loadQuotations(); await openQuotationDetail(id);
        VKModal.toast('Đã ghi nhận quyết định.');
    }, {submitText:label});
}

function openQuotationWorkflow(action) {
    const row = quotationState.detail;
    if (!row) return;
    let body = '', title = '', submit = 'Xác nhận';
    if (action === 'submit') {
        title = 'Gửi duyệt báo giá'; submit = 'Gửi duyệt';
        body = '<p>Nội dung và giá sẽ được khóa. Hệ thống tự lấy người duyệt từ thiết lập công ty; bạn không cần chọn lại.</p>';
    } else if (action === 'revise') {
        title = 'Tạo phiên bản mới'; submit = 'Tạo phiên bản';
        body = '<p>Giữ nguyên nội dung và lịch sử bản cũ. Bản mới là nháp, cần duyệt và phát hành lại. Bản cũ không còn dùng để chốt đơn.</p>';
    } else if (action === 'issue') {
        title = 'Ghi nhận phát hành báo giá'; submit = 'Lưu phát hành';
        body = `<p class="form-note">Lưu cố định file Word và mã kiểm tra. Đây là ghi nhận việc gửi bên ngoài, không tự gửi email/Zalo. Chỉ xác nhận sau khi đã chuyển báo giá cho khách.</p><div class="form-grid">${VKModal.field('recipient','Người nhận', 'text', row.customer?.email || row.customer?.contact_name || '')}${VKModal.select('channel','Kênh gửi',[{value:'email',label:'Email'},{value:'zalo',label:'Zalo'},{value:'direct',label:'Trực tiếp'},{value:'other',label:'Khác'}])}</div><div class="field"><label>Bằng chứng gửi *</label><textarea name="delivery_evidence" required maxlength="2000" placeholder="Thời gian gửi, người nhận, mã email hoặc đường dẫn chứng từ..."></textarea></div>`;
    } else {
        title = 'Ghi nhận phản hồi khách hàng'; submit = 'Lưu phản hồi';
        body = `${VKModal.select('decision','Kết quả',[{value:'accepted',label:'Khách đồng ý'},{value:'rejected',label:'Khách từ chối'}])}<div class="field"><label>Bằng chứng xác nhận *</label><textarea name="evidence" required maxlength="2000" placeholder="Email/tin nhắn, người xác nhận và thời gian, đường dẫn chứng từ..."></textarea></div>`;
    }
    VKModal.open(title, body, async data => {
        const payload = Object.fromEntries(new FormData(data));
        const response = await VKApi.request(`/quotations/${row.id}/workflow/${action}`, {method:'POST',body:JSON.stringify(payload)});
        VKModal.close(); quotationState.detailCache = {}; await loadQuotations(); await openQuotationDetail(response.data.id);
        VKModal.toast('Đã cập nhật luồng báo giá.');
    }, {submitText:submit,className:'modal-wide'});
}

async function downloadIssuedQuotation() {
    const row = quotationState.detail;
    const response = await fetch(`/api/v1/quotations/${row.id}/issued-file`, {headers:{Authorization:`Bearer ${VKApi.token()}`}});
    if (!response.ok) throw new Error(await response.json().then(data => data.message).catch(() => 'Không tải được bản phát hành.'));
    const url = URL.createObjectURL(await response.blob());
    const link = document.createElement('a'); link.href = url; link.download = `${row.code}-v${row.revision_number}.docx`; link.click();
    setTimeout(() => URL.revokeObjectURL(url), 1000);
}

function renderQuotationWorkflow(row) {
    if (Number(row.workflow_version) !== 2) return '<p class="form-note">Báo giá cũ: giữ luồng và lịch sử trước khi nâng cấp. Nhân bản để sử dụng quy trình mới.</p>';
    const stages = [['draft','Nháp'],['pending_approval','Duyệt nội bộ'],['approved','Đã duyệt'],['issued','Phát hành'],['accepted','Khách đồng ý']];
    const current = stages.findIndex(([key]) => key === row.status);
    return `<section class="quote-workflow-panel"><div class="quote-workflow-stages">${stages.map(([key,label],index) => `<div class="${index === current ? 'current' : index < current ? 'done' : ''}"><span>${index < current ? '✓' : index + 1}</span>${label}</div>`).join('')}</div><p class="form-note">Phiên bản ${row.revision_number || 1} · ${row.status === 'draft' ? 'Có thể chỉnh sửa. Gửi duyệt để khóa giá và nội dung.' : 'Nội dung đã khóa. Muốn thay đổi hãy tạo phiên bản mới.'}</p>${(row.approval_steps || []).length ? `<div class="quote-approval-steps">${row.approval_steps.map((step,index) => `<div><strong>${index + 1}. ${VKTable.escapeHtml(step.name)}</strong><span>${step.status === 'approved' ? '✓ Đã duyệt' : VKTable.translateStatus(step.status)}</span>${step.reason ? `<small>${VKTable.escapeHtml(step.reason)}</small>` : ''}</div>`).join('')}</div>` : ''}${row.issue ? `<div class="quote-issue-fields"><div><small>Người nhận</small><strong>${VKTable.escapeHtml(row.issue.recipient)}</strong></div><div><small>Phát hành</small><strong>${formatDate(row.issue.issued_at)}</strong></div><div><small>Kênh gửi</small><strong>${VKTable.escapeHtml(row.issue.channel)}</strong></div><div><small>Bằng chứng gửi</small><span>${VKTable.escapeHtml(row.issue.delivery_evidence)}</span></div>${row.issue.customer_evidence ? `<div><small>Bằng chứng phản hồi</small><span>${VKTable.escapeHtml(row.issue.customer_evidence)}</span></div>` : ''}</div>` : ''}</section>`;
}

async function duplicateQuotation(id) {
    const response = await VKApi.request(`/quotations/${id}/duplicate`, { method: 'POST' });
    const copy = response.data || {};
    quotationState.detailCache = {};
    VKModal.toast(`Đã nhân bản báo giá ${copy.code || ''}.`, 'success');
    await loadQuotations();
    if (copy.id) {
        await openQuotationDetail(copy.id);
    }
}

async function openQuotationDetail(id) {
    quotationState.view = 'detail';
    quotationState.detailTab = 'overview';
    const cached = quotationState.detailCache[id] || quotationState.rows.find(row => String(row.id) === String(id));
    if (cached) {
        quotationState.detail = normalizeQuotationPreview(cached);
        renderQuotationDetailPage();
    }

    try {
        const response = await VKApi.request(`/quotations/${id}`);
        quotationState.detailCache[id] = response.data;
        quotationState.detail = response.data;
        renderQuotationDetailPage();
    } catch (error) {
        VKModal.toast(error.message || 'Không tải được chi tiết báo giá.');
        if (!cached) {
            quotationState.view = 'list';
            quotationState.detail = null;
            renderQuotations();
        }
    }
}

function normalizeQuotationPreview(row) {
    return {
        ...row,
        customer: row.customer || {},
        items: row.items || [],
        tasks: row.tasks || [],
        alerts: row.alerts || [],
        timeline: row.timeline || [
            { label: 'Tạo báo giá', status: row.status || 'draft', at: row.created_at || row.updated_at },
            { label: 'Trạng thái hiện tại', status: row.status || 'draft', at: row.updated_at || row.created_at },
        ],
    };
}

function renderQuotationDetailLoading() {
    return `
        <section class="record-page">
            <div class="record-page-head">
                <button class="btn small" type="button" data-back-quotation-list>Quay lại</button>
                <div class="record-page-title">
                    <span>Báo giá</span>
                    <h2>Đang tải...</h2>
                </div>
            </div>
            <div class="pjax-skeleton">
                <div class="skeleton-line wide"></div>
                <div class="skeleton-table"><span></span><span></span><span></span><span></span></div>
            </div>
        </section>
    `;
}

function renderQuotationDetailPage() {
    const root = document.getElementById('quotationsRoot');
    const row = quotationState.detail;
    if (!root || !row) return;

    root.innerHTML = `
        <section class="record-page record-page-quotation quotation-detail-page quotation-detail-redesign">
            ${renderQuotationPageHeader(row)}
            ${renderQuotationPageTabs(row)}
            <div class="record-page-body ${quotationState.detailTab === 'overview' ? 'quote-form-body' : ''}">
                ${renderQuotationPageTabContent(row)}
            </div>
        </section>
    `;
}

function renderQuotationSplitList(activeId) {
    const rows = filterRows();

    return `
        <aside class="quote-split-list">
            <div class="quote-split-list-head">
                <div>
                    <strong>Danh sách báo giá</strong>
                    <span>${VKTable.money(rows.length)} bản ghi</span>
                </div>
                <button class="btn small" type="button" data-back-quotation-list>Đầy đủ</button>
            </div>
            <div class="quote-split-rows">
                ${rows.length ? rows.map(row => renderQuotationSplitRow(row, activeId)).join('') : `
                    <div class="empty compact">
                        <strong>Chưa có báo giá</strong>
                        <span>Dữ liệu phù hợp sẽ hiển thị ở đây.</span>
                    </div>
                `}
            </div>
        </aside>
    `;
}

function renderQuotationSplitRow(row, activeId) {
    const active = String(row.id) === String(activeId) ? 'active' : '';
    const customer = row.customer?.name || 'Chưa có khách hàng';

    return `
        <button class="quote-split-row ${active}" type="button" data-quotation-detail="${row.id}">
            <span>
                <strong>${VKTable.escapeHtml(row.code || '-')}</strong>
                <small>${VKTable.escapeHtml(customer)}</small>
            </span>
            <em>${VKTable.money(row.total_amount || 0)}</em>
        </button>
    `;
}

function renderQuotationSplitHeader(row) {
    const customer = row.customer || {};
    const contact = [customer.contact_name, customer.phone, customer.email].filter(Boolean).join(' · ');

    return `
        <div class="record-page-head quote-split-detail-head">
            <div class="record-page-title">
                <span>Báo giá</span>
                <h2>${VKTable.escapeHtml(row.code || '-')} ${VKTable.statusBadge(row.status || 'draft')}</h2>
                <p><strong>${VKTable.escapeHtml(customer.name || 'Chưa có khách hàng')}</strong>${contact ? ` · ${VKTable.escapeHtml(contact)}` : ''}</p>
            </div>
            <div class="record-page-meta">
                <div><span>Ngày tạo</span><strong>${formatDate(row.created_at)}</strong></div>
                <div><span>Cập nhật</span><strong>${formatDate(row.updated_at)}</strong></div>
            </div>
            <div class="record-page-actions">
                ${renderQuotationPageActions(row)}
            </div>
        </div>
    `;
}

function renderQuotationPageHeader(row) {
    const customer = row.customer || {};
    const contact = [customer.contact_name, customer.phone, customer.email].filter(Boolean).join(' · ');

    return `
        <header class="quotation-hero">
            <div class="quotation-hero-tools">
                <button class="btn small" type="button" data-back-quotation-list>← Danh sách báo giá</button>
                <div class="record-page-actions">${renderQuotationPageActions(row)}</div>
            </div>
            <div class="quotation-hero-content">
                <div class="quotation-hero-title">
                    <span>Báo giá</span>
                    <h2>${VKTable.escapeHtml(row.code || '-')} ${VKTable.statusBadge(row.status || 'draft')}</h2>
                    <p><strong>${VKTable.escapeHtml(customer.name || 'Chưa có khách hàng')}</strong>${contact ? ` · ${VKTable.escapeHtml(contact)}` : ''}</p>
                </div>
                <div class="quotation-hero-meta">
                    <div><span>Ngày tạo</span><strong>${formatDate(row.created_at)}</strong></div>
                    <div><span>Cập nhật</span><strong>${formatDate(row.updated_at)}</strong></div>
                </div>
            </div>
        </header>
    `;
}

function renderQuotationPageActions(row) {
    const can = window.VKLayout?.hasPermission || (() => false);
    const salesOrder = (row.related_documents || []).find(item => item.type === 'salesOrder');
    const actions = [
        `<button class="btn small" type="button" data-quotation-document-preview>Xem trước</button>`,
        `<button class="btn small" type="button" data-quotation-preview>Xuất file</button>`,
    ].filter(Boolean);
    if (canEditQuotation(row)) {
        actions.unshift(`<button class="btn small" type="button" data-edit-quotation="${row.id}">Sửa</button>`);
    }
    if (canDeleteQuotation(row)) actions.push(`<button class="btn danger small" type="button" data-delete-quotation="${row.id}">Xóa</button>`);
    if (Number(row.workflow_version) === 2) {
        if (row.status === 'draft' && can('sales.quotation.edit')) actions.push('<button class="btn primary small" data-quote-workflow="submit">Gửi duyệt</button>');
        if (row.status === 'approved' && can('sales.quotation.edit')) actions.push('<button class="btn primary small" data-quote-workflow="issue">Ghi nhận phát hành</button>');
        if (row.status === 'issued' && can('sales.quotation.edit')) actions.push('<button class="btn primary small" data-quote-workflow="customer-response">Phản hồi khách</button>');
        if (row.status !== 'draft' && row.status !== 'superseded' && !salesOrder && can('sales.quotation.edit')) actions.push('<button class="btn small" data-quote-workflow="revise">Phiên bản mới</button>');
        if (row.issue) actions.push('<button class="btn small" data-quote-workflow-download>Bản đã phát hành</button>');
    }
    if (row.status === 'pending_approval' && can('sales.margin.approve') && (Number(row.workflow_version) !== 2 || row.can_approve)) {
        actions.push(`<button class="btn primary small" type="button" data-page-approve-quotation="${row.id}">Duyệt</button>`);
        actions.push(`<button class="btn danger small" type="button" data-page-reject-quotation="${row.id}">Từ chối</button>`);
        if (Number(row.workflow_version) === 2) actions.push('<button class="btn small" data-quote-request-change>Yêu cầu sửa</button>');
    }
    if (salesOrder?.path) {
        const orderId = String(salesOrder.path || '').split('/').filter(Boolean).pop();
        actions.push(`<button class="btn primary small" type="button" data-open-sales-order="/sales-orders?open=${VKTable.escapeHtml(orderId || '')}">Mở đơn hàng</button>`);
    } else if ((Number(row.workflow_version) === 2 ? row.status === 'accepted' : ['ready', 'approved'].includes(row.status)) && can('sales.order.create')) {
        actions.push(`<button class="btn primary small" type="button" data-page-create-sales-order="${row.id}">Tạo đơn hàng</button>`);
    }
    return actions.join('');
}

function renderQuotationPageTabs(row) {
    const tabs = [
        ['overview', 'Tổng quan'],
        ['items', `Dòng hàng (${row.items?.length || 0})`],
        ['history', 'Lịch sử'],
        ['approval', 'Thanh toán & duyệt'],
        ['notes', 'Ghi chú'],
    ];

    return `
        <div class="record-tabs">
            ${tabs.map(([key, label]) => `
                <button class="${quotationState.detailTab === key ? 'active' : ''}" type="button" data-quotation-page-tab="${key}">${label}</button>
            `).join('')}
        </div>
    `;
}

function renderQuotationPageTabContent(row) {
    if (quotationState.detailTab === 'items') return renderQuotationPageItems(row.items || [], false, row);
    if (quotationState.detailTab === 'history') return `<section class="quote-workflow-panel"><h3>Các phiên bản báo giá</h3>${(row.revisions || []).map(item => `<button class="btn small" data-quote-revision="${item.id}">${VKTable.escapeHtml(item.code)} · v${item.revision_number} · ${VKTable.translateStatus(item.status)}</button>`).join(' ')}</section>${renderQuotationTimeline(row.timeline || [])}`;
    if (quotationState.detailTab === 'approval') return renderQuotationApprovalPayment(row);
    if (quotationState.detailTab === 'notes') return renderQuotationNotes();

    return `
        ${row.is_expired ? '<p class="form-note quote-expired-note">Báo giá đã hết hiệu lực. Hãy tạo phiên bản mới trước khi tiếp tục chốt đơn.</p>' : ''}
        ${renderQuotationWorkflow(row)}
        <div class="quote-overview-layout">
            ${renderQuotationQuickInfo(row)}
            ${renderQuotationPageItems(row.items || [], true)}
            ${renderQuotationCompactTotals(row)}
        </div>
    `;
}

function renderQuotationApprovalPayment(row) {
    const customer = row.customer || {};
    const approval = row.approval || {};
    const approvalText = approval.status
        ? `${VKTable.translateStatus(approval.status)}${approval.reason ? ` - ${approval.reason}` : ''}`
        : (Number(row.workflow_version) === 2 ? 'Chưa gửi duyệt' : (Number(row.margin_percent || 0) < 15 ? 'Cần duyệt biên lợi nhuận' : 'Không cần duyệt thêm'));
    const fields = [
        ['Hạn mức công nợ', VKTable.money(customer.credit_limit || 0)],
        ['Phương thức thanh toán', row.payment_terms || 'Chưa khai báo'],
        ['Người phụ trách', row.sales_owner?.name || row.salesOwner?.name || '-'],
        ['Biên lợi nhuận', `${Number(row.margin_percent || 0).toFixed(2)}%`],
        ['Duyệt báo giá', approvalText],
        ['Ghi chú', row.note || 'Chưa có ghi chú'],
    ];

    return `
        ${renderQuotationWorkflow(row)}
        <section class="record-panel">
            <h3>Thanh toán & duyệt</h3>
            <div class="quotation-approval-grid">
                ${fields.map(([label, value]) => `
                    <div>
                        <span>${VKTable.escapeHtml(label)}</span>
                        <strong>${VKTable.escapeHtml(value)}</strong>
                    </div>
                `).join('')}
            </div>
        </section>
    `;
}

function renderQuotationPageFinance(row) {
    const subtotal = Number(row.subtotal_amount || row.total_amount || 0);
    const tax = Number(row.tax_amount || 0);
    const amount = Number(row.total_amount || subtotal + tax);
    const cost = Number(row.total_cost || 0);
    const profit = subtotal - cost;
    const margin = Number(row.margin_percent || 0);

    return `
        <div class="record-metrics">
            ${metricBox('Tiền trước thuế', VKTable.money(subtotal), 'Giá trị hàng hóa')}
            ${metricBox('Thuế VAT', VKTable.money(tax), 'Tổng thuế các dòng')}
            ${metricBox('Tổng thanh toán', VKTable.money(amount), 'Đã gồm VAT', 'blue')}
            ${metricBox('Giá vốn', VKTable.money(cost), 'Tổng chi phí hàng')}
            ${metricBox('Lợi nhuận gộp', VKTable.money(profit), profit >= 0 ? 'Dương' : 'Âm', profit >= 0 ? 'green' : 'orange')}
            ${metricBox('Biên lợi nhuận', `${margin.toFixed(2)}%`, margin < 15 ? 'Cần duyệt' : 'Đạt ngưỡng', margin < 15 ? 'orange' : 'green')}
        </div>
    `;
}

function metricBox(label, value, note, tone = '') {
    return `
        <div class="record-metric ${tone}">
            <span>${VKTable.escapeHtml(label)}</span>
            <strong>${VKTable.escapeHtml(value)}</strong>
            <small>${VKTable.escapeHtml(note)}</small>
        </div>
    `;
}

function renderQuotationPageInfo(row) {
    const approval = row.approval || {};
    const approvalText = approval.status
        ? `${VKTable.translateStatus(approval.status)}${approval.reason ? ` - ${approval.reason}` : ''}`
        : (Number(row.margin_percent || 0) < 15 ? 'Cần duyệt biên lợi nhuận' : 'Không cần duyệt thêm');
    const fields = [
        ['Mã báo giá', row.code || '-'],
        ['Khách hàng', row.customer?.name || '-'],
        ['Mã khách hàng', row.customer?.code || '-'],
        ['Người phụ trách', row.sales_owner?.name || row.salesOwner?.name || '-'],
        ['Trạng thái', VKTable.statusBadge(row.status || 'draft'), true],
        ['Duyệt biên lợi nhuận', approvalText],
    ];

    return `
        <section class="record-panel">
            <h3>Thông tin chi tiết</h3>
            <div class="record-info-list">
                ${fields.map(([label, value, html]) => `
                    <div>
                        <span>${VKTable.escapeHtml(label)}</span>
                        <strong>${html ? value : VKTable.escapeHtml(value)}</strong>
                    </div>
                `).join('')}
            </div>
        </section>
    `;
}

function renderQuotationQuickInfo(row) {
    const customer = row.customer || {};
    const contact = [customer.contact_name, customer.phone].filter(Boolean).join(' - ') || customer.email || '-';
    const customerFields = [
        ['Tên khách hàng', customer.name || '-'],
        ['Người liên hệ', contact],
        ['SĐT', customer.phone || 'Chưa khai báo'],
        ['Mã khách hàng', customer.code || '-'],
    ];
    const invoiceFields = [
        ['Tên công ty', customer.name || 'Chưa khai báo'],
        ['Mã số thuế', customer.tax_code || 'Chưa khai báo'],
        ['Địa chỉ', customer.billing_address || customer.address || 'Chưa khai báo'],
    ];

    return `
        <div class="quote-detail-cards">
            ${renderQuotationDetailCard('Thông tin khách hàng', 'user', customerFields)}
            ${renderQuotationDetailCard('Thông tin xuất hóa đơn', 'doc', invoiceFields)}
        </div>
    `;
}

function renderQuotationDetailCard(title, icon, fields) {
    return `
        <section class="quote-detail-card">
            <h3><i class="quote-info-icon ${icon}" aria-hidden="true"></i>${VKTable.escapeHtml(title)}</h3>
            <div class="quote-detail-list">
                ${fields.map(([label, value]) => `
                    <div>
                        <span>${VKTable.escapeHtml(label)}</span>
                        <strong>${VKTable.escapeHtml(value)}</strong>
                    </div>
                `).join('')}
            </div>
            <button class="quote-more-info" type="button">Xem thêm thông tin</button>
        </section>
    `;
}

function renderQuotationInfoPanel(title, groups) {
    return `
        <div class="quote-info-panel">
            <h3>${VKTable.escapeHtml(title)}</h3>
            ${groups.map(([groupTitle, fields]) => `
                <div class="quote-info-group">
                    <h4>${VKTable.escapeHtml(groupTitle)}</h4>
                    <div class="quote-quick-list">
                        ${fields.map(([icon, label, value, html, highlight]) => `
                            <div class="${highlight ? 'quote-total-row' : ''}">
                                <i class="quote-info-icon ${icon}" aria-hidden="true"></i>
                                <span>${VKTable.escapeHtml(label)}</span>
                                <strong>${html ? value : VKTable.escapeHtml(value)}</strong>
                            </div>
                        `).join('')}
                    </div>
                </div>
            `).join('')}
        </div>
    `;
}

function renderQuotationPageFlow(row) {
    return `
        <section class="record-panel">
            <h3>Luồng xử lý</h3>
            ${renderQuotationTimeline(row.timeline || [])}
        </section>
    `;
}

function renderQuotationTimeline(rows) {
    if (!rows.length) return `<div class="empty compact"><strong>Chưa có lịch sử</strong><span>Lịch sử xử lý sẽ hiển thị tại đây.</span></div>`;

    return `
        <div class="record-timeline">
            ${rows.map(item => `
                <div class="${timelineTone(item.status)}">
                    <i></i>
                    <span>
                        <strong>${VKTable.escapeHtml(item.label || '-')}</strong>
                        <small>${formatDate(item.at)}</small>
                    </span>
                    <em>${VKTable.escapeHtml(VKTable.translateStatus(item.status || '-'))}</em>
                </div>
            `).join('')}
        </div>
    `;
}

function renderQuotationPageItems(items, compact = false, quotation = null) {
    const rows = items.map((item, index) => ({ ...item, _index: index + 1 }));
    const columns = compact ? [
        { label: 'Mã hàng', render: item => `<span class="mono">${VKTable.escapeHtml(item.sku?.sku_code || '-')}</span>` },
        { label: 'Tên hàng', render: item => VKTable.escapeHtml(item.name || item.sku?.name || '-') },
        { label: 'SL', render: item => `${VKTable.money(item.quantity)} ${VKTable.escapeHtml(item.unit || item.sku?.unit || '')}` },
        { label: 'Đơn giá', render: item => VKTable.money(item.unit_price) },
        { label: 'Thành tiền', render: item => VKTable.money(item.line_total) },
    ] : [
        { label: 'Mã hàng', render: item => `<span class="mono">${VKTable.escapeHtml(item.sku?.sku_code || '-')}</span>` },
        { label: 'Tên hàng', render: item => VKTable.escapeHtml(item.name || item.sku?.name || '-') },
        { label: 'ĐVT', render: item => VKTable.escapeHtml(item.unit || item.sku?.unit || '-') },
        { label: 'Số lượng', render: item => VKTable.money(item.quantity) },
        { label: 'Đơn giá', render: item => VKTable.money(item.unit_price) },
        { label: 'VAT', render: item => `${Number(item.vat_rate || 0).toFixed(0)}%` },
        { label: 'Thành tiền', render: item => VKTable.money(item.line_total) },
    ];

    return `
        <section class="record-panel ${compact ? 'quote-lines-panel' : ''}">
            <div class="quote-panel-head">
                <h3>Dòng hàng (${items.length})</h3>
            </div>
            ${renderQuotationItemsTable(columns, rows, quotation)}
        </section>
    `;
}

function renderQuotationCompactTotals(row) {
    const subtotal = Number(row.subtotal_amount || row.total_amount || 0);
    const tax = Number(row.tax_amount || 0);
    const amount = Number(row.total_amount || subtotal + tax);
    const discount = Number(row.discount_amount || 0);
    const discountPercent = subtotal > 0 ? (discount / subtotal) * 100 : 0;
    return `<aside class="quote-compact-total">
        <div><span>Tạm tính</span><strong>${VKTable.money(subtotal)}</strong></div>
        <div><span>Chiết khấu (${discountPercent.toFixed(0)}%)</span><strong>${VKTable.money(discount)}</strong></div>
        <div><span>VAT</span><strong>${VKTable.money(tax)}</strong></div>
        <div class="quote-compact-grand"><span>Tổng cộng</span><strong>${VKTable.money(amount)}</strong><em>VND</em></div>
    </aside>`;
}

function renderQuotationItemsTable(columns, rows, quotation) {
    if (!rows.length) {
        return `<div class="empty"><strong>Chưa có dòng hàng</strong><span>Dữ liệu mới sẽ hiển thị tại đây.</span></div>`;
    }

    return `
        <div class="table-wrap list-table-wrap quote-items-table">
            <table class="list-table">
                <thead>
                    <tr>${columns.map(column => `<th>${VKTable.escapeHtml(column.label)}</th>`).join('')}</tr>
                </thead>
                <tbody>
                    ${rows.map(row => `
                        <tr>${columns.map(column => `<td>${column.render(row)}</td>`).join('')}</tr>
                    `).join('')}
                </tbody>
                ${quotation ? renderQuotationTableFooter(quotation, columns.length) : ''}
            </table>
        </div>
    `;
}

function renderQuotationTableFooter(row, columnCount) {
    const subtotal = Number(row.subtotal_amount || row.total_amount || 0);
    const tax = Number(row.tax_amount || 0);
    const amount = Number(row.total_amount || subtotal + tax);
    const discount = Number(row.discount_amount || 0);
    const discountPercent = subtotal > 0 ? (discount / subtotal) * 100 : 0;

    return `
        <tfoot class="quote-table-footer">
            <tr>
                <td colspan="${columnCount - 1}">Tạm tính</td>
                <td>${VKTable.money(subtotal)}</td>
            </tr>
            <tr>
                <td colspan="${columnCount - 1}">Chiết khấu (${discountPercent.toFixed(2)}%)</td>
                <td>${VKTable.money(discount)}</td>
            </tr>
            <tr>
                <td colspan="${columnCount - 1}">VAT</td>
                <td>${VKTable.money(tax)}</td>
            </tr>
            <tr class="quote-table-total">
                <td colspan="${columnCount - 1}">Tổng cộng</td>
                <td>${VKTable.money(amount)} <small>VND</small></td>
            </tr>
        </tfoot>
    `;
}

function renderQuotationPageTotals(row) {
    const subtotal = Number(row.subtotal_amount || row.total_amount || 0);
    const tax = Number(row.tax_amount || 0);
    const amount = Number(row.total_amount || subtotal + tax);
    const cost = Number(row.total_cost || 0);
    const profit = subtotal - cost;

    return `
        <section class="record-total-bar">
            <div><span>Tiền trước thuế</span><strong>${VKTable.money(subtotal)}</strong></div>
            <div><span>Thuế VAT</span><strong>${VKTable.money(tax)}</strong></div>
            <div><span>Giá vốn</span><strong>${VKTable.money(cost)}</strong></div>
            <div><span>Lợi nhuận gộp</span><strong>${VKTable.money(profit)}</strong></div>
            <div class="grand"><span>Tổng cộng</span><strong>${VKTable.money(amount)}</strong><small>VND</small></div>
        </section>
    `;
}

function renderQuotationRelated(title, rows, primaryKey) {
    if (!rows.length) return `<section class="record-panel"><h3>${VKTable.escapeHtml(title)}</h3><div class="empty compact"><strong>Chưa có dữ liệu</strong><span>Nội dung liên quan sẽ hiển thị tại đây.</span></div></section>`;
    return `
        <section class="record-panel">
            <h3>${VKTable.escapeHtml(title)}</h3>
            <div class="record-related">
                ${rows.map(row => `
                    <div>
                        <strong>${VKTable.escapeHtml(row[primaryKey] || row.code || row.title || '-')}</strong>
                        <span>${VKTable.escapeHtml(row.status ? VKTable.translateStatus(row.status) : '')}</span>
                    </div>
                `).join('')}
            </div>
        </section>
    `;
}

function renderQuotationNotes() {
    return `
        <section class="record-panel">
            <h3>Ghi chú</h3>
            <div class="empty compact">
                <strong>Chưa có ghi chú</strong>
                <span>Khi bổ sung chức năng ghi chú, nội dung sẽ hiển thị ở đây.</span>
            </div>
        </section>
    `;
}

async function createSalesOrderFromQuotation(id) {
    const response = await VKApi.request('/sales-orders', {
        method: 'POST',
        body: JSON.stringify({ quotation_id: Number(id) }),
    });
    VKModal.toast('Đã tạo đơn bán từ báo giá.');
    if (response.data?.id) {
        window.location.href = `/sales-orders?open=${response.data.id}`;
        return;
    }
    await openQuotationDetail(id);
}

async function openQuotationTemplate() {
    const row = quotationState.detail;
    if (!row) return;
    let template = null;
    try {
        const response = await fetch('/api/v1/print-templates/active?module=quotation', {
            headers: { Accept: 'application/json', Authorization: `Bearer ${VKApi.token()}` },
        });
        if (response.ok) {
            template = (await response.json()).data || null;
        }
    } catch (error) {
        template = null;
    }
    if (template?.id && template.file_path && /\.docx$/i.test(template.file_name || '')) {
        quotationTemplateCache = template;
        await downloadQuotationWord(template);
        return;
    }
    VKModal.notice({
        type: 'warning',
        title: 'Chưa có mẫu báo giá để xuất',
        message: 'Hệ thống chỉ tải file Word theo mẫu báo giá đang đặt mặc định, không tự mở bản in mặc định nữa.',
        details: ['Vào Mẫu in → chọn Báo giá.', 'Tải lên file .docx và bật “Mặc định”, sau đó bấm Xuất file lại.'],
    });
}

async function openQuotationPrintPicker(mode = 'print') {
    const row = quotationState.detail;
    if (!row) return;

    let templates;
    try {
        templates = await getQuotationTemplateChoices();
    } catch (error) {
        VKModal.toast(error.message || 'Không tải được danh sách mẫu in.', 'danger');
        return;
    }

    const selected = templates.find(item => item.is_default) || templates[0] || null;

    VKModal.open(mode === 'word' ? 'Chọn mẫu xuất Word' : 'Chọn mẫu xuất PDF', `
        <div class="quotation-template-picker">
            <input type="hidden" name="template_id" value="${VKTable.escapeHtml(String(selected?.id ?? 'default'))}" data-selected-print-template>
            <div class="quotation-template-picker-list">
                ${templates.map(template => renderQuotationTemplateChoice(template, String(selected?.id ?? 'default'))).join('')}
            </div>
            <p>${mode === 'word' ? 'Hệ thống sẽ trộn dữ liệu vào file Word mẫu đã chọn.' : 'PDF được mở từ bản xem trước; tại cửa sổ in chọn “Lưu dưới dạng PDF”.'}</p>
        </div>
    `, async (form) => {
        const data = Object.fromEntries(new FormData(form));
        const template = templates.find(item => String(item.id ?? 'default') === String(data.template_id)) || selected;
        VKModal.close();
        try {
            if (mode === 'word') {
                await downloadQuotationWord(template);
                return;
            }
            await openQuotationPrint(template);
        } catch (error) {
            VKModal.toast(error.message || 'Không thể xuất báo giá lúc này.', 'danger');
        }
    }, {
        submitText: mode === 'word' ? 'Tải Word' : 'Mở bản in PDF',
        className: 'modal-wide',
    });
}

function renderQuotationTemplateChoice(template, selectedValue) {
    const value = String(template.id ?? 'default');
    const active = value === selectedValue ? 'active' : '';
    return `
        <button class="quotation-template-choice ${active}" type="button" data-select-print-template="${VKTable.escapeHtml(value)}">
            <span>
                <strong>${VKTable.escapeHtml(template.name || template.code || 'Mẫu báo giá')}</strong>
                <small>${VKTable.escapeHtml(template.code || 'Mẫu hệ thống')}${template.file_name ? ` · ${VKTable.escapeHtml(template.file_name)}` : ''}</small>
            </span>
            ${template.is_default ? '<em>Mặc định</em>' : ''}
        </button>
    `;
}

async function openQuotationPrint(template = null) {
    const row = quotationState.detail;
    if (!row) return;

    template = template || await getActiveQuotationTemplate();
    if (template?.id && template.file_path && /\.docx$/i.test(template.file_name || '')) {
        await downloadQuotationDocx(row, template);
        VKModal.toast('Mẫu đã chọn là file Word, hệ thống đã tải Word theo mẫu in.', 'success');
        return;
    }

    const popup = window.open('', '_blank', 'width=980,height=720');
    if (!popup) {
        VKModal.toast('Trình duyệt đang chặn cửa sổ in. Vui lòng cho phép popup để xuất PDF.', 'warning');
        return;
    }

    popup.document.open();
    popup.document.write('<p style="font-family:Arial;padding:24px">Đang tải mẫu in...</p>');
    popup.document.close();

    popup.document.open();
    popup.document.write(quotationDocumentHtml(row, { autoPrint: true, template }));
    popup.document.close();
}

async function downloadQuotationWord(template = null) {
    const row = quotationState.detail;
    if (!row) return;

    template = template || await getActiveQuotationTemplate();
    await downloadQuotationDocx(row, template);
}

async function previewQuotationWord() {
    const row = quotationState.detail;
    if (!row) return;
    const template = row.issue ? {id:'default'} : await getActiveQuotationTemplate();
    if (!template?.id) throw new Error('Chưa có mẫu Word báo giá mặc định. Vui lòng thiết lập Mẫu in.');
    VKModal.open(`Xem trước báo giá ${row.code}`, '<p class="form-note">Bản xem trước dùng file Word đã trộn dữ liệu. Phân trang có thể khác khi mở trong Microsoft Word.</p><div data-quotation-word-preview>Đang tạo bản xem trước…</div>', null, {className:'modal-wide quotation-word-preview-modal',hideSubmit:true,cancelText:'Đóng'});
    const host = document.querySelector('[data-quotation-word-preview]');
    const response = await fetch(`/api/v1/quotations/${encodeURIComponent(row.id)}/word?template_id=${encodeURIComponent(template.id)}`, {headers:{Accept:'application/vnd.openxmlformats-officedocument.wordprocessingml.document',Authorization:`Bearer ${VKApi.token()}`}});
    if (!response.ok) {
        const message = await response.json().then(body => body.message).catch(() => null);
        if (host.isConnected) host.textContent = message || 'Không tạo được bản xem trước.';
        return;
    }
    const blob = await response.blob();
    if (!host.isConnected) return;
    if (!window.docx?.renderAsync) throw new Error('Không tải được bộ xem trước Word. Hãy tải lại trang.');
    host.textContent = '';
    const frame = document.createElement('iframe');
    frame.title = 'Bản xem trước file báo giá';
    frame.setAttribute('sandbox','allow-same-origin');
    frame.style.cssText = 'width:100%;height:100%;border:0;background:#f1f5f9';
    host.appendChild(frame);
    const frameDoc = frame.contentDocument;
    await window.docx.renderAsync(blob,frameDoc.body,frameDoc.head,{inWrapper:true,ignoreWidth:false,ignoreHeight:false,breakPages:true});
    // Word's floating textboxes do not reserve space in browser layout.
    // Normalize only the preview, keeping the downloaded DOCX untouched.
    frameDoc.querySelectorAll('svg').forEach(svg => {
        const textbox = svg.querySelector('foreignObject');
        if (!textbox || !textbox.textContent.trim()) return;
        const block = frameDoc.createElement('div');
        block.className = 'quotation-preview-textbox';
        Array.from(textbox.childNodes).forEach(child => block.appendChild(child.cloneNode(true)));
        svg.replaceWith(block);
    });
    const previewStyle = frameDoc.createElement('style');
    previewStyle.textContent = 'body{margin:0}.docx-wrapper{padding:24px!important}.docx-wrapper>section.docx{margin:0 auto 24px!important;height:auto!important;min-height:0!important}section.docx table{float:none!important;position:static!important;margin-top:12px!important;margin-bottom:12px!important;width:100%!important;max-width:100%!important} .quotation-preview-textbox{display:block;position:static!important;margin:12px 0;width:100%;height:auto;overflow:visible}.quotation-preview-textbox p{height:auto!important;min-height:1em;overflow:visible}';
    frameDoc.head.appendChild(previewStyle);
    const download = document.createElement('button');
    download.type = 'button'; download.className = 'btn primary'; download.textContent = 'Tải file Word';
    download.style.marginTop = '12px';
    download.addEventListener('click',()=>downloadBlob(blob,`${safeFileName(row.code || 'bao-gia')}.docx`));
    host.appendChild(download);
}

async function downloadQuotationDocx(row, template) {
    const controller = new AbortController();
    const timeout = window.setTimeout(() => controller.abort(), 15000);
    let response;
    const query = template?.id ? `?template_id=${encodeURIComponent(template.id)}` : '';

    try {
        response = await fetch(`/api/v1/quotations/${encodeURIComponent(row.id)}/word${query}`, {
            signal: controller.signal,
            headers: {
                Accept: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                Authorization: `Bearer ${VKApi.token()}`,
            },
        });
    } catch (error) {
        if (error.name === 'AbortError') {
            throw new Error('Máy chủ xuất Word phản hồi quá chậm. Bạn thử lại giúp mình nha.');
        }
        throw error;
    } finally {
        window.clearTimeout(timeout);
    }

    if (!response.ok) {
        const message = await response.json()
            .then(body => body.message || 'Khong xuat duoc file Word tu mau da chon.')
            .catch(() => 'Khong xuat duoc file Word tu mau da chon.');
        throw new Error(message);
    }

    const blob = await response.blob();
    downloadBlob(blob, `${safeFileName(row.code || 'bao-gia')}.docx`);
    VKModal.toast('Da tai file Word tu mau in.', 'success');
}

function downloadBlob(blob, fileName) {
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = fileName;
    document.body.appendChild(link);
    link.click();
    link.remove();
    URL.revokeObjectURL(url);
}

async function getQuotationTemplateChoices() {
    const listedTemplates = await getQuotationTemplateChoicesFromList();
    if (listedTemplates.length) return listedTemplates;

    try {
        const response = await VKApi.request('/print-templates/choices?module=quotation', { cache: false });
        const templates = response.data || [];
        if (templates.length) return templates.map(normalizeQuotationTemplateChoice);
    } catch (error) {
        const templates = await getQuotationTemplateChoicesDirect('/api/v1/print-templates/choices?module=quotation');
        if (templates.length) return templates;
    }
    const fallback = await getActiveQuotationTemplate();
    if (fallback?.id) return [normalizeQuotationTemplateChoice(fallback)];

    throw new Error('Không tìm thấy mẫu Word đang dùng. Bạn kiểm tra lại màn Mẫu in giúp mình.');
}

async function getQuotationTemplateChoicesFromList() {
    try {
        const response = await VKApi.request('/print-templates?page_size=100&module=quotation', { cache: false });
        return (response.data || [])
            .filter(template => template.module === 'quotation' && template.status === 'active')
            .map(normalizeQuotationTemplateChoice);
    } catch (error) {
        return getQuotationTemplateChoicesDirect('/api/v1/print-templates?page_size=100&module=quotation');
    }
}

async function getQuotationTemplateChoicesDirect(url) {
    try {
        const response = await fetch(url, {
            headers: {
                Accept: 'application/json',
                Authorization: `Bearer ${VKApi.token()}`,
            },
        });
        if (!response.ok) return [];
        const body = await response.json();
        return (body.data || [])
            .filter(template => template.module === 'quotation' && template.status === 'active')
            .map(normalizeQuotationTemplateChoice);
    } catch (error) {
        return [];
    }
}

function normalizeQuotationTemplateChoice(template = {}) {
    return {
        id: template.id ?? null,
        code: template.code || 'TPL-QUOTATION-DEFAULT',
        name: template.name || 'Mẫu báo giá mặc định',
        file_name: template.file_name || '',
        file_path: template.file_path || '',
        content_html: template.content_html || '',
        is_default: template.is_default !== false,
        is_system: template.is_system || !template.id,
        status: template.status || 'active',
    };
}

async function getActiveQuotationTemplate() {
    try {
        const response = await VKApi.request('/print-templates/choices?module=quotation', { cache: false });
        const templates = response.data || [];
        quotationTemplateCache = templates.find(template => template.is_default) || templates[0] || null;
        if (quotationTemplateCache) return quotationTemplateCache;

        const active = await VKApi.request('/print-templates/active?module=quotation', { cache: false });
        quotationTemplateCache = active.data || null;
        return quotationTemplateCache;
    } catch (error) {
        return null;
    }
}

function openQuotationEmail() {
    const row = quotationState.detail;
    if (!row) return;

    const customer = row.customer || {};
    const email = customer.email || '';
    const subject = `Báo giá ${row.code || ''} - ${customer.name || ''}`.trim();
    const body = quotationShareText(row, 'email');

    if (!email) {
        VKModal.toast('Khách hàng chưa có email. Bạn vẫn có thể tải Word/PDF rồi gửi thủ công.', 'warning');
        return;
    }

    window.location.href = `mailto:${encodeURIComponent(email)}?subject=${encodeURIComponent(subject)}&body=${encodeURIComponent(body)}`;
}

async function openQuotationZalo() {
    const row = quotationState.detail;
    if (!row) return;

    const text = quotationShareText(row, 'zalo');
    try {
        await navigator.clipboard.writeText(text);
        VKModal.toast('Đã sao chép nội dung báo giá. Zalo Web sẽ mở để bạn gửi cho khách.', 'success');
    } catch (error) {
        VKModal.toast('Không sao chép tự động được, bạn có thể tải Word/PDF rồi gửi qua Zalo.', 'warning');
    }
    window.open('https://chat.zalo.me/', '_blank');
}

function quotationShareText(row, channel) {
    const customer = row.customer || {};
    const amount = VKTable.money(row.total_amount || 0);
    const contact = [customer.contact_name, customer.phone].filter(Boolean).join(' - ');
    const lines = [
        `Kính gửi ${customer.name || 'Quý khách'},`,
        '',
        `VK-KPI gửi ${channel === 'zalo' ? 'thông tin' : 'báo giá'} ${row.code || ''}.`,
        `Tổng giá trị: ${amount} VND.`,
        contact ? `Người liên hệ: ${contact}.` : '',
        '',
        'Vui lòng xem file báo giá đính kèm hoặc bản PDF/Word được gửi cùng nội dung này.',
        'Trân trọng.',
    ];
    return lines.filter(line => line !== '').join('\n');
}

function quotationDocumentShell(row, content, autoPrint = false) {
    return `<!doctype html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <title>Báo giá ${escapeDoc(row.code || '')}</title>
    <style>
        @page { size: A4; margin: 16mm; }
        * { box-sizing: border-box; }
        body { margin: 0; color: #111827; font-family: Arial, sans-serif; font-size: 12px; line-height: 1.45; }
        .doc { max-width: 794px; margin: 0 auto; }
        .no-print { margin: 0 0 16px; text-align: right; }
        .no-print button { height: 34px; border: 1px solid #CBD5E1; background: #fff; border-radius: 8px; padding: 0 12px; cursor: pointer; }
        .muted { color: #64748B; margin: 0; }
        .doc-header { display: grid; grid-template-columns: 1fr auto; gap: 24px; padding-bottom: 16px; border-bottom: 2px solid #2563EB; }
        .doc-header h1 { margin: 4px 0 6px; color: #1D4ED8; font-size: 28px; }
        .doc-meta { display: grid; gap: 4px; min-width: 150px; text-align: right; }
        .doc-meta span { color: #64748B; }
        .doc-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin: 18px 0; }
        .doc-card { border: 1px solid #E5E7EB; border-radius: 8px; padding: 12px; }
        .doc-card h2 { margin: 0 0 10px; font-size: 14px; }
        .doc-card p { margin: 6px 0; }
        table { width: 100%; border-collapse: collapse; margin-top: 12px; }
        th { background: #F8FAFC; color: #475569; font-weight: 600; text-align: left; }
        th, td { border: 1px solid #E5E7EB; padding: 9px 8px; vertical-align: top; }
        td.num, th.num { text-align: right; }
        .summary-table { width: 360px; margin-left: auto; }
        .summary-table td:last-child { text-align: right; font-weight: 600; }
        .summary-table tr.total td { background: #EFF6FF; color: #1D4ED8; font-weight: 700; }
        @media print { .no-print { display: none; } body { print-color-adjust: exact; -webkit-print-color-adjust: exact; } }
    </style>
</head>
<body>
    <div class="doc">
        <div class="no-print"><button type="button" onclick="window.print()">In hoặc lưu PDF</button></div>
        ${content}
    </div>
    ${autoPrint ? '<script>window.addEventListener("load", () => setTimeout(() => window.print(), 250));</script>' : ''}
</body>
</html>`;
}

function mergeQuotationTemplate(row, templateContent) {
    const values = quotationMergeValues(row);
    return String(templateContent || '').replace(/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/g, (match, key) => {
        return Object.prototype.hasOwnProperty.call(values, key) ? values[key] : match;
    });
}

function quotationMergeValues(row) {
    const customer = row.customer || {};
    const subtotal = Number(row.subtotal_amount || row.total_amount || 0);
    const tax = Number(row.tax_amount || 0);
    const discount = Number(row.discount_amount || 0);
    const total = Number(row.total_amount || subtotal + tax);

    return {
        ma_bao_gia: escapeDoc(row.code || '-'),
        ngay_bao_gia: escapeDoc(formatDate(row.created_at)),
        ten_khach_hang: escapeDoc(customer.name || '-'),
        ma_khach_hang: escapeDoc(customer.code || '-'),
        nguoi_lien_he: escapeDoc(customer.contact_name || '-'),
        so_dien_thoai: escapeDoc(customer.phone || '-'),
        email: escapeDoc(customer.email || '-'),
        ten_cong_ty: escapeDoc(customer.company_name || customer.name || '-'),
        ma_so_thue: escapeDoc(customer.tax_code || '-'),
        dia_chi_cong_ty: escapeDoc(customer.billing_address || customer.address || '-'),
        bang_dong_hang: renderQuotationTemplateLineTable(row),
        tam_tinh: `${VKTable.money(subtotal)} VND`,
        chiet_khau: `${VKTable.money(discount)} VND`,
        vat: `${VKTable.money(tax)} VND`,
        tong_cong: `${VKTable.money(total)} VND`,
    };
}

function renderQuotationTemplateLineTable(row) {
    const rows = (row.items || []).map((item, index) => {
        const sku = item.sku || {};
        const quantity = Number(item.quantity || 0);
        const unitPrice = Number(item.unit_price || 0);
        const vatRate = Number(item.vat_rate || 0);
        const lineTotal = quantity * unitPrice * (1 + vatRate / 100);
        return `
            <tr>
                <td>${index + 1}</td>
                <td>${escapeDoc(sku.sku_code || item.sku_code || '-')}</td>
                <td>${escapeDoc(item.name || sku.name || '-')}</td>
                <td>${escapeDoc(item.unit || sku.unit || '-')}</td>
                <td class="num">${VKTable.money(quantity)}</td>
                <td class="num">${VKTable.money(unitPrice)}</td>
                <td class="num">${VKTable.money(vatRate)}%</td>
                <td class="num">${VKTable.money(lineTotal)}</td>
            </tr>
        `;
    }).join('');

    return `
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>Mã hàng</th>
                    <th>Tên hàng</th>
                    <th>ĐVT</th>
                    <th class="num">Số lượng</th>
                    <th class="num">Đơn giá</th>
                    <th class="num">VAT</th>
                    <th class="num">Thành tiền</th>
                </tr>
            </thead>
            <tbody>${rows || '<tr><td colspan="8">Chưa có dòng hàng</td></tr>'}</tbody>
        </table>
    `;
}

function quotationDocumentHtml(row, { autoPrint = false, template = null } = {}) {
    const customer = row.customer || {};
    const items = row.items || [];
    const subtotal = Number(row.subtotal_amount || row.total_amount || 0);
    const tax = Number(row.tax_amount || 0);
    const discount = Number(row.discount_amount || 0);
    const total = Number(row.total_amount || subtotal + tax);
    const documentDate = formatDate(row.created_at);
    const mergedContent = template?.content_html ? mergeQuotationTemplate(row, template.content_html) : '';

    if (mergedContent) {
        return quotationDocumentShell(row, mergedContent, autoPrint);
    }

    return `<!doctype html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <title>Báo giá ${escapeDoc(row.code || '')}</title>
    <style>
        @page { size: A4; margin: 16mm; }
        * { box-sizing: border-box; }
        body { margin: 0; color: #111827; font-family: Arial, sans-serif; font-size: 12px; line-height: 1.45; }
        .doc { max-width: 794px; margin: 0 auto; }
        .doc-head { display: grid; grid-template-columns: 1fr auto; gap: 24px; padding-bottom: 18px; border-bottom: 2px solid #2563EB; }
        .brand strong { display: block; color: #0F172A; font-size: 22px; letter-spacing: .5px; }
        .brand span { color: #64748B; }
        .title { text-align: right; }
        .title h1 { margin: 0; color: #2563EB; font-size: 28px; }
        .title span { color: #64748B; }
        .info { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin: 18px 0; }
        .box { border: 1px solid #E5E7EB; border-radius: 8px; padding: 12px; }
        .box h2 { margin: 0 0 10px; font-size: 13px; color: #111827; }
        .row { display: grid; grid-template-columns: 120px 1fr; gap: 10px; padding: 5px 0; border-top: 1px solid #F1F5F9; }
        .row:first-of-type { border-top: 0; }
        .row span { color: #64748B; }
        .row strong { font-weight: 600; }
        table { width: 100%; border-collapse: collapse; margin-top: 12px; }
        th { background: #F8FAFC; color: #475569; font-weight: 600; text-align: left; }
        th, td { border: 1px solid #E5E7EB; padding: 9px 8px; vertical-align: top; }
        td.num, th.num { text-align: right; }
        tfoot td { background: #F8FAFC; }
        tfoot tr.total td { background: #EFF6FF; color: #0F172A; font-size: 14px; font-weight: 700; }
        .note { margin-top: 18px; color: #475569; }
        .sign { display: grid; grid-template-columns: 1fr 1fr; gap: 60px; margin-top: 34px; text-align: center; }
        .sign strong { display: block; margin-bottom: 64px; }
        .no-print { margin: 0 0 16px; text-align: right; }
        .no-print button { height: 34px; border: 1px solid #CBD5E1; background: #fff; border-radius: 8px; padding: 0 12px; cursor: pointer; }
        @media print { .no-print { display: none; } body { print-color-adjust: exact; -webkit-print-color-adjust: exact; } }
    </style>
</head>
<body>
    <div class="doc">
        <div class="no-print"><button type="button" onclick="window.print()">In hoặc lưu PDF</button></div>
        <header class="doc-head">
            <div class="brand">
                <strong>VK-KPI</strong>
                <span>Hệ thống quản lý chỉ tiêu công việc</span>
            </div>
            <div class="title">
                <h1>BÁO GIÁ</h1>
                <span>Số: ${escapeDoc(row.code || '-')}</span>
            </div>
        </header>
        <section class="info">
            <div class="box">
                <h2>Thông tin khách hàng</h2>
                ${docRow('Khách hàng', customer.name)}
                ${docRow('Người liên hệ', [customer.contact_name, customer.phone].filter(Boolean).join(' - '))}
                ${docRow('Email', customer.email)}
                ${docRow('Mã khách hàng', customer.code)}
            </div>
            <div class="box">
                <h2>Thông tin xuất hóa đơn</h2>
                ${docRow('Tên công ty', customer.name)}
                ${docRow('Mã số thuế', customer.tax_code)}
                ${docRow('Địa chỉ công ty', customer.billing_address || customer.address)}
                ${docRow('Ngày báo giá', documentDate)}
            </div>
        </section>
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>Mã hàng</th>
                    <th>Tên hàng</th>
                    <th>ĐVT</th>
                    <th class="num">SL</th>
                    <th class="num">Đơn giá</th>
                    <th class="num">VAT</th>
                    <th class="num">Thành tiền</th>
                </tr>
            </thead>
            <tbody>
                ${items.map((item, index) => `
                    <tr>
                        <td>${index + 1}</td>
                        <td>${escapeDoc(item.sku?.sku_code || '-')}</td>
                        <td>${escapeDoc(item.name || item.sku?.name || '-')}</td>
                        <td>${escapeDoc(item.unit || item.sku?.unit || '-')}</td>
                        <td class="num">${escapeDoc(VKTable.money(item.quantity || 0))}</td>
                        <td class="num">${escapeDoc(VKTable.money(item.unit_price || 0))}</td>
                        <td class="num">${Number(item.vat_rate || 0).toFixed(0)}%</td>
                        <td class="num">${escapeDoc(VKTable.money(item.line_total || 0))}</td>
                    </tr>
                `).join('')}
            </tbody>
            <tfoot>
                <tr><td colspan="7" class="num">Tạm tính</td><td class="num">${escapeDoc(VKTable.money(subtotal))}</td></tr>
                <tr><td colspan="7" class="num">Chiết khấu</td><td class="num">${escapeDoc(VKTable.money(discount))}</td></tr>
                <tr><td colspan="7" class="num">VAT</td><td class="num">${escapeDoc(VKTable.money(tax))}</td></tr>
                <tr class="total"><td colspan="7" class="num">Tổng cộng</td><td class="num">${escapeDoc(VKTable.money(total))} VND</td></tr>
            </tfoot>
        </table>
        <p class="note">Báo giá có hiệu lực trong 30 ngày kể từ ngày phát hành, trừ khi có thỏa thuận khác.</p>
        <section class="sign">
            <div><strong>Khách hàng</strong><span>Ký, ghi rõ họ tên</span></div>
            <div><strong>Đại diện bán hàng</strong><span>Ký, ghi rõ họ tên</span></div>
        </section>
    </div>
    ${autoPrint ? '<script>window.addEventListener("load", () => setTimeout(() => window.print(), 250));<\/script>' : ''}
</body>
</html>`;
}

function docRow(label, value) {
    return `<div class="row"><span>${escapeDoc(label)}</span><strong>${escapeDoc(value || '-')}</strong></div>`;
}

function escapeDoc(value) {
    return String(value == null ? '' : value)
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
}

function safeFileName(value) {
    return String(value || 'bao-gia').replace(/[\\/:*?"<>|]+/g, '-').replace(/\s+/g, '-');
}

function defaultQuotationValidUntil(row = null) {
    if (row?.valid_until) return dateInputValue(row.valid_until);
    const date = new Date();
    date.setDate(date.getDate() + 15);
    return dateInputValue(date);
}
function dateInputValue(value) {
    if (!value) return '';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return '';
    return date.toISOString().slice(0, 10);
}

function formatDate(value) {
    if (!value) return '-';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return '-';
    return date.toLocaleString('vi-VN', { hour: '2-digit', minute: '2-digit', day: '2-digit', month: '2-digit', year: 'numeric' });
}

function timelineTone(status) {
    if (['approved', 'ready', 'completed', 'done'].includes(status)) return 'done';
    if (['pending', 'pending_approval', 'draft', 'new'].includes(status)) return 'waiting';
    if (['rejected', 'overdue', 'shortage'].includes(status)) return 'risk';
    return '';
}

window.loadQuotations = loadQuotations;
})();
