(function(){
    document.addEventListener('click',event=>{
        const trigger=event.target.closest('[data-directory-import]'); if(!trigger) return;
        const kind=trigger.dataset.directoryImport; if(!['contacts','leads'].includes(kind)) return;
        let verified=null;
        VKModal.open(kind==='contacts'?'Nhập người liên hệ':'Nhập khách hàng tiềm năng','<button type="button" class="btn small" data-directory-template>Tải mẫu</button><div class="field" style="margin-top:16px"><label>Chọn danh sách</label><input type="file" accept=".xlsx" data-directory-file style="height:auto;min-height:48px;padding:12px"><small>Excel · Tối đa 300 dòng · 1 MB</small></div><div data-directory-result role="status" style="margin-top:16px"></div>',async form=>{
            const file=form.querySelector('[data-directory-file]').files[0];if(!file)throw new Error('Chọn file Excel theo mẫu.');if(file.size>1024*1024)throw new Error('File vượt 1 MB.');
            const body=new FormData();body.append('file',file);body.append('confirm',verified===file?'1':'0');
            const result=(await VKApi.request('/directory-import/'+kind,{method:'POST',body,timeout:180000})).data;
            if(result.imported){VKModal.close();VKModal.toast(`Đã nhập ${result.imported} hồ sơ.`,'success');if(kind==='contacts')await window.loadCustomerContacts?.();else await window.loadLeads?.();return;}
            verified=result.can_import?file:null;
            const escape=VKTable.escapeHtml;
            form.querySelector('[data-directory-result]').innerHTML=verified?`<p>${result.total} dòng hợp lệ. Xác nhận để nhập.</p>`:`<p>Chưa nhập dữ liệu. Sửa các dòng sau:</p><ul>${result.errors.map(e=>`<li>Dòng ${escape(String(e.row))}: ${escape(e.message)}</li>`).join('')}</ul>`;
            document.getElementById('modalSubmit').textContent=verified?'Xác nhận nhập':'Kiểm tra file';
        },{submitText:'Kiểm tra file'});
        document.querySelector('[data-directory-file]').addEventListener('change',()=>{verified=null;document.querySelector('[data-directory-result]').textContent='';document.getElementById('modalSubmit').textContent='Kiểm tra file';});
        document.querySelector('[data-directory-template]').addEventListener('click',async()=>{
            try{const response=await fetch('/api/v1/directory-import/'+kind+'/template',{headers:{Authorization:`Bearer ${VKApi.token()}`}});if(!response.ok)throw new Error('Không tải được mẫu hoặc chưa có quyền.');const url=URL.createObjectURL(await response.blob());const a=document.createElement('a');a.href=url;a.download=kind==='contacts'?'mau-nguoi-lien-he.xlsx':'mau-khach-tiem-nang.xlsx';a.click();setTimeout(()=>URL.revokeObjectURL(url),1000);}catch(e){VKModal.toast(e.message,'danger');}
        });
    });
})();
