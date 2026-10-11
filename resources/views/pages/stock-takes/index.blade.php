@extends('layouts.app')

@section('title', 'Kiểm kê kho')
@section('page', 'stock-takes')
@section('page_title', 'Kiểm kê kho')
@section('page_subtitle', 'Ghi nhận số lượng thực tế, so sánh lệch tồn và xác nhận điều chỉnh kho.')

@section('page_actions')
    <button class="btn primary" type="button" data-permission="inventory.stocktake.create" data-create-stock-take>Tạo phiếu kiểm kê</button>
@endsection

@section('content')
<div id="stockTakesRoot" class="stock-takes-page">
    <section class="module-panel"><p class="form-note">Đang tải phiếu kiểm kê…</p></section>
</div>
@endsection

@push('scripts')
<script src="/assets/js/features/stock-takes.js?v=20261011-live1"></script>
@endpush
