(function () {
    let data = { supplierQuotations: [], purchaseRequests: [], suppliers: [], skus: [] };

    window.loadSupplierQuotations = loadSupplierQuotations;

    document.addEventListener('click', (event) => {
        if (!document.getElementById('supplierQuotationsRoot')) return;

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
        if (selectButton) {
            selectQuotation(selectButton.dataset.selectSupplierQuotation);
            return;
        }

        const createPoButton = event.target.closest('[data-create-po-from-supplier-quotation]');
        if (createPoButton) {
            createPurchaseOrder(createPoButton.dataset.createPoFromSupplierQuotation);
        }
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

    function openCreateModal() {
        readData();
        if (!data.suppliers.length || !data.skus.length) {
            VKModal.toast('Cần có nhà cung cấp và mã hàng trước khi tạo báo giá NCC.', 'warning');
            return;
        }

        const purchaseOptions = [{ value: '', label: 'Không gắn yêu cầu mua' }].concat(data.purchaseRequests.map(item => ({
            value: item.id,
            label: `${item.code} - ${item.reason || item.status}`,
        })));

        VKModal.open('Tạo báo giá nhà cung cấp', `
            <div class="form-grid two">
                ${VKModal.select('purchase_request_id', 'Yêu cầu mua', purchaseOptions, '')}
                ${VKModal.select('supplier_id', 'Nhà cung cấp', data.suppliers.map(supplier => ({
                    value: supplier.id,
                    label: `${supplier.code} - ${supplier.name}`,
                })), data.suppliers[0]?.id)}
                ${VKModal.field('quoted_at', 'Ngày báo giá', 'date', new Date().toISOString().slice(0, 10))}
                ${VKModal.field('valid_until', 'Hiệu lực đến', 'date', '')}
            </div>
            <div class="supplier-quotation-lines" data-supplier-quotation-lines>
                ${lineTemplate(0)}
            </div>
            <button class="btn secondary" type="button" data-add-supplier-quotation-line>+ Thêm dòng</button>
            <div class="field">
                <label for="note">Ghi chú</label>
                <textarea id="note" name="note" rows="3" placeholder="Điều kiện thanh toán, thời gian giao hàng, bảo hành..."></textarea>
            </div>
        `, async (form) => {
            const payload = Object.fromEntries(new FormData(form));
            payload.purchase_request_id = payload.purchase_request_id ? Number(payload.purchase_request_id) : null;
            payload.supplier_id = Number(payload.supplier_id);
            payload.lines = collectLines();
            await VKApi.request('/supplier-quotations', {
                method: 'POST',
                body: JSON.stringify(payload),
            });
            VKModal.toast('Đã tạo báo giá nhà cung cấp.');
            window.setTimeout(() => window.location.reload(), 450);
        }, { className: 'modal-wide supplier-quotation-modal', submitText: 'Lưu báo giá' });
    }

    function lineTemplate(index) {
        const skuOptions = data.skus.map(sku => `<option value="${sku.id}">${escape(`${sku.sku_code} - ${sku.name}`)}</option>`).join('');
        return `
            <div class="supplier-quotation-line">
                <label>
                    <span>Mã hàng</span>
                    <select name="lines[${index}][sku_id]">${skuOptions}</select>
                </label>
                <label>
                    <span>Số lượng</span>
                    <input name="lines[${index}][quantity]" type="number" min="0.01" step="0.01" value="1">
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

    function openDetail(id) {
        readData();
        const row = data.supplierQuotations.find(item => String(item.id) === String(id));
        if (!row) {
            VKModal.toast('Không tìm thấy báo giá nhà cung cấp.', 'warning');
            return;
        }

        const lines = (row.lines || []).map((line, index) => `
            <tr>
                <td>${index + 1}</td>
                <td>${escape(line.sku?.sku_code || '-')}</td>
                <td>${escape(line.sku?.name || '-')}</td>
                <td>${formatNumber(line.quantity)}</td>
                <td>${formatMoney(line.unit_price)}</td>
                <td><strong>${formatMoney(line.line_total)}</strong></td>
            </tr>
        `).join('');

        VKModal.open(`Chi tiết báo giá ${row.code}`, `
            <div class="detail-grid two">
                ${detailItem('Nhà cung cấp', row.supplier?.name || '-')}
                ${detailItem('Yêu cầu mua', row.purchase_request?.code || row.purchaseRequest?.code || '-')}
                ${detailItem('Ngày báo giá', formatDate(row.quoted_at))}
                ${detailItem('Hiệu lực', formatDate(row.valid_until))}
                ${detailItem('Trạng thái', statusLabel(row.status))}
                ${detailItem('Tổng tiền', formatMoney(row.total_amount))}
            </div>
            <div class="table-wrap">
                <table class="list-table compact-table">
                    <thead><tr><th>#</th><th>Mã</th><th>Tên hàng</th><th>SL</th><th>Đơn giá</th><th>Thành tiền</th></tr></thead>
                    <tbody>${lines}</tbody>
                </table>
            </div>
            ${row.note ? `<p class="form-note">${escape(row.note)}</p>` : ''}
            <div class="button-row">
                ${row.status === 'draft' ? `<button class="btn primary" type="button" data-select-supplier-quotation="${row.id}">Chọn báo giá này</button>` : ''}
                ${row.status === 'selected' ? `<button class="btn primary" type="button" data-create-po-from-supplier-quotation="${row.id}">Tạo đơn mua</button>` : ''}
            </div>
        `, null, { className: 'modal-wide supplier-quotation-modal', hideSubmit: true, cancelText: 'Đóng' });
    }

    async function selectQuotation(id) {
        if (!confirm('Chọn báo giá này làm căn cứ mua hàng?')) return;

        await VKApi.request(`/supplier-quotations/${id}/select`, { method: 'POST' });
        VKModal.toast('Đã chọn báo giá nhà cung cấp.');
        window.setTimeout(() => window.location.reload(), 450);
    }

    async function createPurchaseOrder(id) {
        await VKApi.request('/purchase-orders', {
            method: 'POST',
            body: JSON.stringify({ supplier_quotation_id: Number(id) }),
        });
        VKModal.toast('Đã tạo đơn mua từ báo giá NCC.');
        VKModal.close();
        window.setTimeout(() => (window.VKLayout?.visit ? VKLayout.visit('/purchase-orders') : window.location.assign('/purchase-orders')), 450);
    }

    function detailItem(label, value) {
        return `<div class="detail-item"><span>${escape(label)}</span><strong>${escape(value)}</strong></div>`;
    }

    function statusLabel(value) {
        return { draft: 'Nháp', selected: 'Đã chọn', rejected: 'Không chọn' }[value] || value || '-';
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
