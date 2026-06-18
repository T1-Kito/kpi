@extends('layouts.app')

@section('title', 'Tồn kho')
@section('page', 'inventory')
@section('page_title', 'Tồn kho')
@section('page_subtitle', 'Theo dõi tồn thực tế, tồn đã giữ, tồn khả dụng và lịch sử giao dịch kho.')

@section('content')
<div id="inventoryRoot"></div>
@endsection

@push('scripts')
<script src="/assets/js/features/inventory.js?v=20260615-3"></script>
@endpush
