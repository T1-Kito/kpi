@extends('layouts.app')

@section('title', 'Đơn hàng')
@section('page', 'sales-orders')
@section('page_title', 'Đơn hàng')
@section('page_subtitle', 'Đơn tự tạo khi khách chấp thuận báo giá; xác nhận để giữ hàng và chuyển việc xuống kho.')
@section('page_actions')
    <button class="btn primary" type="button" data-permission="sales.order.create" data-create-sales-order>Tạo từ báo giá</button>
@endsection

@section('content')
<div id="salesOrdersRoot"></div>
@endsection

@push('scripts')
<script src="/assets/js/features/sales-orders.js?v=20261020-money1"></script>
@endpush
