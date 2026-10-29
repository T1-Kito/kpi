@extends('layouts.app')
@section('title', 'Báo cáo KPI theo kỳ')
@section('page', 'kpi-reports')
@section('page_title', 'Điều hành KPI và công việc')
@section('page_subtitle', 'Theo dõi người được giao, mức đạt KPI và tiến độ công việc theo kỳ.')
@section('content')
<section class="module-panel kpi-period-report">
    <div class="panel-head"><div><h2>KPI theo nhân viên</h2><span id="kpiReportSync" role="status">Số liệu tự cập nhật khi màn hình đang mở.</span></div><button type="button" class="btn primary" data-kpi-report-export disabled>Xuất Excel</button></div>
    <form id="kpiReportFilters" class="kpi-report-filters">
        <label>Kỳ<select name="period_type"><option value="month">Tháng</option><option value="quarter">Quý</option><option value="year">Năm</option></select></label>
        <label>Ngày thuộc kỳ<input type="date" name="date" required></label>
        <label>Phòng ban<select name="department_id"><option value="">Trong phạm vi được xem</option></select></label>
        <label>Nhân viên<select name="user_id"><option value="">Tất cả được xem</option></select></label>
        <button type="submit" class="btn secondary">Xem báo cáo</button>
    </form>
    <div id="kpiReportRoot" aria-live="polite"></div>
</section>
@endsection
@push('scripts')
<script src="/assets/js/features/kpi-reports.js?v=20261029-compact1"></script>
@endpush
