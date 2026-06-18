@extends('layouts.app')

@section('title', 'Thu tiền')
@section('page', 'customer-payments')
@section('page_title', 'Thu tiền')
@section('page_subtitle', 'Theo dõi các lần khách hàng thanh toán và đối chiếu hóa đơn.')

@section('content')
<div id="customerPaymentsRoot"></div>
@endsection

@push('scripts')
<script src="/assets/js/features/sales-fulfillment.js?v=20260618-1"></script>
@endpush
