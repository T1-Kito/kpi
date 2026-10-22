@extends('layouts.app')
@section('title', 'Quản lý nhập dữ liệu')
@section('page', 'import-center')
@section('page_title', 'Quản lý nhập dữ liệu')
@section('page_subtitle', 'Kiểm tra, xác nhận và theo dõi từng lần nhập danh sách.')
@section('content')
<div id="importCenterRoot" aria-live="polite"></div>
@endsection
@push('scripts')
<script src="/assets/js/features/import-center.js?v=20261022-3"></script>
@endpush
