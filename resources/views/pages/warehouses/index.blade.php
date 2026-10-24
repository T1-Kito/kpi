@extends('layouts.app')

@section('title', 'Kho')
@section('page', 'warehouses')
@section('page_title', 'Kho')
@section('page_subtitle', 'Quản lý kho, địa chỉ và vị trí lưu trữ dùng cho nhập xuất tồn.')
@section('page_actions')
    <button class="btn" type="button" data-directory-import="warehouses" data-permission="warehouse.manage">Nhập kho</button>
    <button class="btn" type="button" data-directory-import="locations" data-permission="warehouse.manage">Nhập vị trí kho</button>
    <button class="btn primary" type="button" data-create-warehouse>Tạo kho</button>
@endsection

@section('content')
<div id="warehousesRoot"></div>
@endsection

@push('scripts')
<script src="/assets/js/features/warehouses.js?v=20261023-procurement1"></script>
@endpush
