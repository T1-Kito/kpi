@extends('layouts.app')

@section('title', 'Đơn bán')
@section('page', 'sales-orders')
@section('page_title', 'Đơn bán')
@section('page_subtitle', 'Kiểm tra tồn kho, giữ hàng và tạo công việc kho.')
@section('page_actions')
    <button class="btn primary" type="button" data-permission="sales.order.create" data-create-sales-order>Tạo từ báo giá</button>
@endsection

@section('content')
<div id="salesOrdersRoot"></div>
@endsection

@push('scripts')
<script src="/assets/js/features/sales-orders.js?v=20260620-1"></script>
@endpush
