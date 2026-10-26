@php
    $currentPath = request()->path();
    $moduleGroups = [
        [
            'match' => ['deals', 'quotations', 'sales-orders', 'contracts', 'deliveries'],
            'items' => [
                ['label' => 'Cơ hội bán hàng', 'href' => '/deals', 'match' => 'deals', 'permission' => 'sales.deal.view'],
                ['label' => 'Báo giá', 'href' => '/quotations', 'match' => 'quotations', 'permission' => 'sales.quotation.view'],
                ['label' => 'Đơn hàng', 'href' => '/sales-orders', 'match' => 'sales-orders', 'permission' => 'sales.order.view'],
                ['label' => 'Hợp đồng', 'href' => '/contracts', 'match' => 'contracts', 'permission' => 'sales.contract.manage'],
                ['label' => 'Giao hàng', 'href' => '/deliveries', 'match' => 'deliveries', 'permission' => 'sales.delivery.view'],
            ],
        ],
        [
            'match' => ['customers', 'leads', 'customer-contacts', 'customer-groups', 'import-center'],
            'items' => [
                ['label' => 'Khách hàng', 'href' => '/customers', 'match' => 'customers', 'permission' => 'master.view'],
                ['label' => 'Khách hàng tiềm năng', 'href' => '/leads', 'match' => 'leads', 'permission' => 'sales.lead.view'],
                ['label' => 'Người liên hệ', 'href' => '/customer-contacts', 'match' => 'customer-contacts', 'permission' => 'master.view'],
                ['label' => 'Nhóm khách hàng', 'href' => '/customer-groups', 'match' => 'customer-groups', 'permission' => 'master.view'],
            ],
        ],
        [
            'match' => ['inventory', 'inventory-transactions', 'warehouses', 'goods-receipts', 'goods-issues', 'stock-takes'],
            'items' => [
                ['label' => 'Tồn kho', 'href' => '/inventory', 'match' => 'inventory', 'permission' => 'master.view'],
                ['label' => 'Giao dịch kho', 'href' => '/inventory-transactions', 'match' => 'inventory-transactions', 'permission' => 'master.view'],
                ['label' => 'Kho', 'href' => '/warehouses', 'match' => 'warehouses', 'permission' => 'master.view'],
                ['label' => 'Nhập kho', 'href' => '/goods-receipts', 'match' => 'goods-receipts', 'permission' => 'inventory.receipt.view'],
                ['label' => 'Xuất kho', 'href' => '/goods-issues', 'match' => 'goods-issues', 'permission' => 'inventory.issue.view'],
                ['label' => 'Kiểm kê', 'href' => '/stock-takes', 'match' => 'stock-takes', 'permission' => 'inventory.stocktake.view'],
            ],
        ],
        [
            'match' => ['purchase-requests', 'supplier-quotations', 'purchase-orders', 'suppliers'],
            'items' => [
                ['label' => 'Yêu cầu mua', 'href' => '/purchase-requests', 'match' => 'purchase-requests', 'permission' => 'procurement.pr.view'],
                ['label' => 'Báo giá NCC', 'href' => '/supplier-quotations', 'match' => 'supplier-quotations', 'permission' => 'procurement.sq.view'],
                ['label' => 'Đơn mua', 'href' => '/purchase-orders', 'match' => 'purchase-orders', 'permission' => 'procurement.po.view'],
                ['label' => 'Nhà cung cấp', 'href' => '/suppliers', 'match' => 'suppliers', 'permission' => 'master.view'],
            ],
        ],
        [
            'match' => ['skus', 'product-categories', 'product-brands'],
            'items' => [
                ['label' => 'Sản phẩm và mã hàng', 'href' => '/skus', 'match' => 'skus', 'permission' => 'master.view'],
                ['label' => 'Danh mục', 'href' => '/product-categories', 'match' => 'product-categories', 'permission' => 'master.view'],
                ['label' => 'Hãng', 'href' => '/product-brands', 'match' => 'product-brands', 'permission' => 'master.view'],
            ],
        ],
        [
            'match' => ['sales-invoices', 'customer-receivables', 'customer-payments'],
            'items' => [
                ['label' => 'Hóa đơn', 'href' => '/sales-invoices', 'match' => 'sales-invoices', 'permission' => 'finance.invoice.view'],
                ['label' => 'Công nợ', 'href' => '/customer-receivables', 'match' => 'customer-receivables', 'permission' => 'finance.payment.view'],
                ['label' => 'Thu tiền', 'href' => '/customer-payments', 'match' => 'customer-payments', 'permission' => 'finance.payment.view'],
            ],
        ],
        [
            'match' => ['service-tickets', 'warranty-claims'],
            'items' => [
                ['label' => 'Ticket hỗ trợ', 'href' => '/service-tickets', 'match' => 'service-tickets', 'permission' => 'service.ticket.view'],
                ['label' => 'Bảo hành', 'href' => '/warranty-claims', 'match' => 'warranty-claims', 'permission' => 'service.ticket.view'],
            ],
        ],
        [
            'match' => ['kpi', 'my-kpi', 'kpi-reports', 'kpi-adjustments', 'kpi-settings'],
            'items' => [
                ['label' => 'Tổng quan KPI', 'href' => '/kpi', 'match' => 'kpi', 'permission' => ''],
                ['label' => 'KPI của tôi', 'href' => '/my-kpi', 'match' => 'my-kpi', 'permission' => ''],
                ['label' => 'Báo cáo theo kỳ', 'href' => '/kpi-reports', 'match' => 'kpi-reports', 'permission' => ''],
                ['label' => 'Sổ điểm nhân viên', 'href' => '/kpi-adjustments', 'match' => 'kpi-adjustments', 'permission' => ''],
                ['label' => 'Thiết lập KPI', 'href' => '/kpi-settings', 'match' => 'kpi-settings', 'permission' => 'kpi.lock'],
            ],
        ],
        [
            'match' => ['users', 'roles', 'organization', 'print-templates', 'audit-logs', 'settings', 'sales-master-data'],
            'items' => [
                ['label' => 'Người dùng', 'href' => '/users', 'match' => 'users', 'permission' => 'user.manage'],
                ['label' => 'Vai trò', 'href' => '/roles', 'match' => 'roles', 'permission' => 'role.manage'],
                ['label' => 'Phòng ban và chức vụ', 'href' => '/organization', 'match' => 'organization', 'permission' => 'user.manage'],
                ['label' => 'Mẫu in', 'href' => '/print-templates', 'match' => 'print-templates', 'permission' => 'user.manage'],
                ['label' => 'Nhật ký', 'href' => '/audit-logs', 'match' => 'audit-logs', 'permission' => 'audit.view'],
                ['label' => 'Thiết lập', 'href' => '/settings', 'match' => 'settings', 'permission' => 'audit.view'],
                ['label' => 'Dữ liệu nền bán hàng', 'href' => '/sales-master-data', 'match' => 'sales-master-data', 'permission' => 'sales.master.manage'],
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
                class="{{ $currentPath === $item['match'] || str_starts_with($currentPath, $item['match'].'/') ? 'active' : '' }}"
                href="{{ $item['href'] }}"
                data-permission="{{ $item['permission'] }}"
            >
                {{ $item['label'] }}
            </a>
        @endforeach
    </nav>
@endif
