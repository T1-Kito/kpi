@extends('layouts.app')
@php($isCategory = $type === 'category')
@section('title', $isCategory ? 'Danh mục sản phẩm' : 'Hãng sản phẩm')
@section('page', $isCategory ? 'product-categories' : 'product-brands')
@section('page_title', $isCategory ? 'Danh mục sản phẩm' : 'Hãng / thương hiệu')
@section('page_subtitle', $isCategory ? 'Tạo nhóm sản phẩm để lựa chọn khi thêm sản phẩm.' : 'Tạo hãng để lựa chọn khi thêm sản phẩm.')
@section('page_actions')<button class="btn" type="button" data-directory-import="{{ $isCategory ? 'categories' : 'brands' }}" data-permission="product.manage">Nhập file</button>@if($isCategory)<button class="btn" type="button" data-catalog-tree-import data-permission="product.manage">Nhập file phân loại</button>@endif<button class="btn primary" type="button" data-catalog-create="{{ $type }}">+ Thêm {{ $isCategory ? 'danh mục' : 'hãng' }}</button>@endsection
@section('content')<div id="productCatalogRoot" data-catalog-type="{{ $type }}"></div>@endsection
@push('scripts')<script src="/assets/js/features/product-catalog.js?v=20261028-table6"></script>@endpush
