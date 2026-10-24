@extends('layouts.app')

@section('title', 'Mã hàng')
@section('page', 'skus')
@section('page_title', 'Sản phẩm và mã hàng')
@section('page_subtitle', 'Quản lý danh mục, hãng, ảnh, mô tả, thông số kỹ thuật và giá bán.')
@section('page_actions')
    <button class="btn" type="button" data-directory-import="skus" data-permission="product.manage">Nhập file</button>
    <button class="btn primary" type="button" data-create-sku>+ Thêm sản phẩm</button>
@endsection

@section('content')
<div id="skusRoot"></div>
@endsection

@push('scripts')
<script src="/assets/js/features/skus.js?v=20261016-cost1"></script>
@endpush
