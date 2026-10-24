@extends('layouts.app')
@section('title', 'Người liên hệ')
@section('page', 'customer-contacts')
@section('page_title', 'Người liên hệ')
@section('page_subtitle', 'Quản lý đầu mối liên hệ của từng khách hàng.')
@section('page_actions')
<button class="btn" type="button" data-directory-import="contacts" data-permission="customer.manage">Nhập file</button>
<button class="btn primary" data-create-contact data-permission="customer.manage">Thêm người liên hệ</button>
@endsection
@section('content')
<div id="customerContactsRoot"></div>
@endsection
@push('scripts')
<script src="/assets/js/features/customer-contacts.js?v=20261023-list-audit2"></script>
@endpush
