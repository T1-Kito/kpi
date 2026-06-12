@extends('layouts.app')

@section('title', 'Nhật ký hệ thống')
@section('page', 'audit-logs')
@section('page_title', 'Nhật ký hệ thống')
@section('page_subtitle', 'Theo dõi thao tác quan trọng, trạng thái trước/sau và lý do xử lý trong hệ thống.')

@section('content')
<div id="auditLogsRoot"></div>
@endsection

@push('scripts')
<script src="/assets/js/features/audit-logs.js"></script>
@endpush
