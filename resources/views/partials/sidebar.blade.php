@php
    $currentPath = request()->path();
    $openBusiness = str_starts_with($currentPath, 'leads') || str_starts_with($currentPath, 'quotations') || str_starts_with($currentPath, 'sales-orders') || str_starts_with($currentPath, 'customers');
    $openWarehouse = str_starts_with($currentPath, 'inventory') || str_starts_with($currentPath, 'goods-receipts') || str_starts_with($currentPath, 'goods-issues') || str_starts_with($currentPath, 'warehouses') || str_starts_with($currentPath, 'skus');
    $openPurchase = str_starts_with($currentPath, 'purchase-requests') || str_starts_with($currentPath, 'purchase-orders') || str_starts_with($currentPath, 'suppliers');
    $openAdmin = str_starts_with($currentPath, 'users') || str_starts_with($currentPath, 'roles') || str_starts_with($currentPath, 'organization') || str_starts_with($currentPath, 'audit-logs') || str_starts_with($currentPath, 'settings');
@endphp

<aside class="sidebar">
    <div class="side-brand">
        <a class="brand" href="/dashboard">
            <span class="brand-logo" aria-hidden="true"></span>
            <img class="brand-image hidden" alt="Logo hệ thống" data-brand-logo>
        </a>
    </div>

    <nav class="nav grouped-nav" id="mainNav">
        <a class="nav-link top-link {{ $currentPath === 'dashboard' ? 'active' : '' }}" data-permission="" href="/dashboard">
            <span class="nav-icon home" aria-hidden="true"></span>
            <span>Dashboard</span>
        </a>

        <a class="nav-link {{ str_starts_with($currentPath, 'tasks') ? 'active' : '' }}" data-permission="task.view" href="/tasks">
            <span class="nav-icon tasks" aria-hidden="true"></span>
            <span>Công việc</span>
            <span class="nav-badge" data-sidebar-task-count>0</span>
        </a>

        <section class="nav-group {{ $openBusiness ? '' : 'collapsed' }}" data-nav-group data-nav-key="business">
            <button class="nav-group-toggle" type="button" data-nav-toggle>
                <span class="nav-icon business" aria-hidden="true"></span>
                <span>Kinh doanh</span>
                <span class="nav-chevron" aria-hidden="true"></span>
            </button>
            <div class="nav-sub">
                <a class="{{ str_starts_with($currentPath, 'leads') ? 'active' : '' }}" data-permission="sales.lead.manage" href="/leads">Khách hàng tiềm năng</a>
                <a class="{{ str_starts_with($currentPath, 'quotations') ? 'active' : '' }}" data-permission="sales.quotation.create" href="/quotations">Báo giá</a>
                <a class="{{ str_starts_with($currentPath, 'sales-orders') ? 'active' : '' }}" data-permission="sales.order.create" href="/sales-orders">Đơn hàng</a>
                <a class="{{ str_starts_with($currentPath, 'customers') ? 'active' : '' }}" data-permission="master.view" href="/customers">Khách hàng</a>
            </div>
        </section>

        <section class="nav-group {{ $openWarehouse ? '' : 'collapsed' }}" data-nav-group data-nav-key="warehouse">
            <button class="nav-group-toggle" type="button" data-nav-toggle>
                <span class="nav-icon warehouse" aria-hidden="true"></span>
                <span>Kho vận</span>
                <span class="nav-chevron" aria-hidden="true"></span>
            </button>
            <div class="nav-sub">
                <a class="{{ str_starts_with($currentPath, 'inventory') ? 'active' : '' }}" data-permission="master.view" href="/inventory">Tồn kho</a>
                <a class="{{ str_starts_with($currentPath, 'warehouses') ? 'active' : '' }}" data-permission="master.view" href="/warehouses">Kho</a>
                <a class="{{ str_starts_with($currentPath, 'skus') ? 'active' : '' }}" data-permission="master.view" href="/skus">Mã hàng</a>
                <a class="{{ str_starts_with($currentPath, 'goods-receipts') ? 'active' : '' }}" data-permission="inventory.receipt.confirm" href="/goods-receipts">Nhập kho</a>
                <a class="{{ str_starts_with($currentPath, 'goods-issues') ? 'active' : '' }}" data-permission="inventory.issue.confirm" href="/goods-issues">Xuất kho</a>
            </div>
        </section>

        <section class="nav-group {{ $openPurchase ? '' : 'collapsed' }}" data-nav-group data-nav-key="purchase">
            <button class="nav-group-toggle" type="button" data-nav-toggle>
                <span class="nav-icon purchase" aria-hidden="true"></span>
                <span>Mua hàng</span>
                <span class="nav-chevron" aria-hidden="true"></span>
            </button>
            <div class="nav-sub">
                <a class="{{ str_starts_with($currentPath, 'purchase-requests') ? 'active' : '' }}" data-permission="procurement.pr.approve" href="/purchase-requests">Yêu cầu mua</a>
                <a class="{{ str_starts_with($currentPath, 'purchase-orders') ? 'active' : '' }}" data-permission="procurement.po.approve" href="/purchase-orders">Đơn mua</a>
                <a class="{{ str_starts_with($currentPath, 'suppliers') ? 'active' : '' }}" data-permission="master.view" href="/suppliers">Nhà cung cấp</a>
            </div>
        </section>

        <a class="nav-link {{ $currentPath === 'kpi' ? 'active' : '' }}" data-permission="" href="/kpi">
            <span class="nav-icon kpi" aria-hidden="true"></span>
            <span>KPI</span>
        </a>

        <a class="nav-link {{ $currentPath === 'alerts' ? 'active' : '' }}" data-permission="alert.view" href="/alerts">
            <span class="nav-icon alert" aria-hidden="true"></span>
            <span>Cảnh báo</span>
            <span class="nav-badge danger" data-sidebar-alert-count>0</span>
        </a>

        <section class="nav-group {{ $openAdmin ? '' : 'collapsed' }}" data-nav-group data-nav-key="admin">
            <button class="nav-group-toggle" type="button" data-nav-toggle>
                <span class="nav-icon admin" aria-hidden="true"></span>
                <span>Quản trị</span>
                <span class="nav-chevron" aria-hidden="true"></span>
            </button>
            <div class="nav-sub">
                <a class="{{ str_starts_with($currentPath, 'users') ? 'active' : '' }}" data-permission="user.manage" href="/users">Người dùng</a>
                <a class="{{ str_starts_with($currentPath, 'roles') ? 'active' : '' }}" data-permission="role.manage" href="/roles">Vai trò</a>
                <a class="{{ str_starts_with($currentPath, 'organization') ? 'active' : '' }}" data-permission="user.manage" href="/organization">Phòng ban</a>
                <a class="{{ str_starts_with($currentPath, 'audit-logs') ? 'active' : '' }}" data-permission="audit.view" href="/audit-logs">Nhật ký</a>
                <a class="{{ str_starts_with($currentPath, 'settings') ? 'active' : '' }}" data-permission="audit.view" href="/settings">Thiết lập</a>
            </div>
        </section>
    </nav>

    <div class="sidebar-foot">
        <button class="side-foot-link" type="button" data-logout>
            <span class="nav-icon logout" aria-hidden="true"></span>
            <span>Đăng xuất</span>
        </button>
        <div class="user-chip" id="userLine" title="">
            <span class="user-avatar" aria-hidden="true">U</span>
            <span class="user-meta">
                <strong id="userName">Người dùng</strong>
                <small id="userRole">VK-KPI</small>
            </span>
        </div>
    </div>
</aside>
