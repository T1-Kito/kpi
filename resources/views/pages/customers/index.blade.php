@extends('layouts.app')

@section('title', 'Khách hàng')
@section('page', 'customers')
@section('page_title', 'Khách hàng')
@section('page_subtitle', 'Quản lý khách hàng, liên hệ, hạn mức công nợ và trạng thái sử dụng.')
@section('page_actions')
    <a class="btn" href="/import-center" data-permission="customer.manage">Quản lý nhập</a>
    <button class="btn" type="button" data-customer-care-board>Việc chăm sóc</button>
    <button class="btn" type="button" data-import-customers data-permission="customer.manage">Nhập file</button>
    <button class="btn" type="button" data-customer-duplicates data-permission="master.merge">Xét khách trùng</button>
    <button class="btn primary" type="button" data-create-customer>Tạo khách hàng</button>
@endsection

@section('content')
<div id="customersRoot"></div>
@endsection

@push('scripts')
<script src="/assets/js/features/customers.js?v=20261020-care-links2"></script>
@endpush
