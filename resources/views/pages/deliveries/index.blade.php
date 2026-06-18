@extends('layouts.app')

@section('title', 'Giao hàng')
@section('page', 'deliveries')
@section('page_title', 'Giao hàng')
@section('page_subtitle', 'Theo dõi xác nhận khách nhận hàng từ các phiếu xuất kho.')

@section('content')
<div id="deliveriesRoot"></div>
@endsection

@push('scripts')
<script src="/assets/js/features/sales-fulfillment.js?v=20260618-1"></script>
@endpush
