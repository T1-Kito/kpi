@extends('layouts.app')

@section('title', 'Báo giá nhà cung cấp')
@section('page', 'supplier-quotations')
@section('page_title', 'Báo giá nhà cung cấp')
@section('page_subtitle', 'Thu thập báo giá, so sánh giá mua và chọn báo giá làm căn cứ tạo đơn mua.')

@section('page_actions')
    <button class="btn primary" type="button" data-permission="procurement.sq.manage" data-create-supplier-quotation>Tạo báo giá NCC</button>
@endsection

@section('content')
<div id="supplierQuotationsRoot" class="supplier-quotations-page" data-api-shell="1" aria-busy="true">
    <section class="module-panel procurement-inbox">
        <div class="panel-head"><div><h2>Yêu cầu chờ xử lý báo giá</h2><span>Bấm mã yêu cầu để xem các báo giá liên quan. Lấy báo giá mới sẽ kế thừa hàng hóa và số lượng.</span></div><button class="btn small" type="button" data-toggle-sq-queue hidden>Xem tất cả (0)</button></div>
        <div class="table-wrap"><table class="list-table"><thead><tr><th>Yêu cầu</th><th>Lý do mua</th><th>Mã hàng</th><th>Tên sản phẩm</th><th>Số lượng</th><th>ĐVT</th><th>Người yêu cầu</th><th>Tiến độ</th><th></th></tr></thead><tbody data-sq-queue-body><tr><td colspan="9"><div class="empty-state">Đang tải yêu cầu mua đã duyệt…</div></td></tr></tbody></table></div>
    </section>
    <section class="module-panel" data-sq-list>
        <div class="panel-head"><div><h2>Danh sách báo giá NCC</h2><span>Mỗi yêu cầu mua có thể có nhiều báo giá, nhưng chỉ một báo giá được chọn để đi tiếp lên PO.</span></div><span class="badge info" data-sq-count>— báo giá</span></div>
        <div class="sq-list-filters">
            <label>Yêu cầu mua<select data-sq-filter="request"><option value="">Tất cả yêu cầu</option></select></label>
            <label>Nhà cung cấp<select data-sq-filter="supplier"><option value="">Tất cả nhà cung cấp</option></select></label>
            <label>Trạng thái<select data-sq-filter="status"><option value="">Tất cả trạng thái</option><option value="draft">Nháp</option><option value="selected">Đã chọn, chưa tạo đơn</option><option value="ordered">Đã tạo đơn mua</option><option value="rejected">Không chọn</option></select></label>
            <button type="button" class="btn small" data-clear-sq-filters>Xóa bộ lọc</button>
        </div>
        <div class="table-wrap"><table class="list-table"><thead><tr><th>Yêu cầu mua</th><th>Mã báo giá</th><th>Lý do mua</th><th>Tên sản phẩm</th><th>Nhà cung cấp</th><th>Ngày báo giá</th><th>Hiệu lực</th><th>Tổng tiền</th><th>Trạng thái</th><th>Tiến độ duyệt</th><th></th></tr></thead><tbody data-sq-table-body><tr><td colspan="11"><div class="empty-state">Đang tải báo giá nhà cung cấp…</div></td></tr></tbody></table></div>
    </section>
</div>
@endsection

@push('scripts')
<script src="/assets/js/features/supplier-quotations.js?v=20261020-money1"></script>
@endpush
