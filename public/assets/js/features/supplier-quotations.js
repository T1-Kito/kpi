(function () {
    let data = { supplierQuotations: [], purchaseRequests: [], suppliers: [], skus: [], warehouses: [] };
    let documentUrl = null;
    let documentSequence = 0;

    window.loadSupplierQuotations = loadSupplierQuotations;

    document.addEventListener('click', (event) => {
        if (!document.getElementById('supplierQuotationsRoot')) return;
        const uploadDocument = event.target.closest('[data-sq-upload-document]');
        if (uploadDocument) { uploadOriginalDocument(uploadDocument.dataset.sqUploadDocument); return; }
        const filterRequest = event.target.closest('[data-filter-sq-request]');
        if (filterRequest) {
            document.querySelector('[data-sq-filter="request"]').value = filterRequest.dataset.filterSqRequest;
            document.querySelector('[data-sq-filter="supplier"]').value = '';
            document.querySelector('[data-sq-filter="status"]').value = '';
            filterQuotations();
            document.querySelector('[data-sq-list]').scrollIntoView({behavior:'smooth', block:'start'});
            return;
        }
        if (event.target.closest('[data-clear-sq-filters]')) {
            document.querySelectorAll('[data-sq-filter]').forEach(input => input.value = ''); filterQuotations(); return;
        }
        const toggleQueue = event.target.closest('[data-toggle-sq-queue]');
        if (toggleQueue) {
            const expanded = toggleQueue.dataset.expanded !== 'true';
            toggleQueue.dataset.expanded = String(expanded);
            const rows = document.querySelectorAll('[data-sq-queue-row]');
            rows.forEach(row => row.hidden = !expanded && Number(row.dataset.sqQueueIndex) >= 5);
            const requestCount = new Set(Array.from(rows, row => row.dataset.sqQueueRow)).size;
            toggleQueue.textContent = expanded ? 'Thu gọn' : `Xem tất cả (${requestCount})`; return;
        }

        const requestButton = event.target.closest('[data-quote-purchase-request]');
        if (requestButton) { openCreateModal(requestButton.dataset.quotePurchaseRequest); return; }

        if (event.target.closest('[data-create-supplier-quotation]')) {
            openCreateModal();
            return;
        }

        const openButton = event.target.closest('[data-open-supplier-quotation]');
        if (openButton) {
            openDetail(openButton.dataset.openSupplierQuotation);
            return;
        }

        const selectButton = event.target.closest('[data-select-supplier-quotation]');
        const configure = event.target.closest('[data-configure-sq-approval]');
        if (configure) { configureQuotationApproval(configure.dataset.configureSqApproval); return; }
        if (selectButton) {
            selectQuotation(selectButton.dataset.selectSupplierQuotation);
            return;
        }

        const createPoButton = event.target.closest('[data-create-po-from-supplier-quotation]');
        if (createPoButton) {
            createPurchaseOrder(createPoButton.dataset.createPoFromSupplierQuotation);
            return;
        }
        if (event.target.closest('button, a, input, select, details')) return;
        const row = event.target.closest('[data-supplier-quotation-row]');
        if (row) openDetail(row.dataset.supplierQuotationRow);
    });

    document.addEventListener('click', (event) => {
        if (event.target.closest('[data-add-supplier-quotation-line]')) {
            const box = document.querySelector('[data-supplier-quotation-lines]');
            if (!box) return;
            box.insertAdjacentHTML('beforeend', lineTemplate(box.querySelectorAll('.supplier-quotation-line').length));
        }

        const remove = event.target.closest('[data-remove-supplier-quotation-line]');
        if (remove) {
            const rows = document.querySelectorAll('.supplier-quotation-line');
            if (rows.length <= 1) {
                VKModal.toast('Báo giá cần ít nhất một dòng hàng.', 'warning');
                return;
            }
            remove.closest('.supplier-quotation-line')?.remove();
        }
    });

    function loadSupplierQuotations() {
        readData();
        filterQuotations();
        const queueToggle = document.querySelector('[data-toggle-sq-queue]');
        if (queueToggle) queueToggle.hidden = new Set(Array.from(document.querySelectorAll('[data-sq-queue-row]'), row => row.dataset.sqQueueRow)).size <= 5;
        const requestId = new URLSearchParams(window.location.search).get('request');
        if (requestId) { openCreateModal(requestId); window.history.replaceState({}, '', window.location.pathname); }
    }

    document.addEventListener('change', event => {
        if (event.target.matches('[data-sq-filter]')) { filterQuotations(); return; }
        if (!document.getElementById('supplierQuotationsRoot')) return;
        if (event.target.matches('[name$="[sku_id]"]')) {
            const sku = data.skus.find(row => String(row.id) === event.target.value);
            const line = event.target.closest('.supplier-quotation-line');
            if (line) { line.querySelector('[data-sq-product-name]').value = sku?.name || ''; line.querySelector('[data-sq-product-unit]').value = sku?.unit || ''; }
            return;
        }
        if (event.target.matches('[name="supplier_id"]')) {
            const supplier = data.suppliers.find(row => String(row.id) === event.target.value);
            document.querySelector('[data-sq-supplier-name]').value = supplier?.name || ''; return;
        }
        if (!document.getElementById('supplierQuotationsRoot') || !event.target.matches('[name="purchase_request_id"]')) return;
        const request = data.purchaseRequests.find(row => String(row.id) === event.target.value);
        document.querySelector('[data-sq-request-reason]').value = request?.reason || '';
        const box = document.querySelector('[data-supplier-quotation-lines]');
        if (box && request) box.innerHTML = request.items.map((item, index) => lineTemplate(index, item)).join('');
    });

    function filterQuotations() {
        const root = document.getElementById('supplierQuotationsRoot');
        if (!root) return;
        const values = {};
        root.querySelectorAll('[data-sq-filter]').forEach(input => values[input.dataset.sqFilter] = input.value);
        let count = 0;
        root.querySelectorAll('[data-supplier-quotation-row]').forEach(row => {
            row.hidden = Boolean((values.request && row.dataset.sqRequest !== values.request) || (values.supplier && row.dataset.sqSupplier !== values.supplier) || (values.status && row.dataset.sqStatus !== values.status));
            if (!row.hidden) count++;
        });
        const badge = root.querySelector('[data-sq-count]');
        if (badge) badge.textContent = `${count} báo giá`;
        const empty = root.querySelector('[data-sq-filter-empty]');
        if (empty) empty.hidden = count > 0 || !root.querySelector('[data-supplier-quotation-row]');
        root.querySelectorAll('[data-sq-queue-row]').forEach(row => row.classList.toggle('sq-request-active', row.dataset.sqQueueRow === values.request));
    }

    function updateApprovalProgress(row) {
        if (!row) return;
        const index = data.supplierQuotations.findIndex(item => String(item.id) === String(row.id));
        if (index >= 0) data.supplierQuotations[index] = {...data.supplierQuotations[index], ...row};
        const source = document.getElementById('supplierQuotationsData');
        if (source) source.textContent = JSON.stringify(data);
        const ticks = document.querySelector(`[data-supplier-quotation-row="${row.id}"] .sq-approval-ticks`);
        if (!ticks) return;
        const flow = row.approval_flow || [];
        ticks.innerHTML = flow.map(step => {
            const approved = Boolean(step.decided_at);
            const label = `${step.name}${step.final ? ' (duyệt cuối)' : ''}: ${approved ? 'Đã duyệt' : 'Chờ duyệt'}`;
            return `<span class="sq-approval-tick ${approved ? 'is-approved' : 'is-pending'} ${step.final ? 'is-final' : ''}" title="${escape(label)}" aria-label="${escape(label)}">${approved ? '✓' : '○'}</span>`;
        }).join('');
    }

    function readData() {
        const node = document.getElementById('supplierQuotationsData');
        if (!node) return;
        try {
            data = JSON.parse(node.textContent || '{}');
        } catch (error) {
            data = { supplierQuotations: [], purchaseRequests: [], suppliers: [], skus: [] };
        }
    }

    function openCreateModal(requestId = '') {
        readData();
        if (!data.suppliers.length || !data.skus.length) {
            VKModal.toast('Cần có nhà cung cấp và mã hàng trước khi tạo báo giá NCC.', 'warning');
            return;
        }

        const purchaseOptions = [{ value: '', label: 'Không gắn yêu cầu mua' }].concat(data.purchaseRequests.map(item => ({
            value: item.id,
            label: item.code,
        })));
        const selectedRequest = data.purchaseRequests.find(item => String(item.id) === String(requestId));

        VKModal.open('Tạo báo giá nhà cung cấp', `
            <div class="form-grid two">
                ${VKModal.select('purchase_request_id', 'Mã yêu cầu mua đã duyệt', purchaseOptions, requestId)}
                <div class="field"><label>Lý do mua</label><input data-sq-request-reason value="${escape(selectedRequest?.reason || '')}" readonly></div>
                ${VKModal.select('supplier_id', 'Mã nhà cung cấp', data.suppliers.map(supplier => ({
                    value: supplier.id,
                    label: supplier.code,
                })), data.suppliers[0]?.id)}
                <div class="field"><label>Tên nhà cung cấp</label><input data-sq-supplier-name value="${escape(data.suppliers[0]?.name || '')}" readonly></div>
                ${VKModal.field('quoted_at', 'Ngày báo giá', 'date', new Date().toISOString().slice(0, 10))}
                ${VKModal.field('valid_until', 'Hiệu lực đến', 'date', '')}
            </div>
            <div class="supplier-quotation-lines" data-supplier-quotation-lines>
                ${selectedRequest?.items?.length ? selectedRequest.items.map((item, index) => lineTemplate(index, item)).join('') : lineTemplate(0)}
            </div>
            <div class="field">
                <label for="note">Ghi chú</label>
                <textarea id="note" name="note" rows="3" placeholder="Điều kiện thanh toán, thời gian giao hàng, bảo hành..."></textarea>
            </div>
            <div class="sq-document-upload">
                <label for="sqCreateDocument">File báo giá gốc của nhà cung cấp</label>
                <input id="sqCreateDocument" name="document" type="file" accept=".pdf,.jpg,.jpeg,.png,.xlsx,.xls">
                <small class="form-note">PDF, ảnh hoặc Excel · tối đa 10 MB. File được lưu cùng báo giá; cần có file trước khi duyệt nhiều người.</small>
            </div>
        `, async (form) => {
            const payload = new FormData(form);
            if (!payload.get('document')?.size) payload.delete('document');
            collectLines().forEach((line,index) => Object.entries(line).forEach(([key,value]) => payload.set(`lines[${index}][${key}]`,String(value))));
            await VKApi.request('/supplier-quotations', {
                method: 'POST',
                body: payload,
            });
            VKModal.toast('Đã tạo báo giá nhà cung cấp.');
            window.setTimeout(() => window.location.reload(), 450);
        }, { className: 'modal-wide supplier-quotation-modal', submitText: 'Lưu báo giá' });
    }

    function lineTemplate(index, item = {}) {
        const sku = data.skus.find(row => String(row.id) === String(item.sku_id)) || data.skus[0];
        const skuOptions = data.skus.map(sku => `<option value="${sku.id}" ${String(sku.id) === String(item.sku_id) ? 'selected' : ''}>${escape(sku.sku_code)}</option>`).join('');
        return `
            <div class="supplier-quotation-line">
                <label>
                    <span>Mã hàng</span>
                    <select name="lines[${index}][sku_id]">${skuOptions}</select>
                </label>
                <label><span>Tên sản phẩm</span><input data-sq-product-name value="${escape(sku?.name || '')}" readonly></label>
                <label><span>ĐVT</span><input data-sq-product-unit value="${escape(sku?.unit || '')}" readonly></label>
                <label>
                    <span>Số lượng</span>
                    <input name="lines[${index}][quantity]" type="number" min="0.01" step="0.01" value="${Number(item.quantity || 1)}">
                </label>
                <label>
                    <span>Đơn giá mua</span>
                    <input name="lines[${index}][unit_price]" type="number" min="0" step="1000" value="0">
                </label>
                <label>
                    <span>Ghi chú</span>
                    <input name="lines[${index}][note]" type="text" placeholder="Bảo hành/giao hàng">
                </label>
                <button class="btn danger" type="button" data-remove-supplier-quotation-line>×</button>
            </div>
        `;
    }

    function collectLines() {
        return Array.from(document.querySelectorAll('.supplier-quotation-line')).map(row => ({
            sku_id: Number(row.querySelector('[name$="[sku_id]"]')?.value || 0),
            quantity: Number(row.querySelector('[name$="[quantity]"]')?.value || 0),
            unit_price: Number(row.querySelector('[name$="[unit_price]"]')?.value || 0),
            note: row.querySelector('[name$="[note]"]')?.value || '',
        }));
    }

    async function openDetail(id) {
        const sequence = ++documentSequence;
        if (documentUrl) { URL.revokeObjectURL(documentUrl); documentUrl = null; }
        readData();
        const response = await VKApi.request(`/supplier-quotations/${id}`);
        const row = response.data;
        const flow = row.approval_flow || [];
        const found = data.supplierQuotations.findIndex(item => String(item.id) === String(id));
        if (found >= 0) data.supplierQuotations[found] = row;
        if (!row) {
            VKModal.toast('Không tìm thấy báo giá nhà cung cấp.', 'warning');
            return;
        }

        const lines = (row.lines || []).map((line, index) => `
            <tr>
                <td>${index + 1}</td>
                <td>${escape(line.sku?.sku_code || '-')}</td>
                <td>${escape(line.sku?.name || '-')}</td>
                <td>${escape(line.sku?.unit || '-')}</td>
                <td>${formatNumber(line.quantity)}</td>
                <td>${formatMoney(line.unit_price)}</td>
                <td><strong>${formatMoney(line.line_total)}</strong></td>
            </tr>
        `).join('');
        const order = row.purchase_orders?.[0] || row.purchaseOrders?.[0];
        const label = order ? 'Đã tạo đơn mua' : statusLabel(row.status);
        const badge = `<span class="badge ${row.status === 'selected' ? 'success' : row.status === 'draft' ? 'info' : 'inactive'}">${escape(label)}</span>`;
        const currentStep = flow.find(step => String(step.user_id) === String(window.VKUser?.id));
        const mayApprove = !flow.length || (currentStep && !currentStep.decided_at && (!currentStep.final || flow.every(step => step.final || step.decided_at)));

        VKModal.open(`Chi tiết báo giá ${row.code}`, `
            <div class="sq-review-layout"><section class="sq-review-summary">
            <div class="sq-detail-banner"><div class="sq-supplier-identity"><div><span>Nhà cung cấp</span><h3>${escape(row.supplier?.name || 'Chưa khai báo')}</h3></div></div><div class="sq-status-caption"><small>Trạng thái báo giá</small>${badge}</div></div>
            <div class="sq-detail-info">
                ${detailItem('Điện thoại nhà cung cấp', row.supplier?.phone || '—')}
                ${detailItem('Email nhà cung cấp', row.supplier?.email || '—')}
                ${detailItem('Yêu cầu mua', row.purchase_request?.code || row.purchaseRequest?.code || '-')}
                ${detailItem('Lý do mua', row.purchase_request?.reason || row.purchaseRequest?.reason || 'Chưa gắn yêu cầu mua')}
                ${detailItem('Ngày báo giá', formatDate(row.quoted_at))}
                ${detailItem('Hiệu lực', formatDate(row.valid_until))}
                ${row.selected_at ? detailItem('Ngày chọn làm căn cứ mua', formatDate(row.selected_at)) : ''}
                ${order ? detailItem('Đơn mua liên quan', `${order.code} · ${formatDate(order.created_at)}`) : ''}
            </div>
            ${flow.length ? `<section class="record-panel po-approval"><div class="po-approval-heading"><div><h3>Duyệt báo giá NCC <span>${flow.filter(step => step.decided_at).length}/${flow.length}</span></h3><p>Đủ người duyệt và người duyệt cuối mới tự tạo đơn mua.</p></div>${response.can_configure_approval && row.status === 'draft' && !flow.some(step => step.decided_at) ? `<button class="btn secondary small" data-configure-sq-approval="${row.id}">Thiết lập người duyệt</button>` : ''}</div><div class="po-approval-people">${flow.map((step,index) => `<div class="po-approval-person ${step.decided_at ? 'approved' : 'pending'}"><i>${step.decided_at ? '✓' : index + 1}</i><div><strong>${escape(step.name)}</strong><small>${step.final ? 'Duyệt cuối · ' : ''}${step.decided_at ? formatDate(step.decided_at) : 'Chờ duyệt'}</small></div></div>`).join('')}</div></section>` : ''}
            <div class="sq-items-title">Hàng hóa báo giá <span>${(row.lines || []).length} dòng hàng</span></div>
            <div class="table-wrap">
                <table class="list-table compact-table">
                    <thead><tr><th>#</th><th>Mã hàng</th><th>Tên sản phẩm</th><th>ĐVT</th><th>Số lượng</th><th>Đơn giá</th><th>Thành tiền</th></tr></thead>
                    <tbody>${lines}</tbody>
                </table>
            </div>
            <div class="sq-detail-total"><span>Tổng giá trị báo giá</span><strong>${formatMoney(row.total_amount)} <small>VND</small></strong></div>
            ${row.note ? `<p class="form-note">${escape(row.note)}</p>` : ''}
            </section><section class="sq-review-document">
                <div class="sq-document-heading"><h3>Báo giá gốc của nhà cung cấp</h3><span>${row.document_name ? escape(row.document_name) : 'Chưa có chứng từ'}</span></div>
                <div data-sq-document-preview="${row.id}" class="sq-document-preview">${row.document_name ? 'Đang tải tài liệu…' : '<div class="empty-state">Đính kèm báo giá gốc để người duyệt đối chiếu.<br>PDF, ảnh hoặc Excel · tối đa 10 MB</div>'}</div>
                <a class="btn small" data-sq-document-download hidden>Tải file gốc</a>
                <p class="form-note">File lưu riêng theo doanh nghiệp. Sau lượt duyệt đầu tiên không được thay file.</p>
            </section></div>
            <div class="button-row">
                ${row.status === 'draft' && mayApprove ? `<button class="btn primary" type="button" data-select-supplier-quotation="${row.id}" ${flow.length && !row.document_name ? 'disabled' : ''}>${flow.length && currentStep?.final ? 'Duyệt & tự tạo đơn mua' : 'Duyệt báo giá'}</button>` : ''}
                ${row.status === 'draft' && flow.length && !mayApprove ? '<span class="form-note">Đang chờ người được phân công duyệt theo luồng.</span>' : ''}
                ${row.status === 'selected' && !order ? `<button class="btn primary" type="button" data-create-po-from-supplier-quotation="${row.id}">Tạo đơn mua</button>` : ''}
                ${order ? '<a class="btn primary" href="/purchase-orders">Xem danh sách đơn mua</a>' : ''}
            </div>
        `, null, { className: 'modal-wide supplier-quotation-modal sq-detail-modal', hideSubmit: true, cancelText: 'Đóng' });
        if (row.document_name) loadOriginalDocument(row, sequence);
    }

    async function uploadOriginalDocument(id) {
        const file = document.getElementById('sqOriginalDocument')?.files?.[0];
        if (!file) { VKModal.toast('Chọn file báo giá trước khi lưu.', 'warning'); return; }
        const button = document.querySelector('[data-sq-upload-document]');
        button.disabled = true;
        try {
            const form = new FormData(); form.append('document', file);
            await VKApi.request(`/supplier-quotations/${id}/document`, {method:'POST', body:form});
            VKModal.toast('Đã lưu file báo giá gốc.');
            await openDetail(id);
        } catch (error) { VKModal.toast(error.message, 'danger'); }
        finally { if (button.isConnected) button.disabled = false; }
    }

    async function loadOriginalDocument(row, sequence) {
        const preview = document.querySelector('[data-sq-document-preview]');
        try {
            const response = await fetch(`/api/v1/supplier-quotations/${row.id}/document`, {headers:{Authorization:`Bearer ${VKApi.token()}`, Accept:'application/pdf,image/*,application/octet-stream'}});
            if (!response.ok) throw new Error('Không tải được file báo giá gốc.');
            const blob = await response.blob();
            if (sequence !== documentSequence || !preview.isConnected) return;
            documentUrl = URL.createObjectURL(blob);
            const download = document.querySelector('[data-sq-document-download]');
            download.href = documentUrl; download.download = row.document_name; download.hidden = false;
            if (blob.type === 'application/pdf') preview.innerHTML = `<iframe title="Báo giá gốc" src="${documentUrl}"></iframe>`;
            else if (['image/jpeg','image/png'].includes(blob.type)) preview.innerHTML = `<img alt="Báo giá gốc của nhà cung cấp" src="${documentUrl}">`;
            else preview.innerHTML = '<div class="empty-state">File Excel đã đính kèm. Tải file gốc để xem đầy đủ.</div>';
        } catch (error) { if (preview.isConnected) preview.textContent = error.message; }
    }

    async function selectQuotation(id) {
        const quotation = data.supplierQuotations.find(row => String(row.id) === String(id));
        if (!quotation) return;
        const flow = quotation.approval_flow || [];
        if (flow.length && window.VKUser?.id) {
            const currentStep = flow.find(step => String(step.user_id) === String(window.VKUser.id));
            if (!currentStep) {
                VKModal.toast(`Bạn không thuộc danh sách duyệt báo giá này. Người duyệt: ${flow.map(step => step.name).join(', ')}.`, 'danger');
                return;
            }
            if (currentStep.decided_at) { VKModal.toast('Bạn đã duyệt báo giá này.', 'info'); return; }
            if (currentStep.final && flow.some(step => !step.final && !step.decided_at)) {
                VKModal.toast('Chưa đủ người duyệt nội bộ trước người duyệt cuối.', 'danger');
                return;
            }
        }
        VKModal.open('Xác nhận duyệt báo giá', `
            <div class="sq-confirm-copy"><span class="sq-confirm-icon" aria-hidden="true">✓</span><h3>Duyệt báo giá này làm căn cứ mua hàng?</h3><p>Kiểm tra nhà cung cấp và giá trị trước khi duyệt.</p></div>
            <dl class="sq-confirm-summary"><div><dt>Báo giá</dt><dd>${escape(quotation.code)}</dd></div><div><dt>Nhà cung cấp</dt><dd>${escape(quotation.supplier?.name || '—')}</dd></div><div><dt>Tổng giá trị</dt><dd>${formatMoney(quotation.total_amount)} VND</dd></div></dl>
            <label>Kho nhận hàng<select name="receiving_warehouse_id" required>${(data.warehouses || []).map(row => `<option value="${row.id}">${escape(row.code)} - ${escape(row.name)}</option>`).join('')}</select></label>
            <p class="sq-confirm-note">${quotation.approval_flow?.length ? 'Ghi nhận lượt duyệt của bạn. Khi đủ người duyệt, hệ thống tạo đơn mua và phiếu nhập chờ kho xác nhận.' : 'Xác nhận sẽ chọn báo giá và tạo đơn mua nháp.'}</p>
        `, async () => {
            const warehouseId = Number(document.querySelector('#modalBody [name="receiving_warehouse_id"]')?.value || 0);
            if (!warehouseId) throw new Error('Chọn kho nhận hàng.');
            const result = await VKApi.request(`/supplier-quotations/${id}/select`, { method: 'POST', body: JSON.stringify({create_purchase_order: true, receiving_warehouse_id: warehouseId}) });
            updateApprovalProgress(result.data);
            VKModal.close();
            if (result.purchase_order) {
                VKModal.toast(result.purchase_order.status === 'draft' ? 'Đã duyệt báo giá, tạo đơn mua chờ duyệt theo luồng riêng.' : 'Đã duyệt đủ báo giá và tạo đơn mua.');
                await VKLayout.navigatePjax(`/purchase-orders?order=${result.purchase_order.id}`);
            } else {
                VKModal.toast('Đã ghi nhận lượt duyệt. Đang chờ những người còn lại.');
                window.setTimeout(() => openDetail(id), 0);
            }
        }, {className:'sq-confirm-modal', submitText:'Xác nhận duyệt', cancelText:'Hủy'});
    }

    async function configureQuotationApproval(id) {
        const response = await VKApi.request(`/supplier-quotations/${id}`);
        const options = [{value:'',label:'Chọn người duyệt'}, ...(response.approval_candidates || []).map(user => ({value:user.id,label:user.name}))];
        VKModal.open('Người duyệt báo giá nhà cung cấp', `<p class="form-note">Chọn 1–3 người duyệt nội bộ và người duyệt cuối. Nhân sự nội bộ duyệt song song; đủ tất cả mới đến giám đốc.</p><div class="form-grid two">${[0,1,2,3].map(index => VKModal.select(`approver_${index}`, index === 3 ? 'Giám đốc / Người duyệt cuối *' : `Người duyệt nội bộ ${index+1}${index ? ' (tùy chọn)' : ' *'}`,options,'')).join('')}</div>`, async form => {
            const values = Object.fromEntries(new FormData(form));
            if (!values.approver_0 || !values.approver_3) throw new Error('Chọn người nội bộ và người duyệt cuối.');
            await VKApi.request(`/supplier-quotations/${id}/approval-flow`,{method:'POST',body:JSON.stringify({approvers:[0,1,2,3].map(index => values[`approver_${index}`]).filter(Boolean).map(Number)})});
            window.setTimeout(() => openDetail(id), 0);
        },{className:'modal-wide',submitText:'Lưu luồng duyệt'});
    }

    async function createPurchaseOrder(id) {
        VKModal.open('Tạo đơn mua', `<label>Kho nhận hàng<select name="receiving_warehouse_id" required>${(data.warehouses || []).map(row => `<option value="${row.id}">${escape(row.code)} - ${escape(row.name)}</option>`).join('')}</select></label>`, async form => {
            const warehouseId = Number(new FormData(form).get('receiving_warehouse_id'));
            await VKApi.request('/purchase-orders', {method:'POST', body:JSON.stringify({supplier_quotation_id:Number(id),receiving_warehouse_id:warehouseId})});
            VKModal.toast('Đã tạo đơn mua từ báo giá NCC.'); VKModal.close();
            window.setTimeout(() => (window.VKLayout?.visit ? VKLayout.visit('/purchase-orders') : window.location.assign('/purchase-orders')), 450);
        }, {submitText:'Tạo đơn mua'});
    }

    function detailItem(label, value) {
        return `<div class="detail-item"><span>${escape(label)}</span><strong>${escape(value)}</strong></div>`;
    }

    function statusLabel(value) {
        return { draft: 'Nháp', selected: 'Đã chọn làm căn cứ mua hàng', rejected: 'Không chọn' }[value] || value || '-';
    }

    function formatDate(value) {
        if (!value) return '-';
        return new Date(value).toLocaleDateString('vi-VN');
    }

    function formatNumber(value) {
        return Number(value || 0).toLocaleString('vi-VN', { maximumFractionDigits: 2 });
    }

    function formatMoney(value) {
        return Number(value || 0).toLocaleString('vi-VN');
    }

    function escape(value) {
        return VKTable.escapeHtml(value ?? '');
    }
})();
