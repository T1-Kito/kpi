@extends('layouts.app')

@section('title', 'KPI')
@section('page', 'kpi')
@section('page_title', 'KPI vận hành')
@section('page_subtitle', 'Tổng hợp hiệu suất bán hàng, mua hàng, kho, công việc và cảnh báo từ dữ liệu thực tế.')

@section('content')
<div id="kpiRoot"></div>
@endsection

@push('scripts')
<script src="/assets/js/features/kpi.js"></script>
@endpush
