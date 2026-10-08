(function () {
    let state = { rows: [], q: '' };

    document.addEventListener('vk:ready', loadContracts);
    document.addEventListener('input', event => {
        if (document.getElementById('contractsRoot') && event.target.matches('[data-list-search]')) {
            state.q = event.target.value.toLowerCase();
            render();
        }
    });
    document.addEventListener('click', event => {
        if (!document.getElementById('contractsRoot')) return;
        if (event.target.matches('[data-contract-create]')) openCreate();
        const open = event.target.closest('[data-contract-open]');
        if (open) openDetail(open.dataset.contractOpen);
    });
    document.addEventListener('click', handleContractAction);

    async function loadContracts() {
        const root = document.getElementById('contractsRoot');
        if (!root) return;
        try {
            const response = await VKApi.request('/contracts?page_size=100');
            state.rows = response.data || [];
            render();
        } catch (error) {
            root.innerHTML = `<section class="list-page"><div class="empty"><strong>Chưa tải được danh sách hợp đồng</strong><span>${VKTable.escapeHtml(error.message || 'Vui lòng tải lại trang.')}</span></div></section>`;
        }
    }

    function render() {
        const root = document.getElementById('contractsRoot');
        if (!root) return;
        const rows = state.rows.filter(row => `${row.code} ${row.name} ${row.customer?.name || ''}`.toLowerCase().includes(state.q));
        root.innerHTML = VKTable.fullList({
            title: 'Danh sách hợp đồng',
            subtitle: 'Tạo hợp đồng từ báo giá hoặc đơn hàng; theo dõi từng mốc đến nghiệm thu.',
            meta: `${rows.length} hợp đồng`,
            filters: VKTable.filterBar({ searchPlaceholder: 'Tìm số hợp đồng, tên hoặc khách hàng...' }),
            table: VKTable.renderTable([
                { label: 'Hợp đồng', render: row => `<strong>${VKTable.escapeHtml(row.name)}</strong><span class="row-note mono">${VKTable.escapeHtml(row.code)}</span>` },
                { label: 'Khách hàng', render: row => VKTable.escapeHtml(row.customer?.name || '-') },
                { label: 'Giá trị', render: row => `<strong>${VKTable.money(row.total_amount || 0)}</strong>` },
                { label: 'Hiệu lực', render: row => `${date(row.effective_date)} → ${date(row.expiry_date)}` },
                { label: 'Trạng thái', render: row => VKTable.statusBadge(row.status) },
                { label: '', render: row => VKTable.rowActions([VKTable.smallButton('Mở', `data-contract-open="${row.id}"`)]) },
            ], rows, 'Chưa có hợp đồng'),
        });
        const search = document.querySelector('[data-list-search]');
        if (search) search.value = state.q;
    }

    async function openCreate() {
        const [quotes, orders, customers] = await Promise.all([
            VKApi.request('/quotations?page_size=100'),
            VKApi.request('/sales-orders?page_size=100'),
            VKApi.request('/customers?page_size=100'),
        ]);
        const customerOptions = [{ value: '', label: 'Tự lấy từ báo giá / đơn hàng' }, ...(customers.data || []).map(row => ({ value: row.id, label: `${row.code} - ${row.name}` }))];
        const quoteOptions = [{ value: '', label: 'Không chọn' }, ...(quotes.data || []).map(row => ({ value: row.id, label: `${row.code} - ${row.customer?.name || ''}` }))];
        const orderOptions = [{ value: '', label: 'Không chọn' }, ...(orders.data || []).map(row => ({ value: row.id, label: `${row.code} - ${row.customer?.name || ''}` }))];
        const form = `<div class="form-grid">
            ${VKModal.field('name', 'Tên hợp đồng')}
            ${VKModal.select('customer_id', 'Khách hàng', customerOptions)}
            ${VKModal.select('quotation_id', 'Báo giá tham chiếu', quoteOptions)}
            ${VKModal.select('sales_order_id', 'Đơn hàng tham chiếu', orderOptions)}
            ${VKModal.field('effective_date', 'Ngày hiệu lực', 'date')}
            ${VKModal.field('expiry_date', 'Ngày hết hạn', 'date')}
            <div class="field full"><label for="note">Ghi chú</label><textarea id="note" name="note" rows="3" placeholder="Phạm vi hợp đồng, điều kiện đặc biệt..."></textarea></div>
        </div><div class="contract-milestone-note">Sau khi tạo, vào chi tiết hợp đồng để xem và nghiệm thu các mốc thanh toán.</div>`;
        VKModal.open('Tạo hợp đồng', form, async modalForm => {
            const data = Object.fromEntries(new FormData(modalForm));
            ['customer_id', 'quotation_id', 'sales_order_id'].forEach(key => { data[key] = data[key] ? Number(data[key]) : null; });
            await VKApi.request('/contracts', { method: 'POST', body: JSON.stringify(data) });
            VKModal.close();
            VKModal.toast('Đã tạo hợp đồng nháp.');
            loadContracts();
        }, { submitText: 'Tạo hợp đồng', className: 'contract-modal' });
    }

    async function openDetail(id) {
        const response = await VKApi.request(`/contracts/${id}`);
        const contract = response.data;
        VKModal.open(`${contract.code} · ${contract.name}`, contractDetailHtml(contract), async () => {}, { hideSubmit: true, cancelText: 'Đóng', className: 'contract-detail-modal' });
    }

    function contractDetailHtml(contract) {
        const milestones = contract.milestones || [];
        const milestoneHtml = milestones.length
            ? `<div class="contract-milestones">${milestones.map((milestone, index) => milestoneHtmlFor(contract, milestone, index)).join('')}</div>`
            : '<div class="empty compact"><strong>Chưa có mốc thanh toán</strong><span>Hiện hợp đồng chưa khai báo mốc.</span></div>';
        const activateButton = contract.status === 'draft'
            ? `<button class="btn primary contract-primary-action" type="button" data-contract-activate="${contract.id}">▷ Kích hoạt hợp đồng</button>`
            : '';
        return `<article class="contract-record">
            <header class="contract-record-head">
                <div class="contract-record-icon">▧</div>
                <div class="contract-record-title"><span>Hợp đồng ${VKTable.escapeHtml(contract.code)}</span><strong>${VKTable.escapeHtml(contract.name)}</strong></div>
                <div class="contract-record-state">${VKTable.statusBadge(contract.status === 'draft' ? 'draft' : contract.status)}</div>
            </header>
            <section class="contract-overview-grid">
                <div><span>Khách hàng</span><strong>${VKTable.escapeHtml(contract.customer?.name || '-')}</strong></div>
                <div><span>Giá trị</span><strong>${VKTable.money(contract.total_amount || 0)}</strong></div>
                <div><span>Trạng thái</span>${VKTable.statusBadge(contract.status)}</div>
            </section>
            <section class="contract-milestone-section">
                <div class="contract-section-title"><h3>Mốc thanh toán và nghiệm thu</h3><span>${milestones.length} mốc</span></div>
                ${milestoneHtml}
            </section>
            <section class="contract-meta-grid">
                <div><span>▣ Ngày tạo</span><strong>${date(contract.created_at)}</strong></div>
                <div><span>♙ Người tạo</span><strong>${VKTable.escapeHtml(contract.owner?.name || '-')}</strong></div>
                <div><span># Mã hợp đồng</span><strong>${VKTable.escapeHtml(contract.code)}</strong></div>
            </section>
            <footer class="contract-record-actions">
                <div class="contract-document-actions">
                    <button class="btn secondary" type="button" data-contract-print="${contract.id}">◉ Xem / in</button>
                    <button class="btn secondary" type="button" data-contract-word="${contract.id}">⇩ Tải Word</button>
                </div>
                ${activateButton}
            </footer>
        </article>`;
    }

    function milestoneHtmlFor(contract, milestone, index) {
        const action = milestone.status === 'accepted'
            ? VKTable.statusBadge('accepted')
            : `<button class="btn small" type="button" data-contract-accept="${contract.id}" data-milestone="${milestone.id}" ${contract.status !== 'active' ? 'disabled' : ''}>Nghiệm thu</button>`;
        return `<div><span class="contract-milestone-index">${milestone.status === 'accepted' ? '✓' : index + 1}</span><span><strong>${VKTable.escapeHtml(milestone.name)}</strong><small>${VKTable.money(milestone.amount)} · Hạn ${date(milestone.due_date)}</small></span>${action}</div>`;
    }

    async function handleContractAction(event) {
        const activate = event.target.closest('[data-contract-activate]');
        const accept = event.target.closest('[data-contract-accept]');
        const print = event.target.closest('[data-contract-print]');
        const word = event.target.closest('[data-contract-word]');
        if (activate) {
            await VKApi.request(`/contracts/${activate.dataset.contractActivate}/activate`, { method: 'POST' });
            VKModal.close();
            VKModal.toast('Hợp đồng đã có hiệu lực.');
            loadContracts();
        }
        if (accept) {
            await VKApi.request(`/contracts/${accept.dataset.contractAccept}/milestones/${accept.dataset.milestone}/accept`, { method: 'POST' });
            VKModal.close();
            VKModal.toast('Đã nghiệm thu mốc thanh toán.');
            loadContracts();
        }
        if (print) await openContractPrint(print.dataset.contractPrint);
        if (word) await downloadContractWord(word.dataset.contractWord);
    }

    async function openContractPrint(id) {
        const popup = window.open('', '_blank', 'width=980,height=720');
        if (!popup) {
            VKModal.toast('Trình duyệt đang chặn cửa sổ in. Vui lòng cho phép popup để tiếp tục.', 'warning');
            return;
        }

        popup.document.write('<p style="font-family:Arial;padding:24px">Đang chuẩn bị hợp đồng...</p>');
        const [contractResponse, templateResponse] = await Promise.all([
            VKApi.request(`/contracts/${id}`),
            VKApi.request('/print-templates/active?module=contract', { cache: false }),
        ]);
        popup.document.open();
        popup.document.write(contractDocumentHtml(contractResponse.data, templateResponse.data));
        popup.document.close();
    }

    async function downloadContractWord(id) {
        const response = await fetch(`/api/v1/contracts/${encodeURIComponent(id)}/word`, {
            headers: {
                Accept: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                Authorization: `Bearer ${VKApi.token()}`,
            },
        });
        if (!response.ok) {
            const message = await response.json().then(body => body.message).catch(() => 'Không thể xuất file Word.');
            throw new Error(message || 'Không thể xuất file Word.');
        }
        const contract = await VKApi.request(`/contracts/${id}`);
        const blob = await response.blob();
        const link = document.createElement('a');
        link.href = URL.createObjectURL(blob);
        link.download = `${safeFileName(contract.data.code || 'hop-dong')}.docx`;
        document.body.appendChild(link);
        link.click();
        link.remove();
        URL.revokeObjectURL(link.href);
        VKModal.toast('Đã tải file Word theo mẫu hợp đồng.', 'success');
    }

    function contractDocumentHtml(contract, template) {
        const customer = contract.customer || {};
        const value = Number(contract.total_amount || 0);
        const values = {
            ma_chung_tu: contract.code,
            ngay_chung_tu: date(contract.created_at),
            ma_hop_dong: contract.code,
            ten_hop_dong: contract.name,
            ngay_hop_dong: date(contract.created_at),
            ngay_hieu_luc: date(contract.effective_date),
            ngay_het_han: date(contract.expiry_date),
            ten_khach_hang: customer.name || '',
            ma_khach_hang: customer.code || '',
            nguoi_lien_he: customer.contact_name || '',
            so_dien_thoai: customer.phone || '',
            email: customer.email || '',
            ten_cong_ty: customer.name || '',
            ma_so_thue: customer.tax_code || '',
            dia_chi_cong_ty: customer.billing_address || customer.address || '',
            dia_chi_giao_hang: customer.address || customer.billing_address || '',
            ma_bao_gia: contract.quotation?.code || '',
            ma_don_hang: contract.sales_order?.code || '',
            gia_tri_hop_dong: VKTable.money(value),
            gia_tri_bang_chu: moneyInWords(value),
            tong_cong: VKTable.money(value),
            tong_tien_bang_chu: moneyInWords(value),
            dieu_khoan: contract.note || 'Các bên thực hiện theo các điều khoản đã thống nhất.',
            moc_thanh_toan: (contract.milestones || []).map(item => `${item.name}: ${VKTable.money(item.amount)} · hạn ${date(item.due_date)}`).join('<br>') || 'Theo thỏa thuận của hai bên.',
            nguoi_lap: contract.owner?.name || '',
        };
        const source = template?.content_html || `<section class="doc-header"><div><p class="muted">HỢP ĐỒNG</p><h1>{{ma_hop_dong}}</h1><p><strong>{{ten_hop_dong}}</strong></p></div><div class="doc-meta"><span>Ngày lập</span><strong>{{ngay_hop_dong}}</strong></div></section><section class="doc-grid"><div class="doc-card"><h2>Bên A</h2><p><strong>{{ten_cong_ty}}</strong></p><p>Mã số thuế: {{ma_so_thue}}</p><p>Địa chỉ: {{dia_chi_cong_ty}}</p></div><div class="doc-card"><h2>Bên B</h2><p><strong>{{ten_khach_hang}}</strong></p><p>Người liên hệ: {{nguoi_lien_he}}</p><p>Địa chỉ: {{dia_chi_giao_hang}}</p></div></section><section class="doc-card"><h2>Giá trị và thời hạn</h2><p>Giá trị hợp đồng: <strong>{{gia_tri_hop_dong}}</strong></p><p>Bằng chữ: {{gia_tri_bang_chu}}</p><p>Hiệu lực: {{ngay_hieu_luc}} đến {{ngay_het_han}}</p></section><section class="doc-card"><h2>Mốc thanh toán</h2><p>{{moc_thanh_toan}}</p></section><section class="doc-card"><h2>Điều khoản khác</h2><p>{{dieu_khoan}}</p></section>`;
        const body = source.replace(/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/g, (match, key) => {
            const valueForKey = values[key] ?? '';
            return key === 'moc_thanh_toan' ? valueForKey : VKTable.escapeHtml(String(valueForKey));
        });
        return `<!doctype html><html lang="vi"><head><meta charset="utf-8"><title>Hợp đồng ${VKTable.escapeHtml(contract.code)}</title><style>body{font:14px/1.55 Arial,sans-serif;color:#172033;max-width:900px;margin:28px auto;padding:0 26px}.doc-header{display:grid;grid-template-columns:1fr auto;gap:24px;padding-bottom:18px;border-bottom:2px solid #2563eb}.doc-header h1{margin:2px 0;font-size:28px}.muted,.doc-meta span{color:#64748b}.doc-meta{text-align:right}.doc-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin:18px 0}.doc-card{border:1px solid #dbe3ef;border-radius:9px;padding:14px 16px;margin:16px 0}.doc-card h2{font-size:16px;margin:0 0 10px}.doc-card p{margin:5px 0}.no-print{text-align:right;margin-bottom:14px}.no-print button{padding:9px 14px;border:1px solid #bfdbfe;border-radius:8px;background:#fff;color:#1d4ed8;cursor:pointer}@media print{.no-print{display:none}body{margin:0;max-width:none}.doc-card{break-inside:avoid}}</style></head><body><div class="no-print"><button onclick="window.print()">In hoặc lưu PDF</button></div>${body}</body></html>`;
    }

    function safeFileName(value) {
        return String(value).replace(/[^a-z0-9_-]+/gi, '-').replace(/^-+|-+$/g, '') || 'hop-dong';
    }

    function moneyInWords(value) {
        return value ? `${VKTable.money(value)} (theo giá trị trên hợp đồng)` : 'Không đồng';
    }

    function date(value) {
        return value ? new Date(value).toLocaleDateString('vi-VN') : '-';
    }

    window.loadContracts = loadContracts;
    if (document.readyState !== 'loading') loadContracts();
})();
