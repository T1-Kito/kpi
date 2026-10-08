@extends('layouts.app')
@section('title', 'Bảo hành')
@section('page', 'warranty-claims')
@section('page_title', 'Quản lý bảo hành')
@section('page_subtitle', 'Quản lý thông tin bảo hành sản phẩm theo serial.')
@section('page_actions')<button class="btn primary" type="button" data-service-create="warranty">+ Thêm bảo hành</button>@endsection
@section('content')
@php($active = $warranties->filter(fn ($claim) => $claim->warranty_end_at && $claim->warranty_end_at->copy()->endOfDay()->gte(now()))->count())
<div class="service-desk-page" data-service-desk-page="warranties">
    <section class="service-warranty-stats">
        <article><span>Tổng bảo hành</span><strong>{{ $warranties->count() }}</strong></article><article><span>Còn hiệu lực</span><strong>{{ $active }}</strong></article><article><span>Hết hạn</span><strong>{{ $warranties->count() - $active }}</strong></article><article><span>Đăng ký hôm nay</span><strong>{{ $warranties->filter(fn ($claim) => $claim->created_at?->isToday())->count() }}</strong></article>
    </section>
    <section class="module-panel"><div class="panel-head"><div><h2>Danh sách bảo hành</h2><span>Tìm nhanh theo serial, sản phẩm hoặc khách hàng.</span></div></div>
        <div class="warranty-filter-grid" data-warranty-filter><div class="field"><label>Số serial</label><input id="warrantyFilterSerial" placeholder="Nhập số serial cần tìm..."></div><div class="field"><label>Sản phẩm / Model</label><input id="warrantyFilterProduct" placeholder="Nhập model / tên sản phẩm..."></div><div class="field"><label>Khách hàng</label><input id="warrantyFilterCustomer" placeholder="Nhập tên khách hàng..."></div><div class="field"><label>Ngày mua</label><input id="warrantyFilterPurchase" type="date"></div><div class="warranty-filter-actions"><button class="btn primary" type="button" data-warranty-search>Tìm kiếm</button><button class="btn secondary" type="button" data-warranty-reset>Làm mới</button></div></div>
        <div class="table-wrap"><table class="list-table"><thead><tr><th>Mã</th><th>Số serial</th><th>Sản phẩm / Model</th><th>Khách hàng</th><th>Ngày bán</th><th>Hết hạn</th><th>Trạng thái</th></tr></thead><tbody data-warranty-list>
        @forelse($warranties as $claim)
            @php($isActive = $claim->warranty_end_at && $claim->warranty_end_at->copy()->endOfDay()->gte(now()))
            <tr data-serial="{{ strtolower($claim->serial_number) }}" data-product="{{ strtolower($claim->product_name) }}" data-customer="{{ strtolower($claim->customer_name ?: $claim->customer?->name) }}" data-purchase="{{ optional($claim->purchase_date)->format('Y-m-d') }}"><td><span class="record-code-chip">{{ $claim->code }}</span></td><td><strong>{{ $claim->serial_number }}</strong></td><td>{{ $claim->product_name }}</td><td><strong>{{ $claim->customer_name ?: $claim->customer?->name }}</strong><br><small>{{ $claim->customer_email ?: $claim->customer?->email }}</small></td><td>{{ optional($claim->purchase_date)->format('d/m/Y') ?: '—' }}</td><td>{{ optional($claim->warranty_end_at)->format('d/m/Y') ?: '—' }}<br><small>{{ $isActive ? 'Còn hiệu lực' : 'Đã hết hạn' }}</small></td><td><span class="badge {{ $isActive ? 'success' : 'warning' }}">{{ $isActive ? 'Còn hiệu lực' : 'Hết hạn' }}</span></td></tr>
        @empty
            <tr><td colspan="7"><div class="empty-state"><strong>Chưa có đăng ký bảo hành</strong><span>Thêm serial đầu tiên để bắt đầu quản lý.</span></div></td></tr>
        @endforelse
        </tbody></table></div>
    </section>
</div>
<script id="serviceDeskData" type="application/json">{!! json_encode(['customers'=>$customers->values()], JSON_UNESCAPED_UNICODE) !!}</script>
@endsection
@push('scripts')<script src="/assets/js/features/service-desk.js"></script>@endpush
