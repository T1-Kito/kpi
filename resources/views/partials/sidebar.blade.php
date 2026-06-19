@php
    $currentPath = request()->path();
    $isBusiness = str_starts_with($currentPath, 'leads')
        || str_starts_with($currentPath, 'quotations')
        || str_starts_with($currentPath, 'sales-orders')
        || str_starts_with($currentPath, 'deliveries')
        || str_starts_with($currentPath, 'sales-invoices')
        || str_starts_with($currentPath, 'customer-receivables')
        || str_starts_with($currentPath, 'customer-payments')
        || str_starts_with($currentPath, 'customers');
    $isWarehouse = str_starts_with($currentPath, 'inventory')
        || str_starts_with($currentPath, 'goods-receipts')
        || str_starts_with($currentPath, 'goods-issues')
        || str_starts_with($currentPath, 'stock-takes')
        || str_starts_with($currentPath, 'warehouses');
    $isPurchase = str_starts_with($currentPath, 'purchase-requests')
        || str_starts_with($currentPath, 'supplier-quotations')
        || str_starts_with($currentPath, 'purchase-orders')
        || str_starts_with($currentPath, 'suppliers')
        || str_starts_with($currentPath, 'skus');
    $isPrintTemplate = str_starts_with($currentPath, 'print-templates');
    $isWorkflow = str_starts_with($currentPath, 'workflows');
    $isAdmin = str_starts_with($currentPath, 'users')
        || str_starts_with($currentPath, 'roles')
        || str_starts_with($currentPath, 'organization')
        || str_starts_with($currentPath, 'audit-logs')
        || str_starts_with($currentPath, 'settings');
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
            <span class="nav-icon home" data-sidebar-icon="dashboard" aria-hidden="true"></span>
            <span>Dashboard</span>
        </a>

        <a class="nav-link {{ str_starts_with($currentPath, 'tasks') ? 'active' : '' }}" data-permission="task.view" href="/tasks">
            <span class="nav-icon tasks" data-sidebar-icon="tasks" aria-hidden="true"></span>
            <span>Công việc</span>
            <span class="nav-badge" data-sidebar-task-count>0</span>
        </a>

        <a class="nav-link {{ str_starts_with($currentPath, 'approvals') ? 'active' : '' }}" data-permission="task.approve" href="/approvals">
            <span class="nav-icon approval" data-sidebar-icon="approval" aria-hidden="true"></span>
            <span>Phê duyệt</span>
        </a>

        <a class="nav-link {{ $isBusiness ? 'active' : '' }}" data-permission="" data-nav-match="leads,quotations,sales-orders,deliveries,sales-invoices,customer-receivables,customer-payments,customers" href="/leads">
            <span class="nav-icon business" data-sidebar-icon="business" aria-hidden="true"></span>
            <span>Kinh doanh</span>
        </a>

        <a class="nav-link {{ $isWarehouse ? 'active' : '' }}" data-permission="" data-nav-match="inventory,warehouses,goods-receipts,goods-issues,stock-takes" href="/inventory">
            <span class="nav-icon warehouse" data-sidebar-icon="warehouse" aria-hidden="true"></span>
            <span>Kho vận</span>
        </a>

        <a class="nav-link {{ $isPurchase ? 'active' : '' }}" data-permission="" data-nav-match="purchase-requests,supplier-quotations,purchase-orders,suppliers,skus" href="/purchase-requests">
            <span class="nav-icon purchase" data-sidebar-icon="purchase" aria-hidden="true"></span>
            <span>Mua hàng</span>
        </a>

        <a class="nav-link {{ $currentPath === 'kpi' || $currentPath === 'kpi-adjustments' || $currentPath === 'kpi-settings' ? 'active' : '' }}" data-permission="" data-nav-match="kpi,kpi-adjustments,kpi-settings" href="/kpi">
            <span class="nav-icon kpi" data-sidebar-icon="kpi" aria-hidden="true"></span>
            <span>KPI</span>
        </a>

        <a class="nav-link {{ $currentPath === 'alerts' ? 'active' : '' }}" data-permission="alert.view" href="/alerts">
            <span class="nav-icon alert" data-sidebar-icon="alerts" aria-hidden="true"></span>
            <span>Cảnh báo</span>
            <span class="nav-badge danger" data-sidebar-alert-count>0</span>
        </a>

        <a class="nav-link {{ $isAdmin ? 'active' : '' }}" data-permission="" data-nav-match="users,roles,organization,audit-logs,settings" href="/users">
            <span class="nav-icon admin" data-sidebar-icon="admin" aria-hidden="true"></span>
            <span>Quản trị</span>
        </a>

        <a class="nav-link {{ $isPrintTemplate ? 'active' : '' }}" data-permission="user.manage" href="/print-templates">
            <span class="nav-icon print" data-sidebar-icon="print" aria-hidden="true"></span>
            <span>Mẫu in</span>
        </a>

        <a class="nav-link {{ $isWorkflow ? 'active' : '' }}" data-permission="" href="/workflows">
            <span class="nav-icon workflow" data-sidebar-icon="workflow" aria-hidden="true"></span>
            <span>Quy trình</span>
        </a>
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
