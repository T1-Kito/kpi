(function () {
let templateState = { rows: [], q: '', status: '', fields: [] };

document.addEventListener('vk:ready', () => loadPrintTemplates());
document.addEventListener('input', (event) => {
    if (!document.getElementById('printTemplatesRoot')) return;
    if (event.target.matches('[data-list-search]')) {
        templateState.q = event.target.value.toLowerCase();
        renderPrintTemplates();
    }
});
document.addEventListener('change', (event) => {
    if (!document.getElementById('printTemplatesRoot')) return;
    if (event.target.matches('[data-list-status]')) {
        templateState.status = event.target.value;
        renderPrintTemplates();
    }
});
document.addEventListener('click', (event) => {
    if (!document.getElementById('printTemplatesRoot')) return;
    if (event.target.matches('[data-create-print-template]')) openPrintTemplateModal();
    if (event.target.matches('[data-download-merge-template]')) downloadMergeTemplate();
    if (event.target.matches('[data-edit-print-template]')) {
        event.stopPropagation();
        openPrintTemplateModal(Number(event.target.dataset.editPrintTemplate));
    }
    if (event.target.matches('[data-default-print-template]')) {
        event.stopPropagation();
        setDefaultTemplate(Number(event.target.dataset.defaultPrintTemplate));
    }
});

async function loadPrintTemplates(options = {}) {
    const root = document.getElementById('printTemplatesRoot');
    if (!root) return;
    if (options.source === 'pjax' && templateState.rows.length) {
        renderPrintTemplates();
    }

    const response = await VKApi.request('/print-templates?page_size=100');
    if (document.getElementById('printTemplatesRoot') !== root) return;
    templateState.rows = response.data || [];
    templateState.fields = response.meta?.fields || defaultFields();
    renderPrintTemplates(response.meta?.total ?? templateState.rows.length);
}

function renderPrintTemplates(total = templateState.rows.length) {
    const root = document.getElementById('printTemplatesRoot');
    if (!root) return;
    const rows = filterRows();

    root.innerHTML = VKTable.fullList({
        title: 'Danh sách mẫu in',
        subtitle: 'Thiết lập mẫu Word/PDF, mẫu gửi khách và trường trộn cho từng phân hệ.',
        meta: `${VKTable.money(rows.length)} / ${VKTable.money(total)} mẫu in`,
        filters: VKTable.filterBar({
            searchPlaceholder: 'Tìm mã mẫu hoặc tên mẫu...',
            status: [
                { value: 'active', label: 'Đang dùng' },
                { value: 'inactive', label: 'Tạm ngưng' },
            ],
        }),
        table: renderTemplateTable(rows),
    });
    restoreFilters();
}

function renderTemplateTable(rows) {
    return VKTable.renderTable([
        { label: 'Mẫu in', render: row => `
            <strong>${VKTable.escapeHtml(row.name)}</strong>
            <span class="row-note mono">${VKTable.escapeHtml(row.code)}</span>
        ` },
        { label: 'Phân hệ', render: row => moduleLabel(row.module) },
        { label: 'Mặc định', render: row => row.is_default ? '<span class="badge success">Mặc định</span>' : '<span class="text-muted">-</span>' },
        { label: 'File Word', render: row => VKTable.escapeHtml(row.file_name || 'Chưa tải file') },
        { label: 'Trạng thái', render: row => row.status === 'active' ? '<span class="badge success">Đang dùng</span>' : '<span class="badge muted">Tạm ngưng</span>' },
        { label: 'Cập nhật', render: row => formatDateTime(row.updated_at || row.created_at) },
        { label: '', render: row => VKTable.rowActions([
            VKTable.smallButton('Sửa', `data-edit-print-template="${row.id}"`),
            row.is_default ? '' : VKTable.smallButton('Đặt mặc định', `data-default-print-template="${row.id}"`),
        ]) },
    ], rows, 'Chưa có mẫu in', { rowAttr: row => `data-template-id="${row.id}"` });
}

function filterRows() {
    return templateState.rows.filter(row => {
        const haystack = `${row.code || ''} ${row.name || ''} ${moduleLabel(row.module)}`.toLowerCase();
        return (!templateState.q || haystack.includes(templateState.q)) && (!templateState.status || row.status === templateState.status);
    });
}

function restoreFilters() {
    const search = document.querySelector('[data-list-search]');
    const status = document.querySelector('[data-list-status]');
    if (search) search.value = templateState.q;
    if (status) status.value = templateState.status;
}

function openPrintTemplateModal(id = null) {
    const row = id ? templateState.rows.find(item => item.id === id) : {};
    VKModal.open(id ? 'Sửa mẫu in' : 'Tạo mẫu in', `
        <div class="template-form">
            <div class="form-grid">
                ${VKModal.field('name', 'Tên mẫu', 'text', row?.name || 'Mẫu báo giá khách hàng')}
                ${VKModal.select('module', 'Phân hệ', [{ value: 'quotation', label: 'Báo giá' }], row?.module || 'quotation')}
                ${VKModal.select('status', 'Trạng thái', [
                    { value: 'active', label: 'Đang dùng' },
                    { value: 'inactive', label: 'Tạm ngưng' },
                ], row?.status || 'active')}
                <div class="field full">
                    <label for="template_file">File Word gốc</label>
                    <input id="template_file" name="template_file" type="file" accept=".doc,.docx">
                    <small>${row?.file_name ? `Đang lưu: ${VKTable.escapeHtml(row.file_name)}` : 'Chọn file Word mẫu đã soạn sẵn.'}</small>
                </div>
            </div>
        </div>
    `, async (form) => {
        const data = new FormData(form);
        data.set('is_default', row?.is_default || !id ? '1' : '0');
        data.set('content_html', row?.content_html || defaultTemplateContent());
        await VKApi.request(id ? `/print-templates/${id}` : '/print-templates', {
            method: 'POST',
            body: data,
        });
        VKApi.clearCache();
        VKModal.toast(id ? 'Đã cập nhật mẫu in.' : 'Đã tạo mẫu in.');
        VKModal.close();
        loadPrintTemplates();
    }, { submitText: 'Lưu mẫu', className: 'modal-wide' });
}

async function setDefaultTemplate(id) {
    const row = templateState.rows.find(item => item.id === id);
    if (!row) return;
    const data = new FormData();
    data.set('name', row.name);
    data.set('module', row.module);
    data.set('status', 'active');
    data.set('is_default', '1');
    data.set('content_html', row.content_html || defaultTemplateContent());

    await VKApi.request(`/print-templates/${id}`, { method: 'POST', body: data });
    VKApi.clearCache();
    VKModal.toast('Đã đặt mẫu in mặc định.');
    loadPrintTemplates();
}

function renderFieldChips() {
    return (templateState.fields.length ? templateState.fields : defaultFields()).map(field => (
        `<button class="field-chip" type="button" onclick="navigator.clipboard?.writeText('{{${field.key}}}')">{{${field.key}}}</button>`
    )).join('');
}

function downloadMergeTemplate() {
    const fields = templateState.fields.length ? templateState.fields : defaultFields();
    const fieldRows = fields.map((field, index) => `
        <tr>
            <td>${index + 1}</td>
            <td><strong>{{${VKTable.escapeHtml(field.key)}}}</strong></td>
            <td>${VKTable.escapeHtml(field.label || field.key)}</td>
            <td>Chèn đúng cú pháp vào vị trí cần trộn dữ liệu.</td>
        </tr>
    `).join('');
    const html = `<!DOCTYPE html>
<html xmlns:o="urn:schemas-microsoft-com:office:office"
      xmlns:w="urn:schemas-microsoft-com:office:word"
      xmlns="http://www.w3.org/TR/REC-html40">
<head>
    <meta charset="utf-8">
    <meta name="ProgId" content="Word.Document">
    <meta name="Generator" content="VK-KPI">
    <title>Mẫu trường trộn báo giá</title>
    <style>
        @page Section1 { size: 21cm 29.7cm; margin: 1.8cm 1.6cm 1.8cm 1.6cm; }
        div.Section1 { page: Section1; }
        body { font-family: Arial, sans-serif; color: #111827; font-size: 11pt; line-height: 1.45; }
        h1 { font-size: 22pt; margin: 0 0 4pt; color: #0F172A; }
        h2 { font-size: 15pt; margin: 18pt 0 8pt; color: #0F172A; }
        p { margin: 4pt 0; }
        .muted { color: #64748B; }
        .note { background: #EFF6FF; border: 1px solid #BFDBFE; padding: 10pt; margin: 12pt 0; }
        table { width: 100%; border-collapse: collapse; margin-top: 10pt; }
        th, td { border: 1px solid #CBD5E1; padding: 7pt; text-align: left; vertical-align: top; }
        th { background: #EAF2FF; color: #0F172A; font-weight: 700; }
        .sample-header td { border: 0; padding: 0 0 10pt; }
        .sample-title { font-size: 20pt; font-weight: 700; color: #0F172A; }
        .sample-meta { text-align: right; color: #475569; }
        .total td { font-weight: 700; background: #F8FAFC; }
    </style>
</head>
<body>
    <div class="Section1">
        <h1>Mẫu trường trộn báo giá</h1>
        <p class="muted">Dùng file này để soạn mẫu Word, sau đó upload lại ở màn Tạo mẫu in.</p>
        <div class="note">
            <strong>Cách dùng:</strong> copy trường ở cột "Trường trộn" và đặt vào vị trí cần hiển thị dữ liệu trong mẫu Word.
            Giữ nguyên hai dấu ngoặc nhọn, ví dụ <strong>{{ma_bao_gia}}</strong>.
        </div>

        <h2>Danh sách trường trộn</h2>
        <table>
            <thead>
                <tr>
                    <th style="width: 42px;">#</th>
                    <th style="width: 190px;">Trường trộn</th>
                    <th style="width: 190px;">Ý nghĩa</th>
                    <th>Ghi chú</th>
                </tr>
            </thead>
            <tbody>${fieldRows}</tbody>
        </table>

        <h2>Mẫu báo giá tham khảo</h2>
        ${wordTemplateContent()}
    </div>
</body>
</html>`;
    downloadTextFile('mau-truong-tron-bao-gia.doc', `\ufeff${html}`, 'application/msword;charset=utf-8');
}

function downloadTextFile(fileName, content, type) {
    const blob = new Blob([content], { type });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = fileName;
    document.body.appendChild(link);
    link.click();
    link.remove();
    URL.revokeObjectURL(url);
}

function moduleLabel(module) {
    return module === 'quotation' ? 'Báo giá' : VKTable.escapeHtml(module || '-');
}

function formatDateTime(value) {
    if (!value) return '-';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return '-';
    return date.toLocaleString('vi-VN', { hour: '2-digit', minute: '2-digit', day: '2-digit', month: '2-digit', year: 'numeric' });
}

function defaultFields() {
    return [
        { key: 'ma_bao_gia', label: 'Mã báo giá' },
        { key: 'ngay_bao_gia', label: 'Ngày báo giá' },
        { key: 'ten_khach_hang', label: 'Tên khách hàng' },
        { key: 'ma_khach_hang', label: 'Mã khách hàng' },
        { key: 'nguoi_lien_he', label: 'Người liên hệ' },
        { key: 'so_dien_thoai', label: 'Số điện thoại' },
        { key: 'email', label: 'Email khách hàng' },
        { key: 'ten_cong_ty', label: 'Tên công ty xuất hóa đơn' },
        { key: 'ma_so_thue', label: 'Mã số thuế' },
        { key: 'dia_chi_cong_ty', label: 'Địa chỉ công ty' },
        { key: 'stt', label: 'STT dòng hàng' },
        { key: 'ma_hang', label: 'Mã hàng' },
        { key: 'ten_hang', label: 'Tên hàng' },
        { key: 'dvt', label: 'Đơn vị tính' },
        { key: 'so_luong', label: 'Số lượng' },
        { key: 'don_gia', label: 'Đơn giá' },
        { key: 'vat_percent', label: 'VAT (%)' },
        { key: 'vat_dong', label: 'Tiền VAT dòng' },
        { key: 'thanh_tien', label: 'Thành tiền dòng' },
        { key: 'bang_dong_hang', label: 'Bảng dòng hàng dạng text' },
        { key: 'tam_tinh', label: 'Tạm tính' },
        { key: 'vat', label: 'Tiền thuế VAT' },
        { key: 'tong_cong', label: 'Tổng cộng' },
        { key: 'tong_tien_bang_chu', label: 'Tổng tiền bằng chữ' },
    ];
}

function wordTemplateContent() {
    return `<table class="sample-header">
    <tr>
        <td>
            <div class="sample-title">BÁO GIÁ</div>
            <p><strong>Mã báo giá:</strong> {{ma_bao_gia}}</p>
            <p><strong>Khách hàng:</strong> {{ten_khach_hang}}</p>
        </td>
        <td class="sample-meta">
            <p><strong>Ngày báo giá</strong></p>
            <p>{{ngay_bao_gia}}</p>
        </td>
    </tr>
</table>

<table>
    <tr>
        <th colspan="2">Thông tin khách hàng</th>
        <th colspan="2">Thông tin xuất hóa đơn</th>
    </tr>
    <tr>
        <td>Mã khách hàng</td>
        <td>{{ma_khach_hang}}</td>
        <td>Tên công ty</td>
        <td>{{ten_cong_ty}}</td>
    </tr>
    <tr>
        <td>Người liên hệ</td>
        <td>{{nguoi_lien_he}}</td>
        <td>Mã số thuế</td>
        <td>{{ma_so_thue}}</td>
    </tr>
    <tr>
        <td>Số điện thoại</td>
        <td>{{so_dien_thoai}}</td>
        <td>Địa chỉ</td>
        <td>{{dia_chi_cong_ty}}</td>
    </tr>
    <tr>
        <td>Email</td>
        <td>{{email}}</td>
        <td></td>
        <td></td>
    </tr>
</table>

<table>
    <thead>
        <tr>
            <th>STT</th>
            <th>Mã hàng</th>
            <th>Tên hàng</th>
            <th>ĐVT</th>
            <th>Số lượng</th>
            <th>Đơn giá</th>
            <th>VAT</th>
            <th>Thành tiền</th>
        </tr>
    </thead>
    <tbody>
        <tr>
            <td>{{stt}}</td>
            <td>{{ma_hang}}</td>
            <td>{{ten_hang}}</td>
            <td>{{dvt}}</td>
            <td>{{so_luong}}</td>
            <td>{{don_gia}}</td>
            <td>{{vat_percent}}</td>
            <td>{{thanh_tien}}</td>
        </tr>
    </tbody>
</table>

<table>
    <tr><td style="width: 75%;">Tạm tính</td><td>{{tam_tinh}}</td></tr>
    <tr><td>VAT</td><td>{{vat}}</td></tr>
    <tr class="total"><td>Tổng cộng</td><td>{{tong_cong}}</td></tr>
    <tr><td>Bằng chữ</td><td>{{tong_tien_bang_chu}}</td></tr>
</table>`;
}

function defaultTemplateContent() {
    return `<section class="doc-header">
    <div>
        <p class="muted">Báo giá</p>
        <h1>{{ma_bao_gia}}</h1>
        <p><strong>{{ten_khach_hang}}</strong> · {{nguoi_lien_he}} · {{so_dien_thoai}}</p>
    </div>
    <div class="doc-meta">
        <span>Ngày báo giá</span>
        <strong>{{ngay_bao_gia}}</strong>
    </div>
</section>

<section class="doc-grid">
    <div class="doc-card">
        <h2>Thông tin khách hàng</h2>
        <p>Tên khách hàng: <strong>{{ten_khach_hang}}</strong></p>
        <p>Mã khách hàng: <strong>{{ma_khach_hang}}</strong></p>
        <p>Người liên hệ: <strong>{{nguoi_lien_he}}</strong></p>
        <p>Email: <strong>{{email}}</strong></p>
    </div>
    <div class="doc-card">
        <h2>Thông tin xuất hóa đơn</h2>
        <p>Tên công ty: <strong>{{ten_cong_ty}}</strong></p>
        <p>Mã số thuế: <strong>{{ma_so_thue}}</strong></p>
        <p>Địa chỉ công ty: <strong>{{dia_chi_cong_ty}}</strong></p>
    </div>
</section>

{{bang_dong_hang}}

<table class="summary-table">
    <tr><td>Tạm tính</td><td>{{tam_tinh}}</td></tr>
    <tr><td>VAT</td><td>{{vat}}</td></tr>
    <tr class="total"><td>Tổng cộng</td><td>{{tong_cong}}</td></tr>
</table>`;
}

window.loadPrintTemplates = loadPrintTemplates;
})();
