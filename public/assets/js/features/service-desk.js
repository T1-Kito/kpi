(function () {
    let sourceData = { customers: [], users: [] };
    let loadVersion = 0;
    let contextOpened = '';
    const orderRequestCache=new Map();
    function orderRequests(context,page){const key=JSON.stringify([window.VKUser?.id,window.VKUser?.tenant_id,context,page]);const cached=orderRequestCache.get(key);if(cached && Date.now()-cached.time<5000)return cached.promise;const promise=VKApi.request('/service-tickets?'+new URLSearchParams({...context,page,page_size:10,compact:1}),{cache:false}).catch(error=>{orderRequestCache.delete(key);throw error;});orderRequestCache.set(key,{promise,time:Date.now()});if(orderRequestCache.size>30)orderRequestCache.delete(orderRequestCache.keys().next().value);return promise;}
    let embeddedContext=null, embeddedRefresh=null, embeddedRoot=null;
    const requestGroups={ticket_advice:'Tư vấn vé / ưu đãi',group_sales:'Khách đoàn / TA / doanh nghiệp',resort:'Phòng và dịch vụ Resort',online_payment:'Đơn online / thanh toán',onsite:'Hỗ trợ tại quầy',lost_property:'Đồ thất lạc',complaint:'Khiếu nại dịch vụ',suggestion:'Góp ý trải nghiệm',aftercare:'Chăm sóc sau dịch vụ'};
    function documentContext(){ if(embeddedContext && embeddedRoot?.isConnected)return embeddedContext;if(embeddedContext){embeddedContext=null;embeddedRefresh=null;}const p=new URLSearchParams(location.search);return p.get('document_type')&&p.get('document_id')&&p.get('customer_id')?{document_type:p.get('document_type'),document_id:p.get('document_id'),customer_id:p.get('customer_id')}:null; }
    function detailFields(category,details={}) {
        const fields=data().groups?.[category]?.fields || {};
        return Object.entries(fields).map(([key,field])=>{
            const v=details[key] ?? '';
            return `<div class="field"><label>${escape(field.label)}${field.required?' <small class="service-required-note">Cần trước khi đóng</small>':''}</label>${field.type==='select'?`<select data-request-detail="${key}"><option value="">Chưa chọn</option>${Object.entries(field.options).map(([value,label])=>`<option value="${escape(value)}" ${String(v)===value?'selected':''}>${escape(label)}</option>`).join('')}</select>`:`<input data-request-detail="${key}" type="${field.type}" value="${escape(field.type==='datetime-local'&&v?localDateTime(v):v)}" ${field.type==='number'?'min="0" step="any"':''} maxlength="1000">`}</div>`;
        }).join('');
    }
    function localDateTime(value){const d=new Date(value);if(!Number.isFinite(d.getTime()))return '';return `${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,'0')}-${String(d.getDate()).padStart(2,'0')}T${String(d.getHours()).padStart(2,'0')}:${String(d.getMinutes()).padStart(2,'0')}`;}
    function collectDetails(root){return Object.fromEntries([...root.querySelectorAll('[data-request-detail]')].map(input=>[input.dataset.requestDetail,input.value && input.type==='datetime-local'?new Date(input.value).toISOString():input.value || null]));}
    function peopleFields(department='',assignee='',coordinator='',includeAssignee=true) {
        const users=data().users || [], departments=data().departments || [];
        return VKModal.select('department_id','Bộ phận xử lý',[{value:'',label:'Chưa chọn bộ phận'},...options(departments,u=>u.name)],department)+(includeAssignee?VKModal.select('assignee_id','Người xử lý',[{value:'',label:'Chưa phân công'},...options(users.filter(u=>!department||String(u.department_id)===String(department)),u=>u.name)],assignee):'')+VKModal.select('coordinator_id','CSKH theo dõi',[{value:'',label:'Người tiếp nhận ban đầu'},...options(users,u=>u.name)],coordinator);
    }
    function bindDepartment(root){const dept=root.querySelector('[name="department_id"]'),assignee=root.querySelector('[name="assignee_id"]');dept?.addEventListener('change',()=>{const old=assignee.value;assignee.innerHTML='<option value="">Chưa phân công</option>'+(data().users || []).filter(u=>!dept.value||String(u.department_id)===dept.value).map(u=>`<option value="${escape(u.id)}">${escape(u.name)}</option>`).join('');assignee.value=old;if(!assignee.value)assignee.value='';});}
    async function configureRouting(){
        let draft=JSON.parse(JSON.stringify(data().routing || {})),current=Object.keys(requestGroups)[0];
        const categories=Object.entries(requestGroups).map(([value,label])=>({value,label}));
        VKModal.open('Thiết lập tiếp nhận và thời hạn CSKH',`${VKModal.select('routing_group','Nhóm yêu cầu',categories,current)}<section data-routing-fields></section><p class="form-note">Để trống thời hạn: dùng chính sách SLA hiện có. Thiết lập mới chỉ áp dụng phiếu tạo sau khi lưu; không đổi hạn và lịch sử phiếu cũ.</p>`,async()=>{
            capture();await VKApi.request('/service-desk/routing',{method:'PUT',body:JSON.stringify({routing:draft})});VKModal.close();VKModal.toast('Đã lưu thiết lập CSKH.');await loadPage(true);
        },{className:'wide-modal service-routing-modal',submitText:'Lưu thiết lập'});
        const body=document.getElementById('modalBody'),group=body.querySelector('[name="routing_group"]'),root=body.querySelector('[data-routing-fields]');
        function capture(){const row={};for(const name of ['department_id','assignee_id','coordinator_id','supervisor_id','response_minutes','resolution_minutes']){const input=root.querySelector(`[name="${name}"]`);row[name]=input?.value?Number(input.value):null;}draft[current]=row;}
        function render(){const row=draft[current] || {};root.innerHTML='<div class="form-grid two">'+peopleFields(row.department_id,row.assignee_id,row.coordinator_id)+VKModal.select('supervisor_id','Quản lý nhận cảnh báo',[{value:'',label:'Chưa chọn'},...options(data().users,u=>u.name)],row.supervisor_id)+VKModal.field('response_minutes','Hạn phản hồi (phút)','number',row.response_minutes || '')+VKModal.field('resolution_minutes','Hạn xử lý (phút)','number',row.resolution_minutes || '')+'</div>';bindDepartment(root);}
        group.addEventListener('change',()=>{capture();current=group.value;render();});render();
    }
    async function openContextTicket(){
        if(!window.VKUser?.permissions?.includes('service.ticket.manage'))throw new Error('Bạn chưa có quyền tiếp nhận yêu cầu.');
        const context=documentContext();createTicket();addDeadlineInput();if(!context)return;
        const body=document.getElementById('modalBody'),customer=body.querySelector('[name="customer_id"]');
        customer.value=context.customer_id;if(customer.value!==context.customer_id){VKModal.close();throw new Error('Bạn chưa có quyền sử dụng khách hàng này.');}
        body.querySelector('.ticket-optional-links')?.setAttribute('open','');
        const type=body.querySelector('[data-feedback-document-type]');type.value=context.document_type;
        body.querySelector('.ticket-linked-document').hidden=false;
        const result=await VKApi.request('/service-desk/documents?'+new URLSearchParams({customer_id:context.customer_id,type:context.document_type,document_id:context.document_id}),{cache:false});
        if(!document.body.contains(body))return;
        if(!result.data?.length)throw new Error('Không tìm thấy chứng từ được liên kết.');
        const record=body.querySelector('[data-feedback-document-id]');record.replaceChildren(new Option(result.data[0].code,context.document_id,true,true));record.disabled=false;
        body.querySelector('[data-ticket-document]').value=result.data[0].code;
    }
    const warrantyFilters={serial:'',product:'',customer:'',purchase_date:'',page:1};
    const ticketFilters={view:'',status:'',priority:'',assignee_id:'',category:'',department_id:'',search:'',overdue:'0',page:1};
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
            if(documentType && !documentId) payload.related_document=null;
            payload.document_type = documentId ? documentType : null;
            payload.document_id = documentId ? Number(documentId) : null;
            payload.related_document = document.querySelector('[data-ticket-document]')?.value || null;
            payload.source = document.querySelector('[data-ticket-source]')?.value || 'manual';
            payload.request_details={...collectDetails(document.getElementById('modalBody')),reporter_name:document.querySelector('[data-reporter-name]')?.value || null,reporter_phone:document.querySelector('[data-reporter-phone]')?.value || null};
            for(const key of ['department_id','coordinator_id'])payload[key]=Number(document.querySelector(`[name="${key}"]`)?.value) || null;
            const date = document.querySelector('[data-ticket-deadline]')?.value;
            if (date) {
                const parsed = new Date(date);
                if (!Number.isFinite(parsed.getTime()) || parsed.getTime() <= Date.now()) throw new Error('Hạn xử lý phải sau thời điểm hiện tại.');
                payload.resolution_due_at = parsed.toISOString();
            }
        }
        await VKApi.request(url, { method: 'POST', body: JSON.stringify(payload) });
        VKModal.close();
        if(embeddedRefresh)await embeddedRefresh();else await loadPage(true);
    }

    async function loadPage(silent = false) {
        const root = document.querySelector('[data-service-desk-page]');
        if (!root) return;
        const version = ++loadVersion;
        if (!silent) root.innerHTML = '<section class="module-panel"><p class="form-note">Đang tải dữ liệu…</p></section>';
        try {
            const page = root.dataset.serviceDeskPage;
            const [records, lookups] = await Promise.all([
                VKApi.request(page === 'tickets' ? '/service-tickets?page_size=20&'+new URLSearchParams({...ticketFilters,...(documentContext() || {})}) : '/warranty-claims?page_size=20&'+new URLSearchParams(warrantyFilters), {cache:false}),
                VKApi.request('/service-desk/lookups', {cache:false}),
            ]);
            if (version !== loadVersion || !document.body.contains(root)) return;
            sourceData = lookups.data || {customers:[], users:[]};
            root.innerHTML = page === 'tickets' ? renderTickets(records.data || []) : renderWarranties(records.data || [],records.meta?.stats || {});
            if (page === 'tickets') {
                const context=documentContext();
                if(context){const banner=document.createElement('div');banner.className='service-context-banner';banner.innerHTML='<strong>CSKH theo chứng từ đang chọn</strong><div><button type="button" class="btn primary" data-service-context-create>Tạo yêu cầu</button> <a class="btn secondary" href="/service-tickets">Tất cả yêu cầu</a></div>';root.prepend(banner);}
                const ticketId=new URLSearchParams(location.search).get('ticket');if(ticketId && contextOpened!==ticketId){contextOpened=ticketId;await openTicket(ticketId);}
                ticketControls(root,records.meta || {}); }
            if (page === 'warranties') {bindWarrantyFilters();root.insertAdjacentHTML('beforeend',VKTable.pager(records.meta || {},'data-warranty-page'));}
        } catch (error) {
            if (version !== loadVersion || !document.body.contains(root)) return;
            root.innerHTML = `<section class="module-panel"><div class="empty-state"><strong>Không tải được dữ liệu</strong><span>${escape(error.message || 'Vui lòng thử lại.')}</span><button class="btn" type="button" data-service-retry>Thử lại</button></div></section>`;
        }
    }

    function compactDeadline(sla,due,label){const minutes=sla?.remaining_minutes,abs=Math.abs(minutes || 0);const duration=abs>=1440?`${Math.floor(abs/1440)} ngày`:abs>=60?`${Math.floor(abs/60)} giờ`:`${abs} phút`;const text=Number.isFinite(minutes)?`${minutes<0?'Quá hạn':'Còn'} ${duration}`:sla?.label || 'Chưa có hạn';return `<div class="care-deadline" title="${escape(label+': '+formatDate(due,true))}"><span>${label}</span><span class="badge ${sla?.color==='red'?'danger':sla?.color==='yellow'?'warning':sla?.color==='green'?'success':'info'}">${escape(text)}</span></div>`;}
    function renderTickets(rows) {
        const body = rows.length ? rows.map(row => `<tr data-ticket-row="${escape(row.id)}"><td><button class="btn small" type="button" data-ticket-open="${escape(row.id)}">${escape(row.code)}</button></td><td>${escape(row.customer?.name || '—')}</td><td><strong>${escape(row.subject)}</strong><small class="care-row-meta">${escape(row.department?.name || 'Chưa chọn bộ phận')} · ${escape(row.assignee?.name || 'Chưa phân công')}</small>${row.related_document?`<small class="care-reference">${escape(row.related_document)}</small>`:''}</td><td>${escape(requestGroups[row.category] || ({support:'Hỗ trợ',installation:'Lắp đặt',warranty:'Bảo hành',maintenance:'Bảo trì',quotation:'Báo giá',order:'Đơn hàng',delivery:'Giao hàng',product:'Sản phẩm'})[row.category] || 'Khác')}</td><td><span class="badge ${['high','urgent'].includes(row.priority)?'danger':'info'}">${escape(({low:"Thấp",normal:"Bình thường",high:"Cao",urgent:"Khẩn cấp"})[row.priority] || row.priority)}</span></td><td>${compactDeadline(row.response_sla,row.response_due_at,"Phản hồi")}${compactDeadline(row.resolution_sla,row.resolution_due_at,"Xử lý")}</td><td><span class="badge ${['resolved','closed'].includes(row.status)?'success':'warning'}">${escape(row.status==='open'?(row.assignee_id?'Chờ tiếp nhận':'Chưa phân công'):({in_progress:'Đang xử lý',pending_customer:'Chờ thông tin khách',resolved:'Chờ xác nhận',closed:'Đã đóng',cancelled:'Đã hủy'})[row.status] || row.status)}</span></td></tr>`).join('') : '<tr><td colspan="7"><div class="empty-state"><strong>Chưa có yêu cầu hỗ trợ</strong><span>Tiếp nhận yêu cầu khi khách hàng cần hỗ trợ hoặc bảo hành.</span></div></td></tr>';
        return `<section class="module-panel"><div class="panel-head"><div><h2>Yêu cầu khách hàng</h2><span>Theo dõi bàn giao, xử lý và phản hồi khách.</span></div><span class="badge info">${rows.length} yêu cầu</span></div><div class="table-wrap"><table class="list-table"><thead><tr><th>Mã</th><th>Khách hàng</th><th>Tiêu đề</th><th>Loại</th><th>Ưu tiên</th><th>Hạn xử lý</th><th>Trạng thái</th></tr></thead><tbody>${body}</tbody></table></div></section>`;
    }
    function ticketControls(root,meta) {
        const controls=document.createElement('section');controls.className='ticket-list-filters';
        const statuses={open:'Mới',in_progress:'Đang xử lý',pending_customer:'Chờ thông tin khách',resolved:'Đã xử lý / chờ xác nhận',closed:'Đã đóng',cancelled:'Đã hủy'};
        controls.innerHTML=`<div class="form-grid"><div class="field"><label>Tìm mã / tiêu đề</label><input data-ticket-filter="search"></div><div class="field"><label>Nhóm yêu cầu</label><select data-ticket-filter="category"><option value="">Tất cả nhóm</option>${Object.entries(requestGroups).map(([key,label])=>`<option value="${key}">${escape(label)}</option>`).join('')}</select></div><div class="field"><label>Bộ phận xử lý</label><select data-ticket-filter="department_id"><option value="">Tất cả bộ phận</option>${(data().departments || []).map(d=>`<option value="${d.id}">${escape(d.name)}</option>`).join('')}</select></div><div class="field"><label>Trạng thái</label><select data-ticket-filter="status"><option value="">Tất cả</option>${Object.entries(statuses).map(([key,label])=>`<option value="${key}">${label}</option>`).join('')}</select></div><div class="field"><label>Người phụ trách</label><select data-ticket-filter="assignee_id"><option value="">Tất cả</option>${data().users.map(u=>`<option value="${escape(u.id)}">${escape(u.name)}</option>`).join('')}</select></div><div class="field"><label>Hạn xử lý</label><select data-ticket-filter="overdue"><option value="0">Tất cả</option><option value="1">Đang quá hạn</option></select></div></div><button class="btn" type="button" data-ticket-search>Lọc danh sách</button>`;
        const panel=root.querySelector('.module-panel');
        panel.classList.add('ticket-list-panel');
        panel.querySelector('.panel-head').after(controls);
        controls.querySelectorAll('[data-ticket-filter]').forEach(input=>input.value=ticketFilters[input.dataset.ticketFilter]);
        controls.querySelector('[data-ticket-filter="search"]').placeholder='Tìm mã hoặc tiêu đề yêu cầu...';
        controls.querySelector('[data-ticket-filter="search"]').addEventListener('keydown',event=>{if(event.key==='Enter'){event.preventDefault();controls.querySelector('[data-ticket-search]').click();}});
        controls.querySelector('[data-ticket-search]').addEventListener('click',()=>{controls.querySelectorAll('[data-ticket-filter]').forEach(input=>ticketFilters[input.dataset.ticketFilter]=input.value);ticketFilters.page=1;loadPage(true);});
        const grid=controls.querySelector('.form-grid');grid.className='care-main-filters';
        const advanced=document.createElement('details');advanced.className='care-advanced-filters';advanced.innerHTML='<summary>Lọc thêm</summary><div></div>';
        for(const key of ['department_id','assignee_id','overdue'])advanced.querySelector('div').append(controls.querySelector(`[data-ticket-filter="${key}"]`).closest('.field'));
        advanced.open=Boolean(ticketFilters.department_id || ticketFilters.assignee_id || ticketFilters.overdue==='1');grid.append(advanced);grid.append(controls.querySelector('[data-ticket-search]'));
        const quick=document.createElement('div');quick.className='care-quick-tabs';quick.innerHTML=[['','Tất cả'],['mine','Cần tôi xử lý'],['unassigned','Chưa phân công'],['overdue','Quá hạn'],['waiting','Chờ xác nhận']].map(([key,label])=>`<button type="button" data-care-view="${key}" class="${ticketFilters.view===key?'active':''}">${label}</button>`).join('');controls.before(quick);
        quick.querySelectorAll('button').forEach(button=>button.addEventListener('click',()=>{ticketFilters.view=button.dataset.careView;ticketFilters.page=1;ticketFilters.status='';ticketFilters.overdue='0';loadPage(true);}));
        panel.querySelector('.panel-head>.badge').textContent=`${meta.total || 0} yêu cầu`;
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
            const categories={...requestGroups,support:'Hỗ trợ',warranty:'Bảo hành',installation:'Lắp đặt',maintenance:'Bảo trì',quotation:'Báo giá',order:'Đơn hàng',delivery:'Giao hàng',product:'Chất lượng sản phẩm'};
            const statuses={open:'Mới',in_progress:'Đang xử lý',pending_customer:'Chờ thông tin khách',resolved:'Đã xử lý / chờ xác nhận',closed:'Đã đóng',cancelled:'Đã hủy'};
            cells[2].innerHTML=`${escape(row.subject)}<br><small>Phụ trách: ${escape(row.assignee?.name || 'Chưa phân công')}</small>`;
            cells[3].textContent=categories[row.category] || row.category;
            const priority=cells[4].querySelector('.badge');if(priority)priority.textContent=({low:'Thấp',normal:'Bình thường',high:'Cao',urgent:'Khẩn cấp'})[row.priority] || row.priority;
            const status=cells[6].querySelector('.badge');if(status)status.textContent=statuses[row.status] || row.status;
            cells[5].innerHTML = `<div>Phản hồi: ${deadlineBadge(row.response_sla,row.response_due_at)}</div><div style="margin-top:8px">Xử lý: ${deadlineBadge(row.resolution_sla,row.resolution_due_at)}</div>`;
        });
    }

    function renderWarranties(rows,stats={}) {
        const now = new Date();
        const active = stats.active || 0;
        const today = new Date().toISOString().slice(0,10);
        const body = rows.length ? rows.map(row => {
            const isActive = row.warranty_status === 'in_warranty';
            const statusText = row.warranty_status === 'not_started' ? 'Chưa hiệu lực' : (isActive ? 'Còn hiệu lực' : 'Hết hạn');
            const customer = row.customer_name || row.customer?.name || '—';
            return `<tr data-serial="${escape(String(row.serial_number||'').toLowerCase())}" data-product="${escape(String(row.product_name||'').toLowerCase())}" data-customer="${escape(String(customer).toLowerCase())}" data-purchase="${escape(String(row.purchase_date||'').slice(0,10))}"><td><span class="record-code-chip">${escape(row.code)}</span></td><td><strong>${escape(row.serial_number)}</strong></td><td>${escape(row.product_name)}</td><td><strong>${escape(customer)}</strong><br><small>${escape(row.customer_email || '')}</small></td><td>${formatDate(row.purchase_date)}</td><td>${formatDate(row.warranty_end_at)}<br><small>${statusText}</small></td><td><span class="badge ${isActive?'success':'warning'}">${statusText}</span></td></tr>`;
        }).join('') : '<tr><td colspan="7"><div class="empty-state"><strong>Chưa có đăng ký bảo hành</strong><span>Thêm serial đầu tiên để bắt đầu quản lý.</span></div></td></tr>';
        return `<section class="service-warranty-stats"><article><span>Tổng bảo hành</span><strong>${stats.total || 0}</strong></article><article><span>Còn hiệu lực</span><strong>${stats.active || 0}</strong></article><article><span>Hết hạn</span><strong>${stats.expired || 0}</strong></article><article><span>Đăng ký hôm nay</span><strong>${stats.today || 0}</strong></article></section><section class="module-panel"><div class="panel-head"><div><h2>Danh sách bảo hành</h2><span>Tìm nhanh theo serial, sản phẩm hoặc khách hàng.</span></div></div><div class="warranty-filter-grid" data-warranty-filter><div class="field"><label>Số serial</label><input id="warrantyFilterSerial" placeholder="Nhập số serial cần tìm..."></div><div class="field"><label>Sản phẩm / Model</label><input id="warrantyFilterProduct" placeholder="Nhập model / tên sản phẩm..."></div><div class="field"><label>Khách hàng</label><input id="warrantyFilterCustomer" placeholder="Nhập tên khách hàng..."></div><div class="field"><label>Ngày mua</label><input id="warrantyFilterPurchase" type="date"></div><div class="warranty-filter-actions"><button class="btn primary" type="button" data-warranty-search>Tìm kiếm</button><button class="btn secondary" type="button" data-warranty-reset>Làm mới</button></div></div><div class="table-wrap"><table class="list-table"><thead><tr><th>Mã</th><th>Số serial</th><th>Sản phẩm / Model</th><th>Khách hàng</th><th>Ngày mua</th><th>Hết hạn</th><th>Trạng thái</th></tr></thead><tbody data-warranty-list>${body}</tbody></table></div></section>`;
    }
    function createTicket() {
        const source = data(); const customers = options(source.customers, r => `${r.code} - ${r.name}`); const users = [{ value: '', label: 'Chưa phân công' }, ...options(source.users, r => r.name)];
        VKModal.open('Tạo ticket hỗ trợ', [select('customer_id', 'Khách hàng đã có (không bắt buộc)', [{value:'',label:'Chưa liên kết hồ sơ khách hàng'},...customers]), select('assignee_id', 'Người xử lý', users), VKModal.field('subject', 'Tiêu đề'), '<div class="field"><label for="description">Mô tả yêu cầu</label><textarea id="description" name="description" rows="4"></textarea></div>', select('category', 'Nhóm yêu cầu', [...Object.entries(requestGroups).map(([value,label])=>({value,label})),{value:'warranty',label:'Bảo hành'},{value:'installation',label:'Lắp đặt'},{value:'maintenance',label:'Bảo trì'}]), select('priority', 'Ưu tiên', [{value:'normal',label:'Bình thường'}, {value:'low',label:'Thấp'}, {value:'high',label:'Cao'}, {value:'urgent',label:'Khẩn cấp'}])].join(''), async form => send('/service-tickets', { customer_id: value(form,'customer_id') ? Number(value(form,'customer_id')) : null, assignee_id:value(form,'assignee_id') ? Number(value(form,'assignee_id')) : null, subject:value(form,'subject'), description:value(form,'description'), category:value(form,'category'), priority:value(form,'priority'), source:'manual' }));
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
            extra.insertAdjacentHTML('beforeend','<div class="field"><label>Tên người phản ánh</label><input data-reporter-name maxlength="255" placeholder="Người gọi / người nhận hàng / đại diện đoàn"></div><div class="field"><label>Số điện thoại người phản ánh</label><input data-reporter-phone type="tel" inputmode="tel" maxlength="30" placeholder="Số liên hệ để phản hồi"></div>');
            body.appendChild(extra);
            const link = document.createElement('div');
            link.innerHTML = '<div class="field"><label>Liên kết chứng từ</label><select data-feedback-document-type><option value="">Không liên kết</option><option value="quotation">Báo giá</option><option value="order">Đơn hàng</option><option value="delivery">Phiếu giao hàng</option><option value="contract">Hợp đồng</option></select></div><div class="field"><label>Chứng từ liên quan</label><input data-feedback-search placeholder="Tìm mã chứng từ, tên khách hoặc số điện thoại" maxlength="120"><select data-feedback-document-id hidden disabled><option value="">Chọn loại chứng từ trước</option></select><button type="button" class="btn small" data-feedback-document-more hidden>Tải thêm</button><small data-feedback-document-error></small></div>';
            body.appendChild(link);
            const type = link.querySelector('[data-feedback-document-type]'), record = link.querySelector('[data-feedback-document-id]'), more = link.querySelector('[data-feedback-document-more]'), error = link.querySelector('[data-feedback-document-error]');
            const customer = body.querySelector('[name="customer_id"]');
            const documentSearch=link.querySelector('[data-feedback-search]');
            const documentField=record.closest('.field');documentField.classList.add('ticket-linked-document');
            const syncDocumentField=()=>{documentField.hidden=!type.value;};syncDocumentField();
            const suggestions=document.createElement('div');suggestions.className='ticket-document-suggestions';suggestions.hidden=true;suggestions.setAttribute('role','listbox');documentSearch.after(suggestions);
            documentSearch.setAttribute('role','combobox');documentSearch.setAttribute('aria-expanded','false');documentSearch.setAttribute('autocomplete','off');
            const showSuggestions=()=>{suggestions.hidden=false;documentSearch.setAttribute('aria-expanded','true');};
            const closeSuggestions=()=>{suggestions.hidden=true;documentSearch.setAttribute('aria-expanded','false');};
            function renderSuggestions(){suggestions.replaceChildren();for(const option of [...record.options].slice(1)){const button=document.createElement('button');button.type='button';button.setAttribute('role','option');button.textContent=option.textContent;button.addEventListener('click',()=>{record.value=option.value;record.dispatchEvent(new Event('change'));documentSearch.value=option.textContent;const reference=body.querySelector('[data-ticket-document]');if(reference)reference.value=option.textContent.split(' · ')[0];documentSearch.focus();closeSuggestions();});suggestions.append(button);}if(!suggestions.children.length)suggestions.textContent='Không tìm thấy chứng từ phù hợp.';showSuggestions();}
            documentSearch.addEventListener('focus',()=>{if(record.options.length>1)renderSuggestions();});
            documentSearch.addEventListener('keydown',event=>{if(event.key==='Escape')closeSuggestions();if(event.key==='ArrowDown'){event.preventDefault();showSuggestions();suggestions.querySelector('button')?.focus();}});
            suggestions.addEventListener('keydown',event=>{const buttons=[...suggestions.querySelectorAll('button')],index=buttons.indexOf(document.activeElement);if(event.key==='ArrowDown'||event.key==='ArrowUp'){event.preventDefault();buttons[(index+(event.key==='ArrowDown'?1:-1)+buttons.length)%buttons.length]?.focus();}if(event.key==='Escape'){closeSuggestions();documentSearch.focus();}});
            documentField.addEventListener('focusout',()=>setTimeout(()=>{if(!documentField.contains(document.activeElement))closeSuggestions();},0));
            let generation = 0, page = 1;
            async function documents(append = false) {
                const current = ++generation;
                if (!append) { record.innerHTML = '<option value="">Chọn chứng từ</option>'; page = 1; }
                record.disabled = true; more.hidden = true; error.textContent = '';suggestions.textContent='Đang tìm chứng từ…';showSuggestions();
                if (!type.value) return;
                try {
                    const result = await VKApi.request(`/service-desk/documents?type=${encodeURIComponent(type.value)}&search=${encodeURIComponent(documentSearch.value)}&page=${page}`,{cache:false});
                    if (current !== generation || !document.body.contains(record)) return;
                    for (const item of result.data || []) { const option = document.createElement('option'); option.value = item.id; option.textContent = item.code + (item.customer_name?' · '+item.customer_name:''); option.dataset.customerId=item.customer_id || ''; option.dataset.customerName=item.customer_name || ''; record.appendChild(option); }
                    record.disabled = false; more.hidden = result.meta.page >= result.meta.last_page;renderSuggestions();
                    if (record.options.length === 1) error.textContent = 'Không tìm thấy chứng từ. Bạn có thể tiếp nhận trước và xác minh sau.';
                } catch (failure) { if (current === generation) {record.disabled=false;error.textContent = failure.message || 'Chưa tải được chứng từ. Bạn vẫn có thể tiếp nhận không liên kết.';} }
            }
            type.addEventListener('change',()=>{documentSearch.value='';record.value='';body.querySelector('[data-ticket-document]').value='';closeSuggestions();syncDocumentField();if(type.value)documents();});
            let searchTimer;documentSearch.addEventListener('input',()=>{record.value='';body.querySelector('[data-ticket-document]').value='';clearTimeout(searchTimer);searchTimer=setTimeout(()=>documents(),300);});
            record.addEventListener('change',()=>{const option=record.selectedOptions[0];if(!option?.dataset.customerId)return;if(![...customer.options].some(o=>o.value===option.dataset.customerId))customer.add(new Option(option.dataset.customerName,option.dataset.customerId));customer.value=option.dataset.customerId;});
            customer?.addEventListener('change',()=>{record.value='';documentSearch.value='';closeSuggestions();document.querySelector('[data-ticket-document]').value='';});
            more.addEventListener('click',()=>{ page++; documents(true); });
            const category = body.querySelector('[name="category"]');
            if (category) for (const [key,label] of Object.entries({quotation:'Báo giá',order:'Đơn hàng',delivery:'Giao hàng',product:'Chất lượng sản phẩm'})) { const option = document.createElement('option'); option.value = key; option.textContent = label; category.appendChild(option); }
            layoutTicketForm(body);
            const assignment=body.querySelector('[name="assignee_id"]').closest('.field');assignment.insertAdjacentHTML('afterend',peopleFields('','','',false));
            const specific=document.createElement('section');specific.className='ticket-form-section ticket-specific-section';specific.innerHTML='<h3>'+intakeIcon('chat')+'<span>Thông tin theo nhóm yêu cầu</span></h3><div class="ticket-form-grid" data-specific-fields></div>';body.querySelector('.ticket-create-layout').append(specific);
            const draftByGroup={};let previousCategory=category.value;
            const syncGroup=()=>{draftByGroup[previousCategory]=collectDetails(specific);previousCategory=category.value;specific.querySelector('[data-specific-fields]').innerHTML=detailFields(category.value,draftByGroup[category.value]);const route=data().routing?.[category.value] || {};for(const key of ['department_id','coordinator_id']){const input=body.querySelector(`[name="${key}"]`);if(input)input.value=route[key] || '';}body.querySelector('[name="department_id"]')?.dispatchEvent(new Event('change'));body.querySelector('[name="assignee_id"]').value=route.assignee_id || '';};
            bindDepartment(body);category.addEventListener('change',syncGroup);syncGroup();
            const hints={ticket_advice:'Ghi câu hỏi và nội dung tư vấn theo chương trình chính thức.',group_sales:'Ghi tên đơn vị, người liên hệ, số khách, ngày đi và dịch vụ cần. Bàn giao Sales qua hồ sơ lead; không coi chuyển phiếu là đã chốt đơn.',resort:'Ghi ngày đến/đi, số phòng, số khách và dịch vụ cần kiểm tra với Resort.',online_payment:'Ghi mã đơn, mã giao dịch, số tiền và tình trạng vé. Hoàn tiền cần chứng từ và phê duyệt riêng.',onsite:'Ghi điểm phục vụ, nội dung hỗ trợ và miễn giảm nếu có.',lost_property:'Ghi món đồ, đặc điểm, nơi/thời gian mất và cách liên hệ. Xác minh chủ sở hữu trước khi trả đồ.',complaint:'Ghi nguyên văn phản ánh và mong muốn của khách. Vượt thẩm quyền báo quản lý; phản hồi khách trước khi đóng.',suggestion:'Ghi góp ý, dịch vụ/khu vực liên quan để tổng hợp báo cáo.',aftercare:'Ghi ngày sử dụng, nội dung chăm sóc, ngày và người gửi cảm ơn/xin lỗi.'};
            body.querySelector('[data-reporter-phone]')?.addEventListener('input',event=>{event.target.value=event.target.value.replace(/[^0-9+().\s-]/g,'');});
            const help=document.createElement('p');help.className='form-note intake-group-hint';help.setAttribute('aria-live','polite');body.querySelector('[name="priority"]').closest('.field').after(help);
            const sync=()=>help.textContent=hints[category.value] || '';category.addEventListener('change',sync);sync();
        }
    }
    function intakeIcon(kind) {
        const paths={person:'<circle cx="12" cy="7" r="4"/><path d="M4 21v-2a8 8 0 0 1 16 0v2"/>',settings:'<path d="m9 3 1-1h4l1 3 3 1 3 1v4l-2 2 1 3-3 3-3-1-2 2H8l-1-3-3-1-2-3 2-3-1-3 3-3Z"/><circle cx="12" cy="12" r="3"/>',link:'<path d="m10 13 4-4m-6 6-2 2a4 4 0 0 1-6-6l4-4a4 4 0 0 1 6 0m4 2 2-2a4 4 0 0 1 6 6l-4 4a4 4 0 0 1-6 0"/>',chat:'<path d="M21 11a9 9 0 0 1-9 9H4l-3 3 1-8a9 9 0 1 1 19-4Z"/><path d="M7 10h.01M12 10h.01M17 10h.01"/>'};
        return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'+(paths[kind] || paths.chat)+'</svg>';
    }
    function layoutTicketForm(body) {
        const modal=body.closest('.modal');
        modal?.classList.add('ticket-create-modal');
        document.getElementById('modalTitle').textContent='Tiếp nhận yêu cầu khách hàng';
        document.getElementById('modalSubmit').textContent='Tạo yêu cầu';
        const sections=[
            ['Nội dung tiếp nhận',['[data-reporter-name]','[data-reporter-phone]','[data-ticket-source]','[name="subject"]','[name="description"]']],
            ['Phân công và thời hạn',['[name="category"]','[name="priority"]','[name="assignee_id"]','[data-ticket-deadline]']],
            ['Liên kết bổ sung (không bắt buộc)',['[name="customer_id"]','[data-feedback-document-type]','[data-feedback-document-id]','[data-ticket-document]']]
        ];
        const layout=document.createElement('div');layout.className='ticket-create-layout';
        for(const [title,selectors] of sections) {
            const section=document.createElement(title.startsWith('Liên kết')?'details':'section');section.className='ticket-form-section';
            if(title.startsWith('Liên kết')){section.classList.add('ticket-optional-links');section.open=true;}
            const heading=document.createElement(title.startsWith('Liên kết')?'summary':'h3');heading.innerHTML=intakeIcon(title.startsWith('Liên kết')?'link':title.startsWith('Phân công')?'settings':'person')+'<span>'+title+'</span>';section.appendChild(heading);
            const grid=document.createElement('div');grid.className='ticket-form-grid';
            for(const selector of selectors) {
                const input=body.querySelector(selector),field=input?.closest('.field');if(!field)continue;
                if(['description'].includes(input.name))field.classList.add('ticket-full-row');
                if(input.matches('[data-ticket-document]'))field.classList.add('ticket-manual-reference');
                grid.appendChild(field);
            }
            section.appendChild(grid);layout.appendChild(section);
        }
        // Move existing controls rather than rebuilding them: preserve values and handlers.
        body.replaceChildren(layout);
        const description=body.querySelector('[name="description"]');description.maxLength=3000;description.placeholder='Mô tả chi tiết yêu cầu của khách hàng…';
        const counter=document.createElement('small');counter.className='intake-character-count';description.after(counter);const updateCount=()=>counter.textContent=description.value.length+'/3000';description.addEventListener('input',updateCount);updateCount();
        body.querySelector('[name="subject"]').placeholder='Nhập tiêu đề yêu cầu';
        body.querySelector('[data-reporter-name]').placeholder='Nhập họ tên / tên khách hàng';
    }
    async function openTicket(id,readOnly=false) {
        const response=await VKApi.request(`/service-tickets/${id}`, {cache:false});
        const ticket=response.data;if(response.meta?.groups)sourceData.groups=response.meta.groups;
        const canEdit = !readOnly && window.VKUser?.permissions?.includes('service.ticket.manage') && !['closed','cancelled'].includes(ticket.status);
        const statuses={open:'Mới',in_progress:'Đang xử lý',pending_customer:'Chờ thông tin khách',resolved:'Đã xử lý · Chờ xác nhận',closed:'Đã đóng',cancelled:'Đã hủy'};
        const historyText = text => String(text || '').replace(/\b(open|in_progress|pending_customer|resolved|closed|cancelled)\b/g, code=>statuses[code]).replace('Tạo ticket dịch vụ.','Tiếp nhận yêu cầu khách hàng.');
        const history = (ticket.worklogs || []).filter(log=>log.action!=='sla_policy').map(log => `<li><div><strong>${escape(log.user?.name || 'Hệ thống')}</strong><time>${formatDate(log.worked_at,true)}</time></div><p>${escape(historyText(log.content))}</p></li>`).join('');
        const step=ticket.status==='open'?0:ticket.status==='in_progress'||ticket.status==='pending_customer'?1:ticket.status==='resolved'?2:3;
        const groupFields=data().groups?.[ticket.category]?.fields || {};
        const info=Object.entries(groupFields).map(([key,field])=>{const v=ticket.request_details?.[key];return `<div><span>${escape(field.label)}</span><strong>${escape(field.type==='select'?field.options[v] || 'Chưa cập nhật':field.type==='datetime-local'?formatDate(v,true):v || 'Chưa cập nhật')}</strong></div>`;}).join('');
        let content = `<div class="ticket-process-strip">${['Tiếp nhận / Phân công','Đang xử lý','Phản hồi khách','Hoàn tất'].map((label,index)=>`<span class="${ticket.status==='cancelled'?'':index===step?'current':index<step?'done':''}"><b>${index+1}</b>${label}</span>`).join('')}</div><div class="ticket-detail-layout"><div class="ticket-detail-overview"><section class="ticket-detail-card"><div class="ticket-detail-heading"><h3>${escape(ticket.subject)}</h3><span class="badge ${['closed','resolved'].includes(ticket.status)?'success':'info'}">${escape(statuses[ticket.status] || 'Chưa xác định')}</span></div><p class="form-note">${escape(requestGroups[ticket.category] || 'Yêu cầu hỗ trợ')} · Người xử lý: ${escape(ticket.assignee?.name || 'Chưa phân công')}${ticket.status==='open' && ticket.assignee_id ? ' · Chờ tiếp nhận' : ''}</p><p class="form-note">Người phản ánh: ${escape(ticket.request_details?.reporter_name || 'Chưa ghi nhận')} · ${escape(ticket.request_details?.reporter_phone || 'Chưa có số điện thoại')}</p><p class="ticket-detail-description">${escape(ticket.description || 'Chưa có mô tả.')}</p><div class="ticket-detail-deadlines"><div><span>Hạn phản hồi</span>${deadlineBadge(ticket.response_sla,ticket.response_due_at)}</div><div><span>Hạn xử lý</span>${deadlineBadge(ticket.resolution_sla,ticket.resolution_due_at)}</div></div><p class="ticket-reference">Chứng từ tham chiếu: <strong>${escape(ticket.related_document || 'Chưa liên kết')}</strong></p><p class="form-note">Bộ phận: ${escape(ticket.department?.name || 'Chưa chọn')} · CSKH theo dõi: ${escape(ticket.coordinator?.name || 'Người tiếp nhận ban đầu')}</p></section>${info?`<section class="ticket-detail-card"><h3>Thông tin theo nhóm yêu cầu</h3><div class="service-specific-summary">${info}</div></section>`:''}<section class="ticket-detail-card"><h3>Lịch sử xử lý</h3><ul class="ticket-detail-history">${history || '<li>Chưa có lịch sử.</li>'}</ul></section>`;
        content += `<section class="ticket-evidence"><h3>Ảnh minh chứng</h3><ul>${(ticket.attachments || []).map(a=>`<li><button class="btn small" type="button" data-ticket-evidence-download="${escape(a.id)}">${escape(a.original_name)}</button> <small>${Math.ceil(a.bytes/1024)} KB</small></li>`).join('') || '<li>Chưa có ảnh minh chứng.</li>'}</ul>${canEdit?'<label class="ticket-evidence-picker"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><rect x="3" y="3" width="18" height="18" rx="3"/><path d="m3 16 5-5 4 4 3-3 6 6M15 7h.01"/></svg><span>Thêm ảnh minh chứng</span><input type="file" accept="image/jpeg,image/png,image/webp" data-ticket-evidence-file></label><small class="ticket-evidence-help">Chọn ảnh để tải lên · JPG, PNG, WebP · Tối đa 5 MB/ảnh</small>':''}<p data-ticket-evidence-result role="status"></p></section>`;
        content += '</div>';
        if (canEdit) {
            content += '<section class="ticket-detail-card ticket-detail-editor"><h3>Cập nhật xử lý</h3>';
            content += VKModal.select('customer_confirmation','Xác nhận khách hàng',[{value:'not_contacted',label:'Chưa xác nhận'},{value:'accepted',label:'Khách đồng ý kết quả xử lý'},{value:'rejected',label:'Khách chưa đồng ý — cần xử lý lại'}]);
            if(ticket.status==='resolved')content+='<p class="form-note">Khách chưa đồng ý: chọn Đang xử lý, ghi lý do và chọn Khách chưa đồng ý. Giữ hạn ban đầu. Muốn đóng: ghi nhật ký đã trả lời khách về kết quả mới nhất trước.</p>';
            content += VKModal.select('operation','Thao tác',[{value:'worklog',label:'Ghi nhật ký xử lý'},{value:'status',label:'Cập nhật trạng thái và phân công'},...(ticket.sla_clock?.allow_pause?[{value:ticket.sla_clock.paused?'resume':'pause',label:ticket.sla_clock.paused?'Tiếp tục SLA':'Tạm dừng SLA — chờ thông tin khách'}]:[])]);
            content += '<div class="field"><label for="content">Nội dung xử lý</label><textarea name="content" id="content" rows="3"></textarea></div>';
            content += VKModal.select('visibility','Loại ghi nhận',[{value:'internal',label:'Trao đổi nội bộ'},{value:'public',label:'Đã trả lời khách qua điện thoại / Zalo / email'}]);
            content += '<p class="form-note">CRM lưu lịch sử, không tự gửi tin nhắn. Sau khi xử lý, ghi nhận đã trả lời khách; chỉ đóng khi khách đồng ý.</p>';
            content += VKModal.select('status','Trạng thái',['open','in_progress','pending_customer','resolved','closed','cancelled'].map(status=>({value:status,label:({open:'Mới',in_progress:ticket.status==='resolved'?'Xử lý lại — khách chưa đồng ý':'Đang xử lý',pending_customer:'Chờ thông tin khách',resolved:'Đã xử lý / chờ khách xác nhận',closed:'Đóng — khách đồng ý',cancelled:'Hủy'})[status]})),ticket.status);
            content += peopleFields(ticket.department_id,ticket.assignee_id,ticket.coordinator_id);
            content += `<div data-ticket-details-editor><h3>Thông tin bổ sung trước khi đóng</h3><div class="ticket-form-grid">${detailFields(ticket.category,ticket.request_details || {})}</div></div>`;
            content += VKModal.field('resolution_code','Mã kết quả xử lý');
            content += `<p class="form-note">SLA: ${ticket.sla_clock?.clock==='business'?'theo lịch làm việc đã cấu hình':'giờ liên tục'}. ${ticket.sla_clock?.paused?'Đang tạm dừng; cần tiếp tục trước khi đổi trạng thái.':''}</p>`;
        }
        if (canEdit) content += '</section>';
        content += '</div>';
        VKModal.open(`Yêu cầu ${ticket.code}`,content,async form=>{
            if (['pause','resume'].includes(value(form,'operation'))) {
                await VKApi.request(`/service-tickets/${id}/sla-clock`,{method:'POST',body:JSON.stringify({pause:value(form,'operation')==='pause',reason:value(form,'content')})});
            } else if (value(form,'operation') === 'worklog') {
                if (!String(value(form,'content') || '').trim()) throw new Error('Vui lòng nhập nội dung xử lý.');
                await VKApi.request(`/service-tickets/${id}/worklogs`,{method:'POST',body:JSON.stringify({content:value(form,'content'),visibility:value(form,'visibility')})});
            } else {
                await VKApi.request(`/service-tickets/${id}`,{method:'PUT',body:JSON.stringify({status:value(form,'status'),assignee_id:value(form,'assignee_id') ? Number(value(form,'assignee_id')) : null,resolution_code:value(form,'resolution_code'),resolution_note:value(form,'content'),customer_confirmation:value(form,'customer_confirmation'),department_id:Number(value(form,'department_id'))||null,coordinator_id:Number(value(form,'coordinator_id'))||null,request_details:collectDetails(form)})});
            }
            VKModal.close(); if(embeddedRefresh)await embeddedRefresh();else await loadPage(true);
        },{className:'ticket-detail-modal',hideSubmit:!canEdit,submitText:'Lưu xử lý',cancelText:'Đóng'});
        if(canEdit && ticket.status==='open' && Number(ticket.assignee_id)===Number(window.VKUser?.id)) {
            const button=document.createElement('button');button.type='button';button.className='btn primary';button.textContent='Tiếp nhận xử lý';
            document.querySelector('.ticket-detail-modal .modal-foot').prepend(button);
            button.addEventListener('click',async()=>{button.disabled=true;try{await VKApi.request(`/service-tickets/${id}/accept`,{method:'POST'});VKModal.close();if(embeddedRefresh)await embeddedRefresh();else await loadPage(true);await openTicket(id);}catch(error){VKModal.toast(error.message,'danger');button.disabled=false;}});
        }
        const title=document.getElementById('modalTitle');
        title.textContent='Yêu cầu ';
        const code=document.createElement('span');code.className='ticket-code';code.textContent=ticket.code;title.appendChild(code);
        const modalBody=document.getElementById('modalBody');
        const operation=modalBody.querySelector('[name="operation"]');
        if (ticket.customer_id && window.VKUser?.permissions?.includes('master.view')) {
            const overview = modalBody.querySelector('.ticket-detail-overview');
            const faceIdPanel = window.VKCustomerFaceId?.mount(overview, ticket.customer_id, false);
            if (faceIdPanel) overview.insertBefore(faceIdPanel.button, faceIdPanel.panel);
        }
        const syncEditor=()=>{
            const mode=operation?.value || 'worklog';
            for(const name of ['status','assignee_id','department_id','coordinator_id','resolution_code','customer_confirmation']) {
                const field=modalBody.querySelector(`[name="${name}"]`)?.closest('.field');
                if(field) field.hidden=mode!=='status';
            }
            const detailsEditor=modalBody.querySelector('[data-ticket-details-editor]');if(detailsEditor)detailsEditor.hidden=mode!=='status';
            const visibility=modalBody.querySelector('[name="visibility"]')?.closest('.field');
            if(visibility) visibility.hidden=mode!=='worklog';
        };
        bindDepartment(modalBody);
        operation?.addEventListener('change',syncEditor);syncEditor();
        modalBody.querySelector('[data-ticket-evidence-file]')?.addEventListener('change',async event=>{
            const button=event.currentTarget,output=modalBody.querySelector('[data-ticket-evidence-result]');
            const file=modalBody.querySelector('[data-ticket-evidence-file]').files[0];
            if(!file){output.textContent='Chọn ảnh trước khi tải lên.';return;}
            if(file.size>5*1024*1024){output.textContent='Ảnh vượt 5 MB.';return;}
            button.disabled=true;output.textContent='Đang tải ảnh lên…';
            try{
                const draft=Array.from(modalBody.querySelectorAll('input[name],select[name],textarea[name]'),input=>[input.name,input.value]);
                const body=new FormData();body.append('file',file);await VKApi.request(`/service-tickets/${id}/attachments`,{method:'POST',body});await openTicket(id);
                const refreshed=document.getElementById('modalBody');
                for(const [name,value]of draft)for(const input of refreshed.querySelectorAll('input[name],select[name],textarea[name]'))if(input.name===name)input.value=value;
                refreshed.querySelector('[name="operation"]')?.dispatchEvent(new Event('change'));
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
        const apply=()=>{for(const key of ['serial','product','customer'])warrantyFilters[key]=fields[key].value.trim();warrantyFilters.purchase_date=fields.purchase.value;warrantyFilters.page=1;loadPage(true);};
        for(const key of ['serial','product','customer'])fields[key].value=warrantyFilters[key];fields.purchase.value=warrantyFilters.purchase_date;
        root.querySelector('[data-warranty-search]').addEventListener('click',apply);
        root.querySelector('[data-warranty-reset]').addEventListener('click',()=>{Object.assign(warrantyFilters,{serial:'',product:'',customer:'',purchase_date:'',page:1});loadPage(true);});
        Object.values(fields).forEach(input=>input.addEventListener('keydown',event=>{if(event.key==='Enter'){event.preventDefault();apply();}}));
    }
    function bind() {
        document.querySelectorAll('[data-service-create]').forEach(button => { if (button.dataset.bound) return; button.dataset.bound = '1'; button.addEventListener('click', () => { ({ticket:createTicket,warranty:createWarranty}[button.dataset.serviceCreate] || (()=>{}))(); if (button.dataset.serviceCreate === 'ticket') addDeadlineInput(); }); });
        const root = document.querySelector('[data-service-desk-page]');
        if (root && !root.dataset.loaded) { root.dataset.loaded = '1'; loadPage(); }
    }
    document.addEventListener('click', event => {
        const pager=event.target.closest('[data-warranty-page]');if(pager){warrantyFilters.page=Number(pager.dataset.warrantyPage);loadPage(true);return;}
        if(event.target.closest('[data-service-routing]'))configureRouting().catch(error=>VKModal.toast(error.message,'danger'));
        if(event.target.closest('[data-service-context-create]'))openContextTicket().catch(error=>VKModal.toast(error.message,'danger'));
        if (event.target.closest('[data-warranty-import]')) importWarranties();
        if (event.target.closest('[data-service-retry]')) loadPage();
        const ticket = event.target.closest('[data-ticket-open]') || (!event.target.closest('a,input,select,textarea,button') && event.target.closest('[data-ticket-row]'));
        if (!event.target.closest('[data-ticket-open]') && window.getSelection()?.toString()) return;
        if (ticket) openTicket(ticket.dataset.ticketOpen || ticket.dataset.ticketRow).catch(error=>VKModal.toast(error.message || 'Không thể mở ticket.','danger'));
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
    window.VKServiceDesk={async embed(root,context){
        let page=1,sequence=0;
        const documentLabel={order:'đơn hàng',quotation:'báo giá',contract:'hợp đồng',delivery:'phiếu giao hàng'}[context.document_type] || 'chứng từ';
        const statuses={open:'Chờ tiếp nhận',in_progress:'Đang xử lý',pending_customer:'Chờ khách bổ sung',resolved:'Chờ xác nhận kết quả',closed:'Đã đóng',cancelled:'Đã hủy'};
        const refresh=async()=>{
            const version=++sequence;
            try{
                const records=await orderRequests(context,page);
                if(!root.isConnected || version!==sequence)return;
                const stats=records.meta.stats || {},last=Math.max(1,Math.ceil((records.meta.total || 0)/10));
                root.innerHTML=`<section class="order-service-card"><header><div><h3>CSKH & Khiếu nại của ${documentLabel}</h3><p>${stats.total || 0} yêu cầu · <b>${stats.open || 0} chưa đóng</b> · ${stats.closed || 0} đã đóng</p></div></header><div class="table-wrap"><table class="list-table"><thead><tr><th>Mã yêu cầu</th><th>Nội dung</th><th>Nhóm</th><th>Người xử lý</th><th>Trạng thái</th><th>Hạn xử lý</th></tr></thead><tbody>${(records.data || []).map(ticket=>`<tr data-order-care-ticket="${ticket.id}" tabindex="0" role="button"><td><span class="ticket-code">${escape(ticket.code)}</span></td><td><strong>${escape(ticket.subject)}</strong></td><td>${escape(requestGroups[ticket.category] || 'Hỗ trợ')}</td><td>${escape(ticket.assignee?.name || 'Chưa phân công')}</td><td><span class="badge ${ticket.status==='closed'?'success':ticket.status==='resolved'?'info':'warning'}">${statuses[ticket.status] || escape(ticket.status)}</span></td><td>${formatDate(ticket.resolution_due_at,true)}</td></tr>`).join('') || '<tr><td colspan="6"><div class="empty-state"><strong>Chứng từ chưa có yêu cầu CSKH</strong><span>Các yêu cầu đã được CSKH liên kết sẽ hiển thị tại đây.</span></div></td></tr>'}</tbody></table></div>${last>1?`<footer><button class="btn small" data-order-care-prev ${page<=1?'disabled':''}>Trang trước</button><span>Trang ${page} / ${last}</span><button class="btn small" data-order-care-next ${page>=last?'disabled':''}>Trang sau</button></footer>`:''}</section>`;
                const activate=()=>{embeddedRoot=root;embeddedContext=context;embeddedRefresh=()=>root.isConnected?refresh():Promise.resolve();};
                root.querySelectorAll('[data-order-care-ticket]').forEach(row=>{const open=()=>{activate();openTicket(row.dataset.orderCareTicket,true).catch(error=>VKModal.toast(error.message,'danger'));};row.addEventListener('click',open);row.addEventListener('keydown',event=>{if(event.key==='Enter'){event.preventDefault();open();}});});
                root.querySelector('[data-order-care-prev]')?.addEventListener('click',()=>{page--;refresh();});root.querySelector('[data-order-care-next]')?.addEventListener('click',()=>{page++;refresh();});
            }catch(error){if(root.isConnected && version===sequence){root.innerHTML='<section class="order-service-card"><header><h3>CSKH & Khiếu nại</h3><button class="btn small" data-order-care-retry>Thử lại</button></header><p class="form-note">'+escape(error.message || 'Chưa tải được yêu cầu của đơn hàng.')+'</p></section>';root.querySelector('[data-order-care-retry]').addEventListener('click',refresh);}}
        };await refresh();
    }};
    document.addEventListener('vk:ready',()=>{if(document.querySelector('[data-service-desk-page]')){embeddedContext=null;embeddedRefresh=null;}});
    window.loadServiceTickets = bind; window.loadWarrantyClaims = bind; document.addEventListener('vk:ready', bind); bind();
    if(window.vkTicketRefreshTimer)clearInterval(window.vkTicketRefreshTimer);
    window.vkTicketRefreshTimer=setInterval(()=>{
        const root=document.querySelector('[data-service-desk-page="tickets"]');
        if(root && !document.hidden && !root.contains(document.activeElement) && !document.querySelector('#modalBackdrop.open'))loadPage(true);
    },60000);
})();
