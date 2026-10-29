(function () {
    let rows=[], version=0;
    const root=()=>document.getElementById('productCatalogRoot');
    const endpoint=type=>`/product-${type==='category'?'categories':'brands'}`;
    const label=type=>type==='category'?'danh mục':'hãng';
    const allowed=()=>window.VKLayout?.hasPermission('product.manage');
    async function load() {
        const target=root();if(!target)return;
        const ticket=++version,type=target.dataset.catalogType;
        const result=await VKApi.request(endpoint(type),{cache:false});
        if(ticket!==version || root()!==target)return;
        rows=result.data || [];
        if(type==='category'){
            const actions=item=>allowed()?`<div class="pc-actions">${!item.parent_id?`<button class="btn small" data-catalog-child="${item.id}">+ Nhóm con</button>`:''}<button class="btn small" data-catalog-edit="${item.id}">Sửa</button><button class="btn danger small" data-catalog-delete="${item.id}">Xóa</button></div>`:'';
            const ordered=rows.filter(x=>!x.parent_id).flatMap(parent=>[parent,...rows.filter(x=>String(x.parent_id)===String(parent.id))]);
            const columns=[
                {label:'Tên danh mục',render:item=>`<div class="pc-table-name ${item.parent_id?'child':''}">${item.parent_id?'<span class="pc-child-mark" aria-hidden="true">↳</span>':''}<strong>${VKTable.escapeHtml(item.name)}</strong><span class="pc-level">Cấp ${item.parent_id?'2':'1'}</span></div>`},
                {label:'Trạng thái',className:'pc-status',render:item=>VKTable.statusBadge(item.status)},
            ];
            if(allowed())columns.push({label:'Thao tác',className:'catalog-actions',render:actions});
            target.innerHTML=`<div class="pc-compact-list">${VKTable.fullList({title:'Danh sách danh mục',subtitle:'Các danh mục này sẽ xuất hiện trong danh sách chọn khi thêm sản phẩm.',meta:`${rows.length} danh mục`,table:VKTable.renderTable(columns,ordered,'Chưa có danh mục')})}</div>`;
            return;
        }
        const columns=[{label:'Tên',render:r=>`<strong>${VKTable.escapeHtml(r.name)}</strong>`},{label:'Trạng thái',render:r=>VKTable.statusBadge(r.status)}];
        if(allowed())columns.push({label:'Thao tác',className:'catalog-actions',render:r=>`<div style="display:flex;justify-content:flex-end;gap:8px"><button type="button" class="btn small" data-catalog-edit="${r.id}">Sửa</button><button type="button" class="btn danger small" data-catalog-delete="${r.id}">Xóa</button></div>`});
        target.innerHTML=VKTable.fullList({title:`Danh sách ${label(type)}`,subtitle:`Các ${label(type)} này sẽ xuất hiện trong danh sách chọn khi thêm sản phẩm.`,meta:`${rows.length} ${label(type)}`,table:VKTable.renderTable(columns,rows,`Chưa có ${label(type)}`)});
    }
    function edit(type,item=null,parentId=''){
        if(!allowed())return;
        const parent=type==='category'?VKModal.select('parent_id','Nhóm cha',[{value:'',label:'Không có — Nhóm cấp 1'},...rows.filter(x=>!x.parent_id && x.id!==item?.id).map(x=>({value:x.id,label:x.name}))],item?.parent_id || parentId):'';
        VKModal.open(`${item?'Sửa':'Thêm'} ${label(type)}`,parent+VKModal.field('name',`Tên ${label(type)}`,'text',item?.name || '')+VKModal.select('status','Trạng thái',[{value:'active',label:'Hoạt động'},{value:'inactive',label:'Ngừng sử dụng'}],item?.status || 'active'),async form=>{
            const data=Object.fromEntries(new FormData(form));data.name=data.name.trim();
            if(!data.name)throw new Error('Vui lòng nhập tên.');
            await VKApi.request(endpoint(type)+(item?`/${item.id}`:''),{method:item?'PUT':'POST',body:JSON.stringify(data)});
            VKModal.close();await load();VKModal.toast(item?'Đã cập nhật.':'Đã thêm mới.');
        });
    }
    document.addEventListener('click',async event=>{
        if(!root())return;
        if(event.target.closest('[data-catalog-tree-import]')){importTree();return;}
        const child=event.target.closest('[data-catalog-child]');if(child){edit('category',null,child.dataset.catalogChild);return;}
        const create=event.target.closest('[data-catalog-create]');
        if(create){edit(create.dataset.catalogCreate);return;}
        const button=event.target.closest('[data-catalog-edit],[data-catalog-delete]');
        if(!button || !allowed())return;
        const item=rows.find(r=>String(r.id)===String(button.dataset.catalogEdit || button.dataset.catalogDelete));if(!item)return;
        const type=root().dataset.catalogType;
        if(button.hasAttribute('data-catalog-edit')){edit(type,item);return;}
        if(!await VKModal.confirm({title:`Xóa ${label(type)}`,message:`Bạn muốn xóa “${item.name}”?`,confirmText:'Xóa',cancelText:'Hủy'}))return;
        try{await VKApi.request(`${endpoint(type)}/${item.id}`,{method:'DELETE'});await load();VKModal.toast('Đã xóa.');}
        catch(error){VKModal.toast(error.message || 'Không thể xóa.','danger');}
    });
    window.loadProductCatalog=load;document.addEventListener('vk:ready',()=>load());
    function importTree(){
        let token=null;
        VKModal.open('Nhập cây danh mục sản phẩm', '<div class="field"><label>File phân loại Excel</label><input type="file" name="file" accept=".xlsx" required></div><div data-tree-preview></div>',async form=>{
            const body=token?JSON.stringify({token}):new FormData();if(!token)body.append('file',form.elements.file.files[0]);
            const result=await VKApi.request('/product-categories/import-tree',{method:'POST',body});
            if(token){VKModal.close();await load();VKModal.toast(`Đã thêm ${result.data.created} nhóm.`);return;}
            token=result.data.token;form.querySelector('[data-tree-preview]').innerHTML=`<p>Sẽ thêm ${result.data.create_count} nhóm. Nhóm đã có giữ nguyên.</p><div class="pc-preview">${result.data.rows.map(r=>`<p>${VKTable.escapeHtml(r.path)} <small>· ${VKTable.escapeHtml(r.action)}</small></p>`).join('')}</div>`;document.getElementById('modalSubmit').textContent='Xác nhận nhập';
        },{submitText:'Kiểm tra file'});
        document.getElementById('modalForm').elements.file.onchange=()=>{token=null;document.querySelector('[data-tree-preview]').innerHTML='';document.getElementById('modalSubmit').textContent='Kiểm tra file';};
    }
})();
