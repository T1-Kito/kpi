@extends('layouts.app')
@section('title', 'Bảo hành')
@section('page', 'warranty-claims')
@section('page_title', 'Quản lý bảo hành')
@section('page_subtitle', 'Quản lý thông tin bảo hành sản phẩm theo serial.')
@section('page_actions')<button class="btn" type="button" data-permission="service.ticket.manage" data-warranty-import>Nhập file</button><button class="btn primary" type="button" data-permission="service.ticket.manage" data-service-create="warranty">+ Thêm bảo hành</button>@endsection
@section('content')
<div class="service-desk-page" data-service-desk-page="warranties">
    <section class="module-panel"><p class="form-note">Đang tải dữ liệu bảo hành…</p></section>
</div>
@endsection
@push('scripts')<script src="/assets/js/features/service-desk.js?v=20261029-list2"></script>@endpush
