(function(){
    const labels={customers:'Khách hàng',contacts:'Người liên hệ',leads:'Khách hàng tiềm năng'};
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
        VKModal.open(source?'Chỉnh sửa nguồn nhập':'Thêm nguồn nhập',`<section class="source-editor-section"><h3>Thông tin nguồn</h3><div class="source-editor-grid"><div class="field"><label for="sourceCode">Mã nguồn</label><input id="sourceCode" name="code" value="${esc(source?.code || '')}" placeholder="VD: WEBSITE" maxlength="50" ${source?'readonly':'required pattern="[A-Za-z0-9_-]+"'}><small>${source?'Mã nguồn được giữ cố định.':'Dùng chữ không dấu, số, dấu - hoặc _.'}</small></div><div class="field"><label for="sourceName">Tên nguồn</label><input id="sourceName" name="name" value="${esc(source?.name || '')}" placeholder="VD: Khách từ website" maxlength="150" required></div></div><label class="source-active-row"><span><strong>Cho phép sử dụng</strong><small>Nguồn đang hoạt động có thể nhận danh sách mới.</small></span><input name="is_active" type="checkbox" ${!source || source.is_active?'checked':''}></label></section><section class="source-editor-section"><div class="source-members-heading"><h3>Người được nhập dữ liệu</h3><span>${(meta.users || []).length} người</span></div><p class="source-editor-note">Chọn người được phép sử dụng nguồn này.</p><div class="source-member-list">${(meta.users || []).map(user=>`<label class="source-member-row"><input type="checkbox" name="members" value="${Number(user.id)}" ${(source?.member_ids || []).map(Number).includes(Number(user.id))?'checked':''}><span class="source-member-avatar" aria-hidden="true">${esc(Array.from(user.name || '?')[0].toUpperCase())}</span><span class="source-member-name">${esc(user.name)}</span></label>`).join('') || '<p class="source-editor-note">Chưa có người dùng đang hoạt động.</p>'}</div></section>`,async form=>{
            const values=new FormData(form),payload={name:values.get('name'),is_active:values.has('is_active'),member_ids:values.getAll('members').map(Number)};if(!source)payload.code=values.get('code');
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
            const path=catalog==='customers'?'/customer-import/template':`/directory-import/${catalog}/template`;
            try{const response=await fetch('/api/v1'+path,{headers:{Authorization:`Bearer ${VKApi.token()}`}});if(!response.ok)throw new Error('Không tải được mẫu hoặc chưa có quyền danh mục.');const url=URL.createObjectURL(await response.blob());const a=document.createElement('a');a.href=url;a.download=`mau-${({customers:'khach-hang',contacts:'nguoi-lien-he',leads:'khach-tiem-nang'})[catalog]}.xlsx`;a.click();setTimeout(()=>URL.revokeObjectURL(url),1000);}catch(error){notify(error);}
        };
    }
    async function openBatch(id){await showBatch((await VKApi.request('/import-center/batches/'+id,{cache:false})).data);}
    async function showBatch(batch){
        const result=batch.result || {},ready=batch.status==='ready' && new Date(batch.expires_at).getTime()>Date.now();
        VKModal.open('Kết quả kiểm tra #'+batch.id,`<p><strong>${esc(labels[batch.catalog])}</strong> · ${esc(batch.original_name)}</p><p>${esc(statuses[batch.status] || 'Cần kiểm tra')}</p><p>Tổng số dòng: ${Number(result.total || 0)} · Đã nhập: ${Number(result.imported || 0)}</p>${(result.errors || []).length?`<p>Chưa nhập dữ liệu. Sửa các dòng sau rồi tải lại file:</p><ul>${result.errors.map(error=>`<li>Dòng ${Number(error.row)}: ${esc(error.message)}</li>`).join('')}</ul>`:''}${ready?`<p class="form-note">Kết quả kiểm tra có hiệu lực 2 giờ. Khi xác nhận, hệ thống kiểm tra lại quyền, nguồn và dữ liệu trùng. Một dòng lỗi sẽ chặn toàn bộ lô.</p><label><input name="confirmed" type="checkbox" value="1" required> Tôi đã kiểm tra và đồng ý nhập toàn bộ danh sách.</label>`:''}`,async form=>{
            if(!form.elements.confirmed.checked)throw new Error('Vui lòng xác nhận đã kiểm tra danh sách.');
            const response=await VKApi.request(`/import-center/batches/${batch.id}/commit`,{method:'POST',body:JSON.stringify({confirmed:true}),timeout:180000});VKModal.close();await load();await showBatch(response.data);
        },{hideSubmit:!ready,submitText:'Xác nhận nhập'});
        const body=document.getElementById('modalBody');
        if((result.preview || []).length && body) {
            const preview=document.createElement('section');preview.className='table-wrap';
            preview.innerHTML=`<h3>Kiểm tra nhanh ${result.preview.length} dòng đầu</h3><table class="list-table"><thead><tr><th>Dòng</th><th>Tên khách hàng</th><th>Loại khách</th></tr></thead><tbody>${result.preview.map(row=>`<tr><td>${Number(row.row)}</td><td>${esc(row.name)}</td><td>${esc(({organization:'Doanh nghiệp',person:'Cá nhân'})[row.customer_type] || 'Chưa xác định')}</td></tr>`).join('')}</tbody></table>`;
            body.appendChild(preview);
        }
    }
    document.addEventListener('click',event=>{if(event.target.closest('[data-import-retry]'))load();});
    document.addEventListener('vk:ready',load);window.loadImportCenter=load;
})();
