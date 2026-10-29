@extends('layouts.app')
@section('title', 'Chăm sóc khách hàng')
@section('page', 'service-tickets')
@section('page_title', 'Chăm sóc khách hàng')
@section('page_subtitle', 'Theo dõi yêu cầu chăm sóc khách hàng, SLA và lịch sử xử lý.')
@section('page_actions')<button class="btn primary" type="button" data-permission="service.ticket.manage" data-service-create="ticket">Tiếp nhận yêu cầu</button>@endsection
@section('content')
<div class="service-desk-page" data-service-desk-page="tickets">
<section class="module-panel"><p class="form-note">Đang tải hàng đợi dịch vụ…</p></section></div>
@endsection
@push('scripts')<script src="/assets/js/features/service-desk.js?v=20261029-list2"></script>@endpush
