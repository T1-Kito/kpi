(function () {
document.addEventListener('vk:ready', () => loadRoles());
document.addEventListener('click', (event) => {
    if (!document.getElementById('rolesRoot')) return;
    if (event.target.matches('[data-create-role]')) openRoleModal();
    if (event.target.matches('[data-sync-role-permissions]')) openPermissionModal(Number(event.target.dataset.syncRolePermissions));
});
document.addEventListener('change', (event) => {
    const modal = event.target.closest('.role-permission-modal');
    if (!modal || !event.target.matches('[data-role-select-all], [data-role-group-all], input[name="permission_ids"]')) return;
    if (event.target.matches('[data-role-select-all]')) {
        modal.querySelectorAll('.role-permission-group:not([hidden]) input[name="permission_ids"]').forEach(input => { input.checked = event.target.checked; });
    } else if (event.target.matches('[data-role-group-all]')) {
        event.target.closest('.role-permission-group').querySelectorAll('input[name="permission_ids"]').forEach(input => { input.checked = event.target.checked; });
    }
    updatePermissionSelection(modal);
});

let roleState = { rows: [], permissions: [] };
document.addEventListener('input', event => {
    if (!event.target.matches('[data-role-search]')) return;
    const term = event.target.value.trim().toLocaleLowerCase('vi');
    event.target.closest('.role-permission-modal').querySelectorAll('.role-permission-group').forEach(group => {
        group.hidden = Boolean(term && !group.textContent.toLocaleLowerCase('vi').includes(term));
        if (term && !group.hidden) group.open = true;
    });
});
const rolePermissionGroups = [
    { title: 'Báo giá', prefix: 'sales.quotation.', actions: ['view', 'create', 'edit', 'delete'] },
    { title: 'Đơn hàng', prefix: 'sales.order.', actions: ['view', 'create', 'edit', 'delete'] },
    { title: 'Cơ hội bán hàng', prefix: 'sales.deal.' },
    { title: 'Hợp đồng', prefix: 'sales.contract.' },
    { title: 'Khách hàng tiềm năng', prefix: 'sales.lead.' },
    { title: 'Giao hàng', prefix: 'sales.delivery.' },
    { title: 'Khách hàng & dữ liệu nền', prefix: 'master.' },
    { title: 'Chỉnh sửa khách hàng', prefix: 'customer.' },
    { title: 'Chỉnh sửa nhà cung cấp', prefix: 'supplier.' },
    { title: 'Chỉnh sửa sản phẩm', prefix: 'product.' },
    { title: 'Chỉnh sửa danh mục kho', prefix: 'warehouse.' },
    { title: 'Thiết lập bán hàng', prefix: 'sales.master.' },
    { title: 'Công việc', prefix: 'task.' },
    { title: 'Cảnh báo', prefix: 'alert.' },
    { title: 'Hóa đơn', prefix: 'finance.invoice.' },
    { title: 'Thu tiền', prefix: 'finance.payment.' },
    { title: 'Nhập kho', prefix: 'inventory.receipt.' },
    { title: 'Xuất kho', prefix: 'inventory.issue.' },
    { title: 'Kiểm kê kho', prefix: 'inventory.stocktake.' },
    { title: 'Báo cáo kho', prefix: 'inventory.report.' },
    { title: 'Yêu cầu mua', prefix: 'procurement.pr.' },
    { title: 'Đơn mua', prefix: 'procurement.po.' },
    { title: 'Báo giá nhà cung cấp', prefix: 'procurement.sq.' },
    { title: 'Báo cáo mua hàng', prefix: 'procurement.report.' },
    { title: 'Báo cáo bán hàng', prefix: 'sales.report.' },
    { title: 'Báo cáo tài chính', prefix: 'finance.report.' },
    { title: 'Tổng quan điều hành', prefix: 'dashboard.' },
    { title: 'Chiến dịch tiếp thị', prefix: 'marketing.' },
    { title: 'Duyệt biên lợi nhuận', prefix: 'sales.margin.' },
    { title: 'Dịch vụ & bảo hành', prefix: 'service.ticket.' },
    { title: 'KPI', prefix: 'kpi.' },
    { title: 'Quản trị', codes: ['user.manage', 'role.manage', 'audit.view'] },
];

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
                ${renderPermissionTree([])}
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
    }, { className: 'role-permission-modal' });
    updatePermissionSelection(document.querySelector('.role-permission-modal'));
}

function openPermissionModal(roleId) {
    const role = roleState.rows.find(row => row.id === roleId);
    if (!role) return;
    const selected = (role.permissions || []).map(item => item.id);

    VKModal.open(`Gán quyền cho ${role.name}`, `
        <div class="field">
            <label>Quyền truy cập</label>
            ${renderPermissionTree(selected)}
        </div>
    `, async (form) => {
        await VKApi.request(`/roles/${roleId}/permissions`, {
            method: 'POST',
            body: JSON.stringify({ permission_ids: checkedValues(form, 'permission_ids') }),
        });
        VKModal.toast('Đã cập nhật quyền cho vai trò.');
        VKModal.close();
        loadRoles();
    }, { className: 'role-permission-modal' });
    updatePermissionSelection(document.querySelector('.role-permission-modal'));
}

function renderPermissionTree(selectedIds) {
    const used = new Set();
    const groups = rolePermissionGroups.map(group => {
        const items = roleState.permissions.filter(item => group.codes ? group.codes.includes(item.code) : item.code.startsWith(group.prefix));
        items.forEach(item => used.add(item.code));
        return { ...group, items };
    }).filter(group => group.items.length);
    const others = roleState.permissions.filter(item => !used.has(item.code));
    if (others.length) groups.push({ title: 'Quyền khác', items: others });
    return `<div class="role-permission-search"><input type="search" data-role-search aria-label="Tìm nhóm quyền" placeholder="Tìm chức năng hoặc quyền…"></div><div class="role-permission-toolbar"><label><input type="checkbox" data-role-select-all><span>Chọn quyền trong nhóm đang hiển thị</span></label><small data-role-total-count></small></div><p class="role-permission-help">Phạm vi dữ liệu quyết định được xem hồ sơ nào; quyền quyết định được làm gì. Chọn hàng loạt chỉ áp dụng cho các nhóm đang hiển thị; nhóm bị ẩn giữ nguyên lựa chọn. Kiểm tra riêng quyền Xóa và Quản trị, chỉ cấp quyền cần thiết.</p><div class="role-permission-tree">${groups.map(group => {
        const count = group.items.filter(item => selectedIds.includes(item.id)).length;
        const items = group.actions ? [...group.actions.map(action => group.items.find(item => item.code === `${group.prefix}${action}`)).filter(Boolean), ...group.items.filter(item => !group.actions.some(action => item.code === `${group.prefix}${action}`))] : group.items;
        return `<details class="role-permission-group" ${count ? 'open' : ''}><summary><span class="role-permission-plus" aria-hidden="true">+</span><strong>${VKTable.escapeHtml(group.title)}</strong><small data-role-group-count>${count}/${group.items.length} quyền</small></summary><div class="role-permission-options"><div class="role-permission-group-select"><label><input type="checkbox" data-role-group-all><span>Chọn tất cả trong nhóm</span></label></div>${items.map(item => {
            const action = item.code.split('.').pop();
            const translated = VKTable.translatePermission(item.code);
            const label = translated && translated !== item.code ? translated : (item.name && item.name !== item.code ? item.name : 'Quyền mở rộng chưa có tên');
            return `<label class="${action === 'delete' || ['role.manage','user.manage'].includes(item.code) ? 'role-sensitive-permission' : ''}" title="${VKTable.escapeHtml(item.code)}"><input type="checkbox" name="permission_ids" value="${item.id}" ${selectedIds.includes(item.id) ? 'checked' : ''}><span>${VKTable.escapeHtml(label)}</span></label>`;
        }).join('')}</div></details>`;
    }).join('')}</div>`;
}

function updatePermissionSelection(modal) {
    if (!modal) return;
    const permissions = [...modal.querySelectorAll('input[name="permission_ids"]')];
    const selected = permissions.filter(input => input.checked).length;
    const selectAll = modal.querySelector('[data-role-select-all]');
    selectAll.checked = permissions.length > 0 && selected === permissions.length;
    selectAll.indeterminate = selected > 0 && selected < permissions.length;
    modal.querySelector('[data-role-total-count]').textContent = `${selected}/${permissions.length} quyền`;
    modal.querySelectorAll('.role-permission-group').forEach(group => {
        const inputs = [...group.querySelectorAll('input[name="permission_ids"]')];
        const count = inputs.filter(input => input.checked).length;
        const groupSelect = group.querySelector('[data-role-group-all]');
        groupSelect.checked = inputs.length > 0 && count === inputs.length;
        groupSelect.indeterminate = count > 0 && count < inputs.length;
        group.querySelector('[data-role-group-count]').textContent = `${count}/${inputs.length} quyền`;
    });
}

function checkedValues(form, name) {
    return Array.from(form.querySelectorAll(`input[name="${name}"]:checked`)).map(item => Number(item.value));
}

function summaryCard(label, value, note) {
    return `<section class="summary-card"><span>${label}</span><strong>${value}</strong><small>${note}</small></section>`;
}

window.loadRoles = loadRoles;
})();
