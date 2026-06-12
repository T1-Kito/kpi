@extends('layouts.app')

@section('title', 'Yêu cầu mua')
@section('page', 'purchase-requests')
@section('page_title', 'Yêu cầu mua')
@section('page_subtitle', 'Theo dõi nhu cầu mua phát sinh từ đơn bán thiếu tồn.')

@section('content')
<div id="purchaseRequestsRoot"></div>
@endsection

@push('scripts')
<script src="/assets/js/features/purchase-requests.js"></script>
@endpush
