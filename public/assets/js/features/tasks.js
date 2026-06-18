(function () {
let taskState = {
    rows: [],
    q: '',
    status: '',
    priority: '',
    assignee: '',
    due: '',
    tab: 'all',
    selectedId: null,
    view: 'list',
    detail: null,
    detailTab: 'overview',
    detailCache: {},
};

document.addEventListener('vk:ready', () => loadTasks());

document.addEventListener('input', (event) => {
    if (!document.getElementById('tasksRoot')) return;
    if (event.target.matches('[data-task-search]')) {
        taskState.q = event.target.value.toLowerCase();
        renderTasks();
    }
});

document.addEventListener('change', (event) => {
    if (!document.getElementById('tasksRoot')) return;
    if (event.target.matches('[data-task-status]')) taskState.status = event.target.value;
    if (event.target.matches('[data-task-priority]')) taskState.priority = event.target.value;
    if (event.target.matches('[data-task-assignee]')) taskState.assignee = event.target.value;
    if (event.target.matches('[data-task-due]')) taskState.due = event.target.value;
    renderTasks();
});

document.addEventListener('click', (event) => {
    if (!document.getElementById('tasksRoot')) return;

    if (event.target.matches('[data-create-task]')) openTaskModal();

    if (event.target.matches('[data-back-task-list]')) {
        taskState.view = 'list';
        taskState.selectedId = null;
        taskState.detail = null;
        renderTasks();
        return;
    }

    if (event.target.matches('[data-task-page-tab]')) {
        taskState.detailTab = event.target.dataset.taskPageTab;
        renderTaskDetailPage();
        return;
    }

    if (event.target.matches('[data-task-tab]')) {
        taskState.tab = event.target.dataset.taskTab;
        taskState.status = '';
        taskState.due = '';
        renderTasks();
        return;
    }

    if (event.target.matches('[data-task-change-status]')) {
        event.stopPropagation();
        updateTaskStatus(event.target.dataset.taskChangeStatus, event.target.dataset.status);
        return;
    }

    if (event.target.matches('[data-task-detail]')) {
        event.stopPropagation();
        openTaskDetail(event.target.dataset.taskDetail);
        return;
    }

    if (event.target.closest('.row-action-menu')) return;
    const detail = event.target.closest('[data-task-detail], tr[data-row-detail]');
    if (detail && !event.target.closest('button')) {
        openTaskDetail(detail.dataset.taskDetail || detail.dataset.rowDetail);
    }
});

async function loadTasks(options = {}) {
    if (!document.getElementById('tasksRoot')) return;
    if (options.source === 'pjax') {
        taskState.view = 'list';
        taskState.selectedId = null;
        taskState.detail = null;
        taskState.detailTab = 'overview';
    }
    const tasks = await VKApi.request('/tasks?page_size=100');
    taskState.rows = tasks.data || [];
    if (taskState.view === 'detail' && taskState.selectedId) {
        await openTaskDetail(taskState.selectedId);
        return;
    }
    renderTasks(tasks.meta.total);
}

function renderTasks(total = taskState.rows.length) {
    const rows = filterRows();
    document.getElementById('tasksRoot').innerHTML = `
        ${renderTaskStats()}
        ${renderTaskFilters()}
        ${renderTaskTabs()}
        <section class="task-board-panel">
            ${renderTaskTable(rows)}
            <div class="task-table-footer">
                <span>Hiển thị ${VKTable.money(rows.length)} / ${VKTable.money(total)} công việc</span>
                <div>
                    <select aria-label="Số dòng mỗi trang">
                        <option>20 / trang</option>
                    </select>
                    <button class="btn small" type="button" disabled>‹</button>
                    <button class="btn small primary" type="button">1</button>
                    <button class="btn small" type="button" disabled>›</button>
                </div>
            </div>
        </section>
    `;
    restoreFilters();
}

function renderTaskStats() {
    const overdue = taskState.rows.filter(row => isOverdue(row)).length;
    const today = taskState.rows.filter(row => isToday(row.due_at)).length;
    const week = taskState.rows.filter(row => isThisWeek(row.due_at)).length;
    const completed = taskState.rows.filter(row => row.status === 'completed').length;

    return `
        <div class="task-stat-row">
            ${taskStat('Quá hạn', overdue, 'Cần xử lý ngay', 'danger')}
            ${taskStat('Hôm nay', today, 'Đến hạn hôm nay', 'warning')}
            ${taskStat('Tuần này', week, 'Đến hạn trong tuần', 'info')}
            ${taskStat('Hoàn thành', completed, 'Đã xử lý', 'success')}
            ${taskStat('Tất cả', taskState.rows.length, 'Công việc', 'neutral')}
        </div>
    `;
}

function taskStat(label, value, note, tone) {
    return `
        <section class="task-stat-card ${tone}">
            <span class="task-stat-icon"></span>
            <div>
                <span>${label}</span>
                <strong>${VKTable.money(value)}</strong>
                <small>${note}</small>
            </div>
        </section>
    `;
}

function renderTaskFilters() {
    const assignees = uniqueBy(taskState.rows.map(row => row.assignee).filter(Boolean), 'id');
    return `
        <div class="task-filter-bar">
            <label class="list-search">
                <span aria-hidden="true">⌕</span>
                <input type="search" data-task-search placeholder="Tìm mã, tiêu đề, khách hàng..." value="${VKTable.escapeHtml(taskState.q)}">
            </label>
            <select data-task-status>
                ${option('', 'Tất cả trạng thái', taskState.status)}
                ${option('new', 'Mới', taskState.status)}
                ${option('in_progress', 'Đang xử lý', taskState.status)}
                ${option('overdue', 'Quá hạn', taskState.status)}
                ${option('completed', 'Hoàn thành', taskState.status)}
                ${option('cancelled', 'Đã hủy', taskState.status)}
            </select>
            <select data-task-priority>
                ${option('', 'Ưu tiên: Tất cả', taskState.priority)}
                ${option('urgent', 'Khẩn cấp', taskState.priority)}
                ${option('high', 'Cao', taskState.priority)}
                ${option('normal', 'Trung bình', taskState.priority)}
                ${option('low', 'Thấp', taskState.priority)}
            </select>
            <select data-task-assignee>
                ${option('', 'Người phụ trách: Tất cả', taskState.assignee)}
                ${assignees.map(user => option(String(user.id), user.name, taskState.assignee)).join('')}
            </select>
            <select data-task-due>
                ${option('', 'Hạn xử lý', taskState.due)}
                ${option('overdue', 'Quá hạn', taskState.due)}
                ${option('today', 'Hôm nay', taskState.due)}
                ${option('week', 'Tuần này', taskState.due)}
                ${option('none', 'Chưa có hạn', taskState.due)}
            </select>
        </div>
    `;
}

function renderTaskTabs() {
    const counts = {
        all: taskState.rows.length,
        overdue: taskState.rows.filter(row => isOverdue(row)).length,
        today: taskState.rows.filter(row => isToday(row.due_at)).length,
        week: taskState.rows.filter(row => isThisWeek(row.due_at)).length,
        pending: taskState.rows.filter(row => ['new', 'in_progress', 'overdue'].includes(row.status)).length,
        completed: taskState.rows.filter(row => row.status === 'completed').length,
    };
    const tabs = [
        ['all', 'Tất cả'],
        ['overdue', 'Quá hạn'],
        ['today', 'Hôm nay'],
        ['week', 'Tuần này'],
        ['pending', 'Chờ xử lý'],
        ['completed', 'Hoàn thành'],
    ];

    return `<div class="task-tabs">
        ${tabs.map(([key, label]) => `
            <button class="${taskState.tab === key ? 'active' : ''}" type="button" data-task-tab="${key}">
                ${label} <span>${VKTable.money(counts[key])}</span>
            </button>
        `).join('')}
    </div>`;
}

function renderTaskTable(rows) {
    return VKTable.renderTable([
        { label: '', render: row => `<input type="checkbox" ${taskState.selectedId === row.id ? 'checked' : ''} aria-label="Chọn công việc">` },
        { label: 'Mã', render: row => `<span class="mono">${VKTable.escapeHtml(row.code)}</span>` },
        { label: 'Công việc', render: row => renderTaskInfo(row) },
        { label: 'Phụ trách', render: row => renderOwner(row) },
        { label: 'Hạn xử lý', render: row => renderDue(row) },
        { label: 'Ưu tiên', render: row => VKTable.statusBadge(row.priority) },
        { label: 'Trạng thái', render: row => VKTable.statusBadge(row.status) },
        { label: '', className: 'actions-cell', render: row => renderTaskActions(row) },
    ], rows, 'Chưa có công việc', { rowAttr: row => `data-row-detail="${row.id}" class="${taskState.selectedId === row.id ? 'selected' : ''}"` });
}

function filterRows() {
    return taskState.rows.filter(row => {
        const haystack = `${row.code || ''} ${row.title || ''} ${row.task_type || ''} ${row.assignee?.name || ''} ${row.department?.name || ''} ${row.source_type || ''}`.toLowerCase();
        const matchText = !taskState.q || haystack.includes(taskState.q);
        const matchStatus = !taskState.status || row.status === taskState.status;
        const matchPriority = !taskState.priority || row.priority === taskState.priority;
        const matchAssignee = !taskState.assignee || String(row.assignee?.id || '') === taskState.assignee;
        const matchDue = !taskState.due || matchDueFilter(row, taskState.due);
        const matchTab = matchTabFilter(row, taskState.tab);
        return matchText && matchStatus && matchPriority && matchAssignee && matchDue && matchTab;
    });
}

function restoreFilters() {
    const search = document.querySelector('[data-task-search]');
    const status = document.querySelector('[data-task-status]');
    const priority = document.querySelector('[data-task-priority]');
    const assignee = document.querySelector('[data-task-assignee]');
    const due = document.querySelector('[data-task-due]');
    if (search) search.value = taskState.q;
    if (status) status.value = taskState.status;
    if (priority) priority.value = taskState.priority;
    if (assignee) assignee.value = taskState.assignee;
    if (due) due.value = taskState.due;
}

function renderTaskInfo(row) {
    const source = row.source_type ? `${sourceLabel(row.source_type)} #${row.source_id || '-'}` : 'Thủ công';
    return `<strong>${VKTable.escapeHtml(row.title)}</strong><span class="row-note">${VKTable.translateType(row.task_type)} · ${VKTable.escapeHtml(source)}</span>`;
}

function renderOwner(row) {
    const name = row.assignee?.name || 'Chưa phân công';
    const initials = getInitials(name);
    return `
        <span class="task-owner">
            <span class="task-avatar">${VKTable.escapeHtml(initials)}</span>
            <span>
                <strong>${VKTable.escapeHtml(name)}</strong>
                <small>${VKTable.escapeHtml(row.department?.name || 'Chưa gắn phòng ban')}</small>
            </span>
        </span>
    `;
}

function renderDue(row) {
    if (!row.due_at) return '<span class="badge inactive">Chưa có hạn</span>';
    const due = new Date(row.due_at);
    const late = isOverdue(row);
    const today = isToday(row.due_at);
    return `<span class="task-due ${late ? 'danger' : today ? 'warning' : 'info'}">${due.toLocaleString('vi-VN')}${late ? '<small>Quá hạn</small>' : today ? '<small>Hôm nay</small>' : ''}</span>`;
}

function renderTaskActions(row) {
    const actions = [];
    if (row.status === 'new') actions.push(VKTable.smallButton('Bắt đầu', `data-task-change-status="${row.id}" data-status="in_progress"`));
    if (['new', 'in_progress', 'overdue'].includes(row.status)) {
        actions.push(VKTable.smallButton('Hoàn thành', `data-task-change-status="${row.id}" data-status="completed"`, 'primary'));
    }
    actions.push(VKTable.smallButton('Mở', `data-task-detail="${row.id}"`));
    return VKTable.rowActions(actions);
}

async function openTaskModal() {
    const [users, departments] = await Promise.all([
        VKApi.request('/lookups/users'),
        VKApi.request('/lookups/departments'),
    ]);

    VKModal.open('Tạo công việc', `
        <div class="form-grid">
            ${VKModal.field('title', 'Tiêu đề')}
            ${VKModal.select('assignee_id', 'Người phụ trách', [
                { value: '', label: 'Chưa phân công' },
                ...users.data.map(user => ({ value: user.id, label: `${user.name} - ${user.email}` })),
            ])}
            ${VKModal.select('department_id', 'Phòng ban', [
                { value: '', label: 'Chưa gắn phòng ban' },
                ...departments.data.map(department => ({ value: department.id, label: `${department.code} - ${department.name}` })),
            ])}
            ${VKModal.select('priority', 'Ưu tiên', [
                { value: 'low', label: 'Thấp' },
                { value: 'normal', label: 'Bình thường' },
                { value: 'high', label: 'Cao' },
                { value: 'urgent', label: 'Khẩn cấp' },
            ])}
            ${VKModal.select('task_type', 'Loại công việc', [
                { value: 'manual', label: 'Thủ công' },
                { value: 'lead_follow_up', label: 'Chăm sóc khách hàng tiềm năng' },
                { value: 'warehouse_issue', label: 'Xuất kho' },
                { value: 'purchase_request', label: 'Yêu cầu mua hàng' },
            ])}
            ${VKModal.select('module', 'Phân hệ', [
                { value: 'general', label: 'Chung' },
                { value: 'sales', label: 'Bán hàng' },
                { value: 'inventory', label: 'Kho' },
                { value: 'procurement', label: 'Mua hàng' },
            ])}
            ${VKModal.field('due_at', 'Hạn xử lý', 'datetime-local')}
            <div class="field full"><label for="description">Mô tả</label><textarea id="description" name="description"></textarea></div>
        </div>
    `, async (form) => {
        const data = Object.fromEntries(new FormData(form));
        await VKApi.request('/tasks', {
            method: 'POST',
            body: JSON.stringify({
                ...data,
                assignee_id: data.assignee_id ? Number(data.assignee_id) : null,
                department_id: data.department_id ? Number(data.department_id) : null,
                due_at: data.due_at || null,
            }),
        });
        VKModal.toast('Đã tạo công việc.');
        VKModal.close();
        loadTasks();
    });
}

async function updateTaskStatus(id, status) {
    await VKApi.request(`/tasks/${id}/status`, {
        method: 'POST',
        body: JSON.stringify({ status, reason: `Cập nhật từ màn hình công việc: ${VKTable.translateStatus(status)}` }),
    });
    VKModal.toast('Đã cập nhật trạng thái công việc.');
    loadTasks();
}

async function openTaskDetail(id) {
    taskState.view = 'detail';
    taskState.selectedId = Number(id);
    taskState.detailTab = 'overview';

    const cached = taskState.detailCache[id] || taskState.rows.find(row => String(row.id) === String(id));
    if (cached) {
        taskState.detail = normalizeTaskDetail(cached);
        renderTaskDetailPage();
    }

    try {
        const response = await VKApi.request(`/tasks/${id}`);
        taskState.detailCache[id] = response.data;
        taskState.detail = normalizeTaskDetail(response.data);
        renderTaskDetailPage();
    } catch (error) {
        VKModal.toast(error.message || 'Không tải được chi tiết công việc.');
        if (!cached) {
            taskState.view = 'list';
            taskState.selectedId = null;
            renderTasks();
        }
    }
}

function normalizeTaskDetail(row) {
    return {
        ...row,
        history: row.history || [],
    };
}

function renderTaskDetailPage() {
    const root = document.getElementById('tasksRoot');
    const row = taskState.detail;
    if (!root || !row) return;

    root.innerHTML = `
        <section class="record-page task-record-page">
            ${renderTaskDetailHead(row)}
            ${renderTaskDetailTabs(row)}
            <div class="record-page-body">
                ${renderTaskDetailContent(row)}
            </div>
        </section>
    `;
}

function renderTaskDetailHead(row) {
    return `
        <div class="record-page-head">
            <button class="btn small" type="button" data-back-task-list>Quay lại danh sách</button>
            <div class="record-page-title">
                <span>Công việc</span>
                <h2>${VKTable.escapeHtml(row.code || '-')} ${VKTable.statusBadge(row.status || 'new')}</h2>
                <p><strong>${VKTable.escapeHtml(row.title || '-')}</strong></p>
            </div>
            <div class="record-page-meta">
                <div><span>Ngày tạo</span><strong>${formatTaskDate(row.created_at)}</strong></div>
                <div><span>Hạn xử lý</span><strong>${formatTaskDate(row.due_at)}</strong></div>
            </div>
            <div class="record-page-actions">${renderTaskPageActions(row)}</div>
        </div>
    `;
}

function renderTaskPageActions(row) {
    const actions = [];
    if (row.status === 'new') {
        actions.push(`<button class="btn small" type="button" data-task-change-status="${row.id}" data-status="in_progress">Bắt đầu</button>`);
    }
    if (['new', 'in_progress', 'overdue'].includes(row.status)) {
        actions.push(`<button class="btn primary small" type="button" data-task-change-status="${row.id}" data-status="completed">Hoàn thành</button>`);
    }
    return actions.join('');
}

function renderTaskDetailTabs(row) {
    const tabs = [
        ['overview', 'Thông tin'],
        ['history', `Lịch sử (${row.history?.length || 0})`],
        ['files', 'Tệp đính kèm'],
        ['notes', 'Ghi chú'],
    ];

    return `
        <div class="record-tabs">
            ${tabs.map(([key, label]) => `
                <button class="${taskState.detailTab === key ? 'active' : ''}" type="button" data-task-page-tab="${key}">
                    ${VKTable.escapeHtml(label)}
                </button>
            `).join('')}
        </div>
    `;
}

function renderTaskDetailContent(row) {
    if (taskState.detailTab === 'history') return renderTaskHistoryPage(row.history || []);
    if (taskState.detailTab === 'files') return renderTaskEmptyPanel('Tệp đính kèm', 'Chưa có tệp đính kèm');
    if (taskState.detailTab === 'notes') {
        return row.description
            ? `<section class="record-panel"><h3>Mô tả công việc</h3><p class="task-description">${VKTable.escapeHtml(row.description)}</p></section>`
            : renderTaskEmptyPanel('Ghi chú', 'Chưa có ghi chú');
    }

    const source = row.source_type ? `${sourceLabel(row.source_type)} #${row.source_id || '-'}` : 'Thủ công';
    const fields = [
        ['Mã công việc', row.code || '-'],
        ['Trạng thái', VKTable.statusBadge(row.status || 'new'), true],
        ['Tiêu đề', row.title || '-'],
        ['Ưu tiên', VKTable.statusBadge(row.priority || 'normal'), true],
        ['Người phụ trách', row.assignee?.name || 'Chưa phân công'],
        ['Phòng ban', row.department?.name || 'Chưa gắn phòng ban'],
        ['Loại công việc', VKTable.translateType(row.task_type)],
        ['Nguồn phát sinh', source],
        ['Ngày tạo', formatTaskDate(row.created_at)],
        ['Hạn xử lý', formatTaskDate(row.due_at)],
    ];

    return `
        <section class="record-panel task-info-panel">
            <h3>Thông tin công việc</h3>
            <div class="sales-order-info-grid">
                ${fields.map(([label, value, html]) => `
                    <div>
                        <span>${VKTable.escapeHtml(label)}</span>
                        <strong>${html ? value : VKTable.escapeHtml(value)}</strong>
                    </div>
                `).join('')}
            </div>
        </section>
        ${row.description ? `<section class="record-panel"><h3>Mô tả</h3><p class="task-description">${VKTable.escapeHtml(row.description)}</p></section>` : ''}
    `;
}

function renderTaskHistoryPage(rows) {
    if (!rows.length) return renderTaskEmptyPanel('Lịch sử', 'Chưa có lịch sử cập nhật');
    return `
        <section class="record-panel">
            <h3>Lịch sử cập nhật</h3>
            <div class="record-timeline">
                ${rows.map(item => `
                    <div class="${taskTimelineTone(item.to_status)}">
                        <i></i>
                        <span>
                            <strong>${VKTable.escapeHtml(VKTable.translateStatus(item.from_status || 'new'))} → ${VKTable.escapeHtml(VKTable.translateStatus(item.to_status))}</strong>
                            <small>${VKTable.escapeHtml(item.reason || 'Cập nhật trạng thái')} · ${formatTaskDate(item.created_at)}</small>
                        </span>
                    </div>
                `).join('')}
            </div>
        </section>
    `;
}

function renderTaskEmptyPanel(title, message) {
    return `<section class="record-panel"><h3>${VKTable.escapeHtml(title)}</h3><div class="empty compact"><strong>${VKTable.escapeHtml(message)}</strong></div></section>`;
}

function formatTaskDate(value) {
    if (!value) return '-';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return '-';
    return date.toLocaleString('vi-VN', {
        hour: '2-digit',
        minute: '2-digit',
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
    });
}

function taskTimelineTone(status) {
    if (status === 'completed') return 'done';
    if (['overdue', 'cancelled'].includes(status)) return 'risk';
    return 'waiting';
}

function matchTabFilter(row, tab) {
    if (tab === 'overdue') return isOverdue(row);
    if (tab === 'today') return isToday(row.due_at);
    if (tab === 'week') return isThisWeek(row.due_at);
    if (tab === 'pending') return ['new', 'in_progress', 'overdue'].includes(row.status);
    if (tab === 'completed') return row.status === 'completed';
    return true;
}

function matchDueFilter(row, due) {
    if (due === 'overdue') return isOverdue(row);
    if (due === 'today') return isToday(row.due_at);
    if (due === 'week') return isThisWeek(row.due_at);
    if (due === 'none') return !row.due_at;
    return true;
}

function isOverdue(row) {
    if (!row.due_at || row.status === 'completed') return false;
    return new Date(row.due_at).getTime() < Date.now() || row.status === 'overdue';
}

function isToday(value) {
    if (!value) return false;
    const date = new Date(value);
    const now = new Date();
    return date.getFullYear() === now.getFullYear() && date.getMonth() === now.getMonth() && date.getDate() === now.getDate();
}

function isThisWeek(value) {
    if (!value) return false;
    const date = new Date(value);
    const now = new Date();
    const start = new Date(now);
    start.setDate(now.getDate() - now.getDay() + 1);
    start.setHours(0, 0, 0, 0);
    const end = new Date(start);
    end.setDate(start.getDate() + 7);
    return date >= start && date < end;
}

function option(value, label, current) {
    return `<option value="${VKTable.escapeHtml(value)}" ${String(value) === String(current) ? 'selected' : ''}>${VKTable.escapeHtml(label)}</option>`;
}

function uniqueBy(rows, key) {
    const map = new Map();
    rows.forEach(row => {
        if (row?.[key] !== undefined && !map.has(row[key])) map.set(row[key], row);
    });
    return [...map.values()];
}

function getInitials(value) {
    return String(value || 'U').trim().split(/\s+/).slice(-2).map(part => part.charAt(0)).join('').toUpperCase() || 'U';
}

function sourceLabel(value) {
    return VKTable.translateEntity(value);
}

window.loadTasks = loadTasks;
})();
