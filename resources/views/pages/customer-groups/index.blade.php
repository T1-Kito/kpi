@extends('layouts.app')
@section('title', 'Nhóm khách hàng')
@section('page', 'customer-groups')
@section('page_title', 'Nhóm khách hàng')
@section('content')
<div id="customerGroupsRoot" aria-live="polite"></div>
@endsection
@push('scripts')
<script src="/assets/js/features/customer-groups-page.js?v=20261025-4"></script>
@endpush
