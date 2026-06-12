@extends('layouts.app')

@section('title', 'Đơn mua')
@section('page', 'purchase-orders')
@section('page_title', 'Đơn mua')
@section('page_subtitle', 'Lập đơn mua từ yêu cầu đã duyệt và theo dõi trạng thái mua hàng.')
@section('page_actions')
    <button class="btn primary" type="button" data-create-purchase-order>Tạo từ yêu cầu mua</button>
@endsection

@section('content')
<div id="purchaseOrdersRoot"></div>
@endsection

@push('scripts')
<script src="/assets/js/features/purchase-orders.js"></script>
@endpush
