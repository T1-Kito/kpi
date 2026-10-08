@php
    $modules = [
        ['key' => 'work', 'label' => 'Công việc', 'description' => 'Tổng quan và việc cần xử lý', 'href' => '/dashboard', 'icon' => 'work', 'permission' => ''],
        ['key' => 'sales', 'label' => 'Kinh doanh', 'description' => 'Cơ hội, báo giá và đơn bán', 'href' => '/deals', 'icon' => 'sales', 'permission' => 'sales.deal.manage'],
        ['key' => 'customer', 'label' => 'Khách hàng', 'description' => 'Hồ sơ khách hàng và người liên hệ', 'href' => '/customers', 'icon' => 'service', 'permission' => 'master.view'],
        ['key' => 'product', 'label' => 'Sản phẩm', 'description' => 'Sản phẩm và mã hàng', 'href' => '/skus', 'icon' => 'product', 'permission' => 'master.view'],
        ['key' => 'procurement', 'label' => 'Mua hàng', 'description' => 'Yêu cầu mua và đơn mua', 'href' => '/purchase-requests', 'icon' => 'procurement', 'permission' => 'procurement.pr.approve'],
        ['key' => 'inventory', 'label' => 'Kho vận', 'description' => 'Tồn kho, nhập xuất và kiểm kê', 'href' => '/inventory', 'icon' => 'inventory', 'permission' => 'master.view'],
        ['key' => 'finance', 'label' => 'Tài chính', 'description' => 'Hóa đơn, công nợ và thu tiền', 'href' => '/sales-invoices', 'icon' => 'finance', 'permission' => 'finance.invoice.view'],
        ['key' => 'service', 'label' => 'Dịch vụ khách hàng', 'description' => 'Ticket và bảo hành', 'href' => '/service-tickets', 'icon' => 'service', 'permission' => 'service.ticket.view'],
        ['key' => 'kpi', 'label' => 'KPI', 'description' => 'Hiệu suất và sổ điểm', 'href' => '/kpi', 'icon' => 'kpi', 'permission' => ''],
        ['key' => 'admin', 'label' => 'Quản trị', 'description' => 'Người dùng và cấu hình hệ thống', 'href' => '/users', 'icon' => 'admin', 'permission' => 'user.manage'],
    ];
@endphp

<div class="app-switcher" data-app-switcher>
    <button class="app-switcher-trigger" type="button" aria-label="Mở danh sách phân hệ" aria-expanded="false" data-app-switcher-trigger><span aria-hidden="true"></span></button>
    <div class="app-switcher-panel" data-app-switcher-panel hidden>
        <div class="app-switcher-head">
            <div><strong>Phân hệ VKSoftware</strong><small>Chọn khu vực bạn muốn làm việc</small></div>
            <button type="button" aria-label="Đóng" data-app-switcher-close>&times;</button>
        </div>
        <div class="app-switcher-search"><input type="search" placeholder="Tìm phân hệ" aria-label="Tìm phân hệ" data-app-switcher-search></div>
        <nav class="app-switcher-grid" aria-label="Danh sách phân hệ">
            @foreach ($modules as $module)
                <a href="{{ $module['href'] }}" data-module-card="{{ $module['key'] }}" data-module-search="{{ mb_strtolower($module['label'].' '.$module['description']) }}">
                    <span class="module-card-icon {{ $module['icon'] }}" aria-hidden="true"></span>
                    <strong>{{ $module['label'] }}</strong><small>{{ $module['description'] }}</small>
                </a>
            @endforeach
        </nav>
    </div>
</div>
