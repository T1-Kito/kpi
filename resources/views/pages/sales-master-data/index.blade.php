@extends('layouts.app')

@section('title', 'Dữ liệu nền bán hàng')
@section('page', 'sales-master-data')
@section('page_title', 'Dữ liệu nền bán hàng')
@section('page_subtitle', 'Chuẩn hóa Pipeline và nguồn cơ hội trước khi nhân viên kinh doanh tạo cơ hội, báo giá và đơn hàng.')
@section('content')
<div id="salesMasterDataRoot"></div>
@endsection

@push('scripts')
<script src="/assets/js/features/sales-master-data.js?v=20260923-1"></script>
@endpush
