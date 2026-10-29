(function () {
    let rows = [], canManage = false;
    const esc = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    const byId = id => rows.find(r => String(r.id) === String(id));
    function path(id) {
        const result = [], seen = new Set();
        while (id && !seen.has(String(id))) { seen.add(String(id)); const row = byId(id); if (!row) break; result.unshift(row); id = row.parent_id; }
        return result;
    }
    function active(row) { return path(row.id).every(r => r.is_active); }
    async function load() {
        const result = await VKApi.request('/customer-groups');
        rows = result.data || []; canManage = !!result.can_manage;
    }
    function field(customer) {
        const selected = path(customer?.customer_group_id);
        return `<div class="cg-picker field"><span>Phân nhóm khách hàng <small>Không bắt buộc</small></span><div class="cg-levels">${[0,1,2].map(level => {
            const parent = selected[level - 1]?.id;
            const choices = rows.filter(r => (level === 0 ? !r.parent_id : parent && String(r.parent_id) === String(parent)) && (active(r) || selected.some(s => s.id === r.id)));
            return `<div class="field customer-field cg-level-field"><label for="customer-group-level-${level}">Nhóm cấp ${level+1}</label><div class="customer-input-wrap"><select id="customer-group-level-${level}" data-cg-level="${level}" ${level && !parent ? 'disabled' : ''}><option value="">${level ? 'Chọn nhóm chi tiết' : 'Chưa phân nhóm'}</option>${choices.map(r => `<option value="${r.id}" ${String(selected[level]?.id)===String(r.id)?'selected':''}>${esc(r.name)}${active(r)?'':' · Ngừng dùng'}</option>`).join('')}</select></div></div>`;
        }).join('')}</div><input type="hidden" name="customer_group_id" value="${esc(customer?.customer_group_id || '')}">${!customer?.customer_group_id && customer?.customer_group ? `<small>Nhóm cũ: ${esc(customer.customer_group)} — được giữ nguyên.</small>` : ''}${!rows.length ? '<small>Chưa có danh mục. Admin có thể thêm tại “Phân nhóm” trên danh sách khách hàng.</small>' : ''}</div>`;
    }
    function options(selected) {
        return `<option value="">Tất cả phân nhóm</option>${rows.map(r=>`<option value="${r.id}" ${String(selected)===String(r.id)?'selected':''}>${esc(path(r.id).map(p=>p.name).join(' / '))}</option>`).join('')}`;
    }
    async function manage() {
        await load();
        const renderTree = parent => rows.filter(r => String(r.parent_id || '') === String(parent || '')).map(r => `<div class="cg-node"><div class="cg-node-line"><span class="cg-level-badge">${path(r.id).length}</span><strong>${esc(r.name)}</strong><span class="cg-status ${active(r)?'':'off'}">${active(r)?'Đang sử dụng':'Ngừng sử dụng'}</span><button type="button" class="btn small" data-cg-edit="${r.id}">Chỉnh sửa</button>${path(r.id).length<3?`<button type="button" class="btn small" data-cg-add="${r.id}" ${active(r)?'':'disabled'}>＋ Nhóm con</button>`:''}</div><div class="cg-children">${renderTree(r.id)}</div></div>`).join('');
        VKModal.open('Phân nhóm khách hàng', `<div class="cg-manager"><div class="cg-intro"><div><h3>Danh mục của công ty</h3><p>Tối đa 3 cấp · Nhân viên chọn nhóm khi tạo khách hàng</p></div><button type="button" class="btn primary" data-cg-add="">＋ Thêm nhóm cấp 1</button></div><div class="cg-tree">${renderTree(null) || '<div class="cg-empty"><h3>Bắt đầu từ nhóm khách hàng của bạn</h3><p>Thêm nhóm cấp 1, sau đó thêm nhóm con nếu cần. Không bắt buộc dùng đủ 3 cấp.</p></div>'}</div><p class="cg-footnote">Ngừng sử dụng để giữ lịch sử. Nhóm cha không thể thay đổi sau khi tạo.</p></div>`, null);
        document.getElementById('modalSubmit').hidden = true;
        document.querySelectorAll('[data-cg-add]').forEach(b => b.onclick=()=>edit(null,b.dataset.cgAdd));
        document.querySelectorAll('[data-cg-edit]').forEach(b => b.onclick=()=>edit(byId(b.dataset.cgEdit)));
    }
    function edit(row, parentId = '') {
        const parent = row?.parent_id || parentId;
        VKModal.open(row?'Chỉnh sửa nhóm':'Thêm nhóm khách hàng', `<div class="cg-edit"><p class="cg-breadcrumb">${parent ? esc(path(parent).map(r=>r.name).join(' / ')) : 'Nhóm cấp 1'}</p><label class="field">Tên nhóm<input name="name" maxlength="100" required value="${esc(row?.name)}" placeholder="Nhập tên nhóm khách hàng"></label><label class="cg-active"><input type="checkbox" name="active" ${!row || row.is_active?'checked':''}> Cho phép sử dụng</label></div>`, async form => {
            await VKApi.request(`/customer-groups${row?'/'+row.id:''}`, {method:row?'PUT':'POST',body:JSON.stringify({name:form.elements.name.value,parent_id:parent?Number(parent):null,is_active:form.elements.active.checked})});
            // Modal submit closes its current surface; reopen the updated tree afterwards.
            VKModal.close();
            if (document.getElementById('customerGroupsRoot')) await window.loadCustomerGroupsPage();
            else await manage();
        });
    }
    document.addEventListener('change', event => {
        if (!event.target.matches('[data-cg-level]')) return;
        const picker = event.target.closest('.cg-picker'), level = Number(event.target.dataset.cgLevel);
        for (let next=level+1;next<3;next++) {
            const select=picker.querySelector(`[data-cg-level="${next}"]`), parent=picker.querySelector(`[data-cg-level="${next-1}"]`).value;
            select.innerHTML='<option value="">Chọn nhóm chi tiết</option>'+rows.filter(r=>String(r.parent_id)===parent && active(r)).map(r=>`<option value="${r.id}">${esc(r.name)}</option>`).join('');
            select.disabled=!parent;
        }
        picker.querySelector('[name="customer_group_id"]').value=Array.from(picker.querySelectorAll('select')).map(s=>s.value).filter(Boolean).pop() || '';
    });
    document.addEventListener('click', e => { if (e.target.closest('[data-manage-customer-groups]')) window.location.href='/customer-groups'; });
    window.VKCustomerGroups={load,field,options,path,active,edit, get rows(){return rows;}, get canManage(){return canManage;}};
})();
