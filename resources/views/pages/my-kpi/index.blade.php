@extends('layouts.app')
@section('title', 'KPI của tôi')
@section('page', 'my-kpi')
@section('page_title', 'KPI của tôi')
@section('page_subtitle', 'Chỉ tiêu được phân theo tài khoản, phòng ban hoặc chức vụ. Kết quả cập nhật tự động.')
@section('content')
@include('partials.kpi-navigation')
<section class="module-panel">
    <div class="panel-head"><h2>Chỉ tiêu được giao</h2><label>Ngày thuộc kỳ <input type="date" id="myKpiDate"></label></div>
    <div id="myKpiRoot" aria-live="polite"><p class="form-note">Đang tải KPI của bạn…</p></div>
</section>
@endsection
@push('scripts')
<script src="/assets/js/features/my-kpi.js?v=20261011-review1"></script>
@endpush
