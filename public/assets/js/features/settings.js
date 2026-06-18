(function () {
document.addEventListener('vk:ready', () => loadSettings());

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

    const createButton = event.target.closest('[data-create-sla-policy]');
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
    if (options.source !== 'pjax') {
        root.innerHTML = renderLoading();
    }

    const fallback = { data: [], meta: { total: 0 } };
    const [me, users, roles, customers, suppliers, skus, warehouses, slaPolicies] = await Promise.all([
        safeRequest('/me'),
        safeRequest('/users?page_size=100'),
        safeRequest('/roles?page_size=100'),
        safeRequest('/customers'),
        safeRequest('/suppliers'),
        safeRequest('/skus'),
        safeRequest('/warehouses'),
        safeRequest('/sla-policies?page_size=100'),
    ]);

    root.innerHTML = renderSettings({
        me: me?.data || {},
        users: users || fallback,
        roles: roles || fallback,
        customers: customers || fallback,
        suppliers: suppliers || fallback,
        skus: skus || fallback,
        warehouses: warehouses || fallback,
        slaPolicies: slaPolicies || fallback,
    });
}

async function safeRequest(path) {
    try {
        return await VKApi.request(path);
    } catch (error) {
        return null;
    }
}

function renderSettings(data) {
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
        warehouse_issue: 'Xử lý xuất kho',
        purchase_request: 'Xử lý yêu cầu mua',
        payment_follow_up: 'Theo dõi thanh toán',
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
