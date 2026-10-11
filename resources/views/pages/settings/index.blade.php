@extends('layouts.app')

@section('title', 'Thiết lập')
@section('page', 'settings')
@section('page_title', 'Thiết lập hệ thống')
@section('page_subtitle', 'Quản lý doanh nghiệp, nhân sự, quy trình, dữ liệu và nhận diện thương hiệu.')

@section('content')
<div id="settingsRoot"></div>
@endsection

@push('scripts')
<script src="/assets/js/features/settings.js?v=20261009-icons2"></script>
@endpush
