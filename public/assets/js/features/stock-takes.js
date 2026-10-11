(function () {
    const can = (permission) => window.VKLayout?.hasPermission?.(permission) === true;
    let data = { stockTakes: [], warehouses: [], skus: [] };

    window.loadStockTakes = loadStockTakes;
    document.addEventListener('vk:ready', loadStockTakes);

    document.addEventListener('click', (event) => {
        if (!document.getElementById('stockTakesRoot')) return;

        if (event.target.closest('[data-create-stock-take]')) {
            openCreateModal();
            return;
        }

        const openButton = event.target.closest('[data-open-stock-take]');
        if (openButton) {
            openDetail(openButton.dataset.openStockTake);
            return;
        }

        const confirmButton = event.target.closest('[data-confirm-stock-take]');
        if (confirmButton) {
            confirmStockTake(confirmButton.dataset.confirmStockTake);
        }
    });

    async function loadStockTakes() {
        const root = document.getElementById('stockTakesRoot');
        if (!root) return;
        try {
            const [stockTakes, warehouses, skus] = await Promise.all([
                VKApi.request('/stock-takes?page_size=100', {cache:false}),
                VKApi.request('/warehouses?page_size=100', {cache:false}),
                VKApi.request('/skus?page_size=100', {cache:false}),
            ]);
            data = {stockTakes:stockTakes.data||[],warehouses:(warehouses.data||[]).filter(row=>row.status==='active'),skus:(skus.data||[]).filter(row=>row.status==='active')};
            root.innerHTML = renderList(stockTakes.meta?.total || data.stockTakes.length);
        } catch (error) {
            data = { stockTakes: [], warehouses: [], skus: [] };
            root.innerHTML = `<section class="module-panel"><p class="form-note">${escape(error.message || 'Không thể tải dữ liệu kiểm kê.')}</p></section>`;
        }
    }

    function renderList(total) {
        const rows=data.stockTakes.map(row=>`<tr><td><span class="record-code-chip">${escape(row.code)}</span></td><td>${escape(row.warehouse?.name||'-')}</td><td>${formatDate(row.counted_at)}</td><td>${formatNumber(row.lines_count||0)}</td><td>—</td><td><span class="badge ${row.status==='confirmed'?'success':'info'}">${row.status==='confirmed'?'Đã xác nhận':'Nháp'}</span></td><td>${escape(row.confirmer?.name||'-')}</td><td class="actions-cell"><details class="row-action-menu"><summary aria-label="Mở thao tác">⋮</summary><div class="row-action-menu-list"><button class="btn" type="button" data-open-stock-take="${row.id}">Mở</button>${row.status==='draft'&&can('inventory.stocktake.confirm')?`<button class="btn primary" type="button" data-confirm-stock-take="${row.id}">Xác nhận</button>`:''}</div></details></td></tr>`).join('');
        return `<section class="module-panel"><div class="panel-head"><div><h2>Danh sách phiếu kiểm kê</h2><span>Phiếu nháp chưa ảnh hưởng tồn kho. Chỉ khi xác nhận mới tạo giao dịch điều chỉnh.</span></div><span class="badge info">${total} phiếu</span></div><div class="table-wrap"><table class="list-table"><thead><tr><th>Mã phiếu</th><th>Kho</th><th>Ngày kiểm kê</th><th>Số dòng</th><th>Lệch tồn</th><th>Trạng thái</th><th>Người xác nhận</th><th></th></tr></thead><tbody>${rows||'<tr><td colspan="8"><div class="empty-state"><strong>Chưa có phiếu kiểm kê</strong><span>Tạo phiếu để đối chiếu tồn thực tế với tồn hệ thống.</span></div></td></tr>'}</tbody></table></div></section>`;
    }

    function openCreateModal() {
        if (!data.warehouses.length || !data.skus.length) {
            VKModal.toast('Cần có kho và mã hàng trước khi tạo phiếu kiểm kê.', 'warning');
            return;
        }

        VKModal.open('Tạo phiếu kiểm kê', `
            <div class="form-grid two">
                ${VKModal.select('warehouse_id', 'Kho kiểm kê', data.warehouses.map(warehouse => ({
                    value: warehouse.id,
                    label: `${warehouse.code} - ${warehouse.name}`,
                })), data.warehouses[0]?.id)}
                ${VKModal.field('counted_at', 'Ngày kiểm kê', 'date', new Date().toISOString().slice(0, 10))}
            </div>
            <div class="stock-take-lines" data-stock-take-lines>
                ${lineTemplate(0)}
            </div>
            <button class="btn secondary" type="button" data-add-stock-take-line>+ Thêm dòng</button>
            <div class="field">
                <label for="note">Ghi chú</label>
                <textarea id="note" name="note" rows="3" placeholder="Ghi chú kiểm kê, khu vực đếm, người phụ trách..."></textarea>
            </div>
        `, async (form) => {
            const payload = Object.fromEntries(new FormData(form));
            const formData = new FormData(form);
            payload.warehouse_id = Number(payload.warehouse_id);
            payload.lines = collectLines(formData);
            await VKApi.request('/stock-takes', {
                method: 'POST',
                body: JSON.stringify(payload),
            });
            VKModal.toast('Đã tạo phiếu kiểm kê.');
            VKModal.close();
            await loadStockTakes();
        }, { className: 'wide-modal stock-take-modal', submitText: 'Lưu phiếu' });
    }

    document.addEventListener('click', (event) => {
        if (event.target.closest('[data-add-stock-take-line]')) {
            const box = document.querySelector('[data-stock-take-lines]');
            if (!box) return;
            box.insertAdjacentHTML('beforeend', lineTemplate(box.querySelectorAll('.stock-take-line').length));
        }

        const remove = event.target.closest('[data-remove-stock-take-line]');
        if (remove) {
            const rows = document.querySelectorAll('.stock-take-line');
            if (rows.length <= 1) {
                VKModal.toast('Phiếu kiểm kê cần ít nhất một dòng hàng.', 'warning');
                return;
            }
            remove.closest('.stock-take-line')?.remove();
        }
    });

    function lineTemplate(index) {
        const skuOptions = data.skus.map(sku => `<option value="${sku.id}">${escape(`${sku.sku_code} - ${sku.name}`)}</option>`).join('');
        return `
            <div class="stock-take-line">
                <label>
                    <span>Mã hàng</span>
                    <select name="lines[${index}][sku_id]">${skuOptions}</select>
                </label>
                <label>
                    <span>Lô</span>
                    <input name="lines[${index}][lot_no]" type="text" placeholder="Không bắt buộc">
                </label>
                <label>
                    <span>Số lượng thực tế</span>
                    <input name="lines[${index}][counted_quantity]" type="number" min="0" step="0.01" value="0">
                </label>
                <label>
                    <span>Ghi chú</span>
                    <input name="lines[${index}][note]" type="text" placeholder="Lý do lệch">
                </label>
                <button class="btn danger" type="button" data-remove-stock-take-line>×</button>
            </div>
        `;
    }

    function collectLines(formData) {
        const rows = [];
        document.querySelectorAll('.stock-take-line').forEach((row, index) => {
            const skuId = formData.get(`lines[${index}][sku_id]`) || row.querySelector('[name$="[sku_id]"]')?.value;
            const lotNo = formData.get(`lines[${index}][lot_no]`) || row.querySelector('[name$="[lot_no]"]')?.value || '';
            const counted = formData.get(`lines[${index}][counted_quantity]`) || row.querySelector('[name$="[counted_quantity]"]')?.value || '0';
            const note = formData.get(`lines[${index}][note]`) || row.querySelector('[name$="[note]"]')?.value || '';
            rows.push({
                sku_id: Number(skuId),
                lot_no: lotNo || null,
                counted_quantity: Number(counted || 0),
                note,
            });
        });
        return rows;
    }

    async function openDetail(id) {
        let row;
        try { row=(await VKApi.request(`/stock-takes/${id}`,{cache:false})).data; }
        catch(error){ VKModal.toast(error.message,'danger');return; }

        const rows = (row.lines || []).map((line, index) => `
            <tr>
                <td>${index + 1}</td>
                <td>${escape(line.sku?.sku_code || '-')}</td>
                <td>${escape(line.sku?.name || '-')}</td>
                <td>${formatNumber(line.system_quantity)}</td>
                <td>${formatNumber(line.counted_quantity)}</td>
                <td><strong class="${Number(line.difference_quantity) < 0 ? 'text-danger' : 'text-success'}">${formatSigned(line.difference_quantity)}</strong></td>
                <td>${escape(line.note || '-')}</td>
            </tr>
        `).join('');

        VKModal.open(`Chi tiết kiểm kê ${row.code}`, `
            <div class="detail-grid two">
                ${detailItem('Kho', row.warehouse?.name || '-')}
                ${detailItem('Ngày kiểm kê', formatDate(row.counted_at))}
                ${detailItem('Trạng thái', row.status === 'confirmed' ? 'Đã xác nhận' : 'Nháp')}
                ${detailItem('Người xác nhận', row.confirmer?.name || '-')}
            </div>
            <div class="table-wrap">
                <table class="list-table compact-table">
                    <thead><tr><th>#</th><th>Mã</th><th>Tên hàng</th><th>Hệ thống</th><th>Thực tế</th><th>Lệch</th><th>Ghi chú</th></tr></thead>
                    <tbody>${rows}</tbody>
                </table>
            </div>
            ${row.status === 'draft' && can('inventory.stocktake.confirm') ? `<button class="btn primary" type="button" data-confirm-stock-take="${row.id}">Xác nhận điều chỉnh kho</button>` : ''}
        `, null, { className: 'wide-modal', hideSubmit: true, cancelText: 'Đóng' });
    }

    async function confirmStockTake(id) {
        if (!confirm('Xác nhận phiếu kiểm kê và cập nhật tồn kho?')) return;

        await VKApi.request(`/stock-takes/${id}/confirm`, { method: 'POST' });
        VKModal.toast('Đã xác nhận kiểm kê và cập nhật tồn kho.');
        VKModal.close();
        await loadStockTakes();
    }

    function detailItem(label, value) {
        return `<div class="detail-item"><span>${escape(label)}</span><strong>${escape(value)}</strong></div>`;
    }

    function formatDate(value) {
        if (!value) return '-';
        return new Date(value).toLocaleDateString('vi-VN');
    }

    function formatNumber(value) {
        return Number(value || 0).toLocaleString('vi-VN', { maximumFractionDigits: 2 });
    }

    function formatSigned(value) {
        const number = Number(value || 0);
        return `${number > 0 ? '+' : ''}${formatNumber(number)}`;
    }

    function escape(value) {
        return VKTable.escapeHtml(value ?? '');
    }
})();
