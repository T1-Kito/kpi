@extends('layouts.app')

@section('title', 'Sổ điểm KPI')
@section('page', 'kpi-adjustments')
@section('page_title', 'Sổ điểm nhân viên')
@section('page_subtitle', 'Quản lý cộng điểm, trừ điểm, duyệt điều chỉnh và truy vết lý do KPI theo từng kỳ.')

@section('content')
@php
    $periodOptions = [
        'day' => 'Theo ngày',
        'week' => 'Theo tuần',
        'month' => 'Theo tháng',
        'quarter' => 'Theo quý',
        'year' => 'Theo năm',
    ];
    $statusOptions = [
        '' => 'Tất cả trạng thái',
        'pending' => 'Chờ duyệt',
        'approved' => 'Đã duyệt',
        'rejected' => 'Từ chối',
    ];
    $typeLabels = ['bonus' => 'Cộng điểm', 'penalty' => 'Trừ điểm'];
    $statusLabels = ['pending' => 'Chờ duyệt', 'approved' => 'Đã duyệt', 'rejected' => 'Từ chối'];
    $statusTone = ['pending' => 'warning', 'approved' => 'success', 'rejected' => 'danger'];
    $adjustmentDetails = $adjustments->mapWithKeys(fn ($adjustment) => [
        $adjustment->id => [
            'id' => $adjustment->id,
            'code' => 'KPI-ADJ-'.$adjustment->id,
            'employee' => $adjustment->user?->name ?? '-',
            'email' => $adjustment->user?->email ?? '',
            'department' => $adjustment->user?->department?->name ?? 'Chưa gán phòng ban',
            'period' => ($periodOptions[$adjustment->period_type] ?? $adjustment->period_type).' · '.$adjustment->period_start?->format('d/m/Y').' - '.$adjustment->period_end?->format('d/m/Y'),
            'type' => $typeLabels[$adjustment->adjustment_type] ?? $adjustment->adjustment_type,
            'type_raw' => $adjustment->adjustment_type,
            'points' => (float) $adjustment->points,
            'reason' => $adjustment->reason,
            'reason_code' => $adjustment->reason_code,
            'evidence_url' => $adjustment->evidence_url,
            'creator' => $adjustment->creator?->name ?? '-',
            'reviewer' => $adjustment->reviewer?->name ?? '-',
            'reviewed_at' => $adjustment->reviewed_at?->format('H:i d/m/Y'),
            'review_note' => $adjustment->review_note,
            'status' => $adjustment->status,
            'status_label' => $statusLabels[$adjustment->status] ?? $adjustment->status,
        ],
    ]);
@endphp

<div id="kpiAdjustmentsRoot" class="kpi-ledger-page" data-server-rendered="1">
    <script type="application/json" id="kpiAdjustmentRecords">@json($adjustmentDetails)</script>

    <section class="module-panel">
        <div class="panel-head">
            <div>
                <h2>Bảng điểm điều chỉnh</h2>
                <span>Cộng/trừ điểm có lý do, trạng thái duyệt và người phê duyệt để tránh sửa KPI trực tiếp.</span>
            </div>
            <button class="btn primary" type="button" data-create-kpi-adjustment>Tạo phiếu điểm</button>
        </div>

        <form class="kpi-ledger-toolbar" method="GET" action="{{ route('kpi-adjustments.index') }}">
            <label>
                <span>Kỳ tính</span>
                <select name="period_type" onchange="this.form.submit()">
                    @foreach ($periodOptions as $value => $label)
                        <option value="{{ $value }}" @selected($periodType === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </label>
            <label>
                <span>Trạng thái</span>
                <select name="status" onchange="this.form.submit()">
                    @foreach ($statusOptions as $value => $label)
                        <option value="{{ $value }}" @selected($status === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </label>
        </form>

        @if ($summary->isNotEmpty())
            <div class="kpi-ledger-summary">
                @foreach ($summary->take(6) as $row)
                    <article class="kpi-ledger-score-card {{ $row['net_points'] < 0 ? 'negative' : 'positive' }}">
                        <span>{{ $row['user']?->department?->name ?? 'Chưa gán phòng ban' }}</span>
                        <strong>
                            {{ $row['user']?->name ?? 'Nhân viên' }}
                            <b>{{ $row['net_points'] > 0 ? '+' : '' }}{{ number_format($row['net_points'], 2, ',', '.') }} điểm</b>
                        </strong>
                        <small>
                            Cộng {{ number_format($row['bonus_points'], 2, ',', '.') }}
                            · Trừ {{ number_format($row['penalty_points'], 2, ',', '.') }}
                            · {{ $row['adjustment_count'] }} phiếu
                        </small>
                    </article>
                @endforeach
            </div>
        @else
            <div class="kpi-ledger-summary empty">
                <strong>Chưa có điểm đã duyệt trong kỳ này</strong>
                <span>Khi phiếu cộng/trừ điểm được duyệt, tổng điểm từng nhân viên sẽ hiển thị ở đây.</span>
            </div>
        @endif

        @if ($adjustments->isNotEmpty())
            <div class="table-wrap">
                <table class="list-table">
                    <thead>
                        <tr>
                            <th>Nhân viên</th>
                            <th>Kỳ tính</th>
                            <th>Loại</th>
                            <th>Điểm</th>
                            <th>Lý do</th>
                            <th>Người tạo</th>
                            <th>Trạng thái</th>
                            <th>Người duyệt</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($adjustments as $adjustment)
                            <tr id="kpi-adjustment-{{ $adjustment->id }}">
                                <td>
                                    <strong>{{ $adjustment->user?->name ?? '-' }}</strong>
                                    <small>{{ $adjustment->user?->email }}</small>
                                </td>
                                <td>
                                    {{ $periodOptions[$adjustment->period_type] ?? $adjustment->period_type }}
                                    <small>{{ $adjustment->period_start?->format('d/m/Y') }} - {{ $adjustment->period_end?->format('d/m/Y') }}</small>
                                </td>
                                <td>
                                    <span class="badge {{ $adjustment->adjustment_type === 'bonus' ? 'success' : 'danger' }}">
                                        {{ $typeLabels[$adjustment->adjustment_type] ?? $adjustment->adjustment_type }}
                                    </span>
                                </td>
                                <td>
                                    <strong class="{{ $adjustment->adjustment_type === 'penalty' ? 'text-danger' : 'text-success' }}">
                                        {{ $adjustment->adjustment_type === 'penalty' ? '-' : '+' }}{{ number_format((float) $adjustment->points, 2, ',', '.') }}
                                    </strong>
                                </td>
                                <td class="kpi-ledger-reason">{{ $adjustment->reason }}</td>
                                <td>{{ $adjustment->creator?->name ?? '-' }}</td>
                                <td>
                                    <span class="badge {{ $statusTone[$adjustment->status] ?? 'info' }}">
                                        {{ $statusLabels[$adjustment->status] ?? $adjustment->status }}
                                    </span>
                                </td>
                                <td>
                                    <span class="muted">{{ $adjustment->reviewer?->name ?? '-' }}</span>
                                </td>
                                <td class="row-actions-cell actions-cell">
                                    <details class="row-action-menu">
                                        <summary aria-label="Thao tác">⋮</summary>
                                        <div class="row-action-menu-list">
                                            <button class="btn small" type="button" data-open-kpi-adjustment="{{ $adjustment->id }}">Mở</button>
                                            @if ($adjustment->status === 'pending')
                                                <button class="btn small primary" type="button" data-review-kpi-adjustment="{{ $adjustment->id }}" data-status="approved">Duyệt</button>
                                                <button class="btn small danger" type="button" data-review-kpi-adjustment="{{ $adjustment->id }}" data-status="rejected">Từ chối</button>
                                            @endif
                                        </div>
                                    </details>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <div class="empty-state">
                <strong>Chưa có phiếu điểm</strong>
                <span>Tạo phiếu điểm để ghi nhận thưởng/phạt KPI có kiểm soát.</span>
            </div>
        @endif
    </section>
</div>
@endsection

@push('scripts')
<script src="/assets/js/features/kpi-adjustments.js?v=20260619-1"></script>
@endpush
