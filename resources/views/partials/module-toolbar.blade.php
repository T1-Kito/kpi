@php
    $currentPath = request()->path();
    $moduleGroups = [
        [
            'match' => ['leads', 'quotations', 'sales-orders', 'deliveries', 'sales-invoices', 'customer-receivables', 'customer-payments', 'customers'],
            'items' => [
                ['label' => 'Khách hàng tiềm năng', 'href' => '/leads', 'match' => 'leads', 'permission' => 'sales.lead.manage'],
                ['label' => 'Báo giá', 'href' => '/quotations', 'match' => 'quotations', 'permission' => 'sales.quotation.create'],
                ['label' => 'Đơn bán', 'href' => '/sales-orders', 'match' => 'sales-orders', 'permission' => 'sales.order.view'],
                ['label' => 'Giao hàng', 'href' => '/deliveries', 'match' => 'deliveries', 'permission' => 'sales.delivery.view'],
                ['label' => 'Hóa đơn', 'href' => '/sales-invoices', 'match' => 'sales-invoices', 'permission' => 'finance.invoice.view'],
                ['label' => 'Công nợ', 'href' => '/customer-receivables', 'match' => 'customer-receivables', 'permission' => 'finance.payment.view'],
                ['label' => 'Thu tiền', 'href' => '/customer-payments', 'match' => 'customer-payments', 'permission' => 'finance.payment.view'],
                ['label' => 'Khách hàng', 'href' => '/customers', 'match' => 'customers', 'permission' => 'master.view'],
            ],
        ],
        [
            'match' => ['inventory', 'warehouses', 'goods-receipts', 'goods-issues'],
            'items' => [
                ['label' => 'Tồn kho', 'href' => '/inventory', 'match' => 'inventory', 'permission' => 'master.view'],
                ['label' => 'Kho', 'href' => '/warehouses', 'match' => 'warehouses', 'permission' => 'master.view'],
                ['label' => 'Nhập kho', 'href' => '/goods-receipts', 'match' => 'goods-receipts', 'permission' => 'inventory.receipt.confirm'],
                ['label' => 'Xuất kho', 'href' => '/goods-issues', 'match' => 'goods-issues', 'permission' => 'inventory.issue.confirm'],
            ],
        ],
        [
            'match' => ['purchase-requests', 'purchase-orders', 'suppliers', 'skus'],
            'items' => [
                ['label' => 'Yêu cầu mua', 'href' => '/purchase-requests', 'match' => 'purchase-requests', 'permission' => 'procurement.pr.approve'],
                ['label' => 'Đơn mua', 'href' => '/purchase-orders', 'match' => 'purchase-orders', 'permission' => 'procurement.po.approve'],
                ['label' => 'Mã hàng', 'href' => '/skus', 'match' => 'skus', 'permission' => 'master.view'],
                ['label' => 'Nhà cung cấp', 'href' => '/suppliers', 'match' => 'suppliers', 'permission' => 'master.view'],
            ],
        ],
        [
            'match' => ['kpi', 'kpi-adjustments'],
            'items' => [
                ['label' => 'Tổng quan KPI', 'href' => '/kpi', 'match' => 'kpi', 'permission' => ''],
                ['label' => 'Sổ điểm nhân viên', 'href' => '/kpi-adjustments', 'match' => 'kpi-adjustments', 'permission' => ''],
            ],
        ],
        [
            'match' => ['users', 'roles', 'organization', 'print-templates', 'audit-logs', 'settings'],
            'items' => [
                ['label' => 'Người dùng', 'href' => '/users', 'match' => 'users', 'permission' => 'user.manage'],
                ['label' => 'Vai trò', 'href' => '/roles', 'match' => 'roles', 'permission' => 'role.manage'],
                ['label' => 'Phòng ban và chức vụ', 'href' => '/organization', 'match' => 'organization', 'permission' => 'user.manage'],
                ['label' => 'Mẫu in', 'href' => '/print-templates', 'match' => 'print-templates', 'permission' => 'user.manage'],
                ['label' => 'Nhật ký', 'href' => '/audit-logs', 'match' => 'audit-logs', 'permission' => 'audit.view'],
                ['label' => 'Thiết lập', 'href' => '/settings', 'match' => 'settings', 'permission' => 'audit.view'],
            ],
        ],
    ];

    $activeGroup = collect($moduleGroups)->first(function ($group) use ($currentPath) {
        return collect($group['match'])->contains(fn ($prefix) => str_starts_with($currentPath, $prefix));
    });
@endphp

@if ($activeGroup)
    <nav class="module-toolbar" data-module-toolbar aria-label="Chức năng trong phân hệ">
        @foreach ($activeGroup['items'] as $item)
            <a
                class="{{ str_starts_with($currentPath, $item['match']) ? 'active' : '' }}"
                href="{{ $item['href'] }}"
                data-permission="{{ $item['permission'] }}"
            >
                {{ $item['label'] }}
            </a>
        @endforeach
    </nav>
@endif
