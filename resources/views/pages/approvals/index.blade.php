@extends('layouts.app')

@section('title', 'Duyệt tập trung')
@section('page', 'approvals')
@section('page_title', 'Duyệt tập trung')
@section('page_subtitle', 'Một nơi xử lý báo giá, mua hàng và điều chỉnh KPI đang chờ phê duyệt.')

@php
    $types = [
        'all' => ['Tất cả', 'Tổng hợp'],
        'quotation' => ['Báo giá', 'Biên lợi nhuận'],
        'purchase_request' => ['Yêu cầu mua', 'Nhu cầu mua hàng'],
        'purchase_order' => ['Đơn mua', 'Giá trị mua hàng'],
        'kpi_adjustment' => ['Phiếu điểm', 'Cộng/trừ KPI'],
        'kpi_exception' => ['Ngoại lệ KPI', 'Giải trình KPI'],
    ];
    $labels = [
        'quotation' => 'Báo giá', 'purchase_request' => 'Yêu cầu mua', 'purchase_order' => 'Đơn mua',
        'kpi_adjustment' => 'Phiếu điểm', 'kpi_exception' => 'Ngoại lệ KPI',
    ];
    $statusLabels = ['approved' => 'Đã duyệt', 'rejected' => 'Từ chối'];
@endphp

@section('content')
<div class="approval-center" data-server-rendered="1">
    <nav class="approval-view-tabs" aria-label="Chế độ xem phê duyệt">
        <a class="active" href="{{ route('approvals.index') }}">Tổng quan</a>
        <a href="#approvalQueue">Hàng chờ phê duyệt</a>
        <a href="#approvalHistory">Đã xử lý</a>
    </nav>

    <section class="approval-metric-grid">
        @foreach ([
            ['pending', '▣', 'Đang chờ phê duyệt', 'Yêu cầu', 'blue'],
            ['overdue', '⌛', 'Quá hạn', 'Cần xử lý ngay', 'teal'],
            ['today', '○', 'Hôm nay', 'Yêu cầu chờ xử lý', 'orange'],
            ['processed', '□', 'Đã xử lý', 'Hôm nay', 'blue'],
            ['approved', '✓', 'Phê duyệt', 'Gần đây', 'green'],
            ['rejected', '×', 'Từ chối', 'Gần đây', 'red'],
        ] as [$key, $icon, $label, $note, $tone])
            <article class="approval-metric-card {{ $tone }}">
                <span class="approval-metric-icon" aria-hidden="true">{{ $icon }}</span>
                <div><small>{{ $label }}</small><strong>{{ $metrics[$key] }}</strong><span>{{ $note }}</span></div>
            </article>
        @endforeach
    </section>

    <section class="module-panel approval-queue-panel" id="approvalQueue">
        <div class="panel-head">
            <div>
                <h2>Hàng chờ phê duyệt</h2>
                <span>Ưu tiên hồ sơ rủi ro cao và hồ sơ chờ lâu trước.</span>
            </div>
            <span class="badge info">{{ $pending->count() }} hồ sơ</span>
        </div>

        <form class="approval-filter" method="GET" action="{{ route('approvals.index') }}">
            <div class="search-control">
                <span aria-hidden="true">⌕</span>
                <input name="q" value="{{ $keyword }}" placeholder="Tìm mã hồ sơ, người tạo hoặc nội dung...">
            </div>
            <select name="type">
                @foreach ($types as $key => $meta)
                    <option value="{{ $key }}" @selected($type === $key)>{{ $meta[0] }}</option>
                @endforeach
            </select>
            <button class="btn secondary" type="submit">Lọc</button>
            @if ($keyword !== '' || $type !== 'all')
                <a class="btn" href="{{ route('approvals.index') }}">Xóa lọc</a>
            @endif
        </form>

        @if ($pending->isEmpty())
            <div class="empty-state approval-empty">
                <strong>Không có hồ sơ đang chờ</strong>
                <span>Hàng chờ hiện tại đã được xử lý hoặc không khớp bộ lọc.</span>
            </div>
        @else
            <div class="approval-list">
                @foreach ($pending as $row)
                    @php
                        $ageHours = (int) ($row['created_at'] ? $row['created_at']->diffInHours(now()) : 0);
                        $ageLabel = $ageHours >= 24 ? 'Quá hạn '.intdiv($ageHours, 24).' ngày' : 'Còn '.max(1, 24 - $ageHours).' giờ';
                        $ageTone = $ageHours >= 24 ? 'danger' : ($ageHours >= 18 ? 'warning' : 'info');
                    @endphp
                    <article class="approval-item {{ $row['risk'] === 'high' ? 'risk' : '' }}">
                        <div class="approval-kind {{ $row['type'] }}" aria-hidden="true"></div>
                        <div class="approval-body">
                            <div class="approval-title-line">
                                <div>
                                    <span class="approval-type">{{ $labels[$row['type']] ?? $row['type'] }}</span>
                                    <h3>{{ $row['title'] }}</h3>
                                </div>
                                <span class="badge {{ $ageTone }}">{{ $ageLabel }}</span>
                            </div>
                            <div class="approval-code-line">
                                <a href="{{ $row['path'] }}">{{ $row['code'] }}</a>
                                <span>{{ $row['owner'] }}</span>
                                <span>{{ $row['created_at']?->format('H:i d/m/Y') }}</span>
                            </div>
                            <p>{{ $row['summary'] }}</p>
                            <small>{{ $row['detail'] }}</small>
                        </div>
                        <div class="approval-actions">
                            <a class="btn small" href="{{ $row['path'] }}">Mở hồ sơ</a>
                            <button class="btn small danger" type="button" data-approval-decision data-type="{{ $row['type'] }}" data-id="{{ $row['id'] }}" data-code="{{ $row['code'] }}" data-status="rejected">Từ chối</button>
                            <button class="btn small primary" type="button" data-approval-decision data-type="{{ $row['type'] }}" data-id="{{ $row['id'] }}" data-code="{{ $row['code'] }}" data-status="approved">Duyệt</button>
                        </div>
                    </article>
                @endforeach
            </div>
        @endif
    </section>

    <section class="module-panel approval-history-panel" id="approvalHistory">
        <div class="panel-head"><div><h2>Quyết định gần đây</h2><span>Phục vụ kiểm tra nhanh trước khi mở nhật ký hệ thống.</span></div><a class="btn small" href="/audit-logs">Mở nhật ký</a></div>
        <div class="approval-history-list">
            @forelse ($recent as $row)
                <div class="approval-history-row">
                    <span class="approval-history-mark {{ $row['status'] }}"></span>
                    <div><strong>{{ $row['code'] }}</strong><small>{{ $labels[$row['type']] ?? $row['type'] }} · {{ $row['owner'] }}</small></div>
                    <span class="badge {{ $row['status'] === 'approved' ? 'success' : 'danger' }}">{{ $statusLabels[$row['status']] ?? $row['status'] }}</span>
                    <time>{{ $row['decided_at']?->format('H:i d/m/Y') }}</time>
                    <a href="{{ $row['path'] }}">Xem</a>
                </div>
            @empty
                <div class="empty-state"><strong>Chưa có quyết định gần đây</strong></div>
            @endforelse
        </div>
    </section>
</div>
@endsection

@push('scripts')
<script src="/assets/js/features/approvals.js?v=20260619-1"></script>
@endpush
