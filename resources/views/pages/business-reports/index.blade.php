@extends('layouts.app')
@section('title', 'Báo cáo nghiệp vụ')
@section('page', 'business-reports')
@section('page_title', 'Báo cáo nghiệp vụ')
@section('page_subtitle', 'Khách hàng, chăm sóc, phản hồi, bán hàng, mua hàng, kho và công nợ.')
@section('content')
<section class="module-panel business-report-panel">
    <div class="panel-head"><h2>Báo cáo và Excel</h2><button type="button" class="btn primary" id="businessReportExport" disabled>Xuất Excel</button></div>
    <form id="businessReportFilters" class="kpi-report-filters">
        <label>Báo cáo<select name="type"></select></label>
        <label data-date-filter>Khoảng thời gian<select name="range"><option value="month">Tháng này</option><option value="quarter">Quý này</option><option value="year">Năm nay</option><option value="custom">Tùy chọn</option></select></label>
        <label data-date-filter>Từ ngày<input type="date" name="from"></label>
        <label data-date-filter>Đến ngày<input type="date" name="to"></label>
        <label>Tìm kiếm<input name="q" placeholder="Mã hoặc tên đối tượng..."></label>
        <label data-status-filter>Trạng thái<select name="status"><option value="">Tất cả</option></select></label>
        <label data-debt-filter hidden>Tình trạng nợ<select name="debt_status"><option value="">Tất cả còn nợ</option><option value="overdue">Quá hạn</option><option value="not_due">Chưa đến hạn</option></select></label>
        <label data-party-filter>Đối tượng<select name="party_id"><option value="">Tất cả được xem</option></select></label>
        <label data-user-filter>Nhân viên<select name="user_id"><option value="">Tất cả được xem</option></select></label>
        <label data-sku-filter hidden>Mã hàng<select name="sku_id"><option value="">Tất cả mã hàng</option></select></label>
        <button class="btn secondary" type="submit">Xem báo cáo</button>
        <button class="btn secondary" type="button" id="businessReportReset">Đặt lại</button>
    </form>
    <p id="businessReportStatus" class="form-note" role="status"></p>
    <div id="businessReportRoot" aria-live="polite"></div>
    <div class="row-actions" id="businessReportPages"></div>
</section>
@endsection
@push('scripts')
<script src="/assets/js/features/business-reports.js?v=20261020-care-report1"></script>
@endpush
