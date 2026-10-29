@extends('layouts.app')

@section('title', 'Khách hàng')
@section('page', 'customers')
@section('page_title', 'Khách hàng')
@section('page_subtitle', 'Quản lý khách hàng, liên hệ, hạn mức công nợ và trạng thái sử dụng.')
@section('page_actions')
    <button class="btn" type="button" data-customer-care-board>Việc chăm sóc</button>
    <button class="btn" type="button" data-customer-duplicates data-permission="master.merge">Xét khách trùng</button>
@endsection

@section('content')
<div id="customersRoot"></div>
@endsection

@push('scripts')
<script src="/assets/js/features/customers.js?v=20261028-perf2"></script>
@endpush
