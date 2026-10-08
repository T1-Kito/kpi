@extends('layouts.app')
@php($isCategory = $type === 'category')
@section('title', $isCategory ? 'Danh mục sản phẩm' : 'Hãng sản phẩm')
@section('page', $isCategory ? 'product-categories' : 'product-brands')
@section('page_title', $isCategory ? 'Danh mục sản phẩm' : 'Hãng / thương hiệu')
@section('page_subtitle', $isCategory ? 'Tạo nhóm sản phẩm để lựa chọn khi thêm sản phẩm.' : 'Tạo hãng để lựa chọn khi thêm sản phẩm.')
@section('page_actions')<button class="btn primary" type="button" data-catalog-create="{{ $type }}">+ Thêm {{ $isCategory ? 'danh mục' : 'hãng' }}</button>@endsection
@section('content')<div id="productCatalogRoot" data-catalog-type="{{ $type }}"></div>@endsection
@push('scripts')<script src="/assets/js/features/product-catalog.js?v=20260921-1"></script>@endpush
