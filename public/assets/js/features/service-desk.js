(function () {
    let sourceData = { customers: [], users: [] };
    let loadVersion = 0;
    const escape = (value) => VKTable.escapeHtml(String(value ?? ''));
    const formatDate = (value, withTime = false) => value ? new Intl.DateTimeFormat('vi-VN', withTime ? {day:'2-digit',month:'2-digit',year:'numeric',hour:'2-digit',minute:'2-digit'} : {day:'2-digit',month:'2-digit',year:'numeric'}).format(new Date(value)) : '—';
    function data() { return sourceData; }
    function options(rows, label) { return (rows || []).map(row => ({ value: row.id, label: label(row) })); }
    function value(form, name) { return new FormData(form).get(name) || null; }
    function select(name, label, rows) { return VKModal.select(name, label, rows); }
    async function send(url, payload) {
        await VKApi.request(url, { method: 'POST', body: JSON.stringify(payload) });
        VKModal.close();
        await loadPage(true);
    }

    async function loadPage(silent = false) {
        const root = document.querySelector('[data-service-desk-page]');
        if (!root) return;
        const version = ++loadVersion;
        if (!silent) root.innerHTML = '<section class="module-panel"><p class="form-note">Đang tải dữ liệu…</p></section>';
        try {
            const page = root.dataset.serviceDeskPage;
            const [records, lookups] = await Promise.all([
                VKApi.request(page === 'tickets' ? '/service-tickets?page_size=100' : '/warranty-claims?page_size=100', {cache:false}),
                VKApi.request('/service-desk/lookups', {cache:false}),
            ]);
            if (version !== loadVersion || !document.body.contains(root)) return;
            sourceData = lookups.data || {customers:[], users:[]};
            root.innerHTML = page === 'tickets' ? renderTickets(records.data || []) : renderWarranties(records.data || []);
            if (page === 'warranties') bindWarrantyFilters();
        } catch (error) {
            if (version !== loadVersion || !document.body.contains(root)) return;
            root.innerHTML = `<section class="module-panel"><div class="empty-state"><strong>Không tải được dữ liệu</strong><span>${escape(error.message || 'Vui lòng thử lại.')}</span><button class="btn" type="button" data-service-retry>Thử lại</button></div></section>`;
        }
    }

    function renderTickets(rows) {
        const body = rows.length ? rows.map(row => `<tr><td><span class="record-code-chip">${escape(row.code)}</span></td><td>${escape(row.customer?.name || '—')}</td><td>${escape(row.subject)}</td><td>${escape(row.category)}</td><td><span class="badge ${['high','urgent'].includes(row.priority)?'danger':'info'}">${escape(row.priority)}</span></td><td>${formatDate(row.resolution_due_at,true)}</td><td><span class="badge ${['resolved','closed'].includes(row.status)?'success':'warning'}">${escape(row.status)}</span></td></tr>`).join('') : '<tr><td colspan="7"><div class="empty-state"><strong>Chưa có ticket</strong><span>Tạo ticket khi khách hàng cần hỗ trợ hoặc bảo hành.</span></div></td></tr>';
        return `<section class="module-panel"><div class="panel-head"><div><h2>Hàng đợi dịch vụ</h2><span>Ticket đang mở, đang xử lý và chờ phản hồi khách hàng.</span></div><span class="badge info">${rows.length} ticket</span></div><div class="table-wrap"><table class="list-table"><thead><tr><th>Mã</th><th>Khách hàng</th><th>Tiêu đề</th><th>Loại</th><th>Ưu tiên</th><th>SLA xử lý</th><th>Trạng thái</th></tr></thead><tbody>${body}</tbody></table></div></section>`;
    }

    function renderWarranties(rows) {
        const now = new Date();
        const active = rows.filter(row => row.warranty_end_at && new Date(row.warranty_end_at) >= now).length;
        const today = new Date().toISOString().slice(0,10);
        const body = rows.length ? rows.map(row => {
            const isActive = row.warranty_end_at && new Date(row.warranty_end_at) >= now;
            const customer = row.customer_name || row.customer?.name || '—';
            return `<tr data-serial="${escape(String(row.serial_number||'').toLowerCase())}" data-product="${escape(String(row.product_name||'').toLowerCase())}" data-customer="${escape(String(customer).toLowerCase())}" data-purchase="${escape(String(row.purchase_date||'').slice(0,10))}"><td><span class="record-code-chip">${escape(row.code)}</span></td><td><strong>${escape(row.serial_number)}</strong></td><td>${escape(row.product_name)}</td><td><strong>${escape(customer)}</strong><br><small>${escape(row.customer_email || '')}</small></td><td>${formatDate(row.purchase_date)}</td><td>${formatDate(row.warranty_end_at)}<br><small>${isActive?'Còn hiệu lực':'Đã hết hạn'}</small></td><td><span class="badge ${isActive?'success':'warning'}">${isActive?'Còn hiệu lực':'Hết hạn'}</span></td></tr>`;
        }).join('') : '<tr><td colspan="7"><div class="empty-state"><strong>Chưa có đăng ký bảo hành</strong><span>Thêm serial đầu tiên để bắt đầu quản lý.</span></div></td></tr>';
        return `<section class="service-warranty-stats"><article><span>Tổng bảo hành</span><strong>${rows.length}</strong></article><article><span>Còn hiệu lực</span><strong>${active}</strong></article><article><span>Hết hạn</span><strong>${rows.length-active}</strong></article><article><span>Đăng ký hôm nay</span><strong>${rows.filter(row=>String(row.created_at||'').slice(0,10)===today).length}</strong></article></section><section class="module-panel"><div class="panel-head"><div><h2>Danh sách bảo hành</h2><span>Tìm nhanh theo serial, sản phẩm hoặc khách hàng.</span></div></div><div class="warranty-filter-grid" data-warranty-filter><div class="field"><label>Số serial</label><input id="warrantyFilterSerial" placeholder="Nhập số serial cần tìm..."></div><div class="field"><label>Sản phẩm / Model</label><input id="warrantyFilterProduct" placeholder="Nhập model / tên sản phẩm..."></div><div class="field"><label>Khách hàng</label><input id="warrantyFilterCustomer" placeholder="Nhập tên khách hàng..."></div><div class="field"><label>Ngày mua</label><input id="warrantyFilterPurchase" type="date"></div><div class="warranty-filter-actions"><button class="btn primary" type="button" data-warranty-search>Tìm kiếm</button><button class="btn secondary" type="button" data-warranty-reset>Làm mới</button></div></div><div class="table-wrap"><table class="list-table"><thead><tr><th>Mã</th><th>Số serial</th><th>Sản phẩm / Model</th><th>Khách hàng</th><th>Ngày bán</th><th>Hết hạn</th><th>Trạng thái</th></tr></thead><tbody data-warranty-list>${body}</tbody></table></div></section>`;
    }
    function createTicket() {
        const source = data(); const customers = options(source.customers, r => `${r.code} - ${r.name}`); const users = [{ value: '', label: 'Chưa phân công' }, ...options(source.users, r => r.name)];
        VKModal.open('Tạo ticket hỗ trợ', [select('customer_id', 'Khách hàng', customers), VKModal.field('serial_number', 'Số serial (nếu có)'), VKModal.field('product_name', 'Tên sản phẩm / thiết bị'), select('assignee_id', 'Người xử lý', users), VKModal.field('subject', 'Tiêu đề'), '<div class="field"><label for="description">Mô tả yêu cầu</label><textarea id="description" name="description" rows="4"></textarea></div>', select('category', 'Loại yêu cầu', [{value:'support',label:'Hỗ trợ'}, {value:'warranty',label:'Bảo hành'}, {value:'installation',label:'Lắp đặt'}, {value:'maintenance',label:'Bảo trì'}]), select('priority', 'Ưu tiên', [{value:'normal',label:'Bình thường'}, {value:'low',label:'Thấp'}, {value:'high',label:'Cao'}, {value:'urgent',label:'Khẩn cấp'}])].join(''), async form => send('/service-tickets', { customer_id: Number(value(form,'customer_id')), serial_number:value(form,'serial_number'), product_name:value(form,'product_name'), assignee_id:value(form,'assignee_id') ? Number(value(form,'assignee_id')) : null, subject:value(form,'subject'), description:value(form,'description'), category:value(form,'category'), priority:value(form,'priority'), source:'manual' }));
    }
    function createWarranty() {
        const today = new Date().toISOString().slice(0, 10);
        const content = [
            '<div class="serial-mode-actions warranty-full-row"><button type="button" class="btn primary" data-warranty-serial-mode="single">Nhập 1 serial</button><button type="button" class="btn secondary" data-warranty-serial-mode="multiple">Nhập nhiều serial</button></div><div class="field"><label for="serial_number">Số serial <em>*</em></label><input id="serial_number" name="serial_number" placeholder="Ví dụ: SN123456789"><textarea id="serial_numbers" name="serial_numbers" rows="3" placeholder="Mỗi serial một dòng hoặc cách nhau bằng dấu phẩy" hidden></textarea><small>Serial được dùng để tra cứu và quản lý bảo hành.</small></div>',
            '<div class="field"><label for="product_name">Tên sản phẩm / Model <em>*</em></label><input id="product_name" name="product_name" placeholder="Ví dụ: K50PRO, K20PRO..."><small>Gõ tự do tên sản phẩm hoặc model.</small></div>',
            '<div class="field"><label for="tax_code">Mã số thuế</label><input id="tax_code" name="tax_code" placeholder="Không bắt buộc"><small data-tax-hint>Nhập MST để tự điền khách hàng đã có trong CRM.</small></div>',
            '<div class="modal-grid"><div class="field"><label for="customer_name">Tên khách hàng <em>*</em></label><input id="customer_name" name="customer_name"></div><div class="field"><label for="customer_phone">Số điện thoại</label><input id="customer_phone" name="customer_phone"></div></div>',
            '<div class="field"><label for="customer_email">Email</label><input id="customer_email" name="customer_email" type="email"></div><div class="field warranty-full-row"><label for="customer_address">Địa chỉ</label><textarea id="customer_address" name="customer_address" rows="2"></textarea></div>',
            `<div class="modal-grid warranty-dates"><div class="field"><label for="purchase_date">Ngày nhập hàng</label><input id="purchase_date" name="purchase_date" type="date"></div><div class="field"><label for="warranty_start_at">Ngày bắt đầu bảo hành <em>*</em></label><input id="warranty_start_at" name="warranty_start_at" type="date" value="${today}"></div><div class="field"><label for="warranty_months">Thời hạn bảo hành (tháng) <em>*</em></label><input id="warranty_months" name="warranty_months" type="number" min="1" max="120" value="12"></div></div>`
        ].join('');
        VKModal.open('Thông tin bảo hành', `<div class="warranty-form-layout">${content}</div>`, async form => {
            const multiple = !form.querySelector('#serial_numbers').hidden;
            const raw = multiple ? value(form, 'serial_numbers') : value(form, 'serial_number');
            const serials = [...new Set(String(raw || '').split(/[\n,;]+/).map(s => s.trim()).filter(Boolean))];
            if (!serials.length) throw new Error('Vui lòng nhập ít nhất một số serial.');
            return send('/warranty-claims', { serial_numbers: serials, product_name:value(form,'product_name'), tax_code:value(form,'tax_code'), customer_name:value(form,'customer_name'), customer_phone:value(form,'customer_phone'), customer_email:value(form,'customer_email'), customer_address:value(form,'customer_address'), purchase_date:value(form,'purchase_date'), warranty_start_at:value(form,'warranty_start_at'), warranty_months:Number(value(form,'warranty_months')) });
        }, { className: 'warranty-register-modal', submitText: 'Đăng ký bảo hành' });
        const modal = document.querySelector('.modal, [role="dialog"]') || document;
        modal.querySelectorAll('[data-warranty-serial-mode]').forEach(button => button.addEventListener('click', () => { const many = button.dataset.warrantySerialMode === 'multiple'; modal.querySelector('#serial_number').hidden = many; modal.querySelector('#serial_numbers').hidden = !many; modal.querySelectorAll('[data-warranty-serial-mode]').forEach(b => b.className = `btn ${b === button ? 'primary' : 'secondary'}`); }));
        const tax = modal.querySelector('#tax_code'); tax?.addEventListener('change', async () => { if (!tax.value.trim()) return; try { const result = await VKApi.request(`/warranty-customers/lookup/${encodeURIComponent(tax.value.trim())}`); const c = result.data; if (!c) return; ['name','phone','email','address'].forEach(key => { const field = modal.querySelector(`#customer_${key}`); if (field) field.value = c[key] || (key === 'name' ? c.contact_name : ''); }); const hint = modal.querySelector('[data-tax-hint]'); if (hint) hint.textContent = `Đã tìm thấy khách hàng ${c.code}.`; } catch (_) {} });
    }
    function bindWarrantyFilters() {
        const root = document.querySelector('[data-warranty-filter]'); if (!root || root.dataset.bound) return; root.dataset.bound = '1'; const fields = { serial: root.querySelector('#warrantyFilterSerial'), product: root.querySelector('#warrantyFilterProduct'), customer: root.querySelector('#warrantyFilterCustomer'), purchase: root.querySelector('#warrantyFilterPurchase') };
        const search = () => document.querySelectorAll('[data-warranty-list] tr[data-serial]').forEach(row => { const show = ['serial','product','customer'].every(key => !fields[key].value || row.dataset[key].includes(fields[key].value.trim().toLowerCase())) && (!fields.purchase.value || row.dataset.purchase === fields.purchase.value); row.hidden = !show; });
        root.querySelector('[data-warranty-search]').addEventListener('click', search); root.querySelector('[data-warranty-reset]').addEventListener('click', () => { Object.values(fields).forEach(input => input.value = ''); search(); }); Object.values(fields).forEach(input => input.addEventListener('keydown', event => { if (event.key === 'Enter') { event.preventDefault(); search(); } }));
    }
    function bind() {
        document.querySelectorAll('[data-service-create]').forEach(button => { if (button.dataset.bound) return; button.dataset.bound = '1'; button.addEventListener('click', () => ({ticket:createTicket,warranty:createWarranty}[button.dataset.serviceCreate] || (()=>{}))()); });
        const root = document.querySelector('[data-service-desk-page]');
        if (root && !root.dataset.loaded) { root.dataset.loaded = '1'; loadPage(); }
    }
    document.addEventListener('click', event => { if (event.target.closest('[data-service-retry]')) loadPage(); });
    window.loadServiceTickets = bind; window.loadWarrantyClaims = bind; document.addEventListener('vk:ready', bind); bind();
})();
