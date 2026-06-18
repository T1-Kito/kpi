@extends('layouts.app')

@section('title', 'Mẫu in')
@section('page', 'print-templates')
@section('page_title', 'Mẫu in')
@section('page_subtitle', 'Quản lý mẫu Word/PDF và trường trộn dùng khi gửi báo giá cho khách hàng.')
@section('page_actions')
    <button class="btn secondary" type="button" data-download-merge-template>Tải mẫu trường trộn</button>
    <button class="btn primary" type="button" data-create-print-template>Tạo mẫu in</button>
@endsection

@section('content')
    <div id="printTemplatesRoot" data-page="print-templates"></div>
@endsection

@push('scripts')
    <script src="/assets/js/features/print-templates.js?v=20260618-1"></script>
@endpush
