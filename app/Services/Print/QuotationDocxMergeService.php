<?php

namespace App\Services\Print;

use App\Models\PrintTemplate;
use App\Models\Quotation;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use ZipArchive;

class QuotationDocxMergeService
{
    public function merge(Quotation $quotation, PrintTemplate $template): string
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

        $target = $targetDir.'/quotation-'.$quotation->id.'-'.time().'-'.bin2hex(random_bytes(4)).'.docx';

        if (! copy($source, $target)) {
            throw new RuntimeException('Khong the tao ban sao mau Word.');
        }

        $zip = new ZipArchive();
        if ($zip->open($target) !== true) {
            throw new RuntimeException('Khong the mo file mau Word.');
        }

        $quotation->loadMissing(['customer', 'items.sku', 'salesOwner']);
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

    private function repeatItemRows(string $xml, Quotation $quotation): string
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

            if ($quotation->items->isEmpty()) {
                return $this->replaceFields($rowXml, $this->emptyLineValues());
            }

            return $quotation->items
                ->values()
                ->map(fn ($item, int $index): string => $this->replaceFields($rowXml, $this->lineValues($item, $index)))
                ->implode('');
        }, $xml) ?? $xml;
    }

    private function mergeValues(Quotation $quotation): array
    {
        $quotation->loadMissing(['customer', 'items.sku', 'salesOwner']);

        $customer = $quotation->customer;
        $subtotal = (float) ($quotation->subtotal_amount ?? 0);
        $discount = (float) ($quotation->discount_amount ?? 0);
        $tax = (float) ($quotation->tax_amount ?? 0);
        $total = (float) ($quotation->total_amount ?? ($subtotal + $tax - $discount));

        return [
            'ma_bao_gia' => $quotation->code,
            'ngay_bao_gia' => optional($quotation->created_at)->format('d/m/Y H:i'),
            'ten_khach_hang' => $customer?->name ?? '',
            'ma_khach_hang' => $customer?->code ?? '',
            'nguoi_lien_he' => $customer?->contact_name ?? '',
            'so_dien_thoai' => $customer?->phone ?? '',
            'email' => $customer?->email ?? '',
            'ten_cong_ty' => $customer?->name ?? '',
            'ma_so_thue' => $customer?->tax_code ?? '',
            'dia_chi_cong_ty' => $customer?->billing_address ?: ($customer?->address ?? ''),
            'bang_dong_hang' => $this->itemsText($quotation),
            'tam_tinh' => $this->money($subtotal),
            'chiet_khau' => $this->money($discount),
            'vat' => $this->money($tax),
            'tong_cong' => $this->money($total),
            'tong_tien_bang_chu' => $this->moneyInWords($total),
            'nguoi_tao' => $quotation->salesOwner?->name ?? '',
            'trang_thai' => $this->statusText($quotation->status),
        ];
    }

    private function lineValues($item, int $index): array
    {
        $sku = $item->sku;

        return [
            'stt' => (string) ($index + 1),
            'ma_hang' => $sku?->sku_code ?? '',
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

    private function itemsText(Quotation $quotation): string
    {
        return $quotation->items
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
                    $sku?->sku_code ?? '',
                    $item->name ?: ($sku?->name ?? ''),
                    $quantity,
                    $unitPrice,
                    $vat,
                    $total
                );
            })
            ->implode('; ');
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
            default => $status ?: '',
        };
    }
}
