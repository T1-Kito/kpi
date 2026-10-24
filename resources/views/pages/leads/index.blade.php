@extends('layouts.app')

@section('title', 'Khách hàng tiềm năng')
@section('page', 'leads')
@section('page_title', 'Khách hàng tiềm năng')
@section('page_subtitle', 'Quản lý khách hàng tiềm năng, phân công phụ trách và theo dõi nguồn phát sinh.')
@section('page_actions')
    <button class="btn" type="button" data-directory-import="leads" data-permission="sales.lead.manage">Nhập file</button>
    <button class="btn primary" type="button" data-permission="sales.lead.manage" data-create-lead>Tạo khách hàng tiềm năng</button>
@endsection

@section('content')
<div id="leadsRoot"></div>
@endsection

@push('scripts')
<script src="/assets/js/features/leads.js?v=20261023-list-audit2"></script>
@endpush
