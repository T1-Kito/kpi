@extends('layouts.app')

@section('title', 'Mẫu in')
@section('page', 'print-templates')
@section('page_title', 'Mẫu in')
@section('page_subtitle', 'Quản lý mẫu Word và trường trộn cho báo giá, đơn hàng, phiếu xuất kho và hợp đồng.')
@section('page_actions')
    <button class="btn secondary" type="button" data-download-merge-template="quotation">Mẫu Báo giá</button>
    <button class="btn secondary" type="button" data-download-merge-template="sales_order">Mẫu Đơn bán</button>
    <button class="btn secondary" type="button" data-download-merge-template="goods_issue">Mẫu Phiếu xuất</button>
    <button class="btn secondary" type="button" data-download-merge-template="contract">Mẫu Hợp đồng</button>
    <button class="btn primary" type="button" data-create-print-template>Tạo mẫu in</button>
@endsection

@section('content')
    <div id="printTemplatesRoot" data-page="print-templates"></div>
@endsection

@push('scripts')
    <script src="/assets/js/features/print-templates.js?v=20260923-5"></script>
@endpush
