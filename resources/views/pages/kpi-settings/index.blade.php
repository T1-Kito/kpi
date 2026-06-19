@extends('layouts.app')

@section('title', 'Cấu hình KPI')
@section('page', 'kpi-settings')
@section('page_title', 'Cấu hình KPI')
@section('page_subtitle', 'Thiết lập chỉ tiêu, mục tiêu theo kỳ và khóa kỳ KPI trước khi chốt điểm.')

@section('page_actions')
    <button class="btn secondary" type="button" data-create-kpi-target>Thêm mục tiêu</button>
    <button class="btn primary" type="button" data-create-kpi-definition>Tạo chỉ tiêu</button>
@endsection

@section('content')
<div id="kpiSettingsRoot" class="kpi-settings-page">
    <section class="kpi-settings-summary">
        <article>
            <span>Chỉ tiêu đang dùng</span>
            <strong>{{ $definitions->where('status', 'active')->count() }}</strong>
            <small>Định nghĩa KPI</small>
        </article>
        <article>
            <span>Mục tiêu mở</span>
            <strong>{{ $targets->whereNull('locked_at')->count() }}</strong>
            <small>Có thể chỉnh sửa</small>
        </article>
        <article>
            <span>Kỳ đã khóa</span>
            <strong>{{ $targets->whereNotNull('locked_at')->count() }}</strong>
            <small>Đã chốt KPI</small>
        </article>
    </section>

    <section class="module-panel">
        <div class="panel-head">
            <div>
                <h2>Danh mục chỉ tiêu</h2>
                <span>Admin khai báo công thức, đơn vị, trọng số và hướng tính điểm cho từng KPI.</span>
            </div>
            <button class="btn primary" type="button" data-create-kpi-definition>Tạo chỉ tiêu</button>
        </div>

        <div class="table-wrap">
            <table class="list-table">
                <thead>
                    <tr>
                        <th>Mã</th>
                        <th>Tên chỉ tiêu</th>
                        <th>Nguồn dữ liệu</th>
                        <th>Công thức</th>
                        <th>Đơn vị</th>
                        <th>Trọng số</th>
                        <th>Trạng thái</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($definitions as $definition)
                        <tr>
                            <td><span class="record-code-chip">{{ $definition->code }}</span></td>
                            <td><strong>{{ $definition->name }}</strong></td>
                            <td>{{ $definition->source_type ?: 'Thủ công' }}</td>
                            <td>{{ $definition->formula ?: '-' }}</td>
                            <td>{{ $definition->unit ?: '-' }}</td>
                            <td>{{ number_format((float) $definition->weight, 2, ',', '.') }}</td>
                            <td>
                                <span class="badge {{ $definition->status === 'active' ? 'success' : 'inactive' }}">
                                    {{ $definition->status === 'active' ? 'Đang dùng' : 'Tạm ngưng' }}
                                </span>
                            </td>
                            <td class="actions-cell">
                                <details class="row-action-menu">
                                    <summary aria-label="Mở thao tác">⋮</summary>
                                    <div class="row-action-menu-list">
                                        <button class="btn" type="button" data-edit-kpi-definition="{{ $definition->id }}">Sửa</button>
                                        <button class="btn primary" type="button" data-create-kpi-target="{{ $definition->id }}">Thêm mục tiêu</button>
                                    </div>
                                </details>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8">
                                <div class="empty-state">
                                    <strong>Chưa có chỉ tiêu KPI</strong>
                                    <span>Tạo chỉ tiêu để hệ thống có cơ sở tính điểm và giao mục tiêu.</span>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <section class="module-panel">
        <div class="panel-head">
            <div>
                <h2>Mục tiêu theo kỳ</h2>
                <span>Thiết lập target tháng/quý/năm, nhập thực tế và khóa kỳ sau khi đã rà soát.</span>
            </div>
            <button class="btn secondary" type="button" data-create-kpi-target>Thêm mục tiêu</button>
        </div>

        <div class="table-wrap">
            <table class="list-table">
                <thead>
                    <tr>
                        <th>Chỉ tiêu</th>
                        <th>Kỳ tính</th>
                        <th>Mục tiêu</th>
                        <th>Thực tế</th>
                        <th>Điểm</th>
                        <th>Trạng thái</th>
                        <th>Khóa lúc</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($targets as $target)
                        <tr>
                            <td>
                                <strong>{{ $target->definition?->name ?: 'Chỉ tiêu đã xóa' }}</strong>
                                <small>{{ $target->definition?->code }}</small>
                            </td>
                            <td>{{ ucfirst($target->period_type) }} · {{ $target->period_start?->format('d/m/Y') }} - {{ $target->period_end?->format('d/m/Y') }}</td>
                            <td>{{ number_format((float) $target->target_value, 2, ',', '.') }}</td>
                            <td>{{ number_format((float) $target->actual_value, 2, ',', '.') }}</td>
                            <td><strong>{{ number_format((float) $target->score, 2, ',', '.') }}/100</strong></td>
                            <td>
                                @php
                                    $targetStatus = $target->locked_at ? 'locked' : $target->status;
                                    $targetLabel = ['draft' => 'Nháp', 'active' => 'Đang theo dõi', 'locked' => 'Đã khóa'][$targetStatus] ?? $targetStatus;
                                    $targetTone = ['draft' => 'info', 'active' => 'success', 'locked' => 'inactive'][$targetStatus] ?? 'info';
                                @endphp
                                <span class="badge {{ $targetTone }}">{{ $targetLabel }}</span>
                            </td>
                            <td>{{ $target->locked_at?->format('H:i d/m/Y') ?: '-' }}</td>
                            <td class="actions-cell">
                                <details class="row-action-menu">
                                    <summary aria-label="Mở thao tác">⋮</summary>
                                    <div class="row-action-menu-list">
                                        <button class="btn" type="button" data-edit-kpi-target="{{ $target->id }}">Sửa</button>
                                        @if (! $target->locked_at)
                                            <button class="btn primary" type="button" data-lock-kpi-target="{{ $target->id }}">Khóa kỳ</button>
                                        @endif
                                    </div>
                                </details>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8">
                                <div class="empty-state">
                                    <strong>Chưa có mục tiêu KPI</strong>
                                    <span>Thêm mục tiêu theo tháng/quý/năm để theo dõi điểm chuẩn.</span>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</div>

<script id="kpiSettingsData" type="application/json">
{!! json_encode([
    'definitions' => $definitions->values(),
    'activeDefinitions' => $activeDefinitions,
    'targets' => $targets->values(),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!}
</script>
@endsection

@push('scripts')
<script src="/assets/js/features/kpi-settings.js?v=20260619-3"></script>
@endpush
