@extends('layouts.app')

@section('title', 'Thiết lập')
@section('page', 'settings')
@section('page_title', 'Thiết lập hệ thống')
@section('page_subtitle', 'Cấu hình công ty, giao diện, luồng duyệt mua hàng và thời hạn công việc theo từng mục riêng.')

@section('content')
<div id="settingsRoot"></div>
@endsection

@push('scripts')
<script src="/assets/js/features/settings.js?v=20261008-5"></script>
@endpush
