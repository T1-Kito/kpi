document.addEventListener('vk:ready', () => loadRoles());
document.addEventListener('click', (event) => {
    if (!document.getElementById('rolesRoot')) return;
    if (event.target.matches('[data-create-role]')) openRoleModal();
    if (event.target.matches('[data-sync-role-permissions]')) openPermissionModal(Number(event.target.dataset.syncRolePermissions));
});

let roleState = { rows: [], permissions: [] };

async function loadRoles() {
    if (!document.getElementById('rolesRoot')) return;
    const roles = await VKApi.request('/roles');
    roleState = { rows: roles.data, permissions: roles.meta.permissions || [] };

    const companyScope = roles.data.filter(row => row.data_scope === 'company').length;
    const permissionLinks = roles.data.reduce((sum, row) => sum + (row.permissions?.length || 0), 0);

    document.getElementById('rolesRoot').innerHTML = `
        <div class="module-summary">
            ${summaryCard('Tổng vai trò', roles.meta.total, 'Theo công ty hiện tại')}
            ${summaryCard('Quyền hệ thống', roleState.permissions.length, 'Tập quyền có sẵn')}
            ${summaryCard('Vai trò toàn công ty', companyScope, 'Phạm vi dữ liệu rộng nhất')}
            ${summaryCard('Lượt gán quyền', permissionLinks, 'Tổng quyền trên vai trò')}
        </div>
        <section class="module-panel">
            <div class="panel-head">
                <div>
                    <h2>Danh sách vai trò</h2>
                    <span>Quản lý phạm vi dữ liệu và quyền thao tác theo từng vai trò.</span>
                </div>
            </div>
            ${renderRoleTable(roles.data)}
        </section>
    `;
}

function renderRoleTable(rows) {
    return VKTable.renderTable([
        { label: 'Vai trò', render: row => renderRole(row) },
        { label: 'Phạm vi', render: row => scopeBadge(row.data_scope) },
        { label: 'Số quyền', render: row => `<strong>${row.permissions?.length || 0}</strong>` },
        { label: 'Quyền tiêu biểu', render: row => renderPermissions(row.permissions) },
        { label: 'Thao tác', render: row => `<button class="btn" type="button" data-sync-role-permissions="${row.id}">Gán quyền</button>` },
    ], rows, 'Chưa có vai trò');
}

function renderRole(row) {
    return `<strong title="${VKTable.escapeHtml(row.code)}">${VKTable.escapeHtml(row.name)}</strong>`;
}

function renderPermissions(permissions = []) {
    if (!permissions.length) return '<span class="badge warning">Chưa có quyền</span>';
    return permissions.slice(0, 5).map(item => `<span class="badge info" title="${VKTable.escapeHtml(item.code)}">${VKTable.escapeHtml(VKTable.translatePermission(item.code) || item.name)}</span>`).join(' ') + (permissions.length > 5 ? `<span class="row-note">+${permissions.length - 5} quyền khác</span>` : '');
}

function scopeBadge(scope) {
    const labels = {
        own: 'Cá nhân',
        department: 'Phòng ban',
        warehouse: 'Kho',
        company: 'Toàn công ty',
    };
    return `<span class="badge info">${labels[scope] || scope || '-'}</span>`;
}

function openRoleModal() {
    VKModal.open('Tạo vai trò', `
        <div class="form-grid">
            ${VKModal.field('name', 'Tên vai trò')}
            ${VKModal.select('data_scope', 'Phạm vi dữ liệu', [
                { value: 'own', label: 'Cá nhân' },
                { value: 'department', label: 'Phòng ban' },
                { value: 'warehouse', label: 'Kho' },
                { value: 'company', label: 'Toàn công ty' },
            ])}
            <div class="field full">
                <label>Quyền truy cập</label>
                ${renderCheckboxList('permission_ids', roleState.permissions, [])}
            </div>
        </div>
    `, async (form) => {
        const data = Object.fromEntries(new FormData(form));
        await VKApi.request('/roles', {
            method: 'POST',
            body: JSON.stringify({
                name: data.name,
                data_scope: data.data_scope,
                permission_ids: checkedValues(form, 'permission_ids'),
            }),
        });
        VKModal.toast('Đã tạo vai trò.');
        VKModal.close();
        loadRoles();
    });
}

function openPermissionModal(roleId) {
    const role = roleState.rows.find(row => row.id === roleId);
    if (!role) return;
    const selected = (role.permissions || []).map(item => item.id);

    VKModal.open(`Gán quyền cho ${role.name}`, `
        <div class="field">
            <label>Quyền truy cập</label>
            ${renderCheckboxList('permission_ids', roleState.permissions, selected)}
        </div>
    `, async (form) => {
        await VKApi.request(`/roles/${roleId}/permissions`, {
            method: 'POST',
            body: JSON.stringify({ permission_ids: checkedValues(form, 'permission_ids') }),
        });
        VKModal.toast('Đã cập nhật quyền cho vai trò.');
        VKModal.close();
        loadRoles();
    });
}

function renderCheckboxList(name, items, selectedIds) {
    return `<div class="checkbox-grid">${items.map(item => `
        <label>
            <input type="checkbox" name="${name}" value="${item.id}" ${selectedIds.includes(item.id) ? 'checked' : ''}>
            <span>${VKTable.escapeHtml(VKTable.translatePermission(item.code) || item.name)}</span>
        </label>
    `).join('')}</div>`;
}

function checkedValues(form, name) {
    return Array.from(form.querySelectorAll(`input[name="${name}"]:checked`)).map(item => Number(item.value));
}

function summaryCard(label, value, note) {
    return `<section class="summary-card"><span>${label}</span><strong>${value}</strong><small>${note}</small></section>`;
}
