<aside class="sidebar">
    <div class="side-brand">
        <a class="brand" href="/dashboard" aria-label="Trang tổng quan"><span class="brand-logo hidden" aria-hidden="true"></span><img class="brand-image hidden" alt="Logo hệ thống" data-brand-logo></a>
    </div>

    <nav class="nav grouped-nav" id="mainNav">
        <div data-module-section="work">
            <a class="nav-link" href="/dashboard"><span class="nav-icon home" aria-hidden="true"></span><span>Tổng quan</span></a>
            <a class="nav-link" data-permission="task.view" href="/tasks"><span class="nav-icon tasks" aria-hidden="true"></span><span>Công việc</span><span class="nav-badge" data-sidebar-task-count>0</span></a>
            <a class="nav-link" data-permission="task.approve" href="/approvals"><span class="nav-icon approval" aria-hidden="true"></span><span>Phê duyệt</span></a>
            <a class="nav-link" data-permission="alert.view" href="/alerts"><span class="nav-icon alert" aria-hidden="true"></span><span>Cảnh báo</span><span class="nav-badge danger" data-sidebar-alert-count>0</span></a>
        </div>

        <div data-dashboard-shortcuts hidden>
            <div class="nav-group" data-nav-group data-nav-key="dashboard-work">
                <button class="nav-group-toggle" type="button" data-nav-toggle><span class="nav-icon home" aria-hidden="true"></span><span>Công việc</span><span class="nav-chevron" aria-hidden="true"></span></button>
                <div class="nav-sub">
                    <a href="/dashboard">Tổng quan</a>
                    <a data-permission="task.view" href="/tasks">Công việc</a>
                    <a data-permission="task.approve" href="/approvals">Phê duyệt</a>
                    <a data-permission="alert.view" href="/alerts">Cảnh báo</a>
                </div>
            </div>
            <div class="nav-group" data-nav-group data-nav-key="dashboard-sales">
                <button class="nav-group-toggle" type="button" data-nav-toggle><span class="nav-icon business" aria-hidden="true"></span><span>Kinh doanh</span><span class="nav-chevron" aria-hidden="true"></span></button>
                <div class="nav-sub">
                    <a data-permission="sales.deal.manage" href="/deals">Cơ hội bán hàng</a>
                    <a data-permission="sales.quotation.view" href="/quotations">Báo giá</a>
                    <a data-permission="sales.order.view" href="/sales-orders">Đơn hàng</a>
                    <a data-permission="sales.contract.manage" href="/contracts">Hợp đồng</a>
                    <a data-permission="sales.delivery.view" href="/deliveries">Giao hàng</a>
                </div>
            </div>
            <div class="nav-group" data-nav-group data-nav-key="dashboard-customers">
                <button class="nav-group-toggle" type="button" data-nav-toggle><span class="nav-icon profile" aria-hidden="true"></span><span>Khách hàng</span><span class="nav-chevron" aria-hidden="true"></span></button>
                <div class="nav-sub">
                    <a data-permission="master.view" href="/customers">Danh sách khách hàng</a>
                    <a data-permission="sales.lead.manage" href="/leads">Khách hàng tiềm năng</a>
                    <a data-permission="master.view" href="/customer-contacts">Người liên hệ</a>
                </div>
            </div>
            <div class="nav-group" data-nav-group data-nav-key="dashboard-products">
                <button class="nav-group-toggle" type="button" data-nav-toggle><span class="nav-icon product" aria-hidden="true"></span><span>Sản phẩm</span><span class="nav-chevron" aria-hidden="true"></span></button>
                <div class="nav-sub">
                    <a data-permission="master.view" href="/skus">Sản phẩm và mã hàng</a>
                    <a data-permission="master.view" href="/product-categories">Danh mục sản phẩm</a>
                    <a data-permission="master.view" href="/product-brands">Hãng sản phẩm</a>
                </div>
            </div>
            <div class="nav-group" data-nav-group data-nav-key="dashboard-warehouse">
                <button class="nav-group-toggle" type="button" data-nav-toggle><span class="nav-icon warehouse" aria-hidden="true"></span><span>Kho vận</span><span class="nav-chevron" aria-hidden="true"></span></button>
                <div class="nav-sub">
                    <a data-permission="master.view" href="/inventory">Tồn kho</a>
                    <a data-permission="master.view" href="/inventory-transactions">Giao dịch kho</a>
                    <a data-permission="master.view" href="/warehouses">Kho và vị trí</a>
                    <a data-permission="inventory.receipt.confirm" href="/goods-receipts">Nhập kho</a>
                    <a data-permission="inventory.issue.confirm" href="/goods-issues">Xuất kho</a>
                </div>
            </div>
            <div class="nav-group" data-nav-group data-nav-key="dashboard-procurement">
                <button class="nav-group-toggle" type="button" data-nav-toggle><span class="nav-icon purchase" aria-hidden="true"></span><span>Mua hàng</span><span class="nav-chevron" aria-hidden="true"></span></button>
                <div class="nav-sub">
                    <a data-permission="procurement.pr.approve" href="/purchase-requests">Yêu cầu mua</a>
                    <a data-permission="procurement.po.approve" href="/supplier-quotations">Báo giá nhà cung cấp</a>
                    <a data-permission="procurement.po.approve" href="/purchase-orders">Đơn mua</a>
                    <a data-permission="master.view" href="/suppliers">Nhà cung cấp</a>
                </div>
            </div>
            <div class="nav-group" data-nav-group data-nav-key="dashboard-finance">
                <button class="nav-group-toggle" type="button" data-nav-toggle><span class="nav-icon finance" aria-hidden="true"></span><span>Tài chính</span><span class="nav-chevron" aria-hidden="true"></span></button>
                <div class="nav-sub">
                    <a data-permission="finance.invoice.view" href="/sales-invoices">Hóa đơn bán hàng</a>
                    <a data-permission="finance.payment.view" href="/customer-receivables">Công nợ khách hàng</a>
                    <a data-permission="finance.payment.view" href="/customer-payments">Thu tiền</a>
                </div>
            </div>
            <div class="nav-group" data-nav-group data-nav-key="dashboard-service">
                <button class="nav-group-toggle" type="button" data-nav-toggle><span class="nav-icon tasks" aria-hidden="true"></span><span>Chăm sóc khách hàng</span><span class="nav-chevron" aria-hidden="true"></span></button>
                <div class="nav-sub">
                    <a data-permission="service.ticket.view" href="/service-tickets">Ticket hỗ trợ</a>
                    <a data-permission="service.ticket.view" href="/warranty-claims">Bảo hành</a>
                </div>
            </div>
            <div class="nav-group" data-nav-group data-nav-key="dashboard-kpi">
                <button class="nav-group-toggle" type="button" data-nav-toggle><span class="nav-icon kpi" aria-hidden="true"></span><span>KPI</span><span class="nav-chevron" aria-hidden="true"></span></button>
                <div class="nav-sub">
                    <a href="/kpi">Tổng quan KPI</a>
                    <a href="/kpi-adjustments">Sổ điểm nhân viên</a>
                    <a data-permission="kpi.lock" href="/kpi-settings">Thiết lập KPI</a>
                </div>
            </div>
            <div class="nav-group" data-nav-group data-nav-key="dashboard-admin">
                <button class="nav-group-toggle" type="button" data-nav-toggle><span class="nav-icon admin" aria-hidden="true"></span><span>Quản trị</span><span class="nav-chevron" aria-hidden="true"></span></button>
                <div class="nav-sub">
                    <a href="/settings">Thiết lập</a>
                </div>
            </div>
        </div>

        <div data-module-section="sales" hidden>
            <a class="nav-link" data-permission="sales.deal.manage" href="/deals"><span class="nav-icon business" aria-hidden="true"></span><span>Cơ hội bán hàng</span></a>
            <a class="nav-link" data-permission="sales.quotation.view" href="/quotations"><span class="nav-icon print" aria-hidden="true"></span><span>Báo giá</span></a>
            <a class="nav-link" data-permission="sales.order.view" href="/sales-orders"><span class="nav-icon business" aria-hidden="true"></span><span>Đơn hàng</span></a>
            <a class="nav-link" data-permission="sales.contract.manage" href="/contracts"><span class="nav-icon print" aria-hidden="true"></span><span>Hợp đồng</span></a>
            <a class="nav-link" data-permission="sales.delivery.view" href="/deliveries"><span class="nav-icon warehouse" aria-hidden="true"></span><span>Giao hàng</span></a>
        </div>

        <div data-module-section="customer" hidden>
            <a class="nav-link" data-permission="master.view" href="/customers"><span class="nav-icon profile" aria-hidden="true"></span><span>Danh sách khách hàng</span></a>
            <a class="nav-link" data-permission="sales.lead.manage" href="/leads"><span class="nav-icon profile" aria-hidden="true"></span><span>Khách hàng tiềm năng</span></a>
            <a class="nav-link" data-permission="master.view" href="/customer-contacts"><span class="nav-icon profile" aria-hidden="true"></span><span>Người liên hệ</span></a>
        </div>
        <div data-module-section="product" hidden>
            <a class="nav-link" data-permission="master.view" href="/skus"><span class="nav-icon product" aria-hidden="true"></span><span>Sản phẩm và mã hàng</span></a>
            <a class="nav-link" data-permission="master.view" href="/product-categories"><span class="nav-icon tasks" aria-hidden="true"></span><span>Danh mục sản phẩm</span></a>
            <a class="nav-link" data-permission="master.view" href="/product-brands"><span class="nav-icon business" aria-hidden="true"></span><span>Hãng sản phẩm</span></a>
        </div>

        <div data-module-section="procurement" hidden>
            <a class="nav-link" data-permission="procurement.pr.approve" href="/purchase-requests"><span class="nav-icon tasks" aria-hidden="true"></span><span>Yêu cầu mua</span></a>
            <a class="nav-link" data-permission="procurement.po.approve" href="/supplier-quotations"><span class="nav-icon print" aria-hidden="true"></span><span>Báo giá nhà cung cấp</span></a>
            <a class="nav-link" data-permission="procurement.po.approve" href="/purchase-orders"><span class="nav-icon purchase" aria-hidden="true"></span><span>Đơn mua</span></a>
            <a class="nav-link" data-permission="master.view" href="/suppliers"><span class="nav-icon profile" aria-hidden="true"></span><span>Nhà cung cấp</span></a>
        </div>

        <div data-module-section="inventory" hidden>
            <a class="nav-link" data-permission="master.view" href="/inventory"><span class="nav-icon inventory" aria-hidden="true"></span><span>Tồn kho</span></a>
            <a class="nav-link" data-permission="master.view" href="/inventory-transactions"><span class="nav-icon inventory" aria-hidden="true"></span><span>Giao dịch kho</span></a>
            <a class="nav-link" data-permission="master.view" href="/warehouses"><span class="nav-icon warehouse" aria-hidden="true"></span><span>Kho và vị trí</span></a>
            <a class="nav-link" data-permission="inventory.receipt.confirm" href="/goods-receipts"><span class="nav-icon receipt" aria-hidden="true"></span><span>Nhập kho</span></a>
            <a class="nav-link" data-permission="inventory.issue.confirm" href="/goods-issues"><span class="nav-icon issue" aria-hidden="true"></span><span>Xuất kho</span></a>
            <a class="nav-link" data-permission="inventory.issue.confirm" href="/stock-takes"><span class="nav-icon stocktake" aria-hidden="true"></span><span>Kiểm kê</span></a>
        </div>

        <div data-module-section="finance" hidden>
            <a class="nav-link" data-permission="finance.invoice.view" href="/sales-invoices"><span class="nav-icon invoice" aria-hidden="true"></span><span>Hóa đơn bán hàng</span></a>
            <a class="nav-link" data-permission="finance.payment.view" href="/customer-receivables"><span class="nav-icon finance" aria-hidden="true"></span><span>Công nợ khách hàng</span></a>
            <a class="nav-link" data-permission="finance.payment.view" href="/customer-payments"><span class="nav-icon payment" aria-hidden="true"></span><span>Thu tiền</span></a>
        </div>

        <div data-module-section="service" hidden>
            <a class="nav-link" data-permission="service.ticket.view" href="/service-tickets"><span class="nav-icon tasks" aria-hidden="true"></span><span>Ticket hỗ trợ</span></a>
            <a class="nav-link" data-permission="service.ticket.view" href="/warranty-claims"><span class="nav-icon approval" aria-hidden="true"></span><span>Yêu cầu bảo hành</span></a>
        </div>

        <div data-module-section="kpi" hidden>
            <a class="nav-link" href="/kpi"><span class="nav-icon kpi" aria-hidden="true"></span><span>Tổng quan KPI</span></a>
            <a class="nav-link" href="/kpi-adjustments"><span class="nav-icon adjustment" aria-hidden="true"></span><span>Sổ điểm nhân viên</span></a>
            <a class="nav-link" data-permission="kpi.lock" href="/kpi-settings"><span class="nav-icon settings" aria-hidden="true"></span><span>Thiết lập KPI</span></a>
        </div>

        <div data-module-section="admin" hidden>
            <a class="nav-link" href="/settings"><span class="nav-icon settings" aria-hidden="true"></span><span>Thiết lập</span></a>
        </div>
    </nav>

    <div class="sidebar-foot">
        <button class="side-foot-link" type="button" data-logout><span class="nav-icon logout" aria-hidden="true"></span><span>Đăng xuất</span></button>
        <div class="user-chip" id="userLine" title=""><span class="user-avatar" aria-hidden="true">U</span><span class="user-meta"><strong id="userName">Người dùng</strong><small id="userRole">VK-KPI</small></span></div>
    </div>
</aside>
