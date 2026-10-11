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
let organizationLoadVersion = 0;

async function loadAllOrganizationRows(endpoint) {
    const rows = [];
    for (let page = 1; ; page++) {
        const result = await VKApi.request(`${endpoint}?page_size=100&page=${page}`, {cache:false});
        rows.push(...(result.data || []));
        if (rows.length >= (result.meta?.total ?? rows.length)) return rows;
        if (!result.data?.length) throw new Error('Không thể tải đầy đủ dữ liệu tổ chức.');
    }
}

async function loadOrganization() {
    const root = document.getElementById('organizationRoot');
    if (!root) return;
    const version = ++organizationLoadVersion;
    root.textContent = 'Đang tải cơ cấu tổ chức…';
    try {
    const [departments, positions, users] = await Promise.all([
        loadAllOrganizationRows('/departments'),
        loadAllOrganizationRows('/positions'),
        loadAllOrganizationRows('/users'),
    ]);
    if (version !== organizationLoadVersion || document.getElementById('organizationRoot') !== root) return;

    organizationState = {
        departments,
        positions,
        users,
    };

    root.innerHTML = `
        <div class="organization-help"><strong>Thiết lập cơ cấu trước, gán nhân viên sau.</strong><span>Bước 1: tạo phòng ban/chức vụ. Bước 2: vào Người dùng để gán từng nhân viên vào phòng ban và chức vụ phù hợp.</span></div>
        <div class="layout-2">
            <section class="module-panel">
                <div class="panel-head">
                    <div>
                        <h2>Phòng ban</h2>
                        <span>Tạo tên phòng ban trước; nhân viên sẽ được gán tại mục Người dùng.</span>
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
    } catch(error) {
        if (version === organizationLoadVersion && document.getElementById('organizationRoot') === root) root.textContent = error.message || 'Không thể tải cơ cấu tổ chức.';
    }
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
    VKModal.open(id ? 'Cập nhật phòng ban' : 'Tạo phòng ban', `
        <div class="organization-simple-form">
            ${VKModal.field('name', 'Tên phòng ban', 'text', department?.name || '')}
            <small>Ví dụ: Kinh doanh, Kế toán, Kho. Sau khi tạo, vào mục Người dùng để gán nhân viên.</small>
        </div>
    `, async (form) => {
        const data = Object.fromEntries(new FormData(form));
        await VKApi.request(id ? `/departments/${id}` : '/departments', {
            method: id ? 'PUT' : 'POST',
            body: JSON.stringify({
                name: data.name,
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
        <div class="organization-simple-form">
            ${VKModal.field('name', 'Tên chức vụ', 'text', position?.name || '')}
            <small>Ví dụ: Nhân viên, Trưởng phòng, Kế toán trưởng.</small>
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
