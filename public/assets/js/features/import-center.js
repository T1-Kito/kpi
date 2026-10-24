(function(){
    const labels={customers:'Khách hàng · chỉ thêm mới','customers-v1':'Khách hàng · thêm mới và cập nhật',contacts:'Người liên hệ · chỉ thêm mới','contacts-v1':'Người liên hệ · thêm mới và cập nhật',leads:'Khách tiềm năng · mẫu cũ','leads-v1':'Khách tiềm năng · theo nguồn, chỉ thêm mới','categories-v1':'Danh mục sản phẩm · chỉ thêm mới','brands-v1':'Hãng sản phẩm · chỉ thêm mới','skus-v1':'Sản phẩm và mã hàng · thêm mới và cập nhật','suppliers-v1':'Nhà cung cấp · thêm mới và cập nhật','warehouses-v1':'Kho · thêm mới và cập nhật','locations-v1':'Vị trí kho · chỉ thêm mới'};
    Object.assign(labels,{'positions-v1':'Chức vụ · thêm mới và cập nhật','departments-v1':'Phòng ban · thêm mới và cập nhật','users-v1':'Người dùng · tạo chưa kích hoạt'});
    const statuses={ready:'Đã kiểm tra — chờ xác nhận',invalid:'Có lỗi — chưa nhập',committed:'Đã nhập thành công',expired:'Hết hạn — cần kiểm tra lại'};
    const esc=value=>VKTable.escapeHtml(String(value ?? ''));
    let sources=[],meta={},page=1,generation=0;
    const notify=error=>VKModal.toast(error.message || 'Chưa thực hiện được. Vui lòng thử lại.','danger');
    async function load(){
        const root=document.getElementById('importCenterRoot');if(!root)return;const version=++generation;
        root.innerHTML='<section class="module-panel"><p>Đang tải lịch sử nhập…</p></section>';
        try{
            const [list,batches]=await Promise.all([VKApi.request('/import-center/sources',{cache:false}),VKApi.request('/import-center/batches?page='+page,{cache:false})]);
            if(version!==generation || !document.body.contains(root))return;
            sources=list.data;meta=list.meta;
            root.innerHTML=`<section class="module-panel"><div class="panel-head"><div><h2>Nhập danh sách theo nguồn</h2><span>Lưu lịch sử và xác nhận đúng lô đã kiểm tra.</span></div><div class="row-actions">${meta.can_manage?'<button type="button" class="btn" data-import-sources>Quản lý nguồn</button>':''}<button type="button" class="btn primary" data-import-new ${meta.catalogs.length?'':'disabled'}>Nhập danh sách</button></div></div><p class="form-note">Hiện hỗ trợ nhập mới khách hàng, người liên hệ và khách tiềm năng · tối đa 300 dòng, 1 MB. Chưa hỗ trợ cập nhật hồ sơ đã có qua nguồn. Chức năng Nhập file cũ vẫn được giữ tại từng danh mục.</p><h3>Lịch sử của bạn</h3><div class="table-wrap"><table class="list-table"><thead><tr><th>Lần nhập</th><th>Danh mục</th><th>File</th><th>Trạng thái</th><th></th></tr></thead><tbody>${batches.data.map(row=>`<tr><td>#${Number(row.id)}</td><td>${esc(labels[row.catalog])}</td><td>${esc(row.original_name)}</td><td>${esc(statuses[row.status] || 'Cần kiểm tra')}</td><td><button type="button" class="btn small" data-import-batch="${Number(row.id)}">Xem kết quả</button></td></tr>`).join('') || '<tr><td colspan="5">Chưa có lần nhập nào.</td></tr>'}</tbody></table></div><nav class="row-actions"><button type="button" class="btn small" data-import-page="${page-1}" ${page<=1?'disabled':''}>Trang trước</button><span>Trang ${page} / ${batches.meta.last_page}</span><button type="button" class="btn small" data-import-page="${page+1}" ${page>=batches.meta.last_page?'disabled':''}>Trang sau</button></nav></section>`;
            root.querySelector('[data-import-new]').onclick=newBatch;
            root.querySelector('.form-note').textContent='Excel · Tối đa 300 dòng, 1 MB. Chọn mẫu theo danh mục. Khách hàng theo phiên bản chỉ cập nhật hồ sơ đã nhập cùng nguồn.';
            root.querySelector('[data-import-sources]')?.addEventListener('click',sourceList);
            root.querySelectorAll('[data-import-batch]').forEach(button=>button.onclick=()=>openBatch(Number(button.dataset.importBatch)).catch(notify));
            root.querySelectorAll('[data-import-page]').forEach(button=>button.onclick=()=>{page=Number(button.dataset.importPage);load();});
        }catch(error){if(version===generation && document.body.contains(root))root.innerHTML=`<section class="module-panel"><h2>Chưa mở được quản lý nhập</h2><p>${esc(error.message)}</p><button class="btn" type="button" data-import-retry>Thử lại</button></section>`;}
    }
    function sourceList(){
        VKModal.open('Nguồn nhập dữ liệu',`<section class="import-source-manager"><div class="import-source-toolbar"><div><h3>Quản lý nguồn</h3><p>Phân nhóm danh sách và chọn người được phép nhập.</p></div>${sources.length?'<button type="button" class="btn primary" data-source-new>+ Thêm nguồn</button>':''}</div>${sources.length?`<div class="import-source-list">${sources.map(source=>`<article class="import-source-card"><span class="import-source-symbol" aria-hidden="true">↳</span><div class="import-source-info"><strong>${esc(source.name)}</strong><span>${esc(source.code)} · ${(source.member_ids || []).length} người được sử dụng</span></div><span class="import-source-status ${source.is_active?'is-active':''}">${source.is_active?'Hoạt động':'Đã ngừng'}</span><button type="button" class="btn small" data-source-edit="${Number(source.id)}">Chỉnh sửa</button></article>`).join('')}</div>`:'<div class="import-source-empty"><span class="import-source-empty-icon" aria-hidden="true">↳</span><h3>Tạo nguồn nhập đầu tiên</h3><p>Ví dụ: Khách từ hội chợ, Website hoặc danh sách kinh doanh.</p><button type="button" class="btn primary" data-source-new>+ Thêm nguồn</button></div>'}<div class="import-source-hint">Chỉ người được thêm vào nguồn mới có thể nhập dữ liệu.</div></section>`,null,{hideSubmit:true,cancelText:'Đóng',className:'import-source-modal'});
        const body=document.getElementById('modalBody');body.querySelector('[data-source-new]').onclick=()=>editSource();body.querySelectorAll('[data-source-edit]').forEach(button=>button.onclick=()=>editSource(sources.find(s=>Number(s.id)===Number(button.dataset.sourceEdit))));
    }
    function editSource(source){
VKModal.open(source?'Chỉnh sửa nguồn nhập':'Thêm nguồn nhập',`<section class="source-editor-section"><h3>Thông tin nguồn</h3><div class="source-editor-grid"><div class="field"><label for="sourceCode">Mã nguồn</label><input id="sourceCode" name="code" value="${esc(source?.code || '')}" placeholder="VD: WEBSITE" maxlength="50" ${source?'readonly':'required pattern="[A-Za-z0-9_-]+"'}><small>${source?'Mã nguồn được giữ cố định.':'Dùng chữ không dấu, số, dấu - hoặc _.'}</small></div><div class="field"><label for="sourceName">Tên nguồn</label><input id="sourceName" name="name" value="${esc(source?.name || '')}" placeholder="VD: Khách từ website" maxlength="150" required></div></div><label class="source-active-row"><span><strong>Cho phép sử dụng</strong><small>Nguồn đang hoạt động có thể nhận danh sách mới.</small></span><input name="is_active" type="checkbox" ${!source || source.is_active?'checked':''}></label></section><p class="form-note">Cấp người sử dụng tại Quản trị → Vai trò và quyền → Quyền nguồn nhập.</p>`,async form=>{
            const values=new FormData(form),payload={name:values.get('name'),is_active:values.has('is_active')};if(!source)payload.code=values.get('code');
            await VKApi.request('/import-center/sources'+(source?'/'+source.id:''),{method:source?'PUT':'POST',body:JSON.stringify(payload)});VKModal.close();await load();VKModal.toast('Đã lưu nguồn nhập.');
        },{submitText:'Lưu nguồn',className:'source-editor-modal'});
    }
    function newBatch(){
        const allowed=sources.filter(source=>source.is_active && source.can_use);
        if(!allowed.length){VKModal.toast('Bạn chưa có nguồn nhập đang hoạt động. Nhờ quản trị thêm bạn vào nguồn.','danger');return;}
        VKModal.open('Kiểm tra danh sách trước khi nhập',`${VKModal.select('source_id','Nguồn nhập',allowed.map(s=>({value:s.id,label:s.name})))}${VKModal.select('catalog','Danh mục',meta.catalogs.map(c=>({value:c,label:labels[c]})))}<button type="button" class="btn small" data-import-template>Tải mẫu</button><div class="field" style="margin-top:16px"><label>Chọn file danh sách</label><input type="file" name="file" accept=".xlsx,.csv" style="height:auto;padding:12px"><small>Dùng mẫu đúng danh mục. Không nhập mật khẩu hoặc quyền vào Excel.</small></div>`,async form=>{
            const file=form.elements.file.files[0];if(!file)throw new Error('Vui lòng chọn file danh sách.');
            const payload=new FormData();payload.append('source_id',form.elements.source_id.value);payload.append('catalog',form.elements.catalog.value);payload.append('file',file);
            const response=await VKApi.request('/import-center/batches',{method:'POST',body:payload,timeout:180000});await showBatch(response.data);
        },{submitText:'Kiểm tra file'});
        document.querySelector('[data-import-template]').onclick=async()=>{
            const catalog=document.querySelector('#modalBody [name="catalog"]').value;
            const product=['categories-v1','brands-v1','skus-v1'].includes(catalog);
            const procurement=['suppliers-v1','warehouses-v1','locations-v1'].includes(catalog);
            const organization=['positions-v1','departments-v1','users-v1'].includes(catalog);
            const path=organization?`/import-center/organization/${catalog.slice(0,-3)}/template`:procurement?`/import-center/procurement/${catalog.slice(0,-3)}/template`:product?`/import-center/products/${catalog.slice(0,-3)}/template`:catalog==='leads-v1'?'/import-center/lead-template':catalog==='contacts-v1'?'/import-center/contact-template':catalog==='customers-v1'?'/import-center/customer-template':catalog==='customers'?'/customer-import/template':`/directory-import/${catalog}/template`;
            try{const response=await fetch('/api/v1'+path,{cache:'no-store',headers:{Authorization:`Bearer ${VKApi.token()}`}});if(!response.ok)throw new Error('Không tải được mẫu hoặc chưa có quyền danh mục.');const url=URL.createObjectURL(await response.blob());const a=document.createElement('a');a.href=url;a.download=`Mẫu ${labels[catalog].split(' · ')[0]}.xlsx`;a.click();setTimeout(()=>URL.revokeObjectURL(url),1000);}catch(error){notify(error);}
        };
    }
    async function openBatch(id){await showBatch((await VKApi.request('/import-center/batches/'+id,{cache:false})).data);}
    async function showBatch(batch){
        const result=batch.result || {},ready=batch.status==='ready' && new Date(batch.expires_at).getTime()>Date.now();
        VKModal.open('Kết quả kiểm tra #'+batch.id,`<p><strong>${esc(labels[batch.catalog])}</strong> · ${esc(batch.original_name)}</p><p>${esc(statuses[batch.status] || 'Cần kiểm tra')}</p><p>Tổng số dòng: ${Number(result.total || 0)} · Đã nhập: ${Number(result.imported || 0)}</p>${(result.errors || []).length?`<p>Chưa nhập dữ liệu. Sửa các dòng sau rồi tải lại file:</p><ul>${result.errors.map(error=>`<li>Dòng ${Number(error.row)}: ${esc(error.message)}</li>`).join('')}</ul>`:''}${ready?`<p class="form-note">Kết quả kiểm tra có hiệu lực 2 giờ. Khi xác nhận, hệ thống kiểm tra lại quyền, nguồn và dữ liệu trùng. Một dòng lỗi sẽ chặn toàn bộ lô.</p><label><input name="confirmed" type="checkbox" value="1" required> Tôi đã kiểm tra và đồng ý nhập toàn bộ danh sách.</label>`:''}`,async form=>{
            if(!form.elements.confirmed.checked)throw new Error('Vui lòng xác nhận đã kiểm tra danh sách.');
            const response=await VKApi.request(`/import-center/batches/${batch.id}/commit`,{method:'POST',body:JSON.stringify({confirmed:true}),timeout:180000});VKModal.close();await load();await showBatch(response.data);
        },{hideSubmit:!ready,submitText:'Xác nhận nhập',className:'import-preview-modal',cancelText:'Đóng'});
        const body=document.getElementById('modalBody');
        if((result.errors || []).length && body){
            const download=document.createElement('button');download.type='button';download.className='btn small';download.textContent='Tải danh sách lỗi';
            download.onclick=async()=>{
                download.disabled=true;
                try{
                    const response=await fetch(`/api/v1/import-center/batches/${Number(batch.id)}/errors`,{headers:{Authorization:`Bearer ${VKApi.token()}`}});
                    if(!response.ok)throw new Error('Không tải được file lỗi. Hãy kiểm tra quyền và mở lại lô nhập.');
                    const url=URL.createObjectURL(await response.blob());const link=document.createElement('a');link.href=url;link.download=`loi-nhap-danh-sach-${Number(batch.id)}.xlsx`;link.click();setTimeout(()=>URL.revokeObjectURL(url),1000);
                }catch(error){notify(error);}finally{download.disabled=false;}
            };body.appendChild(download);
        }
        if((result.preview || []).length && body) {
            const preview=document.createElement('section');preview.className='table-wrap';
            const isContact=batch.catalog==='contacts-v1';
            const isLead=batch.catalog==='leads-v1';
            const fieldLabels={name:isContact?'Tên người liên hệ':'Tên',position:'Chức vụ',customer_id:'Khách hàng',customer_type:'Loại khách',phone:'Điện thoại',email:'Email',tax_code:'Mã số thuế',identity_number:'CCCD',contact_name:'Người liên hệ',address:'Địa chỉ',payment_terms:'Điều khoản thanh toán',manager_id:'Người quản lý',warehouse_id:'Kho',code:'Mã vị trí',status:'Trạng thái'};
            const actions={create:'Thêm mới',update:'Cập nhật',skip:'Bỏ qua'};
            preview.innerHTML=`<h3>Danh sách kiểm tra · ${result.preview.length} dòng</h3><table class="list-table"><thead><tr><th>Dòng</th><th>Thao tác</th><th>Mã ngoài</th><th>${isContact?'Tên người liên hệ':'Tên khách hàng'}</th>${isContact?'<th>Khách hàng</th><th>Chức vụ</th>':''}<th>Điện thoại</th><th>Email</th><th>Thay đổi trước → sau</th></tr></thead><tbody>${result.preview.map(row=>`<tr><td>${Number(row.row)}</td><td>${esc(actions[row.action || 'create'] || 'Cần kiểm tra')}</td><td>${esc(row.external_id || '—')}</td><td>${esc(row.name)}</td>${isContact?`<td>${esc(row.customer_code)} · ${esc(row.customer_name)}</td><td>${esc(row.position || '—')}</td>`:''}<td>${esc(row.phone || '—')}</td><td>${esc(row.email || '—')}</td><td>${Object.entries(row.changes || {}).map(([key,value])=>`<div><strong>${esc(fieldLabels[key] || key)}</strong>: ${esc(value.before ?? 'Trống')} → ${esc(value.after ?? 'Trống')}</div>`).join('') || '—'}</td></tr>`).join('')}</tbody></table>`;
            body.querySelector('[name="confirmed"]')?.closest('label')?.before(preview);
            if(!body.contains(preview))body.appendChild(preview);
            if(isLead){
                const note=document.createElement('p');note.className='form-note';note.textContent='Khách tiềm năng chỉ thêm mới. Người nhập được gán phụ trách; công việc chăm sóc tạo theo luồng hiện hành.';preview.before(note);
                const details=document.createElement('section');details.className='table-wrap';
                details.innerHTML=`<table class="list-table"><thead><tr><th>Dòng</th><th>Nguồn</th><th>Mã chiến dịch</th></tr></thead><tbody>${result.preview.map(row=>`<tr><td>${Number(row.row)}</td><td>${esc(row.source || '—')}</td><td>${esc(row.campaign_code || '—')}</td></tr>`).join('')}</tbody></table>`;preview.after(details);
            }
        }
    }
    document.addEventListener('click',event=>{if(event.target.closest('[data-import-retry]'))load();});
    document.addEventListener('vk:ready',load);window.loadImportCenter=load;
})();
