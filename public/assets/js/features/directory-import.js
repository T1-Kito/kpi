(function(){
    if(window.vkDirectoryImportBound)return;
    window.vkDirectoryImportBound=true;
    document.addEventListener('click',event=>{
        const trigger=event.target.closest('[data-directory-import]'); if(!trigger) return;
        const kind=trigger.dataset.directoryImport; if(!['contacts','leads','categories','brands','skus','suppliers','warehouses','locations','positions','departments','users'].includes(kind)) return;
        const product=['categories','brands','skus','suppliers','warehouses','locations','positions','departments','users'].includes(kind);
        const productNames={categories:'Danh mục sản phẩm',brands:'Hãng sản phẩm',skus:'Sản phẩm và mã hàng',suppliers:'Nhà cung cấp',warehouses:'Kho',locations:'Vị trí kho',positions:'Chức vụ',departments:'Phòng ban',users:'Người dùng'};
        if(product){openProductImport(kind,productNames[kind]);return;}
        let verified=null;
        let batchId=null;
        VKModal.open(kind==='contacts'?'Nhập người liên hệ':'Nhập khách hàng tiềm năng','<button type="button" class="btn small" data-directory-template>Tải mẫu</button><div class="field" style="margin-top:16px"><label>Chọn danh sách</label><input type="file" accept=".xlsx" data-directory-file style="height:auto;min-height:48px;padding:12px"><small>Excel · Tối đa 300 dòng · 1 MB</small></div><div data-directory-result role="status" style="margin-top:16px"></div>',async form=>{
            const file=form.querySelector('[data-directory-file]').files[0];if(!file)throw new Error('Chọn file Excel theo mẫu.');if(file.size>1024*1024)throw new Error('File vượt 1 MB.');
            const body=new FormData();body.append('file',file);body.append('confirm',verified===file?'1':'0');
            let result,committed=false;
            {
                const batch=(await VKApi.request(verified===file && batchId?`/import-center/batches/${batchId}/commit`:`/import-center/${kind==='contacts'?'contact':'lead'}-preview`,verified===file && batchId?{method:'POST',body:JSON.stringify({confirmed:true}),timeout:180000}:{method:'POST',body,timeout:180000})).data;
                result=batch.result;batchId=batch.id;committed=batch.status==='committed';
            }
            if(result.imported || committed){VKModal.close();VKModal.toast(`Đã xử lý ${result.imported} hồ sơ, bỏ qua ${result.skipped || 0} dòng đã có.`,'success');if(kind==='contacts')await window.loadCustomerContacts?.();else await window.loadLeads?.();return;}
            verified=result.can_import?file:null;
            const escape=VKTable.escapeHtml;
            const leadPreview=kind==='leads' && verified?`<div class="table-wrap"><table class="list-table"><thead><tr><th>Dòng</th><th>Thao tác</th><th>Khách tiềm năng</th><th>Điện thoại</th><th>Email</th><th>Nguồn</th></tr></thead><tbody>${(result.preview || []).map(row=>`<tr><td>${Number(row.row)}</td><td>${row.action==='skip'?'Bỏ qua':'Thêm mới'}</td><td>${escape(row.name)}</td><td>${escape(row.phone)}</td><td>${escape(row.email || '')}</td><td>${escape(row.source || '')}</td></tr>`).join('')}</tbody></table></div>`:'';
            form.querySelector('[data-directory-result]').innerHTML=verified?`<p>${result.total} dòng hợp lệ. Xem lại trước khi xác nhận.</p>${kind==='contacts'?`<div class="table-wrap"><table class="list-table"><thead><tr><th>Dòng</th><th>Thao tác</th><th>Người liên hệ</th><th>Khách hàng</th><th>Liên hệ chính</th><th>Thay đổi</th></tr></thead><tbody>${(result.preview || []).map(row=>`<tr><td>${Number(row.row)}</td><td>${({create:'Thêm mới',update:'Cập nhật',skip:'Bỏ qua'})[row.action] || ''}</td><td>${escape(row.name)}</td><td>${escape(row.customer_code)} · ${escape(row.customer_name)}</td><td>${row.is_primary===null?'Theo hồ sơ':row.is_primary?'Có':'Không'}</td><td>${Object.entries(row.changes || {}).map(([key,v])=>`${escape(({name:'Họ tên',phone:'Điện thoại',email:'Email',position:'Chức vụ',is_primary:'Liên hệ chính'})[key] || key)}: ${escape(String(v.before ?? 'Trống'))} → ${escape(String(v.after ?? 'Trống'))}`).join('<br>') || '—'}</td></tr>`).join('')}</tbody></table></div>`:''}`:`<p>Chưa nhập dữ liệu. Sửa các dòng sau:</p><ul>${result.errors.map(e=>`<li>Dòng ${escape(String(e.row))}: ${escape(e.message)}</li>`).join('')}</ul>`;
            document.getElementById('modalSubmit').textContent=verified?'Xác nhận nhập':'Kiểm tra file';
            if(leadPreview)form.querySelector('[data-directory-result]').insertAdjacentHTML('beforeend',leadPreview);
        },{submitText:'Kiểm tra file'});
        document.querySelector('[data-directory-file]').addEventListener('change',()=>{verified=null;batchId=null;document.querySelector('[data-directory-result]').textContent='';document.getElementById('modalSubmit').textContent='Kiểm tra file';});
        document.querySelector('[data-directory-template]').addEventListener('click',async()=>{
            try{const response=await fetch(`/api/v1/import-center/${kind==='contacts'?'contact':'lead'}-template?v=20261023-2`,{cache:'no-store',headers:{Authorization:`Bearer ${VKApi.token()}`}});if(!response.ok)throw new Error('Không tải được mẫu hoặc chưa có quyền.');const url=URL.createObjectURL(await response.blob());const a=document.createElement('a');a.href=url;a.download=kind==='contacts'?'Mẫu người liên hệ.xlsx':'Mẫu khách hàng tiềm năng.xlsx';a.click();setTimeout(()=>URL.revokeObjectURL(url),1000);}catch(e){VKModal.toast(e.message,'danger');}
        });
    });
    function openProductImport(kind,title){
        let verified=null,batchId=null;
        const procurement=['suppliers','warehouses','locations'].includes(kind),organization=['positions','departments','users'].includes(kind),base=organization?'organization':procurement?'procurement':'products';
        const labels={name:'Tên',status:'Trạng thái',product_code:'Mã sản phẩm',sku_code:'Mã hàng',product_name:'Tên sản phẩm',category_id:'Danh mục sản phẩm',brand_id:'Hãng sản phẩm',serial_number:'Serial',barcode:'Mã vạch',unit:'Đơn vị tính',min_stock:'Tồn tối thiểu',max_stock:'Tồn tối đa',sale_price:'Giá bán',cost_price:'Giá vốn',description:'Mô tả',tax_code:'Mã số thuế',phone:'Điện thoại',email:'Email',address:'Địa chỉ',contact_name:'Người liên hệ',payment_terms:'Điều khoản thanh toán',manager_id:'Người quản lý',warehouse_id:'Kho',code:'Mã vị trí'};
        const esc=VKTable.escapeHtml;
        Object.assign(labels,{parent_id:'Phòng ban cha',department_id:'Phòng ban',position_id:'Chức vụ',is_active:'Kích hoạt'});
        const instruction=organization?'Nhập chức vụ và phòng ban trước người dùng. Người dùng được tạo chưa kích hoạt, chưa có vai trò. Không nhập mật khẩu vào Excel.':procurement?'Nhập kho trước vị trí kho.':'Nhập danh mục và hãng trước sản phẩm.';
        VKModal.open('Nhập '+title.toLocaleLowerCase('vi'),`<button type="button" class="btn small" data-product-template>Tải mẫu</button><p class="form-note">${instruction} Kiểm tra toàn bộ danh sách rồi xác nhận.</p><label class="field">Chọn danh sách<input type="file" accept=".xlsx" data-product-file style="height:auto;padding:12px"></label><small>Excel · Tối đa 300 dòng · 1 MB</small><div data-product-result role="status"></div>`,async form=>{
            const file=form.querySelector('[data-product-file]').files[0];if(!file)throw new Error('Vui lòng chọn file Excel theo mẫu.');if(file.size>1024*1024)throw new Error('File vượt quá 1 MB.');
            const commit=verified===file && batchId;const body=new FormData();body.append('file',file);
            const batch=(await VKApi.request(commit?`/import-center/batches/${batchId}/commit`:`/import-center/${base}/${kind}/preview`,commit?{method:'POST',body:JSON.stringify({confirmed:true}),timeout:180000}:{method:'POST',body,timeout:180000})).data;
            if(batch.status==='committed'){VKModal.close();VKModal.toast(`Đã nhập ${batch.result.imported} hồ sơ, bỏ qua ${batch.result.skipped || 0} dòng.`,'success');if(kind==='users')await window.loadUsers?.();else if(organization)await window.loadOrganization?.();else if(kind==='skus')await window.loadSkus?.();else if(kind==='suppliers')await window.loadSuppliers?.();else if(['warehouses','locations'].includes(kind))await window.loadWarehouses?.();else await window.loadProductCatalog?.();return;}
            batchId=batch.id;verified=batch.result.can_import?file:null;
            form.querySelector('[data-product-result]').innerHTML=verified?`<p>${batch.result.total} dòng hợp lệ. Kiểm tra trước khi xác nhận.</p><div class="table-wrap"><table class="list-table"><thead><tr><th>Dòng</th><th>Tên</th><th>Thao tác</th><th>Thay đổi</th></tr></thead><tbody>${batch.result.preview.map(row=>`<tr><td>${Number(row.row)}</td><td>${esc(row.name)}</td><td>${({create:'Thêm mới',update:'Cập nhật',skip:'Bỏ qua'})[row.action]}</td><td>${Object.entries(row.changes || {}).map(([key,v])=>`${esc(labels[key] || key)}: ${esc(String(v.before ?? 'Trống'))} → ${esc(String(v.after ?? 'Trống'))}`).join('<br>') || '—'}</td></tr>`).join('')}</tbody></table></div>`:`<p>Chưa lưu dữ liệu. Sửa các dòng sau rồi kiểm tra lại:</p><ul>${batch.result.errors.map(error=>`<li>Dòng ${Number(error.row)}: ${esc(error.message)}</li>`).join('')}</ul>`;
            if(verified && procurement && kind!=='suppliers')form.querySelector('[data-product-result]').insertAdjacentHTML('beforeend',`<div class="table-wrap"><table class="list-table"><thead><tr><th>Dòng</th><th>${kind==='locations'?'Kho nhận vị trí':'Người quản lý'}</th></tr></thead><tbody>${batch.result.preview.map(row=>`<tr><td>${Number(row.row)}</td><td>${esc(row.reference_label || 'Chưa gán')}</td></tr>`).join('')}</tbody></table></div>`);
            if(verified && organization && kind!=='positions')form.querySelector('[data-product-result]').insertAdjacentHTML('beforeend',`<div class="table-wrap"><table class="list-table"><thead><tr><th>Dòng</th><th>Email</th><th>Liên kết tổ chức</th></tr></thead><tbody>${batch.result.preview.map(row=>`<tr><td>${Number(row.row)}</td><td>${esc(row.email || '—')}</td><td>${esc(row.reference_label || 'Chưa gán')}</td></tr>`).join('')}</tbody></table></div>`);
            document.getElementById('modalSubmit').textContent=verified?'Xác nhận nhập':'Kiểm tra file';
        },{submitText:'Kiểm tra file'});
        document.querySelector('[data-product-file]').addEventListener('change',()=>{verified=null;batchId=null;document.querySelector('[data-product-result]').textContent='';document.getElementById('modalSubmit').textContent='Kiểm tra file';});
        document.querySelector('[data-product-template]').addEventListener('click',async()=>{try{
            const response=await fetch(`/api/v1/import-center/${base}/${kind}/template`,{cache:'no-store',headers:{Authorization:`Bearer ${VKApi.token()}`}});if(!response.ok)throw new Error('Không tải được mẫu. Kiểm tra quyền nhập danh mục và quyền giá vốn nếu nhập mã hàng.');
            const url=URL.createObjectURL(await response.blob()),link=document.createElement('a');link.href=url;link.download=`Mẫu ${title}.xlsx`;link.click();setTimeout(()=>URL.revokeObjectURL(url),1000);
        }catch(error){VKModal.toast(error.message,'danger');}});
    }
})();
