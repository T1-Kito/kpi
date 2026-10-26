(function () {
    const state={q:'',status:'',selected:null,collapsed:new Set()};
    const esc=v=>VKTable.escapeHtml(String(v ?? ''));
    const folder='<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M3 7a2 2 0 0 1 2-2h5l2 2h7a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2Z"/><path d="M3 10h18"/></svg>';
    async function loadCustomerGroupsPage() {
        const root=document.getElementById('customerGroupsRoot'); if(!root)return;
        try { await VKCustomerGroups.load(); render(); } catch(e) { root.innerHTML='<div class="cg-empty">Không tải được danh mục. <button type="button" class="btn" data-cgp-reload>Thử lại</button></div>'; VKModal.toast(e.message,'danger'); }
    }
    function render() {
        const root=document.getElementById('customerGroupsRoot'); if(!root)return;
        const groups=VKCustomerGroups.rows, manager=VKCustomerGroups.canManage;
        const normalize=v=>v.normalize('NFD').replace(/[\u0300-\u036f]/g,'').replace(/đ/g,'d').toLowerCase();
        const matches=groups.filter(g=>(!state.status || (VKCustomerGroups.active(g)?'active':'inactive')===state.status) && normalize(VKCustomerGroups.path(g.id).map(n=>n.name).join(' ')).includes(normalize(state.q)));
        const visible=new Set(); matches.forEach(g=>VKCustomerGroups.path(g.id).forEach(n=>visible.add(String(n.id))));
        const children=id=>groups.filter(g=>String(g.parent_id || '')===String(id || ''));
        const tree=parent=>children(parent).filter(g=>visible.has(String(g.id))).map(g=>{
            const depth=VKCustomerGroups.path(g.id).length, kids=children(g.id), isOpen=state.q || state.status || !state.collapsed.has(String(g.id));
            return `<div class="cgp-branch" role="treeitem" aria-level="${depth}" ${kids.length?`aria-expanded="${!!isOpen}"`:''}><div class="cgp-row ${String(state.selected)===String(g.id)?'selected':''}"><button type="button" class="cgp-chevron" data-cgp-toggle="${g.id}" ${kids.length?'':'disabled'} aria-label="${isOpen?'Thu gọn':'Mở rộng'} ${esc(g.name)}">${kids.length?(isOpen?'⌄':'›'):''}</button><button type="button" class="cgp-name" data-cgp-select="${g.id}"><span class="cgp-folder depth-${depth}">${folder}</span><span><strong>${esc(g.name)}</strong><small>Cấp ${depth}${kids.length?' · '+kids.length+' nhóm con':''}</small></span></button><span class="cg-status ${VKCustomerGroups.active(g)?'':'off'}">${VKCustomerGroups.active(g)?'Đang dùng':'Ngừng dùng'}</span>${manager && depth<3?`<button type="button" class="btn small" data-cgp-add="${g.id}" ${VKCustomerGroups.active(g)?'':'disabled'}>＋ Nhóm con</button>`:''}${manager?`<button type="button" class="btn small cgp-edit" data-cgp-edit="${g.id}" aria-label="Sửa ${esc(g.name)}">Sửa</button>`:''}</div>${kids.length && isOpen?`<div class="cgp-children" role="group">${tree(g.id)}</div>`:''}</div>`;
        }).join('');
        const selected=groups.find(g=>String(g.id)===String(state.selected)), branch=selected?VKCustomerGroups.path(selected.id):[];
        root.innerHTML=`<section class="cgp-workspace"><div class="cgp-layout" style="grid-template-columns:minmax(0,1fr)"><section class="cgp-tree-panel"><div class="cgp-tools cgp-tools-compact"><span class="cgp-search-icon" aria-hidden="true">⌕</span><input type="search" data-cgp-search aria-label="Tìm nhóm khách hàng" placeholder="Tìm tên nhóm…" value="${esc(state.q)}"><select data-cgp-status aria-label="Trạng thái nhóm"><option value="">Tất cả trạng thái</option><option value="active" ${state.status==='active'?'selected':''}>Đang sử dụng</option><option value="inactive" ${state.status==='inactive'?'selected':''}>Ngừng sử dụng</option></select><button type="button" class="btn small" data-cgp-expand>Mở tất cả</button><button type="button" class="btn small" data-cgp-collapse>Thu gọn</button><div class="cgp-toolbar-actions">${manager?'<button type="button" class="btn" data-cgp-import>Nhập Excel</button><button type="button" class="btn primary" data-cgp-add="">＋ Thêm nhóm</button>':''}</div></div><div class="cgp-tree" role="tree" aria-label="Cây nhóm khách hàng">${tree(null) || `<div class="cg-empty"><span class="cgp-empty-icon">${folder}</span><h3>${groups.length?'Không tìm thấy nhóm phù hợp':'Danh mục đang chờ bạn sắp xếp'}</h3><p>${groups.length?'Thử tên nhóm khác hoặc đổi bộ lọc.':'Thêm nhóm đầu tiên hoặc nhập danh sách Excel của công ty.'}</p>${manager && !groups.length?'<button type="button" class="btn primary" data-cgp-add="">＋ Tạo nhóm đầu tiên</button>':''}</div>`}</div><footer class="cgp-tree-footer">${matches.length} / ${groups.length} nhóm phù hợp</footer></section></div></section>`;
    }
    async function download() {
        const response=await fetch('/api/v1/customer-groups/template',{headers:{Authorization:`Bearer ${VKApi.token()}`}});
        if(!response.ok)throw new Error('Không tải được mẫu nhóm khách hàng.');
        const url=URL.createObjectURL(await response.blob()),a=document.createElement('a');a.href=url;a.download='Mẫu nhóm khách hàng.xlsx';a.click();setTimeout(()=>URL.revokeObjectURL(url),1000);
    }
    function openImport() {
        let token=null, version=0;
        VKModal.open('Nhập nhóm khách hàng từ Excel',`<div class="cgp-import"><div class="cgp-import-steps"><strong>1. Chọn file</strong><span>→</span><strong>2. Kiểm tra</strong><span>→</span><strong>3. Xác nhận</strong></div><p>Mỗi dòng là một đường dẫn nhóm. Phần mềm tạo nhóm còn thiếu và giữ nguyên nhóm đã có.</p><p>Có thể chọn file NHẬP LIỆU - PHÂN LOẠI: chỉ lấy bảng phân nhóm đối tượng 3 cấp ở trang đầu, không nhập khách hàng hay các bảng dịch vụ/kênh tiếp cận.</p><button type="button" class="btn" data-cgp-template>Tải mẫu Excel</button><label class="field">File danh sách nhóm<input type="file" name="file" accept=".xlsx" required></label><small>Tối đa 300 dòng · 1 MB · Không đổi tên hay chuyển nhánh nhóm cũ</small><div data-cgp-preview aria-live="polite"></div></div>`,async form=>{
            const result=form.querySelector('[data-cgp-preview]'),current=version;
            if(!token){
                const body=new FormData();body.append('file',form.elements.file.files[0]);
                try{
                    const response=await VKApi.request('/customer-groups/preview',{method:'POST',body});
                    if(version!==current)return;
                    token=response.data.token;
                    result.innerHTML=`<div class="cgp-import-success">Kiểm tra hợp lệ · Sẽ thêm <strong>${response.data.create_count}</strong> nhóm mới</div><div class="cgp-preview-table"><table><thead><tr><th>Dòng</th><th>Đường dẫn nhóm</th><th>Kết quả</th></tr></thead><tbody>${response.data.rows.map(r=>`<tr><td>${r.row}</td><td>${esc(r.path)}</td><td>${esc(r.action)}</td></tr>`).join('')}</tbody></table></div>`;
                    document.getElementById('modalSubmit').textContent='Xác nhận nhập';
                }catch(e){result.innerHTML=`<div class="cgp-import-errors">${esc(e.message)}</div>`;throw e;}
                return;
            }
            try{
                const response=await VKApi.request('/customer-groups/commit',{method:'POST',body:JSON.stringify({token})});
                VKModal.close();await loadCustomerGroupsPage();VKModal.toast(`Đã thêm ${response.data.created} nhóm khách hàng.`);
            }catch(e){token=null;document.getElementById('modalSubmit').textContent='Kiểm tra lại';result.innerHTML=`<div class="cgp-import-errors">${esc(e.message)}</div>`;throw e;}
        },{submitText:'Kiểm tra file'});
        document.querySelector('[data-cgp-template]').onclick=()=>download().catch(e=>VKModal.toast(e.message,'danger'));
        document.querySelector('.cgp-import input[type=file]').onchange=()=>{version++;token=null;document.querySelector('[data-cgp-preview]').innerHTML='';document.getElementById('modalSubmit').textContent='Kiểm tra file';};
    }
    document.addEventListener('vk:ready',loadCustomerGroupsPage);
    document.addEventListener('input',e=>{if(e.target.matches('[data-cgp-search]')){state.q=e.target.value;const pos=e.target.selectionStart;render();const input=document.querySelector('[data-cgp-search]');input.focus();input.setSelectionRange(pos,pos);}});
    document.addEventListener('change',e=>{if(e.target.matches('[data-cgp-status]')){state.status=e.target.value;render();}});
    document.addEventListener('click',e=>{
        if(!document.getElementById('customerGroupsRoot'))return;
        const toggle=e.target.closest('[data-cgp-toggle]'),select=e.target.closest('[data-cgp-select]'),add=e.target.closest('[data-cgp-add]'),edit=e.target.closest('[data-cgp-edit]');
        if(toggle){const id=toggle.dataset.cgpToggle;state.collapsed.has(id)?state.collapsed.delete(id):state.collapsed.add(id);render();}
        if(select){state.selected=select.dataset.cgpSelect;render();}
        if(add && VKCustomerGroups.canManage)VKCustomerGroups.edit(null,add.dataset.cgpAdd);
        if(edit && VKCustomerGroups.canManage)VKCustomerGroups.edit(VKCustomerGroups.rows.find(r=>String(r.id)===edit.dataset.cgpEdit));
        if(e.target.closest('[data-cgp-expand]')){state.collapsed.clear();render();}
        if(e.target.closest('[data-cgp-collapse]')){state.collapsed=new Set(VKCustomerGroups.rows.map(r=>String(r.id)));render();}
        if(e.target.closest('[data-cgp-import]') && VKCustomerGroups.canManage)openImport();
        if(e.target.closest('[data-cgp-reload]'))loadCustomerGroupsPage();
    });
    window.loadCustomerGroupsPage=loadCustomerGroupsPage;
})();
