@extends('layouts.app')

@section('title', 'Xuất kho')
@section('page', 'goods-issues')
@section('page_title', 'Xuất kho')
@section('page_subtitle', 'Tạo phiếu xuất từ đơn bán đã giữ hàng và ghi nhận giao dịch kho.')
@section('page_actions')
    <button class="btn primary" type="button" data-create-goods-issue>Tạo phiếu xuất</button>
@endsection

@section('content')
<div id="goodsIssuesRoot"></div>
@endsection

@push('scripts')
<script src="/assets/js/features/goods-issues.js?v=20261008-menu1"></script>
@endpush
