(function () {
let leadState = { rows: [], q: '', status: '', scope: 'all', page: 1, total: 0, stats: {}, detail: null, detailTab: 'overview' };
let leadSearchTimer;

document.addEventListener('vk:ready', () => loadLeads());
document.addEventListener('input', (event) => {
    if (!document.getElementById('leadsRoot')) return;
    if (event.target.matches('[data-list-search]')) {
        leadState.q = event.target.value;
        clearTimeout(leadSearchTimer);
        leadSearchTimer = setTimeout(() => { leadState.page = 1; loadLeads(); }, 280);
    }
});
document.addEventListener('change', (event) => {
    if (!document.getElementById('leadsRoot')) return;
    if (event.target.matches('[data-list-status]')) {
        leadState.status = event.target.value;
        leadState.page = 1;
        loadLeads();
    }
});
document.addEventListener('click', (event) => {
    if (!document.getElementById('leadsRoot')) return;
    if (event.target.closest('[data-lead-back]')) { leadState.detail = null; renderLeads(); return; }
    const detailTab = event.target.closest('[data-lead-tab]');
    if (detailTab) { leadState.detailTab = detailTab.dataset.leadTab; renderLeadDetail(); return; }
    const followUp = event.target.closest('[data-lead-follow-up]');
    if (followUp) { openLeadFollowUpModal(); return; }
    const taskStatus = event.target.closest('[data-lead-task-status]');
    if (taskStatus) { updateLeadTaskStatus(taskStatus.dataset.leadTaskStatus, taskStatus.dataset.taskStatus); return; }
    if (event.target.closest('[data-retry-leads]')) { loadLeads(); return; }
    if (event.target.matches('[data-create-lead]')) openLeadModal();
    const scope = event.target.closest('[data-lead-scope]');
    if (scope) { leadState.scope = scope.dataset.leadScope; leadState.page = 1; loadLeads(); return; }
    const page = event.target.closest('[data-lead-page]');
    if (page) { leadState.page = Number(page.dataset.leadPage); loadLeads(); return; }
    const assign = event.target.closest('[data-assign-lead]');
    if (assign) { event.stopPropagation(); openAssignLeadModal(assign.dataset.assignLead); return; }
    const qualify = event.target.closest('[data-qualify-lead]');
    if (qualify) { event.stopPropagation(); return openQualifyModal(qualify.dataset.qualifyLead); }
    const detail = event.target.closest('[data-lead-detail], tr[data-row-detail]');
    if (detail) openLeadDetail(detail.dataset.leadDetail || detail.dataset.rowDetail);
});

async function loadLeads() {
    const root = document.getElementById('leadsRoot');
    if (!root) return;
    try {
        const query = new URLSearchParams({ page: String(leadState.page), page_size: '20', scope: leadState.scope });
        if (leadState.q) query.set('q', leadState.q);
        if (leadState.status) query.set('status', leadState.status);
        const leads = await VKApi.request(`/leads?${query}`, { cache: false });
        leadState.rows = leads.data || [];
        leadState.total = leads.meta?.total || 0;
        leadState.stats = leads.meta?.stats || {};
        renderLeads();
    } catch (error) {
        root.innerHTML = `<section class="list-page"><div class="empty"><strong>Không tải được khách hàng tiềm năng</strong><span>${VKTable.escapeHtml(error.message || 'Vui lòng thử lại.')}</span><button class="btn small" type="button" data-retry-leads>Thử lại</button></div></section>`;
    }
}

function renderLeads() {
    leadState.detail = null;
    const rows = leadState.rows;
    const stats = leadState.stats;
    const canQueue = window.VKUser?.data_scope === 'company';
    document.getElementById('leadsRoot').innerHTML = `<div class="lead-workbench">
        <div class="lead-overview">
            ${leadMetric('Tất cả lead', stats.all || 0, 'Hồ sơ trong phạm vi của bạn', 'blue')}
            ${leadMetric('Của tôi', stats.mine || 0, 'Đang được giao', 'green')}
            ${canQueue ? leadMetric('Chưa phân công', stats.unassigned || 0, 'Cần tiếp nhận', 'amber') : ''}
            ${leadMetric('Quá hạn', stats.overdue || 0, 'Cần xử lý trước', 'red')}
        </div>
        <div class="lead-scope-tabs" role="tablist" aria-label="Lọc lead theo công việc">
            ${[['all', 'Tất cả'], ['mine', 'Của tôi'], ...(canQueue ? [['unassigned', 'Chưa phân công']] : []), ['overdue', 'Quá hạn']]
                .map(([key, label]) => `<button type="button" role="tab" aria-selected="${leadState.scope === key}" class="${leadState.scope === key ? 'active' : ''}" data-lead-scope="${key}">${label}</button>`).join('')}
        </div>
    </div>` + VKTable.fullList({
        title: 'Danh sách khách hàng tiềm năng',
        subtitle: 'Theo dõi nguồn, người phụ trách và hạn chăm sóc tiếp theo.',
        meta: `${VKTable.money(rows.length)} / ${VKTable.money(leadState.total)} khách hàng tiềm năng`,
        filters: VKTable.filterBar({
            searchPlaceholder: 'Tìm tên, số điện thoại, email...',
            status: [
                { value: 'new', label: 'Mới' },
                { value: 'assigned', label: 'Đã giao' },
                { value: 'qualified', label: 'Đã chuyển cơ hội' },
            ],
        }),
        table: renderLeadTable(rows),
    }) + `<div class="lead-pagination"><button class="btn small" type="button" data-lead-page="${leadState.page - 1}" ${leadState.page <= 1 ? 'disabled' : ''}>← Trước</button><span>Trang ${leadState.page} / ${Math.max(1, Math.ceil(leadState.total / 20))}</span><button class="btn small" type="button" data-lead-page="${leadState.page + 1}" ${leadState.page * 20 >= leadState.total ? 'disabled' : ''}>Sau →</button></div>`;
    restoreFilters();
}

function leadMetric(label, value, note, tone) {
    return `<article class="lead-metric ${tone}"><span>${label}</span><strong>${VKTable.money(value)}</strong><small>${note}</small></article>`;
}

function renderLeadTable(rows) {
    return VKTable.renderTable([
        { label: 'Mã', render: row => `<span class="mono customer-code-accent">${VKTable.escapeHtml(row.code)}</span>` },
        { label: 'Khách hàng tiềm năng', render: row => renderLeadName(row) },
        { label: 'Số điện thoại', render: row => VKTable.escapeHtml(row.phone || '—') },
        { label: 'Email', render: row => VKTable.escapeHtml(row.email || '—') },
        { label: 'Chiến dịch', render: row => VKTable.escapeHtml(row.campaign_code || '—') },
        { label: 'Nguồn', render: row => `<span class="lead-source">${VKTable.escapeHtml(leadSourceLabel(row.source))}</span>` },
        { label: 'Phụ trách', render: row => VKTable.escapeHtml(row.assignee?.name || 'Chưa phân công') },
        { label: 'Việc tiếp theo', render: row => renderLeadNextTask(row) },
        { label: 'Trạng thái', render: row => VKTable.statusBadge(row.status) },
        { label: '', render: row => VKTable.rowActions([
            row.status !== 'qualified' ? VKTable.smallButton(row.assigned_to ? 'Phân công lại' : 'Phân công', `data-assign-lead="${row.id}"`) : '',
            row.status !== 'qualified' ? VKTable.smallButton('Chuyển cơ hội', `data-qualify-lead="${row.id}"`) : '',
            VKTable.smallButton('Mở', `data-lead-detail="${row.id}"`),
        ].filter(Boolean)) },
    ], rows, 'Chưa có khách hàng tiềm năng', { rowAttr: row => `data-row-detail="${row.id}"` });
}

function leadSourceLabel(source) {
    return ({ website: 'Website', hotline: 'Hotline', social: 'Mạng xã hội', referral: 'Giới thiệu', walk_in: 'Khách trực tiếp', campaign: 'Chiến dịch', manual: 'Nhập thủ công' })[source] || source || 'Chưa rõ';
}

function renderLeadNextTask(row) {
    if (row.status === 'qualified') return '<span class="lead-next done">Đã chuyển cơ hội</span>';
    if (!row.assigned_to) return '<span class="lead-next warning">Cần phân công</span>';
    const task = row.next_task;
    if (!task) return '<span class="lead-next warning">Chưa có việc tiếp theo</span>';
    const overdue = task.due_at && new Date(task.due_at).getTime() < Date.now();
    const due = task.due_at ? new Date(task.due_at).toLocaleString('vi-VN', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' }) : 'Chưa đặt hạn';
    return `<span class="lead-next ${overdue ? 'danger' : 'info'}">${VKTable.escapeHtml(task.title)}</span><small class="lead-next-due">${overdue ? 'Quá hạn · ' : 'Hạn · '}${VKTable.escapeHtml(due)}</small>`;
}

function filterRows() {
    return leadState.rows;
}

function restoreFilters() {
    const search = document.querySelector('[data-list-search]');
    const status = document.querySelector('[data-list-status]');
    if (search) search.value = leadState.q;
    if (status) status.value = leadState.status;
}

function renderLeadName(row) {
    return `<span class="customer-name-accent">${VKTable.escapeHtml(row.name || '—')}</span>`;
}

function renderContact(row) {
    return `${VKTable.escapeHtml(row.phone || '-')}<span class="row-note">${VKTable.escapeHtml(row.email || 'Chưa có email')}</span>`;
}

async function openLeadModal() {
    const me = await VKApi.request('/me');
    const users = await VKApi.request('/lookups/sales-users');
    const canAssignOthers = me.data.data_scope === 'company';
    VKModal.open('Tạo khách hàng tiềm năng', `
        <div class="form-grid">
            ${VKModal.field('name', 'Tên khách hàng tiềm năng')}
            ${VKModal.field('phone', 'Số điện thoại')}
            ${VKModal.field('email', 'Email', 'email')}
            ${VKModal.select('source', 'Nguồn khách hàng tiềm năng', [
                { value: 'manual', label: 'Nhập thủ công' }, { value: 'website', label: 'Website' },
                { value: 'hotline', label: 'Hotline' }, { value: 'social', label: 'Mạng xã hội' },
                { value: 'referral', label: 'Giới thiệu' }, { value: 'walk_in', label: 'Khách trực tiếp' },
                { value: 'campaign', label: 'Chiến dịch' },
            ])}
            ${VKModal.field('campaign_code', 'Mã chiến dịch')}
            ${VKModal.select('assigned_to', 'Nhân viên phụ trách', canAssignOthers ? [{ value: '', label: 'Để trong hàng chờ chưa phân công' }, ...(users.data || []).map(user => ({ value: user.id, label: user.name }))] : [{ value: me.data.id, label: me.data.name }])}
        </div>
    `, async (form) => {
        await VKApi.request('/leads', { method: 'POST', body: JSON.stringify(Object.fromEntries(new FormData(form))) });
        VKModal.toast('Đã tạo khách hàng tiềm năng và công việc chăm sóc.');
        VKModal.close();
        loadLeads();
    });
}

async function openAssignLeadModal(id) {
    const lead = leadState.rows.find(row => String(row.id) === String(id));
    if (!lead) return;
    const me = await VKApi.request('/me');
    const canAssignOthers = me.data.data_scope === 'company';
    const users = canAssignOthers ? (await VKApi.request('/lookups/sales-users')).data || [] : [{ id: me.data.id, name: me.data.name }];
    VKModal.open(`Phân công ${lead.code}`, `<p class="modal-intro">${VKTable.escapeHtml(lead.name)} · ${VKTable.escapeHtml(lead.phone)}</p>
        ${VKModal.select('assigned_to', 'Người phụ trách', users.map(user => ({ value: user.id, label: user.name })), lead.assigned_to || me.data.id)}
        <p class="modal-intro">Sau khi phân công, hệ thống tạo việc chăm sóc theo hạn SLA đã cấu hình.</p>`, async form => {
        await VKApi.request(`/leads/${id}/assign`, { method: 'POST', body: JSON.stringify(Object.fromEntries(new FormData(form))) });
        VKModal.close();
        VKModal.toast('Đã phân công và tạo việc chăm sóc.');
        await loadLeads();
        await openLeadDetail(id);
    }, { submitText: 'Xác nhận phân công' });
}

async function openLeadDetail(id) {
    const root = document.getElementById('leadsRoot');
    root.innerHTML = '<div class="customer360-loading">Đang tải hồ sơ khách hàng tiềm năng…</div>';
    try {
        const response = await VKApi.request(`/leads/${id}`);
        leadState.detail = response.data;
        leadState.detailTab = 'overview';
        renderLeadDetail();
    } catch (error) {
        renderLeads();
        VKModal.toast(error.message || 'Không tải được hồ sơ khách hàng tiềm năng.');
    }
}

function renderLeadDetail() {
    const lead = leadState.detail;
    if (!lead) return;
    const e = VKTable.escapeHtml;
    const tasks = lead.tasks || [];
    const alerts = lead.alerts || [];
    const docs = lead.related_documents || [];
    const openTasks = tasks.filter(task => !['completed', 'cancelled'].includes(task.status));
    const tabs = [['overview', 'Tổng quan'], ['activity', `Chăm sóc (${tasks.length})`], ['documents', `Chứng từ (${docs.length})`]];
    let content = '';
    if (leadState.detailTab === 'activity') {
        content = `<div class="customer360-grid lead360-grid"><article class="customer360-card"><div class="customer360-card-title"><span>✓</span><h2>Công việc chăm sóc</h2><small>${tasks.length} công việc</small>${lead.assigned_to && lead.status !== 'qualified' ? '<button type="button" data-lead-follow-up>+ Thêm việc</button>' : ''}</div>${tasks.length ? tasks.map(task => lead360Item(task.title, `${VKTable.translateStatus(task.status)} · ${lead360Date(task.due_at)}`, '', lead.status !== 'qualified' && !['completed', 'cancelled'].includes(task.status) ? task : null)).join('') : lead360Empty('Chưa có công việc chăm sóc.')}</article><article class="customer360-card"><div class="customer360-card-title"><span>!</span><h2>Cảnh báo</h2><small>${alerts.length} cảnh báo</small></div>${alerts.length ? alerts.map(alert => lead360Item(alert.title || alert.message || 'Cảnh báo', VKTable.translateStatus(alert.status))).join('') : lead360Empty('Không có cảnh báo.')}</article></div>`;
    } else if (leadState.detailTab === 'documents') {
        content = `<article class="customer360-card"><div class="customer360-card-title"><span>▤</span><h2>Chứng từ liên quan</h2><small>${docs.length} chứng từ</small></div>${docs.length ? docs.map(doc => lead360Item(`${doc.label || 'Chứng từ'} ${doc.code || ''}`, VKTable.translateStatus(doc.status), doc.path)).join('') : lead360Empty('Chưa có chứng từ liên quan.')}</article>`;
    } else {
        content = `<section class="customer360-metrics lead360-metrics"><article class="customer360-metric blue"><span>Trạng thái</span><strong>${e(VKTable.translateStatus(lead.status || 'new'))}</strong><small>Giai đoạn hiện tại</small></article><article class="customer360-metric green"><span>Người phụ trách</span><strong>${e(lead.assignee?.name || 'Chưa phân công')}</strong><small>Đầu mối chăm sóc</small></article><article class="customer360-metric orange"><span>Việc cần xử lý</span><strong>${openTasks.length}</strong><small>Công việc đang mở</small></article><article class="customer360-metric purple"><span>Chứng từ</span><strong>${docs.length}</strong><small>Chứng từ liên quan</small></article></section>${lead.converted_customer || lead.converted_deal ? `<div class="lead360-converted"><strong>Đã chuyển đổi</strong>${lead.converted_customer ? `<a href="/customers?open=${encodeURIComponent(lead.converted_customer.id)}">Khách hàng: ${e(lead.converted_customer.code)} · ${e(lead.converted_customer.name)} →</a>` : ''}${lead.converted_deal ? `<a href="/deals?open=${encodeURIComponent(lead.converted_deal.id)}">Cơ hội: ${e(lead.converted_deal.code)} · ${e(lead.converted_deal.name)} →</a>` : ''}</div>` : ''}<div class="customer360-grid lead360-grid"><article class="customer360-card"><div class="customer360-card-title"><span>◉</span><h2>Thông tin hồ sơ</h2><small>Dữ liệu lead</small></div>${lead360Info('Mã lead', lead.code)}${lead360Info('Số điện thoại', lead.phone)}${lead360Info('Email', lead.email)}${lead360Info('Nguồn', leadSourceLabel(lead.source))}${lead360Info('Chiến dịch', lead.campaign_code)}${lead360Info('Ngày tạo', lead360Date(lead.created_at))}</article><article class="customer360-card"><div class="customer360-card-title"><span>⌁</span><h2>Hoạt động gần đây</h2><small>${tasks.length + docs.length} mục</small>${lead.assigned_to && lead.status !== 'qualified' ? '<button type="button" data-lead-follow-up>+ Thêm việc</button>' : ''}</div>${tasks.slice(0, 3).map(task => lead360Item(task.title, `${VKTable.translateStatus(task.status)} · ${lead360Date(task.due_at)}`)).join('')}${docs.slice(0, 2).map(doc => lead360Item(`${doc.label || 'Chứng từ'} ${doc.code || ''}`, VKTable.translateStatus(doc.status), doc.path)).join('')}${!tasks.length && !docs.length ? lead360Empty('Chưa có hoạt động. Hãy phân công để bắt đầu chăm sóc.') : ''}</article></div>`;
    }
    document.getElementById('leadsRoot').innerHTML = `<section class="customer360-page lead360-page"><header class="customer360-hero"><div class="customer360-hero-top"><button class="customer360-back" type="button" data-lead-back>← Danh sách khách hàng tiềm năng</button><div class="customer360-hero-actions"><span class="customer360-id">${e(lead.code || 'LEAD')}</span>${lead.status !== 'qualified' ? `<button class="btn small" type="button" data-assign-lead="${lead.id}">Phân công</button><button class="btn small" type="button" data-qualify-lead="${lead.id}">Chuyển cơ hội</button>` : ''}</div></div><div class="customer360-identity"><div class="customer360-avatar">${e((lead.name || 'K').trim().slice(0, 1).toUpperCase())}</div><div><p>Hồ sơ khách hàng tiềm năng</p><h1>${e(lead.name || 'Khách hàng tiềm năng')}</h1><span>${e(lead.phone || 'Chưa có số điện thoại')} · ${e(lead.email || 'Chưa có email')} · ${e(leadSourceLabel(lead.source))}</span></div><div class="customer360-owner"><span>Người phụ trách</span><strong>${e(lead.assignee?.name || 'Chưa phân công')}</strong><em class="customer360-status active">${e(VKTable.translateStatus(lead.status || 'new'))}</em></div></div></header><nav class="customer360-tabs" aria-label="Hồ sơ khách hàng tiềm năng">${tabs.map(([key, label]) => `<button type="button" class="${leadState.detailTab === key ? 'active' : ''}" data-lead-tab="${key}">${label}</button>`).join('')}</nav><div class="customer360-body">${content}</div></section>`;
}

function lead360Info(label, value) {
    return `<div class="customer360-info"><span>${VKTable.escapeHtml(label)}</span><strong>${VKTable.escapeHtml(value || 'Chưa cập nhật')}</strong></div>`;
}

function lead360Item(title, subtitle, path = '', task = null) {
    return `<div class="lead360-item"><div><strong>${VKTable.escapeHtml(title || '-')}</strong><span>${VKTable.escapeHtml(subtitle || '')}</span></div>${path ? `<a class="btn small" href="${VKTable.escapeHtml(path)}">Mở</a>` : ''}${task ? `<div class="lead360-task-actions">${task.status === 'new' ? `<button class="btn small" type="button" data-lead-task-status="${task.id}" data-task-status="in_progress">Bắt đầu</button>` : ''}<button class="btn small" type="button" data-lead-task-status="${task.id}" data-task-status="completed">Hoàn thành</button></div>` : ''}</div>`;
}

function openLeadFollowUpModal() {
    const lead = leadState.detail;
    if (!lead || !lead.assigned_to || lead.status === 'qualified') return;
    VKModal.open('Thêm công việc chăm sóc', `<p class="modal-intro">Công việc sẽ giao cho ${VKTable.escapeHtml(lead.assignee?.name || 'người phụ trách lead')}.</p><div class="form-grid">${VKModal.field('title', 'Nội dung công việc')}${VKModal.field('due_at', 'Hạn xử lý', 'datetime-local')}${VKModal.select('priority', 'Ưu tiên', [{ value: 'normal', label: 'Bình thường' }, { value: 'high', label: 'Cao' }, { value: 'urgent', label: 'Khẩn cấp' }])}${VKModal.field('description', 'Ghi chú')}</div>`, async form => {
        await VKApi.request(`/leads/${lead.id}/follow-ups`, { method: 'POST', body: JSON.stringify(Object.fromEntries(new FormData(form))) });
        VKModal.close();
        VKModal.toast('Đã tạo công việc chăm sóc.');
        await openLeadDetail(lead.id);
        leadState.detailTab = 'activity';
        renderLeadDetail();
    }, { submitText: 'Tạo công việc' });
}

async function updateLeadTaskStatus(taskId, status) {
    const leadId = leadState.detail?.id;
    if (!leadId) return;
    try {
        await VKApi.request(`/leads/${leadId}/follow-ups/${taskId}/status`, { method: 'POST', body: JSON.stringify({ status }) });
        await openLeadDetail(leadId);
        leadState.detailTab = 'activity';
        renderLeadDetail();
        VKModal.toast(status === 'completed' ? 'Đã hoàn thành công việc.' : 'Đã bắt đầu công việc.');
    } catch (error) { VKModal.toast(error.message || 'Không cập nhật được công việc.'); }
}

function lead360Empty(message) {
    return `<p class="customer360-empty-timeline">${VKTable.escapeHtml(message)}</p>`;
}

function lead360Date(value) {
    if (!value) return 'Chưa đặt hạn';
    const date = new Date(value);
    return Number.isNaN(date.getTime()) ? '-' : date.toLocaleString('vi-VN');
}

async function openQualifyModal(id) {
    const lead = leadState.rows.find(row => String(row.id) === String(id));
    if (!lead) return;
    let customers;
    try {
        customers = [];
        let page = 1, total = 0;
        do {
            const response = await VKApi.request(`/customers?page_size=100&page=${page++}`, {cache:false});
            customers.push(...(response.data || []).filter(customer => customer.status === 'active'));
            total = response.meta?.total || 0;
        } while ((page - 1) * 100 < total);
    } catch (error) { VKModal.toast(error.message || 'Không tải được khách hàng.'); return; }
    VKModal.open('Chuyển thành khách hàng & cơ hội', `
        <p class="modal-intro">Hệ thống sẽ dùng khách hàng trùng số điện thoại nếu đã có; nếu chưa có thì tự tạo khách hàng mới và mở một cơ hội bán hàng.</p>
        <div class="form-grid">
            ${VKModal.select('customer_id', 'Liên kết khách hàng', [{value:'',label:'Tự tìm theo điện thoại/email; chưa có thì tạo mới'}, ...customers.map(customer => ({value:customer.id,label:customer.name}))], lead.customer_id || '')}
            ${VKModal.select('customer_type', 'Loại khách hàng mới', [{value:'person',label:'Cá nhân / khách lẻ'},{value:'organization',label:'Tổ chức / doanh nghiệp'}], 'person')}
            ${VKModal.field('customer_name', 'Tên khách hàng', 'text', lead.name)}
            ${VKModal.field('deal_name', 'Tên cơ hội', 'text', `Cơ hội từ ${lead.name}`)}
            ${VKModal.field('amount', 'Giá trị dự kiến', 'number', '0')}
            ${VKModal.field('expected_close_date', 'Ngày dự kiến chốt', 'date')}
            ${VKModal.field('next_activity_at', 'Lần chăm sóc tiếp theo', 'datetime-local')}
        </div>
    `, async (form) => {
        await VKApi.request(`/leads/${id}/qualify`, { method: 'POST', body: JSON.stringify(Object.fromEntries(new FormData(form))) });
        VKModal.toast('Đã liên kết khách hàng và tạo cơ hội bán hàng.');
        VKModal.close();
        await loadLeads();
        await openLeadDetail(id);
    }, { submitText: 'Tạo cơ hội' });
    const customerSelect = document.querySelector('#modalBody [name="customer_id"]');
    const syncCustomerChoice = () => {
        const selected = customers.find(customer => String(customer.id) === customerSelect.value);
        const name = document.querySelector('#modalBody [name="customer_name"]');
        const type = document.querySelector('#modalBody [name="customer_type"]');
        name.readOnly = Boolean(selected);
        type.disabled = Boolean(selected);
        if (selected) { name.value = selected.name; type.value = selected.customer_type; }
    };
    customerSelect.addEventListener('change', syncCustomerChoice);
    syncCustomerChoice();
}

window.loadLeads = loadLeads;
})();
