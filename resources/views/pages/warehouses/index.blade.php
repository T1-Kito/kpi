@extends('layouts.app')

@section('title', 'Kho')
@section('page', 'warehouses')
@section('page_title', 'Kho')
@section('page_subtitle', 'Quản lý kho, địa chỉ và vị trí lưu trữ dùng cho nhập xuất tồn.')
@section('page_actions')
    <button class="btn primary" type="button" data-create-warehouse>Tạo kho</button>
@endsection

@section('content')
<div id="warehousesRoot"></div>
@endsection

@push('scripts')
<script src="/assets/js/features/warehouses.js"></script>
@endpush
