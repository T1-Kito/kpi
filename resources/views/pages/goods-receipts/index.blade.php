@extends('layouts.app')

@section('title', 'Nhập kho')
@section('page', 'goods-receipts')
@section('page_title', 'Nhập kho')
@section('page_subtitle', 'Phiếu nhập được tự tạo sau khi đơn mua duyệt đủ; kho chỉ cần kiểm tra và xác nhận nhận hàng.')

@section('content')
<div id="goodsReceiptsRoot"></div>
@endsection

@push('scripts')
<script src="/assets/js/features/goods-receipts.js?v=20261012-auto-flow1"></script>
@endpush
