(function(){
    const esc=value=>VKTable.escapeHtml(String(value ?? ''));
    document.addEventListener('click',async event=>{
        if(!event.target.closest('[data-import-access]'))return;
        try{
            const response=await VKApi.request('/import-center/sources',{cache:false});
            if(!response.meta.can_manage)throw new Error('Bạn chưa có quyền quản lý nguồn nhập.');
            const sources=response.data,users=response.meta.users || [];
            VKModal.open('Phân quyền nguồn nhập',`<p class="form-note">Quyền này bổ sung cho quyền nhập danh mục và phạm vi toàn công ty, không thay thế quyền vai trò.</p>${sources.length?'<div class="field"><label>Nguồn nhập</label><select name="access_source">'+sources.map(s=>`<option value="${Number(s.id)}">${esc(s.name)} (${esc(s.code)})</option>`).join('')+'</select></div><div data-access-members></div>':'<p>Chưa có nguồn. Tạo nguồn tại Quản lý nhập trước.</p>'}`,async form=>{
                const values=new FormData(form),source=sources.find(s=>Number(s.id)===Number(values.get('access_source')));
                await VKApi.request('/import-center/sources/'+source.id,{method:'PUT',body:JSON.stringify({name:source.name,is_active:source.is_active,member_ids:values.getAll('access_member').map(Number)})});
                VKModal.close();VKModal.toast('Đã lưu quyền sử dụng nguồn nhập.');
            },{submitText:'Lưu quyền',hideSubmit:!sources.length,className:'source-editor-modal'});
            if(!sources.length)return;
            const body=document.getElementById('modalBody'),select=body.querySelector('[name="access_source"]');
            const render=()=>{const source=sources.find(s=>Number(s.id)===Number(select.value));body.querySelector('[data-access-members]').innerHTML=`<div class="source-member-list">${users.map(u=>`<label class="source-member-row"><input type="checkbox" name="access_member" value="${Number(u.id)}" ${(source.member_ids || []).map(Number).includes(Number(u.id))?'checked':''}><span class="source-member-name">${esc(u.name)}</span></label>`).join('')}</div>`;};
            select.onchange=render;render();
        }catch(error){VKModal.toast(error.message || 'Chưa tải được quyền nguồn nhập.','danger');}
    });
})();
