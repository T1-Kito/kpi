<nav class="kpi-workspace-nav" aria-label="Các màn hình KPI">
    <a href="/kpi" class="{{ request()->is('kpi') ? 'active' : '' }}"><span>01</span><strong>Tổng quan</strong><small>Điểm và xu hướng thực tế</small></a>
    <a href="/kpi-adjustments" class="{{ request()->is('kpi-adjustments') ? 'active' : '' }}"><span>02</span><strong>Sổ điểm nhân viên</strong><small>Phiếu cộng, trừ và duyệt</small></a>
    <a href="/kpi-settings" data-permission="kpi.lock" class="{{ request()->is('kpi-settings') ? 'active' : '' }}"><span>03</span><strong>Thiết lập KPI</strong><small>Dữ liệu nền và mục tiêu</small></a>
</nav>
