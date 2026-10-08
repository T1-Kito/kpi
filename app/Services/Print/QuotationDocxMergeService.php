<?php

namespace App\Services\Print;

use App\Models\PrintTemplate;
use App\Models\Contract;
use App\Models\GoodsIssue;
use App\Models\Quotation;
use App\Models\SalesOrder;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use ZipArchive;

class QuotationDocxMergeService
{
    public function merge(Quotation|SalesOrder|GoodsIssue|Contract $quotation, PrintTemplate $template): string
    {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('May chu chua bat PHP ZipArchive de xu ly file Word.');
        }

        if (! $template->file_path || ! Storage::disk('public')->exists($template->file_path)) {
            throw new RuntimeException('Mau Word goc khong ton tai hoac da bi xoa.');
        }

        $source = Storage::disk('public')->path($template->file_path);
        $targetDir = storage_path('app/generated/print-templates');

        if (! is_dir($targetDir) && ! mkdir($targetDir, 0775, true) && ! is_dir($targetDir)) {
            throw new RuntimeException('Khong the tao thu muc xuat file Word.');
        }

        $prefix = $quotation instanceof Contract ? 'contract' : ($quotation instanceof GoodsIssue ? 'goods-issue' : ($quotation instanceof SalesOrder ? 'sales-order' : 'quotation'));
        $target = $targetDir.'/'.$prefix.'-'.$quotation->id.'-'.time().'-'.bin2hex(random_bytes(4)).'.docx';

        if (! copy($source, $target)) {
            throw new RuntimeException('Khong the tao ban sao mau Word.');
        }

        $zip = new ZipArchive();
        if ($zip->open($target) !== true) {
            throw new RuntimeException('Khong the mo file mau Word.');
        }

        $this->loadRelations($quotation);
        $values = $this->mergeValues($quotation);

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = $zip->getNameIndex($index);
            if (! $name || ! $this->shouldMergeXml($name)) {
                continue;
            }

            $xml = $zip->getFromName($name);
            if ($xml === false) {
                continue;
            }

            $xml = $this->repeatItemRows($xml, $quotation);
            $xml = $this->makeMergeFieldValuesRegular($xml);
            $zip->addFromString($name, $this->replaceFields($xml, $values));
        }

        $zip->close();

        return $target;
    }

    private function shouldMergeXml(string $name): bool
    {
        return $name === 'word/document.xml'
            || (bool) preg_match('/^word\/(header|footer|footnotes|endnotes)\d*\.xml$/', $name);
    }

    private function replaceFields(string $xml, array $values): string
    {
        foreach ($values as $key => $value) {
            $replacement = htmlspecialchars((string) $value, ENT_XML1 | ENT_COMPAT, 'UTF-8');
            $xml = preg_replace('/\{\{\s*'.preg_quote($key, '/').'\s*\}\}/u', $replacement, $xml) ?? $xml;
        }

        return $xml;
    }

    /**
     * A merge placeholder is commonly copied from a bold label in Word.
     * Clear bold only on the run that contains a placeholder, leaving labels
     * and headings in the customer's original template untouched.
     */
    private function makeMergeFieldValuesRegular(string $xml): string
    {
        return preg_replace_callback('/<w:r\b[^>]*>.*?<\/w:r>/s', function (array $match): string {
            if (! preg_match('/\{\{\s*[a-zA-Z0-9_]+\s*\}\}/u', $match[0])) {
                return $match[0];
            }

            return preg_replace('/<w:b(?:\s+[^>]*)?\/>|<w:b(?:\s+[^>]*)?>.*?<\/w:b>/s', '', $match[0]) ?? $match[0];
        }, $xml) ?? $xml;
    }

    private function repeatItemRows(string $xml, Quotation|SalesOrder|GoodsIssue|Contract $quotation): string
    {
        $itemKeys = [
            'stt',
            'ma_hang',
            'ten_hang',
            'dvt',
            'so_luong',
            'don_gia',
            'don_gia_ban',
            'vat_dong',
            'vat_percent',
            'thanh_tien',
        ];

        return preg_replace_callback('/<w:tr\b[^>]*>.*?<\/w:tr>/s', function (array $match) use ($quotation, $itemKeys): string {
            $rowXml = $match[0];
            $hasItemField = false;

            foreach ($itemKeys as $key) {
                if (preg_match('/\{\{\s*'.preg_quote($key, '/').'\s*\}\}/u', $rowXml)) {
                    $hasItemField = true;
                    break;
                }
            }

            if (! $hasItemField) {
                return $rowXml;
            }

            $items = $this->documentItems($quotation);
            if ($items->isEmpty()) {
                return $this->replaceFields($rowXml, $this->emptyLineValues());
            }

            return $items
                ->values()
                ->map(fn ($item, int $index): string => $this->replaceFields($rowXml, $this->lineValues($item, $index)))
                ->implode('');
        }, $xml) ?? $xml;
    }

    private function mergeValues(Quotation|SalesOrder|GoodsIssue|Contract $quotation): array
    {
        $this->loadRelations($quotation);

        if ($quotation instanceof GoodsIssue) {
            $order = $quotation->salesOrder;
            $customer = $order?->customer;

            return [
                'ma_chung_tu' => $quotation->code,
                'ngay_chung_tu' => optional($quotation->created_at)->format('d/m/Y H:i'),
                'ma_don_ban' => $order?->code ?? '',
                'ten_khach_hang' => $customer?->name ?? '',
                'ma_khach_hang' => $customer?->code ?? '',
                'nguoi_lien_he' => $quotation->recipient_name ?: ($customer?->contact_name ?? ''),
                'so_dien_thoai' => $quotation->recipient_phone ?: ($customer?->phone ?? ''),
                'dia_chi_giao_hang' => $quotation->recipient_address ?: ($customer?->address ?? ''),
                'bang_dong_hang' => $this->itemsText($quotation),
                'nguoi_lap' => '',
                'trang_thai' => $this->statusText($quotation->status),
                'kho_xuat' => $quotation->warehouse?->name ?? '',
                'ly_do_xuat' => $quotation->issue_reason ?? '',
                'dia_diem_giao_hang' => $quotation->delivery_location ?? '',
                'chung_tu_goc' => $quotation->source_document ?? '',
                'co_tru_ton' => $quotation->affects_stock ? 'Có' : 'Không',
            ];
        }

        if ($quotation instanceof Contract) {
            $customer = $quotation->customer;
            $amount = (float) ($quotation->total_amount ?? 0);
            return [
                'ma_chung_tu' => $quotation->code,
                'ngay_chung_tu' => optional($quotation->created_at)->format('d/m/Y'),
                'ma_hop_dong' => $quotation->code,
                'ten_hop_dong' => $quotation->name,
                'ngay_hop_dong' => optional($quotation->created_at)->format('d/m/Y'),
                'ngay_hieu_luc' => optional($quotation->effective_date)->format('d/m/Y'),
                'ngay_het_han' => optional($quotation->expiry_date)->format('d/m/Y'),
                'ten_khach_hang' => $customer?->name ?? '',
                'ma_khach_hang' => $customer?->code ?? '',
                'nguoi_lien_he' => $customer?->contact_name ?? '',
                'so_dien_thoai' => $customer?->phone ?? '',
                'email' => $customer?->email ?? '',
                'ten_cong_ty' => $customer?->name ?? '',
                'ma_so_thue' => $customer?->tax_code ?? '',
                'dia_chi_cong_ty' => $customer?->billing_address ?: ($customer?->address ?? ''),
                'dia_chi_giao_hang' => $customer?->address ?: ($customer?->billing_address ?? ''),
                'ma_bao_gia' => $quotation->quotation?->code ?? '',
                'ma_don_hang' => $quotation->salesOrder?->code ?? '',
                'gia_tri_hop_dong' => $this->money($amount),
                'gia_tri_bang_chu' => $this->moneyInWords($amount),
                'tong_cong' => $this->money($amount),
                'tong_tien_bang_chu' => $this->moneyInWords($amount),
                'dieu_khoan' => $quotation->note ?? '',
                'moc_thanh_toan' => $quotation->milestones->map(fn ($milestone) => sprintf('%s: %s, hạn %s', $milestone->name, $this->money((float) $milestone->amount), optional($milestone->due_date)->format('d/m/Y') ?: 'chưa xác định'))->implode('; '),
                'bang_dong_hang' => $this->itemsText($quotation),
                'nguoi_lap' => $quotation->owner?->name ?? '',
            ];
        }

        $customer = $quotation->customer;
        $subtotal = (float) ($quotation->subtotal_amount ?? 0);
        $discount = (float) ($quotation->discount_amount ?? 0);
        $tax = (float) ($quotation->tax_amount ?? 0);
        $total = (float) ($quotation->total_amount ?? ($subtotal + $tax - $discount));

        return [
            'ma_chung_tu' => $quotation->code,
            'ngay_chung_tu' => optional($quotation->created_at)->format('d/m/Y H:i'),
            'ma_bao_gia' => $quotation instanceof SalesOrder ? ($quotation->quotation?->code ?? '') : $quotation->code,
            'ngay_bao_gia' => optional($quotation->created_at)->format('d/m/Y H:i'),
            'ma_don_hang' => $quotation instanceof SalesOrder ? $quotation->code : '',
            'ngay_don_hang' => $quotation instanceof SalesOrder ? optional($quotation->created_at)->format('d/m/Y H:i') : '',
            'ten_khach_hang' => $customer?->name ?? '',
            'ma_khach_hang' => $customer?->code ?? '',
            'nguoi_lien_he' => $customer?->contact_name ?? '',
            'so_dien_thoai' => $customer?->phone ?? '',
            'email' => $customer?->email ?? '',
            'ten_cong_ty' => $customer?->name ?? '',
            'ma_so_thue' => $customer?->tax_code ?? '',
            'dia_chi_cong_ty' => $customer?->billing_address ?: ($customer?->address ?? ''),
            'dia_chi_giao_hang' => $customer?->address ?: ($customer?->billing_address ?? ''),
            'bang_dong_hang' => $this->itemsText($quotation),
            'tam_tinh' => $this->money($subtotal),
            'chiet_khau' => $this->money($discount),
            'vat' => $this->money($tax),
            'tong_cong' => $this->money($total),
            'tong_tien_bang_chu' => $this->moneyInWords($total),
            'nguoi_tao' => $quotation->salesOwner?->name ?? '',
            'nguoi_lap' => $quotation->salesOwner?->name ?? '',
            'trang_thai' => $this->statusText($quotation->status),
        ];
    }

    private function lineValues($item, int $index): array
    {
        $sku = $item->sku;

        return [
            'stt' => (string) ($index + 1),
            'ma_hang' => $item->sku_code ?? $sku?->sku_code ?? '',
            'ten_hang' => $item->name ?: ($sku?->name ?? ''),
            'dvt' => $item->unit ?: ($sku?->unit ?? ''),
            'so_luong' => $this->number((float) ($item->quantity ?? 0)),
            'don_gia' => $this->money((float) ($item->unit_price ?? 0)),
            'don_gia_ban' => $this->money((float) ($item->unit_price ?? 0)),
            'vat_dong' => $this->money((float) ($item->vat_amount ?? 0)),
            'vat_percent' => $this->number((float) ($item->vat_rate ?? 0)).'%',
            'thanh_tien' => $this->money((float) ($item->line_total ?? 0)),
        ];
    }

    private function emptyLineValues(): array
    {
        return [
            'stt' => '',
            'ma_hang' => '',
            'ten_hang' => '',
            'dvt' => '',
            'so_luong' => '',
            'don_gia' => '',
            'don_gia_ban' => '',
            'vat_dong' => '',
            'vat_percent' => '',
            'thanh_tien' => '',
        ];
    }

    private function itemsText(Quotation|SalesOrder|GoodsIssue|Contract $quotation): string
    {
        return $this->documentItems($quotation)
            ->values()
            ->map(function ($item, int $index): string {
                $sku = $item->sku;
                $quantity = $this->number((float) ($item->quantity ?? 0));
                $unitPrice = $this->money((float) ($item->unit_price ?? 0));
                $total = $this->money((float) ($item->line_total ?? 0));
                $vat = $this->number((float) ($item->vat_rate ?? 0));

                return sprintf(
                    '%d. %s - %s - SL %s - Don gia %s - VAT %s%% - Thanh tien %s',
                    $index + 1,
                    $item->sku_code ?? $sku?->sku_code ?? '',
                    $item->name ?: ($sku?->name ?? ''),
                    $quantity,
                    $unitPrice,
                    $vat,
                    $total
                );
            })
            ->implode('; ');
    }

    private function loadRelations(Quotation|SalesOrder|GoodsIssue|Contract $document): void
    {
        if ($document instanceof Contract) {
            $document->loadMissing(['customer', 'owner', 'milestones', 'salesOrder.customer', 'salesOrder.items.sku', 'quotation.customer', 'quotation.items.sku']);
            return;
        }
        if ($document instanceof GoodsIssue) {
            $document->loadMissing(['salesOrder.customer', 'items.sku', 'warehouse']);
            return;
        }

        $document->loadMissing(['customer', 'items.sku', 'salesOwner']);
    }

    private function documentItems(Quotation|SalesOrder|GoodsIssue|Contract $document)
    {
        if ($document instanceof Contract) {
            return $document->salesOrder?->items ?? $document->quotation?->items ?? collect();
        }

        return $document->items;
    }

    private function money(float $value): string
    {
        return number_format($value, 0, ',', '.').' đ';
    }

    private function moneyInWords(float $value): string
    {
        $amount = (int) round($value);

        if ($amount === 0) {
            return 'Không đồng';
        }

        return mb_convert_case($this->numberToVietnameseWords($amount), MB_CASE_TITLE, 'UTF-8').' đồng';
    }

    private function numberToVietnameseWords(int $number): string
    {
        $units = ['', ' nghìn', ' triệu', ' tỷ'];
        $parts = [];
        $level = 0;

        while ($number > 0) {
            $group = $number % 1000;
            if ($group > 0) {
                $parts[] = $this->readThreeDigits($group, $number >= 1000).$units[$level];
            }
            $number = intdiv($number, 1000);
            $level++;
        }

        return trim(implode(' ', array_reverse($parts)));
    }

    private function readThreeDigits(int $number, bool $full): string
    {
        $digits = ['không', 'một', 'hai', 'ba', 'bốn', 'năm', 'sáu', 'bảy', 'tám', 'chín'];
        $hundreds = intdiv($number, 100);
        $tens = intdiv($number % 100, 10);
        $ones = $number % 10;
        $words = [];

        if ($hundreds > 0 || $full) {
            $words[] = $digits[$hundreds].' trăm';
        }

        if ($tens > 1) {
            $words[] = $digits[$tens].' mươi';
            if ($ones === 1) {
                $words[] = 'mốt';
            } elseif ($ones === 5) {
                $words[] = 'lăm';
            } elseif ($ones > 0) {
                $words[] = $digits[$ones];
            }
        } elseif ($tens === 1) {
            $words[] = 'mười';
            if ($ones === 5) {
                $words[] = 'lăm';
            } elseif ($ones > 0) {
                $words[] = $digits[$ones];
            }
        } elseif ($ones > 0) {
            if ($hundreds > 0 || $full) {
                $words[] = 'lẻ';
            }
            $words[] = $ones === 5 && ($hundreds > 0 || $full) ? 'năm' : $digits[$ones];
        }

        return trim(implode(' ', $words));
    }

    private function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 3, ',', '.'), '0'), ',');
    }

    private function statusText(?string $status): string
    {
        return match ($status) {
            'draft' => 'Nháp',
            'pending_approval' => 'Chờ duyệt',
            'approved' => 'Đã duyệt',
            'rejected' => 'Từ chối',
            'ready' => 'Sẵn sàng',
            'confirmed' => 'Đã xác nhận',
            default => $status ?: '',
        };
    }
}
