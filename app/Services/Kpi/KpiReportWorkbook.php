<?php

namespace App\Services\Kpi;

use RuntimeException;
use ZipArchive;

/** Runtime XLSX export. Text cells are inline strings, never executable formulas. */
class KpiReportWorkbook
{
    public function write(array $report): string
    {
        $names = array_column($report['rows'], 'name', 'user_id');
        $meta = $report['meta'];
        $summary = [['BÁO CÁO NHÂN VIÊN THEO KỲ'], ['Từ ngày', $meta['period_start'], 'Đến ngày', $meta['period_end']],
            ['Thời điểm xem', $meta['as_of']], [$meta['task_basis']], [$meta['points_basis']],
            ['Nhân viên', 'Phòng ban', 'Chức vụ', 'Tạo trong kỳ', 'Đã hoàn thành', 'Đang quá hạn', 'Điểm cộng', 'Điểm trừ', 'Điểm ròng', 'Phiếu chờ duyệt', 'Chỉ tiêu được phân', 'Điểm KPI /100']];
        foreach ($report['rows'] as $row) {
            $summary[] = [$row['name'], $row['department'] ?? 'Chưa gán', $row['position'] ?? 'Chưa gán', $row['tasks_created'], $row['tasks_completed'], $row['tasks_overdue'], $row['bonus_points'], $row['penalty_points'], $row['net_points'], $row['pending_adjustments'], $row['kpi_indicators'] ?? 0, $row['kpi_score'] ?? 'Chưa phân'];
        }
        $ledger = [['PHIẾU ĐIỂM TRONG KỲ'], ['Từ ngày', $meta['period_start'], 'Đến ngày', $meta['period_end']], [],
            ['Mã phiếu', 'Nhân viên', 'Loại', 'Điểm', 'Trạng thái', 'Lý do', 'Nguồn', 'Mã nguồn']];
        foreach ($report['adjustments'] as $row) {
            $ledger[] = ['KPA-'.$row['id'], $names[$row['user_id']] ?? '', $row['adjustment_type'] === 'bonus' ? 'Cộng' : 'Trừ', (float) $row['points'], $row['status'], $row['reason'], $row['source_type'] ?? '', (string) ($row['source_id'] ?? '')];
        }
        $tasks = [['CÔNG VIỆC TẠO TRONG KỲ'], [$meta['task_basis']], [], ['Mã', 'Tiêu đề', 'Nhân viên', 'Trạng thái', 'Hạn xử lý', 'Ngày tạo']];
        foreach ($report['tasks'] as $row) {
            $tasks[] = [$row['code'], $row['title'], $names[$row['assignee_id']] ?? '', $row['status'], $row['due_at'] ?? '', $row['created_at']];
        }
        $kpis = [['KPI ĐƯỢC PHÂN TRONG KỲ'], ['Từ ngày', $meta['period_start'], 'Đến ngày', $meta['period_end']], ['Điểm chỉ tiêu theo nguồn tự động, tổng KPI theo trọng số. Phiếu cộng/trừ hiển thị riêng.'],
            ['Nhân viên', 'Mã chỉ tiêu', 'Chỉ tiêu', 'Nguồn', 'Mục tiêu', 'Thực tế', 'Đơn vị', 'Trọng số', 'Điểm /100', 'Trạng thái']];
        foreach ($report['kpis'] ?? [] as $row) {
            $kpis[] = [$row['employee_name'], $row['code'], $row['name'], $row['metric_label'], $row['target'], $row['actual'], $row['unit'], $row['weight'], $row['score'], $row['status']];
        }
        $sheets = [['Tổng hợp', $summary, 6], ['KPI được phân', $kpis, 4], ['Phiếu điểm', $ledger, 4], ['Công việc', $tasks, 4]];
        $path = tempnam(sys_get_temp_dir(), 'kpi-xlsx-');
        $zip = new ZipArchive;
        try {
            if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Không thể tạo Excel.');
            }
            $types = '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
            $book = '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>';
            $rels = '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
            foreach ($sheets as $index => [$name, $rows, $header]) {
                $id = $index + 1;
                $types .= '<Override PartName="/xl/worksheets/sheet'.$id.'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
                $book .= '<sheet name="'.$name.'" sheetId="'.$id.'" r:id="rId'.$id.'"/>';
                $rels .= '<Relationship Id="rId'.$id.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.$id.'.xml"/>';
                $zip->addFromString('xl/worksheets/sheet'.$id.'.xml', $this->sheet($rows, $header));
            }
            $zip->addFromString('[Content_Types].xml', $types.'</Types>');
            $zip->addFromString('_rels/.rels', '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
            $zip->addFromString('xl/workbook.xml', $book.'</sheets></workbook>');
            $zip->addFromString('xl/_rels/workbook.xml.rels', $rels.'<Relationship Id="rId5" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
            $zip->addFromString('xl/styles.xml', '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font></fonts><fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF2563EB"/><bgColor indexed="64"/></patternFill></fill></fills><borders count="1"><border/></borders><cellStyleXfs count="1"><xf/></cellStyleXfs><cellXfs count="3"><xf fontId="0" fillId="0" borderId="0" xfId="0"><alignment vertical="top" wrapText="1"/></xf><xf fontId="1" fillId="2" borderId="0" xfId="0" applyFill="1" applyFont="1"><alignment wrapText="1"/></xf><xf fontId="0" fillId="0" borderId="0" xfId="0" numFmtId="4" applyNumberFormat="1"/></cellXfs></styleSheet>');
            if (! $zip->close()) {
                throw new RuntimeException('Không thể hoàn tất Excel.');
            }

            return $path;
        } catch (\Throwable $e) {
            if (is_file($path)) {
                unlink($path);
            }
            throw $e;
        }
    }

    private function sheet(array $rows, int $header): string
    {
        $xml = '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetViews><sheetView workbookViewId="0"><pane ySplit="'.$header.'" topLeftCell="A'.($header + 1).'" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews><cols><col min="1" max="3" width="28" customWidth="1"/><col min="4" max="10" width="20" customWidth="1"/></cols><sheetData>';
        foreach ($rows as $index => $row) {
            $number = $index + 1;
            $xml .= '<row r="'.$number.'">';
            foreach ($row as $column => $value) {
                $ref = chr(65 + $column).$number;
                if (is_int($value) || is_float($value)) {
                    $xml .= '<c r="'.$ref.'" s="2"><v>'.$value.'</v></c>';
                } else {
                    $clean = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', (string) $value);
                    $xml .= '<c r="'.$ref.'" t="inlineStr" s="'.($number === $header ? 1 : 0).'"><is><t xml:space="preserve">'.htmlspecialchars($clean, ENT_XML1 | ENT_QUOTES, 'UTF-8').'</t></is></c>';
                }
            }
            $xml .= '</row>';
        }
        $lastColumn = chr(64 + count($rows[$header - 1]));

        return $xml.'</sheetData><autoFilter ref="A'.$header.':'.$lastColumn.max($header, count($rows)).'"/></worksheet>';
    }
}
