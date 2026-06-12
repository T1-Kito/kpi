@extends('layouts.app')

@section('title', 'Bảng điều khiển')
@section('page', 'dashboard')
@section('page_title', 'Tổng quan')
@section('page_subtitle', 'Theo dõi công việc, cảnh báo, bán hàng, mua hàng và kho trong một màn hình.')
@section('page_actions')
    <a class="btn primary" href="/leads">Tạo khách hàng tiềm năng</a>
    <a class="btn" href="/tasks">Xem công việc</a>
@endsection

@section('content')
<div id="dashboardRoot" class="dashboard-root"></div>
@endsection

@push('scripts')
<script src="/assets/js/features/dashboard.js"></script>
@endpush
