@extends('layouts.app')

@section('title', 'Báo giá nhà cung cấp')
@section('page', 'supplier-quotations')
@section('page_title', 'Báo giá nhà cung cấp')
@section('page_subtitle', 'Thu thập báo giá, so sánh giá mua và chọn báo giá làm căn cứ tạo đơn mua.')

@section('page_actions')
    <button class="btn primary" type="button" data-create-supplier-quotation>Tạo báo giá NCC</button>
@endsection

@section('content')
<div id="supplierQuotationsRoot" class="supplier-quotations-page">
    <section class="module-panel">
        <div class="panel-head">
            <div>
                <h2>Danh sách báo giá NCC</h2>
                <span>Mỗi yêu cầu mua có thể có nhiều báo giá, nhưng chỉ một báo giá được chọn để đi tiếp lên PO.</span>
            </div>
            <span class="badge info">{{ $supplierQuotations->count() }} báo giá</span>
        </div>

        <div class="table-wrap">
            <table class="list-table">
                <thead>
                    <tr>
                        <th>Mã báo giá</th>
                        <th>Yêu cầu mua</th>
                        <th>Nhà cung cấp</th>
                        <th>Ngày báo giá</th>
                        <th>Hiệu lực</th>
                        <th>Tổng tiền</th>
                        <th>Trạng thái</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($supplierQuotations as $quotation)
                        @php
                            $label = ['draft' => 'Nháp', 'selected' => 'Đã chọn', 'rejected' => 'Không chọn'][$quotation->status] ?? $quotation->status;
                            $tone = ['draft' => 'info', 'selected' => 'success', 'rejected' => 'inactive'][$quotation->status] ?? 'info';
                        @endphp
                        <tr>
                            <td><span class="record-code-chip">{{ $quotation->code }}</span></td>
                            <td>{{ $quotation->purchaseRequest?->code ?: '-' }}</td>
                            <td><strong>{{ $quotation->supplier?->name ?: '-' }}</strong></td>
                            <td>{{ $quotation->quoted_at?->format('d/m/Y') }}</td>
                            <td>{{ $quotation->valid_until?->format('d/m/Y') ?: '-' }}</td>
                            <td><strong>{{ number_format((float) $quotation->total_amount, 0, ',', '.') }}</strong></td>
                            <td><span class="badge {{ $tone }}">{{ $label }}</span></td>
                            <td class="actions-cell">
                                <details class="row-action-menu">
                                    <summary aria-label="Mở thao tác">⋮</summary>
                                    <div class="row-action-menu-list">
                                        <button class="btn" type="button" data-open-supplier-quotation="{{ $quotation->id }}">Mở</button>
                                        @if ($quotation->status === 'draft')
                                            <button class="btn primary" type="button" data-select-supplier-quotation="{{ $quotation->id }}">Chọn báo giá</button>
                                        @endif
                                        @if ($quotation->status === 'selected')
                                            <button class="btn primary" type="button" data-create-po-from-supplier-quotation="{{ $quotation->id }}">Tạo đơn mua</button>
                                        @endif
                                    </div>
                                </details>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8">
                                <div class="empty-state">
                                    <strong>Chưa có báo giá NCC</strong>
                                    <span>Tạo báo giá để so sánh giá mua trước khi lên đơn mua.</span>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</div>

<script id="supplierQuotationsData" type="application/json">
{!! json_encode([
    'supplierQuotations' => $supplierQuotations->values(),
    'purchaseRequests' => $purchaseRequests->values(),
    'suppliers' => $suppliers->values(),
    'skus' => $skus->values(),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!}
</script>
@endsection

@push('scripts')
<script src="/assets/js/features/supplier-quotations.js?v=20260619-4"></script>
@endpush
