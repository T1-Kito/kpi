@extends('layouts.app')

@section('title', 'Cơ hội bán hàng')
@section('page', 'deals')
@section('page_title', 'Cơ hội bán hàng')
@section('page_subtitle', 'Theo dõi cơ hội từ tiếp nhận nhu cầu đến chốt báo giá.')
@section('page_actions')
    <button class="btn primary" type="button" data-create-deal>Tạo cơ hội</button>
@endsection

@section('content')
<div id="dealsRoot"></div>
@endsection

@push('scripts')
<script src="/assets/js/features/deals.js?v=20261009-care1"></script>
@endpush
