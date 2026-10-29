(function () {
let customerState = { rows: [], q: '', status: '', customerType: '', view: 'list', currentId: null, detailCache: {} };
customerState.page = 1;
customerState.pageSize = 20;
customerState.extraColumns = false;
let customerSearchTimer, customerLoadVersion = 0;

document.addEventListener('vk:ready', () => loadCustomers());
document.addEventListener('input', (event) => {
    if (!document.getElementById('customersRoot')) return;
    if (event.target.matches('[data-list-search]')) {
        customerState.q = event.target.value.toLowerCase();
        ++customerLoadVersion;
        customerState.page = 1;
        clearTimeout(customerSearchTimer);
        customerSearchTimer = setTimeout(() => loadCustomers(), 300);
    }
});
document.addEventListener('change', (event) => {
    if (!document.getElementById('customersRoot')) return;
    if (event.target.matches('[data-customer-group-filter]')) { customerState.groupId=event.target.value; customerState.page=1; loadCustomers(); return; }
    if(event.target.matches('[data-customers-size]')){customerState.pageSize=Number(event.target.value);customerState.page=1;loadCustomers();return;}
    if(event.target.matches('[data-customers-extra]')){customerState.extraColumns=event.target.checked;renderCustomers();return;}
    if (event.target.matches('[data-customer-type-filter]')) {
        customerState.customerType = event.target.value;
        customerState.page = 1;
        loadCustomers();
        return;
    }
    if (event.target.matches('[data-list-status]')) {
        customerState.status = event.target.value;
        customerState.page = 1;
        loadCustomers();
    }
});
document.addEventListener('click', (event) => {
    if (!document.getElementById('customersRoot')) return;
    const pageButton = event.target.closest('[data-customers-page]');
    if (pageButton) { if(pageButton.disabled)return; customerState.page = Number(pageButton.dataset.customersPage); loadCustomers(); return; }
    if(event.target.closest('[data-customers-refresh]')){loadCustomers();return;}
    const care = event.target.closest('[data-customer-care]');
    if (care) { event.stopPropagation(); openCustomerCare(Number(care.dataset.customerCare)); return; }
    if (event.target.matches('[data-create-customer]')) openCustomerModal();
    if (event.target.closest('[data-import-customers]')) { openCustomerImport(); return; }
    if (event.target.closest('[data-customer-import-template]')) { downloadCustomerImportTemplate(); return; }
    if (event.target.matches('[data-customer-duplicates]')) {
        loadCustomerDuplicates();
        return;
    }
    if (event.target.matches('[data-customer-duplicates-back]')) {
        customerState.view = 'list';
        renderCustomers();
        return;
    }
    if (event.target.matches('[data-customer-duplicates-scan]')) {
        scanCustomerDuplicates();
        return;
    }
    const duplicateAction = event.target.closest('[data-duplicate-action]');
    if (duplicateAction) {
        openDuplicateReviewModal(Number(duplicateAction.dataset.reviewId), duplicateAction.dataset.duplicateAction);
        return;
    }
    const contactButton = event.target.closest('[data-customer-contact]');
    if (contactButton) {
        event.stopPropagation();
        openCustomerContactModal(Number(contactButton.dataset.customerContact || 0));
        return;
    }
    const contactDelete = event.target.closest('[data-delete-customer-contact]');
    if (contactDelete) {
        event.stopPropagation();
        deleteCustomerContact(Number(contactDelete.dataset.deleteCustomerContact));
        return;
    }
    if (event.target.matches('[data-customer360-back]')) {
        customerState.view = 'list';
        customerState.currentId = null;
        renderCustomers();
    }
    const customer360Route = event.target.closest('[data-customer360-route]');
    if (customer360Route) window.VKLayout?.navigatePjax(customer360Route.dataset.customer360Route);
    if (event.target.matches('[data-edit-customer]')) {
        event.stopPropagation();
        openCustomerModal(Number(event.target.dataset.editCustomer));
        return;
    }
    if (event.target.closest('.row-action-menu') && !event.target.closest('[data-customer-detail]')) return;
    const detail = event.target.closest('[data-customer-detail], tr[data-row-detail]');
    if (detail) openCustomerDetail(detail.dataset.customerDetail || detail.dataset.rowDetail);
});

async function downloadCustomerImportTemplate() {
    try {
        const response = await fetch('/api/v1/import-center/customer-template?v=20261025-groups', {cache:'no-store',headers:{Authorization:`Bearer ${VKApi.token()}`}});
        if (!response.ok) throw new Error('Không tải được mẫu hoặc bạn không có quyền nhập khách hàng.');
        const url = URL.createObjectURL(await response.blob());
        const link = document.createElement('a'); link.href=url; link.download='Mẫu khách hàng.xlsx'; link.click();
        setTimeout(()=>URL.revokeObjectURL(url),1000);
    } catch (error) { VKModal.toast(error.message,'danger'); }
}

function openCustomerImport() {
    let checkedFile = null;
    let checkedBatch = null;
    VKModal.open('Nhập khách hàng', `
        <div class="customer-import-template"><span>Chuẩn bị danh sách theo mẫu.</span><button type="button" class="btn" data-customer-import-template>Tải mẫu</button></div>
        <label class="field customer-import-file">Chọn danh sách<input name="customer_import_file" type="file" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" aria-describedby="customerImportLimit" required></label>
        <p class="customer-import-hint" id="customerImportLimit">Excel .xlsx · Tối đa 300 khách · 1 MB</p>
        <details class="customer-import-guide">
            <summary>Hướng dẫn nhập file</summary>
            <p>Điền trang Danh sách từ dòng 2, giữ nguyên các cột. Chọn loại khách Doanh nghiệp hoặc Cá nhân. Ba cột nhóm khách hàng là tùy chọn; nhập danh mục nhóm trước, rồi điền tên nhóm đúng nhánh từ cấp 1.</p>
            <p>Giữ điện thoại, MST và CCCD ở dạng văn bản để không mất số 0 đầu. Lưu .xlsx và nhập trực tiếp, không cần đổi sang CSV.</p>
            <p>Giữ Mã ngoài để nhận đúng khách khi cập nhật. Phần mềm tự xử lý phiên bản, nhân viên không cần nhập. Người phụ trách điền email nhân viên; ô trống khi cập nhật giữ nguyên.</p>
        </details>
        <div data-customer-import-result role="status" aria-live="polite"></div>
    `, async form => {
        const file = form.querySelector('[name="customer_import_file"]').files[0];
        if (!file) throw new Error('Vui lòng chọn file Excel hoặc CSV.');
        const confirm = checkedFile === file;
        const body = new FormData(); body.append('file',file); body.append('confirm',confirm ? '1' : '0');
        const response = (await VKApi.request(confirm && checkedBatch?`/import-center/batches/${checkedBatch}/commit`:'/import-center/customer-preview',confirm && checkedBatch?{method:'POST',body:JSON.stringify({confirmed:true}),timeout:180000}:{method:'POST',body,timeout:180000})).data;
        const result=response.result;
        checkedBatch=response.id;
        const target = form.querySelector('[data-customer-import-result]');
        if (response.status==='committed') {
            VKModal.close(); customerState.page=1; await loadCustomers();
            VKModal.toast(`Đã xử lý ${result.imported} khách hàng · Bỏ qua ${result.skipped || 0} dòng đã nhập.`); return;
        }
        checkedFile = result.can_import ? file : null;
        const escape = VKTable.escapeHtml;
        target.innerHTML = result.errors.length
            ? `<p>Chưa nhập khách nào. Sửa các dòng sau rồi kiểm tra lại:</p><ul>${result.errors.map(error=>`<li>Dòng ${Number(error.row)}: ${escape(error.message)}</li>`).join('')}</ul>`
            : `<p>${Number(result.total)} dòng hợp lệ. Xem thay đổi rồi bấm “Xác nhận nhập”.</p><div class="table-wrap"><table class="list-table"><thead><tr><th>Dòng</th><th>Mã ngoài</th><th>Thao tác</th><th>Khách hàng</th><th>Phân nhóm</th><th>Thay đổi</th></tr></thead><tbody>${result.preview.map(row=>`<tr><td>${Number(row.row)}</td><td>${escape(row.external_id || '')}</td><td>${({create:'Thêm mới',update:'Cập nhật',skip:'Bỏ qua'})[row.action] || 'Kiểm tra'}</td><td>${escape(row.name)}</td><td>${escape(row.group_path || 'Chưa phân nhóm')}</td><td>${Object.entries(row.changes || {}).map(([field,value])=>`${escape(({name:'Tên',phone:'Điện thoại',email:'Email',address:'Địa chỉ',billing_address:'Địa chỉ hóa đơn',legal_representative:'Người đại diện',representative_position:'Chức vụ',credit_limit:'Hạn mức',status:'Trạng thái',sales_owner_ref:'Phụ trách',group_level1:'Nhóm cấp 1',group_level2:'Nhóm cấp 2',group_level3:'Nhóm cấp 3'})[field] || field)}: ${escape(String(value.before ?? 'Trống'))} → ${escape(String(value.after ?? 'Trống'))}`).join('<br>') || '—'}</td></tr>`).join('')}</tbody></table></div>`;
        document.getElementById('modalSubmit').textContent = checkedFile ? 'Xác nhận nhập' : 'Kiểm tra file';
    }, {submitText:'Kiểm tra file',className:'customer-import-modal'});
    const input = document.querySelector('#modalForm [name="customer_import_file"]');
    input.addEventListener('change',()=>{
        checkedFile=null;
        checkedBatch=null;
        document.getElementById('modalSubmit').textContent='Kiểm tra file';
        document.querySelector('[data-customer-import-result]').textContent='File đã đổi. Hãy kiểm tra lại trước khi nhập.';
    });
}

async function loadCustomers(options = {}) {
    if (!document.getElementById('customersRoot')) return;
    if (options.source === 'pjax') {
        customerState.view = 'list';
        customerState.currentId = null;
    }
    const version = ++customerLoadVersion;
    const focused = document.activeElement?.matches('[data-list-search]');
    const groupsReady = window.VKCustomerGroups?.load();
    if (customerState.groupId === undefined) customerState.groupId = new URLSearchParams(window.location.search).get('group') || '';
    const params = new URLSearchParams({page_size:customerState.pageSize,page:customerState.page,q:customerState.q,status:customerState.status,customer_type:customerState.customerType,customer_group_id:customerState.groupId || ''});
    const [customers] = await Promise.all([VKApi.request(`/customers?${params}`, {cache:false}), groupsReady]);
    if (version !== customerLoadVersion || !document.getElementById('customersRoot')) return;
    const careBucket=new URLSearchParams(window.location.search).get('care');
    const careRoot=document.getElementById('customersRoot');
    if(['today','overdue','upcoming','unscheduled'].includes(careBucket) && !careRoot.dataset.careOpened) {
        careRoot.dataset.careOpened='1';
        openCustomerCareBoard(careBucket).catch(error=>VKModal.toast(error.message,'danger'));
    }
    customerState.meta = customers.meta;
    customerState.rows = customers.data || [];
    const requestedId = new URLSearchParams(window.location.search).get('open');
    if (requestedId && /^\d+$/.test(requestedId)) {
        await openCustomerDetail(requestedId);
        return;
    }
    if (customerState.view === 'duplicates') {
        loadCustomerDuplicates();
        return;
    }
    if (customerState.view === 'detail' && customerState.currentId) {
        openCustomerDetail(customerState.currentId);
        return;
    }
    renderCustomers(customers.meta.total);
    if (focused) { const input = document.querySelector('[data-list-search]'); input?.focus(); input?.setSelectionRange(input.value.length,input.value.length); }
}

function renderCustomers(total = customerState.meta?.total || 0) {
    const rows = customerState.rows;
    const page=Number(customerState.meta?.page || 1),last=Number(customerState.meta?.last_page || 1);
    const start=total?(page-1)*customerState.pageSize+1:0,end=total?start+rows.length-1:0;
    const pages=[...new Set([1,...Array.from({length:5},(_,i)=>page+i-2).filter(n=>n>1&&n<last),last])].sort((a,b)=>a-b);
    const buttons=pages.map((n,i)=>`${i&&n>pages[i-1]+1?'<span class="customer-page-gap">…</span>':''}<button type="button" class="btn small ${n===page?'primary':''}" data-customers-page="${n}" ${n===page?'aria-current="page"':''}>${n}</button>`).join('');
    document.getElementById('customersRoot').innerHTML = `<section class="customer-list-workspace" aria-label="Danh sách khách hàng">
        <div class="customer-list-heading"><div><h2>Khách hàng <span>/ Danh sách</span></h2><span class="customer-list-count">${Number(total)} khách hàng</span></div></div>
        <div class="customer-list-tools">
            <select data-customer-group-filter aria-label="Lọc phân nhóm khách hàng">${window.VKCustomerGroups?.options(customerState.groupId) || '<option value="">Tất cả phân nhóm</option>'}</select>
            <select data-customer-type-filter aria-label="Lọc loại khách hàng"><option value="">Loại khách hàng</option><option value="person">Cá nhân</option><option value="organization">Doanh nghiệp</option></select>
            <select data-list-status aria-label="Lọc trạng thái"><option value="">Trạng thái</option><option value="active">Hoạt động</option><option value="inactive">Ngừng hoạt động</option></select>
            <input type="search" data-list-search placeholder="Tìm tên, mã, điện thoại, email…" aria-label="Tìm khách hàng">
            <button type="button" class="btn small" data-customers-refresh title="Tải lại danh sách" aria-label="Tải lại danh sách">↻</button>
            <label class="customer-columns-toggle"><input type="checkbox" data-customers-extra ${customerState.extraColumns?'checked':''}> Cột bổ sung</label>
            ${window.VKLayout?.hasPermission('customer.manage')?'<button class="btn small" type="button" data-import-customers>Nhập file</button><button class="btn primary small" type="button" data-create-customer>＋ Thêm khách hàng</button>':''}
        </div>
        <div class="customer-list-scroll">${renderCustomerTable(rows)}</div>
        <nav class="customer-list-pagination" aria-label="Trang khách hàng"><span>Hiển thị <strong>${start}–${end}</strong> / ${Number(total)} khách hàng</span><div><label>Số dòng <select data-customers-size aria-label="Số dòng mỗi trang">${[20,50,100].map(n=>`<option value="${n}" ${n===customerState.pageSize?'selected':''}>${n}</option>`).join('')}</select></label><button type="button" class="btn small" aria-label="Trang trước" data-customers-page="${page-1}" ${page<=1?'disabled':''}>‹</button>${buttons}<button type="button" class="btn small" aria-label="Trang sau" data-customers-page="${page+1}" ${page>=last?'disabled':''}>›</button></div></nav>
    </section>`;
    restoreFilters();
}

async function loadCustomerDuplicates() {
    customerState.view = 'duplicates';
    const root = document.getElementById('customersRoot');
    if (!root) return;
    root.innerHTML = '<div class="customer360-loading">Đang tải hàng chờ xét trùng…</div>';
    const response = await VKApi.request('/customer-duplicate-reviews', { cache: false });
    customerState.duplicateReviews = response.data || [];
    root.innerHTML = `<section class="customer360-page customer-duplicate-page">
        <header class="customer360-hero"><div class="customer360-hero-top">
            <button class="customer360-back" type="button" data-customer-duplicates-back>← Danh sách khách hàng</button>
            <button class="btn primary small" type="button" data-customer-duplicates-scan>Quét khách nghi trùng</button>
        </div><div class="customer360-identity"><div><p>Chất lượng dữ liệu</p><h1>Hàng chờ xét khách trùng</h1><span>${VKTable.money(response.meta?.total || 0)} trường hợp cần người có quyền kiểm tra</span></div></div></header>
        <div class="customer-duplicate-list">${customerState.duplicateReviews.length ? customerState.duplicateReviews.map(renderDuplicateReview).join('') : '<article class="customer360-card customer360-empty">Chưa có trường hợp cần xét. Bấm “Quét khách nghi trùng” để kiểm tra dữ liệu hiện có.</article>'}</div>
    </section>`;
}

function renderDuplicateReview(review) {
    const first = review.first_customer || {};
    const second = review.second_customer || {};
    const reasons = { tax_code: 'Mã số thuế', phone: 'Số điện thoại', email: 'Email' };
    return `<article class="customer360-card customer-duplicate-card">
        <div class="customer360-card-title"><span>≋</span><h2>Nghi trùng #${review.id}</h2><small>Trùng: ${VKTable.escapeHtml((review.matched_on || '').split(',').map(key => reasons[key] || key).join(', '))}</small></div>
        <div class="customer-duplicate-pair">${[first, second].map(item => `<div><strong>${VKTable.escapeHtml(item.code || '')} · ${VKTable.escapeHtml(item.name || '')}</strong><span>${VKTable.escapeHtml(item.phone || 'Không có SĐT')} · ${VKTable.escapeHtml(item.email || 'Không có email')} · MST: ${VKTable.escapeHtml(item.tax_code || '—')}</span></div>`).join('')}</div>
        <div class="customer-duplicate-actions"><button class="btn small" type="button" data-review-id="${review.id}" data-duplicate-action="dismiss">Không trùng</button><button class="btn primary small" type="button" data-review-id="${review.id}" data-duplicate-action="merge">Gộp hồ sơ</button></div>
    </article>`;
}

async function scanCustomerDuplicates() {
    const response = await VKApi.request('/customer-duplicate-reviews/scan', { method: 'POST' });
    VKModal.toast(`Đã quét: ${response.data?.detected || 0} cặp, thêm ${response.data?.new_reviews || 0} trường hợp mới.`);
    await loadCustomerDuplicates();
}

function openDuplicateReviewModal(id, action) {
    const review = (customerState.duplicateReviews || []).find(item => item.id === id);
    if (!review) return;
    const first = review.first_customer || {};
    const second = review.second_customer || {};
    VKModal.open(action === 'merge' ? 'Gộp hồ sơ khách hàng' : 'Xác nhận không trùng', `
        <p>${VKTable.escapeHtml(first.code || '')} · ${VKTable.escapeHtml(first.name || '')} / ${VKTable.escapeHtml(second.code || '')} · ${VKTable.escapeHtml(second.name || '')}</p>
        ${action === 'merge' ? `<div class="field"><label for="retained_customer_id">Hồ sơ giữ lại</label><select id="retained_customer_id" name="retained_customer_id"><option value="${first.id}">${VKTable.escapeHtml(first.code || '')} · ${VKTable.escapeHtml(first.name || '')}</option><option value="${second.id}">${VKTable.escapeHtml(second.code || '')} · ${VKTable.escapeHtml(second.name || '')}</option></select><small>Hồ sơ còn lại sẽ ngừng hoạt động; báo giá, đơn hàng, ticket và liên hệ chuyển sang hồ sơ giữ lại.</small></div>` : ''}
        <div class="field"><label for="duplicate_reason">Lý do xử lý</label><textarea id="duplicate_reason" name="reason" required minlength="${action === 'merge' ? 10 : 5}" placeholder="Ghi rõ căn cứ để truy vết"></textarea></div>
    `, async form => {
        const values = Object.fromEntries(new FormData(form));
        await VKApi.request(`/customer-duplicate-reviews/${id}/${action}`, { method: 'POST', body: JSON.stringify(values) });
        VKModal.close();
        VKModal.toast(action === 'merge' ? 'Đã gộp và lưu dấu vết.' : 'Đã đánh dấu không trùng.');
        await loadCustomers();
    }, { submitText: action === 'merge' ? 'Xác nhận gộp' : 'Xác nhận' });
}

function renderCustomerTable(rows) {
    return VKTable.renderTable([
        { label: 'Mã KH', render: row => `<span class="customer-list-code">${VKTable.escapeHtml(row.code)}</span>` },
        { label: 'Tổ chức / Tên khách hàng', render: row => `<div class="customer-list-name"><span class="customer-list-avatar" aria-hidden="true">${VKTable.escapeHtml((row.name || '?').trim().slice(0,1).toUpperCase())}</span><strong title="${VKTable.escapeHtml(row.name || '')}">${VKTable.escapeHtml(row.name || '—')}</strong></div>` },
        { label: 'Loại khách', render: row => `<span class="customer-type-chip ${row.customer_type==='person'?'person':'organization'}">${row.customer_type==='person'?'Cá nhân':'Doanh nghiệp'}</span>` },
        { label: 'Phân nhóm', render: row => VKTable.escapeHtml(window.VKCustomerGroups?.path(row.customer_group_id).map(g=>g.name).join(' / ') || row.customer_group || 'Chưa phân nhóm') },
        { label: 'Người liên hệ', render: row => VKTable.escapeHtml(row.contact_name || '—') },
        { label: 'Số điện thoại', render: row => VKTable.escapeHtml(row.phone || '—') },
        { label: 'Email', render: row => VKTable.escapeHtml(row.email || '—') },
        { label: 'Địa chỉ', render: row => `<span class="customer-list-address" title="${VKTable.escapeHtml(row.address || '')}">${VKTable.escapeHtml(row.address || '—')}</span>` },
        { label: 'Phụ trách', render: row => VKTable.escapeHtml(row.sales_owner?.name || 'Chưa gán') },
        { label: 'Dùng chung', render: row => VKCatalogSharing.button(row, 'customers') },
        ...(customerState.extraColumns?[
            {label:'Mã số thuế',render:row=>VKTable.escapeHtml(row.tax_code || '—')},
            {label:'Số CCCD',render:row=>VKTable.escapeHtml(row.identity_number || '—')},
            {label:'Hạn mức',render:row=>VKTable.money(row.credit_limit)},
            {label:'Trạng thái',render:row=>VKTable.statusBadge(row.status)},
        ]:[]),
        { label: 'Thao tác', render: row => `<div class="customer-list-actions">${[
            VKTable.smallButton('Mở', `data-customer-detail="${row.id}"`),
            VKTable.smallButton('Chăm sóc', `data-customer-care="${row.id}"`),
            ...(window.VKLayout?.hasPermission('customer.manage')?[VKTable.smallButton('Sửa', `data-edit-customer="${row.id}"`)]:[]),
        ].join('')}</div>` },
    ], rows, 'Chưa có khách hàng', { rowAttr: row => `data-row-detail="${row.id}"` });
}

function filterRows() {
    return customerState.rows.filter(row => {
        const haystack = `${row.code || ''} ${row.name || ''} ${row.tax_code || ''} ${row.billing_address || ''} ${row.address || ''} ${row.phone || ''} ${row.email || ''}`.toLowerCase();
        return (!customerState.q || haystack.includes(customerState.q)) && (!customerState.status || row.status === customerState.status);
    });
}

function restoreFilters() {
    const search = document.querySelector('[data-list-search]');
    const status = document.querySelector('[data-list-status]');
    const customerType = document.querySelector('[data-customer-type-filter]');
    if (customerType) customerType.value = customerState.customerType;
    if (search) search.value = customerState.q;
    if (status) status.value = customerState.status;
}

async function openCustomerModal(id = null, readOnly = false, asPage = false) {
    try { await window.VKCustomerGroups?.load(); } catch (error) { VKModal.toast('Không tải được phân nhóm khách hàng. Vui lòng thử lại.', 'danger'); return; }
    if (id && !readOnly) {
        try { customerState.detailCache[String(id)] = await VKApi.request(`/customers/${id}`, { cache: false }); }
        catch (error) { VKModal.toast(error.message || 'Không tải được khách hàng.'); return; }
    }
    const row = id ? (customerState.detailCache[String(id)]?.data || customerState.rows.find(item => String(item.id) === String(id))) : {};
    VKModal.open(id ? 'Sửa khách hàng' : 'Tạo khách hàng', `
        <div class="customer-form-sections">
            <section class="quick-customer-section" data-customer-main-section>
                <div class="customer-section-heading"><h4><span aria-hidden="true">${customerFormIcon('user')}</span>Thông tin khách hàng</h4>${VKCatalogSharing.field(row || {}, readOnly)}</div>
                <div class="quick-customer-grid">
                    ${customerField('name', 'Tên khách hàng / tổ chức', 'text', row?.name || '', 'CT')}
                    ${customerSelect('customer_type', 'Loại khách hàng', [
                        { value: 'organization', label: 'Tổ chức / doanh nghiệp' },
                        { value: 'person', label: 'Cá nhân / khách lẻ / vãng lai' },
                    ], row?.customer_type || 'organization', 'KH')}
                    <div class="customer-primary-combo" data-primary-contact-picker>
                        ${customerField('contact_name', 'Người liên hệ chính (nếu là tổ chức)', 'text', row?.contact_name || '', 'KH')}
                        <input type="hidden" name="primary_contact_id" value="">
                        <button type="button" class="customer-contact-toggle" aria-label="Chọn người liên hệ chính" aria-expanded="false">▾</button>
                        <div class="customer-contact-options" role="listbox" id="customerPrimaryContacts" hidden></div>
                    </div>
                    ${customerField('phone', 'Số điện thoại', 'text', row?.phone || '', 'SDT')}
                    ${customerField('email', 'Email', 'email', row?.email || '', '@')}
                    ${customerField('address', 'Địa chỉ liên hệ', 'text', row?.address || '', 'DC', true)}
                </div>
            </section>
            <section class="quick-customer-section">
                <h4>Phân nhóm khách hàng</h4>
                <div class="quick-customer-grid">
                    ${window.VKCustomerGroups?.field(row) || ''}
                </div>
                <p class="form-note">Phân nhóm phục vụ tư vấn và báo cáo, không thay đổi loại hồ sơ Cá nhân / Tổ chức.</p>
            </section>
            <section class="quick-customer-section" data-customer-invoice-section>
                <h4><span aria-hidden="true">${customerFormIcon('document')}</span><span data-customer-invoice-title>Thông tin xuất hóa đơn</span></h4>
                <div class="quick-customer-grid">
                    <div data-customer-business-field>${customerField('legal_representative', 'Người đại diện pháp luật', 'text', row?.legal_representative || '', 'ĐD')}</div>
                    <div data-customer-business-field>${customerField('representative_position', 'Chức vụ đại diện', 'text', row?.representative_position || '', 'CV')}</div>
                    <div data-customer-tax-field>${customerField('tax_code', 'Mã số thuế', 'text', row?.tax_code || '', 'MST')}</div>
                    <div data-customer-identity-field>${customerField('identity_number', 'Số CCCD', 'text', row?.identity_number || '', 'CCCD')}</div>
                    <div data-customer-business-field class="customer-field-full-wrap">${customerField('billing_address', 'Địa chỉ xuất hóa đơn', 'text', row?.billing_address || '', 'DC', true)}</div>
                </div>
            </section>
            <section class="quick-customer-section" data-customer-payment-section>
                <h4><span aria-hidden="true">${customerFormIcon('money')}</span>Thanh toán & công nợ</h4>
                <div class="quick-customer-grid">
                    ${customerField('credit_limit', 'Hạn mức công nợ', 'number', row?.credit_limit || '0', 'VND')}
                    ${customerField('payment_terms', 'Điều khoản thanh toán', 'text', row?.payment_terms || '', 'TT')}
                    <div data-customer-business-field>${customerField('bank_name', 'Ngân hàng', 'text', row?.bank_name || '', 'NH')}</div>
                    <div data-customer-business-field>${customerField('bank_account_no', 'Số tài khoản', 'text', row?.bank_account_no || '', 'STK')}</div>
                    <div data-customer-business-field class="customer-field-full-wrap">${customerField('bank_account_name', 'Tên tài khoản', 'text', row?.bank_account_name || '', 'TK', true)}</div>
                </div>
            </section>
        </div>
    `, async (form) => {
        const data = Object.fromEntries(new FormData(form));
        if (form.elements.is_shared && !form.elements.is_shared.disabled) data.is_shared = form.elements.is_shared.checked;
        if (!data.name && data.contact_name) data.name = data.contact_name;
        if (data.customer_type === 'person') {
            Object.assign(data, {
                primary_contact_id: null,
                contact_name: null,
                tax_code: null,
                billing_address: null,
                legal_representative: null,
                representative_position: null,
                bank_name: null,
                bank_account_no: null,
                bank_account_name: null,
            });
        }
        const duplicateQuery = new URLSearchParams({
            tax_code: data.tax_code || '',
            phone: data.phone || '',
            email: data.email || '',
            ...(id ? { exclude_id: String(id) } : {}),
        });
        const duplicateResponse = await VKApi.request(`/customers/duplicates?${duplicateQuery}`, { cache: false });
        const matches = duplicateResponse.data || [];
        if (matches.length) {
            const sameTaxCode = matches.some(item => item.tax_code && item.tax_code.replace(/[^0-9-]/g, '') === (data.tax_code || '').replace(/[^0-9-]/g, ''));
            const details = matches.map(item => `${item.code} – ${item.name}`);
            if (sameTaxCode) {
                await VKModal.confirm({title:'Không thể lưu khách hàng', message:'Mã số thuế đã có trong hồ sơ hiện có. Vui lòng kiểm tra lại thông tin.', details, allowContinue:false});
                return;
            }
            if (!await VKModal.confirm({title:'Phát hiện khách hàng có thể trùng', message:'Số điện thoại hoặc email đã được sử dụng trong các hồ sơ dưới đây. Nếu đây là khách hàng khác, bạn có thể tiếp tục lưu.', details})) return;
        }
        try { await VKApi.request(id ? `/customers/${id}` : '/customers', {
            method: id ? 'PUT' : 'POST',
            body: JSON.stringify({ ...data, credit_limit: VKMoney.parse(data.credit_limit || 0) }),
        }); } catch (error) {
            await VKModal.confirm({title:'Chưa lưu được khách hàng', message:error.message || 'Vui lòng kiểm tra lại thông tin và thử lại.', allowContinue:false});
            return;
        }
        VKModal.toast(id ? 'Đã cập nhật khách hàng.' : 'Đã tạo khách hàng.');
        VKModal.close();
        if (id) customerState.detailCache[String(id)] = null;
        loadCustomers();
    }, {
        className: 'customer-form-modal',
        submitText: id ? 'Cập nhật' : 'Lưu khách hàng',
        hideSubmit: readOnly,
        cancelText: readOnly ? 'Đóng' : 'Hủy',
    });
    document.getElementById('modalTitle').innerHTML = `<span class="customer-form-title-icon" aria-hidden="true">${customerFormIcon(readOnly ? 'user' : 'userPlus')}</span><span>${readOnly ? 'Chi tiết khách hàng' : id ? 'Sửa khách hàng' : 'Tạo khách hàng'}<small>${readOnly ? VKTable.escapeHtml(`${row?.code || ''} · ${row?.name || ''}`) : id ? 'Cập nhật thông tin khách hàng trong hệ thống' : 'Thêm thông tin khách hàng mới vào hệ thống'}</small></span>`;
    document.getElementById('modalSubmit').innerHTML = `${customerFormIcon('save')}<span>${id ? 'Cập nhật' : 'Lưu khách hàng'}</span>`;
    const typeSelect = document.querySelector('#modalBody [name="customer_type"]');
    const contactPicker = document.querySelector('#modalBody [name="primary_contact_id"]');
    bindCustomerPrimaryContactPicker(document.getElementById('modalBody'), row?.contacts || [], readOnly);
    const syncIdentityField = () => {
        const person = typeSelect?.value === 'person';
        syncCustomerBusinessFields(document.getElementById('modalBody'), person, readOnly);
        const pickerField = document.querySelector('#modalBody [data-primary-contact-picker]');
        if (pickerField) {
            pickerField.hidden = person;
            pickerField.querySelectorAll('input, button').forEach(input => { input.disabled = person || readOnly; });
            if (person) {
                pickerField.querySelector('.customer-contact-options').hidden = true;
                contactPicker.value = '';
            }
        }
        const taxField = document.querySelector('#modalBody [data-customer-tax-field]');
        const identityField = document.querySelector('#modalBody [data-customer-identity-field]');
        const invoiceTitle = document.querySelector('#modalBody [data-customer-invoice-title]');
        if (invoiceTitle) invoiceTitle.textContent = person ? 'Thông tin định danh cá nhân' : 'Thông tin xuất hóa đơn';
        taxField.hidden = person;
        identityField.hidden = !person;
        taxField.querySelector('input').disabled = person || readOnly;
        const identityInput = identityField.querySelector('input');
        identityInput.disabled = !person || readOnly;
        identityInput.placeholder = 'Nhập số CCCD (12 chữ số)...';
        identityInput.inputMode = 'numeric';
        identityInput.maxLength = 12;
        identityInput.pattern = '[0-9]{12}';
        const body=document.getElementById('modalBody');
        const invoice=body.querySelector('[data-customer-invoice-section]');
        const payment=body.querySelector('[data-customer-payment-section]');
        body.querySelector('.customer-form-sections')?.classList.toggle('is-person',person);
        const mergedTitle=body.querySelector('[data-customer-merged-title]');
        if(mergedTitle)mergedTitle.textContent=person?'Thông tin khách hàng':'Thông tin doanh nghiệp';
        const groupTitle=body.querySelector('.customer-contact-group-section h4');
        if(groupTitle)groupTitle.textContent=person?'Phân nhóm khách hàng':'Người liên hệ & phân nhóm';
        if(invoice && payment){
            const grid=(person?body.querySelector('[data-customer-main-section]') || payment:invoice).querySelector('.quick-customer-grid');
            if(identityField.parentElement!==grid)grid.prepend(identityField);
            invoice.hidden=person;
        }
    };
    typeSelect?.addEventListener('change', syncIdentityField);
    syncIdentityField();
    {
        const body=document.getElementById('modalBody');
        body.querySelectorAll('.customer-form-sections > .quick-customer-section').forEach(section=>{section.hidden=section.hasAttribute('data-customer-invoice-section') && typeSelect?.value==='person';});
        const group=body.querySelector('[name="customer_group_id"]')?.closest('.field');
        const groupSection=group?.closest('.quick-customer-section');
        const mainGrid=body.querySelector('.quick-customer-section:first-child .quick-customer-grid');
        const invoiceGrid=body.querySelector('[data-customer-invoice-section] .quick-customer-grid');
        const taxField=invoiceGrid?.querySelector('[data-customer-tax-field]');
        if(taxField)invoiceGrid.prepend(taxField);
        if(group && groupSection && mainGrid){
            mainGrid.append(group);
            groupSection.remove();
        }
        arrangeCustomerCompanyForm(body);
        if(!readOnly){
        VKModal.mountPage(document.getElementById('customersRoot'));
        document.querySelector('.customer-editor-page')?.scrollIntoView({block:'start',behavior:'instant'});
        document.querySelector('.customer-editor-page [data-modal-close]')?.addEventListener('click',()=>loadCustomers());
        document.querySelector('.customer-editor-page .modal-foot [data-modal-close]')?.addEventListener('click',()=>loadCustomers());
        }
    }
    if (readOnly) {
        const body = document.getElementById('modalBody');
        body.querySelectorAll('input, select').forEach(input => {
            input.disabled = true;
            if (input.tagName === 'INPUT' && !input.value) input.placeholder = 'Chưa cập nhật';
        });
        body.insertAdjacentHTML('beforeend', '<div class="customer-detail-actions"><button type="button" class="btn" data-customer-transactions>Giao dịch & người liên hệ</button><button type="button" class="btn primary" data-customer-detail-edit>Chỉnh sửa khách hàng</button></div>');
        body.querySelector('[data-customer-detail-edit]').onclick = () => openCustomerModal(Number(id));
        body.querySelector('[data-customer-transactions]').onclick = () => openCustomerTransactions(id);
        if (asPage) {
            const actions=body.querySelector('.customer-detail-actions');
            actions.classList.add('customer-page-view-actions');
            VKModal.mountPage(document.getElementById('customersRoot'));
            const page=document.querySelector('.customer-editor-page');
            page.classList.add('customer-view-page');
            page.querySelector('.quick-customer-section:first-child h4').appendChild(actions);
            page.scrollIntoView({block:'start',behavior:'instant'});
            page.querySelector('.modal-foot [data-modal-close]')?.addEventListener('click',()=>loadCustomers());
            customerState.view = 'detail';
        }
    }
}

function syncCustomerBusinessFields(body, person, readOnly) {
    body.querySelectorAll('[data-customer-business-field]').forEach(field => {
        field.hidden = person;
        field.querySelectorAll('input').forEach(input => { input.disabled = person || readOnly; });
    });
}

function bindCustomerPrimaryContactPicker(body, contacts, readOnly) {
    const combo = body.querySelector('[data-primary-contact-picker]');
    const input = combo.querySelector('[name="contact_name"]');
    const selectedId = combo.querySelector('[name="primary_contact_id"]');
    const toggle = combo.querySelector('.customer-contact-toggle');
    const options = combo.querySelector('.customer-contact-options');
    input.autocomplete = 'off';
    input.setAttribute('role', 'combobox');
    input.setAttribute('aria-controls', 'customerPrimaryContacts');
    input.setAttribute('aria-autocomplete', 'list');
    const close = () => {
        options.hidden = true;
        input.setAttribute('aria-expanded', 'false');
        toggle.setAttribute('aria-expanded', 'false');
    };
    const show = (all = false) => {
        if (readOnly || input.disabled) return;
        const term = all ? '' : input.value.trim().toLocaleLowerCase('vi');
        const matches = contacts.filter(contact => `${contact.name || ''} ${contact.phone || ''} ${contact.email || ''}`.toLocaleLowerCase('vi').includes(term));
        options.innerHTML = matches.map(contact => `<button type="button" role="option" data-primary-contact-option="${VKTable.escapeHtml(String(contact.id))}" aria-selected="${String(contact.id) === selectedId.value}"><strong>${VKTable.escapeHtml(contact.name || '')}</strong><small>${VKTable.escapeHtml([contact.phone, contact.email].filter(Boolean).join(' · '))}</small></button>`).join('') || '<span class="customer-contact-empty">Chưa có liên hệ phù hợp. Bạn có thể nhập tên trực tiếp.</span>';
        options.hidden = false;
        input.setAttribute('aria-expanded', 'true');
        toggle.setAttribute('aria-expanded', 'true');
    };
    input.addEventListener('focus', () => show(true));
    input.addEventListener('input', () => {
        selectedId.value = '';
        ['phone', 'email'].forEach(name => { body.querySelector(`[name="${name}"]`).readOnly = false; });
        show();
    });
    toggle.addEventListener('click', () => { if (options.hidden) show(true); else close(); });
    options.addEventListener('click', event => {
        const button = event.target.closest('[data-primary-contact-option]');
        const contact = contacts.find(item => String(item.id) === button?.dataset.primaryContactOption);
        if (!contact) return;
        selectedId.value = String(contact.id);
        input.value = contact.name || '';
        ['phone', 'email'].forEach(name => {
            const field = body.querySelector(`[name="${name}"]`);
            field.value = contact[name] || '';
            field.readOnly = true;
        });
        input.focus();
        close();
    });
    combo.addEventListener('keydown', event => {
        if (event.key === 'Escape' && !options.hidden) { event.stopPropagation(); close(); return; }
        const buttons = [...options.querySelectorAll('button')];
        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            if (options.hidden) show(true);
            const items = [...options.querySelectorAll('button')];
            const index = items.indexOf(document.activeElement);
            const next = index < 0 ? (event.key === 'ArrowDown' ? 0 : items.length - 1) : (index + (event.key === 'ArrowDown' ? 1 : -1) + items.length) % items.length;
            items[next]?.focus();
        } else if (event.key === 'Enter' && !options.hidden && document.activeElement === input && buttons.length) {
            event.preventDefault();
            buttons[0].click();
        }
    });
    combo.addEventListener('focusout', event => { if (!combo.contains(event.relatedTarget)) close(); });
    body.addEventListener('click', event => { if (!combo.contains(event.target)) close(); });
    close();
}

async function openCustomerTransactions(id) {
    try {
        const response = await VKApi.request(`/customers/${id}`);
        customerState.detailCache[String(id)] = response;
        const customer = response.data || {};
        const data = response.meta?.customer_360 || {};
        const summary = data.summary || {};
        const table = (title, rows, amountKey = 'total_amount') => `<section class="customer-transactions-section"><h3>${title}<small>${rows.length} bản ghi gần nhất</small></h3>${rows.length ? `<div class="customer-transactions-table"><table><thead><tr><th>Mã chứng từ</th><th>Ngày</th><th>Trạng thái</th><th>Giá trị</th></tr></thead><tbody>${rows.map(item => `<tr><td>${VKTable.escapeHtml(item.code || '-')}</td><td>${formatCustomer360Date(item.paid_at || item.created_at)}</td><td>${VKTable.statusBadge(item.status || 'completed')}</td><td>${VKTable.money(item[amountKey] || 0)}</td></tr>`).join('')}</tbody></table></div>` : '<p class="customer-transactions-empty">Chưa có giao dịch.</p>'}</section>`;
        VKModal.open('Giao dịch & người liên hệ', `
            <p class="customer-transactions-identity">${VKTable.escapeHtml(customer.code || '')} · ${VKTable.escapeHtml(customer.name || '')}</p>
            <div class="customer-transactions-summary">
                <div><span>Số lần báo giá</span><strong>${summary.quotation_count ?? 0}</strong></div>
                <div><span>Đơn hàng</span><strong>${summary.order_count || 0}</strong></div>
                <div><span>Giá trị mua</span><strong>${VKTable.money(summary.order_value || 0)}</strong></div>
                <div><span>Đã thanh toán</span><strong>${VKTable.money(summary.payment_value || 0)}</strong></div>
            </div>
            ${table('Báo giá', data.quotations || [])}
            ${table('Đơn hàng', data.orders || [])}
            ${table('Thanh toán', data.payments || [], 'amount')}
            <section class="customer-transactions-section"><h3>Người liên hệ <small>${(customer.contacts || []).length} người</small></h3>
            ${(customer.contacts || []).length ? `<div class="customer-transactions-table"><table><thead><tr><th>Họ tên</th><th>Chức vụ</th><th>Điện thoại</th><th>Email</th></tr></thead><tbody>${customer.contacts.map(contact => `<tr><td>${VKTable.escapeHtml(contact.name)}${contact.is_primary ? ' <small>· Liên hệ chính</small>' : ''}</td><td>${VKTable.escapeHtml(contact.position || '-')}</td><td>${VKTable.escapeHtml(contact.phone || '-')}</td><td>${VKTable.escapeHtml(contact.email || '-')}</td></tr>`).join('')}</tbody></table></div>` : '<p class="customer-transactions-empty">Chưa có người liên hệ.</p>'}</section>
        `, null, {className:'customer-transactions-modal',hideSubmit:true,cancelText:'Đóng'});
        // Keep transactions in the page, not a second overlay over the inline form.
        const body=document.getElementById('modalBody');
        const back=document.createElement('button');back.type='button';back.className='btn small';back.textContent='← Hồ sơ khách hàng';
        const returnToProfile=()=>{VKModal.close();openCustomerDetail(id).catch(error=>{renderCustomers();VKModal.toast(error.message,'danger');});};
        back.onclick=returnToProfile;body.prepend(back);
        VKModal.mountPage(document.getElementById('customersRoot'));
        document.querySelector('.customer-editor-page')?.scrollIntoView({block:'start',behavior:'instant'});
        const close=document.querySelector('.customer-editor-page .modal-foot [data-modal-close]');
        close?.addEventListener('click',()=>{openCustomerDetail(id).catch(error=>{renderCustomers();VKModal.toast(error.message,'danger');});});
    } catch (error) {
        VKModal.toast(error.message || 'Không tải được giao dịch khách hàng.', 'danger');
        if(!document.getElementById('customersRoot')?.textContent.trim())renderCustomers();
    }
}

function arrangeCustomerCompanyForm(body) {
    const sections=body.querySelector('.customer-form-sections');
    const main=body.querySelector('[data-customer-main-section]');
    const grid=main?.querySelector('.quick-customer-grid');
    const invoice=body.querySelector('[data-customer-invoice-section]');
    if(!sections || !grid || !invoice)return;
    sections.classList.add('is-business-merged');
    for(const selector of ['[data-customer-tax-field]','[data-customer-business-field]']) {
        invoice.querySelectorAll(selector).forEach(field=>grid.append(field));
    }
    const tax=grid.querySelector('[data-customer-tax-field]');
    if(tax)grid.prepend(tax);
    const identity=body.querySelector('[data-customer-identity-field]');
    if(sections.classList.contains('is-person') && identity)grid.prepend(identity);
    const heading=main.querySelector('h4');
    const icon=heading.querySelector('span');
    heading.replaceChildren();if(icon)heading.append(icon);
    const title=document.createElement('span');title.dataset.customerMergedTitle='';
    title.textContent=sections.classList.contains('is-person')?'Thông tin khách hàng':'Thông tin doanh nghiệp';heading.append(title);
    const contact=grid.querySelector('[data-primary-contact-picker]');
    const group=grid.querySelector('[name="customer_group_id"]')?.closest('.field');
    const extra=document.createElement('section');extra.className='quick-customer-section customer-contact-group-section';
    extra.innerHTML=`<h4>${sections.classList.contains('is-person')?'Phân nhóm khách hàng':'Người liên hệ & phân nhóm'}</h4><div class="quick-customer-grid"></div>`;
    if(contact)extra.querySelector('.quick-customer-grid').append(contact);
    if(group)extra.querySelector('.quick-customer-grid').append(group);
    main.after(extra);
}

function customerFormIcon(kind) {
    const paths = {
        user: '<circle cx="12" cy="7" r="4"/><path d="M4 21v-2a8 8 0 0 1 16 0v2z"/>',
        userPlus: '<circle cx="9" cy="7" r="4"/><path d="M2 21v-2a7 7 0 0 1 12-5M19 13v8m-4-4h8"/>',
        document: '<path d="M14 2H5v20h14V7zM14 2v5h5M8 12h8M8 16h6"/>',
        phone: '<path d="m7 3 3 5-3 3a16 16 0 0 0 6 6l3-3 5 3v3c0 2-3 2-5 1C9 19 5 15 3 8 2 5 3 3 5 3z"/>',
        email: '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 6 9 7 9-7"/>',
        address: '<path d="M19 10c0 5-7 12-7 12S5 15 5 10a7 7 0 1 1 14 0z"/><circle cx="12" cy="10" r="2"/>',
        money: '<rect x="2" y="5" width="20" height="14" rx="2"/><circle cx="12" cy="12" r="3"/><path d="M5 9h1m12 6h1"/>',
        bank: '<path d="m2 8 10-6 10 6zM4 10v9m5-9v9m6-9v9m5-9v9M2 22h20M2 19h20"/>',
        card: '<rect x="2" y="4" width="20" height="16" rx="2"/><path d="M2 9h20M6 15h4"/>',
        save: '<path d="M3 3h15l3 3v15H3zM7 3v6h10V3M7 21v-8h10v8"/>',
    };
    return `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${paths[kind] || paths.user}</svg>`;
}

function customerField(name, label, type, value = '', icon = '', full = false) {
    const icons = {phone:'phone',email:'email',address:'address',tax_code:'document',legal_representative:'user',representative_position:'user',billing_address:'address',credit_limit:'money',payment_terms:'document',bank_name:'bank',bank_account_no:'card',bank_account_name:'user'};
    const placeholders = {name:'Nhập tên khách hàng / tổ chức...',contact_name:'Nhập tên người liên hệ...',phone:'Nhập số điện thoại...',email:'Nhập email...',address:'Nhập địa chỉ liên hệ...',tax_code:'Nhập mã số thuế...',legal_representative:'Nhập người đại diện pháp luật...',representative_position:'Nhập chức vụ...',billing_address:'Nhập địa chỉ xuất hóa đơn...',payment_terms:'Nhập điều khoản...',bank_name:'Nhập tên ngân hàng...',bank_account_no:'Nhập số tài khoản...',bank_account_name:'Nhập tên tài khoản...'};
    return `
        <div class="field customer-field ${full ? 'full' : ''}">
            <label for="${name}">${VKTable.escapeHtml(label)}${name === 'name' ? ' <em class="customer-required">*</em>' : ''}</label>
            <div class="customer-input-wrap ${icons[name] ? 'has-icon' : ''}">
                ${icons[name] ? `<span aria-hidden="true">${customerFormIcon(icons[name])}</span>` : ''}
                <input id="${name}" name="${name}" type="${type}" value="${VKTable.escapeHtml(value)}" placeholder="${placeholders[name] || ''}" ${name === 'name' ? 'required' : ''} ${type === 'number' ? 'min="0" step="any"' : ''}>
            </div>
        </div>
    `;
}

function customerSelect(name, label, options, selected = '', icon = '') {
    return `
        <div class="field customer-field">
            <label for="${name}">${VKTable.escapeHtml(label)}${name === 'customer_type' ? ' <em class="customer-required">*</em>' : ''}</label>
            <div class="customer-input-wrap ${name === 'status' ? 'has-icon' : ''}">
                ${name === 'status' ? `<span aria-hidden="true"><i class="customer-status-dot ${selected === 'inactive' ? 'inactive' : ''}"></i></span>` : ''}
                <select id="${name}" name="${name}">
                    ${options.map(item => `<option value="${VKTable.escapeHtml(item.value)}" ${String(item.value) === String(selected) ? 'selected' : ''}>${VKTable.escapeHtml(item.label)}</option>`).join('')}
                </select>
            </div>
        </div>
    `;
}

async function openCustomerDetail(id) {
    customerState.view = 'detail';
    customerState.currentId = id;
    const root = document.getElementById('customersRoot');
    try {
        const response = await VKApi.request(`/customers/${id}`);
        customerState.detailCache[String(id)] = response;
        openCustomerModal(Number(id), true, true);
    } catch (error) {
        customerState.view = 'list';
        customerState.currentId = null;
        renderCustomers();
        throw error;
    }
}

document.addEventListener('click',event=>{
    if(event.target.closest('[data-customer-care-board]'))openCustomerCareBoard().catch(error=>VKModal.toast(error.message,'danger'));
});
async function openCustomerCareBoard(bucket='today',page=1) {
    const response=await VKApi.request(`/customers/care-board?bucket=${encodeURIComponent(bucket)}&page=${page}`,{cache:false});
    const labels={overdue:'Quá hạn',today:'Hôm nay',upcoming:'Sắp tới',unscheduled:'Chưa có lịch'};
    const esc=VKTable.escapeHtml;
    const cards=Object.entries(labels).map(([key,label])=>`<button type="button" class="btn ${key===bucket?'primary':''}" data-care-bucket="${key}">${label} (${Number(response.counts[key])})</button>`).join('');
    const rows=response.data.map(row=>{
        const customer=bucket==='unscheduled'?row:row.customer;
        return `<tr><td>${esc(customer?.code || '')} · ${esc(customer?.name || '')}</td><td>${esc(bucket==='unscheduled'?'Chưa có lịch tiếp theo':row.title)}</td><td>${bucket==='unscheduled'?'—':esc(formatCustomer360Date(row.due_at))}</td><td>${esc((bucket==='unscheduled'?row.sales_owner:row.assignee)?.name || 'Chưa phân công')}</td><td><button type="button" class="btn small" data-care-board-customer="${Number(customer?.id)}">Mở chăm sóc</button></td></tr>`;
    }).join('');
    VKModal.open('Việc chăm sóc khách hàng',`<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px">${cards}</div><p class="form-note">Ngày tính theo giờ Việt Nam. Việc quá hạn trong hôm nay có thể xuất hiện ở cả hai nhóm.</p><div class="table-wrap"><table class="list-table"><thead><tr><th>Khách hàng</th><th>Công việc</th><th>Hẹn thực hiện</th><th>Phụ trách</th><th></th></tr></thead><tbody>${rows || '<tr><td colspan="5">Không có hồ sơ trong nhóm này.</td></tr>'}</tbody></table></div><nav style="display:flex;gap:12px;margin-top:16px"><button type="button" class="btn small" data-care-board-page="${page-1}" ${page<=1?'disabled':''}>Trang trước</button><span>Trang ${page} / ${response.meta.last_page}</span><button type="button" class="btn small" data-care-board-page="${page+1}" ${page>=response.meta.last_page?'disabled':''}>Trang sau</button></nav>`,null,{className:'modal-wide',hideSubmit:true});
    const body=document.getElementById('modalBody');
    const run=promise=>promise.catch(error=>VKModal.toast(error.message,'danger'));
    body.querySelectorAll('[data-care-bucket]').forEach(button=>button.onclick=()=>run(openCustomerCareBoard(button.dataset.careBucket)));
    body.querySelectorAll('[data-care-board-page]').forEach(button=>button.onclick=()=>run(openCustomerCareBoard(bucket,Number(button.dataset.careBoardPage))));
    body.querySelectorAll('[data-care-board-customer]').forEach(button=>button.onclick=()=>run(openCustomerCare(Number(button.dataset.careBoardCustomer))));
}

async function openCustomerCare(id, page = 1) {
    const response = await VKApi.request(`/customers/${id}/care?page=${page}`, {cache:false});
    const data = response.data, esc = VKTable.escapeHtml;
    const canWrite = VKLayout.hasPermission('task.create');
    VKModal.open('Chăm sóc khách hàng', `
        <div class="form-grid">
            <div class="field"><label>Phụ trách</label><strong>${esc(data.owner?.name || 'Chưa phân công')}${data.owner && !data.owner.is_active ? ' · Đã khóa' : ''}</strong></div>
            <div class="field"><label>Lần chăm sóc ghi nhận gần nhất</label><strong>${esc(data.last_contact_at ? formatCustomer360Date(data.last_contact_at) : 'Chưa ghi nhận')}</strong></div>
            <div class="field"><label>Kết quả gần nhất</label><span>${esc(data.last_result || '—')}</span></div>
            <div class="field"><label>Quá hạn</label><strong>${data.overdue_count} công việc</strong></div>
        </div>
        <p class="form-note">Việc tiếp theo: ${data.next_task ? `${esc(data.next_task.title)} · ${esc(formatCustomer360Date(data.next_task.due_at))}` : 'Chưa có lịch chăm sóc tiếp theo'}</p>
        ${canWrite ? `<div class="form-grid"><div class="field"><label>Nội dung chăm sóc *</label><input name="title" required maxlength="255" placeholder="Gọi lại, hẹn gặp, gửi thông tin…"></div><div class="field"><label>Hẹn thực hiện *</label><input name="due_at" type="datetime-local" required></div></div>` : ''}
        <h3>Lịch chăm sóc (${response.meta.total})</h3>
        <nav style="display:flex;gap:12px;align-items:center"><button type="button" class="btn small" data-care-page="${page-1}" ${page<=1?'disabled':''}>Trang trước</button><span>Trang ${page} / ${response.meta.last_page}</span><button type="button" class="btn small" data-care-page="${page+1}" ${page>=response.meta.last_page?'disabled':''}>Trang sau</button></nav>
        <div style="max-height:35vh;overflow:auto">${data.tasks.length ? data.tasks.map(task=>`<div class="panel" style="padding:12px;margin:8px 0"><strong>${esc(task.title)}</strong><span class="row-note">${esc(task.code)} · ${esc(task.assignee?.name || 'Chưa phân công')} · ${esc(formatCustomer360Date(task.due_at))}</span>${VKTable.statusBadge(task.status)} ${canWrite && ['new','in_progress','overdue'].includes(task.status) ? `<button type="button" class="btn small" data-complete-care="${task.id}">Ghi kết quả</button>` : ''}</div>`).join('') : '<p class="form-note">Chưa có lịch chăm sóc.</p>'}</div>
    `, async form => {
        await VKApi.request(`/customers/${id}/care`, {method:'POST',body:JSON.stringify({title:form.elements.title.value,due_at:new Date(form.elements.due_at.value).toISOString()})});
        VKModal.toast('Đã tạo lịch và thông báo người phụ trách.');
        await openCustomerCare(id);
    }, {className:'modal-wide',submitText:'Thêm lịch chăm sóc',hideSubmit:!canWrite});
    document.querySelectorAll('[data-care-page]').forEach(button=>button.onclick=()=>openCustomerCare(id, Number(button.dataset.carePage)));
    document.querySelectorAll('[data-complete-care]').forEach(button=>button.onclick=()=> {
        VKModal.open('Ghi kết quả và hẹn tiếp', `${VKModal.select('outcome','Kết quả',[{value:'contacted',label:'Đã liên hệ'},{value:'no_answer',label:'Không nghe máy'},{value:'meeting',label:'Đã gặp'},{value:'information_sent',label:'Đã gửi thông tin'}])}<div class="field"><label>Ghi chú *</label><textarea name="note" required maxlength="400" rows="4"></textarea></div><div class="form-grid"><div class="field"><label>Việc tiếp theo</label><input name="next_title" maxlength="255" placeholder="Gọi lại, gửi báo giá, hẹn gặp…"></div><div class="field"><label>Hẹn tiếp</label><input name="next_due_at" type="datetime-local"></div></div><p class="form-note">Không nghe máy: bắt buộc nhập việc và ngày hẹn lại. Các kết quả khác có thể để trống nếu chưa cần hẹn tiếp.</p>`, async form=> {
            const payload=Object.fromEntries(new FormData(form));
            if(payload.next_due_at)payload.next_due_at=new Date(payload.next_due_at).toISOString();
            await VKApi.request(`/customers/${id}/care/${button.dataset.completeCare}/complete`,{method:'POST',body:JSON.stringify(payload)});
            VKModal.toast(payload.next_due_at?'Đã lưu kết quả và tạo lịch tiếp theo.':'Đã lưu kết quả.');
            await openCustomerCare(id);
        },{submitText:'Lưu kết quả'});
    });
}

function renderCustomer360(customer, data) {
    const summary = data.summary || {};
    const paid = Number(summary.payment_value || 0);
    const orderValue = Number(summary.order_value || 0);
    const balance = Math.max(0, orderValue - paid);
    return `
        <section class="customer360-page">
            <header class="customer360-hero">
                <div class="customer360-hero-top">
                    <button class="customer360-back" type="button" data-customer360-back>← Danh sách khách hàng</button>
                    <div class="customer360-hero-actions">
                        <span class="customer360-id">${VKTable.escapeHtml(customer.code || 'CUS')}</span>
                        <button class="btn small" type="button" data-edit-customer="${customer.id}">Chỉnh sửa hồ sơ</button>
                    </div>
                </div>
                <div class="customer360-identity">
                    <div class="customer360-avatar">${VKTable.escapeHtml((customer.name || 'K').trim().slice(0, 1).toUpperCase())}</div>
                    <div>
                        <h1>${VKTable.escapeHtml(customer.name || 'Khách hàng')}</h1>
                        <span>${VKTable.escapeHtml(customer.contact_name || 'Chưa có người liên hệ')} · ${VKTable.escapeHtml(customer.phone || 'Chưa có số điện thoại')} · ${VKTable.escapeHtml(customer.email || 'Chưa có email')}</span>
                    </div>
                    <div class="customer360-owner"><span>Người phụ trách</span><strong>${VKTable.escapeHtml(customer.sales_owner?.name || 'Chưa phân công')}</strong><em class="customer360-status ${customer.status === 'active' ? 'active' : ''}">${customer.status === 'active' ? 'Đang hoạt động' : 'Tạm ngưng'}</em></div>
                </div>
            </header>
            <nav class="customer360-tabs" aria-label="Thông tin hồ sơ khách hàng">
                <span class="active">Tổng quan</span><span>Hành trình khách</span><span>Doanh thu</span><span>Chăm sóc</span><span>Dữ liệu & đồng ý</span>
            </nav>
            <div class="customer360-body">
                <section class="customer360-metrics">
                    ${customer360Metric('Đơn hàng', summary.order_count || 0, 'Đơn phát sinh')}
                    ${customer360Metric('Giá trị mua', VKTable.money(orderValue), 'Tổng giá trị đơn hàng', 'blue')}
                    ${customer360Metric('Đã thanh toán', VKTable.money(paid), 'Theo chứng từ thu', 'green')}
                    ${customer360Metric('Cần thu', VKTable.money(balance), balance ? 'Cần theo dõi công nợ' : 'Không còn công nợ', balance ? 'orange' : 'green')}
                    ${customer360Metric('Ticket mở', summary.open_ticket_count || 0, 'Yêu cầu cần xử lý', summary.open_ticket_count ? 'orange' : 'purple')}
                </section>
                <div class="customer360-grid">
                    <article class="customer360-card customer360-profile">
                        <div class="customer360-card-title"><span>◉</span><h2>Thông tin hồ sơ</h2><small>Dữ liệu chuẩn</small></div>
                        ${customer360Info('Mã khách hàng', customer.code)}
                        ${customer360Info('Loại khách hàng', customer.customer_type === 'person' ? 'Cá nhân' : 'Tổ chức / doanh nghiệp')}
                        ${customer360Info(customer.customer_type === 'person' ? 'Số CCCD' : 'Mã số thuế', (customer.customer_type === 'person' ? customer.identity_number : customer.tax_code) || 'Chưa cập nhật')}
                        ${customer360Info('Địa chỉ', customer.billing_address || customer.address || 'Chưa cập nhật')}
                        ${customer360Info('Điều khoản thanh toán', customer.payment_terms || 'Chưa thiết lập')}
                        ${customer360Info('Hạn mức công nợ', VKTable.money(customer.credit_limit || 0))}
                    </article>
                    <article class="customer360-card customer360-journey">
                        <div class="customer360-card-title"><span>⌁</span><h2>Hành trình gần đây</h2><small>${customer360EventCount(data)} sự kiện</small></div>
                        <div class="customer360-timeline">${renderCustomer360Timeline(data)}</div>
                    </article>
                </div>
                ${customer.customer_type !== 'person' ? renderCustomerContacts(customer) : ''}
                <div class="customer360-grid customer360-sales-grid">
                    ${customer360ListCard('Cơ hội bán hàng', '◌', data.deals, item => `<strong>${VKTable.escapeHtml(item.name || item.code)}</strong><span>${VKTable.escapeHtml(item.stage || 'Mới')} · ${VKTable.money(item.amount || 0)}</span>`, '/deals')}
                    ${customer360ListCard('Báo giá', '▤', data.quotations, item => `<strong>${VKTable.escapeHtml(item.code)}</strong><span>${VKTable.statusBadge(item.status)} <b>${VKTable.money(item.total_amount || 0)}</b></span>`, '/quotations')}
                    ${customer360ListCard('Đơn hàng', '✓', data.orders, item => `<strong>${VKTable.escapeHtml(item.code)}</strong><span>${VKTable.statusBadge(item.status)} <b>${VKTable.money(item.total_amount || 0)}</b></span>`, '/sales-orders')}
                </div>
                <div class="customer360-grid customer360-bottom-grid">
                    ${customer360ListCard('Hợp đồng', '▣', data.contracts, item => `<strong>${VKTable.escapeHtml(item.name || item.code)}</strong><span>${VKTable.escapeHtml(item.code)} · ${VKTable.money(item.total_amount || 0)}</span>`, '/contracts')}
                    ${customer360ListCard('Chăm sóc & ticket', '◐', data.tickets, item => `<strong>${VKTable.escapeHtml(item.subject || item.code)}</strong><span>${VKTable.statusBadge(item.status)} · ${VKTable.escapeHtml(item.priority || 'Thường')}</span>`, '/service-tickets')}
                    ${customer360ListCard('Thanh toán gần đây', '₫', data.payments, item => `<strong>${VKTable.escapeHtml(item.code)}</strong><span>${VKTable.money(item.amount || 0)} · ${formatCustomer360Date(item.paid_at || item.created_at)}</span>`, '/customer-payments')}
                </div>
            </div>
        </section>`;
}

function renderCustomerContacts(customer) {
    const contacts = customer.contacts || [];
    return `<article class="customer360-card customer360-contacts">
        <div class="customer360-card-title"><span>♙</span><h2>Người liên hệ (${contacts.length})</h2><button type="button" data-customer-contact="0">+ Thêm người liên hệ</button></div>
        ${contacts.length ? contacts.map(item => `<div class="customer360-info">
            <span>${item.is_primary ? 'Liên hệ chính' : 'Người liên hệ'}</span>
            <strong>${VKTable.escapeHtml(item.name)}${item.position ? ` · ${VKTable.escapeHtml(item.position)}` : ''}<small> ${VKTable.escapeHtml(item.phone || '')} ${VKTable.escapeHtml(item.email || '')}</small></strong>
            <button type="button" class="btn small" data-customer-contact="${item.id}">Sửa</button>
            <button type="button" class="btn small" data-delete-customer-contact="${item.id}">Xóa</button>
        </div>`).join('') : '<p class="customer360-empty">Chưa có người liên hệ. Thêm đầu mối để giữ lịch sử làm việc với tổ chức.</p>'}
    </article>`;
}

function openCustomerContactModal(id = 0) {
    const customerId = customerState.currentId;
    const customer = customerState.detailCache[String(customerId)]?.data;
    if (!customer || customer.customer_type === 'person') return;
    const contact = (customer.contacts || []).find(item => item.id === id) || {};
    VKModal.open(id ? 'Sửa người liên hệ' : 'Thêm người liên hệ', `
        ${customerField('contact_name', 'Họ tên', 'text', contact.name || '')}
        ${customerField('contact_position', 'Chức vụ', 'text', contact.position || '')}
        ${customerField('contact_phone', 'Số điện thoại', 'text', contact.phone || '')}
        ${customerField('contact_email', 'Email', 'email', contact.email || '')}
        <label class="field"><input name="contact_primary" type="checkbox" ${contact.is_primary ? 'checked' : ''}> Liên hệ chính</label>
    `, async form => {
        const values = Object.fromEntries(new FormData(form));
        await VKApi.request(`/customers/${customerId}/contacts${id ? `/${id}` : ''}`, {
            method: id ? 'PUT' : 'POST',
            body: JSON.stringify({ name: values.contact_name, position: values.contact_position, phone: values.contact_phone, email: values.contact_email, is_primary: Boolean(values.contact_primary) }),
        });
        VKModal.close();
        await openCustomerDetail(customerId);
        VKModal.toast('Đã lưu người liên hệ.');
    }, { submitText: 'Lưu người liên hệ' });
}

async function deleteCustomerContact(id) {
    const customerId = customerState.currentId;
    if (!customerId || !window.confirm('Xóa người liên hệ này khỏi hồ sơ?')) return;
    await VKApi.request(`/customers/${customerId}/contacts/${id}`, { method: 'DELETE' });
    await openCustomerDetail(customerId);
    VKModal.toast('Đã xóa người liên hệ.');
}

function customer360Metric(label, value, note, tone = '') {
    const paths = {
        'Đơn hàng': '<path d="M6 3h12v18H6zM9 8h6M9 12h6M9 16h3"/>',
        'Giá trị mua': '<path d="M3 3h2l3 12h10l3-9H6M9 20h.01M18 20h.01"/>',
        'Đã thanh toán': '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 9h18M8 14l2 2 4-4"/>',
        'Cần thu': '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M15 12h6M7 9h3M7 15h3"/>',
        'Ticket mở': '<path d="M4 4h16v12H9l-5 4V4ZM8 8h8M8 12h5"/>'
    };
    const icon = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${paths[label] || paths['Đơn hàng']}</svg>`;
    return `<article class="customer360-metric ${tone}"><span class="customer-metric-label"><i>${icon}</i>${VKTable.escapeHtml(label)}</span><strong>${VKTable.escapeHtml(String(value))}</strong><small>${VKTable.escapeHtml(note)}</small></article>`;
}

function customer360Info(label, value) {
    return `<div class="customer360-info"><span>${VKTable.escapeHtml(label)}</span><strong class="${/^Mã(?:\s|$)/i.test(label) ? 'record-code-text' : ''}">${VKTable.escapeHtml(String(value ?? '-'))}</strong></div>`;
}

function customer360ListCard(title, icon, rows = [], renderRow, route) {
    const body = rows.length ? rows.slice(0, 4).map(row => `<li>${renderRow(row)}</li>`).join('') : '<li class="customer360-empty">Chưa có dữ liệu phát sinh</li>';
    return `<article class="customer360-card customer360-list-card"><div class="customer360-card-title"><span>${icon}</span><h2>${title}</h2><button type="button" data-customer360-route="${route}">Xem tất cả →</button></div><ul>${body}</ul></article>`;
}

function customer360EventCount(data) {
    return ['leads', 'deals', 'quotations', 'orders', 'contracts', 'tickets', 'payments'].reduce((count, key) => count + (data[key]?.length || 0), 0);
}

function renderCustomer360Timeline(data) {
    const events = [
        ...(data.orders || []).map(row => ({ at: row.created_at, type: 'Đơn hàng', title: row.code, note: VKTable.money(row.total_amount || 0), tone: 'blue' })),
        ...(data.quotations || []).map(row => ({ at: row.created_at, type: 'Báo giá', title: row.code, note: customer360StatusText(row.status), tone: 'purple' })),
        ...(data.tickets || []).map(row => ({ at: row.created_at, type: 'Chăm sóc', title: row.subject || row.code, note: customer360StatusText(row.status), tone: 'orange' })),
        ...(data.payments || []).map(row => ({ at: row.paid_at || row.created_at, type: 'Thanh toán', title: row.code, note: VKTable.money(row.amount || 0), tone: 'green' })),
    ].sort((a, b) => new Date(b.at || 0) - new Date(a.at || 0)).slice(0, 5);
    if (!events.length) return '<div class="customer360-empty-timeline"><span class="customer-empty-symbol" aria-hidden="true">↗</span><strong>Chưa có hoạt động</strong><span>Báo giá, đơn hàng và thanh toán sẽ được cập nhật tại đây.</span></div>';
    return events.map(event => `<div class="customer360-event ${event.tone}"><i></i><div><span>${VKTable.escapeHtml(event.type)} · ${formatCustomer360Date(event.at)}</span><strong>${VKTable.escapeHtml(event.title)}</strong><small>${VKTable.escapeHtml(event.note)}</small></div></div>`).join('');
}

function formatCustomer360Date(value) {
    if (!value) return 'Chưa có ngày';
    const date = new Date(value);
    return Number.isNaN(date.getTime()) ? String(value) : date.toLocaleDateString('vi-VN');
}

function customer360StatusText(status) {
    return ({ draft: 'Nháp', pending: 'Chờ duyệt', approved: 'Đã duyệt', ready: 'Sẵn sàng', confirmed: 'Đã xác nhận', delivered: 'Đã giao hàng', open: 'Đang mở', in_progress: 'Đang xử lý', resolved: 'Đã xử lý', closed: 'Đã đóng' })[status] || String(status || 'Đang cập nhật');
}

window.loadCustomers = loadCustomers;
})();
