@extends('layouts.app')
@section('title', 'Ticket hỗ trợ')
@section('page', 'service-tickets')
@section('page_title', 'Ticket hỗ trợ')
@section('page_subtitle', 'Theo dõi yêu cầu chăm sóc khách hàng, SLA và lịch sử xử lý.')
@section('page_actions')<button class="btn primary" type="button" data-permission="service.ticket.manage" data-service-create="ticket">Tạo ticket</button>@endsection
@section('content')
<div class="service-desk-page" data-service-desk-page="tickets">
<section class="module-panel"><p class="form-note">Đang tải hàng đợi dịch vụ…</p></section></div>
@endsection
@push('scripts')<script src="/assets/js/features/service-desk.js"></script>@endpush
