@extends('layouts.app')

@section('title', 'Nhập kho')
@section('page', 'goods-receipts')
@section('page_title', 'Nhập kho')
@section('page_subtitle', 'Tạo phiếu nhập từ đơn mua đã duyệt và cập nhật tồn kho.')
@section('page_actions')
    <button class="btn primary" type="button" data-create-goods-receipt>Tạo phiếu nhập</button>
@endsection

@section('content')
<div id="goodsReceiptsRoot"></div>
@endsection

@push('scripts')
<script src="/assets/js/features/goods-receipts.js"></script>
@endpush
