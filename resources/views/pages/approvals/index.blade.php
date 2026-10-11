@extends('layouts.app')

@section('title', 'Duyệt tập trung')
@section('page', 'approvals')
@section('page_title', 'Duyệt tập trung')
@section('page_subtitle', 'Một nơi xử lý báo giá, mua hàng và điều chỉnh KPI đang chờ phê duyệt.')

@section('content')
<div class="approval-center" data-api-shell="1">
    <nav class="approval-view-tabs" aria-label="Chế độ xem phê duyệt">
        <a class="active" href="/approvals">Tổng quan</a>
        <a href="#approvalQueue">Hàng chờ phê duyệt</a>
        <a href="#approvalHistory">Đã xử lý</a>
    </nav>

    <section class="approval-metric-grid" data-approval-metrics aria-busy="true">
        @foreach ([['pending','pending','Đang chờ phê duyệt','Yêu cầu','blue'],['overdue','clock','Quá hạn','Cần xử lý ngay','teal'],['today','calendar','Hôm nay','Yêu cầu chờ xử lý','orange'],['processed','processed','Đã xử lý','Hôm nay','blue'],['approved','check','Phê duyệt','Gần đây','green'],['rejected','close','Từ chối','Gần đây','red']] as [$key,$icon,$label,$note,$tone])
            <article class="approval-metric-card {{ $tone }}">
                <span class="approval-metric-icon" aria-hidden="true"><x-approval-icon :name="$icon" /></span>
                <div><small>{{ $label }}</small><strong data-metric="{{ $key }}">—</strong><span>{{ $note }}</span></div>
            </article>
        @endforeach
    </section>

    <section class="module-panel approval-queue-panel" id="approvalQueue">
        <div class="panel-head"><div><h2>Hàng chờ phê duyệt</h2><span>Chỉ hiển thị hồ sơ thuộc quyền xử lý của tài khoản hiện tại.</span></div><span class="badge info" data-approval-count>— hồ sơ</span></div>
        <form class="approval-filter" data-approval-filter>
            <div class="search-control"><span aria-hidden="true"><x-approval-icon name="search" /></span><input name="q" placeholder="Tìm mã hồ sơ, người tạo hoặc nội dung..."></div>
            <select name="type"><option value="all">Tất cả</option><option value="quotation">Báo giá</option><option value="purchase_request">Yêu cầu mua</option><option value="purchase_order">Đơn mua</option><option value="kpi_adjustment">Phiếu điểm</option><option value="kpi_exception">Ngoại lệ KPI</option></select>
            <button class="btn secondary" type="submit">Lọc</button><button class="btn" type="reset">Xóa lọc</button>
        </form>
        <div data-approval-list><div class="empty-state"><strong>Đang tải hàng chờ…</strong></div></div>
    </section>

    <section class="module-panel approval-history-panel" id="approvalHistory">
        <div class="panel-head"><div><h2>Quyết định gần đây</h2><span>Phục vụ kiểm tra nhanh trước khi mở nhật ký hệ thống.</span></div><a class="btn small" href="/audit-logs">Mở nhật ký</a></div>
        <div class="approval-history-list" data-approval-history><div class="empty-state"><strong>Đang tải lịch sử…</strong></div></div>
    </section>
</div>
@endsection

@push('scripts')
<script src="/assets/js/features/approvals.js?v=20261011-2"></script>
@endpush
