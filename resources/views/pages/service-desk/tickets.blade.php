@extends('layouts.app')
@section('title', 'Ticket hỗ trợ')
@section('page', 'service-tickets')
@section('page_title', 'Ticket hỗ trợ')
@section('page_subtitle', 'Theo dõi yêu cầu chăm sóc khách hàng, SLA và lịch sử xử lý.')
@section('page_actions')<button class="btn primary" type="button" data-service-create="ticket">Tạo ticket</button>@endsection
@section('content')
<div class="service-desk-page" data-service-desk-page="tickets">
<section class="module-panel"><div class="panel-head"><div><h2>Hàng đợi dịch vụ</h2><span>Ticket đang mở, đang xử lý và chờ phản hồi khách hàng.</span></div></div>
<div class="table-wrap"><table class="list-table"><thead><tr><th>Mã</th><th>Khách hàng</th><th>Tiêu đề</th><th>Loại</th><th>Ưu tiên</th><th>SLA xử lý</th><th>Trạng thái</th></tr></thead><tbody>
@forelse($tickets as $ticket)<tr><td><span class="record-code-chip">{{ $ticket->code }}</span></td><td>{{ $ticket->customer?->name }}</td><td>{{ $ticket->subject }}</td><td>{{ $ticket->category }}</td><td><span class="badge {{ in_array($ticket->priority,['high','urgent']) ? 'danger':'info' }}">{{ $ticket->priority }}</span></td><td>{{ $ticket->resolution_due_at?->format('d/m H:i') ?: '-' }}</td><td><span class="badge {{ $ticket->status === 'resolved' ? 'success':'warning' }}">{{ $ticket->status }}</span></td></tr>@empty<tr><td colspan="7"><div class="empty-state"><strong>Chưa có ticket</strong><span>Tạo ticket khi khách hàng cần hỗ trợ hoặc bảo hành.</span></div></td></tr>@endforelse
</tbody></table></div></section></div>
<script id="serviceDeskData" type="application/json">{!! json_encode(['customers'=>$customers->values(),'users'=>$users->values()], JSON_UNESCAPED_UNICODE) !!}</script>
@endsection
@push('scripts')<script src="/assets/js/features/service-desk.js"></script>@endpush
