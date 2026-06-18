@extends('layouts.app')

@section('title', 'Mã hàng')
@section('page', 'skus')
@section('page_title', 'Mã hàng')
@section('page_subtitle', 'Quản lý SKU, giá vốn, giá bán, đơn vị tính và ngưỡng tồn kho.')
@section('page_actions')
    <button class="btn primary" type="button" data-create-sku>Tạo mã hàng</button>
@endsection

@section('content')
<div id="skusRoot"></div>
@endsection

@push('scripts')
<script src="/assets/js/features/skus.js?v=20260615-2"></script>
@endpush
