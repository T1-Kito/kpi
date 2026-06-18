@extends('layouts.app')

@section('title', 'Nhà cung cấp')
@section('page', 'suppliers')
@section('page_title', 'Nhà cung cấp')
@section('page_subtitle', 'Quản lý nguồn mua, thông tin liên hệ, điều khoản và đánh giá nhà cung cấp.')
@section('page_actions')
    <button class="btn primary" type="button" data-create-supplier>Tạo nhà cung cấp</button>
@endsection

@section('content')
<div id="suppliersRoot"></div>
@endsection

@push('scripts')
<script src="/assets/js/features/suppliers.js?v=20260615-2"></script>
@endpush
