@extends('layouts.app')

@section('title', 'Đơn mua')
@section('page', 'purchase-orders')
@section('page_title', 'Đơn mua')
@section('page_subtitle', 'Đơn mua được tự tạo từ báo giá nhà cung cấp đã chọn; duyệt xong hệ thống tự sinh phiếu nhập kho.')

@section('content')
<div id="purchaseOrdersRoot"></div>
@endsection

@push('scripts')
<script src="/assets/js/features/purchase-orders.js?v=20261012-auto-flow1"></script>
@endpush
