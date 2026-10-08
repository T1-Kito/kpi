@extends('layouts.app')

@section('title', 'Phòng ban và chức vụ')
@section('page', 'organization')
@section('page_title', 'Phòng ban và chức vụ')
@section('page_subtitle', 'Tạo phòng ban và chức vụ trước; gán nhân viên vào sau ở mục Người dùng.')
@section('page_actions')
    <button class="btn" type="button" data-create-position>Tạo chức vụ</button>
    <button class="btn primary" type="button" data-create-department>Tạo phòng ban</button>
@endsection

@section('content')
<div id="organizationRoot"></div>
@endsection

@push('scripts')
<script src="/assets/js/features/organization.js?v=20260921-1"></script>
@endpush
