(function () {
    let data = { definitions: [], activeDefinitions: [], targets: [] };
    let loadVersion = 0;

    window.loadKpiSettings = loadKpiSettings;
    document.addEventListener('vk:ready', loadKpiSettings);

    document.addEventListener('click', (event) => {
        if (!document.getElementById('kpiSettingsRoot')) return;
        if (!canConfigure()) return;

        const standardSetup = event.target.closest('[data-kpi-standard-setup]');
        if (standardSetup) {
            setupStandardKpi();
            return;
        }

        const assignmentButton = event.target.closest('[data-kpi-assignment-create], [data-kpi-assignment-edit], [data-kpi-assignment-lock], [data-kpi-period-close], [data-kpi-period-reopen], [data-kpi-period-finalize], [data-kpi-ack-resolve]');
        if (assignmentButton) {
            if (assignmentButton.hasAttribute('data-kpi-period-close')) closePeriod(assignmentButton.dataset.kpiPeriodClose);
            else if (assignmentButton.hasAttribute('data-kpi-period-reopen')) openReopenModal(assignmentButton.dataset.kpiPeriodReopen);
            else if (assignmentButton.hasAttribute('data-kpi-period-finalize')) finalizePeriod(assignmentButton.dataset.kpiPeriodFinalize);
            else if (assignmentButton.hasAttribute('data-kpi-ack-resolve')) openResolveModal(assignmentButton.dataset.kpiAckResolve);
            else if (assignmentButton.hasAttribute('data-kpi-assignment-lock')) lockAssignment(assignmentButton.dataset.kpiAssignmentLock);
            else openAssignmentModal(data.assignments?.find(item => String(item.id) === assignmentButton.dataset.kpiAssignmentEdit) || null);
            return;
        }
        const bundleButton = event.target.closest('[data-kpi-bundle-create], [data-kpi-bundle-edit], [data-kpi-bundle-publish], [data-kpi-bundle-duplicate], [data-kpi-bundle-apply]');
        if (bundleButton) {
            const id = bundleButton.dataset.kpiBundleEdit || bundleButton.dataset.kpiBundlePublish || bundleButton.dataset.kpiBundleDuplicate || bundleButton.dataset.kpiBundleApply;
            const bundle = (data.bundles || []).find(item => String(item.id) === String(id));
            if (bundleButton.hasAttribute('data-kpi-bundle-create')) openBundleModal();
            else if (bundleButton.hasAttribute('data-kpi-bundle-edit')) openBundleModal(bundle);
            else if (bundleButton.hasAttribute('data-kpi-bundle-publish')) publishBundle(bundle);
            else if (bundleButton.hasAttribute('data-kpi-bundle-duplicate')) duplicateBundle(bundle);
            else openBundleApplyModal(bundle);
            return;
        }

        const createDefinition = event.target.closest('[data-create-kpi-definition]');
        if (createDefinition) {
            openDefinitionModal();
            return;
        }

        const editDefinition = event.target.closest('[data-edit-kpi-definition]');
        if (editDefinition) {
            openDefinitionModal(findDefinition(editDefinition.dataset.editKpiDefinition));
            return;
        }

        const createTarget = event.target.closest('[data-create-kpi-target]');
        if (createTarget) {
            openTargetModal(null, createTarget.dataset.createKpiTarget || '');
            return;
        }

        const editTarget = event.target.closest('[data-edit-kpi-target]');
        if (editTarget) {
            openTargetModal(findTarget(editTarget.dataset.editKpiTarget));
            return;
        }

        const lockTarget = event.target.closest('[data-lock-kpi-target]');
        if (lockTarget) {
            lockKpiTarget(lockTarget.dataset.lockKpiTarget);
        }
    });

    async function loadKpiSettings() {
        const root = document.getElementById('kpiSettingsRoot');
        if (!root) return;
        const version = ++loadVersion;
        if (!canConfigure()) {
            data = {definitions: [], activeDefinitions: [], targets: []};
            root.innerHTML = '<section class="module-panel"><p class="form-note">Bạn chưa được cấp quyền quản lý KPI. Vui lòng liên hệ người quản trị.</p></section>';
            return;
        }
        root.innerHTML = '<section class="module-panel"><p class="form-note">Đang tải cấu hình KPI…</p></section>';
        try {
            const [definitions, targets, assignments, bundles, closures] = await Promise.all([
                VKApi.request('/kpi/definitions', {cache:false}),
                VKApi.request('/kpi/targets', {cache:false}),
                VKApi.request('/kpi/assignments', {cache:false}),
                VKApi.request('/kpi/bundles', {cache:false}),
                VKApi.request('/kpi/period-closures', {cache:false}),
            ]);
            if (version !== loadVersion || document.getElementById('kpiSettingsRoot') !== root) return;
            data = {definitions: definitions.data || [], targets: targets.data || [], assignments: assignments.data || [], assignmentMeta: assignments.meta || {}, bundles: bundles.data || [], closures: closures.data || []};
            data.activeDefinitions = data.definitions.filter(row => row.status === 'active');
            root.innerHTML = renderBundlePanel() + renderAssignmentPanel() + renderClosurePanel() + renderSettingsPage();
        } catch (error) {
            if (version !== loadVersion || document.getElementById('kpiSettingsRoot') !== root) return;
            data = {definitions: [], activeDefinitions: [], targets: []};
            root.innerHTML = `<section class="module-panel"><p class="form-note">${VKTable.escapeHtml(error.message || 'Không thể tải cấu hình KPI.')}</p></section>`;
        }
    }

    function readData() {
        // Data is held only after the authenticated load, never embedded in HTML.
    }

    function canConfigure() {
        return Array.isArray(window.VKUser?.permissions) && window.VKUser.permissions.includes('kpi.lock');
    }

    function renderSettingsPage() {
        const esc = VKTable.escapeHtml;
        const definitions = VKTable.renderTable([
            {label:'Mã', render:row => esc(row.code)},
            {label:'Chỉ tiêu', render:row => esc(row.name)},
            {label:'Nguồn', render:row => esc(row.source_type || '—')},
            {label:'Công thức', render:row => esc(row.formula || '—')},
            {label:'Đơn vị', render:row => esc(row.unit || '—')},
            {label:'Trọng số', render:row => esc(row.weight)},
            {label:'Trạng thái', render:row => VKTable.statusBadge(row.status)},
            {label:'', render:row => `<button class="btn secondary small" data-edit-kpi-definition="${row.id}">Sửa</button>`},
        ], data.definitions, 'Chưa có chỉ tiêu KPI.');
        const targets = VKTable.renderTable([
            {label:'Mã chỉ tiêu', render:row => esc(row.definition?.code || '—')},
            {label:'Chỉ tiêu', render:row => esc(row.definition?.name || '—')},
            {label:'Kỳ', render:row => `${esc(dateInputValue(row.period_start))} → ${esc(dateInputValue(row.period_end))}`},
            {label:'Mục tiêu', render:row => VKTable.money(row.target_value)},
            {label:'Thực tế', render:row => VKTable.money(row.actual_value)},
            {label:'Điểm', render:row => VKTable.money(row.score)},
            {label:'Trạng thái', render:row => VKTable.statusBadge(row.status)},
            {label:'', render:row => row.locked_at || row.status === 'locked' ? '<span class="badge inactive">Đã khóa</span>' : `<button class="btn secondary small" data-edit-kpi-target="${row.id}">Sửa</button> <button class="btn secondary small" data-lock-kpi-target="${row.id}">Khóa kỳ</button>`},
        ], data.targets, 'Chưa có mục tiêu KPI.');
        return `<div class="settings-stack"><section class="module-panel"><div class="panel-head"><div><h2>Danh mục chỉ tiêu</h2><span>${data.activeDefinitions.length} chỉ tiêu đang dùng · Chọn “Phân KPI” để áp dụng nguồn tự động và trọng số cho nhân viên.</span></div><button class="btn primary small" data-create-kpi-definition>Tạo chỉ tiêu</button></div>${definitions}</section><details class="module-panel"><summary style="padding:18px;cursor:pointer">Mục tiêu vận hành cũ (không phân theo nhân viên)</summary><div class="panel-head"><div><h2>Mục tiêu vận hành</h2><span>Tách biệt với KPI được giao. Giữ lại dữ liệu cũ để đối chiếu.</span></div><button class="btn secondary small" data-create-kpi-target>Thêm mục tiêu cũ</button></div>${targets}</details><p class="form-note">KPI được giao tính theo kỳ và nhân viên; điểm vận hành công ty cũ vẫn là lũy kế. Chưa tự quy đổi điểm thành tiền thưởng.</p></div>`;
    }

    function renderBundlePanel() {
        const esc = VKTable.escapeHtml;
        const rows = VKTable.renderTable([
            {label:'Bộ KPI',render:row=>`<span style="color:#ec008c">${esc(row.code || '')}</span> · v${esc(row.version || '')}<br><strong>${esc(row.name || '')}</strong>`},
            {label:'Chỉ tiêu',render:row=>`${row.items?.length || 0} chỉ tiêu<br><small>${numberValue((row.items || []).reduce((sum,item)=>sum+Number(item.weight || 0),0))}% trọng số</small>`},
            {label:'Trạng thái',render:row=>`${esc(({draft:'Bản nháp',active:'Đang áp dụng',inactive:'Phiên bản cũ'})[row.status] || row.status || '—')}<br><small>${(row.plans || []).filter(plan=>plan.status==='active').length} kế hoạch tự động</small>`},
            {label:'Thao tác',render:row=>row.status === 'draft' ? `<button class="btn secondary small" data-kpi-bundle-edit="${row.id}">Sửa</button> <button class="btn primary small" data-kpi-bundle-publish="${row.id}">Phát hành</button>` : `<button class="btn primary small" data-kpi-bundle-apply="${row.id}">Giao theo bộ</button> <button class="btn secondary small" data-kpi-bundle-duplicate="${row.id}">Tạo phiên bản mới</button>`},
        ], (data.bundles || []).filter(row=>row?.code), 'Chưa có bộ KPI mẫu.');
        return `<section class="module-panel" style="margin-bottom:20px"><div class="panel-head"><div><h2>Bộ KPI theo vị trí / phòng ban</h2><span>Tạo một lần, phát hành phiên bản rồi giao đồng thời nhiều chỉ tiêu.</span></div><div style="display:flex;gap:8px;flex-wrap:wrap"><button class="btn secondary small" data-kpi-standard-setup>Thiết lập nhanh KPI chuẩn</button><button class="btn primary small" data-kpi-bundle-create>Tạo bộ KPI</button></div></div>${rows}<p class="form-note">“Thiết lập nhanh” tự nhận diện phòng ban, tạo bộ KPI tháng và kế hoạch cho các kỳ sau. Có thể tạo phiên bản riêng khi doanh nghiệp cần thay đổi mục tiêu.</p></section>`;
    }

    async function setupStandardKpi() {
        if (!confirm('Thiết lập và giao bộ KPI chuẩn cho các phòng ban đang có? Hệ thống không tạo trùng cấu hình đã tồn tại.')) return;
        try {
            const response = await VKApi.request('/kpi/standard-setup', {method:'POST'});
            const result = response.data || {}, configured = result.configured || [], skipped = result.skipped || [];
            VKModal.notice({type:'success', title:'Đã thiết lập KPI chuẩn', message:`${configured.length} nhóm được cấu hình · ${result.created_assignments || 0} chỉ tiêu mới.`, details:[...configured.map(row=>`${row.name}: ${row.employees} nhân viên · ${row.indicators} chỉ tiêu`), ...skipped]});
            document.dispatchEvent(new CustomEvent('vk:kpi:changed'));
            await loadKpiSettings();
        } catch (error) { VKModal.toast(error.message || 'Không thể thiết lập KPI chuẩn.', 'danger'); }
    }

    function bundleItemRow(item = {}, index = 0) {
        const esc = VKTable.escapeHtml;
        const definitionOptions = data.activeDefinitions.map(definition => `<option value="${definition.id}" ${Number(definition.id) === Number(item.kpi_definition_id) ? 'selected' : ''}>${esc(definition.code)} — ${esc(definition.name)}</option>`).join('');
        const metricOptions = Object.entries(data.assignmentMeta.metrics || {}).map(([value,meta])=>`<option value="${esc(value)}" ${value === item.metric ? 'selected' : ''}>${esc(meta.label)}</option>`).join('');
        return `<div class="kpi-bundle-item" data-bundle-item><label>Chỉ tiêu<select name="items[${index}][kpi_definition_id]" required><option value="">Chọn chỉ tiêu</option>${definitionOptions}</select></label><label>Nguồn tự động<select name="items[${index}][metric]" required>${metricOptions}</select></label><label>Mục tiêu<input name="items[${index}][target_value]" type="number" min="0.01" step="0.01" value="${esc(item.target_value ?? 1)}" required></label><label>Trọng số %<input name="items[${index}][weight]" type="number" min="0.01" max="100" step="0.01" value="${esc(item.weight ?? 100)}" required></label><label>Hướng tính<select name="items[${index}][direction]"><option value="increase" ${item.direction !== 'decrease' ? 'selected' : ''}>Tăng là tốt</option><option value="decrease" ${item.direction === 'decrease' ? 'selected' : ''}>Giảm là tốt</option></select></label><button type="button" class="btn secondary small" data-remove-bundle-item>Xóa dòng</button></div>`;
    }

    function openBundleModal(bundle = null) {
        if (!data.activeDefinitions.length) return VKModal.toast('Hãy tạo chỉ tiêu đang dùng trước.', 'warning');
        const items = bundle?.items?.length ? bundle.items : [{}];
        VKModal.open(bundle ? `Sửa bộ KPI · v${bundle.version}` : 'Tạo bộ KPI mẫu', `<div class="form-grid two">
            ${VKModal.field('code','Mã bộ KPI','text',bundle?.code || '')}
            ${VKModal.field('name','Tên bộ KPI','text',bundle?.name || '')}
            <div class="field full"><label for="kpiBundleDescription">Mô tả</label><textarea id="kpiBundleDescription" name="description" rows="3" maxlength="2000" placeholder="Mô tả mục đích và nhóm nhân sự áp dụng">${VKTable.escapeHtml(bundle?.description || '')}</textarea></div>
            </div><div class="panel-head" style="padding-left:0;padding-right:0"><div><h3>Các chỉ tiêu trong bộ</h3><span>Tổng trọng số phải đúng 100%.</span></div><button type="button" class="btn secondary small" data-add-bundle-item>Thêm chỉ tiêu</button></div><div id="kpiBundleItems">${items.map(bundleItemRow).join('')}</div><p class="form-note" id="kpiBundleWeight"></p>`, async form => {
            const itemRows = Array.from(form.querySelectorAll('[data-bundle-item]'));
            const payload = {code:form.elements.code.value.trim(),name:form.elements.name.value.trim(),description:form.elements.description.value.trim(),items:itemRows.map(row=>({
                kpi_definition_id:Number(row.querySelector('[name*="kpi_definition_id"]').value),metric:row.querySelector('[name*="metric"]').value,
                target_value:Number(row.querySelector('[name*="target_value"]').value),weight:Number(row.querySelector('[name*="weight"]').value),direction:row.querySelector('[name*="direction"]').value,
            }))};
            const weight = payload.items.reduce((sum,item)=>sum+item.weight,0);
            if (Math.abs(weight-100)>0.001) throw new Error(`Tổng trọng số phải đúng 100%. Hiện tại: ${numberValue(weight)}%.`);
            await VKApi.request(bundle ? `/kpi/bundles/${bundle.id}` : '/kpi/bundles',{method:bundle?'PUT':'POST',body:JSON.stringify(payload)});
            VKModal.close(); VKModal.toast(bundle?'Đã cập nhật bản nháp.':'Đã tạo bộ KPI bản nháp.'); await loadKpiSettings();
        },{className:'wide-modal kpi-bundle-modal',submitText:bundle?'Lưu bản nháp':'Tạo bản nháp'});
        const form = document.getElementById('modalForm');
        if (bundle) form.elements.code.readOnly = true;
        const itemsRoot = form.querySelector('#kpiBundleItems');
        const updateWeight = () => { const total=Array.from(itemsRoot.querySelectorAll('[name*="[weight]"]')).reduce((sum,input)=>sum+Number(input.value||0),0); form.querySelector('#kpiBundleWeight').textContent=`Tổng trọng số: ${numberValue(total)}%${Math.abs(total-100)<0.001?' · Hợp lệ':' · Cần điều chỉnh về 100%'}`; };
        const reindex = () => Array.from(itemsRoot.querySelectorAll('[data-bundle-item]')).forEach((row,index)=>row.querySelectorAll('[name^="items["]').forEach(input=>input.name=input.name.replace(/^items\[\d+\]/,`items[${index}]`)));
        form.querySelector('[data-add-bundle-item]').addEventListener('click',()=>{itemsRoot.insertAdjacentHTML('beforeend',bundleItemRow({},itemsRoot.children.length));updateWeight();});
        itemsRoot.addEventListener('click',event=>{const button=event.target.closest('[data-remove-bundle-item]');if(!button)return;if(itemsRoot.children.length===1)return VKModal.toast('Bộ KPI phải có ít nhất một chỉ tiêu.','warning');button.closest('[data-bundle-item]').remove();reindex();updateWeight();});
        itemsRoot.addEventListener('input',updateWeight); updateWeight();
    }

    async function publishBundle(bundle) {
        if (!bundle || !confirm(`Phát hành ${bundle.code} v${bundle.version}? Sau khi phát hành phải tạo phiên bản mới để sửa.`)) return;
        try { await VKApi.request(`/kpi/bundles/${bundle.id}/publish`,{method:'POST'}); VKModal.toast('Đã phát hành bộ KPI.'); await loadKpiSettings(); }
        catch(error){ VKModal.toast(error.message,'danger'); }
    }

    async function duplicateBundle(bundle) {
        if (!bundle) return;
        try { await VKApi.request(`/kpi/bundles/${bundle.id}/duplicate`,{method:'POST'}); VKModal.toast('Đã tạo phiên bản nháp mới.'); await loadKpiSettings(); }
        catch(error){ VKModal.toast(error.message,'danger'); }
    }

    function openBundleApplyModal(bundle) {
        if (!bundle) return;
        const types=[{value:'department',label:'Phòng ban'},{value:'position',label:'Chức vụ'},{value:'user',label:'Nhân viên cụ thể'},{value:'company',label:'Toàn công ty'}];
        const now=new Date(),date=`${now.getFullYear()}-${String(now.getMonth()+1).padStart(2,'0')}-01`;
        VKModal.open(`Giao ${bundle.code} · v${bundle.version}`,`<div class="form-grid two">${VKModal.select('audience_type','Giao cho',types,'department')}<div id="bundleAudienceField"></div>${VKModal.select('period_type','Kỳ theo dõi',[{value:'month',label:'Tháng'},{value:'quarter',label:'Quý'},{value:'year',label:'Năm'}],'month')}${VKModal.field('date','Ngày thuộc kỳ','date',date)}${VKModal.select('status','Trạng thái',[{value:'active',label:'Giao và theo dõi ngay'},{value:'draft',label:'Lưu nháp'}],'active')}<label class="full" style="display:flex;align-items:center;gap:10px"><input type="checkbox" name="recurring" value="1" checked> Tự tạo bộ KPI cho các kỳ tiếp theo</label></div><div id="bundleAudiencePreview" class="form-note" aria-live="polite"></div><p class="form-note">Hệ thống tạo đồng thời ${bundle.items?.length || 0} chỉ tiêu. Nếu có trùng hoặc kết quả đã chốt, toàn bộ thao tác sẽ dừng và không tạo dở dang. Kế hoạch tự động chỉ chạy khi đúng phiên bản bộ KPI này còn đang áp dụng.</p>`,async form=>{
            const payload=Object.fromEntries(new FormData(form));payload.audience_id=Number(payload.audience_id||0);payload.recurring=payload.recurring==='1';
            const result=await VKApi.request(`/kpi/bundles/${bundle.id}/apply`,{method:'POST',body:JSON.stringify(payload)});
            VKModal.close();VKModal.toast(`Đã giao ${result.meta.assignments} chỉ tiêu cho ${result.meta.employees} nhân viên.`);document.dispatchEvent(new CustomEvent('vk:kpi:changed'));await loadKpiSettings();
        },{className:'wide-modal',submitText:'Giao bộ KPI'});
        const form=document.getElementById('modalForm'),preview=form.querySelector('#bundleAudiencePreview');let previewVersion=0;
        const previewAudience=async()=>{const current=++previewVersion;if(form.elements.audience_type.value!=='company'&&!form.elements.audience_id?.value){preview.textContent='Chọn người hoặc nhóm để xem trước.';return;}preview.textContent='Đang kiểm tra người nhận…';try{const result=await VKApi.request('/kpi/assignments/preview',{method:'POST',body:JSON.stringify({audience_type:form.elements.audience_type.value,audience_id:Number(form.elements.audience_id?.value||0)})});if(current!==previewVersion||document.getElementById('modalForm')!==form)return;preview.textContent=result.meta.total?`${result.meta.total} nhân viên hoạt động sẽ nhận ${bundle.items.length} chỉ tiêu.`:'Nhóm chưa có nhân viên hoạt động; chưa thể giao bộ KPI.';}catch(error){if(current===previewVersion)preview.textContent=error.message;}};
        const renderAudience=()=>{const type=form.elements.audience_type.value,pool=data.assignmentMeta[({department:'departments',position:'positions',user:'users'})[type]]||[];form.querySelector('#bundleAudienceField').innerHTML=type==='company'?'<p class="form-note">Áp dụng cho toàn bộ tài khoản hoạt động.</p>':VKModal.select('audience_id','Người / nhóm',[{value:'',label:'Chọn người / nhóm'},...pool.map(item=>({value:item.id,label:item.name}))],'');if(form.elements.audience_id)form.elements.audience_id.addEventListener('change',previewAudience);previewAudience();};
        form.elements.audience_type.addEventListener('change',renderAudience);renderAudience();
    }

    function numberValue(value) { return new Intl.NumberFormat('vi-VN',{maximumFractionDigits:2}).format(Number(value||0)); }

    function renderAssignmentPanel() {
        const esc = VKTable.escapeHtml;
        const seenGroups = new Set();
        const rows = VKTable.renderTable([
            {label:'Mã / chỉ tiêu', render:row => `<span style="color:#ec008c">${esc(row.definition?.code || '')}</span><br>${esc(row.definition?.name || '')}`},
            {label:'Phân cho', render:row => esc(audienceLabel(row))},
            {label:'Nguồn tự động', render:row => esc(data.assignmentMeta.metrics?.[row.metric]?.label || row.metric)},
            {label:'Kỳ', render:row => `${esc(dateInputValue(row.period_start))} → ${esc(dateInputValue(row.period_end))}`},
            {label:'Mục tiêu / trọng số', render:row => `${esc(row.target_value)} / ${esc(row.weight)}`},
            {label:'Trạng thái', render:row => esc(({active:'Đang theo dõi',draft:'Nháp',inactive:'Tạm ngưng',locked:'Đã chốt'})[row.status] || row.status)},
            {label:'', render:row => {
                if (row.locked_at) return '<span>Đã chốt kỳ</span>';
                if (!row.kpi_bundle_id) return `<button class="btn secondary small" data-kpi-assignment-edit="${row.id}">Sửa</button> ${row.status === 'active' ? `<button class="btn secondary small" data-kpi-assignment-lock="${row.id}">Chốt chỉ tiêu</button>` : ''}`;
                const key = assignmentGroupKey(row);
                if (seenGroups.has(key)) return '<span class="form-note">Theo bộ KPI</span>';
                seenGroups.add(key);
                return row.status === 'active' ? `<button class="btn primary small" data-kpi-period-close="${row.id}">Chốt cả bộ</button>` : '<span class="form-note">Bộ KPI chưa kích hoạt</span>';
            }},
        ], data.assignments || [], 'Chưa phân KPI. Tạo chỉ tiêu rồi phân cho nhân viên hoặc nhóm.');
        const users = data.assignmentMeta.users || [];
        const readiness = `<p class="form-note">Dữ liệu nhân sự: ${users.length} tài khoản hoạt động · ${users.filter(user => !user.department_id).length} chưa gắn phòng ban · ${users.filter(user => !user.position_id).length} chưa gắn chức vụ. <a href="/users">Cập nhật nhân sự</a> · <a href="/organization">Phòng ban và chức vụ</a></p>`;
        return `<section class="module-panel" style="margin-bottom:20px"><div class="panel-head"><div><h2>Phân KPI tự động</h2><span>Ưu tiên cá nhân → chức vụ → phòng ban → toàn công ty.</span></div><button class="btn primary small" data-kpi-assignment-create>Phân KPI</button></div>${readiness}${rows}<p class="form-note">Mục tiêu áp dụng cho từng nhân viên trong nhóm. Kết quả tính tự động từ nguồn đã chọn. Kỳ tháng/quý/năm theo dõi riêng, không cộng gộp.</p></section>`;
    }

    function assignmentGroupKey(row) {
        return [row.kpi_bundle_id, row.audience_type, row.audience_id, row.period_type, dateInputValue(row.period_start)].join(':');
    }

    function renderClosurePanel() {
        const esc = VKTable.escapeHtml;
        const rows = VKTable.renderTable([
            {label:'Bộ KPI',render:row=>`<span style="color:#ec008c">${esc(row.bundle?.code || '')}</span> · v${esc(row.bundle?.version || '')}<br><strong>${esc(row.bundle?.name || '')}</strong>`},
            {label:'Kỳ',render:row=>`${esc(dateInputValue(row.period_start))} → ${esc(dateInputValue(row.period_end))}<br><small>Lần chốt ${esc(row.revision)}</small>`},
            {label:'Xác nhận',render:row=>{
                const acknowledgements=row.acknowledgements || [], pending=acknowledgements.filter(item=>item.status==='pending').length, disputed=acknowledgements.filter(item=>item.status==='disputed').length;
                return `${acknowledgements.length-pending-disputed}/${acknowledgements.length} hoàn tất${disputed ? `<br><strong style="color:#dc2626">${disputed} phản hồi cần xử lý</strong>` : ''}`;
            }},
            {label:'Trạng thái',render:row=>row.status === 'closed' ? '<span class="badge active">Đã chốt cuối</span>' : row.status === 'employee_review' ? '<span class="badge pending">Chờ nhân viên xác nhận</span>' : '<span class="badge inactive">Đã mở lại</span>'},
            {label:'Thao tác',render:row=>{
                if(row.status==='reopened') return `<span class="form-note">${esc(row.reopen_reason || 'Đã mở lại')}</span>`;
                const disputed=(row.acknowledgements||[]).filter(item=>item.status==='disputed');
                const pending=(row.acknowledgements||[]).filter(item=>item.status==='pending').length;
                const resolve=disputed.map(item=>`<button class="btn secondary small" data-kpi-ack-resolve="${item.id}">Xử lý: ${esc(item.user?.name || '')}</button>`).join(' ');
                const finalize=row.status==='employee_review'&&!pending&&!disputed.length?`<button class="btn primary small" data-kpi-period-finalize="${row.id}">Chốt cuối</button>`:'';
                return `${resolve} ${finalize} <button class="btn secondary small" data-kpi-period-reopen="${row.id}">Mở lại</button>`;
            }},
        ], data.closures || [], 'Chưa có kỳ KPI nào được chốt theo bộ.');
        return `<details class="module-panel" style="margin-bottom:20px"><summary style="padding:18px;cursor:pointer"><strong>Lịch sử chốt kỳ KPI</strong> · ${(data.closures || []).length} lần chốt</summary>${rows}<p class="form-note">Mỗi lần chốt lưu nguyên danh sách nhân sự và kết quả. Mở lại không xóa ảnh chụp lịch sử; lần chốt tiếp theo tạo phiên bản mới.</p></details>`;
    }

    function audienceLabel(row) {
        const labels = {company:'Toàn công ty',department:'Phòng ban',position:'Chức vụ',user:'Nhân viên'};
        const pool = ({department:'departments',position:'positions',user:'users'})[row.audience_type];
        const name = pool ? data.assignmentMeta?.[pool]?.find(item => Number(item.id) === Number(row.audience_id))?.name : '';
        return labels[row.audience_type] + (pool ? ': ' + (name || '#' + row.audience_id) : '');
    }

    function openDefinitionModal(row = null) {
        if (!canConfigure()) return;
        readData();
        const isEdit = Boolean(row?.id);
        VKModal.open(isEdit ? 'Sửa chỉ tiêu KPI' : 'Tạo chỉ tiêu KPI', `
            <div class="form-grid two">
                ${VKModal.field('name', 'Tên chỉ tiêu', 'text', row?.name || '')}
                ${VKModal.field('source_type', 'Nhóm nghiệp vụ (mô tả)', 'text', row?.source_type || '')}
                ${VKModal.field('formula', 'Mô tả cách đo', 'text', row?.formula || '')}
                ${VKModal.field('unit', 'Đơn vị', 'text', row?.unit || '')}
                ${VKModal.field('weight', 'Trọng số', 'number', row?.weight || '1')}
                ${VKModal.select('target_direction', 'Hướng tính', [
                    { value: 'increase', label: 'Tăng là tốt' },
                    { value: 'decrease', label: 'Giảm là tốt' },
                ], row?.target_direction || 'increase')}
                ${VKModal.select('status', 'Trạng thái', [
                    { value: 'active', label: 'Đang dùng' },
                    { value: 'inactive', label: 'Tạm ngưng' },
                ], row?.status || 'active')}
            </div>
            <p class="form-note">Danh mục đặt tên và mô tả chỉ tiêu. Dùng “Phân KPI” để chọn nguồn tự động, mục tiêu và trọng số thực áp dụng cho nhân viên. Mô tả ở đây không được chạy như công thức hoặc mã lệnh.</p>
        `, async (form) => {
            const payload = Object.fromEntries(new FormData(form));
            payload.weight = Number(payload.weight || 0);
            await VKApi.request(isEdit ? `/kpi/definitions/${row.id}` : '/kpi/definitions', {
                method: isEdit ? 'PUT' : 'POST',
                body: JSON.stringify(payload),
            });
            VKModal.toast(isEdit ? 'Đã cập nhật chỉ tiêu KPI.' : 'Đã tạo chỉ tiêu KPI.');
            VKModal.close();
            await loadKpiSettings();
        }, { className: 'wide-modal', submitText: isEdit ? 'Lưu thay đổi' : 'Tạo chỉ tiêu' });
    }

    function openTargetModal(row = null, selectedDefinitionId = '') {
        if (!canConfigure()) return;
        readData();
        const isEdit = Boolean(row?.id);
        if (!data.activeDefinitions.length) {
            VKModal.toast('Chưa có chỉ tiêu KPI đang dùng để gán mục tiêu.', 'warning');
            return;
        }

        const now = new Date();
        const periodStart = dateInputValue(row?.period_start) || new Date(now.getFullYear(), now.getMonth(), 1).toISOString().slice(0, 10);
        const periodEnd = dateInputValue(row?.period_end) || new Date(now.getFullYear(), now.getMonth() + 1, 0).toISOString().slice(0, 10);
        const definitionOptions = data.activeDefinitions.map(item => ({
            value: item.id,
            label: `${item.code} - ${item.name}`,
        }));

        VKModal.open(isEdit ? 'Sửa mục tiêu KPI' : 'Thêm mục tiêu KPI', `
            <div class="form-grid two">
                ${VKModal.select('kpi_definition_id', 'Chỉ tiêu', definitionOptions, row?.kpi_definition_id || selectedDefinitionId || definitionOptions[0]?.value)}
                ${VKModal.select('period_type', 'Kỳ tính', [
                    { value: 'month', label: 'Tháng' },
                    { value: 'quarter', label: 'Quý' },
                    { value: 'year', label: 'Năm' },
                    { value: 'week', label: 'Tuần' },
                    { value: 'day', label: 'Ngày' },
                ], row?.period_type || 'month')}
                ${VKModal.field('period_start', 'Từ ngày', 'date', periodStart)}
                ${VKModal.field('period_end', 'Đến ngày', 'date', periodEnd)}
                ${VKModal.field('target_value', 'Mục tiêu', 'number', row?.target_value || '0')}
                ${VKModal.field('actual_value', 'Thực tế', 'number', row?.actual_value || '0')}
                ${VKModal.field('score', 'Điểm', 'number', row?.score || '0')}
                ${VKModal.select('status', 'Trạng thái', [
                    { value: 'draft', label: 'Nháp' },
                    { value: 'active', label: 'Đang theo dõi' },
                ], row?.status === 'locked' ? 'active' : (row?.status || 'active'))}
            </div>
            <p class="form-note">Mục tiêu là số cần đạt trong kỳ. “Thực tế” và “Điểm” nhập tại đây có thể được hệ thống tính lại từ dữ liệu vận hành khi chạy Tính lại KPI; hãy kiểm tra trước khi khóa.</p>
            ${row?.locked_at ? '<p class="form-note danger">Kỳ này đã khóa, hệ thống không cho sửa mục tiêu.</p>' : ''}
        `, async (form) => {
            if (row?.locked_at) {
                VKModal.toast('Kỳ KPI đã khóa, không thể sửa mục tiêu.', 'warning');
                return;
            }

            const payload = Object.fromEntries(new FormData(form));
            payload.kpi_definition_id = Number(payload.kpi_definition_id);
            payload.target_value = Number(payload.target_value || 0);
            payload.actual_value = Number(payload.actual_value || 0);
            payload.score = Number(payload.score || 0);
            await VKApi.request(isEdit ? `/kpi/targets/${row.id}` : '/kpi/targets', {
                method: isEdit ? 'PUT' : 'POST',
                body: JSON.stringify(payload),
            });
            VKModal.toast(isEdit ? 'Đã cập nhật mục tiêu KPI.' : 'Đã thêm mục tiêu KPI.');
            VKModal.close();
            await loadKpiSettings();
        }, { className: 'wide-modal', submitText: isEdit ? 'Lưu mục tiêu' : 'Thêm mục tiêu' });
    }

    async function lockKpiTarget(id) {
        if (!canConfigure()) return;
        if (!confirm('Khóa kỳ KPI này? Sau khi khóa, mục tiêu không thể sửa nữa.')) return;

        await VKApi.request(`/kpi/targets/${id}/lock`, { method: 'POST' });
        VKModal.toast('Đã khóa kỳ KPI.');
        VKModal.close();
        await loadKpiSettings();
    }

    function findDefinition(id) {
        readData();
        return data.definitions.find(item => String(item.id) === String(id)) || null;
    }

    function findTarget(id) {
        readData();
        return data.targets.find(item => String(item.id) === String(id)) || null;
    }

    function openAssignmentModal(row = null) {
        const esc = VKTable.escapeHtml;
        if (!data.activeDefinitions.length) return VKModal.toast('Hãy tạo chỉ tiêu đang dùng trước.', 'warning');
        const metrics = Object.entries(data.assignmentMeta.metrics || {}).map(([value, item]) => ({value,label:item.label}));
        const selectedDefinition = data.activeDefinitions.find(item=>Number(item.id)===Number(row?.kpi_definition_id)) || data.activeDefinitions.find(item=>item.code==='KPI-WORKFLOW') || data.activeDefinitions[0];
        const now = new Date();
        const date = dateInputValue(row?.period_start) || `${now.getFullYear()}-${String(now.getMonth()+1).padStart(2,'0')}-01`;
        const types = [{value:'user',label:'Nhân viên cụ thể'},{value:'department',label:'Mỗi nhân viên trong phòng ban'},{value:'position',label:'Mỗi nhân viên có chức vụ'},{value:'company',label:'Mỗi nhân viên toàn công ty'}];
        VKModal.open(row ? 'Sửa phân KPI' : 'Phân KPI tự động', `<div class="form-grid two">
            ${VKModal.select('kpi_definition_id','Mã chỉ tiêu',data.activeDefinitions.map(item=>({value:item.id,label:item.code})),selectedDefinition.id)}
            <div class="field"><label for="kpi_selected_definition_name">Tên chỉ tiêu</label><input id="kpi_selected_definition_name" readonly value="${esc(selectedDefinition.name)}" style="background:#f7f9fd"></div>
            ${VKModal.select('metric','Nguồn số liệu tự động',metrics,row?.metric || metrics[0]?.value)}
            ${VKModal.select('audience_type','Phân cho',types,row?.audience_type || 'user')}
            <div id="kpiAudienceField"></div>
            <div class="full"><div id="kpiAudiencePreview" aria-live="polite"></div></div>
            ${VKModal.select('period_type','Kỳ theo dõi',[{value:'month',label:'Tháng'},{value:'quarter',label:'Quý'},{value:'year',label:'Năm'}],row?.period_type || 'month')}
            ${VKModal.field('date','Ngày thuộc kỳ (tự lấy đầu/cuối kỳ)','date',date)}
            ${VKModal.field('target_value','Mục tiêu mỗi nhân viên','number',row?.target_value ?? 1)}
            ${VKModal.field('weight','Trọng số','number',row?.weight ?? 1)}
            ${VKModal.select('direction','Hướng tính',[{value:'increase',label:'Tăng là tốt'},{value:'decrease',label:'Giảm là tốt'}],row?.direction || 'increase')}
            ${VKModal.select('status','Trạng thái',[{value:'draft',label:'Nháp (chưa giao)'},{value:'active',label:'Đang theo dõi'},{value:'inactive',label:'Tạm ngưng'}],row?.status || 'active')}
            </div><p class="form-note" id="kpiMetricDescription"></p><p class="form-note">Điểm chỉ tiêu tối đa 100. Tăng: thực tế / mục tiêu × 100; giảm: mục tiêu / thực tế × 100 (thực tế ≤ mục tiêu đạt 100). Chốt kỳ sau ngày kết thúc; kết quả lưu cố định. Không tự quy đổi tiền thưởng.</p>`, async form => {
                const payload = Object.fromEntries(new FormData(form));
                delete payload.audience_search;
                payload.audience_id = Number(payload.audience_id || 0);
                await VKApi.request(row ? '/kpi/assignments/'+row.id : '/kpi/assignments', {method:row ? 'PUT' : 'POST',body:JSON.stringify(payload)});
                VKModal.close();
                VKModal.toast('Đã phân KPI. Tài khoản thuộc nhóm sẽ tự nhận.');
                document.dispatchEvent(new CustomEvent('vk:kpi:changed'));
                await loadKpiSettings();
            }, {className:'wide-modal',submitText:'Lưu phân KPI'});
        const form = document.getElementById('modalForm');
        form.elements.kpi_definition_id.addEventListener('change', () => {
            form.querySelector('#kpi_selected_definition_name').value = data.activeDefinitions.find(item => String(item.id) === form.elements.kpi_definition_id.value)?.name || '';
        });
        let previewVersion = 0;
        const previewAudience = async () => {
            const version = ++previewVersion;
            const root = form.querySelector('#kpiAudiencePreview');
            if (form.elements.audience_type.value !== 'company' && !form.elements.audience_id?.value) {
                root.textContent = 'Chọn người hoặc nhóm để xem nhân viên sẽ nhận KPI.';
                return;
            }
            root.textContent = 'Đang kiểm tra nhân viên sẽ nhận KPI…';
            try {
                const result = await VKApi.request('/kpi/assignments/preview', {method:'POST', body:JSON.stringify({audience_type:form.elements.audience_type.value,audience_id:Number(form.elements.audience_id?.value || 0)})});
                if (version !== previewVersion || document.getElementById('modalForm') !== form) return;
                const esc = VKTable.escapeHtml;
                const members = result.data || [];
                root.innerHTML = `<p class="form-note">${members.length ? `${members.length} nhân viên hiện tại sẽ nhận KPI. Cấu hình riêng ưu tiên cao hơn có thể thay thế chỉ tiêu này.` : 'Nhóm chưa có nhân viên hoạt động. Lưu cấu hình không giao KPI cho ai ở thời điểm này.'}</p><p class="form-note">${result.meta.missing_department} chưa gắn phòng ban · ${result.meta.missing_position} chưa gắn chức vụ. Thành viên tự cập nhật theo dữ liệu nhân sự đến khi chốt kỳ.</p><div style="max-height:180px;overflow:auto">${members.map(user=>`<div>${esc(user.name)} · ${esc(user.department?.name || 'Chưa có phòng ban')} · ${esc(user.position?.name || 'Chưa có chức vụ')}</div>`).join('')}</div>`;
            } catch(error) {
                if (version === previewVersion && document.getElementById('modalForm') === form) root.textContent = error.message || 'Không thể kiểm tra nhóm nhận KPI.';
            }
        };
        const renderAudience = () => {
            const type = form.elements.audience_type.value;
            const pool = data.assignmentMeta[({user:'users',department:'departments',position:'positions'})[type]] || [];
            const field = form.querySelector('#kpiAudienceField');
            field.innerHTML = type === 'company' ? '<p class="form-note">Tự áp dụng cho mỗi tài khoản đang hoạt động trong doanh nghiệp.</p>' : `${VKModal.field('audience_search','Tìm người / nhóm','search','')}${VKModal.select('audience_id','Người / nhóm',[{value:'',label:'Chọn người / nhóm'},...pool.map(item=>({value:item.id,label:item.name}))],row?.audience_type === type ? row.audience_id : '')}`;
            if (type !== 'company') {
                form.elements.audience_id.addEventListener('change',previewAudience);
                const select = form.elements.audience_id;
                const options = Array.from(select.options);
                form.elements.audience_search.addEventListener('input',event=>{
                    const term = event.target.value.trim().toLocaleLowerCase('vi');
                    select.replaceChildren(...options.filter(option=>!option.value || option.selected || option.textContent.toLocaleLowerCase('vi').includes(term)));
                });
            }
            previewAudience();
        };
        const describe = () => {document.getElementById('kpiMetricDescription').textContent = data.assignmentMeta.metrics[form.elements.metric.value]?.description || '';};
        form.elements.audience_type.addEventListener('change',renderAudience);
        form.elements.metric.addEventListener('change',describe);
        renderAudience(); describe();
    }

    async function lockAssignment(id) {
        if (!confirm('Chốt kết quả KPI của nhóm này? Sau khi chốt không thể sửa chỉ tiêu hoặc kết quả.')) return;
        try {
            await VKApi.request('/kpi/assignments/'+id+'/lock',{method:'POST'});
            VKModal.toast('Đã chốt kết quả KPI.');
            document.dispatchEvent(new CustomEvent('vk:kpi:changed'));
            await loadKpiSettings();
        } catch(error) { VKModal.toast(error.message,'danger'); }
    }

    async function closePeriod(id) {
        if (!confirm('Chốt toàn bộ chỉ tiêu của bộ KPI trong kỳ? Hệ thống sẽ dừng nếu còn điều chỉnh chờ duyệt.')) return;
        try {
            await VKApi.request('/kpi/period-closures/close', {method:'POST', body:JSON.stringify({assignment_id:Number(id)})});
            VKModal.toast('Đã chốt toàn bộ bộ KPI trong kỳ.');
            document.dispatchEvent(new CustomEvent('vk:kpi:changed'));
            await loadKpiSettings();
        } catch(error) { VKModal.toast(error.message, 'danger'); }
    }

    function openReopenModal(id) {
        VKModal.open('Mở lại kỳ KPI', `<div class="field"><label for="reason">Lý do mở lại</label><textarea id="reason" name="reason" rows="5" minlength="10" maxlength="1000" required placeholder="Nêu rõ chứng từ, sai lệch hoặc quyết định cần điều chỉnh..."></textarea></div><p class="form-note danger">Kết quả hiện tại vẫn được giữ trong lịch sử kiểm toán. Sau khi sửa xong, cần chốt lại để tạo phiên bản mới.</p>`, async form => {
            await VKApi.request('/kpi/period-closures/'+id+'/reopen', {method:'POST', body:JSON.stringify({reason:form.elements.reason.value.trim()})});
            VKModal.close();
            VKModal.toast('Đã mở lại kỳ KPI và lưu lý do kiểm toán.');
            document.dispatchEvent(new CustomEvent('vk:kpi:changed'));
            await loadKpiSettings();
        }, {submitText:'Xác nhận mở lại'});
    }

    async function finalizePeriod(id) {
        try {
            await VKApi.request('/kpi/period-closures/'+id+'/finalize', {method:'POST'});
            VKModal.toast('Đã chốt cuối kỳ KPI sau khi hoàn tất xác nhận.');
            document.dispatchEvent(new CustomEvent('vk:kpi:changed'));
            await loadKpiSettings();
        } catch(error) { VKModal.toast(error.message, 'danger'); }
    }

    function openResolveModal(id) {
        const closure=(data.closures||[]).find(row=>(row.acknowledgements||[]).some(item=>String(item.id)===String(id)));
        const ack=closure?.acknowledgements?.find(item=>String(item.id)===String(id));
        VKModal.open('Xử lý phản hồi KPI', `<p><strong>${VKTable.escapeHtml(ack?.user?.name || 'Nhân viên')}</strong></p><p class="form-note">Phản hồi: ${VKTable.escapeHtml(ack?.employee_note || '')}</p><div class="field"><label for="note">Kết quả xử lý</label><textarea id="note" name="note" rows="5" minlength="10" maxlength="2000" required placeholder="Nêu chứng từ đã kiểm tra và cách xử lý..."></textarea></div>`, async form=>{
            await VKApi.request('/kpi/period-acknowledgements/'+id+'/resolve',{method:'POST',body:JSON.stringify({note:form.elements.note.value.trim()})});
            VKModal.close();VKModal.toast('Đã xử lý phản hồi KPI.');await loadKpiSettings();
        },{submitText:'Xác nhận đã xử lý'});
    }

    function dateInputValue(value) {
        if (!value) return '';
        return String(value).slice(0, 10);
    }
})();
