@extends('layouts.app')

@section('title', 'Hóa đơn bán hàng')
@section('page', 'sales-invoices')
@section('page_title', 'Hóa đơn bán hàng')
@section('page_subtitle', 'Quản lý hóa đơn, hạn thanh toán và công nợ phải thu.')

@section('content')
<div id="salesInvoicesRoot"></div>
@endsection

@push('scripts')
<script src="/assets/js/features/sales-fulfillment.js?v=20260618-1"></script>
@endpush
