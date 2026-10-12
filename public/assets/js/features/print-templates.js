(function () {
let templateState = { rows: [], q: '', status: '', module: 'quotation', fields: [], fieldSets: {}, modules: {} };
let templateLoadVersion = 0;

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
    const tab = event.target.closest('[data-template-module]');
    if (tab) { templateState.module = tab.dataset.templateModule; renderPrintTemplates(); }
    const copy = event.target.closest('[data-copy-merge-field]');
    if (copy) copyMergeField(copy.dataset.copyMergeField);
    if (event.target.matches('[data-create-print-template]')) openPrintTemplateModal();
    if (event.target.matches('[data-download-merge-template]')) downloadMergeTemplate(event.target.dataset.downloadMergeTemplate || 'quotation');
    if (event.target.matches('[data-edit-print-template]')) {
        event.stopPropagation();
        openPrintTemplateModal(Number(event.target.dataset.editPrintTemplate));
    }
    if (event.target.matches('[data-default-print-template]')) {
        event.stopPropagation();
        setDefaultTemplate(Number(event.target.dataset.defaultPrintTemplate));
    }
    if (event.target.matches('[data-delete-print-template]')) {
        event.stopPropagation();
        deletePrintTemplate(Number(event.target.dataset.deletePrintTemplate));
    }
});

async function loadPrintTemplates(options = {}) {
    const root = document.getElementById('printTemplatesRoot');
    if (!root) return;
    if (options.source === 'pjax' && templateState.rows.length) {
        renderPrintTemplates();
    }

    const version = ++templateLoadVersion;
    let response;
    try { response = await VKApi.request('/print-templates?page_size=100'); }
    catch (error) {
        if (version === templateLoadVersion && document.getElementById('printTemplatesRoot') === root) root.innerHTML = `<section class="module-panel"><p class="form-note">${VKTable.escapeHtml(error.message || 'Không tải được danh sách mẫu. Vui lòng tải lại trang.')}</p></section>`;
        return;
    }
    if (version !== templateLoadVersion || document.getElementById('printTemplatesRoot') !== root) return;
    templateState.rows = response.data || [];
    templateState.fields = response.meta?.fields || defaultFields();
    templateState.fieldSets = response.meta?.field_sets || { quotation: templateState.fields };
    templateState.modules = response.meta?.modules || { quotation: 'Báo giá', sales_order: 'Đơn bán hàng', goods_issue: 'Phiếu xuất kho', contract: 'Hợp đồng' };
    renderPrintTemplates(response.meta?.total ?? templateState.rows.length);
}

function renderPrintTemplates(total = templateState.rows.length) {
    const root = document.getElementById('printTemplatesRoot');
    if (!root) return;
    const rows = filterRows();

    root.innerHTML = `<div class="template-module-bar"><nav class="template-module-tabs" aria-label="Loại mẫu chứng từ">${moduleOptions().map(item => `<button type="button" data-template-module="${item.value}" aria-pressed="${templateState.module === item.value}" class="${templateState.module === item.value ? 'active' : ''}">${VKTable.escapeHtml(item.label)}</button>`).join('')}</nav><button class="btn secondary template-example-download" type="button" data-download-merge-template="${templateState.module}">Tải mẫu ${VKTable.escapeHtml(moduleLabel(templateState.module))} tham khảo</button></div>` + VKTable.fullList({
        title: `Mẫu ${moduleLabel(templateState.module).toLowerCase()}`,
        subtitle: 'Soạn một lần trong Word, tự lấy dữ liệu từ chứng từ khi xuất.',
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
            <span class="row-note mono" style="color:#ec008c">${VKTable.escapeHtml(row.code)}</span>
        ` },
        { label: 'Mặc định', render: row => row.is_default ? '<span class="badge success">Mặc định</span>' : '<span class="text-muted">-</span>' },
        { label: 'File Word', render: row => VKTable.escapeHtml(row.file_name || 'Chưa tải file') },
        { label: 'Trạng thái', render: row => row.status === 'active' ? '<span class="badge success">Đang dùng</span>' : '<span class="badge muted">Tạm ngưng</span>' },
        { label: 'Cập nhật', render: row => formatDateTime(row.updated_at || row.created_at) },
        { label: '', render: row => VKTable.rowActions([
            VKTable.smallButton('Xem / sửa mẫu', `data-edit-print-template="${row.id}"`),
            row.is_default ? '' : VKTable.smallButton('Đặt mặc định', `data-default-print-template="${row.id}"`),
            VKTable.smallButton('Xóa mẫu', `data-delete-print-template="${row.id}"`, 'danger'),
        ]) },
    ], rows, 'Chưa có mẫu in', { rowAttr: row => `data-template-id="${row.id}"` });
}

function filterRows() {
    return templateState.rows.filter(row => {
        const haystack = `${row.code || ''} ${row.name || ''} ${moduleLabel(row.module)}`.toLowerCase();
        return row.module === templateState.module && (!templateState.q || haystack.includes(templateState.q)) && (!templateState.status || row.status === templateState.status);
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
        <div class="template-workspace">
            <section class="template-preview-panel">
                <div class="template-preview-toolbar"><strong>Bản xem trước Word</strong><span class="badge muted" data-preview-caption>Mẫu gốc · chưa trộn dữ liệu</span></div>
                ${id && row?.file_path ? `<div class="template-preview-toolbar"><select data-template-record aria-label="Chọn hồ sơ trộn thử"><option value="">Đang tải hồ sơ được phép xem…</option></select><button class="btn secondary" type="button" data-preview-merged>Trộn thử</button><button class="btn secondary" type="button" data-preview-original>Mẫu gốc</button></div><p class="form-note" data-template-merge-note>Trộn thử dùng mẫu đã lưu. Hồ sơ đã phát hành trả về bản lưu, không tạo lại.</p>` : '<p class="form-note">Lưu mẫu trước để chọn hồ sơ và trộn thử dữ liệu thật.</p>'}
                <div class="template-word-preview" data-template-word-preview><div class="template-preview-empty"><strong>Giữ nguyên bố cục Word của bạn</strong><p>Chọn file .docx đã soạn sẵn để xem trước ngay tại đây.</p><small>Hệ thống giữ bố cục gốc và trộn dữ liệu khi xuất chứng từ.</small></div></div>
                <p class="form-note">Bản xem trước trên trình duyệt có thể khác cách ngắt trang trong Microsoft Word. Dữ liệu thật được trộn khi xuất từ hồ sơ.</p>
            </section>
        <aside class="template-form template-settings-panel">
            <div class="form-grid">
                ${VKModal.field('name', 'Tên mẫu', 'text', row?.name || 'Mẫu chứng từ khách hàng')}
                ${VKModal.select('module', 'Loại chứng từ', moduleOptions(), row?.module || templateState.module)}
                ${VKModal.select('status', 'Trạng thái', [
                    { value: 'active', label: 'Đang dùng' },
                    { value: 'inactive', label: 'Tạm ngưng' },
                ], row?.status || 'active')}
                <label><input type="checkbox" name="is_default" value="1" ${row?.is_default ? 'checked' : ''}> Dùng làm mẫu mặc định</label>
                <div class="field full">
                    <label for="template_file">File Word gốc</label>
                    <input id="template_file" name="template_file" type="file" accept=".docx">
                    <small>${row?.file_name ? `Đang lưu: ${VKTable.escapeHtml(row.file_name)}` : 'Chọn file Word mẫu đã soạn sẵn.'}</small>
                </div>
            </div>
        </aside></div>
    `, async (form) => {
        const data = new FormData(form);
        data.set('is_default', form.querySelector('[name="is_default"]').checked ? '1' : '0');
        data.set('content_html', row?.content_html || defaultTemplateContent(data.get('module')));
        await VKApi.request(id ? `/print-templates/${id}` : '/print-templates', {
            method: 'POST',
            body: data,
        });
        VKApi.clearCache();
        VKModal.toast(id ? 'Đã cập nhật mẫu in.' : 'Đã tạo mẫu in.');
        VKModal.close();
        loadPrintTemplates();
    }, { submitText: 'Lưu mẫu', className: 'modal-wide template-editor-modal' });

    const preview = document.querySelector('[data-template-word-preview]');
    if (id && row?.file_path) {
        const endpoint = {quotation:'quotations', sales_order:'sales-orders', goods_issue:'goods-issues', contract:'contracts'}[row.module];
        const select = document.querySelector('[data-template-record]');
        VKApi.request(`/${endpoint}?page_size=100`, {cache:false}).then(response => {
            if (!select?.isConnected) return;
            select.innerHTML = '<option value="">Chọn hồ sơ để trộn thử…</option>' + (response.data || []).map(record => `<option value="${Number(record.id)}">${VKTable.escapeHtml(record.code)} · ${VKTable.escapeHtml(record.customer?.name || record.name || record.status || '')}</option>`).join('');
        }).catch(() => { if (select?.isConnected) select.innerHTML = '<option value="">Không tải được hồ sơ hoặc chưa có quyền xem</option>'; });
        document.querySelector('[data-preview-merged]')?.addEventListener('click', async () => {
            if (!select?.value) { VKModal.toast('Chọn hồ sơ để trộn thử.', 'warning'); return; }
            if (document.querySelector('#modalBody [name="template_file"]')?.files?.length) { VKModal.toast('Lưu file Word mới trước khi trộn thử.', 'warning'); return; }
            const version = String(Number(preview.dataset.previewVersion || 0) + 1);
            preview.dataset.previewVersion = version;
            preview.textContent = 'Đang trộn dữ liệu hồ sơ…';
            try {
                const response = await fetch(`/api/v1/${endpoint}/${encodeURIComponent(select.value)}/word?template_id=${encodeURIComponent(id)}`, {headers:{Accept:'application/json', Authorization:`Bearer ${VKApi.token()}`}});
                if (!response.ok) {
                    const body = await response.json();
                    throw new Error(Object.values(body.fields || body.errors || {}).flat().join(' ') || body.message || 'Không trộn được chứng từ.');
                }
                const blob = await response.blob();
                if (preview.isConnected && preview.dataset.previewVersion === version) previewWord(blob, preview, 'Dữ liệu hồ sơ · bản xuất Word');
            } catch (error) { if (preview.isConnected && preview.dataset.previewVersion === version) preview.textContent = error.message; }
        });
        document.querySelector('[data-preview-original]')?.addEventListener('click', async () => {
            const version = String(Number(preview.dataset.previewVersion || 0) + 1);
            preview.dataset.previewVersion = version;
            try {
                const response = await fetch(`/api/v1/print-templates/${id}/original`, {headers:{Authorization:`Bearer ${VKApi.token()}`}});
                if (!response.ok) throw new Error('Không tải được mẫu gốc.');
                const blob = await response.blob();
                if (preview.isConnected && preview.dataset.previewVersion === version) previewWord(blob, preview);
            } catch (error) { if (preview.isConnected && preview.dataset.previewVersion === version) preview.textContent = error.message; }
        });
    }
    document.querySelector('#modalBody [name="template_file"]')?.addEventListener('change', event => {
        const file = event.target.files?.[0];
        if (file) previewWord(file, preview);
    });
    if (row?.file_path) {
        const path = String(row.file_path);
        if (/^print-templates\/[0-9]+\/[a-zA-Z0-9._-]+$/.test(path)) {
            fetch(`/api/v1/print-templates/${id}/original`, {headers:{Authorization:`Bearer ${VKApi.token()}`}}).then(response => { if (!response.ok) throw new Error('Không tải được file mẫu.'); return response.blob(); }).then(blob => {
                if (preview?.isConnected && !preview.dataset.previewVersion) previewWord(blob, preview);
            }).catch(() => { if (preview?.isConnected && !preview.dataset.previewVersion) preview.textContent = 'Không tải được mẫu gốc. Chọn file .docx để xem trước.'; });
        }
    }
}

async function copyMergeField(key) {
    try { await navigator.clipboard.writeText(`{{${key}}}`); VKModal.toast('Đã sao chép trường trộn.'); }
    catch { VKModal.toast('Không sao chép được. Bạn có thể chọn và sao chép mã trường bên dưới.', 'warning'); }
}

async function previewWord(file, root, caption = 'Mẫu gốc · chưa trộn dữ liệu') {
    if (!root) return;
    const token = String(Number(root.dataset.previewVersion || 0) + 1);
    root.dataset.previewVersion = token;
    root.textContent = 'Đang đọc bố cục Word…';
    try {
        if (file.name && !/\.docx$/i.test(file.name)) throw new Error('Vui lòng lưu mẫu thành .docx trong Word trước khi tải lên.');
        if (file.size > 5 * 1024 * 1024) throw new Error('File mẫu tối đa 5MB.');
        if (!window.JSZip) await loadPreviewScript('/assets/vendor/jszip.min.js', 'VKZipPreviewLoading');
        if (!window.docx) {
            await (window.VKDocxPreviewLoading ||= new Promise((resolve, reject) => {
                const script = document.createElement('script');
                script.src = '/assets/vendor/docx-preview.min.js';
                script.onload = resolve;
                script.onerror = () => { window.VKDocxPreviewLoading = null; reject(new Error('Không tải được công cụ xem Word.')); };
                document.head.appendChild(script);
            }));
        }
        const stage = document.createElement('div');
        await window.docx.renderAsync(file, stage, null, {inWrapper:true, ignoreWidth:false, ignoreHeight:false, renderAltChunks:false});
        if (root.isConnected && root.dataset.previewVersion === token) {
            root.replaceChildren(stage);
            const label = document.querySelector('[data-preview-caption]');
            if (label) label.textContent = caption;
        }
    } catch (error) {
        if (root.isConnected && root.dataset.previewVersion === token) root.textContent = error.message || 'Không đọc được file Word. Kiểm tra lại file .docx.';
    }
}

function loadPreviewScript(src, key) {
    return window[key] ||= new Promise((resolve, reject) => {
        const script = document.createElement('script');
        script.src = src;
        script.onload = resolve;
        script.onerror = () => { window[key] = null; reject(new Error('Không tải được công cụ xem Word.')); };
        document.head.appendChild(script);
    });
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

async function deletePrintTemplate(id) {
    const row = templateState.rows.find(item => item.id === id);
    if (!row || !window.confirm(`Xóa mẫu “${row.name}”? File Word của mẫu cũng sẽ bị xóa và thao tác này không thể hoàn tác.`)) return;

    await VKApi.request(`/print-templates/${id}`, { method: 'DELETE' });
    VKApi.clearCache();
    VKModal.toast('Đã xóa mẫu in.');
    loadPrintTemplates();
}

function renderFieldChips(keys = null, module = 'quotation') {
    const fields = templateState.fieldSets[module] || templateState.fields || defaultFields();
    const groups = {};
    fields.filter((field, index) => (!keys || keys.includes(field.key)) && fields.findIndex(item => item.key === field.key) === index).forEach(field => {
        const group = ['stt','ma_hang','ten_hang','dvt','so_luong','don_gia','vat_percent','vat_dong','thanh_tien','bang_dong_hang'].includes(field.key) ? 'Dòng hàng'
            : /tong|tam_tinh|chiet_khau|^vat$|gia_tri|thanh_toan/.test(field.key) ? 'Thanh toán'
            : /khach_hang|lien_he|dien_thoai|email|cong_ty|ma_so_thue|dia_chi/.test(field.key) ? 'Khách hàng & hóa đơn' : 'Chứng từ';
        (groups[group] ||= []).push(field);
    });
    return Object.entries(groups).map(([group, entries]) => `<section class="template-field-group"><h4>${group}</h4>${entries.map(field => `<button class="template-field-entry" type="button" data-merge-field-entry="${VKTable.escapeHtml(`${field.label} ${field.key}`.toLocaleLowerCase('vi'))}" data-copy-merge-field="${VKTable.escapeHtml(field.key)}"><span>${VKTable.escapeHtml(field.label || field.key)}</span><code>{{${VKTable.escapeHtml(field.key)}}}</code></button>`).join('')}</section>`).join('');
}

async function downloadMergeTemplate(module = 'quotation') {
    const names = {
        quotation: 'mau-bao-gia-tham-khao.docx',
        sales_order: 'mau-don-ban-hang-tham-khao.docx',
        goods_issue: 'mau-phieu-xuat-kho-tham-khao.docx',
        contract: 'mau-hop-dong-tham-khao.docx',
    };
    if (!names[module]) return;
    try {
        const response = await fetch(`/api/v1/print-templates/reference/${module}`, {
            headers: { Authorization: `Bearer ${VKApi.token()}` },
        });
        if (!response.ok) throw new Error(`Không tải được mẫu ${moduleLabel(module).toLowerCase()} tham khảo.`);
        downloadBlobFile(names[module], await response.blob());
    } catch (error) {
        VKModal.toast(error.message || 'Không tải được mẫu chứng từ tham khảo.', 'warning');
    }
}

function legacyWordTemplate(module = 'quotation') {
    const label = templateState.modules[module] || moduleLabel(module);
    return `<!DOCTYPE html>
<html xmlns:o="urn:schemas-microsoft-com:office:office"
      xmlns:w="urn:schemas-microsoft-com:office:word"
      xmlns="http://www.w3.org/TR/REC-html40">
<head>
    <meta charset="utf-8">
    <meta name="ProgId" content="Word.Document">
    <meta name="Generator" content="VK-KPI">
    <title>Mẫu ${VKTable.escapeHtml(label)} tham khảo</title>
    <style>
        @page Section1 { size: 21cm 29.7cm; margin: 1.8cm 1.6cm 1.8cm 1.6cm; }
        div.Section1 { page: Section1; }
        body { font-family: Arial, sans-serif; color: #111827; font-size: 11pt; line-height: 1.45; }
        p { margin: 4pt 0; }
        .muted { color: #64748B; }
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
        ${wordTemplateContent(module)}
        <p class="muted" style="margin-top:18pt;font-size:9pt">Mẫu tham khảo: chỉnh bố cục theo nhận diện doanh nghiệp, giữ nguyên các mã trong dấu ngoặc nhọn. Sau đó chọn Save As thành .docx và tải lên hệ thống.</p>
    </div>
</body>
</html>`;
}

function downloadBlobFile(fileName, blob) {
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = fileName;
    document.body.appendChild(link);
    link.click();
    link.remove();
    URL.revokeObjectURL(url);
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
    return templateState.modules[module] || ({ quotation: 'Báo giá', sales_order: 'Đơn bán hàng', goods_issue: 'Phiếu xuất kho', contract: 'Hợp đồng' }[module] || VKTable.escapeHtml(module || '-'));
}

function moduleOptions() {
    return Object.entries(templateState.modules || { quotation: 'Báo giá', sales_order: 'Đơn bán hàng', goods_issue: 'Phiếu xuất kho', contract: 'Hợp đồng' })
        .map(([value, label]) => ({ value, label }));
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

function wordTemplateContent(module = 'quotation') {
    if (module === 'contract') {
        return `<table class="sample-header">
    <tr><td><div class="sample-title">HỢP ĐỒNG</div><p><strong>Số hợp đồng:</strong> {{ma_hop_dong}}</p><p><strong>Tên hợp đồng:</strong> {{ten_hop_dong}}</p></td><td class="sample-meta"><p><strong>Ngày lập</strong></p><p>{{ngay_hop_dong}}</p></td></tr>
</table>
<table><tr><th>Bên A</th><th>Bên B</th></tr><tr><td><strong>{{ten_cong_ty}}</strong><br>MST: {{ma_so_thue}}<br>Địa chỉ: {{dia_chi_cong_ty}}</td><td><strong>{{ten_khach_hang}}</strong><br>Liên hệ: {{nguoi_lien_he}}<br>Địa chỉ: {{dia_chi_giao_hang}}</td></tr></table>
<table><tr><td>Giá trị hợp đồng</td><td>{{gia_tri_hop_dong}}</td></tr><tr><td>Bằng chữ</td><td>{{gia_tri_bang_chu}}</td></tr><tr><td>Hiệu lực</td><td>{{ngay_hieu_luc}} đến {{ngay_het_han}}</td></tr><tr><td>Mốc thanh toán</td><td>{{moc_thanh_toan}}</td></tr><tr><td>Điều khoản khác</td><td>{{dieu_khoan}}</td></tr></table>`;
    }
    if (module === 'sales_order') {
        return `<table class="sample-header"><tr><td><div class="sample-title">ĐƠN BÁN HÀNG</div><p><strong>Mã đơn:</strong> {{ma_don_ban}}</p><p><strong>Báo giá nguồn:</strong> {{ma_bao_gia}}</p></td><td class="sample-meta"><p><strong>Ngày lập</strong></p><p>{{ngay_chung_tu}}</p></td></tr></table>
<table><tr><th>Khách hàng</th><th>Giao nhận</th></tr><tr><td><strong>{{ten_khach_hang}}</strong><br>Liên hệ: {{nguoi_lien_he}}<br>Điện thoại: {{so_dien_thoai}}</td><td>{{dia_chi_giao_hang}}<br>Hình thức: {{hinh_thuc_giao_hang}}<br>Ghi chú: {{ghi_chu_giao_hang}}</td></tr></table>
<table><thead><tr><th>STT</th><th>Mã hàng</th><th>Tên hàng</th><th>ĐVT</th><th>SL</th></tr></thead><tbody><tr><td>{{stt}}</td><td>{{ma_hang}}</td><td>{{ten_hang}}</td><td>{{dvt}}</td><td>{{so_luong}}</td></tr></tbody></table>
<table><tr class="total"><td>Tổng cộng</td><td>{{tong_cong}}</td></tr><tr><td>Bằng chữ</td><td>{{tong_tien_bang_chu}}</td></tr></table>`;
    }
    if (module === 'goods_issue') {
        return `<table class="sample-header"><tr><td><div class="sample-title">PHIẾU XUẤT KHO</div><p><strong>Mã phiếu:</strong> {{ma_chung_tu}}</p><p><strong>Đơn bán:</strong> {{ma_don_ban}}</p></td><td class="sample-meta"><p><strong>Ngày xuất</strong></p><p>{{ngay_chung_tu}}</p></td></tr></table>
<table><tr><th>Kho xuất</th><th>Thông tin giao hàng</th></tr><tr><td>{{kho_xuat}}<br>Lý do: {{ly_do_xuat}}<br>Chứng từ gốc: {{chung_tu_goc}}</td><td><strong>{{ten_khach_hang}}</strong><br>{{nguoi_lien_he}} · {{so_dien_thoai}}<br>{{dia_diem_giao_hang}}</td></tr></table>
<table><thead><tr><th>STT</th><th>Mã hàng</th><th>Tên hàng</th><th>ĐVT</th><th>SL</th></tr></thead><tbody><tr><td>{{stt}}</td><td>{{ma_hang}}</td><td>{{ten_hang}}</td><td>{{dvt}}</td><td>{{so_luong}}</td></tr></tbody></table>`;
    }
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

function defaultTemplateContent(module = 'quotation') {
    if (module === 'contract') {
        return `<section class="doc-header"><div><p class="muted">HỢP ĐỒNG</p><h1>{{ma_hop_dong}}</h1><p><strong>{{ten_hop_dong}}</strong></p></div><div class="doc-meta"><span>Ngày lập</span><strong>{{ngay_hop_dong}}</strong></div></section><section class="doc-grid"><div class="doc-card"><h2>Bên A</h2><p><strong>{{ten_cong_ty}}</strong></p><p>Mã số thuế: {{ma_so_thue}}</p><p>Địa chỉ: {{dia_chi_cong_ty}}</p></div><div class="doc-card"><h2>Bên B</h2><p><strong>{{ten_khach_hang}}</strong></p><p>Người liên hệ: {{nguoi_lien_he}}</p><p>Địa chỉ: {{dia_chi_giao_hang}}</p></div></section><section class="doc-card"><h2>Giá trị và thời hạn</h2><p>Giá trị hợp đồng: <strong>{{gia_tri_hop_dong}}</strong></p><p>Bằng chữ: {{gia_tri_bang_chu}}</p><p>Hiệu lực: {{ngay_hieu_luc}} đến {{ngay_het_han}}</p></section><section class="doc-card"><h2>Mốc thanh toán</h2><p>{{moc_thanh_toan}}</p></section><section class="doc-card"><h2>Điều khoản khác</h2><p>{{dieu_khoan}}</p></section>`;
    }
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
