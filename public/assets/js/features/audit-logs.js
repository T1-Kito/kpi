(function () {
let auditState = {
    rows: [],
    users: [],
    q: '',
    userId: '',
    entityType: '',
    action: '',
    from: '',
    to: '',
    total: 0,
};
let auditLoadVersion = 0;

const auditFields = {
    id:'Mã tham chiếu',code:'Mã hồ sơ',name:'Tên',title:'Tiêu đề',status:'Trạng thái',email:'Email',phone:'Số điện thoại',address:'Địa chỉ',
    customer_type:'Loại khách hàng',tax_code:'Mã số thuế',identity_number:'Số căn cước',billing_address:'Địa chỉ xuất hóa đơn',legal_representative:'Người đại diện pháp luật',representative_position:'Chức vụ đại diện',
    credit_limit:'Hạn mức công nợ',payment_terms:'Điều khoản thanh toán',bank_name:'Ngân hàng',bank_account:'Số tài khoản',bank_account_name:'Tên tài khoản',is_shared:'Dùng chung',is_primary:'Liên hệ chính',is_active:'Đang sử dụng',
    assigned_to:'Người phụ trách',sales_owner_id:'Người phụ trách',user_id:'Người dùng',created_by:'Người tạo',approved_by:'Người duyệt',customer_id:'Khách hàng',contact_id:'Người liên hệ',customer_contact_id:'Người liên hệ',
    stage:'Giai đoạn',stage_id:'Giai đoạn',source:'Nguồn',source_id:'Nguồn dữ liệu',campaign_id:'Chiến dịch',campaign_code:'Mã chiến dịch',customer_group_id:'Nhóm khách hàng',parent_id:'Nhóm cha',category_id:'Danh mục sản phẩm',brand_id:'Hãng sản phẩm',
    amount:'Số tiền',total:'Tổng cộng',total_amount:'Tổng tiền',subtotal:'Tạm tính',tax_amount:'Tiền thuế',vat_rate:'Thuế VAT (%)',discount_percent:'Chiết khấu (%)',discount_amount:'Tiền chiết khấu',unit_price:'Đơn giá',quantity:'Số lượng',unit:'Đơn vị tính',cost_price:'Giá vốn',sale_price:'Giá bán',
    note:'Ghi chú',notes:'Ghi chú',reason:'Lý do',description:'Mô tả',due_at:'Hạn xử lý',due_date:'Ngày đến hạn',created_at:'Ngày tạo',updated_at:'Ngày cập nhật',completed_at:'Ngày hoàn thành',valid_until:'Hiệu lực đến',expected_close_date:'Dự kiến chốt',
    payment_method:'Phương thức thanh toán',payment_plan:'Cách thanh toán',deposit_percent:'Tỷ lệ đặt cọc (%)',deposit_due_date:'Hạn đặt cọc',balance_due_date:'Hạn thanh toán còn lại',credit_days:'Số ngày công nợ',
    roles:'Vai trò',role_ids:'Vai trò',permissions:'Quyền được cấp',department_id:'Phòng ban',position_id:'Chức vụ',data_scope:'Phạm vi dữ liệu',
    items:'Danh sách hàng hóa',sku_id:'Mã hàng',product_id:'Sản phẩm',warehouse_id:'Kho',warehouse_location_id:'Vị trí kho',supplier_id:'Nhà cung cấp',quotation_id:'Báo giá',sales_order_id:'Đơn bán',purchase_order_id:'Đơn mua',
    old_status:'Trạng thái trước',new_status:'Trạng thái sau',from:'Trước',to:'Sau',row:'Dòng nhập',created:'Số hồ sơ đã tạo',updated:'Số hồ sơ cập nhật',failed:'Số dòng lỗi',action:'Thao tác',external_id:'Mã ngoài',source_revision:'Lần cập nhật nguồn',schema_version:'Phiên bản cấu trúc',
    purpose:'Mục đích',group_size:'Quy mô nhóm',guest_count:'Số khách',service_date:'Ngày sử dụng dịch vụ',budget:'Ngân sách',special_requirements:'Yêu cầu đặc biệt',service_interest_ids:'Dịch vụ quan tâm',estimated_value:'Giá trị dự kiến',probability:'Xác suất (%)',
};
const auditEntities={deal:'Cơ hội bán hàng',customer_contact:'Người liên hệ',customer_group:'Nhóm khách hàng',contract:'Hợp đồng',contract_milestone:'Mốc nghiệm thu hợp đồng',delivery:'Giao hàng',sales_invoice:'Hóa đơn bán hàng',customer_payment:'Thu tiền khách hàng',product_category:'Danh mục sản phẩm',category:'Danh mục sản phẩm',brand:'Hãng sản phẩm',warehouse_location:'Vị trí kho',supplier_quotation:'Báo giá nhà cung cấp',import_batch:'Lần nhập Excel',crm_intake:'Danh sách tổng hợp',installation:'Cài đặt phần mềm',service_ticket:'Yêu cầu hỗ trợ',warranty:'Bảo hành',customer_duplicate_review:'Hồ sơ trùng khách hàng',business_report:'Báo cáo nghiệp vụ',print_template:'Mẫu in',tenant_setting:'Thiết lập doanh nghiệp',service_interest:'Danh mục dịch vụ'};
const auditActions={qualify_lead:'Chuyển khách tiềm năng thành khách hàng và cơ hội',transition_deal:'Chuyển giai đoạn cơ hội',update_deal_needs:'Cập nhật nhu cầu khách hàng',update_deal_items:'Cập nhật hàng hóa của cơ hội',create_customer_quick:'Thêm nhanh khách hàng',create_customer_from_lead:'Tạo khách hàng từ khách tiềm năng',create_contact_from_lead:'Tạo người liên hệ từ khách tiềm năng',merge_customer:'Gộp hồ sơ khách hàng trùng',scan_customer_duplicates:'Kiểm tra khách hàng trùng',dismiss_customer_duplicate:'Xác nhận hai hồ sơ không trùng',commit_import_batch:'Xác nhận nhập Excel',preview_import_batch:'Kiểm tra file Excel',download_import_errors:'Tải danh sách lỗi nhập Excel',commit_import_row:'Nhập một dòng dữ liệu',commit_crm_intake:'Nhập danh sách tổng hợp',import_customer_groups:'Nhập nhóm khách hàng',import_inactive_user:'Nhập tài khoản chưa kích hoạt',delete_draft_quotation:'Xóa báo giá nháp',record_customer_payment:'Ghi nhận thu tiền',confirm_delivery_acceptance:'Xác nhận nghiệm thu giao hàng',initialize_installation:'Thiết lập dữ liệu ban đầu',sync_procurement_approval:'Đồng bộ phê duyệt mua hàng',provision_chairman:'Thiết lập tài khoản Chủ tịch'};
function auditEntity(value){
    const key=String(value||'').split('\\').pop();
    if(auditEntities[key])return auditEntities[key];
    if(key.startsWith('import_'))return `Nhập dữ liệu ${auditEntity(key.slice(7))}`;
    const translated=VKTable.translateEntity(key);
    return translated!==key?translated:'Hồ sơ nghiệp vụ';
}
function auditAction(value){
    if(auditActions[value])return auditActions[value];
    const known=VKTable.translateAction(value);
    if(known && known!==value)return known;
    const verbs={create:'Tạo',update:'Cập nhật',delete:'Xóa',approve:'Phê duyệt',reject:'Từ chối',confirm:'Xác nhận',activate:'Kích hoạt',export:'Xuất',import:'Nhập',issue:'Phát hành',cancel:'Hủy',submit:'Gửi duyệt',publish:'Phát hành',lock:'Khóa',unlock:'Mở khóa'};
    const parts=String(value||'').split('_');const verb=verbs[parts.shift()];
    return verb?`${verb} ${auditEntity(parts.join('_')).toLocaleLowerCase('vi-VN')}`:'Thực hiện thao tác nghiệp vụ';
}
function auditReference(row){
    const data=row.new_value||row.old_value||{};
    return [data.code,data.name||data.title].filter(Boolean).join(' · ') || (row.entity_id?`Mã tham chiếu #${row.entity_id}`:'Toàn hệ thống');
}
const auditSecret=key=>/password|token|secret|app_key|private_key|credential/i.test(key);
function auditValue(value,key=''){
    if(value===null||value===undefined||value==='')return 'Chưa có';
    if(typeof value==='boolean')return value?'Có':'Không';
    if(['is_shared','is_primary','is_active'].includes(key))return Number(value)?'Có':'Không';
    if(['assigned_to','sales_owner_id','user_id','approved_by','created_by'].includes(key))return auditState.users.find(user=>String(user.id)===String(value))?.name || `Người dùng #${value}`;
    if(['status','old_status','new_status','stage','customer_type','payment_plan','data_scope','action'].includes(key)){
        const values={active:'Đang hoạt động',inactive:'Ngừng hoạt động',new:'Mới',assigned:'Đã phân công',qualified:'Đã xác định nhu cầu',draft:'Nháp',pending:'Chờ xử lý',pending_approval:'Chờ duyệt',approved:'Đã duyệt',rejected:'Đã từ chối',open:'Đang mở',closed:'Đã đóng',completed:'Hoàn thành',in_progress:'Đang thực hiện',cancelled:'Đã hủy',accepted:'Khách đồng ý',issued:'Đã phát hành',awaiting_customer:'Chờ khách xác nhận',paid:'Đã thanh toán',unpaid:'Chưa thanh toán',partially_paid:'Thanh toán một phần',individual:'Cá nhân',company:'Doanh nghiệp',organization:'Tổ chức',personal:'Cá nhân',business:'Doanh nghiệp',deposit:'Đặt cọc và thanh toán còn lại',credit:'Công nợ',prepaid:'Thanh toán trước',custom:'Theo thỏa thuận',own:'Cá nhân',department:'Phòng ban',tenant:'Toàn công ty',create:'Tạo mới',update:'Cập nhật'};
        if(key==='action')return auditAction(value);
        if(key==='status' && value==='qualified')return 'Đã chuyển cơ hội';
        if(key==='data_scope' && value==='company')return 'Toàn công ty';
        return values[value] || 'Giá trị nghiệp vụ khác';
    }
    if(Array.isArray(value))return value.length?value.map(item=>auditValue(item,key)).join('; '):'Không có mục nào';
    if(typeof value==='object')return Object.entries(value).filter(([k])=>!auditSecret(k)).map(([k,v])=>`${auditFields[k]||'Thông tin bổ sung'}: ${auditValue(v,k)}`).join('; ')||'Chưa có';
    if(/(_at|_date)$/.test(key)&&!Number.isNaN(Date.parse(value)))return formatDateTime(value);
    return String(value);
}
function auditChanges(row){
    const before=row.old_value&&typeof row.old_value==='object'?row.old_value:{};
    const after=row.new_value&&typeof row.new_value==='object'?row.new_value:{};
    return [...new Set([...Object.keys(before),...Object.keys(after)])]
        .filter(key=>!auditSecret(key)&&!['tenant_id','updated_at','created_at'].includes(key)&&JSON.stringify(before[key]??null)!==JSON.stringify(after[key]??null))
        .map(key=>({label:auditFields[key]||'Thông tin bổ sung',before:auditValue(before[key],key),after:auditValue(after[key],key)}));
}

document.addEventListener('vk:ready', () => loadAuditLogs());

document.addEventListener('input', (event) => {
    if (!document.getElementById('auditLogsRoot')) return;
    if (event.target.matches('[data-audit-search]')) {
        auditState.q = event.target.value.toLowerCase();
        renderAuditLogs();
    }
});

document.addEventListener('change', (event) => {
    if (!document.getElementById('auditLogsRoot')) return;
    if (event.target.matches('[data-audit-user]')) auditState.userId = event.target.value;
    if (event.target.matches('[data-audit-entity]')) auditState.entityType = event.target.value;
    if (event.target.matches('[data-audit-action]')) auditState.action = event.target.value;
    if (event.target.matches('[data-audit-from]')) auditState.from = event.target.value;
    if (event.target.matches('[data-audit-to]')) auditState.to = event.target.value;
    loadAuditLogs();
});

document.addEventListener('click', (event) => {
    if (!document.getElementById('auditLogsRoot')) return;

    if (event.target.matches('[data-audit-reset]')) {
        auditState = { ...auditState, q: '', userId: '', entityType: '', action: '', from: '', to: '' };
        loadAuditLogs();
        return;
    }

    const detail = event.target.closest('[data-audit-detail], tr[data-row-detail]');
    if (detail) openAuditDetail(detail.dataset.auditDetail || detail.dataset.rowDetail);
});

async function loadAuditLogs(options = {}) {
    const root = document.getElementById('auditLogsRoot');
    if (!root) return;
    const version = ++auditLoadVersion;
    if (options.source !== 'pjax') {
        root.innerHTML = renderLoading();
    }

    const params = new URLSearchParams({ page_size: '100' });
    if (auditState.userId) params.set('user_id', auditState.userId);
    if (auditState.entityType) params.set('entity_type', auditState.entityType);
    if (auditState.action) params.set('action', auditState.action);
    if (auditState.from) params.set('from', auditState.from);
    if (auditState.to) params.set('to', auditState.to);

    const [logs, users] = await Promise.all([
        VKApi.request(`/audit-logs?${params.toString()}`, {cache:false}),
        VKApi.request('/lookups/users'),
    ]);

    if (version !== auditLoadVersion || document.getElementById('auditLogsRoot') !== root) return;
    auditState.rows = logs.data || [];
    auditState.total = logs.meta?.total || auditState.rows.length;
    auditState.users = users.data || [];
    renderAuditLogs();
}

function renderAuditLogs() {
    const rows = filteredRows();
    const root = document.getElementById('auditLogsRoot');
    if (!root) return;
    const searching = root.contains(document.activeElement) && document.activeElement?.matches('[data-audit-search]');
    const cursor = searching ? document.activeElement.selectionStart : null;

    root.innerHTML = VKTable.fullList({
        title: 'Danh sách nhật ký',
        subtitle: 'Tra cứu thao tác vận hành, dữ liệu trước/sau và lý do xử lý.',
        meta: `${VKTable.money(rows.length)} / ${VKTable.money(auditState.total)} dòng`,
        filters: renderAuditFilters(),
        table: renderAuditTable(rows),
    });

    restoreAuditFilters();
    if(searching){const input=root.querySelector('[data-audit-search]');input?.focus();if(cursor!==null)input?.setSelectionRange(cursor,cursor);}
}

function renderAuditFilters() {
    return `
        <div class="list-filter audit-filter">
            <label class="list-search">
                <span aria-hidden="true">⌕</span>
                <input type="search" data-audit-search placeholder="Tìm người thao tác, hành động, đối tượng..." value="${VKTable.escapeHtml(auditState.q)}">
            </label>
            <select data-audit-user>
                ${option('', 'Tất cả người thao tác', auditState.userId)}
                ${auditState.users.map(user => option(String(user.id), `${user.name} - ${user.email}`, auditState.userId)).join('')}
            </select>
            <select data-audit-entity>
                ${option('', 'Tất cả đối tượng', auditState.entityType)}
                ${entityOptions().map(value => option(value, auditEntity(value), auditState.entityType)).join('')}
            </select>
            <select data-audit-action>
                ${option('', 'Tất cả hành động', auditState.action)}
                ${actionOptions().map(value => option(value, auditAction(value), auditState.action)).join('')}
            </select>
            <input type="date" data-audit-from value="${VKTable.escapeHtml(auditState.from)}" aria-label="Từ ngày">
            <input type="date" data-audit-to value="${VKTable.escapeHtml(auditState.to)}" aria-label="Đến ngày">
            <div class="list-filter-actions">
                <button class="btn small" type="button" data-audit-reset>Xóa lọc</button>
            </div>
        </div>
    `;
}

function renderAuditTable(rows) {
    return VKTable.renderTable([
        { label: 'Thời gian', render: row => `<span>${formatDateTime(row.created_at)}</span>` },
        { label: 'Người thao tác', render: row => renderActor(row) },
        { label: 'Đối tượng', render: row => renderEntity(row) },
        { label: 'Thao tác', render: row => `<span class="audit-action">${VKTable.escapeHtml(auditAction(row.action))}</span><small class="audit-change-count">${auditChanges(row).length ? `${auditChanges(row).length} thông tin được ghi nhận` : 'Không có thay đổi trường dữ liệu'}</small>` },
        { label: 'Lý do', render: row => `<span class="row-note">${VKTable.escapeHtml(row.reason || '-')}</span>` },
        { label: '', render: row => VKTable.rowActions([VKTable.smallButton('Mở', `data-audit-detail="${row.id}"`)]) },
    ], rows, 'Chưa có nhật ký hệ thống', { rowAttr: row => `data-row-detail="${row.id}"` });
}

function renderActor(row) {
    return `
        <span class="audit-actor">
            <strong>${VKTable.escapeHtml(row.user?.name || 'Hệ thống')}</strong>
            <small>${VKTable.escapeHtml(row.user?.email || '-')}</small>
        </span>
    `;
}

function renderEntity(row) {
    return `
        <span>
            ${VKTable.escapeHtml(auditEntity(row.entity_type))}
            <span class="row-note">${VKTable.escapeHtml(auditReference(row))}</span>
        </span>
    `;
}

function filteredRows() {
    return auditState.rows.filter(row => {
        const haystack = [
            row.user?.name,
            row.user?.email,
            row.entity_type,
            row.entity_id,
            row.action,
            auditEntity(row.entity_type),
            auditAction(row.action),
            auditReference(row),
            row.reason,
        ].join(' ').toLowerCase();

        return !auditState.q || haystack.includes(auditState.q);
    });
}

async function openAuditDetail(id) {
    const drawer = getAuditDrawer();
    if (!drawer.backdrop) return;

    drawer.backdrop.classList.add('open');
    drawer.body.scrollTop = 0;
    drawer.kicker.textContent = 'Nhật ký hệ thống';
    drawer.title.textContent = 'Đang tải...';
    drawer.body.innerHTML = renderLoading();

    try {
        const response = await VKApi.request(`/audit-logs/${id}`);
        renderAuditDetail(response.data);
    } catch (error) {
        drawer.title.textContent = 'Không tải được nhật ký';
        drawer.body.innerHTML = `<div class="empty"><strong>Có lỗi xảy ra</strong><span>${VKTable.escapeHtml(error.message)}</span></div>`;
    }
}

function renderAuditDetail(row) {
    const drawer = getAuditDrawer();
    drawer.kicker.textContent = 'Nhật ký hệ thống';
    drawer.title.textContent = auditAction(row.action);
    drawer.body.innerHTML = `
        <div class="detail-drawer-body audit-detail">
            <section class="detail-section">
                <h3>Thông tin thao tác</h3>
                <div class="detail-grid">
                    ${detailField('Người thao tác', row.user?.name || 'Hệ thống')}
                    ${detailField('Email', row.user?.email || '-')}
                    ${detailField('Thời gian', formatDateTime(row.created_at))}
                    ${detailField('Hồ sơ', `${auditEntity(row.entity_type)} · ${auditReference(row)}`)}
                    ${detailField('IP', row.ip_address || '-')}
                    ${detailField('Lý do', row.reason || '-')}
                </div>
            </section>
            <section class="detail-section">
                <h3>Dữ liệu thay đổi</h3>
                <div class="audit-diff-wrap"><table class="audit-diff-table"><thead><tr><th>Thông tin</th><th>Trước thao tác</th><th>Sau thao tác</th></tr></thead><tbody>
                    ${auditChanges(row).map(change=>`<tr><th>${VKTable.escapeHtml(change.label)}</th><td>${VKTable.escapeHtml(change.before)}</td><td>${VKTable.escapeHtml(change.after)}</td></tr>`).join('')||'<tr><td colspan="3">Không ghi nhận thay đổi trường dữ liệu cho thao tác này.</td></tr>'}
                </tbody></table></div>
            </section>
            <section class="detail-section">
                <h3>Trình duyệt</h3>
                <div class="detail-list-item"><span>${VKTable.escapeHtml(row.user_agent || '-')}</span></div>
            </section>
        </div>
    `;
}

function detailField(label, value) {
    return `
        <div class="detail-field">
            <span>${VKTable.escapeHtml(label)}</span>
            <strong>${VKTable.escapeHtml(value)}</strong>
        </div>
    `;
}

function entityOptions() {
    return unique(auditState.rows.map(row => row.entity_type).filter(Boolean));
}

function actionOptions() {
    return unique(auditState.rows.map(row => row.action).filter(Boolean));
}

function unique(values) {
    return [...new Set(values)].sort((a, b) => String(a).localeCompare(String(b)));
}

function restoreAuditFilters() {
    const search = document.querySelector('[data-audit-search]');
    if (search) search.value = auditState.q;
}

function option(value, label, current) {
    return `<option value="${VKTable.escapeHtml(value)}" ${String(value) === String(current) ? 'selected' : ''}>${VKTable.escapeHtml(label)}</option>`;
}

function formatDateTime(value) {
    if (!value) return '-';
    return new Date(value).toLocaleString('vi-VN');
}

function getAuditDrawer() {
    return {
        backdrop: document.querySelector('[data-detail-drawer-backdrop]'),
        kicker: document.querySelector('[data-detail-kicker]'),
        title: document.querySelector('[data-detail-title]'),
        body: document.querySelector('[data-detail-body]'),
    };
}

function renderLoading() {
    return `<section class="module-panel"><div class="skeleton"></div><div class="skeleton large"></div><div class="skeleton"></div></section>`;
}

window.loadAuditLogs = loadAuditLogs;
window.VKAuditPresentation = {action:auditAction,entity:auditEntity,changes:auditChanges,reference:auditReference};
})();
