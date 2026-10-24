@extends('layouts.app')

@section('title', 'Người dùng')
@section('page', 'users')
@section('page_title', 'Người dùng')
@section('page_subtitle', 'Quản lý tài khoản, phòng ban, chức vụ, trạng thái và vai trò truy cập.')
@section('page_actions')
    <button class="btn" type="button" data-directory-import="users" data-permission="user.manage">Nhập người dùng</button>
    <a class="btn" href="/organization">Phòng ban và chức vụ</a>
    <button class="btn primary" type="button" data-create-user>Tạo người dùng</button>
@endsection

@section('content')
<div id="usersRoot"></div>
@endsection

@push('scripts')
<script src="/assets/js/features/users.js?v=20261023-organization1"></script>
@endpush
