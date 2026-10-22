@extends('layouts.app')

@section('title', 'Sổ điểm KPI')
@section('page', 'kpi-adjustments')
@section('page_title', 'Sổ điểm nhân viên')
@section('page_subtitle', 'Cộng/trừ điểm có duyệt và truy vết; dữ liệu hiển thị theo phạm vi quyền của bạn.')

@section('content')
<div id="kpiAdjustmentsRoot">
    <section class="module-panel"><p class="form-note">Đang xác thực và tải sổ điểm…</p></section>
</div>
@endsection

@push('scripts')
<script src="/assets/js/features/kpi-adjustments.js?v=20261019-safety1"></script>
@endpush
