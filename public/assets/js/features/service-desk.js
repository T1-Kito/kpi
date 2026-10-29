(function () {
    let sourceData = { customers: [], users: [] };
    let loadVersion = 0;
    const ticketFilters={status:'',priority:'',assignee_id:'',search:'',overdue:'0',page:1};
    const escape = (value) => VKTable.escapeHtml(String(value ?? ''));
    const formatDate = (value, withTime = false) => value ? new Intl.DateTimeFormat('vi-VN', withTime ? {day:'2-digit',month:'2-digit',year:'numeric',hour:'2-digit',minute:'2-digit'} : {day:'2-digit',month:'2-digit',year:'numeric'}).format(new Date(value)) : '—';
    function data() { return sourceData; }
    function options(rows, label) { return (rows || []).map(row => ({ value: row.id, label: label(row) })); }
    function value(form, name) { return new FormData(form).get(name) || null; }
    function select(name, label, rows) { return VKModal.select(name, label, rows); }
    async function send(url, payload) {
        if (url === '/service-tickets') {
            const documentType = document.querySelector('[data-feedback-document-type]')?.value;
            const documentId = document.querySelector('[data-feedback-document-id]')?.value;
            if (documentType && !documentId) throw new Error('Vui lòng chọn chứng từ liên quan hoặc chọn Không liên kết.');
            payload.document_type = documentType || null;
            payload.document_id = documentId ? Number(documentId) : null;
            payload.related_document = document.querySelector('[data-ticket-document]')?.value || null;
            payload.source = document.querySelector('[data-ticket-source]')?.value || 'manual';
            const date = document.querySelector('[data-ticket-deadline]')?.value;
            if (date) {
                const parsed = new Date(date);
                if (!Number.isFinite(parsed.getTime()) || parsed.getTime() <= Date.now()) throw new Error('Hạn xử lý phải sau thời điểm hiện tại.');
                payload.resolution_due_at = parsed.toISOString();
            }
        }
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
                VKApi.request(page === 'tickets' ? '/service-tickets?page_size=20&'+new URLSearchParams(ticketFilters) : '/warranty-claims?page_size=100', {cache:false}),
                VKApi.request('/service-desk/lookups', {cache:false}),
            ]);
            if (version !== loadVersion || !document.body.contains(root)) return;
            sourceData = lookups.data || {customers:[], users:[]};
            root.innerHTML = page === 'tickets' ? renderTickets(records.data || []) : renderWarranties(records.data || []);
            if (page === 'tickets') { renderDeadlineCells(root, records.data || []);ticketControls(root,records.meta || {}); }
            if (page === 'warranties') bindWarrantyFilters();
        } catch (error) {
            if (version !== loadVersion || !document.body.contains(root)) return;
            root.innerHTML = `<section class="module-panel"><div class="empty-state"><strong>Không tải được dữ liệu</strong><span>${escape(error.message || 'Vui lòng thử lại.')}</span><button class="btn" type="button" data-service-retry>Thử lại</button></div></section>`;
        }
    }

    function renderTickets(rows) {
        const body = rows.length ? rows.map(row => `<tr><td><button class="btn small" type="button" data-ticket-open="${escape(row.id)}">${escape(row.code)}</button></td><td>${escape(row.customer?.name || '—')}</td><td>${escape(row.subject)}</td><td>${escape(row.category)}</td><td><span class="badge ${['high','urgent'].includes(row.priority)?'danger':'info'}">${escape(row.priority)}</span></td><td>${formatDate(row.resolution_due_at,true)}</td><td><span class="badge ${['resolved','closed'].includes(row.status)?'success':'warning'}">${escape(row.status)}</span></td></tr>`).join('') : '<tr><td colspan="7"><div class="empty-state"><strong>Chưa có yêu cầu hỗ trợ</strong><span>Tiếp nhận yêu cầu khi khách hàng cần hỗ trợ hoặc bảo hành.</span></div></td></tr>';
        return `<section class="module-panel"><div class="panel-head"><div><h2>Danh sách yêu cầu hỗ trợ</h2><span>Theo dõi tiếp nhận, người xử lý và thời hạn hỗ trợ.</span></div><span class="badge info">${rows.length} yêu cầu</span></div><div class="table-wrap"><table class="list-table"><thead><tr><th>Mã</th><th>Khách hàng</th><th>Tiêu đề</th><th>Loại</th><th>Ưu tiên</th><th>Hạn xử lý</th><th>Trạng thái</th></tr></thead><tbody>${body}</tbody></table></div></section>`;
    }
    function ticketControls(root,meta) {
        const controls=document.createElement('section');controls.className='ticket-list-filters';
        const statuses={open:'Mới',in_progress:'Đang xử lý',pending_customer:'Chờ thông tin khách',resolved:'Đã xử lý / chờ xác nhận',closed:'Đã đóng',cancelled:'Đã hủy'};
        controls.innerHTML=`<div class="form-grid"><div class="field"><label>Tìm mã / tiêu đề</label><input data-ticket-filter="search"></div><div class="field"><label>Trạng thái</label><select data-ticket-filter="status"><option value="">Tất cả</option>${Object.entries(statuses).map(([key,label])=>`<option value="${key}">${label}</option>`).join('')}</select></div><div class="field"><label>Người phụ trách</label><select data-ticket-filter="assignee_id"><option value="">Tất cả</option>${data().users.map(u=>`<option value="${escape(u.id)}">${escape(u.name)}</option>`).join('')}</select></div><div class="field"><label>Hạn xử lý</label><select data-ticket-filter="overdue"><option value="0">Tất cả</option><option value="1">Đang quá hạn</option></select></div></div><button class="btn" type="button" data-ticket-search>Lọc danh sách</button>`;
        const panel=root.querySelector('.module-panel');
        panel.classList.add('ticket-list-panel');
        panel.querySelector('.panel-head').after(controls);
        controls.querySelectorAll('[data-ticket-filter]').forEach(input=>input.value=ticketFilters[input.dataset.ticketFilter]);
        controls.querySelector('[data-ticket-filter="search"]').placeholder='Tìm mã hoặc tiêu đề yêu cầu...';
        controls.querySelector('[data-ticket-filter="search"]').addEventListener('keydown',event=>{if(event.key==='Enter'){event.preventDefault();controls.querySelector('[data-ticket-search]').click();}});
        controls.querySelector('[data-ticket-search]').addEventListener('click',()=>{controls.querySelectorAll('[data-ticket-filter]').forEach(input=>ticketFilters[input.dataset.ticketFilter]=input.value);ticketFilters.page=1;loadPage(true);});
        const pager=document.createElement('div');pager.className='ticket-list-pagination';
        const last=Math.max(1,Math.ceil((meta.total || 0)/(meta.page_size || 20)));
        pager.innerHTML=`<button type="button" class="btn small" data-ticket-prev ${ticketFilters.page<=1?'disabled':''}>Trang trước</button><span>Trang ${escape(ticketFilters.page)} / ${last} · ${escape(meta.total || 0)} yêu cầu</span><button type="button" class="btn small" data-ticket-next ${ticketFilters.page>=last?'disabled':''}>Trang sau</button>`;
        panel.appendChild(pager);
        pager.querySelector('[data-ticket-prev]').addEventListener('click',()=>{ticketFilters.page--;loadPage(true);});pager.querySelector('[data-ticket-next]').addEventListener('click',()=>{ticketFilters.page++;loadPage(true);});
    }

    function deadlineBadge(sla, due) {
        const colors = {green:'success',yellow:'warning',red:'danger',neutral:'info'};
        const minutes = sla?.remaining_minutes;
        const duration = Number.isFinite(minutes) ? `${minutes < 0 ? 'Trễ' : 'Còn'} ${Math.floor(Math.abs(minutes)/60)} giờ ${Math.abs(minutes)%60} phút` : '';
        return `<span class="badge ${colors[sla?.color] || 'info'}">${escape(sla?.label || 'Chưa có hạn')}</span><br><small>${escape(duration)}${duration ? ' · ' : ''}${formatDate(due,true)}</small>`;
    }
    function renderDeadlineCells(root, rows) {
        root.querySelectorAll('[data-ticket-open]').forEach((button,index) => {
            const row = rows[index]; const cells = button.closest('tr')?.cells;
            if (!row || !cells) return;
            const categories={support:'Hỗ trợ',warranty:'Bảo hành',installation:'Lắp đặt',maintenance:'Bảo trì',quotation:'Báo giá',order:'Đơn hàng',delivery:'Giao hàng',product:'Chất lượng sản phẩm',complaint:'Khiếu nại khác'};
            const statuses={open:'Mới',in_progress:'Đang xử lý',pending_customer:'Chờ thông tin khách',resolved:'Đã xử lý / chờ xác nhận',closed:'Đã đóng',cancelled:'Đã hủy'};
            cells[2].innerHTML=`${escape(row.subject)}<br><small>Phụ trách: ${escape(row.assignee?.name || 'Chưa phân công')}</small>`;
            cells[3].textContent=categories[row.category] || row.category;
            const priority=cells[4].querySelector('.badge');if(priority)priority.textContent=({low:'Thấp',normal:'Bình thường',high:'Cao',urgent:'Khẩn cấp'})[row.priority] || row.priority;
            const status=cells[6].querySelector('.badge');if(status)status.textContent=statuses[row.status] || row.status;
            cells[5].innerHTML = `<div>Phản hồi: ${deadlineBadge(row.response_sla,row.response_due_at)}</div><div style="margin-top:8px">Xử lý: ${deadlineBadge(row.resolution_sla,row.resolution_due_at)}</div>`;
        });
    }

    function renderWarranties(rows) {
        const now = new Date();
        const active = rows.filter(row => row.warranty_status === 'in_warranty').length;
        const today = new Date().toISOString().slice(0,10);
        const body = rows.length ? rows.map(row => {
            const isActive = row.warranty_status === 'in_warranty';
            const statusText = row.warranty_status === 'not_started' ? 'Chưa hiệu lực' : (isActive ? 'Còn hiệu lực' : 'Hết hạn');
            const customer = row.customer_name || row.customer?.name || '—';
            return `<tr data-serial="${escape(String(row.serial_number||'').toLowerCase())}" data-product="${escape(String(row.product_name||'').toLowerCase())}" data-customer="${escape(String(customer).toLowerCase())}" data-purchase="${escape(String(row.purchase_date||'').slice(0,10))}"><td><span class="record-code-chip">${escape(row.code)}</span></td><td><strong>${escape(row.serial_number)}</strong></td><td>${escape(row.product_name)}</td><td><strong>${escape(customer)}</strong><br><small>${escape(row.customer_email || '')}</small></td><td>${formatDate(row.purchase_date)}</td><td>${formatDate(row.warranty_end_at)}<br><small>${statusText}</small></td><td><span class="badge ${isActive?'success':'warning'}">${statusText}</span></td></tr>`;
        }).join('') : '<tr><td colspan="7"><div class="empty-state"><strong>Chưa có đăng ký bảo hành</strong><span>Thêm serial đầu tiên để bắt đầu quản lý.</span></div></td></tr>';
        return `<section class="service-warranty-stats"><article><span>Tổng bảo hành</span><strong>${rows.length}</strong></article><article><span>Còn hiệu lực</span><strong>${active}</strong></article><article><span>Hết hạn</span><strong>${rows.filter(row=>row.warranty_status === 'out_of_warranty').length}</strong></article><article><span>Đăng ký hôm nay</span><strong>${rows.filter(row=>String(row.created_at||'').slice(0,10)===today).length}</strong></article></section><section class="module-panel"><div class="panel-head"><div><h2>Danh sách bảo hành</h2><span>Tìm nhanh theo serial, sản phẩm hoặc khách hàng.</span></div></div><div class="warranty-filter-grid" data-warranty-filter><div class="field"><label>Số serial</label><input id="warrantyFilterSerial" placeholder="Nhập số serial cần tìm..."></div><div class="field"><label>Sản phẩm / Model</label><input id="warrantyFilterProduct" placeholder="Nhập model / tên sản phẩm..."></div><div class="field"><label>Khách hàng</label><input id="warrantyFilterCustomer" placeholder="Nhập tên khách hàng..."></div><div class="field"><label>Ngày mua</label><input id="warrantyFilterPurchase" type="date"></div><div class="warranty-filter-actions"><button class="btn primary" type="button" data-warranty-search>Tìm kiếm</button><button class="btn secondary" type="button" data-warranty-reset>Làm mới</button></div></div><div class="table-wrap"><table class="list-table"><thead><tr><th>Mã</th><th>Số serial</th><th>Sản phẩm / Model</th><th>Khách hàng</th><th>Ngày mua</th><th>Hết hạn</th><th>Trạng thái</th></tr></thead><tbody data-warranty-list>${body}</tbody></table></div></section>`;
    }
    function createTicket() {
        const source = data(); const customers = options(source.customers, r => `${r.code} - ${r.name}`); const users = [{ value: '', label: 'Chưa phân công' }, ...options(source.users, r => r.name)];
        VKModal.open('Tạo ticket hỗ trợ', [select('customer_id', 'Khách hàng', customers), VKModal.field('serial_number', 'Số serial (nếu có)'), VKModal.field('product_name', 'Tên sản phẩm / thiết bị'), select('assignee_id', 'Người xử lý', users), VKModal.field('subject', 'Tiêu đề'), '<div class="field"><label for="description">Mô tả yêu cầu</label><textarea id="description" name="description" rows="4"></textarea></div>', select('category', 'Loại yêu cầu', [{value:'support',label:'Hỗ trợ'}, {value:'warranty',label:'Bảo hành'}, {value:'installation',label:'Lắp đặt'}, {value:'maintenance',label:'Bảo trì'}]), select('priority', 'Ưu tiên', [{value:'normal',label:'Bình thường'}, {value:'low',label:'Thấp'}, {value:'high',label:'Cao'}, {value:'urgent',label:'Khẩn cấp'}])].join(''), async form => send('/service-tickets', { customer_id: Number(value(form,'customer_id')), serial_number:value(form,'serial_number'), product_name:value(form,'product_name'), assignee_id:value(form,'assignee_id') ? Number(value(form,'assignee_id')) : null, subject:value(form,'subject'), description:value(form,'description'), category:value(form,'category'), priority:value(form,'priority'), source:'manual' }));
    }
    function addDeadlineInput() {
        const subject = document.querySelector('#subject');
        const form = subject?.closest('form');
        if (!form || form.querySelector('[data-ticket-deadline]')) return;
        const field = document.createElement('div'); field.className = 'field';
        field.innerHTML = '<label for="ticket_resolution_due_at">Hạn xử lý</label><input type="datetime-local" id="ticket_resolution_due_at" data-ticket-deadline><small>Để trống: theo chính sách SLA và mức ưu tiên. Nhập ngày giờ để đặt hạn riêng.</small>';
        const body = form.querySelector('#modalBody');
        if (body) {
            body.appendChild(field);
            const extra = document.createElement('div');
            extra.innerHTML = '<div class="field"><label>Mã chứng từ tham chiếu</label><input data-ticket-document maxlength="120" placeholder="Báo giá, đơn hàng hoặc phiếu giao hàng"></div><div class="field"><label>Kênh tiếp nhận</label><select data-ticket-source><option value="phone">Điện thoại</option><option value="chat">Zalo / trò chuyện</option><option value="email">Email</option><option value="manual">Trực tiếp</option></select></div>';
            body.appendChild(extra);
            const link = document.createElement('div');
            link.innerHTML = '<div class="field"><label>Liên kết chứng từ</label><select data-feedback-document-type><option value="">Không liên kết</option><option value="quotation">Báo giá</option><option value="order">Đơn hàng</option><option value="delivery">Phiếu giao hàng</option></select></div><div class="field"><label>Chứng từ của khách hàng</label><select data-feedback-document-id disabled><option value="">Chọn loại chứng từ trước</option></select><button type="button" class="btn small" data-feedback-document-more hidden>Tải thêm</button><small data-feedback-document-error></small></div>';
            body.appendChild(link);
            const type = link.querySelector('[data-feedback-document-type]'), record = link.querySelector('[data-feedback-document-id]'), more = link.querySelector('[data-feedback-document-more]'), error = link.querySelector('[data-feedback-document-error]');
            const customer = body.querySelector('[name="customer_id"]');
            let generation = 0, page = 1;
            async function documents(append = false) {
                const current = ++generation;
                if (!append) { record.innerHTML = '<option value="">Chọn chứng từ</option>'; page = 1; }
                record.disabled = true; more.hidden = true; error.textContent = '';
                if (!type.value || !customer?.value) return;
                try {
                    const result = await VKApi.request(`/service-desk/documents?customer_id=${encodeURIComponent(customer.value)}&type=${encodeURIComponent(type.value)}&page=${page}`,{cache:false});
                    if (current !== generation || !document.body.contains(record)) return;
                    for (const item of result.data || []) { const option = document.createElement('option'); option.value = item.id; option.textContent = item.code; record.appendChild(option); }
                    record.disabled = false; more.hidden = result.meta.page >= result.meta.last_page;
                    if (record.options.length === 1) error.textContent = 'Không có chứng từ phù hợp.';
                } catch (_) { if (current === generation) error.textContent = 'Không tải được hoặc bạn chưa có quyền xem chứng từ này.'; }
            }
            type.addEventListener('change',()=>documents()); customer?.addEventListener('change',()=>documents());
            more.addEventListener('click',()=>{ page++; documents(true); });
            const category = body.querySelector('[name="category"]');
            if (category) for (const [key,label] of Object.entries({quotation:'Báo giá',order:'Đơn hàng',delivery:'Giao hàng',product:'Chất lượng sản phẩm',complaint:'Khiếu nại khác'})) { const option = document.createElement('option'); option.value = key; option.textContent = label; category.appendChild(option); }
            layoutTicketForm(body);
        }
    }
    function layoutTicketForm(body) {
        const modal=body.closest('.modal');
        modal?.classList.add('ticket-create-modal');
        document.getElementById('modalTitle').textContent='Tiếp nhận yêu cầu khách hàng';
        document.getElementById('modalSubmit').textContent='Tạo yêu cầu';
        const sections=[
            ['Thông tin yêu cầu',['[name="customer_id"]','[data-ticket-source]','[name="subject"]','[name="description"]']],
            ['Phân công và thời hạn',['[name="category"]','[name="priority"]','[name="assignee_id"]','[data-ticket-deadline]']],
            ['Sản phẩm và chứng từ',['[name="product_name"]','[name="serial_number"]','[data-feedback-document-type]','[data-feedback-document-id]','[data-ticket-document]']]
        ];
        const layout=document.createElement('div');layout.className='ticket-create-layout';
        for(const [title,selectors] of sections) {
            const section=document.createElement('section');section.className='ticket-form-section';
            const heading=document.createElement('h3');heading.textContent=title;section.appendChild(heading);
            const grid=document.createElement('div');grid.className='ticket-form-grid';
            for(const selector of selectors) {
                const input=body.querySelector(selector),field=input?.closest('.field');if(!field)continue;
                if(['subject','description'].includes(input.name))field.classList.add('ticket-full-row');
                grid.appendChild(field);
            }
            section.appendChild(grid);layout.appendChild(section);
        }
        // Move existing controls rather than rebuilding them: preserve values and handlers.
        body.replaceChildren(layout);
    }
    async function openTicket(id) {
        const ticket = (await VKApi.request(`/service-tickets/${id}`, {cache:false})).data;
        const canEdit = window.VKUser?.permissions?.includes('service.ticket.manage') && !['closed','cancelled'].includes(ticket.status);
        const history = (ticket.worklogs || []).filter(log=>log.action!=='sla_policy').map(log => `<li><strong>${escape(log.user?.name || 'Hệ thống')}</strong> — ${formatDate(log.worked_at,true)}<p>${escape(log.content || '')}</p></li>`).join('');
        let content = `<p><strong>${escape(ticket.subject)}</strong></p><p>${escape(ticket.description || '')}</p><p>Trạng thái: ${escape(ticket.status)}</p><p>Hạn phản hồi: ${deadlineBadge(ticket.response_sla,ticket.response_due_at)}</p><p>Hạn xử lý: ${deadlineBadge(ticket.resolution_sla,ticket.resolution_due_at)}</p><h3>Lịch sử xử lý</h3><ul>${history || '<li>Chưa có lịch sử.</li>'}</ul>`;
        content += `<section class="ticket-evidence"><h3>Ảnh minh chứng</h3><ul>${(ticket.attachments || []).map(a=>`<li><button class="btn small" type="button" data-ticket-evidence-download="${escape(a.id)}">${escape(a.original_name)}</button> <small>${Math.ceil(a.bytes/1024)} KB</small></li>`).join('') || '<li>Chưa có ảnh minh chứng.</li>'}</ul>${canEdit?'<div class="field"><label>Thêm ảnh JPG, PNG hoặc WebP</label><input type="file" accept="image/jpeg,image/png,image/webp" data-ticket-evidence-file style="height:auto;padding:12px"><small>Tối đa 5 MB/ảnh, 10 ảnh/hồ sơ. Ảnh chỉ lưu nội bộ.</small><button type="button" class="btn" data-ticket-evidence-upload>Tải ảnh lên</button></div>':''}<p data-ticket-evidence-result role="status"></p></section>`;
        if (canEdit) {
            content += `<p>Chứng từ tham chiếu: ${escape(ticket.related_document || '—')}</p>`;
            content += VKModal.select('customer_confirmation','Xác nhận khách hàng',[{value:'not_contacted',label:'Chưa xác nhận'},{value:'accepted',label:'Khách đồng ý kết quả xử lý'},{value:'rejected',label:'Khách chưa đồng ý — cần xử lý lại'}]);
            if(ticket.status==='resolved')content+='<p class="form-note">Khách chưa đồng ý: chọn Đang xử lý, ghi lý do và chọn Khách chưa đồng ý. Giữ hạn ban đầu. Muốn đóng: ghi nhật ký đã trả lời khách về kết quả mới nhất trước.</p>';
            content += VKModal.select('operation','Thao tác',[{value:'worklog',label:'Ghi nhật ký xử lý'},{value:'status',label:'Cập nhật trạng thái và phân công'},...(ticket.sla_clock?.allow_pause?[{value:ticket.sla_clock.paused?'resume':'pause',label:ticket.sla_clock.paused?'Tiếp tục SLA':'Tạm dừng SLA — chờ thông tin khách'}]:[])]);
            content += '<div class="field"><label for="content">Nội dung xử lý</label><textarea name="content" id="content" rows="3"></textarea></div>';
            content += VKModal.select('visibility','Loại ghi nhận',[{value:'internal',label:'Trao đổi nội bộ'},{value:'public',label:'Đã trả lời khách qua điện thoại / Zalo / email'}]);
            content += '<p class="form-note">CRM lưu lịch sử, không tự gửi tin nhắn. Sau khi xử lý, ghi nhận đã trả lời khách; chỉ đóng khi khách đồng ý.</p>';
            content += VKModal.select('status','Trạng thái',['open','in_progress','pending_customer','resolved','closed','cancelled'].map(status=>({value:status,label:({open:'Mới',in_progress:ticket.status==='resolved'?'Xử lý lại — khách chưa đồng ý':'Đang xử lý',pending_customer:'Chờ thông tin khách',resolved:'Đã xử lý / chờ khách xác nhận',closed:'Đóng — khách đồng ý',cancelled:'Hủy'})[status]})),ticket.status);
            content += VKModal.select('assignee_id','Người xử lý',[{value:'',label:'Giữ nguyên'},...options(data().users,row=>row.name)],ticket.assignee_id || '');
            content += VKModal.field('resolution_code','Mã kết quả xử lý');
            content += `<p class="form-note">SLA: ${ticket.sla_clock?.clock==='business'?'theo lịch làm việc đã cấu hình':'giờ liên tục'}. ${ticket.sla_clock?.paused?'Đang tạm dừng; cần tiếp tục trước khi đổi trạng thái.':''}</p>`;
        }
        VKModal.open(`Ticket ${ticket.code}`,content,async form=>{
            if (['pause','resume'].includes(value(form,'operation'))) {
                await VKApi.request(`/service-tickets/${id}/sla-clock`,{method:'POST',body:JSON.stringify({pause:value(form,'operation')==='pause',reason:value(form,'content')})});
            } else if (value(form,'operation') === 'worklog') {
                if (!String(value(form,'content') || '').trim()) throw new Error('Vui lòng nhập nội dung xử lý.');
                await VKApi.request(`/service-tickets/${id}/worklogs`,{method:'POST',body:JSON.stringify({content:value(form,'content'),visibility:value(form,'visibility')})});
            } else {
                await VKApi.request(`/service-tickets/${id}`,{method:'PUT',body:JSON.stringify({status:value(form,'status'),assignee_id:value(form,'assignee_id') ? Number(value(form,'assignee_id')) : null,resolution_code:value(form,'resolution_code'),resolution_note:value(form,'content'),customer_confirmation:value(form,'customer_confirmation')})});
            }
            VKModal.close(); await loadPage(true);
        },{hideSubmit:!canEdit,submitText:'Lưu xử lý',cancelText:'Đóng'});
        const modalBody=document.getElementById('modalBody');
        modalBody.querySelector('[data-ticket-evidence-upload]')?.addEventListener('click',async event=>{
            const button=event.currentTarget,output=modalBody.querySelector('[data-ticket-evidence-result]');
            const file=modalBody.querySelector('[data-ticket-evidence-file]').files[0];
            if(!file){output.textContent='Chọn ảnh trước khi tải lên.';return;}
            if(file.size>5*1024*1024){output.textContent='Ảnh vượt 5 MB.';return;}
            button.disabled=true;
            try{
                const draft=Array.from(modalBody.querySelectorAll('input[name],select[name],textarea[name]'),input=>[input.name,input.value]);
                const body=new FormData();body.append('file',file);await VKApi.request(`/service-tickets/${id}/attachments`,{method:'POST',body});await openTicket(id);
                const refreshed=document.getElementById('modalBody');
                for(const [name,value]of draft)for(const input of refreshed.querySelectorAll('input[name],select[name],textarea[name]'))if(input.name===name)input.value=value;
            }
            catch(error){output.textContent=error.message || 'Chưa tải được ảnh.';}
            finally{button.disabled=false;}
        });
        modalBody.querySelectorAll('[data-ticket-evidence-download]').forEach(button=>button.addEventListener('click',async()=>{
            button.disabled=true;
            try{const response=await fetch(`/api/v1/service-tickets/${id}/attachments/${encodeURIComponent(button.dataset.ticketEvidenceDownload)}`,{headers:{Authorization:`Bearer ${VKApi.token()}`}});if(!response.ok)throw new Error('Không tải được ảnh hoặc bạn không có quyền.');const url=URL.createObjectURL(await response.blob());const a=document.createElement('a');a.href=url;a.download=button.textContent;a.click();setTimeout(()=>URL.revokeObjectURL(url),1000);}
            catch(error){VKModal.toast(error.message,'danger');}finally{button.disabled=false;}
        }));
    }
    function createWarranty() {
        const today = new Date().toISOString().slice(0, 10);
        const content = [
            '<div class="serial-mode-actions warranty-full-row"><button type="button" class="btn primary" data-warranty-serial-mode="single">Nhập 1 serial</button><button type="button" class="btn secondary" data-warranty-serial-mode="multiple">Nhập nhiều serial</button></div><div class="field"><label for="serial_number">Số serial <em>*</em></label><input id="serial_number" name="serial_number" placeholder="Ví dụ: SN123456789"><textarea id="serial_numbers" name="serial_numbers" rows="3" placeholder="Mỗi serial một dòng hoặc cách nhau bằng dấu phẩy" hidden></textarea><small>Serial được dùng để tra cứu và quản lý bảo hành.</small></div>',
            '<div class="field"><label for="product_name">Tên sản phẩm / Model <em>*</em></label><input id="product_name" name="product_name" placeholder="Ví dụ: K50PRO, K20PRO..."><small>Gõ tự do tên sản phẩm hoặc model.</small></div>',
            '<div class="field"><label for="tax_code">Mã số thuế</label><input id="tax_code" name="tax_code" placeholder="Không bắt buộc"><small data-tax-hint>Nhập MST để tự điền khách hàng đã có trong CRM.</small></div>',
            '<div class="modal-grid"><div class="field"><label for="customer_name">Tên khách hàng <em>*</em></label><input id="customer_name" name="customer_name"></div><div class="field"><label for="customer_phone">Số điện thoại</label><input id="customer_phone" name="customer_phone"></div></div>',
            '<div class="field"><label for="customer_email">Email</label><input id="customer_email" name="customer_email" type="email"></div><div class="field warranty-full-row"><label for="customer_address">Địa chỉ</label><textarea id="customer_address" name="customer_address" rows="2"></textarea></div>',
            `<div class="modal-grid warranty-dates"><div class="field"><label for="purchase_date">Ngày mua</label><input id="purchase_date" name="purchase_date" type="date"></div><div class="field"><label for="warranty_start_at">Ngày bắt đầu bảo hành <em>*</em></label><input id="warranty_start_at" name="warranty_start_at" type="date" value="${today}"></div><div class="field"><label for="warranty_months">Thời hạn bảo hành (tháng) <em>*</em></label><input id="warranty_months" name="warranty_months" type="number" min="1" max="120" value="12"></div></div>`
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
        document.querySelectorAll('[data-service-create]').forEach(button => { if (button.dataset.bound) return; button.dataset.bound = '1'; button.addEventListener('click', () => { ({ticket:createTicket,warranty:createWarranty}[button.dataset.serviceCreate] || (()=>{}))(); if (button.dataset.serviceCreate === 'ticket') addDeadlineInput(); }); });
        const root = document.querySelector('[data-service-desk-page]');
        if (root && !root.dataset.loaded) { root.dataset.loaded = '1'; loadPage(); }
    }
    document.addEventListener('click', event => {
        if (event.target.closest('[data-warranty-import]')) importWarranties();
        if (event.target.closest('[data-service-retry]')) loadPage();
        const ticket = event.target.closest('[data-ticket-open]');
        if (ticket) openTicket(ticket.dataset.ticketOpen).catch(error=>VKModal.toast(error.message || 'Không thể mở ticket.','danger'));
    });
    function importWarranties() {
        let verified = null;
        VKModal.open('Nhập bảo hành','<button type="button" class="btn small" data-warranty-template>Tải mẫu</button><div class="field" style="margin-top:16px"><label>Chọn danh sách</label><input type="file" accept=".xlsx,.csv" data-warranty-file style="height:auto;min-height:48px;padding:12px"><small>Excel hoặc CSV · Tối đa 300 serial · 1 MB</small></div><details><summary>Hướng dẫn</summary><p>Mã khách hàng phải có sẵn. Mỗi dòng một serial, giữ dạng văn bản. Ngày: dd/mm/yyyy. Số tháng: 1–120. Không ghi đè bảo hành đã có.</p></details><div data-warranty-import-result role="status" style="margin-top:16px"></div>',async form=>{
            const input=form.querySelector('[data-warranty-file]'), file=input.files[0];
            if(!file) throw new Error('Chọn file trước khi kiểm tra.');
            if(file.size>1024*1024) throw new Error('File vượt 1 MB.');
            const confirm=verified===file;
            const payload=new FormData(); payload.append('file',file); payload.append('confirm',confirm?'1':'0');
            const result=(await VKApi.request('/warranty-import',{method:'POST',body:payload,timeout:180000})).data;
            const target=form.querySelector('[data-warranty-import-result]');
            if(result.imported) { VKModal.close(); VKModal.toast(`Đã nhập ${result.imported} bảo hành.`,'success'); await loadPage(true); return; }
            verified=result.can_import ? file : null;
            target.innerHTML=result.can_import ? `<p>${result.total} serial hợp lệ. Chọn Xác nhận nhập để lưu.</p>` : `<p>Chưa nhập dữ liệu. Sửa các dòng sau:</p><ul>${result.errors.map(e=>`<li>Dòng ${escape(e.row)}: ${escape(e.message)}</li>`).join('')}</ul>`;
            document.getElementById('modalSubmit').textContent=verified?'Xác nhận nhập':'Kiểm tra file';
        },{submitText:'Kiểm tra file'});
        const input=document.querySelector('[data-warranty-file]');
        input.addEventListener('change',()=>{ verified=null; document.querySelector('[data-warranty-import-result]').textContent=''; document.getElementById('modalSubmit').textContent='Kiểm tra file'; });
        document.querySelector('[data-warranty-template]').addEventListener('click',async()=>{
            try { const response=await fetch('/api/v1/warranty-import/template',{headers:{Authorization:`Bearer ${VKApi.token()}`}}); if(!response.ok) throw new Error('Không tải được mẫu hoặc bạn chưa có quyền nhập bảo hành.'); const url=URL.createObjectURL(await response.blob()); const anchor=document.createElement('a'); anchor.href=url; anchor.download='mau-bao-hanh.xlsx'; anchor.click(); setTimeout(()=>URL.revokeObjectURL(url),1000); }
            catch(error){VKModal.toast(error.message || 'Chưa tải được mẫu.','danger');}
        });
    }
    window.loadServiceTickets = bind; window.loadWarrantyClaims = bind; document.addEventListener('vk:ready', bind); bind();
    if(window.vkTicketRefreshTimer)clearInterval(window.vkTicketRefreshTimer);
    window.vkTicketRefreshTimer=setInterval(()=>{
        const root=document.querySelector('[data-service-desk-page="tickets"]');
        if(root && !document.hidden && !root.contains(document.activeElement) && !document.querySelector('#modalBackdrop.open'))loadPage(true);
    },60000);
})();
