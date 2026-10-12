@extends('layouts.app')

@section('title', 'Mẫu in')
@section('page', 'print-templates')
@section('page_title', 'Mẫu in')
@section('page_subtitle', 'Quản lý mẫu Word và trường trộn cho báo giá, đơn hàng, phiếu xuất kho và hợp đồng.')
@section('page_actions')
    <button class="btn primary" type="button" data-create-print-template>Tải mẫu Word lên</button>
@endsection

@section('content')
    <div id="printTemplatesRoot" data-page="print-templates"></div>
@endsection

@push('scripts')
    <script src="/assets/js/features/print-templates.js?v=20261012-template-sample1"></script>
@endpush
