@extends('layouts.app')

@section('title', 'Thiết lập')
@section('page', 'settings')
@section('page_title', 'Thiết lập hệ thống')
@section('page_subtitle', 'Quản trị công ty, quyền truy cập, dữ liệu nền, SLA và nhật ký vận hành.')

@section('content')
<div id="settingsRoot"></div>
@endsection

@push('scripts')
<script src="/assets/js/features/settings.js?v=20260618-4"></script>
@endpush
