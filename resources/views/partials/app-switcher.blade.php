@php
    // Permissions match the sidebar; each tile leads directly to its named page.
    $apps = [
        ['customers', 'Khách hàng', 'Hồ sơ khách hàng', 'master.view', 'customers', 'blue'],
        ['leads', 'Khách tiềm năng', 'Khách hàng tiềm năng', 'sales.lead.view', 'leads', 'sky'],
        ['customer-contacts', 'Người liên hệ', 'Danh bạ khách hàng', 'master.view', 'contacts', 'teal'],
        ['deals', 'Cơ hội bán hàng', 'Kinh doanh, cơ hội', 'sales.deal.view', 'deals', 'indigo'],
        ['quotations', 'Báo giá', 'Báo giá khách hàng', 'sales.quotation.view', 'quotation', 'blue'],
        ['sales-orders', 'Đơn hàng', 'Đơn bán, bán hàng', 'sales.order.view', 'order', 'indigo'],
        ['deliveries', 'Giao hàng', 'Vận chuyển, bàn giao', 'sales.delivery.view', 'delivery', 'teal'],
        ['skus', 'Sản phẩm', 'Sản phẩm, mã hàng, SKU', 'master.view', 'product', 'sky'],
        ['inventory', 'Kho hàng', 'Tồn kho, kho vận', 'master.view', 'warehouse', 'blue'],
        ['goods-receipts', 'Nhập kho', 'Phiếu nhập hàng', 'inventory.receipt.view', 'receipt', 'teal'],
        ['goods-issues', 'Xuất kho', 'Phiếu xuất hàng', 'inventory.issue.view', 'issue', 'amber'],
        ['purchase-requests', 'Yêu cầu mua', 'Đề nghị mua hàng', 'procurement.pr.view', 'request', 'sky'],
        ['purchase-orders', 'Đơn mua hàng', 'Đơn mua, nhà cung cấp', 'procurement.po.view', 'purchase', 'indigo'],
        ['suppliers', 'Nhà cung cấp', 'Đối tác cung cấp hàng', 'master.view', 'supplier', 'teal'],
        ['service-tickets', 'Chăm sóc khách hàng', 'Ticket, hỗ trợ, phản hồi, SLA', 'service.ticket.view', 'support', 'blue'],
        ['warranty-claims', 'Bảo hành', 'Tiếp nhận, xử lý bảo hành', 'service.ticket.view', 'warranty', 'teal'],
        ['tasks', 'Công việc', 'Việc cần làm, nhiệm vụ', 'task.view', 'tasks', 'sky'],
        ['approvals', 'Phê duyệt', 'Duyệt yêu cầu, chứng từ', 'task.approve', 'approval', 'indigo'],
        ['sales-invoices', 'Hóa đơn', 'Hóa đơn bán hàng', 'finance.invoice.view', 'invoice', 'amber'],
        ['customer-receivables', 'Công nợ', 'Công nợ khách hàng', 'finance.payment.view', 'finance', 'teal'],
        ['kpi', 'KPI', 'Chỉ tiêu, hiệu suất', '', 'kpi', 'indigo'],
        ['business-reports', 'Báo cáo', 'Báo cáo nghiệp vụ', '', 'report', 'blue'],
        ['users', 'Người dùng', 'Tài khoản nhân viên', 'user.manage', 'users', 'sky'],
        ['settings', 'Thiết lập', 'Cấu hình, quản trị hệ thống', '', 'settings', 'slate'],
    ];
    $icons = [
        'customers' => '<circle cx="12" cy="8" r="3"/><path d="M6 21v-3a6 6 0 0 1 12 0v3M5 5a3 3 0 0 0 0 6m-3 8v-2a5 5 0 0 1 3-4M19 5a3 3 0 0 1 0 6m3 8v-2a5 5 0 0 0-3-4"/>',
        'leads' => '<circle cx="10" cy="8" r="3"/><path d="M3 21v-2a7 7 0 0 1 10-6m4-8h5m-2.5-2.5v5M16 16h6m-3-3v6"/>',
        'contacts' => '<rect x="5" y="3" width="15" height="18" rx="3"/><path d="M3 7h4m-4 5h4m-4 5h4m3 0a3 3 0 0 1 6 0"/><circle cx="13" cy="10" r="2"/>',
        'deals' => '<path d="M4 19V5m0 14h16M8 14l4-4 3 2 5-7m-5 0h5v5"/>',
        'quotation' => '<path d="M14 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9l-6-6Zm0 0v6h6M8 8h2m-2 5h3m-3 4h3m5-5v6m2-5h-3a1 1 0 0 0 0 2h2a1 1 0 0 1 0 2h-3"/>',
        'order' => '<path d="M8 5H6a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2M8 11l2 2 4-4m-6 8h8"/><rect x="8" y="3" width="8" height="4" rx="1.5"/>',
        'delivery' => '<path d="M3 6h11v12H3V6Zm11 4h4l3 4v4h-7m0-4h7M3 10h6"/><circle cx="7" cy="19" r="2"/><circle cx="18" cy="19" r="2"/>',
        'product' => '<path d="m12 3 9 5-9 5-9-5 9-5Zm-9 5v9l9 5 9-5V8m-9 5v9M7.5 5.5l9 5"/>',
        'warehouse' => '<path d="M3 21V9l9-6 9 6v12M1 21h22M7 21V11h10v10M7 15h10M7 18h10"/>',
        'receipt' => '<path d="M4 13v7h16v-7M12 3v12m-5-5 5 5 5-5"/>',
        'issue' => '<path d="M4 13v7h16v-7M12 15V3m-5 5 5-5 5 5"/>',
        'request' => '<path d="M14 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9l-6-6Zm0 0v6h6M8 14h8m-4-4v8"/>',
        'purchase' => '<path d="M2 4h3l3 12h11l3-9H6m3 4h10"/><circle cx="10" cy="20" r="1.5"/><circle cx="18" cy="20" r="1.5"/>',
        'supplier' => '<path d="M3 21V6l9-3v18M12 9h9v12M1 21h22M7 8v1m0 4v1m0 4v1m9-6h1m-1 4h1"/>',
        'support' => '<path d="M4 14v-3a8 8 0 0 1 16 0v3m0 3v1a3 3 0 0 1-3 3h-3"/><rect x="2" y="11" width="5" height="7" rx="2"/><rect x="17" y="11" width="5" height="7" rx="2"/><path d="M10 21h4"/>',
        'warranty' => '<path d="m12 3 8 3v6c0 5-8 9-8 9s-8-4-8-9V6l8-3Zm-4 9 3 3 5-6"/>',
        'tasks' => '<rect x="4" y="3" width="16" height="18" rx="3"/><path d="m7 9 1.5 1.5L11 8m2 2h4M7 15h3m3 0h4"/>',
        'approval' => '<path d="M7 12V5h10v7m-9 0h8l3 5H5l3-5Zm-2 9h12m-8-14 1.5 1.5L14 6"/>',
        'invoice' => '<path d="M5 3h14v19l-3-2-4 2-4-2-3 2V3Zm4 5h6m-6 4h6m-6 4h3"/>',
        'finance' => '<rect x="3" y="5" width="18" height="15" rx="3"/><path d="M3 9h18m-7 4h7v4h-7v-4Zm-7 1h2"/>',
        'kpi' => '<circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="5"/><path d="m12 12 8-8m-4 0h4v4"/>',
        'report' => '<path d="M4 3v18h17M8 17v-4m5 4V9m5 8V5"/>',
        'users' => '<circle cx="9" cy="8" r="3"/><path d="M3 21v-2a6 6 0 0 1 12 0v2m1-16a3 3 0 0 1 0 6m2 3a5 5 0 0 1 3 5v2"/>',
        'settings' => '<path d="m9 3-1 3-3 1-2 4 2 2v4l4 2 2-1 3 3 4-2v-3l3-2-1-5-3-1-1-3-5-1Z"/><circle cx="12" cy="12" r="3"/>',
    ];
@endphp
<div class="app-switcher" data-app-switcher>
    <button class="app-switcher-trigger" type="button" aria-label="Ứng dụng" title="Ứng dụng" aria-expanded="false" aria-controls="crmAppPanel" disabled data-app-switcher-trigger>
        <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><rect x="3" y="3" width="4" height="4" rx="1"/><rect x="10" y="3" width="4" height="4" rx="1"/><rect x="17" y="3" width="4" height="4" rx="1"/><rect x="3" y="10" width="4" height="4" rx="1"/><rect x="10" y="10" width="4" height="4" rx="1"/><rect x="17" y="10" width="4" height="4" rx="1"/><rect x="3" y="17" width="4" height="4" rx="1"/><rect x="10" y="17" width="4" height="4" rx="1"/><rect x="17" y="17" width="4" height="4" rx="1"/></svg>
    </button>
    <section id="crmAppPanel" class="app-switcher-panel" aria-label="Ứng dụng VK CRM" hidden data-app-switcher-panel>
        <header class="app-switcher-head">
            <div><strong>Ứng dụng</strong><small>Truy cập nhanh công việc của bạn</small></div>
            <button class="ui-close-button" type="button" aria-label="Đóng danh sách ứng dụng" title="Đóng" data-app-switcher-close><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg></button>
        </header>
        <div class="app-switcher-search"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="10.5" cy="10.5" r="6.5"/><path d="m16 16 5 5"/></svg><input type="search" placeholder="Tìm ứng dụng…" aria-label="Tìm ứng dụng" autocomplete="off" data-app-switcher-search></div>
        <div class="app-switcher-scroll">
            <section data-app-pinned-section hidden>
                <div class="app-switcher-caption"><strong>Đã ghim <span data-app-pinned-count></span></strong><small>Tối đa 6 ứng dụng</small></div>
                <nav class="app-switcher-grid app-switcher-pinned" aria-label="Ứng dụng đã ghim" data-app-pinned></nav>
            </section>
            <div class="app-switcher-caption"><strong>Tất cả ứng dụng <span data-app-count></span></strong></div>
            <nav class="app-switcher-grid" aria-label="Tất cả ứng dụng" data-app-all>
                @foreach ($apps as [$key, $label, $description, $permission, $icon, $tone])
                    <div class="app-switcher-tile" data-app-tile data-app-key="{{ $key }}" data-app-search="{{ $label.' '.$description }}">
                        <a href="/{{ $key }}" data-module-card="{{ $key }}" data-permission="{{ $permission }}" title="{{ $description }}">
                            <span class="app-tile-icon app-tone-{{ $tone }}" aria-hidden="true"><svg viewBox="0 0 24 24">{!! $icons[$icon] !!}</svg></span>
                            <strong>{{ $label }}</strong>
                        </a>
                        <button type="button" class="app-switcher-pin" data-app-pin="{{ $key }}" aria-label="Ghim {{ $label }}" title="Ghim {{ $label }}" aria-pressed="false"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m12 3 2.8 5.7 6.3.9-4.6 4.4 1.1 6.3-5.6-3-5.6 3 1.1-6.3L2.9 9.6l6.3-.9L12 3Z"/></svg></button>
                    </div>
                @endforeach
            </nav>
            <div class="app-switcher-empty" hidden data-app-empty>Không tìm thấy ứng dụng.<small>Thử tìm theo tên như “Báo giá”, “Kho”.</small></div>
        </div>
        <footer class="app-switcher-footer" role="status" aria-live="polite" data-app-status>Nhấn ngôi sao để ghim ứng dụng thường dùng.</footer>
    </section>
</div>
