(function () {
    const types = {
        care: ['Lịch chăm sóc khách hàng', 'master.view', 'customer_id', ['new','in_progress','overdue','completed','cancelled']],
        care_gaps: ['Khách chưa có lịch chăm sóc', 'master.view', 'customer_id', []],
        service: ['Phản hồi khách hàng và SLA', 'service.ticket.view', 'customer_id', ['open','in_progress','pending_customer','resolved','closed','cancelled']],
        sales: ['Đơn bán hàng', 'sales.order.view', 'customer_id', ['draft','confirmed','awaiting_delivery','delivered','invoiced','completed','cancelled']],
        invoices: ['Hóa đơn bán hàng', 'finance.invoice.view', 'customer_id', ['draft','issued','paid','partially_paid','cancelled']],
        purchase: ['Đơn mua hàng', 'procurement.po.view', 'supplier_id', ['draft','approved','received','rejected','cancelled']],
        inventory: ['Tồn kho hiện tại', 'master.view', 'warehouse_id', []],
        transactions: ['Giao dịch kho', 'master.view', 'warehouse_id', ['receipt','issue','stock_take_adjustment']],
        receivables: ['Công nợ theo hóa đơn', 'finance.payment.view', 'customer_id', []],
    };
    let version = 0, loadedQuery = null, page = 1, polling = null, loading = false, choices = {}, canExport = false;
    const escape = value => VKTable.escapeHtml(String(value ?? ''));
    const form = () => document.getElementById('businessReportFilters');
    const exportButton = () => document.getElementById('businessReportExport');
    function date(value) { return `${value.getFullYear()}-${String(value.getMonth()+1).padStart(2,'0')}-${String(value.getDate()).padStart(2,'0')}`; }
    function query() {
        const f = form(), params = new URLSearchParams({type: f.elements.type.value});
        const type = f.elements.type.value;
        for (const name of ['from','to','q','status','user_id','sku_id','debt_status']) {
            const input = f.elements[name];
            if (!input.closest('label').hidden && input.value) params.set(name, input.value);
        }
        if (f.elements.party_id.value) params.set(types[type][2], f.elements.party_id.value);
        return params.toString();
    }
    function configure() {
        const f = form(), type = f.elements.type.value;
        if (!types[type]) return;
        const stock = ['inventory','transactions'].includes(type);
        f.querySelectorAll('[data-date-filter]').forEach(label => { label.hidden = ['inventory','receivables','care_gaps'].includes(type); const input=label.querySelector('input');if(input) input.required = !label.hidden; });
        f.querySelector('[data-party-filter]').firstChild.textContent = type === 'purchase' ? 'Nhà cung cấp' : stock ? 'Kho' : 'Khách hàng';
        f.querySelector('[data-user-filter]').hidden = !['sales','invoices','receivables','care','service','care_gaps'].includes(type);
        f.querySelector('[data-sku-filter]').hidden = !stock;
        f.querySelector('[data-debt-filter]').hidden = type !== 'receivables';
        f.querySelector('[data-status-filter]').hidden = !types[type][3].length;
        f.elements.status.innerHTML = '<option value="">Tất cả trạng thái</option>' + types[type][3].map(status => `<option value="${status}">${escape(VKTable.translateStatus(status) || status)}</option>`).join('');
        for (const name of ['party_id','user_id','sku_id']) f.elements[name].innerHTML = '<option value="">Tất cả được xem</option>';
        f.elements.debt_status.value = '';
        choices = {};
    }
    function fillOptions(report) {
        const f = form();
        for (const [name, rows] of [['party_id',report.options.parties],['user_id',report.options.users],['sku_id',report.options.skus]]) {
            const selected = f.elements[name].value;
            choices[name] ||= new Map();
            rows.forEach(row => choices[name].set(row.id,row));
            f.elements[name].innerHTML = '<option value="">Tất cả được xem</option>' + [...choices[name].values()].map(row => `<option value="${row.id}">${escape(row.name)}</option>`).join('');
            f.elements[name].value = selected;
        }
    }
    async function load(silent = false) {
        if (!form() || !form().elements.type.value || (silent && loading)) return;
        const f = form(), key = query(), current = ++version;
        loading = true; loadedQuery = null; exportButton().disabled = true;
        const root = document.getElementById('businessReportRoot');
        const sync = document.getElementById('businessReportStatus');
        if (!silent) sync.textContent = 'Đang tải báo cáo…';
        try {
            const result = await VKApi.request('/business-reports?' + key + '&page=' + page, {cache:false});
            if (current !== version || form() !== f) return;
            fillOptions(result.data);
            const report = result.data;
            const content = VKTable.renderTable(report.columns.map((label,index) => ({label,render: row => {
                const value = row[index];
                if (typeof value === 'number') return VKTable.money(value);
                if (typeof value === 'string' && /^\d{4}-\d{2}-\d{2}T/.test(value)) return escape(new Date(value).toLocaleString('vi-VN'));
                if (typeof value === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(value)) return escape(value.split('-').reverse().join('/'));
                return index === 0 ? `<span class="record-code-chip">${escape(value)}</span>` : escape(value);
            }})), report.rows, 'Không có dữ liệu phù hợp') + `<div class="business-report-totals">${Object.entries(report.totals).map(([index,value])=>`<div><small>${escape(report.columns[index])}</small><strong>${VKTable.money(value)}</strong></div>`).join('')}</div>`;
            if (root.innerHTML !== content) root.innerHTML = content;
            const pages = Math.max(1, Math.ceil(report.meta.count / 50));
            document.getElementById('businessReportPages').innerHTML = `<button class="btn small" data-report-page="${page-1}" ${page<=1?'disabled':''}>Trước</button><span>Trang ${page}/${pages} · ${report.meta.count} dòng</span><button class="btn small" data-report-page="${page+1}" ${page>=pages?'disabled':''}>Sau</button>`;
            sync.textContent = `${report.meta.basis} Cập nhật ${new Date(report.meta.generated_at).toLocaleTimeString('vi-VN')}.`;
            loadedQuery = key; canExport = report.meta.can_export === true; exportButton().disabled = !canExport;
            exportButton().title = canExport ? 'Xuất toàn bộ kết quả theo bộ lọc' : 'Chưa được cấp quyền xuất Excel';
        } catch(error) {
            if (current === version && form() === f) sync.textContent = error.message || 'Không tải được báo cáo.';
        } finally { if (current === version) loading = false; }
    }
    async function download() {
        if (!canExport || !loadedQuery || loadedQuery !== query()) return;
        const key = loadedQuery, button = exportButton(); button.disabled = true; button.textContent = 'Đang xuất…';
        const controller = new AbortController(), timer = setTimeout(()=>controller.abort(),60000);
        try {
            const response = await fetch('/api/v1/business-reports/export?' + key, {headers:{Authorization:`Bearer ${VKApi.token()}`},signal:controller.signal});
            if (!response.ok) { const error = await response.json(); throw new Error(error.message || 'Không xuất được Excel.'); }
            const url = URL.createObjectURL(await response.blob()), link = document.createElement('a');
            link.href = url; link.download = /filename="?([^";]+)"?/.exec(response.headers.get('Content-Disposition') || '')?.[1] || 'Bao-cao.xlsx'; link.click();
            setTimeout(()=>URL.revokeObjectURL(url),1000);
        } catch(error) { VKModal.toast(error.name === 'AbortError' ? 'Xuất quá lâu. Hãy thu hẹp bộ lọc.' : error.message, 'danger'); }
        finally { clearTimeout(timer); if(button.isConnected) {button.textContent='Xuất Excel';button.disabled=!canExport || !form() || loadedQuery===null || loadedQuery!==query();} }
    }
    function initialize() {
        const f = form(); if (!f || f.dataset.bound) return; f.dataset.bound = '1';
        f.elements.type.innerHTML = Object.entries(types).filter(([,config])=>VKLayout.hasPermission(config[1])).map(([key,config])=>`<option value="${key}">${config[0]}</option>`).join('');
        const today = new Date(); f.elements.from.value=date(new Date(today.getFullYear(),today.getMonth(),1));f.elements.to.value=date(today);
        const initial=new URLSearchParams(window.location.search);
        if([...f.elements.type.options].some(option=>option.value===initial.get('type')))f.elements.type.value=initial.get('type');
        for(const key of ['from','to'])if(/^\d{4}-\d{2}-\d{2}$/.test(initial.get(key)||''))f.elements[key].value=initial.get(key);
        if(initial.has('from') || initial.has('to'))f.elements.range.value='custom';
        configure();
        f.addEventListener('change',event=>{
            loadedQuery=null;exportButton().disabled=true;page=1;
            if(event.target.name==='type') configure();
            if(event.target.name==='range' && event.target.value!=='custom') {
                const now=new Date(), month=event.target.value==='year'?0:event.target.value==='quarter'?Math.floor(now.getMonth()/3)*3:now.getMonth();
                f.elements.from.value=date(new Date(now.getFullYear(),month,1));f.elements.to.value=date(now);
            }
            if(['from','to'].includes(event.target.name)) f.elements.range.value='custom';
            load();
        });
        f.addEventListener('input',()=>{loadedQuery=null;exportButton().disabled=true;});
        f.addEventListener('submit',event=>{event.preventDefault();page=1;load();});
        exportButton().addEventListener('click',download);
        document.getElementById('businessReportReset').addEventListener('click',()=>{f.elements.q.value='';const now=new Date();f.elements.range.value='month';f.elements.from.value=date(new Date(now.getFullYear(),now.getMonth(),1));f.elements.to.value=date(now);configure();page=1;load();});
        document.getElementById('businessReportPages').addEventListener('click',event=>{const button=event.target.closest('[data-report-page]');if(button&&!button.disabled){page=Number(button.dataset.reportPage);load();}});
        if (!f.elements.type.value) document.getElementById('businessReportStatus').textContent='Bạn chưa có quyền xem các báo cáo nghiệp vụ.';
        load(); clearInterval(polling); polling=setInterval(()=>{if(!document.hidden&&loadedQuery===query())load(true);},30000);
    }
    window.loadBusinessReports=initialize;
    document.addEventListener('vk:ready',initialize);
    document.addEventListener('vk:page:changed',()=>{if(!form()){clearInterval(polling);version++;loading=false;loadedQuery=null;}});
})();
