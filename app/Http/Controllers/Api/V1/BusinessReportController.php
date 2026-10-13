<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Reports\BusinessReport;
use App\Services\Kpi\KpiReportWorkbook;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BusinessReportController extends Controller
{
    public function index(Request $request, BusinessReport $reports)
    {
        $report = $reports->build($request->user(), $this->filters($request));
        $page = max(1, (int) $request->query('page', 1));
        $report['rows'] = array_slice($report['rows'], ($page - 1) * 50, 50);
        $report['meta']['page'] = $page;
        $report['meta']['page_size'] = 50;
        return response()->json(['data' => $report]);
    }

    public function export(Request $request, BusinessReport $reports, KpiReportWorkbook $workbooks)
    {
        $filters = $this->filters($request);
        abort_unless($request->user()->hasPermission(BusinessReport::exportPermission($filters['type'])), 403, 'Bạn có thể xem báo cáo nhưng chưa được cấp quyền xuất Excel.');
        $report = $reports->build($request->user(), $filters);
        $summary = [[$report['title']], ['Thời điểm xuất (giờ Việt Nam)', now()->setTimezone('Asia/Ho_Chi_Minh')],
            [$report['meta']['basis']], ['Số dòng', $report['meta']['count']]];
        $labels = ['type' => 'Loại', 'from' => 'Từ ngày', 'to' => 'Đến ngày', 'q' => 'Tìm kiếm', 'status' => 'Trạng thái', 'user_id' => 'ID nhân viên', 'customer_id' => 'ID khách hàng', 'supplier_id' => 'ID nhà cung cấp', 'warehouse_id' => 'ID kho', 'sku_id' => 'ID mã hàng', 'debt_status' => 'Tình trạng nợ'];
        foreach ($filters as $key => $value) if ($value !== null && $value !== '') $summary[] = [$labels[$key] ?? $key, in_array($key, ['from', 'to']) ? $workbooks->dateCell($value) : (string) $value];
        $summary[] = ['Chỉ tiêu', 'Tổng'];
        $header = count($summary);
        foreach ($report['totals'] as $column => $value) $summary[] = [$report['columns'][$column], $value];
        $rows = $report['rows'];
        if (in_array($filters['type'], ['invoices', 'receivables'])) foreach ($rows as &$row) {
            $row[1] = $workbooks->dateCell($row[1]);
            $row[8] = $workbooks->dateCell($row[8]);
        }
        unset($row);
        $details = [[$report['title']], [$report['meta']['basis']], [], $report['columns'], ...$rows];
        $path = $workbooks->writeSheets([['Tổng hợp', $summary, $header], ['Chi tiết', $details, 4]]);
        try {
            app(\App\Support\AuditLogger::class)->record('business_report', null, 'export_business_report', $request->user(), null, [
                'filters' => $filters, 'row_count' => $report['meta']['count'], 'scope' => $report['meta']['scope'], 'columns' => $report['columns'],
            ], $request);
        } catch (\Throwable $error) {
            @unlink($path);
            throw $error;
        }
        $suffix = in_array($filters['type'], ['inventory', 'receivables']) ? now()->format('Y-m-d') : $filters['from'].'_'.$filters['to'];
        return response()->download($path, 'Bao-cao-'.$filters['type'].'-'.$suffix.'.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'Cache-Control' => 'private, no-store'])->deleteFileAfterSend(true);
    }

    private function filters(Request $request): array
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(array_keys(BusinessReport::TYPES))],
            'from' => ['required_unless:type,inventory,receivables', 'nullable', 'date_format:Y-m-d'],
            'to' => ['required_unless:type,inventory,receivables', 'nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'q' => ['nullable', 'string', 'max:150'], 'status' => ['nullable', 'string', 'max:40'],
            'customer_id' => ['nullable', 'integer', 'min:1'], 'supplier_id' => ['nullable', 'integer', 'min:1'],
            'warehouse_id' => ['nullable', 'integer', 'min:1'], 'sku_id' => ['nullable', 'integer', 'min:1'],
            'user_id' => ['nullable', 'integer', 'min:1'], 'debt_status' => ['nullable', Rule::in(['overdue', 'not_due'])],
        ]);
        $allowed = match ($data['type']) {
            'sales', 'invoices' => ['from', 'to', 'q', 'status', 'customer_id', 'user_id'],
            'purchase' => ['from', 'to', 'q', 'status', 'supplier_id'],
            'inventory' => ['q', 'warehouse_id', 'sku_id'],
            'transactions' => ['from', 'to', 'q', 'status', 'warehouse_id', 'sku_id'],
            'receivables' => ['q', 'customer_id', 'user_id', 'debt_status'],
        };
        foreach ($data as $key => $value) if ($key !== 'type' && $value !== null && $value !== '' && ! in_array($key, $allowed)) {
            throw \Illuminate\Validation\ValidationException::withMessages([$key => 'Bộ lọc không áp dụng cho loại báo cáo này.']);
        }
        return array_intersect_key($data, array_flip(['type', ...$allowed]));
    }
}
