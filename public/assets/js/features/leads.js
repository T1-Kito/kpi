(function () {
let leadState = { rows: [], q: '', status: '', scope: 'all', page: 1, total: 0, stats: {}, detail: null, detailTab: 'overview' };
let leadSearchTimer;
let leadLoadSequence = 0;

document.addEventListener('vk:ready', () => loadLeads());
document.addEventListener('input', (event) => {
    if (event.target.closest('.lead-create-modal') && ['expected_service_date','guest_count','interested_services','region'].includes(event.target.name)) {
        const form=event.target.closest('form'), summary=form?.querySelector('[data-lead-needs-summary]');
        if(summary)summary.textContent=leadNeedsSummary(Object.fromEntries(new FormData(form)));
        const reminder=form?.querySelector('[data-lead-needs-reminder]');
        if(reminder && form.elements.needs?.value.trim())reminder.hidden=false;
    }
    if (!document.getElementById('leadsRoot')) return;
    if (event.target.matches('[data-list-search]')) {
        leadState.q = event.target.value;
        ++leadLoadSequence;
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
    if (event.target.closest('[data-lead-pool-settings]')) { openLeadPoolSettings(); return; }
    if (event.target.closest('[data-lead-handover]')) { openLeadHandover(); return; }
    if (event.target.closest('[data-lead-respond]')) { openLeadHandoverResponse(); return; }
    if (event.target.closest('[data-lead-merge]')) { openLeadMerge(); return; }
    if (event.target.closest('[data-lead-edit]')) { openLeadEditModal(); return; }
    if (!document.getElementById('leadsRoot')) return;
    if (event.target.closest('[data-lead-back]')) { leadState.detail = null; history.replaceState({},'', '/leads'); loadLeads(); return; }
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
    if (page) { if(page.disabled)return;leadState.page = Number(page.dataset.leadPage); loadLeads(); return; }
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
    const version = ++leadLoadSequence;
    try {
        const query = new URLSearchParams({ page: String(leadState.page), page_size: '20', scope: leadState.scope });
        if (leadState.q) query.set('q', leadState.q);
        if (leadState.status) query.set('status', leadState.status);
        const leads = await VKApi.request(`/leads?${query}`, { cache: false });
        if (version !== leadLoadSequence || document.getElementById('leadsRoot') !== root) return;
        leadState.rows = leads.data || [];
        leadState.total = leads.meta?.total || 0;
        leadState.stats = leads.meta?.stats || {};
        const requested = new URLSearchParams(window.location.search).get('open');
        if (requested && /^\d+$/.test(requested)) { await openLeadDetail(requested); return; }
        renderLeads();
    } catch (error) {
        if (version !== leadLoadSequence || document.getElementById('leadsRoot') !== root) return;
        if (root.querySelector('[data-list-search]')) {
            VKModal.toast(error.message || 'Không tải được kết quả. Bạn có thể tìm lại.');
            return;
        }
        root.innerHTML = `<section class="list-page"><div class="empty"><strong>Không tải được khách hàng tiềm năng</strong><span>${VKTable.escapeHtml(error.message || 'Vui lòng thử lại.')}</span><button class="btn small" type="button" data-retry-leads>Thử lại</button></div></section>`;
    }
}

function renderLeads() {
    leadState.detail = null;
    const rows = leadState.rows;
    const stats = leadState.stats;
    const canQueue = window.VKUser?.data_scope === 'company';
    VKTable.updateList(document.getElementById('leadsRoot'), `<div class="lead-workbench">
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
            searchPlaceholder: 'Tên không dấu, mã, điện thoại, nguồn, chiến dịch…',
            status: [
                { value: 'new', label: 'Mới' },
                { value: 'assigned', label: 'Đã giao' },
                { value: 'qualified', label: 'Đã chuyển cơ hội' },
            ],
        }),
        table: renderLeadTable(rows),
    }) + `<div class="lead-pagination"><button class="btn small" type="button" data-lead-page="${leadState.page - 1}" ${leadState.page <= 1 ? 'disabled' : ''}>← Trước</button><span>Trang ${leadState.page} / ${Math.max(1, Math.ceil(leadState.total / 20))}</span><button class="btn small" type="button" data-lead-page="${leadState.page + 1}" ${leadState.page * 20 >= leadState.total ? 'disabled' : ''}>Sau →</button></div>`);
    restoreFilters();
    const root=document.getElementById('leadsRoot');
    root.querySelector('.lead-workbench')?.classList.add('customer-lead-summary');
    const list=root.querySelector('.list-page');
    list?.querySelector('.list-page-head')?.remove();
    const pager=root.querySelector('.lead-pagination');
    if(list && pager){
        list.classList.add('customer-list-workspace','customer-lead-workspace');
        const table=list.querySelector('.table-wrap');
        if(table){const scroll=document.createElement('div');scroll.className='customer-list-scroll';table.before(scroll);scroll.appendChild(table);}
        pager.className='customer-list-pagination';pager.setAttribute('aria-label','Trang khách hàng tiềm năng');list.appendChild(pager);
    }
}

function leadMetric(label, value, note, tone) {
    return `<article class="lead-metric ${tone}"><span>${label}</span><strong>${VKTable.money(value)}</strong><small>${note}</small></article>`;
}

function renderLeadTable(rows) {
    const canManage = window.VKLayout?.hasPermission('sales.lead.manage');
    return VKTable.renderTable([
        { label: 'Mã', render: row => `<span class="customer-list-code">${VKTable.escapeHtml(row.code)}</span>` },
        { label: 'Khách hàng tiềm năng', render: row => renderLeadName(row) },
        { label: 'Số điện thoại', render: row => VKTable.escapeHtml(row.phone || '—') },
        { label: 'Email', render: row => VKTable.escapeHtml(row.email || '—') },
        { label: 'Chiến dịch', render: row => VKTable.escapeHtml(row.campaign_code || '—') },
        { label: 'Nguồn', render: row => `<span class="lead-source">${VKTable.escapeHtml(leadSourceLabel(row.source))}</span>` },
        { label: 'Phụ trách', render: row => VKTable.escapeHtml(row.assignee?.name || 'Chưa phân công') },
        { label: 'Việc tiếp theo', render: row => renderLeadNextTask(row) },
        { label: 'Trạng thái', render: row => VKTable.statusBadge(row.status) },
        { label: '', render: row => VKTable.rowActions([
            canManage && row.can_write && row.handover_status !== 'pending' && row.status !== 'qualified' ? VKTable.smallButton(row.assigned_to ? 'Phân công lại' : 'Phân công', `data-assign-lead="${row.id}"`) : '',
            canManage && row.can_write && row.handover_status !== 'pending' && row.status !== 'qualified' ? VKTable.smallButton('Chuyển cơ hội', `data-qualify-lead="${row.id}"`) : '',
            VKTable.smallButton('Mở', `data-lead-detail="${row.id}"`),
        ].filter(Boolean)) },
    ], rows, 'Chưa có khách hàng tiềm năng', { rowAttr: row => `data-row-detail="${row.id}"` });
}

function leadSourceLabel(source) {
    return ({ website: 'Website', hotline: 'Hotline', social: 'Mạng xã hội', referral: 'Giới thiệu', walk_in: 'Khách trực tiếp', campaign: 'Chiến dịch', manual: 'Nhập thủ công' })[source] || source || 'Chưa rõ';
}

function renderLeadNextTask(row) {
    if (row.handover_status === 'pending') return '<span class="lead-next warning">Chờ Sales tiếp nhận</span>';
    if (row.handover_status === 'returned') return '<span class="lead-next danger">Sales đã trả lại · Cần bổ sung</span>';
    if (row.status === 'qualified') return '<span class="lead-next done">Đã chuyển cơ hội</span>';
    if (!row.assigned_to) return '<span class="lead-next warning">Cần phân công</span>';
    const task = row.next_task;
    if (!task) return row.latest_completed_task ? `<span class="lead-next done">Đã hoàn thành: ${VKTable.escapeHtml(row.latest_completed_task.title)}</span><small class="lead-next-due">Chưa đặt việc tiếp theo</small>` : '<span class="lead-next warning">Chưa có việc tiếp theo</span>';
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
    return `<div class="customer-list-name"><span class="customer-list-avatar" aria-hidden="true">${VKTable.escapeHtml((row.name || '?').slice(0,1).toUpperCase())}</span><strong>${VKTable.escapeHtml(row.name || '—')}</strong></div>`;
}

function renderContact(row) {
    return `${VKTable.escapeHtml(row.phone || '-')}<span class="row-note">${VKTable.escapeHtml(row.email || 'Chưa có email')}</span>`;
}

async function openLeadModal() {
    const requestKey = VKApi.newRequestKey();
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
            ${VKModal.select('assigned_to', 'Nhân viên phụ trách', canAssignOthers ? [{ value: '', label: 'Tự phân công theo cấu hình; chưa có thì vào hàng chờ' }, ...(users.data || []).map(user => ({ value: user.id, label: user.name }))] : [{ value: me.data.id, label: me.data.name }])}
        </div>
        <details class="crm-optional-fields"><summary>Thông tin chiến dịch (không bắt buộc)</summary>${VKModal.field('campaign_code', 'Mã chiến dịch')}</details>
        ${leadNeedsFields({})}
        <p class="crm-auto-note">Mã hồ sơ tự sinh. Khi có người phụ trách, hệ thống tự tạo việc chăm sóc và đặt hạn theo cấu hình SLA.</p>
    `, async (form) => {
        const data = Object.fromEntries(new FormData(form));
        let response;
        try { response = await VKApi.request('/leads', { method: 'POST', headers: {'Idempotency-Key': requestKey}, body: JSON.stringify(data) }); }
        catch (error) {
            if (error.status !== 409 || !error.data?.duplicates) throw error;
            const proceed = await VKModal.confirm({title:'Phát hiện hồ sơ có thể trùng',message:'Kiểm tra các hồ sơ dưới đây. Chỉ tạo riêng khi đây là một yêu cầu khác của khách.',details:error.data.duplicates.map(row=>`${row.code} — ${row.name}`),confirmText:'Tạo yêu cầu riêng',cancelText:'Quay lại kiểm tra'});
            if (!proceed) return;
            response = await VKApi.request('/leads', {method:'POST',headers:{'Idempotency-Key':requestKey},body:JSON.stringify({...data,allow_duplicate:true})});
        }
        VKModal.toast(response.meta?.duplicate_phone_warning ? 'Đã tạo hồ sơ. Số điện thoại đã có ở hồ sơ khác; vui lòng kiểm tra trùng.' : (response.data.assigned_to ? 'Đã tạo hồ sơ, phân công và tạo việc chăm sóc.' : 'Đã tạo hồ sơ trong hàng chờ phân công.'), response.meta?.duplicate_phone_warning ? 'warning' : 'success');
        VKModal.close();
        loadLeads();
    }, { className: 'lead-create-modal', submitText: 'Tạo khách hàng tiềm năng' });
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
    ++leadLoadSequence;
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
        content = `<div class="lead-profile-counts"><span>Việc cần xử lý <b>${openTasks.length}</b></span><span>Chứng từ liên quan <b>${docs.length}</b></span></div>${lead.converted_customer || lead.converted_deal ? `<div class="lead360-converted"><strong>Đã chuyển đổi</strong>${lead.converted_customer ? `<a href="/customers?open=${encodeURIComponent(lead.converted_customer.id)}">Khách hàng: ${e(lead.converted_customer.code)} · ${e(lead.converted_customer.name)} →</a>` : ''}${lead.converted_deal ? `<a href="/deals?open=${encodeURIComponent(lead.converted_deal.id)}">Cơ hội: ${e(lead.converted_deal.code)} · ${e(lead.converted_deal.name)} →</a>` : ''}</div>` : ''}<div class="customer360-grid lead360-grid"><article class="customer360-card"><div class="customer360-card-title"><span>◉</span><h2>Thông tin hồ sơ</h2><small>Dữ liệu lead</small></div>${lead360Info('Mã lead', lead.code)}${lead360Info('Số điện thoại', lead.phone)}${lead360Info('Email', lead.email)}${lead360Info('Nguồn', leadSourceLabel(lead.source))}${lead360Info('Chiến dịch', lead.campaign_code)}${lead360Info('Ngày tạo', lead360Date(lead.created_at))}</article><article class="customer360-card"><div class="customer360-card-title"><span>⌁</span><h2>Hoạt động gần đây</h2><small>${tasks.length + docs.length} mục</small>${lead.assigned_to && lead.status !== 'qualified' ? '<button type="button" data-lead-follow-up>+ Thêm việc</button>' : ''}</div>${tasks.slice(0, 3).map(task => lead360Item(task.title, `${VKTable.translateStatus(task.status)} · ${lead360Date(task.due_at)}`)).join('')}${docs.slice(0, 2).map(doc => lead360Item(`${doc.label || 'Chứng từ'} ${doc.code || ''}`, VKTable.translateStatus(doc.status), doc.path)).join('')}${!tasks.length && !docs.length ? lead360Empty('Chưa có hoạt động. Hãy phân công để bắt đầu chăm sóc.') : ''}</article></div>`;
    }
    document.getElementById('leadsRoot').innerHTML = `<section class="customer360-page lead360-page"><header class="lead-profile-header"><div class="lead-profile-top"><div class="customer360-hero-actions"><button class="btn small" type="button" data-lead-edit>Sửa hồ sơ</button><details class="lead-profile-menu"><summary>Thao tác khác <span aria-hidden="true">⋯</span></summary><div><button type="button" data-assign-lead="${lead.id}">Phân công phụ trách</button><button type="button" data-qualify-lead="${lead.id}">Chuyển thành cơ hội</button></div></details></div></div><div class="lead-profile-main"><div class="lead-profile-name"><h1>${e(lead.name || 'Khách hàng tiềm năng')}</h1><div class="lead-profile-contact"><span class="record-code-text">${e(lead.code)}</span><span>${e(lead.phone || 'Chưa có số điện thoại')}</span><span>${e(lead.email || 'Chưa có email')}</span><span>${e(leadSourceLabel(lead.source))}</span></div></div><div class="lead-profile-owner"><span>Người phụ trách</span><strong>${e(lead.assignee?.name || 'Chưa phân công')}</strong>${VKTable.statusBadge(lead.status || 'new')}</div></div></header><nav class="customer360-tabs" aria-label="Hồ sơ khách hàng tiềm năng">${tabs.map(([key, label]) => `<button type="button" class="${leadState.detailTab === key ? 'active' : ''}" data-lead-tab="${key}">${label}</button>`).join('')}</nav><div class="customer360-body">${content}</div></section>`;
    const body = document.querySelector('#leadsRoot .customer360-body');
    if (leadState.detailTab === 'overview') body?.insertAdjacentHTML('afterbegin', leadNeedsCard(lead));
    const actions = document.querySelector('#leadsRoot .customer360-hero-actions');
    const canManage = window.VKLayout?.hasPermission('sales.lead.manage');
    if (canManage && lead.can_write && lead.status !== 'qualified' && lead.handover_status !== 'pending') {
        actions?.insertAdjacentHTML('afterbegin','<button type="button" class="btn primary small" data-lead-handover>Bàn giao Sales</button>');
        actions?.querySelector('.lead-profile-menu > div')?.insertAdjacentHTML('beforeend','<button type="button" data-lead-merge>Kiểm tra trùng / Gộp</button>');
    }
    if (canManage && lead.handover_status === 'pending' && Number(lead.handover_to) === Number(window.VKUser?.id)) actions?.insertAdjacentHTML('beforeend','<button type="button" class="btn primary small" data-lead-respond>Tiếp nhận / Trả lại</button>');
    if (!canManage || !lead.can_write) {
        document.querySelectorAll('#leadsRoot [data-lead-edit], #leadsRoot [data-assign-lead], #leadsRoot [data-qualify-lead], #leadsRoot [data-lead-follow-up], #leadsRoot [data-lead-task-status]').forEach(node => node.remove());
    }
    if (lead.handover_status === 'pending') document.querySelectorAll('#leadsRoot [data-assign-lead], #leadsRoot [data-qualify-lead]').forEach(node=>node.remove());
    if (lead.status === 'qualified') document.querySelectorAll('#leadsRoot [data-lead-edit], #leadsRoot [data-assign-lead], #leadsRoot [data-qualify-lead]').forEach(node=>node.remove());
    const more=actions?.querySelector('.lead-profile-menu');
    if (more && !more.querySelector('button')) more.remove();
}

function lead360Info(label, value) {
    return `<div class="customer360-info"><span>${VKTable.escapeHtml(label)}</span><strong class="${/^Mã(?:\s|$)/i.test(label) ? 'record-code-text' : ''}">${VKTable.escapeHtml(value || 'Chưa cập nhật')}</strong></div>`;
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
    if (status === 'completed') {
        VKModal.open('Ghi nhận kết quả chăm sóc', `${VKModal.field('reason', 'Kết quả trao đổi với khách')}`, async form => {
            const reason = form.elements.reason.value.trim();
            if (!reason) throw new Error('Cần ghi kết quả chăm sóc trước khi hoàn thành.');
            await VKApi.request(`/leads/${leadId}/follow-ups/${taskId}/status`, {method:'POST', body:JSON.stringify({status, reason})});
            VKModal.close();
            await openLeadDetail(leadId);
            leadState.detailTab = 'activity'; renderLeadDetail();
            VKModal.toast('Đã lưu kết quả và hoàn thành công việc.');
        }, {submitText:'Lưu kết quả'});
        return;
    }
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

function openLeadEditModal() {
    const lead = leadState.detail;
    if (!lead) return;
    VKModal.open(`Sửa ${lead.code}`, `<div class="form-grid">${VKModal.field('name','Tên khách hàng tiềm năng','text',lead.name)}${VKModal.field('phone','Số điện thoại','tel',lead.phone)}${VKModal.field('email','Email','email',lead.email || '')}${VKModal.field('source','Nguồn','text',lead.source || '')}${VKModal.field('campaign_code','Mã chiến dịch','text',lead.campaign_code || '')}</div>${leadNeedsFields(lead)}`, async form => {
        const data=Object.fromEntries(new FormData(form));
        try { await VKApi.request(`/leads/${lead.id}`,{method:'PUT',body:JSON.stringify(data)}); }
        catch(error) {
            if (!error.data?.duplicates?.length) throw error;
            const okay=await VKModal.confirm({title:'Thông tin liên hệ có thể trùng',message:error.data.duplicates.map(row=>`${row.code} — ${row.name}`).join('\n')+'\nNếu đã đối chiếu và đây là nhu cầu riêng, bạn có thể tiếp tục lưu.',confirmText:'Vẫn lưu',cancelText:'Kiểm tra lại'});
            if (!okay) return;
            await VKApi.request(`/leads/${lead.id}`,{method:'PUT',body:JSON.stringify({...data,allow_duplicate:true})});
        }
        VKModal.close(); await openLeadDetail(lead.id); VKModal.toast('Đã cập nhật khách hàng tiềm năng.');
    },{className:'lead-create-modal',submitText:'Lưu thay đổi'});
}

function leadNeedsFields(row) {
    const e=VKTable.escapeHtml;
    return `<section class="lead-needs-form"><h3>Nhu cầu & lịch dự kiến</h3><div class="form-grid two">${VKModal.field('region','Khu vực','text',row.region || '')}${VKModal.field('expected_service_date','Ngày dự kiến sử dụng','date',(row.expected_service_date || '').slice(0,10))}${VKModal.field('guest_count','Số khách dự kiến','number',row.guest_count || '')}${VKModal.field('interested_services','Dịch vụ quan tâm','text',row.interested_services || '')}<div class="full lead-current-summary"><span>Tóm tắt tự động</span><strong data-lead-needs-summary>${e(leadNeedsSummary(row))}</strong></div><p class="full lead-needs-reminder" data-lead-needs-reminder hidden>Thông tin dự kiến vừa thay đổi. Kiểm tra lại ngày và số khách trong mô tả nhu cầu trước khi lưu.</p><label class="full">Mô tả nhu cầu<textarea name="needs" rows="3" maxlength="4000">${e(row.needs || '')}</textarea></label><label class="full">Ghi chú bàn giao<textarea name="notes" rows="2" maxlength="5000">${e(row.notes || '')}</textarea></label></div></section>`;
}
function leadNeedsSummary(row) {
    const date=(row.expected_service_date || '').slice(0,10);
    return [row.guest_count ? `${row.guest_count} khách` : '',date ? `Dự kiến ${date.split('-').reverse().join('/')}` : '',row.interested_services,row.region].filter(Boolean).join(' · ') || 'Bổ sung ngày dự kiến, số khách và dịch vụ quan tâm.';
}
function leadNeedsCard(row) {
    const e=VKTable.escapeHtml;
    const status={pending:'Chờ Sales tiếp nhận',accepted:'Sales đã tiếp nhận',returned:'Sales đã trả lại'}[row.handover_status];
    return `<article class="customer360-card lead-needs-card"><div class="customer360-card-title"><h2>Nhu cầu & bàn giao</h2>${status?`<span class="badge">${e(status)}</span>`:''}</div><div class="lead-current-summary"><span>Yêu cầu hiện tại · Tự cập nhật</span><strong>${e(leadNeedsSummary(row))}</strong></div>${leadResponsibility(row)}<div class="lead-needs-grid">${[['Mô tả nhu cầu',row.needs],['Khu vực',row.region],['Ngày dự kiến',row.expected_service_date],['Số khách',row.guest_count],['Dịch vụ quan tâm',row.interested_services],['Sales nhận',row.handover_recipient],['Ghi chú',row.notes]].map(([label,value])=>lead360Info(label,value)).join('')}</div>${(row.workflow_history || []).length?`<details class="lead-handover-history"><summary>Lịch sử bàn giao và thay đổi</summary>${row.workflow_history.map(leadWorkflowEntry).join('')}</details>`:''}</article>`;
}
function leadResponsibility(row) {
    const e=VKTable.escapeHtml,owner=row.assignee?.name || 'Chưa phân công';
    let text='';
    if(row.handover_status==='pending') text=`${owner} tiếp tục phụ trách khách. Đang chờ ${row.handover_recipient || 'Sales được giao'} tiếp nhận.`;
    if(row.handover_status==='accepted') text=`${owner} đã tiếp nhận và đang phụ trách khách.`;
    if(row.handover_status==='returned') text=`Sales đã trả lại hồ sơ. ${owner} tiếp tục phụ trách và bổ sung thông tin trước khi bàn giao lại.`;
    return text?`<p class="lead-responsibility">${e(text)}</p>`:'';
}
function leadWorkflowEntry(item) {
    const e=VKTable.escapeHtml, details=item.details || {};
    const labels={name:'Tên khách',phone:'Số điện thoại',email:'Email',source:'Nguồn',campaign_code:'Chiến dịch',needs:'Nhu cầu',region:'Khu vực',expected_service_date:'Ngày dự kiến',guest_count:'Số khách',interested_services:'Dịch vụ quan tâm',notes:'Ghi chú'};
    const changes=details.after ? Object.entries(details.after).filter(([key,value])=>String(details.before?.[key] ?? '')!==String(value ?? '')).map(([key,value])=>`${labels[key] || key}: ${details.before?.[key] || 'Chưa có'} → ${value || 'Để trống'}`) : [];
    const note=[details.recipient_name ? `Người nhận: ${details.recipient_name}` : '',details.source_code ? `Từ hồ sơ: ${details.source_code}` : '',details.note || details.reason || '',...changes].filter(Boolean).join('\n');
    return `<div><strong>${e(item.action)}</strong><small>${e(item.actor_name)} · ${e(lead360Date(item.at))}</small>${note?`<p>${e(note)}</p>`:''}</div>`;
}
async function openLeadHandover() {
    try {
        const lead=leadState.detail, response=await VKApi.request('/leads/handover-candidates',{cache:false});
        if (!response.data.length) return VKModal.toast(response.meta?.configured ? 'Nhóm đã chọn chưa có Sales đang hoạt động, đủ quyền nhận bàn giao.' : 'Chưa thiết lập nhóm nhận bàn giao. Quản trị mở “Nhóm nhận bàn giao” trên trang này để chọn vai trò Sales.','warning');
        VKModal.open(`Bàn giao ${lead.code}`, `<div class="lead-transfer-summary"><span class="record-code-text">${VKTable.escapeHtml(lead.code)}</span><strong>${VKTable.escapeHtml(lead.name)}</strong><p>Người phụ trách hiện tại tiếp tục theo khách đến khi Sales tiếp nhận.</p></div>${VKModal.select('recipient_id','Sales tiếp nhận',[{value:'',label:'Chọn người nhận'},...response.data.map(row=>({value:row.id,label:`${row.name} — ${row.department || 'Chưa gán phòng ban'}`}))])}<label class="lead-transfer-note">Nội dung bàn giao<textarea name="note" rows="3" maxlength="2000" required placeholder="Thông tin đã trao đổi và việc Sales cần tiếp tục…"></textarea></label>`, async form=>{
            if (!form.elements.recipient_id.value) throw new Error('Vui lòng chọn người nhận bàn giao.');
            await VKApi.request(`/leads/${lead.id}/handover`,{method:'POST',body:JSON.stringify(Object.fromEntries(new FormData(form)))});
            VKModal.close();await openLeadDetail(lead.id);VKModal.toast('Đã gửi bàn giao và thông báo cho Sales.');
        },{className:'lead-handover-modal',submitText:'Gửi bàn giao'});
    } catch(error) { VKModal.toast(error.message,'danger'); }
}
async function openLeadPoolSettings() {
    try {
        const response=await VKApi.request('/tenant/lead-handover',{cache:false});
        const selected=(response.data.role_ids || []).map(Number),e=VKTable.escapeHtml;
        VKModal.open('Nhóm nhận bàn giao Sales',`<p class="form-note">Chọn các vai trò được nhận lead từ Marketing. Thành viên cần đang hoạt động và có quyền quản lý khách hàng tiềm năng, cơ hội.</p><div class="lead-pool-roles">${response.roles.map(role=>`<label><input type="checkbox" name="role_ids" value="${Number(role.id)}" ${selected.includes(Number(role.id))?'checked':''}><span><strong>${e(role.name)}</strong><small>${e(role.code)}</small></span></label>`).join('') || '<p>Chưa có vai trò để chọn.</p>'}</div>`,async form=>{
            const role_ids=new FormData(form).getAll('role_ids').map(Number);
            await VKApi.request('/tenant/lead-handover',{method:'POST',body:JSON.stringify({role_ids})});
            VKModal.close();VKModal.toast('Đã lưu nhóm nhận bàn giao Sales.');
        },{className:'lead-handover-modal',submitText:'Lưu thiết lập'});
    } catch(error) { VKModal.toast(error.message,'danger'); }
}
function openLeadHandoverResponse() {
    const lead=leadState.detail;
    VKModal.open(`Tiếp nhận ${lead.code}`,`${VKModal.select('decision','Xử lý',[{value:'accepted',label:'Tiếp nhận phụ trách'},{value:'returned',label:'Trả lại người bàn giao'}])}${VKModal.field('note','Xác nhận / Lý do trả lại')}`,async form=>{
        await VKApi.request(`/leads/${lead.id}/handover-response`,{method:'POST',body:JSON.stringify(Object.fromEntries(new FormData(form)))});
        VKModal.close();await openLeadDetail(lead.id);VKModal.toast('Đã cập nhật bàn giao.');
    },{submitText:'Xác nhận'});
}
async function openLeadMerge() {
    try {
        const lead=leadState.detail;
        const response=await VKApi.request('/leads/duplicate-check?'+new URLSearchParams({phone:lead.phone || '',email:lead.email || ''}),{cache:false});
        const rows=response.data.filter(row=>Number(row.id)!==Number(lead.id));
        if (!rows.length) return VKModal.toast('Không tìm thấy lead trùng điện thoại hoặc email trong phạm vi được xem.');
        VKModal.open(`Gộp ${lead.code}`, `<p class="form-note">Hồ sơ đang mở sẽ được lưu thành bản gốc đã gộp. Công việc và lịch sử chuyển vào hồ sơ giữ lại; thông tin đang có ở hồ sơ giữ lại không bị ghi đè. Hồ sơ có báo giá/cơ hội hoặc đang bàn giao sẽ không được gộp.</p>${VKModal.select('target_id','Hồ sơ giữ lại',rows.map(row=>({value:row.id,label:`${row.code} — ${row.name}`})))}${VKModal.field('reason','Lý do gộp và thông tin đã đối chiếu')}`,async form=>{
            const data=Object.fromEntries(new FormData(form));
            const okay=await VKModal.confirm({title:'Xác nhận gộp hồ sơ',message:`Gộp ${lead.code} vào hồ sơ đã chọn? Lịch sử được giữ lại.`,confirmText:'Gộp hồ sơ',cancelText:'Quay lại'});
            if (!okay) return;
            const result=await VKApi.request(`/leads/${lead.id}/merge`,{method:'POST',body:JSON.stringify(data)});
            VKModal.close();history.replaceState({},'',`/leads?open=${result.data.id}`);await openLeadDetail(result.data.id);VKModal.toast('Đã gộp và giữ lịch sử hồ sơ.');
        },{submitText:'Kiểm tra và gộp'});
    } catch(error) { VKModal.toast(error.message,'danger'); }
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
        <div class="crm-conversion-summary"><span>Khách hàng tiềm năng → Khách hàng → Cơ hội</span><strong>${VKTable.escapeHtml(lead.name)}</strong><small>${VKTable.escapeHtml([lead.phone, lead.email].filter(Boolean).join(' · '))}</small></div>
        <p class="crm-auto-note">Tự tìm khách hàng theo điện thoại/email, giữ nguồn và người phụ trách, hoàn tất việc chăm sóc cũ và tạo việc tiếp theo theo SLA. Nếu khớp nhiều khách hàng, hệ thống yêu cầu chọn lại, không tự gộp.</p>
        <div class="form-grid">
            ${VKModal.select('customer_type', 'Loại khách hàng mới', [{value:'person',label:'Cá nhân / khách lẻ'},{value:'organization',label:'Tổ chức / doanh nghiệp'}], 'person')}
            ${VKModal.field('customer_name', 'Tên khách hàng', 'text', lead.name)}
        </div>
        <details class="crm-optional-fields"><summary>Tùy chỉnh cơ hội và liên kết khách hàng (không bắt buộc)</summary><div class="form-grid">
            ${VKModal.select('customer_id', 'Liên kết khách hàng', [{value:'',label:'Tự tìm theo điện thoại/email; chưa có thì tạo mới'}, ...customers.map(customer => ({value:customer.id,label:`${customer.code} · ${customer.name}`}))], lead.customer_id || '')}
            ${VKModal.field('deal_name', 'Tên cơ hội', 'text', `Cơ hội từ ${lead.name}`)}
            ${VKModal.field('amount', 'Giá trị dự kiến', 'number', '0')}
            ${VKModal.field('expected_close_date', 'Ngày dự kiến chốt', 'date')}
            ${VKModal.field('next_activity_at', 'Lần chăm sóc tiếp theo', 'datetime-local')}
        </div></details>
        <label class="crm-auto-note"><input type="checkbox" data-open-created-deal checked> Mở cơ hội sau khi chuyển đổi để tiếp tục xử lý</label>
    `, async (form) => {
        const openDeal = form.querySelector('[data-open-created-deal]')?.checked;
        const payload=Object.fromEntries(new FormData(form));payload.amount=VKMoney.parse(payload.amount || 0);
        const response = await VKApi.request(`/leads/${id}/qualify`, { method: 'POST', body: JSON.stringify(payload) });
        VKModal.toast('Đã liên kết khách hàng và tạo cơ hội bán hàng.');
        VKModal.close();
        if (openDeal && response.data?.deal?.id) {
            await VKLayout.navigatePjax(`/deals?open=${encodeURIComponent(response.data.deal.id)}`);
            return;
        }
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
        else { name.value = lead.name; type.value = 'person'; }
    };
    customerSelect.addEventListener('change', syncCustomerChoice);
    syncCustomerChoice();
}

window.loadLeads = loadLeads;
})();
