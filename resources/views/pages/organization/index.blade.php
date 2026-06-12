@extends('layouts.app')

@section('title', 'Phòng ban và chức vụ')
@section('page', 'organization')
@section('page_title', 'Phòng ban và chức vụ')
@section('page_subtitle', 'Quản lý cơ cấu tổ chức để gắn người dùng, phân quyền và KPI theo bộ phận.')
@section('page_actions')
    <button class="btn" type="button" data-create-position>Tạo chức vụ</button>
    <button class="btn primary" type="button" data-create-department>Tạo phòng ban</button>
@endsection

@section('content')
<div id="organizationRoot"></div>
@endsection

@push('scripts')
<script src="/assets/js/features/organization.js"></script>
@endpush
