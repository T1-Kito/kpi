@extends('layouts.app')

@section('title', 'Vai trò')
@section('page', 'roles')
@section('page_title', 'Vai trò và quyền')
@section('page_subtitle', 'Quản lý vai trò, phạm vi dữ liệu và tập quyền được phép thao tác.')
@section('page_actions')
    <button class="btn primary" type="button" data-create-role>Tạo vai trò</button>
@endsection

@section('content')
<div id="rolesRoot"></div>
@endsection

@push('scripts')
<script src="/assets/js/features/roles.js?v=20261008-1"></script>
@endpush
