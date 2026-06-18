@extends('layouts.app')

@section('title', 'Cảnh báo')
@section('page', 'alerts')
@section('page_title', 'Trung tâm cảnh báo')
@section('page_subtitle', 'Theo dõi cảnh báo tồn kho, quá hạn, biên lợi nhuận và các điểm cần xử lý.')

@section('content')
<div id="alertsRoot"></div>
@endsection

@push('scripts')
<script src="/assets/js/features/alerts.js?v=20260615-2"></script>
@endpush
