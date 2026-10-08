(function () {
    function escapeHtml(value) {
        return String(value ?? '').replace(/[&<>"']/g, (char) => ({
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;',
        }[char]));
    }

    function money(value) {
        return Number(value || 0).toLocaleString('vi-VN');
    }

    function translateStatus(value) {
        const map = {
            active: 'Hoạt động',
            inactive: 'Không hoạt động',
            ready: 'Sẵn sàng',
            approved: 'Đã duyệt',
            issued: 'Đã phát hành',
            accepted: 'Khách đồng ý',
            customer_rejected: 'Khách từ chối',
            change_requested: 'Yêu cầu sửa',
            superseded: 'Đã thay thế',
            waiting: 'Chưa đến lượt',
            confirmed: 'Đã xác nhận',
            completed: 'Hoàn thành',
            awaiting_delivery: 'Chờ giao hàng',
            delivered: 'Đã giao hàng',
            invoiced: 'Đã xuất hóa đơn',
            not_ready: 'Chưa sẵn sàng',
            unpaid: 'Chưa thanh toán',
            partially_paid: 'Thanh toán một phần',
            paid: 'Đã thanh toán',
            failed: 'Thất bại',
            reserved: 'Đã giữ hàng',
            new: 'Mới',
            draft: 'Nháp',
            assigned: 'Đã giao',
            qualified: 'Đã chuyển cơ hội',
            pending_approval: 'Chờ duyệt',
            pending: 'Chờ xử lý',
            open: 'Đang mở',
            in_progress: 'Đang xử lý',
            unchecked: 'Chưa kiểm tra',
            warning: 'Cảnh báo',
            shortage: 'Thiếu tồn',
            overdue: 'Quá hạn',
            rejected: 'Từ chối',
            cancelled: 'Đã hủy',
            high: 'Cao',
            normal: 'Bình thường',
            low: 'Thấp',
            urgent: 'Khẩn cấp',
            acknowledged: 'Đã ghi nhận',
            resolved: 'Đã xử lý',
            ordered: 'Đã đặt mua',
            partially_received: 'Nhập một phần',
            received: 'Đã nhập kho',
            issued: 'Đã xuất kho',
            closed: 'Đã đóng',
            read: 'Đã đọc',
            unread: 'Chưa đọc',
        };
        return map[String(value).toLowerCase()] || value || '-';
    }

    function translateType(value) {
        const map = {
            task_overdue: 'Công việc quá hạn',
            stock_shortage: 'Thiếu tồn kho',
            margin_low: 'Biên lợi nhuận thấp',
            manual: 'Thủ công',
            lead_follow_up: 'Chăm sóc khách hàng tiềm năng',
            quotation_follow_up: 'Theo dõi báo giá',
            margin_approval: 'Duyệt biên lợi nhuận thấp',
            sales_order_confirmation: 'Xác nhận đơn bán',
            delivery_confirmation: 'Xác nhận giao hàng',
            inventory_check: 'Kiểm tra tồn kho',
            warehouse_issue: 'Xuất kho',
            goods_receipt: 'Nhập kho',
            purchase_request: 'Yêu cầu mua hàng',
            purchase_order_follow_up: 'Theo dõi đơn mua',
            supplier_follow_up: 'Làm việc nhà cung cấp',
            invoice_issue: 'Xuất hóa đơn',
            payment_follow_up: 'Theo dõi thanh toán',
            receivable_follow_up: 'Nhắc công nợ',
            kpi_review: 'Rà soát KPI',
            alert_resolution: 'Xử lý cảnh báo',
            receipt: 'Nhập kho',
            issue: 'Xuất kho',
            reserve: 'Giữ hàng',
            adjust: 'Điều chỉnh',
        };
        return map[String(value).toLowerCase()] || value || '-';
    }

    function translateRole(value) {
        const map = {
            'ROLE-ADMIN': 'Quản trị hệ thống',
            'ROLE-DIR': 'Ban giám đốc',
            'ROLE-MGR': 'Trưởng phòng',
            'ROLE-SALES': 'Kinh doanh',
            'ROLE-WH': 'Kho',
            'ROLE-PUR': 'Mua hàng',
            'ROLE-MKT': 'Marketing',
            'ROLE-HR': 'Nhân sự',
            'ROLE-FIN': 'Tài chính',
        };
        return map[String(value)] || value || '-';
    }

    function translatePermission(value) {
        const map = {
            'user.manage': 'Quản lý người dùng',
            'role.manage': 'Quản lý vai trò',
            'dashboard.executive.view': 'Xem dashboard ban giám đốc',
            'dashboard.manager.view': 'Xem dashboard trưởng phòng',
            'master.view': 'Xem dữ liệu nền',
            'master.manage': 'Quản lý dữ liệu nền',
            'task.view': 'Xem công việc',
            'task.create': 'Tạo công việc',
            'task.approve': 'Duyệt công việc',
            'alert.view': 'Xem cảnh báo',
            'sales.lead.manage': 'Quản lý khách hàng tiềm năng',
            'sales.quotation.create': 'Tạo báo giá',
            'sales.quotation.view': 'Xem báo giá',
            'sales.quotation.edit': 'Sửa báo giá',
            'sales.quotation.delete': 'Xóa báo giá nháp',
            'sales.deal.manage': 'Quản lý cơ hội bán hàng',
            'sales.contract.manage': 'Quản lý hợp đồng',
            'sales.margin.approve': 'Duyệt biên lợi nhuận thấp',
            'sales.order.view': 'Xem đơn bán',
            'sales.order.create': 'Tạo đơn bán',
            'sales.order.edit': 'Sửa đơn hàng nháp',
            'sales.order.delete': 'Xóa đơn hàng nháp',
            'sales.delivery.view': 'Xem sổ giao hàng',
            'sales.delivery.confirm': 'Xác nhận giao hàng',
            'finance.invoice.view': 'Xem hóa đơn bán hàng',
            'finance.invoice.manage': 'Phát hành hóa đơn bán hàng',
            'finance.payment.view': 'Xem phiếu thu khách hàng',
            'finance.payment.record': 'Ghi nhận thanh toán khách hàng',
            'inventory.receipt.confirm': 'Xác nhận nhập kho',
            'inventory.issue.confirm': 'Xác nhận xuất kho',
            'procurement.pr.approve': 'Duyệt yêu cầu mua',
            'procurement.po.approve': 'Duyệt đơn mua',
            'marketing.campaign.manage': 'Quản lý chiến dịch marketing',
            'kpi.lock': 'Khóa kỳ KPI',
            'audit.view': 'Xem nhật ký hệ thống',
        };
        return map[String(value)] || value || '-';
    }

    function translateEntity(value) {
        const map = {
            Lead: 'Khách hàng tiềm năng',
            Customer: 'Khách hàng',
            Quotation: 'Báo giá',
            SalesOrder: 'Đơn bán',
            PurchaseRequest: 'Yêu cầu mua',
            PurchaseOrder: 'Đơn mua',
            GoodsReceipt: 'Phiếu nhập kho',
            GoodsIssue: 'Phiếu xuất kho',
            Task: 'Công việc',
            Alert: 'Cảnh báo',
            Sku: 'Mã hàng',
            Product: 'Sản phẩm',
            Supplier: 'Nhà cung cấp',
            Warehouse: 'Kho',
            User: 'Người dùng',
            Role: 'Vai trò',
            System: 'Hệ thống',
            Manual: 'Tạo thủ công',
            auth: 'Đăng nhập',
            tenant: 'Công ty',
            lead: 'Khách hàng tiềm năng',
            customer: 'Khách hàng',
            quotation: 'Báo giá',
            sales_order: 'Đơn bán',
            purchase_request: 'Yêu cầu mua',
            purchase_order: 'Đơn mua',
            goods_receipt: 'Phiếu nhập kho',
            goods_issue: 'Phiếu xuất kho',
            kpi_score_snapshot: 'Snapshot KPI',
            kpi_exception: 'Ngoại lệ KPI',
            task: 'Công việc',
            alert: 'Cảnh báo',
            sku: 'Mã hàng',
            supplier: 'Nhà cung cấp',
            warehouse: 'Kho',
            user: 'Người dùng',
            role: 'Vai trò',
            department: 'Phòng ban',
            position: 'Chức vụ',
        };
        return map[String(value)] || value || 'Thủ công';
    }

    function translateAction(value) {
        const map = {
            create_lead: 'Tạo khách hàng tiềm năng',
            assign_lead: 'Giao khách hàng tiềm năng',
            create_role: 'Tạo vai trò',
            sync_role_permissions: 'Cập nhật quyền vai trò',
            create_user: 'Tạo người dùng',
            sync_user_roles: 'Cập nhật vai trò người dùng',
            update_user_organization: 'Cập nhật tổ chức người dùng',
            update_user_status: 'Cập nhật trạng thái người dùng',
            change_user_status: 'Cập nhật trạng thái người dùng',
            create_task: 'Tạo công việc',
            update_task_status: 'Cập nhật trạng thái công việc',
            change_task_status: 'Cập nhật trạng thái công việc',
            create_customer: 'Tạo khách hàng',
            update_customer: 'Cập nhật khách hàng',
            create_supplier: 'Tạo nhà cung cấp',
            update_supplier: 'Cập nhật nhà cung cấp',
            create_sku: 'Tạo mã hàng',
            update_sku: 'Cập nhật mã hàng',
            create_quotation: 'Tạo báo giá',
            approve_quotation_margin: 'Duyệt biên lợi nhuận báo giá',
            approved_quotation: 'Duyệt báo giá',
            rejected_quotation: 'Từ chối báo giá',
            create_sales_order: 'Tạo đơn bán',
            confirm_sales_order: 'Xác nhận đơn bán',
            complete_sales_order_after_issue: 'Hoàn tất đơn bán sau xuất kho',
            reserve_sales_order_after_receipt: 'Giữ hàng sau nhập kho',
            create_purchase_request_draft: 'Tạo yêu cầu mua nháp',
            create_purchase_request: 'Tạo yêu cầu mua',
            approve_purchase_request: 'Duyệt yêu cầu mua',
            create_purchase_order: 'Tạo đơn mua',
            approve_purchase_order: 'Duyệt đơn mua',
            mark_purchase_order_received: 'Cập nhật đơn mua đã nhận hàng',
            create_goods_receipt: 'Tạo phiếu nhập kho',
            confirm_goods_receipt: 'Xác nhận nhập kho',
            create_goods_issue: 'Tạo phiếu xuất kho',
            confirm_goods_issue: 'Xác nhận xuất kho',
            calculate_kpi_snapshot: 'Tính snapshot KPI',
            create_kpi_exception: 'Tạo ngoại lệ KPI',
            review_kpi_exception: 'Duyệt ngoại lệ KPI',
            update_alert_status: 'Cập nhật trạng thái cảnh báo',
            change_alert_status: 'Cập nhật trạng thái cảnh báo',
            create_department: 'Tạo phòng ban',
            update_department: 'Cập nhật phòng ban',
            create_position: 'Tạo chức vụ',
            update_position: 'Cập nhật chức vụ',
            create_warehouse: 'Tạo kho',
            update_warehouse: 'Cập nhật kho',
            tenant_logo_updated: 'Cập nhật logo hệ thống',
            login_success: 'Đăng nhập thành công',
            login_failed: 'Đăng nhập thất bại',
            login_blocked: 'Đăng nhập bị chặn',
            logout: 'Đăng xuất',
        };
        return map[String(value)] || value || '-';
    }

    function statusBadge(value) {
        const key = String(value || '').toLowerCase();
        let style = 'inactive';
        if (['active', 'ready', 'approved', 'confirmed', 'completed', 'reserved', 'received', 'issued', 'closed', 'read', 'resolved', 'delivered', 'paid'].includes(key)) style = 'success';
        if (['new', 'draft', 'assigned', 'pending_approval', 'pending', 'open', 'in_progress', 'unchecked', 'ordered', 'partially_received', 'unread', 'awaiting_delivery', 'invoiced', 'unpaid', 'not_ready'].includes(key)) style = 'info';
        if (['partially_paid'].includes(key)) style = 'warning';
        if (['warning', 'shortage', 'overdue'].includes(key)) style = 'warning';
        if (['rejected', 'cancelled', 'high', 'urgent'].includes(key)) style = 'danger';
        return `<span class="badge ${style}">${translateStatus(value)}</span>`;
    }

    function renderTable(columns, rows, emptyText = 'Chưa có dữ liệu', options = {}) {
        if (!rows || rows.length === 0) {
            return `<div class="empty"><strong>${emptyText}</strong><span>Dữ liệu mới sẽ hiển thị tại đây.</span></div>`;
        }
        const resolvedColumns = columns.map((col, index) => ({
            ...col,
            className: [col.className || '', index === columns.length - 1 && !col.label ? 'actions-cell' : ''].filter(Boolean).join(' '),
        }));

        return `
            <div class="table-wrap list-table-wrap">
                <table class="list-table">
                    <thead><tr>${resolvedColumns.map((col) => `<th class="${escapeHtml(col.className || '')}">${escapeHtml(col.label)}</th>`).join('')}</tr></thead>
                    <tbody>
                        ${rows.map((row) => `
                            <tr ${options.rowAttr ? options.rowAttr(row) : ''}>
                                ${resolvedColumns.map((col) => `<td class="${escapeHtml(col.className || '')}">${col.render(row)}</td>`).join('')}
                            </tr>
                        `).join('')}
                    </tbody>
                </table>
            </div>
        `;
    }

    function filterBar({ search = true, searchPlaceholder = 'Tìm kiếm...', status = [], extra = '', actions = '' } = {}) {
        return `
            <div class="list-filter">
                ${search ? `
                    <label class="list-search">
                        <span aria-hidden="true">⌕</span>
                        <input type="search" data-list-search placeholder="${escapeHtml(searchPlaceholder)}">
                    </label>
                ` : ''}
                ${status.length ? `
                    <select data-list-status>
                        <option value="">Tất cả trạng thái</option>
                        ${status.map(item => `<option value="${escapeHtml(item.value)}">${escapeHtml(item.label)}</option>`).join('')}
                    </select>
                ` : ''}
                ${extra}
                <div class="list-filter-actions">${actions}</div>
            </div>
        `;
    }

    function fullList({ title, subtitle = '', filters = '', table = '', meta = '' }) {
        return `
            <section class="list-page">
                <div class="list-page-head">
                    <div>
                        <h2>${escapeHtml(title)}</h2>
                        ${subtitle ? `<span>${escapeHtml(subtitle)}</span>` : ''}
                    </div>
                    ${meta ? `<div class="list-meta">${meta}</div>` : ''}
                </div>
                ${filters}
                ${table}
            </section>
        `;
    }

    function rowActions(items) {
        const actions = items.filter(Boolean);
        if (!actions.length) return '';
        if (actions.length === 1) return `<div class="row-actions compact">${actions.join('')}</div>`;

        return `
            <details class="row-action-menu">
                <summary aria-label="Thao tác">⋮</summary>
                <div class="row-action-menu-list">${actions.join('')}</div>
            </details>
        `;
    }

    function smallButton(label, attr = '', variant = '') {
        return `<button class="btn small ${variant}" type="button" ${attr}>${escapeHtml(label)}</button>`;
    }

    window.VKTable = {
        escapeHtml,
        money,
        statusBadge,
        renderTable,
        translateStatus,
        translateType,
        translateRole,
        translatePermission,
        translateEntity,
        translateAction,
        filterBar,
        fullList,
        rowActions,
        smallButton,
    };

    document.addEventListener('click', (event) => {
        if (event.target.closest('.row-action-menu summary')) {
            event.stopPropagation();
        }
    }, true);

    document.addEventListener('toggle', (event) => {
        const menu = event.target;
        if (!menu.matches?.('.row-action-menu')) return;
        if (!menu.open) {
            menu.classList.remove('drop-up');
            menu.style.removeProperty('--row-menu-top');
            menu.style.removeProperty('--row-menu-left');
            return;
        }

        document.querySelectorAll('.row-action-menu[open]').forEach((item) => {
            if (item !== menu) item.removeAttribute('open');
        });

        window.requestAnimationFrame(() => {
            const list = menu.querySelector('.row-action-menu-list');
            const summary = menu.querySelector('summary');
            if (!list || !summary) return;

            const summaryRect = summary.getBoundingClientRect();
            const width = list.offsetWidth || 132;
            const height = list.offsetHeight || 40;
            const container = menu.closest('.list-page, .record-panel, .task-board-panel, .table-wrap, .list-table-wrap');
            const containerBottom = container ? container.getBoundingClientRect().bottom : window.innerHeight;
            const boundaryBottom = Math.min(window.innerHeight, containerBottom);
            const shouldDropUp = summaryRect.bottom + height + 8 > boundaryBottom && summaryRect.top > height + 8;
            const top = shouldDropUp ? summaryRect.top - height - 4 : summaryRect.bottom + 4;
            const left = Math.max(8, Math.min(summaryRect.right - width, window.innerWidth - width - 8));

            menu.classList.toggle('drop-up', shouldDropUp);
            menu.style.setProperty('--row-menu-top', `${Math.max(8, top)}px`);
            menu.style.setProperty('--row-menu-left', `${left}px`);
        });
    }, true);
})();
