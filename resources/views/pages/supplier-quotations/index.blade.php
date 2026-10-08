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
    <section class="module-panel procurement-inbox">
        <div class="panel-head"><div><h2>Yêu cầu chờ xử lý báo giá</h2><span>Bấm mã yêu cầu để xem các báo giá liên quan. Lấy báo giá mới sẽ kế thừa hàng hóa và số lượng.</span></div><button class="btn small" type="button" data-toggle-sq-queue>Xem tất cả ({{ $purchaseRequests->count() }})</button></div>
        <div class="table-wrap"><table class="list-table"><thead><tr><th>Yêu cầu</th><th>Lý do mua</th><th>Mã hàng</th><th>Tên sản phẩm</th><th>Số lượng</th><th>ĐVT</th><th>Người yêu cầu</th><th>Tiến độ</th><th></th></tr></thead><tbody>
        @forelse ($purchaseRequests as $pr)
            @php
                $quotes = $supplierQuotations->where('purchase_request_id', $pr->id);
                $requestIndex = $loop->index;
            @endphp
            @foreach($pr->items->isEmpty() ? [null] : $pr->items as $item)
            <tr data-sq-queue-row="{{ $pr->id }}" data-sq-queue-index="{{ $requestIndex }}" @if($requestIndex >= 5) hidden @endif>
                <td><button type="button" class="sq-request-link mono" data-filter-sq-request="{{ $pr->id }}">{{ $pr->code }}</button></td>
                <td>{{ $pr->reason ?: '—' }}</td>
                <td class="mono">{{ $item?->sku?->sku_code ?: '—' }}</td>
                <td>{{ $item?->sku?->name ?: '—' }}</td>
                <td>{{ $item ? (float)$item->quantity : '—' }}</td>
                <td>{{ $item?->sku?->unit ?: '—' }}</td>
                <td>{{ $pr->requester?->name ?: '—' }}</td>
                <td><span class="badge {{ $quotes->contains('status', 'selected') ? 'success' : 'info' }}">{{ $quotes->contains('status', 'selected') ? 'Đã chọn NCC' : ($quotes->count() ? $quotes->count().' báo giá đang so sánh' : 'Chờ lấy báo giá') }}</span></td>
                <td><button class="btn small" type="button" data-quote-purchase-request="{{ $pr->id }}">+ Lấy báo giá</button></td>
            </tr>
            @endforeach
        @empty
            <tr><td colspan="9"><div class="empty-state">Chưa có yêu cầu mua đã duyệt. Duyệt yêu cầu mua để bắt đầu lấy báo giá.</div></td></tr>
        @endforelse
        </tbody></table></div>
    </section>
    <section class="module-panel" data-sq-list>
        <div class="panel-head">
            <div>
                <h2>Danh sách báo giá NCC</h2>
                <span>Mỗi yêu cầu mua có thể có nhiều báo giá, nhưng chỉ một báo giá được chọn để đi tiếp lên PO.</span>
            </div>
            <span class="badge info" data-sq-count>{{ $supplierQuotations->count() }} báo giá</span>
        </div>

        <div class="sq-list-filters">
            <label>Yêu cầu mua<select data-sq-filter="request"><option value="">Tất cả yêu cầu</option>@foreach($purchaseRequests->concat($supplierQuotations->pluck('purchaseRequest')->filter())->unique('id') as $request)<option value="{{ $request->id }}">{{ $request->code }}</option>@endforeach</select></label>
            <label>Nhà cung cấp<select data-sq-filter="supplier"><option value="">Tất cả nhà cung cấp</option>@foreach($suppliers->concat($supplierQuotations->pluck('supplier')->filter())->unique('id') as $supplier)<option value="{{ $supplier->id }}">{{ $supplier->name }}</option>@endforeach</select></label>
            <label>Trạng thái<select data-sq-filter="status"><option value="">Tất cả trạng thái</option><option value="draft">Nháp</option><option value="selected">Đã chọn, chưa tạo đơn</option><option value="ordered">Đã tạo đơn mua</option><option value="rejected">Không chọn</option></select></label>
            <button type="button" class="btn small" data-clear-sq-filters>Xóa bộ lọc</button>
        </div>
        <div class="table-wrap">
            <table class="list-table">
                <thead>
                    <tr>
                        <th>Yêu cầu mua</th>
                        <th>Mã báo giá</th>
                        <th>Lý do mua</th>
                        <th>Tên sản phẩm</th>
                        <th>Nhà cung cấp</th>
                        <th>Ngày báo giá</th>
                        <th>Hiệu lực</th>
                        <th>Tổng tiền</th>
                        <th>Trạng thái</th>
                        <th>Tiến độ duyệt</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($supplierQuotations as $quotation)
                        @php
                            $order = $quotation->purchaseOrders->first();
                            $label = $order ? 'Đã tạo đơn mua' : (['draft' => 'Nháp', 'selected' => 'Đã chọn làm căn cứ mua hàng', 'rejected' => 'Không chọn'][$quotation->status] ?? $quotation->status);
                            $tone = ['draft' => 'info', 'selected' => 'success', 'rejected' => 'inactive'][$quotation->status] ?? 'info';
                        @endphp
                        <tr data-supplier-quotation-row="{{ $quotation->id }}" data-sq-request="{{ $quotation->purchase_request_id }}" data-sq-supplier="{{ $quotation->supplier_id }}" data-sq-status="{{ $order ? 'ordered' : $quotation->status }}" style="cursor:pointer">
                            <td><span class="sq-request-link mono">{{ $quotation->purchaseRequest?->code ?: '-' }}</span></td>
                            <td><button class="btn small" type="button" data-open-supplier-quotation="{{ $quotation->id }}" aria-label="Xem chi tiết {{ $quotation->code }}">{{ $quotation->code }}</button></td>
                            <td style="min-width:180px;max-width:300px;white-space:normal;overflow-wrap:anywhere;font-weight:400">{{ $quotation->purchaseRequest?->reason ?: '—' }}</td>
                            <td style="min-width:160px;max-width:260px;white-space:normal;overflow-wrap:anywhere;font-weight:400">
                                @forelse ($quotation->lines as $line)
                                    <div>{{ $line->sku?->name ?: 'Chưa có tên sản phẩm' }}</div>
                                @empty
                                    —
                                @endforelse
                            </td>
                            <td><strong>{{ $quotation->supplier?->name ?: '-' }}</strong></td>
                            <td>{{ $quotation->quoted_at?->format('d/m/Y') }}</td>
                            <td>{{ $quotation->valid_until?->format('d/m/Y') ?: '-' }}</td>
                            <td><strong>{{ number_format((float) $quotation->total_amount, 0, ',', '.') }}</strong></td>
                            <td><span class="badge {{ $tone }}">{{ $label }}</span>@if($order)<span class="row-note">{{ $order->code }}</span>@endif</td>
                            <td>
                                <div class="sq-approval-ticks" aria-label="Tiến độ duyệt {{ $quotation->code }}">
                                    @forelse(($quotation->approval_flow ?? []) as $step)
                                        @php
                                            $approved = !empty($step['decided_at']);
                                            $stepLabel = ($step['name'] ?? 'Người duyệt').(!empty($step['final']) ? ' · Duyệt cuối' : '').($approved ? ' · Đã duyệt' : ' · Chờ duyệt');
                                        @endphp
                                        <span class="sq-approval-tick {{ $approved ? 'is-approved' : 'is-pending' }} {{ !empty($step['final']) ? 'is-final' : '' }}" title="{{ $stepLabel }}" aria-label="{{ $stepLabel }}">{{ $approved ? '✓' : '○' }}</span>
                                    @empty
                                        @if($quotation->selected_at)
                                            <span class="sq-approval-tick is-approved" title="Đã duyệt một người" aria-label="Đã duyệt một người">✓</span>
                                        @else
                                            <span class="sq-approval-tick is-pending" title="Chờ duyệt một người" aria-label="Chờ duyệt một người">○</span>
                                        @endif
                                    @endforelse
                                </div>
                            </td>
                            <td class="actions-cell">
                                <details class="row-action-menu">
                                    <summary aria-label="Mở thao tác">⋮</summary>
                                    <div class="row-action-menu-list">
                                        <button class="btn" type="button" data-open-supplier-quotation="{{ $quotation->id }}">Mở</button>
                                        @if ($quotation->status === 'draft')
                                            <button class="btn primary" type="button" data-select-supplier-quotation="{{ $quotation->id }}">Duyệt báo giá</button>
                                        @endif
                                        @if ($quotation->status === 'selected' && !$order)
                                            <button class="btn primary" type="button" data-create-po-from-supplier-quotation="{{ $quotation->id }}">Tạo đơn mua</button>
                                        @endif
                                    </div>
                                </details>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="11">
                                <div class="empty-state">
                                    <strong>Chưa có báo giá NCC</strong>
                                    <span>Tạo báo giá để so sánh giá mua trước khi lên đơn mua.</span>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                    <tr data-sq-filter-empty hidden><td colspan="11"><div class="empty-state">Không có báo giá phù hợp bộ lọc.</div></td></tr>
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
    'warehouses' => $warehouses->values(),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!}
</script>
@endsection

@push('scripts')
<script src="/assets/js/features/supplier-quotations.js?v=20261008-3"></script>
@endpush
