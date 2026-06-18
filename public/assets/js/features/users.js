(function () {
let userState = { rows: [], departments: [], positions: [], roles: [], q: '', status: '' };

document.addEventListener('vk:ready', () => loadUsers());
document.addEventListener('input', (event) => {
    if (!document.getElementById('usersRoot')) return;
    if (event.target.matches('[data-list-search]')) {
        userState.q = event.target.value.toLowerCase();
        renderUsers();
    }
});
document.addEventListener('change', (event) => {
    if (!document.getElementById('usersRoot')) return;
    if (event.target.matches('[data-list-status]')) {
        userState.status = event.target.value;
        renderUsers();
    }
});
document.addEventListener('click', (event) => {
    if (!document.getElementById('usersRoot')) return;
    if (event.target.matches('[data-create-user]')) openUserModal();
    if (event.target.matches('[data-update-user-organization]')) openUserOrganizationModal(Number(event.target.dataset.updateUserOrganization));
    if (event.target.matches('[data-sync-user-roles]')) openRoleAssignModal(Number(event.target.dataset.syncUserRoles));
    if (event.target.matches('[data-user-status]')) updateUserStatus(event.target.dataset.userStatus, event.target.dataset.active === '1');
    if (event.target.closest('.row-action-menu')) return;
    const detail = event.target.closest('[data-user-detail], tr[data-row-detail]');
    if (detail && !event.target.closest('button')) openUserDetail(detail.dataset.userDetail || detail.dataset.rowDetail);
});

async function loadUsers() {
    if (!document.getElementById('usersRoot')) return;
    const users = await VKApi.request('/users');
    userState.rows = users.data || [];
    userState.departments = users.meta.departments || [];
    userState.positions = users.meta.positions || [];
    userState.roles = users.meta.roles || [];
    renderUsers(users.meta.total);
}

function renderUsers(total = userState.rows.length) {
    const rows = filterRows();
    document.getElementById('usersRoot').innerHTML = VKTable.fullList({
        title: 'Danh sách người dùng',
        subtitle: 'Quản lý tài khoản, phòng ban, chức vụ và vai trò truy cập.',
        meta: `${VKTable.money(rows.length)} / ${VKTable.money(total)} người dùng`,
        filters: VKTable.filterBar({
            searchPlaceholder: 'Tìm tên, email, phòng ban, vai trò...',
            status: [
                { value: 'active', label: 'Hoạt động' },
                { value: 'inactive', label: 'Tạm khóa' },
            ],
        }),
        table: renderUserTable(rows),
    });
    restoreFilters();
}

function renderUserTable(rows) {
    return VKTable.renderTable([
        { label: 'Người dùng', render: row => renderUser(row) },
        { label: 'Phòng ban', render: row => renderOrg(row) },
        { label: 'Vai trò', render: row => renderRoles(row.roles) },
        { label: 'Trạng thái', render: row => row.is_active ? '<span class="badge success">Hoạt động</span>' : '<span class="badge inactive">Tạm khóa</span>' },
        { label: '', render: row => renderActions(row) },
    ], rows, 'Chưa có người dùng', { rowAttr: row => `data-row-detail="${row.id}"` });
}

function filterRows() {
    return userState.rows.filter(row => {
        const roles = (row.roles || []).map(role => `${role.code} ${role.name}`).join(' ');
        const haystack = `${row.name || ''} ${row.email || ''} ${row.department?.name || ''} ${row.position?.name || ''} ${roles}`.toLowerCase();
        const matchStatus = !userState.status
            || (userState.status === 'active' && row.is_active)
            || (userState.status === 'inactive' && !row.is_active);
        return (!userState.q || haystack.includes(userState.q)) && matchStatus;
    });
}

function restoreFilters() {
    const search = document.querySelector('[data-list-search]');
    const status = document.querySelector('[data-list-status]');
    if (search) search.value = userState.q;
    if (status) status.value = userState.status;
}

function renderUser(row) {
    return `<strong>${VKTable.escapeHtml(row.name)}</strong><span class="row-note">${VKTable.escapeHtml(row.email)}</span>`;
}

function renderOrg(row) {
    return `${VKTable.escapeHtml(row.department?.name || 'Chưa gắn phòng ban')}<span class="row-note">${VKTable.escapeHtml(row.position?.name || 'Chưa gắn chức vụ')}</span>`;
}

function renderRoles(roles = []) {
    if (!roles.length) return '<span class="badge warning">Chưa có vai trò</span>';
    return roles.slice(0, 3).map(role => `<span class="badge info" title="${VKTable.escapeHtml(role.code)}">${VKTable.escapeHtml(role.name || VKTable.translateRole(role.code))}</span>`).join(' ');
}

function renderActions(row) {
    return VKTable.rowActions([
        VKTable.smallButton('Tổ chức', `data-update-user-organization="${row.id}"`),
        VKTable.smallButton('Vai trò', `data-sync-user-roles="${row.id}"`),
        VKTable.smallButton(row.is_active ? 'Khóa' : 'Mở', `data-user-status="${row.id}" data-active="${row.is_active ? '0' : '1'}"`, row.is_active ? 'danger' : 'primary'),
    ]);
}

function openUserModal() {
    VKModal.open('Tạo người dùng', `
        <div class="form-grid">
            ${VKModal.field('name', 'Họ tên')}
            ${VKModal.field('email', 'Email', 'email')}
            ${VKModal.field('password', 'Mật khẩu', 'password', 'Admin@123')}
            ${VKModal.select('department_id', 'Phòng ban', blankOption().concat(userState.departments.map(item => ({ value: item.id, label: `${item.code} - ${item.name}` }))))}
            ${VKModal.select('position_id', 'Chức vụ', blankOption().concat(userState.positions.map(item => ({ value: item.id, label: `${item.code} - ${item.name}` }))))}
            <div class="field full"><label>Vai trò</label>${renderCheckboxList('role_ids', userState.roles, [])}</div>
        </div>
    `, async (form) => {
        const data = Object.fromEntries(new FormData(form));
        await VKApi.request('/users', {
            method: 'POST',
            body: JSON.stringify({
                name: data.name,
                email: data.email,
                password: data.password || 'Admin@123',
                department_id: data.department_id ? Number(data.department_id) : null,
                position_id: data.position_id ? Number(data.position_id) : null,
                role_ids: checkedValues(form, 'role_ids'),
            }),
        });
        VKModal.toast('Đã tạo người dùng.');
        VKModal.close();
        loadUsers();
    });
}

function openUserOrganizationModal(userId) {
    const user = userState.rows.find(row => row.id === userId);
    if (!user) return;
    const managerOptions = blankOption().concat(userState.rows.filter(row => row.id !== userId).map(row => ({ value: row.id, label: `${row.name} - ${row.email}` })));
    VKModal.open(`Cập nhật tổ chức cho ${user.name}`, `
        <div class="form-grid">
            ${VKModal.select('department_id', 'Phòng ban', blankOption().concat(userState.departments.map(item => ({ value: item.id, label: `${item.code} - ${item.name}` }))), user.department_id || '')}
            ${VKModal.select('position_id', 'Chức vụ', blankOption().concat(userState.positions.map(item => ({ value: item.id, label: `${item.code} - ${item.name}` }))), user.position_id || '')}
            ${VKModal.select('manager_id', 'Người quản lý', managerOptions, user.manager_id || '')}
        </div>
    `, async (form) => {
        const data = Object.fromEntries(new FormData(form));
        await VKApi.request(`/users/${userId}/organization`, {
            method: 'POST',
            body: JSON.stringify({
                department_id: data.department_id ? Number(data.department_id) : null,
                position_id: data.position_id ? Number(data.position_id) : null,
                manager_id: data.manager_id ? Number(data.manager_id) : null,
            }),
        });
        VKModal.toast('Đã cập nhật tổ chức người dùng.');
        VKModal.close();
        loadUsers();
    });
}

function openRoleAssignModal(userId) {
    const user = userState.rows.find(row => row.id === userId);
    if (!user) return;
    const selected = (user.roles || []).map(role => role.id);
    VKModal.open(`Gán vai trò cho ${user.name}`, `
        <div class="field">
            <label>Vai trò được gán</label>
            ${renderCheckboxList('role_ids', userState.roles, selected)}
        </div>
    `, async (form) => {
        await VKApi.request(`/users/${userId}/roles`, { method: 'POST', body: JSON.stringify({ role_ids: checkedValues(form, 'role_ids') }) });
        VKModal.toast('Đã cập nhật vai trò người dùng.');
        VKModal.close();
        loadUsers();
    });
}

async function updateUserStatus(id, isActive) {
    await VKApi.request(`/users/${id}/status`, { method: 'POST', body: JSON.stringify({ is_active: isActive }) });
    VKModal.toast('Đã cập nhật trạng thái người dùng.');
    loadUsers();
}

function openUserDetail(id) {
    const row = userState.rows.find(item => String(item.id) === String(id));
    if (!row) return;
    VKRecordPage.open({
        root: '#usersRoot',
        type: 'user',
        id,
        preview: { ...row, status: row.is_active ? 'active' : 'inactive', timeline: [{ label: 'Tạo người dùng', status: row.is_active ? 'active' : 'inactive', at: row.created_at }] },
        onBack: () => renderUsers(),
    });
    return;
    VKDetailDrawer.open({ type: 'user', row: { ...row, status: row.is_active ? 'active' : 'inactive', timeline: [{ label: 'Tạo người dùng', status: row.is_active ? 'active' : 'inactive', at: row.created_at }] } });
}

function renderCheckboxList(name, items, selectedIds) {
    return `<div class="checkbox-grid">${items.map(item => `
        <label>
            <input type="checkbox" name="${name}" value="${item.id}" ${selectedIds.includes(item.id) ? 'checked' : ''}>
            <span>${VKTable.escapeHtml(item.name || VKTable.translateRole(item.code))}</span>
        </label>
    `).join('')}</div>`;
}

function checkedValues(form, name) {
    return Array.from(form.querySelectorAll(`input[name="${name}"]:checked`)).map(item => Number(item.value));
}

function blankOption() {
    return [{ value: '', label: 'Chưa chọn' }];
}

window.loadUsers = loadUsers;
})();
