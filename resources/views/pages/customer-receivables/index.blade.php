@extends('layouts.app')

@section('title', 'Công nợ khách hàng')
@section('page', 'customer-receivables')
@section('page_title', 'Công nợ khách hàng')
@section('page_subtitle', 'Theo dõi hạn mức, dư nợ, hóa đơn quá hạn và tình trạng thu tiền.')

@section('content')
<div id="customerReceivablesRoot"></div>
@endsection

@push('scripts')
<script src="/assets/js/features/customer-receivables.js?v=20260617-1"></script>
@endpush
