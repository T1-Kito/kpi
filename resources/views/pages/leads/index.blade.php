@extends('layouts.app')

@section('title', 'Khách hàng tiềm năng')
@section('page', 'leads')
@section('page_title', 'Khách hàng tiềm năng')
@section('page_subtitle', 'Quản lý khách hàng tiềm năng, phân công phụ trách và theo dõi nguồn phát sinh.')
@section('page_actions')
    <button class="btn primary" type="button" data-create-lead>Tạo khách hàng tiềm năng</button>
@endsection

@section('content')
<div id="leadsRoot"></div>
@endsection

@push('scripts')
<script src="/assets/js/features/leads.js"></script>
@endpush
