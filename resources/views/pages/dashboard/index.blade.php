@extends('layouts.app')

@section('title', 'Bảng điều khiển')
@section('page', 'dashboard')
@section('page_title', 'Tổng quan')
@section('page_subtitle', 'Theo dõi công việc, cảnh báo, bán hàng, mua hàng và kho trong một màn hình.')
@section('page_actions')
    <a class="btn primary" href="/leads" data-dashboard-create-lead hidden>Tạo khách hàng tiềm năng</a>
    <a class="btn" href="/tasks" data-dashboard-work-action>Xem công việc</a>
@endsection

@section('content')
<section class="module-panel assigned-kpi-panel" data-dashboard-personal-kpi>
    <div class="panel-head"><div><h2>KPI của tôi</h2></div><div class="assigned-kpi-tools"><label for="myKpiDate">Kỳ xem <input type="date" id="myKpiDate"></label><a class="btn secondary small" href="/my-kpi">Xem chi tiết →</a></div></div>
    <div id="myKpiRoot" aria-live="polite"><p class="form-note">Đang tải chỉ tiêu được phân…</p></div>
</section>
<div id="dashboardRoot" class="dashboard-root"></div>
@endsection

@push('scripts')
<script src="/assets/js/features/dashboard.js?v=20261029-activity-vi1"></script>
<script src="/assets/js/features/my-kpi.js?v=20261013-ui2"></script>
@endpush
