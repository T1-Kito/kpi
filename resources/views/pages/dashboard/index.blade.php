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
<section class="module-panel" style="margin-bottom:20px">
    <div class="panel-head"><div><h2>KPI được giao cho tôi</h2><a href="/my-kpi">Xem chi tiết KPI</a></div><label>Ngày thuộc kỳ <input type="date" id="myKpiDate"></label></div>
    <div id="myKpiRoot" aria-live="polite"><p class="form-note">Đang tải chỉ tiêu được phân…</p></div>
</section>
<div id="dashboardRoot" class="dashboard-root"></div>
@endsection

@push('scripts')
<script src="/assets/js/features/dashboard.js?v=20261008-2"></script>
<script src="/assets/js/features/my-kpi.js?v=20261011-assign2"></script>
@endpush
