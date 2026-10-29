<?php

namespace App\Support;

final class QuotationPaymentFields
{
    public static function legacyPlan(?string $terms): ?array
    {
        $terms = trim($terms ?? '');
        if ($terms === 'Thanh toán 100% trước giao hàng / sử dụng dịch vụ.') return ['mode'=>'prepaid'];
        if (preg_match('/^Thanh toán trong (\d+) ngày kể từ khi giao hàng \/ hoàn thành dịch vụ\.$/u',$terms,$m)) {
            $days=(int)$m[1];return $days>=1 && $days<=3650 ? ['mode'=>'credit','days'=>$days] : null;
        }
        if (!preg_match('/^Đặt cọc (\d+(?:\.\d{1,2})?)%(?: trước (\d{2}\/\d{2}\/\d{4}))?; thanh toán (\d+(?:\.\d{1,2})?)% còn lại(?: trước (\d{2}\/\d{2}\/\d{4})| khi giao hàng \/ sử dụng dịch vụ)\.$/u',$terms,$m)) return null;
        $percent=(float)$m[1];
        if ($percent<=0 || $percent>=100 || abs($percent+(float)$m[3]-100)>0.001) return null;
        $date = function($text) {
            if (!$text) return null;
            $value=\DateTimeImmutable::createFromFormat('!d/m/Y',$text);
            return $value && $value->format('d/m/Y')===$text ? $value->format('Y-m-d') : false;
        };
        $deposit=$date($m[2] ?? null);$balance=$date($m[4] ?? null);
        if ($deposit===false || $balance===false || ($deposit && $balance && $balance<$deposit)) return null;
        return ['mode'=>'deposit','percent'=>$percent,'deposit_date'=>$deposit,'balance_date'=>$balance];
    }

    public static function values(?array $plan, float $total, string $terms = ''): array
    {
        $mode = $plan['mode'] ?? '';
        $percent = $mode === 'deposit' ? (float) ($plan['percent'] ?? 0) : 0;
        $money = fn($amount) => number_format($amount,0,',','.').' đ';
        $number = fn($value) => rtrim(rtrim(number_format($value,2,',',''),'0'),',');
        $amount = round($total);
        $deposit = $mode === 'deposit' ? round($total*$percent/100) : 0;
        $label = match ($mode) {
            'deposit' => 'Số tiền đặt cọc ('.$number($percent).'%):',
            'prepaid' => 'Thanh toán trước giao hàng / sử dụng dịch vụ (100%):',
            'credit' => 'Thanh toán công nợ (100%):',
            default => 'Điều khoản thanh toán:',
        };
        return [
            'ty_le_dat_coc' => $mode==='deposit' ? $number($percent).'%' : '',
            'ty_le_con_lai' => $mode==='deposit' ? $number(100-$percent).'%' : '',
            'tien_dat_coc' => $mode==='deposit' ? $money($deposit) : '',
            'tien_con_lai' => in_array($mode,['deposit','prepaid','credit'],true) ? $money($amount-$deposit) : '',
            'nhan_thanh_toan_dau' => $label,
            'gia_tri_thanh_toan_dau' => match($mode) {'deposit'=>$money($deposit),'prepaid','credit'=>$money($amount),default=>$terms},
            'nhan_thanh_toan_con_lai' => $mode==='deposit' ? 'Số tiền còn lại cần thanh toán ('.$number(100-$percent).'%):' : '',
            'gia_tri_thanh_toan_con_lai' => $mode==='deposit' ? $money($amount-$deposit) : '',
            'han_dat_coc' => !empty($plan['deposit_date']) && $mode==='deposit' ? \Carbon\Carbon::parse($plan['deposit_date'])->format('d/m/Y') : '',
            'han_thanh_toan_con_lai' => !empty($plan['balance_date']) && $mode==='deposit' ? \Carbon\Carbon::parse($plan['balance_date'])->format('d/m/Y') : '',
            'so_ngay_cong_no' => $mode==='credit' ? (string)($plan['days'] ?? '') : '',
        ];
    }
}
