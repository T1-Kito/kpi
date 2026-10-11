@extends('layouts.app')

@section('title', 'Cấu hình KPI')
@section('page', 'kpi-settings')
@section('page_title', 'Thiết lập KPI')
@section('page_subtitle', 'Danh mục chỉ tiêu, mục tiêu và trạng thái khóa kỳ; chỉ dành cho người được cấp quyền.')

@section('content')
@include('partials.kpi-navigation')
<div id="kpiSettingsRoot">
    <section class="module-panel"><p class="form-note">Đang xác thực và tải cấu hình KPI…</p></section>
</div>
@endsection

@push('scripts')
<script src="/assets/js/features/kpi-settings.js?v=20261011-review1"></script>
@endpush
