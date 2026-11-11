(function () {
    const css = '/assets/css/hospitality-integration.css?v=20261110-nav1';
    if (!document.querySelector(`link[href="${css}"]`)) { const l=document.createElement('link'); l.rel='stylesheet'; l.href=css; document.head.append(l); }
    const esc = v => String(v ?? '—').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    let mode='demo', section='', resource='rooms', page=1, rows=[], total=0, configured=false, busy=false, error='', generation=0;
    let logs=[];
    let syncState={}, demoSync={}, syncing=false;
    const names={rooms:'Phòng',guests:'Khách lưu trú',stays:'Lượt lưu trú',devices:'Thiết bị',commands:'Lệnh thiết bị'};
    const columns={rooms:[['roomNumber','Mã phòng'],['name','Tên phòng'],['roomTypeCode','Loại phòng'],['maxAdults','Người lớn'],['maxChildren','Trẻ em']],guests:[['id','ID nguồn'],['fullName','Họ tên'],['phoneNumber','Điện thoại'],['email','Email']],stays:[['occupancyId','ID lượt'],['roomId','ID phòng'],['groupName','Tên đoàn'],['bookingGroupCode','Mã đoàn'],['checkInDate','Nhận phòng'],['checkOutDate','Trả phòng']],devices:[['sn','Serial'],['name','Thiết bị'],['isOnline','Kết nối'],['isDisabled','Ngưng sử dụng']],commands:[['cmdReaquestID','Mã lệnh'],['customerId','ID khách'],['deviceInfoId','ID thiết bị'],['transferTime','Gửi lúc'],['returnTime','Phản hồi lúc'],['returnValue','Kết quả nguồn']]};
    const fixture={rooms:[{roomNumber:'DEMO-A101',name:'Deluxe hướng vườn',roomTypeCode:'DE',maxAdults:2,maxChildren:1},{roomNumber:'DEMO-A102',name:'Superior gia đình',roomTypeCode:'SUP',maxAdults:2,maxChildren:2},{roomNumber:'DEMO-B201',name:'Family Suite',roomTypeCode:'FAM',maxAdults:4,maxChildren:2}],guests:[{id:'DEMO-K001',fullName:'Nguyễn Minh Anh (mẫu)',phoneNumber:'0900000001',email:'khach-mau@example.test'},{id:'DEMO-K002',fullName:'Trần Hoàng Nam (mẫu)',phoneNumber:'0900000002',email:'doan-mau@example.test'}],stays:[{occupancyId:'DEMO-LT001',roomId:'DEMO-A101',groupName:'Đoàn trải nghiệm mẫu',bookingGroupCode:'DEMO-DOAN01',checkInDate:'06/11/2026 14:00',checkOutDate:'07/11/2026 12:00'}],devices:[{sn:'DEMO-GATE01',name:'Cổng vào Resort',isOnline:true,isDisabled:false},{sn:'DEMO-GATE02',name:'Cổng nhà hàng',isOnline:true,isDisabled:false}]};
    function demoRows(){
        if(resource!=='commands')return fixture[resource];
        return [{cmdReaquestID:'DEMO-LENH01',customerId:'DEMO-K001',deviceInfoId:'DEMO-GATE01',transferTime:'Mẫu',returnTime:'Mẫu',returnValue:'Phản hồi thành công (mẫu)'}];
    }
    function render(){
        const root=document.getElementById('hospitalityRoot');if(!root)return;
        if (section) {
            renderList(root);
            return;
        }
        root.innerHTML=`<div class="hi-grid hi-work"><section class="hi-card hi-list-card"><div class="hi-row"><h3>${mode==='demo'?'Dữ liệu minh họa':'Dữ liệu từ phần mềm FaceID'}</h3></div>
        <div class="hi-tabs" role="tablist">${Object.entries(names).map(([k,v])=>`<button role="tab" aria-selected="${resource===k}" type="button" data-hi-tab="${k}" class="${resource===k?'active':''}">${v}</button>`).join('')}</div>
        ${error?`<p class="hi-error" role="alert">${esc(error)}</p>`:''}<div class="hi-table-wrap" aria-busy="${busy}">${busy?'<p class="hi-empty">Đang tải dữ liệu…</p>':table()}</div>
        <div class="hi-pager"><button type="button" class="btn secondary" data-hi-page="-1" ${busy||page<=1?'disabled':''}>← Trang trước</button><span class="hi-muted">${mode==='demo'?'Dữ liệu mẫu · ':''}${total} dòng · Trang ${page} / ${Math.max(1,Math.ceil(total/20))}</span><button type="button" class="btn secondary" data-hi-page="1" ${busy||page*20>=total?'disabled':''}>Trang sau →</button></div></section>
        <aside class="hi-card"><h3>Đồng bộ FaceID</h3><div class="hi-status"><span class="hi-tag ${configured?'green':'orange'}">${configured?'Đã cấu hình nguồn':'Chưa cấu hình nguồn'}</span><div class="hi-muted">Lịch đồng bộ: mỗi 15 phút trên máy chủ CRM.</div></div>
        ${syncDetails(syncState, 'Dữ liệu nguồn')}${syncDetails(demoSync, 'Dữ liệu mẫu')}
        <div class="hi-actions"><button class="btn" type="button" data-hi-sync="real" ${syncing||!configured?'disabled':''}>Đồng bộ ngay</button><button class="btn secondary" type="button" data-hi-sync="demo" ${syncing?'disabled':''}>Thử đồng bộ mẫu</button></div>
        <h3 style="margin-top:20px">Nhật ký phiên này</h3><ol class="hi-log">${logs.length?logs.map(x=>`<li>${esc(x.text)}<time>${esc(x.time)}</time></li>`).join(''):'<li class="hi-muted">Chưa có thao tác.</li>'}</ol></aside></div>`;
    }
    function syncDetails(state, label) {
        const stamp = state.succeeded_at ? new Date(state.succeeded_at).toLocaleString('vi-VN', {timeZone:'Asia/Ho_Chi_Minh'}) : 'Chưa có lượt thành công';
        const count = Object.entries(state.counts || {}).map(([key,value])=>`${names[key]}: ${Number(value)}`).join(' · ');
        return `<div class="hi-status"><b>${label}</b><div class="hi-muted">${esc(stamp)}${count ? `<br>${esc(count)}` : ''}</div>${state.status==='failed'?'<span class="hi-tag orange">Lần gần nhất thất bại</span>':state.status==='running'?'<span class="hi-tag">Đang đồng bộ</span>':''}</div>`;
    }
    function renderList(root) {
        const demo = mode === 'demo';
        root.innerHTML = `<div class="hi-head"><div><h2>${names[section]}</h2><div class="hi-muted">${demo ? 'Dữ liệu mẫu để xem giao diện.' : 'Dữ liệu đọc từ hệ thống nguồn đã cấu hình.'}</div></div>
            <div class="hi-mode"><button type="button" data-hi-mode="demo" aria-pressed="${demo}" class="${demo ? 'active' : ''}">Dữ liệu mẫu</button><button type="button" data-hi-mode="sandbox" aria-pressed="${!demo}" class="${!demo ? 'active' : ''}">Dữ liệu nguồn</button></div></div>
            <section class="hi-card hi-list-card"><div class="hi-row"><h3>${demo ? 'Danh sách mẫu' : 'Kết quả tra cứu'}</h3>${!demo ? `<button class="btn" type="button" data-hi-refresh ${busy || !configured ? 'disabled' : ''}>${busy ? 'Đang tải…' : 'Tải dữ liệu'}</button>` : ''}</div>
            ${!demo && !configured ? '<p class="hi-note">Chưa cấu hình kết nối FaceID cho công ty này.</p>' : ''}
            ${!demo && section === 'guests' ? `<div class="hi-filter"><label>Tìm khách<input id="hiSearch" placeholder="Tên hoặc PIN" maxlength="100" value="${esc(filters.search ?? '')}"></label><label>Liên kết CRM<select id="hiUnlinked"><option value="">Tất cả</option><option value="1" ${filters.unlinked?'selected':''}>Chờ liên kết</option></select></label></div>` : ''}
            ${!demo && section === 'stays' ? `<div class="hi-filter"><label>Từ ngày<input id="hiFrom" type="date" value="${esc(filters.from ?? '')}"></label><label>Đến ngày<input id="hiTo" type="date" value="${esc(filters.to ?? '')}"></label></div>` : ''}
            ${!demo && section !== 'commands' && configured ? `<p class="hi-muted">${syncState.succeeded_at ? 'Đồng bộ gần nhất: '+esc(new Date(syncState.succeeded_at).toLocaleString('vi-VN', {timeZone:'Asia/Ho_Chi_Minh'})) : 'Chưa có lượt đồng bộ thành công. Mở Tổng quan kết nối để đồng bộ ngay.'}</p>` : ''}
            ${error ? `<p class="hi-error" role="alert">${esc(error)}</p>` : ''}<div class="hi-table-wrap" aria-busy="${busy}">${busy ? '<div class="hi-empty">Đang đọc dữ liệu nguồn…</div>' : table()}</div>
            <div class="hi-pager"><button type="button" class="btn secondary" data-hi-page="-1" ${busy || page <= 1 ? 'disabled' : ''}>← Trang trước</button><span class="hi-muted">${total} dòng · Trang ${page} / ${Math.max(1, Math.ceil(total / 20))}</span><button type="button" class="btn secondary" data-hi-page="1" ${busy || page * 20 >= total ? 'disabled' : ''}>Trang sau →</button></div></section>`;
    }
    function table(){
        if(!rows.length)return `<div class="hi-empty">${resource==='commands'&&mode==='demo'?'Chưa có lệnh mẫu':'Chưa có dữ liệu'}<small>${mode==='sandbox'?'Kiểm tra bộ lọc hoặc tình trạng đồng bộ ở Tổng quan kết nối.':'Chọn dữ liệu mẫu để xem cách hiển thị.'}</small></div>`;
        const fields = resource==='guests' && mode==='sandbox' ? [...columns.guests, ['link_status','Liên kết CRM']] : columns[resource];
        return `<table><thead><tr>${fields.map(c=>`<th>${c[1]}</th>`).join('')}</tr></thead><tbody>${rows.map(r=>`<tr>${fields.map(([k],i)=>`<td class="${i===0?'hi-code':''}">${typeof r[k]==='boolean'?`<span class="hi-tag">${k==='isOnline'?(r[k]?'Trực tuyến':'Ngoại tuyến'):(r[k]?'Có':'Không')}</span>`:esc(r[k]??(k==='roomNumber'?r.number:k==='occupancyId'?r.stayId??r.id:k==='sn'?r.serialNumber:k==='checkInDate'?r.checkIn:k==='checkOutDate'?r.checkOut:null))}</td>`).join('')}</tr>`).join('')}</tbody></table>`;
    }
    function log(text){logs.unshift({text,time:new Date().toLocaleString('vi-VN')});}
    async function load(query={}){
        const demo = mode==='demo';
        if(demo && (!section || !demoSync.succeeded_at || resource==='commands')){rows=demoRows();total=rows.length;error='';render();return;}
        const g=++generation;rows=[];total=0;error='';busy=true;render();
        try {
            const params=new URLSearchParams({resource,page:String(page),...query});
            if(demo)params.set('demo','1');
            const endpoint = resource==='commands' ? 'read' : 'catalog';
            const result=await VKApi.request(`/integrations/hospitality/${endpoint}?${params}`,{cache:false,timeout:55000});
            if(g!==generation)return;
            rows=result.data.items;total=Number(result.data.total);
        }catch(e){if(g!==generation)return;error=e.message;}
        finally{if(g===generation){busy=false;render();}}
    }
    let filters={};
    async function boot(){
        const root = document.getElementById('hospitalityRoot');
        if (!root || root.dataset.initialized) return;
        if (!window.VKUser?.permissions?.includes('user.manage')) {
            root.innerHTML = '<p class="hi-note">Bạn chưa có quyền xem phân hệ FaceID.</p>';
            return;
        }
        root.dataset.initialized = '1';
        generation++;
        section = Object.hasOwn(names, root.dataset.section) ? root.dataset.section : '';
        mode='demo';resource=section || 'rooms';page=1;logs=[];filters={};configured=false;busy=false;error='';syncState={};demoSync={};syncing=false;rows=demoRows();total=rows.length;render();
        try{const s=await VKApi.request('/integrations/hospitality/status',{cache:false});if(root.isConnected){configured=s.data.configured;syncState=s.data.sync;demoSync=s.data.demo_sync;if(configured){mode='sandbox';load();}else if(section && demoSync.succeeded_at)load();else render();}}
        catch(e){if(root.isConnected){error=e.message;render();}}
    }
    async function runSync(demo) {
        const root = document.getElementById('hospitalityRoot');
        syncing=true;error='';render();
        try {
            const {data}=await VKApi.request('/integrations/hospitality/sync',{method:'POST',body:JSON.stringify({demo}),timeout:120000});
            if(!root.isConnected)return;
            if(demo)demoSync=data;else syncState=data;
            log(demo?'Đã lưu dữ liệu đồng bộ mẫu. Xem kết quả trong các danh sách FaceID.':'Đã cập nhật danh sách FaceID từ nguồn.');
            if(!demo && resource!=='commands'){page=1;await load(filters);}
        } catch(e) {
            if(root.isConnected) {
                error=e.message;
                if(e.status===502) {
                    if(demo)demoSync={...demoSync,status:'failed'};
                    else syncState={...syncState,status:'failed'};
                }
            }
        } finally {
            if(root.isConnected){syncing=false;render();}
        }
    }
    document.addEventListener('click',e=>{
        if(!document.getElementById('hospitalityRoot'))return;
        const b=e.target.closest('button');if(!b||b.disabled)return;
        if(b.hasAttribute('data-hi-sync')){runSync(b.dataset.hiSync==='demo');return;}
        if(b.hasAttribute('data-hi-mode')){if(mode===b.dataset.hiMode)return;generation++;busy=false;mode=b.dataset.hiMode;page=1;filters={};rows=[];total=0;error='';if(mode==='demo' || configured)load();else render();}
        if(b.hasAttribute('data-hi-tab')){resource=b.dataset.hiTab;page=1;filters={};load();}
        if(b.hasAttribute('data-hi-refresh')){filters={};['Search','From','To','Unlinked'].forEach(k=>{const el=document.getElementById('hi'+k);if(el?.value)filters[k.toLowerCase()]=el.value;});page=1;load(filters);}
        if(b.hasAttribute('data-hi-page')){page+=Number(b.dataset.hiPage);load(filters);}
    });
    window.loadHospitalityIntegration=boot;
    document.addEventListener('vk:ready',boot);
})();
