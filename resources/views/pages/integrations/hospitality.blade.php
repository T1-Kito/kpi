@extends('layouts.app')
@php
    $section = request()->route('section');
    $heading = ['guests' => 'Khách lưu trú', 'stays' => 'Lượt lưu trú', 'rooms' => 'Danh sách phòng', 'devices' => 'Thiết bị', 'commands' => 'Lệnh thiết bị'][$section ?? ''] ?? 'Tổng quan kết nối';
@endphp
@section('title', 'FaceID · '.$heading)
@section('page', 'hospitality-integration')
@section('page_title', 'FaceID / '.$heading)
@section('page_subtitle', $section ? 'Tra cứu dữ liệu từ phần mềm FaceID.' : 'Theo dõi dữ liệu và tình trạng đồng bộ từ phần mềm FaceID.')
@section('content')
<div id="hospitalityRoot" class="hi-root" data-section="{{ $section }}"><p>Đang chuẩn bị màn hình FaceID…</p></div>
@endsection
@push('scripts')
<script src="/assets/js/features/hospitality-integration.js?v=20261110-readonly1"></script>
@endpush
