document.addEventListener('vk:ready', () => loadSettings());

document.addEventListener('change', (event) => {
    if (!document.getElementById('settingsRoot')) return;
    if (event.target.matches('[data-logo-input]')) {
        previewLogo(event.target.files?.[0]);
    }
});

document.addEventListener('click', (event) => {
    if (!document.getElementById('settingsRoot')) return;
    if (event.target.matches('[data-save-logo]')) {
        uploadLogo();
    }
});

async function loadSettings() {
    const root = document.getElementById('settingsRoot');
    if (!root) return;
    root.innerHTML = renderLoading();

    const fallback = { data: [], meta: { total: 0 } };
    const [me, users, roles, customers, suppliers, skus, warehouses] = await Promise.all([
        safeRequest('/me'),
        safeRequest('/users'),
        safeRequest('/roles'),
        safeRequest('/customers'),
        safeRequest('/suppliers'),
        safeRequest('/skus'),
        safeRequest('/warehouses'),
    ]);

    root.innerHTML = renderSettings({
        me: me?.data || {},
        users: users || fallback,
        roles: roles || fallback,
        customers: customers || fallback,
        suppliers: suppliers || fallback,
        skus: skus || fallback,
        warehouses: warehouses || fallback,
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

            <section class="module-panel settings-section" id="audit">
                <div class="panel-head">
                    <div>
                        <h2>Nhật ký</h2>
                        <span>Các thao tác gần nhất phục vụ truy vết vận hành.</span>
                    </div>
                </div>
                <a class="btn small" href="/audit-logs">Mở nhật ký hệ thống</a>
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

function renderAuditLogs(rows) {
    return VKTable.renderTable([
        { label: 'Thời gian', render: row => formatDateTime(row.created_at) },
        { label: 'Người thao tác', render: row => VKTable.escapeHtml(row.user?.name || 'Hệ thống') },
        { label: 'Đối tượng', render: row => `${VKTable.escapeHtml(VKTable.translateEntity(row.entity_type))} #${VKTable.escapeHtml(row.entity_id || '-')}` },
        { label: 'Hành động', render: row => VKTable.escapeHtml(VKTable.translateAction(row.action)) },
        { label: 'Lý do', render: row => VKTable.escapeHtml(row.reason || '-') },
    ], rows, 'Chưa có nhật ký hệ thống');
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
        localStorage.setItem('vk.currentUser', JSON.stringify(window.VKUser));
        window.VKLayout?.hydrateLogo?.(logoUrl);
        VKModal.toast('Đã cập nhật logo hệ thống.');
    } catch (error) {
        VKModal.toast(error.message || 'Không thể cập nhật logo.');
    } finally {
        button.disabled = false;
    }
}

function infoRow(label, value) {
    return `<div><span>${label}</span><strong>${VKTable.escapeHtml(value)}</strong></div>`;
}

function metricCard(label, value, note) {
    return `<section class="summary-card"><span>${label}</span><strong>${VKTable.money(value)}</strong><small>${note}</small></section>`;
}

function formatDateTime(value) {
    if (!value) return '-';
    return new Date(value).toLocaleString('vi-VN');
}

function renderLoading() {
    return `<div class="module-summary">${Array.from({ length: 5 }).map(() => '<section class="summary-card"><div class="skeleton"></div><div class="skeleton large"></div><div class="skeleton"></div></section>').join('')}</div>`;
}
