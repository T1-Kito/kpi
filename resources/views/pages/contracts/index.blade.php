@extends('layouts.app')
@section('title', 'Hợp đồng')
@section('page', 'contracts')
@section('page_title', 'Hợp đồng')
@section('page_subtitle', 'Quản lý hợp đồng, mốc thanh toán và nghiệm thu theo báo giá hoặc đơn hàng.')
@section('page_actions')<button class="btn primary" type="button" data-contract-create>Tạo hợp đồng</button>@endsection
@section('content')<div id="contractsRoot"></div>@endsection
@push('scripts')<script src="/assets/js/features/contracts.js?v=20260923-6"></script>@endpush
