@extends('layouts.app')

@section('title', 'Khách hàng')
@section('page', 'customers')
@section('page_title', 'Khách hàng')
@section('page_subtitle', 'Quản lý khách hàng, liên hệ, hạn mức công nợ và trạng thái sử dụng.')
@section('page_actions')
    <button class="btn primary" type="button" data-create-customer>Tạo khách hàng</button>
@endsection

@section('content')
<div id="customersRoot"></div>
@endsection

@push('scripts')
<script src="/assets/js/features/customers.js"></script>
@endpush
