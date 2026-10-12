@extends('layouts.app')

@section('title', 'Xuất kho')
@section('page', 'goods-issues')
@section('page_title', 'Xuất kho')
@section('page_subtitle', 'Phiếu xuất được tự tạo khi đơn bán giữ hàng thành công; kho chỉ cần kiểm tra và xác nhận xuất.')

@section('content')
<div id="goodsIssuesRoot"></div>
@endsection

@push('scripts')
<script src="/assets/js/features/goods-issues.js?v=20261012-auto-flow1"></script>
@endpush
