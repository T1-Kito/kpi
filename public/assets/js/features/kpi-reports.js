(function () {
    let version = 0;
    let loadedQuery = null, canExport = false;
    let employees = [];
    let poll = null, pending = false;
    const exportButton = () => document.querySelector('[data-kpi-report-export]');
    const escape = (value) => VKTable.escapeHtml(String(value ?? ''));
    const number = (value) => new Intl.NumberFormat('vi-VN', {maximumFractionDigits: 2}).format(value);
    document.addEventListener('vk:ready', initialize);
    window.loadKpiReports = initialize;
    document.addEventListener('vk:page:changed', () => {
        if (!document.getElementById('kpiReportRoot')) { clearInterval(poll); poll = null; version++; pending = false; loadedQuery = null; }
    });
    document.addEventListener('visibilitychange', () => { if (!document.hidden) load(true); });
    window.addEventListener('focus', () => load(true));
    document.addEventListener('vk:kpi:changed', () => load(true));
    function initialize() {
        const form = document.getElementById('kpiReportFilters');
        if (!form || form.dataset.bound) return;
        form.dataset.bound = '1';
        const today = new Date();
        form.elements.date.value = `${today.getFullYear()}-${String(today.getMonth()+1).padStart(2,'0')}-${String(today.getDate()).padStart(2,'0')}`;
        form.addEventListener('submit', (event) => { event.preventDefault(); load(); });
        form.addEventListener('change', (event) => {
            loadedQuery = null;
            exportButton().disabled = true;
            if (event.target.name === 'department_id') updateEmployees(form);
            load();
        });
        document.querySelector('[data-kpi-report-export]').addEventListener('click', exportReport);
        load();
        clearInterval(poll);
        poll = setInterval(() => { if (!document.hidden) load(true); }, 30000);
    }
    function queryFor(form) {
        const params = new URLSearchParams();
        for (const [key, value] of new FormData(form)) if (value) params.set(key, value);
        return params.toString();
    }
    function updateEmployees(form) {
        const selected = form.elements.user_id.value;
        const department = form.elements.department_id.value;
        const list = employees.filter((user) => !department || String(user.department_id) === department);
        form.elements.user_id.innerHTML = '<option value="">Tất cả được xem</option>' + list.map((user) => `<option value="${user.id}">${escape(user.name)}</option>`).join('');
        if (list.some((user) => String(user.id) === selected)) form.elements.user_id.value = selected;
    }
    async function load(silent = false) {
        const root = document.getElementById('kpiReportRoot');
        const form = document.getElementById('kpiReportFilters');
        if (!root || !form) return;
        if (silent && pending) return;
        const current = ++version;
        const query = queryFor(form);
        pending = true;
        const button = exportButton();
        const sameQuery = loadedQuery === query;
        if (!sameQuery) {
            loadedQuery = null;
            button.disabled = true;
            delete root.dataset.content;
            root.innerHTML = '<p class="form-note">Đang tổng hợp báo cáo…</p>';
        }
        const sync = document.getElementById('kpiReportSync');
        if (sync) sync.textContent = sameQuery ? 'Đang cập nhật số liệu…' : 'Đang tổng hợp…';
        try {
            const result = await VKApi.request('/kpi/employee-report?' + query, {cache: false});
            if (current !== version || document.getElementById('kpiReportRoot') !== root) return;
            if (queryFor(form) !== query) return;
            if (!Array.isArray(result.data) || !result.meta) throw new Error('Không nhận được báo cáo.');
            employees = result.employees || [];
            const department = form.elements.department_id.value;
            const departments = new Map(employees.filter((user) => user.department_id).map((user) => [user.department_id, user.department]));
            form.elements.department_id.innerHTML = '<option value="">Trong phạm vi được xem</option>' + [...departments].map(([id, name]) => `<option value="${id}">${escape(name)}</option>`).join('');
            form.elements.department_id.value = department;
            updateEmployees(form);
            const content = renderReport(result);
            if (root.dataset.content !== content) {
                const expanded = Array.from(root.querySelectorAll('details[open]')).map(item => item.dataset.employee);
                root.innerHTML = content;
                root.dataset.content = content;
                root.querySelectorAll('details[data-employee]').forEach(item => { item.open = expanded.includes(item.dataset.employee); });
            }
            if (sync) sync.textContent = `Tự cập nhật mỗi 30 giây · Cập nhật lúc ${new Date(result.meta.as_of).toLocaleTimeString('vi-VN')}`;
            // Changing a filter while the request is running must not export the older selection.
            if (queryFor(form) === query) { loadedQuery = query; canExport = result.meta.can_export === true; button.disabled = !canExport; button.title = canExport ? 'Xuất theo kỳ và bộ lọc đang xem' : 'Chưa được cấp quyền xuất Excel KPI'; }
        } catch (error) {
            if (current !== version || document.getElementById('kpiReportRoot') !== root) return;
            if (sameQuery) {
                if (sync) sync.textContent = 'Chưa cập nhật được. Đang giữ số liệu lần gần nhất; hệ thống sẽ tự thử lại.';
            } else {
                root.innerHTML = `<p class="form-note">${escape(error.message || 'Không tải được báo cáo. Bấm Xem báo cáo để thử lại.')}</p>`;
                if (sync) sync.textContent = 'Không tải được số liệu mới.';
            }
        } finally {
            if (current === version) pending = false;
        }
    }
    function renderReport(result) {
        const totals = result.data.reduce((sum, row) => {
            for (const key of ['tasks_created', 'tasks_completed', 'tasks_overdue', 'net_points']) sum[key] += Number(row[key]);
            return sum;
        }, {tasks_created: 0, tasks_completed: 0, tasks_overdue: 0, net_points: 0});
        const assigned = result.data.filter(row => row.kpi_status !== 'unassigned');
        const achieved = result.data.filter(row => row.kpi_status === 'achieved');
        const notStarted = result.data.reduce((sum,row) => sum + Number(row.tasks_not_started || 0),0);
        return `<div class="kpi-report-summary kpi-management-summary">${[['Nhân viên trong phạm vi',result.data.length,'blue'],['Đạt tất cả chỉ tiêu',achieved.length,'green'],['Chưa đạt đầy đủ',assigned.length-achieved.length,'amber'],['Chưa được phân KPI',result.data.length-assigned.length,'slate']].map(([label,value,tone]) => `<div class="tone-${tone}"><small>${label}</small><strong>${number(value)}</strong></div>`).join('')}</div>
            <div class="kpi-work-summary"><span>Công việc tạo trong kỳ: <b>${number(totals.tasks_created)}</b></span><span>Hoàn thành hiện tại: <b>${number(totals.tasks_completed)}</b></span><span>Chưa bắt đầu: <b>${number(notStarted)}</b></span><span class="kpi-overdue">Đang quá hạn: <b>${number(totals.tasks_overdue)}</b></span></div>
            <p class="form-note">${escape(result.meta.period_start)} — ${escape(result.meta.period_end)} · ${result.meta.scope === 'company' ? 'Toàn công ty' : result.meta.scope === 'department' ? 'Phòng ban và bản thân' : 'Cá nhân'}.</p>
            <p class="form-note">${escape(result.meta.task_basis)} ${escape(result.meta.points_basis)}</p>
            <div class="table-wrap"><table class="data-table"><thead><tr><th>Nhân viên</th><th>Phòng ban / chức vụ</th><th>KPI /100</th><th>Tạo trong kỳ</th><th>Hoàn thành</th><th>Quá hạn</th><th>Điểm cộng</th><th>Điểm trừ</th><th>Điểm ròng</th><th>Chờ duyệt</th></tr></thead><tbody>${result.data.length ? result.data.map((row) => `<tr><td>${escape(row.name)}${row.is_active ? '' : '<small> (Ngừng hoạt động)</small>'}</td><td>${escape(row.department || 'Chưa gán')}<br><small>${escape(row.position || 'Chưa gán')}</small></td><td>${row.kpi_score == null ? '<small>Chưa phân</small>' : `<strong>${number(row.kpi_score)}</strong><br><small>${row.kpi_indicators} chỉ tiêu · ${row.kpi_locked ? 'Đã chốt' : 'Đang theo dõi'}</small><br><span class="kpi-result-label ${row.kpi_status === 'achieved' ? 'achieved' : 'not-achieved'}">${row.kpi_status === 'achieved' ? 'Đạt tất cả' : 'Chưa đạt đầy đủ'}</span>`}</td>${['tasks_created','tasks_completed','tasks_overdue','bonus_points','penalty_points','net_points','pending_adjustments'].map((key) => `<td${key === 'tasks_overdue' && row[key] > 0 ? ' class="kpi-overdue"' : ''}>${number(row[key])}</td>`).join('')}</tr><tr class="kpi-detail-row"><td colspan="10"><details data-employee="${row.user_id}"><summary>Xem chỉ tiêu và tiến độ · ${escape(row.name)}</summary>${renderEmployeeDetails(row)}</details></td></tr>`).join('') : '<tr><td colspan="10">Không có nhân viên trong phạm vi và bộ lọc đã chọn.</td></tr>'}</tbody></table></div>`;
    }
    function renderEmployeeDetails(row) {
        const work = `<p class="form-note">Chưa bắt đầu: ${number(row.tasks_not_started || 0)} · Đang thực hiện: ${number(row.tasks_in_progress || 0)} · Hoàn thành: ${number(row.tasks_completed)} · Quá hạn: ${number(row.tasks_overdue)} (có thể trùng nhóm chưa bắt đầu/đang thực hiện).</p>`;
        if (!row.kpi_details?.length) return work + '<p class="form-note">Chưa có chỉ tiêu trong kỳ. Quản trị viên có quyền KPI có thể phân từ Thiết lập KPI.</p>';
        return work + `<div class="kpi-indicator-details">${row.kpi_details.map(item => `<article><span class="kpi-indicator-code">${escape(item.code)}</span><h3>${escape(item.name)}</h3><p>${escape(item.metric_label)}</p><strong>${number(item.actual)} <small>/ ${number(item.target)} ${escape(item.unit)}</small></strong><div class="my-kpi-progress"><span style="width:${Math.max(0,Math.min(100,Number(item.score)))}%"></span></div><p>Điểm: ${number(item.score)}/100 · Trọng số: ${number(item.weight)} · ${item.direction === 'increase' ? 'Tăng là tốt' : 'Giảm là tốt'}</p><p class="form-note">${escape(item.description)}</p></article>`).join('')}</div>`;
    }
    async function exportReport() {
        if (!canExport || loadedQuery === null) return;
        const button = document.querySelector('[data-kpi-report-export]');
        const query = loadedQuery;
        button.disabled = true;
        button.textContent = 'Đang xuất…';
        const controller = new AbortController();
        const timeout = setTimeout(() => controller.abort(), 60000);
        try {
            const response = await fetch('/api/v1/kpi/employee-report/export?' + query, {headers: {Authorization: `Bearer ${VKApi.token()}`, Accept: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'}, signal: controller.signal});
            if (!response.ok) {
                const body = await response.json().catch(() => ({}));
                throw new Error([body.message, ...Object.values(body.fields || {}).flat()].filter(Boolean).join(' ') || 'Không xuất được Excel.');
            }
            const blob = await response.blob();
            const url = URL.createObjectURL(blob);
            const link = document.createElement('a');
            link.href = url;
            link.download = /filename="?([^";]+)"?/.exec(response.headers.get('Content-Disposition') || '')?.[1] || 'KPI-nhan-vien.xlsx';
            link.click();
            setTimeout(() => URL.revokeObjectURL(url), 1000);
        } catch (error) {
            VKModal.toast(error.name === 'AbortError' ? 'Xuất quá lâu. Hãy chọn kỳ hoặc phòng ban nhỏ hơn.' : error.message, 'danger');
        } finally {
            clearTimeout(timeout);
            button.textContent = 'Xuất Excel';
            button.disabled = !canExport || loadedQuery === null;
        }
    }
})();
