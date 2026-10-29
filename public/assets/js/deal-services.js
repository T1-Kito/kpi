(function(){
    let rows=[],canManage=false;
    const esc=v=>VKTable.escapeHtml(String(v ?? ''));
    const active=r=>r.is_active && (!r.parent_id || rows.some(p=>String(p.id)===String(r.parent_id) && p.is_active));
    async function load(){const result=await VKApi.request('/service-interests',{cache:false});rows=result.data || [];canManage=!!result.can_manage;}
    function picker(needs={}){
        const selected=(needs.service_ids || []).map(String);
        const choice=r=>`<label class="dns-choice"><input type="checkbox" name="need_service" value="${r.id}" ${selected.includes(String(r.id))?'checked':''}><span>${esc(r.name)}${active(r)?'':' · Ngừng dùng'}</span></label>`;
        return `<div class="field full dns-picker"><label>Dịch vụ quan tâm <small>Chọn nhiều dịch vụ</small></label><div class="dns-choices">${rows.filter(r=>!r.parent_id && (active(r) || selected.includes(String(r.id)) || rows.some(c=>String(c.parent_id)===String(r.id) && selected.includes(String(c.id))))).map(parent=>`<fieldset><legend>${esc(parent.name)}</legend>${choice(parent)}${rows.filter(r=>String(r.parent_id)===String(parent.id) && (active(r) || selected.includes(String(r.id)))).map(choice).join('')}</fieldset>`).join('') || '<p class="dns-empty">Chưa có danh mục dịch vụ. Admin/Chủ tịch mở “Danh mục dịch vụ” trên danh sách cơ hội để thiết lập.</p>'}</div>${!selected.length && needs.interests?.length?`<p class="dns-empty">Thông tin cũ được giữ nguyên: ${esc(needs.interests.join(', '))}</p>`:''}</div>`;
    }
    async function manage(){
        return window.VKLayout.navigatePjax('/product-categories');
        await load();if(!canManage)return;
        VKModal.open('Danh mục dịch vụ quan tâm',`<div class="dns-manager"><div class="dns-toolbar"><span>Nhóm dịch vụ và dịch vụ chi tiết · tối đa 2 cấp</span><button class="btn primary" type="button" data-dns-add="">＋ Thêm nhóm</button></div>${rows.filter(r=>!r.parent_id).map(r=>`<section><header><strong>${esc(r.name)}</strong><div><button class="btn small" type="button" data-dns-edit="${r.id}">Sửa</button><button class="btn small" type="button" data-dns-add="${r.id}" ${active(r)?'':'disabled'}>＋ Dịch vụ</button></div></header>${rows.filter(c=>String(c.parent_id)===String(r.id)).map(c=>`<div class="dns-service"><span>${esc(c.name)}</span><small>${active(c)?'Đang sử dụng':'Ngừng dùng'}</small><button class="btn small" type="button" data-dns-edit="${c.id}">Sửa</button></div>`).join('')}</section>`).join('') || '<p class="dns-empty">Chưa có nhóm. Hãy thêm nhóm dịch vụ của công ty.</p>'}</div>`,null,{hideSubmit:true,cancelText:'Đóng',className:'deal-needs-modal'});
        document.querySelectorAll('[data-dns-add]').forEach(b=>b.onclick=()=>edit(null,b.dataset.dnsAdd));
        document.querySelectorAll('[data-dns-edit]').forEach(b=>b.onclick=()=>edit(rows.find(r=>String(r.id)===b.dataset.dnsEdit)));
        const button=document.createElement('button');button.type='button';button.className='btn';button.textContent='Nhập từ file phân loại';button.onclick=importCatalog;document.querySelector('.dns-toolbar').append(button);
    }
    function importCatalog(){
        let token=null;
        VKModal.open('Nhập danh mục dịch vụ',`<p class="dns-empty">Chọn file NHẬP LIỆU - PHÂN LOẠI. Chỉ đọc Nhóm sản phẩm cấp 1 và cấp 2 ở trang đầu; không tạo SKU hay tồn kho. Xem trước rồi mới xác nhận.</p><label class="field">File Excel<input type="file" name="file" accept=".xlsx" required></label><div data-dns-preview aria-live="polite"></div>`,async form=>{
            const body=token?JSON.stringify({token}):new FormData();if(!token)body.append('file',form.elements.file.files[0]);
            const response=await VKApi.request('/service-interests/import',{method:'POST',body});
            if(token){await manage();VKModal.toast(`Đã thêm ${response.data.created} nhóm/dịch vụ.`);return;}
            token=response.data.token;form.querySelector('[data-dns-preview]').innerHTML=`<p class="dns-empty">Sẽ thêm ${response.data.create_count} mục. Các mục đã có giữ nguyên.</p><div class="dns-import-rows">${response.data.rows.map(r=>`<p>Dòng ${r.row}: ${esc(r.path)} · ${esc(r.action)}</p>`).join('')}</div>`;document.getElementById('modalSubmit').textContent='Xác nhận nhập';
        },{submitText:'Kiểm tra file',className:'deal-needs-modal'});
        document.getElementById('modalForm').elements.file.onchange=()=>{token=null;document.querySelector('[data-dns-preview]').innerHTML='';document.getElementById('modalSubmit').textContent='Kiểm tra file';};
    }
    function edit(row,parent=''){
        parent=row?.parent_id || parent;
        VKModal.open(row?'Sửa dịch vụ':'Thêm dịch vụ',`<div class="field"><label>Tên ${parent?'dịch vụ':'nhóm dịch vụ'}</label><input name="name" required maxlength="150" value="${esc(row?.name)}"></div><label class="dns-choice"><input type="checkbox" name="is_active" ${!row || row.is_active?'checked':''}>Cho phép sử dụng</label>`,async form=>{
            await VKApi.request('/service-interests'+(row?'/'+row.id:''),{method:row?'PUT':'POST',body:JSON.stringify({name:form.elements.name.value,parent_id:parent?Number(parent):null,is_active:form.elements.is_active.checked})});
            await manage();
        });
    }
    document.addEventListener('click',e=>{if(e.target.closest('[data-manage-deal-services]'))manage().catch(e=>VKModal.toast(e.message,'danger'));});
    window.VKDealServices={load,picker, get canManage(){return canManage;}};
})();
