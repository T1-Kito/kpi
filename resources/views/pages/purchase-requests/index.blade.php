@extends('layouts.app')

@section('title', 'Yêu cầu mua')
@section('page', 'purchase-requests')
@section('page_title', 'Yêu cầu mua')
@section('page_subtitle', 'Theo dõi nhu cầu mua phát sinh từ đơn bán thiếu tồn.')
@section('page_actions')
<button class="btn primary" type="button" data-create-purchase-request>Tạo yêu cầu mua</button>
@endsection

@section('content')
<div id="purchaseRequestsRoot"></div>
@endsection

@push('scripts')
<script src="/assets/js/features/purchase-requests.js?v=20261005-2"></script>
@endpush
