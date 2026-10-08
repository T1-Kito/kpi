(function () {
let procurementApproval = {data:{enabled:false,rules:[]},users:[]};
let approvalRuleSequence = 0;
let salesQuotationApproval = null;
let workAssignment = null;
let operationalApproval = null;
document.addEventListener('vk:ready', () => loadSettings());
document.addEventListener('vk:settings-menu', () => toggleSettingsLauncher(true));

document.addEventListener('change', (event) => {
    if (!document.getElementById('settingsRoot')) return;

    if (event.target.matches('[data-logo-input]')) {
        previewLogo(event.target.files?.[0]);
    }
    if (event.target.matches('[data-sidebar-icon-upload]')) {
        uploadSidebarIcon(event.target);
    }
    if (event.target.matches('[data-sidebar-icon-select]')) {
        previewSidebarIcons();
    }
});

document.addEventListener('click', (event) => {
    if (!document.getElementById('settingsRoot')) return;
    const sectionButton = event.target.closest('[data-settings-nav]');
    if (event.target.closest('[data-save-sales-approval]')) saveSalesQuotationApproval();
    if (event.target.closest('[data-save-work-assignment]')) saveWorkAssignment();
    const addPerson = event.target.closest('[data-add-approval-person]');
    if (addPerson) {
        const box = addPerson.closest('.approval-setting-rule').querySelector('[data-approval-people]');
        if (box.children.length >= 50) return VKModal.toast('Mỗi luồng hỗ trợ tối đa 50 người duyệt.', 'warning');
        box.insertAdjacentHTML('beforeend', approvalPerson(box.dataset.approvalPeople, '', box.children.length));
        renumberApprovalPeople(box);
    }
    if (event.target.closest('[data-remove-approval-person]')) {
        const row = event.target.closest('[data-person-row]');
        const box = row?.parentElement;
        row?.remove();
        if (box) renumberApprovalPeople(box);
    }
    const saveOperational = event.target.closest('[data-save-operational-approval]');
    if (saveOperational) saveOperationalApproval(saveOperational);
    if (sectionButton) { showSettingsSection(sectionButton.dataset.settingsNav, true); toggleSettingsLauncher(false); return; }
    if (event.target.closest('[data-settings-launcher-open]')) { toggleSettingsLauncher(true); return; }
    if (event.target.closest('[data-settings-launcher-close]') || event.target.matches('[data-settings-launcher-layer]')) { toggleSettingsLauncher(false); return; }

    const createButton = event.target.closest('[data-create-sla-policy]');
    if (event.target.closest('[data-add-approval-rule]')) document.querySelector('[data-approval-rules]').insertAdjacentHTML('beforeend', renderApprovalRule({minimum_amount:'',approvers:[]}));
    if (event.target.closest('[data-remove-approval-rule]')) event.target.closest('.approval-setting-rule')?.remove();
    if (event.target.closest('[data-save-procurement-approval]')) saveProcurementApproval();
    const editButton = event.target.closest('[data-edit-sla-policy]');

    if (event.target.matches('[data-save-logo]')) {
        uploadLogo();
    }
    if (event.target.matches('[data-save-sidebar-icons]')) {
        saveSidebarIconSettings();
    }
    if (event.target.matches('[data-reset-sidebar-icons]')) {
        resetSidebarIconSettings();
    }
    if (createButton) {
        openSlaPolicyModal();
    }
    if (editButton) {
        openSlaPolicyModal(Number(editButton.dataset.editSlaPolicy));
    }
});

async function loadSettings(options = {}) {
    const root = document.getElementById('settingsRoot');
    if (!root) return;
    if (!root.querySelector('[data-settings-launcher-layer]')) {
        procurementApproval = null;
        root.innerHTML = renderSettings({me:{},slaPolicies:{data:[]}});
        showSettingsSection(new URLSearchParams(location.search).get('section') || 'company');
        if (!new URLSearchParams(location.search).has('section')) toggleSettingsLauncher(true);
    }

    const fallback = { data: [], meta: { total: 0 } };
    const [me, slaPolicies, approval, salesApproval, assignment, operational] = await Promise.all([
        safeRequest('/me'),
        safeRequest('/sla-policies?page_size=100'),
        safeRequest('/tenant/procurement-approval'),
        safeRequest('/tenant/sales-quotation-approval'),
        safeRequest('/tenant/work-assignment'),
        safeRequest('/tenant/operational-approval'),
    ]);
    if (document.getElementById('settingsRoot') !== root) return;
    procurementApproval = approval || null;
    salesQuotationApproval = salesApproval || null;
    workAssignment = assignment || null;
    operationalApproval = operational || null;
    root.innerHTML = renderSettings({
        me: me?.data || {},
        slaPolicies: slaPolicies || fallback,
    });
    showSettingsSection(new URLSearchParams(location.search).get('section') || 'company');
    if (!new URLSearchParams(location.search).has('section')) toggleSettingsLauncher(true);
}

function settingsIcon(key) {
    const paths = {
        company:'<path d="M5 21V5l7-3 7 3v16M3 21h18M9 7h1m4 0h1M9 11h1m4 0h1M10 21v-6h4v6"/>',
        appearance:'<path d="M12 3a9 9 0 1 0 0 18h1a2 2 0 0 0 1-4 2 2 0 0 1 1-4h3a3 3 0 0 0 3-3c0-4-4-7-9-7Z"/><path d="M7 9h.01M10 6h.01M15 7h.01M6 14h.01"/>',
        approval:'<rect x="5" y="4" width="14" height="17" rx="2"/><path d="M9 4V2h6v2M8 13l3 3 5-6"/>',
        sla:'<circle cx="12" cy="12" r="9"/><path d="M12 6v6l4 2"/>',
        users:'<circle cx="9" cy="8" r="3"/><path d="M3 21v-3a6 6 0 0 1 12 0v3M17 5a3 3 0 0 1 0 6m1 4a5 5 0 0 1 3 5"/>',
        roles:'<path d="m12 2 9 4v6c0 5-9 10-9 10S3 17 3 12V6l9-4Z M8 12l3 3 5-6"/>',
        data:'<ellipse cx="12" cy="5" rx="8" ry="3"/><path d="M4 5v14c0 4 16 4 16 0V5M4 12c0 4 16 4 16 0"/>',
        kpi:'<path d="M3 3v18h18M7 17v-5m5 5V7m5 10v-8"/>',
        audit:'<path d="M5 3h10l4 4v14H5V3Zm10 0v5h4M8 12h8M8 16h6"/>'
    };
    return `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${paths[key] || paths.company}</svg>`;
}

function toggleSettingsLauncher(open) {
    const layer = document.querySelector('[data-settings-launcher-layer]');
    if (!layer) return;
    layer.hidden = !open;
    document.querySelector('[data-settings-launcher-open]')?.setAttribute('aria-expanded',String(open));
    if (open) layer.querySelector('[data-settings-launcher-close]')?.focus();
    else document.querySelector('[data-settings-launcher-open]')?.focus();
}

document.addEventListener('keydown', event => {
    const layer = document.querySelector('[data-settings-launcher-layer]:not([hidden])');
    if (!layer) return;
    if (event.key === 'Escape') { event.preventDefault(); toggleSettingsLauncher(false); }
    if (event.key === 'Tab') {
        const nodes = Array.from(layer.querySelectorAll('button,a[href]'));
        const first = nodes[0], last = nodes.at(-1);
        if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
        else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
    }
});

function renderApprovalRule(rule) {
    const ruleKey = ++approvalRuleSequence;
    const approvers = rule.approvers || [];
    return `<div class="approval-setting-rule"><div class="approval-rule-head"><label>Từ giá trị báo giá (VND)<input type="number" min="0" step="1000" data-approval-minimum value="${VKTable.escapeHtml(rule.minimum_amount)}" placeholder="0 cho luồng mặc định"></label><button class="btn secondary small" type="button" data-remove-approval-rule>Bỏ luồng</button></div><div data-approval-people="quotation" class="form-grid two">${(approvers.length ? approvers : ['']).map((id, index) => approvalPerson('quotation', id, index)).join('')}</div><button type="button" class="btn secondary small" data-add-approval-person>+ Thêm người duyệt</button><p class="form-note">Người cuối danh sách duyệt cuối; những người phía trước duyệt nội bộ song song.</p></div>`;
}

function approvalPerson(type, value, index) {
    const users = type === 'quotation' ? procurementApproval.users : operationalApproval?.users?.[type] || [];
    const options = [{value:'', label:'Chọn nhân sự'}, ...users.map(user => ({value:user.id, label:user.name}))];
    return `<div data-person-row style="display:flex;align-items:end;gap:8px">${VKModal.select(`approval_person_${++approvalRuleSequence}`, `Người duyệt ${index + 1}`, options, value)}<button type="button" class="btn small danger" data-remove-approval-person aria-label="Bỏ người duyệt">Bỏ</button></div>`;
}

function renumberApprovalPeople(box) {
    Array.from(box.children).forEach((row, index) => {
        const label = row.querySelector('label');
        if (label) label.textContent = `Người duyệt ${index + 1}${index === box.children.length - 1 ? ' (duyệt cuối)' : ''}`;
    });
}

function renderOperationalApproval() {
    if (!operationalApproval) return '<p class="form-note">Không tải được cấu hình duyệt đơn mua và nhập kho.</p>';
    return Object.entries({purchase_order:'Duyệt đơn mua', goods_receipt:'Duyệt nhập kho'}).map(([type, title]) => {
        const ids = operationalApproval.data?.[type]?.approvers || [];
        return `<section class="module-panel settings-section approval-setting-rule"><div class="panel-head"><div><h2>${title}</h2><span>Luồng riêng, duyệt lần lượt theo thứ tự. Chưa lưu cấu hình sẽ chưa áp dụng nhiều cấp. Tối thiểu 2 người; người cuối hoàn tất phiếu.</span></div><button class="btn primary small" data-save-operational-approval="${type}">Lưu luồng</button></div><div data-approval-people="${type}" class="form-grid two">${(ids.length ? ids : ['', '']).map((id, index) => approvalPerson(type, id, index)).join('')}</div><button class="btn secondary small" type="button" data-add-approval-person>+ Thêm người duyệt</button><p class="form-note">${type === 'goods_receipt' ? 'Chỉ cộng tồn và chuyển đơn mua sang Đã nhập kho khi đủ mọi cấp duyệt.' : 'Báo giá duyệt xong tạo đơn mua chờ duyệt; chỉ tạo phiếu nhập khi đơn mua duyệt đủ.'} Khi lưu sẽ cập nhật phiếu nháp chưa ai duyệt. Phiếu đã có lượt duyệt giữ nguyên lịch sử.</p></section>`;
    }).join('');
}

async function saveOperationalApproval(button) {
    if (button.disabled) return;
    const type = button.dataset.saveOperationalApproval;
    const ids = Array.from(button.closest('.approval-setting-rule').querySelectorAll('[data-approval-people] select'), input => Number(input.value)).filter(Boolean);
    if (ids.length < 2) return VKModal.toast('Chọn ít nhất 2 người duyệt.', 'danger');
    if (new Set(ids).size !== ids.length) return VKModal.toast('Không chọn trùng người duyệt.', 'danger');
    button.disabled = true;
    try {
        const result = await VKApi.request('/tenant/operational-approval', {method:'POST', body:JSON.stringify({type, approvers:ids})});
        operationalApproval.data[type] = {approvers:ids};
        VKModal.toast(`Đã lưu luồng và cập nhật ${result.updated} phiếu chưa duyệt.`);
    } catch (error) { VKModal.toast(error.message, 'danger'); }
    finally { button.disabled = false; }
}

function renderProcurementApproval() {
    const config = procurementApproval.data;
return `<section class="module-panel settings-section procurement-approval-settings"><div class="panel-head"><div><h2>Luồng duyệt báo giá nhà cung cấp</h2><span>Tự xác định người duyệt khi tạo báo giá, theo tổng giá trị hàng hóa.</span></div><button class="btn primary small" data-save-procurement-approval>Lưu thiết lập</button></div><p class="form-note">Duyệt một người: giữ một ô nhân sự. Duyệt nhiều người: bấm Thêm người duyệt; người cuối danh sách duyệt cuối, đủ lượt nội bộ mới đến người duyệt cuối. Luồng có ngưỡng cao nhất không vượt giá trị báo giá sẽ được áp dụng.</p><div data-approval-rules>${(config.rules.length?config.rules:[{minimum_amount:0,approvers:[]}]).map(renderApprovalRule).join('')}</div><button class="btn secondary small" data-add-approval-rule>+ Thêm luồng theo mức tiền</button><p class="form-note">Cần luồng mặc định từ 0 đồng. Chỉ hiển thị tài khoản đang hoạt động có quyền duyệt mua hàng. Khi lưu, báo giá chưa có lượt duyệt sẽ tự cập nhật theo thiết lập. Báo giá đã có lượt duyệt hoặc đã tạo đơn mua giữ nguyên lịch sử.</p></section>`;
}

async function saveProcurementApproval() {
    const button = document.querySelector('[data-save-procurement-approval]');
    if (button.disabled) return;
    button.disabled = true;
    try {
        const rules = Array.from(document.querySelectorAll('#settingsRoot [data-approval-rules] .approval-setting-rule')).map((node, ruleIndex) => {
            const values = Array.from(node.querySelectorAll('[data-approval-people] select'), input => input.value);
            if (!values.length || values.some(value => !value)) throw new Error(`Luồng ${ruleIndex + 1}: chọn đủ nhân sự hoặc bỏ ô trống.`);
            if (new Set(values).size !== values.length) throw new Error('Không chọn trùng người trong một luồng.');
            const minimum = node.querySelector('[data-approval-minimum]').value;
            if (minimum === '') throw new Error('Nhập ngưỡng tiền cho từng luồng.');
            return {minimum_amount:Number(minimum),approvers:values.filter(Boolean).map(Number)};
        });
        await VKApi.request('/tenant/procurement-approval',{method:'POST',body:JSON.stringify({enabled:true,rules})});
        VKModal.toast('Đã lưu và cập nhật luồng cho báo giá chưa có lượt duyệt.');
    } catch(error) { VKModal.toast(error.message,'danger'); }
    finally { button.disabled = false; }
}

async function safeRequest(path) {
    try {
        return await VKApi.request(path);
    } catch (error) {
        return null;
    }
}

function renderSalesQuotationApproval() {
    if (!salesQuotationApproval) return '<p class="form-note">Cần quyền quản lý người dùng để tải và thiết lập luồng duyệt.</p>';
    const config = salesQuotationApproval.data || {};
    const options = [{value:'',label:'Chọn người duyệt'},...(salesQuotationApproval.users || []).map(user => ({value:user.id,label:user.name}))];
    return `<section class="module-panel settings-section"><div class="panel-head"><div><h2>Duyệt báo giá bán hàng</h2><span>Thiết lập một lần. Báo giá gửi duyệt tự lấy người duyệt, khóa giá và nội dung.</span></div><button class="btn primary" data-save-sales-approval>Lưu thiết lập</button></div><p class="form-note">Duyệt lần lượt theo thứ tự. Người lập được duyệt nếu có quyền, được phân công và đến đúng lượt. Không đổi luồng của báo giá đã gửi duyệt.</p><div class="form-grid">${[0,1,2].map(index => VKModal.select(`sales_approver_${index}`,`Người duyệt ${index + 1}${index === 0 ? ' *' : ' (tùy chọn)'}`, options, config.approvers?.[index] || '')).join('')}${VKModal.select('sales_exception_approver','Người duyệt thêm khi vượt ngưỡng (tùy chọn)',options,config.exception_approvers?.[0] || '')}${VKModal.field('sales_minimum_margin','Biên lợi nhuận tối thiểu (%)','number',config.minimum_margin ?? 15)}${VKModal.field('sales_maximum_discount','Chiết khấu tối đa không cần duyệt thêm (%)','number',config.maximum_discount ?? 0)}${VKModal.field('sales_amount_threshold','Giá trị cần duyệt thêm (VND, bỏ trống nếu không dùng)','number',config.amount_threshold ?? '')}</div><p class="form-note">Dưới biên lợi nhuận, vượt chiết khấu hoặc đạt mức tiền sẽ thêm người duyệt ngoại lệ. Nếu chưa cấu hình, hệ thống chỉ dùng quản lý trực tiếp hợp lệ; không tự chọn Admin.</p></section>`;
}

async function saveSalesQuotationApproval() {
    const button = document.querySelector('[data-save-sales-approval]'); button.disabled = true;
    try {
        const value = name => document.getElementById(name)?.value || '';
        const approvers = [0,1,2].map(index => value(`sales_approver_${index}`)).filter(Boolean).map(Number);
        const exception = value('sales_exception_approver');
        const payload = {approvers,exception_approvers:exception ? [Number(exception)] : [],minimum_margin:Number(value('sales_minimum_margin')),maximum_discount:Number(value('sales_maximum_discount')),amount_threshold:value('sales_amount_threshold') ? Number(value('sales_amount_threshold')) : null};
        await VKApi.request('/tenant/sales-quotation-approval',{method:'POST',body:JSON.stringify(payload)});
        salesQuotationApproval.data = payload; VKModal.toast('Đã lưu luồng duyệt cho báo giá gửi duyệt sau này.');
    } catch(error) { VKModal.toast(error.message,'danger'); }
    finally { button.disabled = false; }
}

function renderWorkAssignment() {
    if (!workAssignment) return '<p class="form-note">Cần quyền quản lý người dùng để thiết lập phân công tự động. Nếu đã có quyền, vui lòng tải lại trang.</p>';
    const config = workAssignment.data || {};
    return `<section class="module-panel settings-section"><div class="panel-head"><div><h2>Tự động phân công công việc</h2><span>Thiết lập một lần · Luân phiên người nhận · Không thay người đã được chọn</span></div><button class="btn primary" data-save-work-assignment>Lưu thiết lập</button></div><label class="form-note" style="display:flex;align-items:center;gap:10px"><input type="checkbox" data-assignment-enabled ${config.enabled ? 'checked' : ''}> Bật phân công tự động cho dữ liệu tạo mới</label><div class="form-grid">${Object.entries(workAssignment.rules || {}).map(([key, rule]) => `<section class="module-panel"><div class="panel-head"><h3>${VKTable.escapeHtml(rule.label)}</h3></div><div style="padding:16px;display:grid;gap:12px">${rule.users.length ? rule.users.map(user => `<label style="display:flex;align-items:center;gap:10px"><input type="checkbox" data-assignment-pool="${key}" value="${Number(user.id)}" ${(config.pools?.[key] || []).map(Number).includes(Number(user.id)) ? 'checked' : ''}>${VKTable.escapeHtml(user.name)}</label>`).join('') : '<p class="form-note">Chưa có nhân sự đang hoạt động với đủ quyền.</p>'}</div></section>`).join('')}</div><p class="form-note">Nhóm để trống sẽ giữ việc ở trạng thái chưa phân công. Người bị khóa hoặc mất quyền sẽ tự được bỏ qua. Không tự duyệt chứng từ, xác nhận giao nhận hay ghi nhận tiền; không đổi dữ liệu cũ.</p></section>`;
}

async function saveWorkAssignment() {
    const button = document.querySelector('[data-save-work-assignment]');
    button.disabled = true;
    try {
        const pools = Object.fromEntries(Object.keys(workAssignment.rules).map(key => [key, Array.from(document.querySelectorAll(`[data-assignment-pool="${key}"]:checked`)).map(input => Number(input.value))]));
        const payload = {enabled:document.querySelector('[data-assignment-enabled]').checked,pools};
        await VKApi.request('/tenant/work-assignment', {method:'POST',body:JSON.stringify(payload)});
        workAssignment.data = payload;
        VKModal.toast('Đã lưu phân công tự động cho dữ liệu tạo mới.');
    } catch (error) { VKModal.toast(error.message, 'danger'); }
    finally { button.disabled = false; }
}

function renderSettings(data) {
    window.__slaPolicies = data.slaPolicies.data || [];
    const sections = [['company','Công ty','Thông tin doanh nghiệp'],['appearance','Giao diện','Logo và biểu tượng'],['assignment','Tự động phân công','Chia đều việc cho người có quyền'],['approval','Duyệt mua hàng','Người duyệt và mức tiền'],['salesApproval','Duyệt báo giá bán','Luồng duyệt tự động'],['sla','Thời hạn công việc','SLA và cảnh báo']];
    const panel = (title, description, body, tools = '') => `<section class="module-panel settings-section"><div class="panel-head"><div><h2>${title}</h2><span>${description}</span></div>${tools}</div>${body}</section>`;
    return `<div class="settings-launcher-workspace"><div class="settings-page-toolbar"><div><span>Thiết lập</span><strong data-settings-current-title>Thông tin công ty</strong></div><button type="button" class="btn secondary" data-settings-launcher-open aria-expanded="false" aria-controls="settingsLauncher">${settingsIcon('data')} Menu thiết lập</button></div><div class="settings-launcher-layer" data-settings-launcher-layer hidden><section class="settings-launcher" id="settingsLauncher" role="dialog" aria-modal="true" aria-labelledby="settingsLauncherTitle"><header><div><h2 id="settingsLauncherTitle">Menu thiết lập</h2><small>Chọn chức năng cần sử dụng</small></div><button type="button" data-settings-launcher-close aria-label="Đóng menu">×</button></header><div class="settings-launcher-group">Hệ thống & quy trình</div><div class="settings-launcher-grid">${sections.map(([key,title,note]) => `<button type="button" data-settings-nav="${key}" title="${note}"><span class="settings-tile-icon">${settingsIcon(key)}</span><span>${title}</span></button>`).join('')}</div><div class="settings-launcher-group">Danh mục & quản trị</div><div class="settings-launcher-grid">${[['users','Người dùng','/users'],['roles','Phân quyền','/roles'],['data','Dữ liệu nền','/sales-master-data'],['kpi','Thiết lập KPI','/kpi-settings'],['audit','Nhật ký','/audit-logs']].map(([key,title,href]) => `<a href="${href}"><span class="settings-tile-icon">${settingsIcon(key)}</span><span>${title}</span></a>`).join('')}</div><footer>Mỗi chức năng mở một màn hình riêng</footer></section></div><div class="settings-content">
        <div data-settings-section="company" hidden>${panel('Thông tin công ty','Thông tin doanh nghiệp và tài khoản đang sử dụng.',renderCompany(data.me))}</div>
        <div data-settings-section="appearance" hidden class="settings-stack">${panel('Logo hệ thống','Nhận diện thương hiệu trên phần mềm.',renderLogoSetting(data.me))}${panel('Biểu tượng menu','Tùy chỉnh biểu tượng cho từng phân hệ.',renderSidebarIconSetting())}</div>
        <div data-settings-section="approval" hidden>${procurementApproval ? renderProcurementApproval() + renderOperationalApproval() : panel('Duyệt mua hàng','Không thể tải cấu hình.', '<p class="form-note">Bạn cần quyền quản lý người dùng để thiết lập. Nếu đã có quyền, vui lòng thử lại.</p>')}</div>
        <div data-settings-section="salesApproval" hidden>${renderSalesQuotationApproval()}</div>
        <div data-settings-section="assignment" hidden>${renderWorkAssignment()}</div>
        <div data-settings-section="sla" hidden>${panel('Thời hạn xử lý công việc','Cấu hình thời hạn, nhắc việc và leo thang cho từng loại công việc.',renderSlaPolicies(data.slaPolicies.data || []),'<button class="btn primary small" data-create-sla-policy>Thêm SLA</button>')}</div>
    </div></div>`;
}

function showSettingsSection(key, updateUrl = false) {
    const root = document.getElementById('settingsRoot');
    if (!root) return;
    if (!['company','appearance','approval','salesApproval','assignment','sla'].includes(key)) key = 'company';
    const grid = root.querySelector('.settings-launcher-grid:last-of-type');
    if (grid && !grid.querySelector('[data-settings-admin-extra]')) {
        grid.insertAdjacentHTML('beforeend', [['company','Phòng ban & chức vụ','/organization'],['audit','Mẫu in','/print-templates'],['approval','Quy trình','/workflows']].map(([icon,title,href]) => `<a href="${href}" data-settings-admin-extra><span class="settings-tile-icon">${settingsIcon(icon)}</span><span>${title}</span></a>`).join(''));
    }
    const title = root.querySelector('[data-settings-current-title]');
    if (title) title.textContent = {company:'Thông tin công ty',appearance:'Giao diện',approval:'Luồng duyệt mua hàng',salesApproval:'Duyệt báo giá bán hàng',assignment:'Tự động phân công',sla:'Thời hạn công việc'}[key];
    root.querySelectorAll('[data-settings-section]').forEach(node => { node.hidden = node.dataset.settingsSection !== key; });
    root.querySelectorAll('[data-settings-nav]').forEach(button => {
        const active = button.dataset.settingsNav === key;
        button.classList.toggle('active',active);
        button.setAttribute('aria-pressed', String(active));
    });
    if (updateUrl) {
        const url = new URL(location.href);
        url.searchParams.set('section',key);
        history.replaceState(history.state,'',url.pathname+url.search);
    }
}

function renderLegacySettings(data) {
    return `
        <div class="settings-stack">
            <section class="module-panel settings-section">
                <div class="panel-head">
                    <div>
                        <h2>Thông tin công ty</h2>
                        <span>Thông tin tenant và tài khoản đang đăng nhập.</span>
                    </div>
                </div>
                ${renderCompany(data.me)}
            </section>

            <section class="module-panel settings-section">
                <div class="panel-head">
                    <div>
                        <h2>Logo hệ thống</h2>
                        <span>Logo hiển thị ở góc trái sidebar, không kèm tên hệ thống.</span>
                    </div>
                </div>
                ${renderLogoSetting(data.me)}
            </section>

            <section class="module-panel settings-section">
                <div class="panel-head">
                    <div>
                        <h2>Icon menu</h2>
                        <span>Chọn biểu tượng hiển thị ở thanh menu bên trái cho từng phân hệ.</span>
                    </div>
                </div>
                ${renderSidebarIconSetting()}
            </section>

            <section class="module-panel settings-section">
                <div class="panel-head">
                    <div>
                        <h2>Người dùng / vai trò</h2>
                        <span>Theo dõi tài khoản, vai trò và nhóm quyền đang có.</span>
                    </div>
                </div>
                ${renderUserRole(data)}
            </section>

            <section class="module-panel settings-section">
                <div class="panel-head">
                    <div>
                        <h2>Dữ liệu nền</h2>
                        <span>Các nhóm dữ liệu dùng chung cho bán hàng, mua hàng và kho.</span>
                    </div>
                </div>
                ${renderMasterData(data)}
            </section>

            <section class="module-panel settings-section">
                <div class="panel-head">
                    <div>
                        <h2>Cấu hình vận hành</h2>
                        <span>SLA, quy tắc tự động và ma trận phê duyệt theo luồng doanh nghiệp.</span>
                    </div>
                    <button class="btn primary small" type="button" data-create-sla-policy>Thêm SLA</button>
                </div>
                ${renderOperationsConfig(data)}
            </section>

            <section class="module-panel settings-section" id="audit">
                <div class="panel-head">
                    <div>
                        <h2>Nhật ký</h2>
                        <span>Các thao tác gần nhất phục vụ truy vết vận hành.</span>
                    </div>
                </div>
                <a class="btn small secondary" href="/audit-logs">Mở nhật ký hệ thống</a>
            </section>
        </div>
    `;
}

function renderCompany(me) {
    return `
        <div class="settings-two-col">
            <div class="info-list">
                ${infoRow('Mã công ty', me.tenant?.code || '-')}
                ${infoRow('Tên công ty', me.tenant?.name || '-')}
                ${infoRow('Người dùng', me.name || '-')}
                ${infoRow('Email', me.email || '-')}
                ${infoRow('Phòng ban', me.department?.name || 'Chưa gắn phòng ban')}
            </div>
            <div class="settings-note">
                <strong>Gợi ý vận hành</strong>
                <span>Thông tin công ty nên được cố định trước khi triển khai thật để đồng bộ báo cáo, phân quyền và KPI theo phòng ban.</span>
            </div>
        </div>
    `;
}

function renderLogoSetting(me) {
    const logoUrl = me.tenant?.logo_url || '';

    return `
        <div class="logo-setting">
            <div class="logo-preview">
                ${logoUrl
                    ? `<img src="${VKTable.escapeHtml(logoUrl)}" alt="Logo hiện tại" data-logo-preview>`
                    : `<span data-logo-preview>VK</span>`}
            </div>
            <div class="logo-control">
                <label class="file-control">
                    <input type="file" accept="image/png,image/jpeg,image/webp,image/svg+xml" data-logo-input>
                    <span>Chọn logo</span>
                </label>
                <button class="btn primary" type="button" data-save-logo>Lưu logo</button>
                <small>Khuyến nghị PNG/WebP nền trong suốt, dung lượng dưới 2MB.</small>
            </div>
        </div>
    `;
}

const sidebarIconTargets = [
    ['dashboard', 'Dashboard'],
    ['tasks', 'Công việc'],
    ['approval', 'Phê duyệt'],
    ['business', 'Kinh doanh'],
    ['warehouse', 'Kho vận'],
    ['purchase', 'Mua hàng'],
    ['kpi', 'KPI'],
    ['alerts', 'Cảnh báo'],
    ['admin', 'Quản trị'],
    ['print', 'Mẫu in'],
    ['workflow', 'Quy trình'],
];

const sidebarIconOptions = [
    ['home', 'Nhà'],
    ['tasks', 'Công việc'],
    ['approval', 'Phê duyệt'],
    ['business', 'Tòa nhà'],
    ['warehouse', 'Kho'],
    ['purchase', 'Giỏ hàng'],
    ['kpi', 'Biểu đồ'],
    ['alert', 'Chuông'],
    ['admin', 'Khiên'],
    ['print', 'Máy in'],
    ['workflow', 'Sơ đồ'],
];

function renderSidebarIconSetting() {
    const config = window.VKLayout?.getSidebarIconConfig?.() || { defaults: {}, current: {} };
    const customIcons = config.custom || {};

    return `
        <div class="sidebar-icon-setting">
            ${sidebarIconTargets.map(([key, label]) => {
                const value = config.current[key] || config.defaults[key] || '';
                const hasCustom = Boolean(customIcons[key]);
                return `
                    <label class="sidebar-icon-row">
                        <span class="nav-icon ${VKTable.escapeHtml(value)}" ${value === 'custom' && hasCustom ? `style="--nav-custom-icon:url('${VKTable.escapeHtml(customIcons[key])}')"` : ''} data-sidebar-icon-preview="${VKTable.escapeHtml(key)}" aria-hidden="true"></span>
                        <strong>${VKTable.escapeHtml(label)}</strong>
                        <select data-sidebar-icon-select="${VKTable.escapeHtml(key)}">
                            ${sidebarIconOptions.map(([icon, iconLabel]) => `<option value="${VKTable.escapeHtml(icon)}" ${icon === value ? 'selected' : ''}>${VKTable.escapeHtml(iconLabel)}</option>`).join('')}
                            ${hasCustom ? `<option value="custom" ${value === 'custom' ? 'selected' : ''}>Icon tải lên</option>` : ''}
                        </select>
                        <span class="sidebar-icon-upload">
                            <input type="file" accept="image/png,image/jpeg,image/webp" data-sidebar-icon-upload="${VKTable.escapeHtml(key)}">
                            <em>Tải lên</em>
                        </span>
                    </label>
                `;
            }).join('')}
        </div>
        <div class="settings-actions">
            <button class="btn primary" type="button" data-save-sidebar-icons>Lưu icon menu</button>
            <button class="btn" type="button" data-reset-sidebar-icons>Khôi phục mặc định</button>
        </div>
    `;
}

function collectSidebarIconSettings() {
    const icons = {};
    document.querySelectorAll('[data-sidebar-icon-select]').forEach((select) => {
        icons[select.dataset.sidebarIconSelect] = select.value;
    });
    return icons;
}

function previewSidebarIcons() {
    const icons = collectSidebarIconSettings();
    const customIcons = window.VKLayout?.getSidebarIconConfig?.().custom || {};
    Object.entries(icons).forEach(([key, icon]) => {
        const preview = document.querySelector(`[data-sidebar-icon-preview="${key}"]`);
        if (preview) {
            preview.className = `nav-icon ${icon}`;
            if (icon === 'custom' && customIcons[key]) {
                preview.style.setProperty('--nav-custom-icon', `url("${customIcons[key]}")`);
            } else {
                preview.style.removeProperty('--nav-custom-icon');
            }
        }
    });
    window.VKLayout?.applySidebarIcons?.(icons);
}

async function uploadSidebarIcon(input) {
    const key = input.dataset.sidebarIconUpload;
    const file = input.files?.[0];
    if (!key || !file) return;

    const form = new FormData();
    form.append('key', key);
    form.append('icon', file);

    try {
        const response = await VKApi.request('/tenant/sidebar-icons', {
            method: 'POST',
            body: form,
        });
        const uiSettings = response.data?.ui_settings || {};
        window.VKUser = {
            ...(window.VKUser || {}),
            tenant: {
                ...(window.VKUser?.tenant || {}),
                ui_settings: uiSettings,
            },
        };
        localStorage.setItem('vk.currentUser.v3', JSON.stringify(window.VKUser));
        localStorage.removeItem('vk.sidebar.icons.v1');
        window.VKLayout?.applySidebarIcons?.(uiSettings.sidebar_icons || {});
        loadSettings({ source: 'pjax' });
        VKModal.toast('Đã tải icon menu lên.');
    } catch (error) {
        VKModal.toast(error.message || 'Không thể tải icon lên.', 'danger');
    } finally {
        input.value = '';
    }
}

async function saveSidebarIconSettings() {
    const icons = collectSidebarIconSettings();
    window.VKLayout?.saveSidebarIcons?.(icons);

    try {
        const response = await VKApi.request('/tenant/ui-settings', {
            method: 'POST',
            body: JSON.stringify({ sidebar_icons: icons }),
        });
        const uiSettings = response.data?.ui_settings || { sidebar_icons: icons };
        window.VKUser = {
            ...(window.VKUser || {}),
            tenant: {
                ...(window.VKUser?.tenant || {}),
                ui_settings: uiSettings,
            },
        };
        localStorage.setItem('vk.currentUser.v3', JSON.stringify(window.VKUser));
        VKModal.toast('Đã cập nhật icon menu.');
    } catch (error) {
        VKModal.toast('Đã lưu tạm trên trình duyệt. Chạy migrate để lưu toàn hệ thống.', 'warning');
    }
}

async function resetSidebarIconSettings() {
    window.VKLayout?.resetSidebarIcons?.();
    try {
        const response = await VKApi.request('/tenant/ui-settings', {
            method: 'POST',
            body: JSON.stringify({ sidebar_icons: {} }),
        });
        window.VKUser = {
            ...(window.VKUser || {}),
            tenant: {
                ...(window.VKUser?.tenant || {}),
                ui_settings: response.data?.ui_settings || {},
            },
        };
        localStorage.setItem('vk.currentUser.v3', JSON.stringify(window.VKUser));
    } catch (error) {
        // Vẫn khôi phục local để admin thấy hiệu lực ngay.
    }
    loadSettings({ source: 'pjax' });
    VKModal.toast('Đã khôi phục icon mặc định.');
}

function renderUserRole(data) {
    const roles = data.me.roles || [];
    const permissions = data.me.permissions || [];

    return `
        <div class="settings-metrics">
            ${metricCard('Người dùng', data.users.meta.total || 0, 'Tài khoản đang quản lý')}
            ${metricCard('Vai trò', data.roles.meta.total || roles.length, 'Nhóm quyền truy cập')}
            ${metricCard('Quyền', permissions.length, 'Quyền của tài khoản hiện tại')}
        </div>
        <div class="access-block compact">
            <div>
                <h3>Vai trò hiện tại</h3>
                <div class="chip-list">${roles.map(role => `<span title="${VKTable.escapeHtml(role)}">${VKTable.escapeHtml(VKTable.translateRole(role))}</span>`).join('') || '<span>Chưa có vai trò</span>'}</div>
            </div>
            <div>
                <h3>Quyền tiêu biểu</h3>
                <div class="permission-list">${permissions.slice(0, 14).map(item => `<span title="${VKTable.escapeHtml(item)}">${VKTable.escapeHtml(VKTable.translatePermission(item))}</span>`).join('') || '<span>Chưa có quyền</span>'}</div>
            </div>
        </div>
    `;
}

function renderMasterData(data) {
    const rows = [
        ['Khách hàng', data.customers.meta.total, '/customers', 'Bán hàng và công nợ'],
        ['Nhà cung cấp', data.suppliers.meta.total, '/suppliers', 'Nguồn mua hàng'],
        ['Mã hàng', data.skus.meta.total, '/skus', 'Sản phẩm và giá vốn'],
        ['Kho', data.warehouses.meta.total, '/warehouses', 'Kho và vị trí lưu trữ'],
    ];

    return `
        <div class="master-grid">
            ${rows.map(([label, total, href, note]) => `
                <a class="master-card" href="${href}">
                    <span>${label}</span>
                    <strong>${VKTable.money(total)}</strong>
                    <small>${note}</small>
                </a>
            `).join('')}
        </div>
    `;
}

function renderOperationsConfig(data) {
    const policies = data.slaPolicies.data || [];
    window.__slaPolicies = policies;

    return `
        <div class="settings-ops-grid">
            <div class="settings-ops-main">
                <div class="subpanel-title">
                    <div>
                        <h3>SLA công việc</h3>
                        <span>Thời hạn tự động khi hệ thống tạo công việc theo module.</span>
                    </div>
                </div>
                ${renderSlaPolicies(policies)}
            </div>
            <div class="settings-ops-side">
                <div class="settings-note">
                    <strong>Quy tắc tự động</strong>
                    <div class="rule-list">
                        ${ruleRow('Đơn bán thiếu tồn', 'Tạo cảnh báo, yêu cầu mua nháp và task mua hàng.')}
                        ${ruleRow('Báo giá margin thấp', 'Đưa vào luồng phê duyệt trước khi chốt.')}
                        ${ruleRow('Lead được giao', 'Tạo task follow-up theo SLA bán hàng.')}
                        ${ruleRow('Task quá hạn', 'Đẩy cảnh báo cho người phụ trách và quản lý.')}
                        ${ruleRow('PO trễ giao', 'Cảnh báo mua hàng và kho để xử lý ngoại lệ.')}
                    </div>
                </div>
                <div class="settings-note settings-note-neutral">
                    <strong>Ma trận phê duyệt</strong>
                    <div class="rule-list">
                        ${ruleRow('Báo giá margin thấp', 'Trưởng phòng hoặc Giám đốc duyệt.')}
                        ${ruleRow('Yêu cầu mua', 'Mua hàng hoặc Trưởng phòng duyệt.')}
                        ${ruleRow('Đơn mua', 'Người có quyền duyệt PO xác nhận.')}
                        ${ruleRow('Ngoại lệ KPI', 'Người có quyền khóa KPI xem xét.')}
                    </div>
                </div>
            </div>
        </div>
    `;
}

function renderSlaPolicies(rows) {
    if (!rows.length) {
        return '<div class="empty-state">Chưa có cấu hình SLA.</div>';
    }

    return VKTable.renderTable([
        { label: 'Module', render: row => VKTable.escapeHtml(translateModule(row.module)) },
        { label: 'Loại công việc', render: row => `<strong>${VKTable.escapeHtml(translateTaskType(row.task_type))}</strong><span class="row-note">${VKTable.escapeHtml(row.task_type)}</span>` },
        { label: 'Ưu tiên', render: row => renderPriority(row.priority) },
        { label: 'Thời hạn', render: row => formatMinutes(row.duration_minutes) },
        { label: 'Cảnh báo trước', render: row => formatMinutes(row.warning_before_minutes) },
        { label: 'Leo thang', render: row => renderEscalation(row.escalation_rules) },
        { label: 'Thao tác', render: row => `<button class="btn small secondary" type="button" data-edit-sla-policy="${row.id}">Sửa</button>` },
    ], rows, 'Chưa có cấu hình SLA.');
}

function openSlaPolicyModal(id = null) {
    const row = id ? (window.__slaPolicies || []).find(item => Number(item.id) === id) : null;
    const moduleOptions = ['general', 'sales', 'inventory', 'procurement', 'finance', 'kpi']
        .map(value => ({ value, label: translateModule(value) }));
    const priorityOptions = ['low', 'normal', 'high', 'urgent']
        .map(value => ({ value, label: translatePriority(value) }));

    VKModal.open(id ? 'Sửa SLA công việc' : 'Thêm SLA công việc', `
        ${VKModal.select('module', 'Module', moduleOptions, row?.module || 'general')}
        ${VKModal.field('task_type', 'Loại công việc', 'text', row?.task_type || '')}
        ${VKModal.select('priority', 'Mức ưu tiên', priorityOptions, row?.priority || 'normal')}
        ${VKModal.field('duration_minutes', 'Thời hạn xử lý (phút)', 'number', row?.duration_minutes || 480)}
        ${VKModal.field('warning_before_minutes', 'Cảnh báo trước hạn (phút)', 'number', row?.warning_before_minutes || 60)}
        <label class="checkbox-line">
            <input type="checkbox" name="manager_escalation" value="1" ${row?.escalation_rules?.manager ? 'checked' : ''}>
            <span>Leo thang cho quản lý khi sắp quá hạn hoặc quá hạn</span>
        </label>
    `, async (form) => {
        const formData = Object.fromEntries(new FormData(form));
        const payload = {
            module: formData.module,
            task_type: formData.task_type,
            priority: formData.priority,
            duration_minutes: Number(formData.duration_minutes),
            warning_before_minutes: Number(formData.warning_before_minutes),
            escalation_rules: {
                manager: Boolean(formData.manager_escalation),
            },
        };

        await VKApi.request(id ? `/sla-policies/${id}` : '/sla-policies', {
            method: id ? 'PUT' : 'POST',
            body: JSON.stringify(payload),
        });
        VKApi.clearCache();
        VKModal.close();
        VKModal.toast(id ? 'Đã cập nhật SLA.' : 'Đã thêm SLA.');
        await loadSettings({ source: 'pjax' });
    });
}

function ruleRow(label, note) {
    return `<div><span>${VKTable.escapeHtml(label)}</span><strong>${VKTable.escapeHtml(note)}</strong></div>`;
}

function infoRow(label, value) {
    return `<div><span>${label}</span><strong>${VKTable.escapeHtml(value)}</strong></div>`;
}

function metricCard(label, value, note) {
    return `<section class="summary-card"><span>${label}</span><strong>${VKTable.money(value)}</strong><small>${note}</small></section>`;
}

function renderPriority(priority) {
    const style = {
        low: 'info',
        normal: 'success',
        high: 'warning',
        urgent: 'danger',
    }[priority] || 'info';

    return `<span class="badge ${style}">${VKTable.escapeHtml(translatePriority(priority))}</span>`;
}

function renderEscalation(rules) {
    if (rules?.manager) return '<span class="badge warning">Quản lý</span>';
    return '<span class="row-note">Không leo thang</span>';
}

function translateModule(value) {
    return {
        general: 'Chung',
        sales: 'Bán hàng',
        inventory: 'Kho',
        procurement: 'Mua hàng',
        finance: 'Tài chính',
        kpi: 'KPI',
    }[value] || value || '-';
}

function translatePriority(value) {
    return {
        low: 'Thấp',
        normal: 'Bình thường',
        high: 'Cao',
        urgent: 'Khẩn cấp',
    }[value] || value || '-';
}

function translateTaskType(value) {
    return {
        manual: 'Tạo thủ công',
        lead_follow_up: 'Theo dõi khách hàng tiềm năng',
        quotation_follow_up: 'Theo dõi báo giá',
        margin_approval: 'Duyệt biên lợi nhuận thấp',
        sales_order_confirmation: 'Xác nhận đơn bán',
        delivery_confirmation: 'Xác nhận giao hàng',
        inventory_check: 'Kiểm tra tồn kho',
        warehouse_issue: 'Xử lý xuất kho',
        goods_receipt: 'Xử lý nhập kho',
        purchase_request: 'Xử lý yêu cầu mua',
        purchase_order_follow_up: 'Theo dõi đơn mua',
        supplier_follow_up: 'Làm việc nhà cung cấp',
        invoice_issue: 'Xuất hóa đơn',
        payment_follow_up: 'Theo dõi thanh toán',
        receivable_follow_up: 'Nhắc công nợ',
        kpi_review: 'Rà soát KPI',
        alert_resolution: 'Xử lý cảnh báo',
    }[value] || VKTable.translateType(value || '-');
}

function formatMinutes(value) {
    const minutes = Number(value || 0);
    if (minutes <= 0) return '0 phút';
    if (minutes % 1440 === 0) return `${minutes / 1440} ngày`;
    if (minutes % 60 === 0) return `${minutes / 60} giờ`;
    return `${minutes} phút`;
}

function previewLogo(file) {
    if (!file) return;
    const preview = document.querySelector('[data-logo-preview]');
    if (!preview) return;

    const url = URL.createObjectURL(file);
    const img = document.createElement('img');
    img.src = url;
    img.alt = 'Logo xem trước';
    img.dataset.logoPreview = '';
    preview.replaceWith(img);
}

async function uploadLogo() {
    const input = document.querySelector('[data-logo-input]');
    const button = document.querySelector('[data-save-logo]');
    const file = input?.files?.[0];
    if (!file) {
        VKModal.toast('Bạn chọn file logo trước nha.');
        return;
    }

    const form = new FormData();
    form.append('logo', file);
    button.disabled = true;

    try {
        const response = await VKApi.request('/tenant/logo', {
            method: 'POST',
            body: form,
        });
        const logoUrl = response.data.logo_url;
        window.VKUser = {
            ...(window.VKUser || {}),
            tenant: {
                ...(window.VKUser?.tenant || {}),
                logo_url: logoUrl,
            },
        };
        localStorage.setItem('vk.currentUser.v3', JSON.stringify(window.VKUser));
        window.VKLayout?.hydrateLogo?.(logoUrl);
        VKModal.toast('Đã cập nhật logo hệ thống.');
    } catch (error) {
        VKModal.toast(error.message || 'Không thể cập nhật logo.');
    } finally {
        button.disabled = false;
    }
}

function renderLoading() {
    return `<div class="module-summary">${Array.from({ length: 5 }).map(() => '<section class="summary-card"><div class="skeleton"></div><div class="skeleton large"></div><div class="skeleton"></div></section>').join('')}</div>`;
}

window.loadSettings = loadSettings;
})();
