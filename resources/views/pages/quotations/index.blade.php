@extends('layouts.app')

@section('title', 'Báo giá')
@section('page', 'quotations')
@section('page_title', 'Báo giá')
@section('page_subtitle', 'Tự tính giá vốn, biên lợi nhuận và trạng thái duyệt.')
@section('page_actions')
    <button class="btn primary" type="button" data-permission="sales.quotation.create" data-create-quotation>Tạo báo giá</button>
@endsection

@section('content')
<div id="quotationsRoot"></div>
@endsection

@push('scripts')
<script src="/assets/vendor/jszip.min.js"></script>
<script src="/assets/vendor/docx-preview.min.js"></script>
<script src="/assets/js/features/quotations.js?v=20261008-2"></script>
@endpush
