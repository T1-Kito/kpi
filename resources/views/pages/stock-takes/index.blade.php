@extends('layouts.app')

@section('title', 'Kiểm kê kho')
@section('page', 'stock-takes')
@section('page_title', 'Kiểm kê kho')
@section('page_subtitle', 'Ghi nhận số lượng thực tế, so sánh lệch tồn và xác nhận điều chỉnh kho.')

@section('page_actions')
    <button class="btn primary" type="button" data-create-stock-take>Tạo phiếu kiểm kê</button>
@endsection

@section('content')
<div id="stockTakesRoot" class="stock-takes-page">
    <section class="module-panel">
        <div class="panel-head">
            <div>
                <h2>Danh sách phiếu kiểm kê</h2>
                <span>Phiếu nháp chưa ảnh hưởng tồn kho. Chỉ khi xác nhận mới tạo giao dịch điều chỉnh.</span>
            </div>
            <span class="badge info">{{ $stockTakes->count() }} phiếu</span>
        </div>

        <div class="table-wrap">
            <table class="list-table">
                <thead>
                    <tr>
                        <th>Mã phiếu</th>
                        <th>Kho</th>
                        <th>Ngày kiểm kê</th>
                        <th>Số dòng</th>
                        <th>Lệch tồn</th>
                        <th>Trạng thái</th>
                        <th>Người xác nhận</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($stockTakes as $stockTake)
                        @php
                            $diff = $stockTake->lines->sum(fn ($line) => (float) $line->difference_quantity);
                            $statusLabel = $stockTake->status === 'confirmed' ? 'Đã xác nhận' : 'Nháp';
                            $statusTone = $stockTake->status === 'confirmed' ? 'success' : 'info';
                        @endphp
                        <tr>
                            <td><span class="record-code-chip">{{ $stockTake->code }}</span></td>
                            <td>{{ $stockTake->warehouse?->name ?: '-' }}</td>
                            <td>{{ $stockTake->counted_at?->format('d/m/Y') }}</td>
                            <td>{{ $stockTake->lines_count }}</td>
                            <td>
                                <strong class="{{ $diff < 0 ? 'text-danger' : ($diff > 0 ? 'text-success' : '') }}">
                                    {{ $diff > 0 ? '+' : '' }}{{ number_format($diff, 2, ',', '.') }}
                                </strong>
                            </td>
                            <td><span class="badge {{ $statusTone }}">{{ $statusLabel }}</span></td>
                            <td>{{ $stockTake->confirmer?->name ?: '-' }}</td>
                            <td class="actions-cell">
                                <details class="row-action-menu">
                                    <summary aria-label="Mở thao tác">⋮</summary>
                                    <div class="row-action-menu-list">
                                        <button class="btn" type="button" data-open-stock-take="{{ $stockTake->id }}">Mở</button>
                                        @if ($stockTake->status === 'draft')
                                            <button class="btn primary" type="button" data-confirm-stock-take="{{ $stockTake->id }}">Xác nhận</button>
                                        @endif
                                    </div>
                                </details>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8">
                                <div class="empty-state">
                                    <strong>Chưa có phiếu kiểm kê</strong>
                                    <span>Tạo phiếu để đối chiếu tồn thực tế với tồn hệ thống.</span>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</div>

<script id="stockTakesData" type="application/json">
{!! json_encode([
    'stockTakes' => $stockTakes->values(),
    'warehouses' => $warehouses->values(),
    'skus' => $skus->values(),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!}
</script>
@endsection

@push('scripts')
<script src="/assets/js/features/stock-takes.js?v=20260619-1"></script>
@endpush
