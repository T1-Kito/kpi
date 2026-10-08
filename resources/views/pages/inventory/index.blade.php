@extends('layouts.app')

@section('title', !empty($transactionsView) ? 'Giao dịch kho' : 'Tồn kho')
@section('page', !empty($transactionsView) ? 'inventory-transactions' : 'inventory')
@section('page_title', !empty($transactionsView) ? 'Giao dịch kho' : 'Tồn kho')
@section('page_subtitle', !empty($transactionsView) ? 'Tra cứu lịch sử nhập xuất và chứng từ nguồn.' : 'Theo dõi tồn thực tế, đã giữ và khả dụng. Bấm vào hàng để mở thẻ kho.')

@section('content')
<div id="inventoryRoot" data-inventory-mode="{{ !empty($transactionsView) ? 'transactions' : 'balances' }}"></div>
@endsection

@push('scripts')
<script src="/assets/js/features/inventory.js?v=20260615-3"></script>
@endpush
