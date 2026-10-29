@extends('layouts.app')

@section('title', 'Đơn hàng')
@section('page', 'sales-orders')
@section('page_title', 'Đơn hàng')
@section('page_subtitle', 'Đơn tự tạo khi báo giá được duyệt; xử lý giao hàng sau khi khách xác nhận.')

@section('content')
<div id="salesOrdersRoot"></div>
@endsection

@push('scripts')
<script src="/assets/js/features/sales-orders.js?v=20261028-auto-order2"></script>
@endpush
