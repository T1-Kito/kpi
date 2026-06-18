(function () {
document.addEventListener('vk:ready', () => loadOrganization());
document.addEventListener('click', (event) => {
    if (!document.getElementById('organizationRoot')) return;
    if (event.target.matches('[data-create-department]')) openDepartmentModal();
    if (event.target.matches('[data-edit-department]')) openDepartmentModal(Number(event.target.dataset.editDepartment));
    if (event.target.matches('[data-create-position]')) openPositionModal();
    if (event.target.matches('[data-edit-position]')) openPositionModal(Number(event.target.dataset.editPosition));
});

let organizationState = { departments: [], positions: [], users: [] };

async function loadOrganization() {
    if (!document.getElementById('organizationRoot')) return;
    const [departments, positions, users] = await Promise.all([
        VKApi.request('/departments'),
        VKApi.request('/positions'),
        VKApi.request('/users?page_size=100'),
    ]);

    organizationState = {
        departments: departments.data || [],
        positions: positions.data || [],
        users: users.data || [],
    };

    document.getElementById('organizationRoot').innerHTML = `
        <div class="module-summary">
            ${summaryCard('Phòng ban', departments.meta.total, 'Cơ cấu theo bộ phận')}
            ${summaryCard('Chức vụ', positions.meta.total, 'Gắn với người dùng')}
            ${summaryCard('Người dùng', users.meta.total, 'Đã có trong hệ thống')}
            ${summaryCard('Đã gắn phòng ban', users.data.filter(row => row.department_id).length, 'Sẵn sàng phân quyền/KPI')}
        </div>
        <div class="layout-2">
            <section class="module-panel">
                <div class="panel-head">
                    <div>
                        <h2>Phòng ban</h2>
                        <span>Quản lý mã phòng ban, tên bộ phận và người phụ trách.</span>
                    </div>
                </div>
                ${renderDepartmentTable(organizationState.departments)}
            </section>
            <section class="module-panel">
                <div class="panel-head">
                    <div>
                        <h2>Chức vụ</h2>
                        <span>Chuẩn hóa chức danh để gắn người dùng và KPI.</span>
                    </div>
                </div>
                ${renderPositionTable(organizationState.positions)}
            </section>
        </div>
    `;
}

function renderDepartmentTable(rows) {
    return VKTable.renderTable([
        { label: 'Mã', render: row => `<span class="mono">${VKTable.escapeHtml(row.code)}</span>` },
        { label: 'Tên phòng ban', render: row => VKTable.escapeHtml(row.name) },
        { label: 'Người dùng', render: row => VKTable.money(row.users_count || 0) },
        { label: 'Thao tác', render: row => `<button class="btn" type="button" data-edit-department="${row.id}">Sửa</button>` },
    ], rows, 'Chưa có phòng ban');
}

function renderPositionTable(rows) {
    return VKTable.renderTable([
        { label: 'Mã', render: row => `<span class="mono">${VKTable.escapeHtml(row.code)}</span>` },
        { label: 'Tên chức vụ', render: row => VKTable.escapeHtml(row.name) },
        { label: 'Người dùng', render: row => VKTable.money(row.users_count || 0) },
        { label: 'Thao tác', render: row => `<button class="btn" type="button" data-edit-position="${row.id}">Sửa</button>` },
    ], rows, 'Chưa có chức vụ');
}

function openDepartmentModal(id = null) {
    const department = organizationState.departments.find(row => row.id === id);
    const parentOptions = blankOption().concat(
        organizationState.departments
            .filter(row => row.id !== id)
            .map(row => ({ value: row.id, label: `${row.code} - ${row.name}` }))
    );
    const managerOptions = blankOption().concat(organizationState.users.map(row => ({ value: row.id, label: `${row.name} - ${row.email}` })));

    VKModal.open(id ? 'Cập nhật phòng ban' : 'Tạo phòng ban', `
        <div class="form-grid">
            ${VKModal.field('name', 'Tên phòng ban', 'text', department?.name || '')}
            ${VKModal.select('parent_id', 'Phòng ban cha', parentOptions, department?.parent_id || '')}
            ${VKModal.select('manager_id', 'Người phụ trách', managerOptions, department?.manager_id || '')}
        </div>
    `, async (form) => {
        const data = Object.fromEntries(new FormData(form));
        await VKApi.request(id ? `/departments/${id}` : '/departments', {
            method: id ? 'PUT' : 'POST',
            body: JSON.stringify({
                name: data.name,
                parent_id: data.parent_id ? Number(data.parent_id) : null,
                manager_id: data.manager_id ? Number(data.manager_id) : null,
            }),
        });
        VKModal.toast(id ? 'Đã cập nhật phòng ban.' : 'Đã tạo phòng ban.');
        VKModal.close();
        loadOrganization();
    });
}

function openPositionModal(id = null) {
    const position = organizationState.positions.find(row => row.id === id);

    VKModal.open(id ? 'Cập nhật chức vụ' : 'Tạo chức vụ', `
        <div class="form-grid">
            ${VKModal.field('name', 'Tên chức vụ', 'text', position?.name || '')}
        </div>
    `, async (form) => {
        const data = Object.fromEntries(new FormData(form));
        await VKApi.request(id ? `/positions/${id}` : '/positions', {
            method: id ? 'PUT' : 'POST',
            body: JSON.stringify({ name: data.name }),
        });
        VKModal.toast(id ? 'Đã cập nhật chức vụ.' : 'Đã tạo chức vụ.');
        VKModal.close();
        loadOrganization();
    });
}

function blankOption() {
    return [{ value: '', label: 'Chưa chọn' }];
}

function summaryCard(label, value, note) {
    return `<section class="summary-card"><span>${label}</span><strong>${value}</strong><small>${note}</small></section>`;
}

window.loadOrganization = loadOrganization;
})();
