@extends('layouts.app')

@section('title', 'ÄÆ¡n mua')
@section('page', 'purchase-orders')
@section('page_title', 'ÄÆ¡n mua')
@section('page_subtitle', 'Láº­p Ä‘Æ¡n mua tá»« yÃªu cáº§u Ä‘Ã£ duyá»‡t vÃ  theo dÃµi tráº¡ng thÃ¡i mua hÃ ng.')
@section('page_actions')
    <button class="btn primary" type="button" data-create-purchase-order>Táº¡o tá»« yÃªu cáº§u mua</button>
@endsection

@section('content')
<div id="purchaseOrdersRoot"></div>
@endsection

@push('scripts')
<script src="/assets/js/features/purchase-orders.js?v=20260619-1"></script>
@endpush
