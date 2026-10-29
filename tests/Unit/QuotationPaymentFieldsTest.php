<?php

namespace Tests\Unit;

use App\Support\QuotationPaymentFields;
use PHPUnit\Framework\TestCase;

class QuotationPaymentFieldsTest extends TestCase
{
    public function test_recognizes_only_generated_legacy_terms_with_valid_dates_and_balanced_ratios(): void
    {
        $plan=QuotationPaymentFields::legacyPlan('Đặt cọc 50% trước 27/10/2026; thanh toán 50% còn lại khi giao hàng / sử dụng dịch vụ.');
        $this->assertSame('deposit',$plan['mode']);
        $this->assertEquals(50,$plan['percent']);
        $this->assertSame('2026-10-27',$plan['deposit_date']);
        $this->assertNull(QuotationPaymentFields::legacyPlan('Đặt cọc 50% trước 31/02/2026; thanh toán 50% còn lại khi giao hàng / sử dụng dịch vụ.'));
        $this->assertNull(QuotationPaymentFields::legacyPlan('Đặt cọc 50%; thanh toán 40% còn lại khi giao hàng / sử dụng dịch vụ.'));
        $this->assertNull(QuotationPaymentFields::legacyPlan('Tự thỏa thuận cọc 50% hoặc trả trước.'));
        $this->assertSame(['mode'=>'credit','days'=>30],QuotationPaymentFields::legacyPlan('Thanh toán trong 30 ngày kể từ khi giao hàng / hoàn thành dịch vụ.'));
    }

    public function test_deposit_labels_and_amounts_follow_chosen_ratio_and_balance_rounding(): void
    {
        $fields=QuotationPaymentFields::values(['mode'=>'deposit','percent'=>30],1000001);
        $this->assertSame('30%',$fields['ty_le_dat_coc']);
        $this->assertSame('70%',$fields['ty_le_con_lai']);
        $this->assertSame('300.000 đ',$fields['tien_dat_coc']);
        $this->assertSame('700.001 đ',$fields['tien_con_lai']);
        $this->assertStringContainsString('(30%)',$fields['nhan_thanh_toan_dau']);
        $this->assertStringContainsString('(70%)',$fields['nhan_thanh_toan_con_lai']);
    }

    public function test_non_deposit_modes_do_not_print_deposit_or_balance_labels(): void
    {
        foreach(['prepaid','credit'] as $mode) {
            $fields=QuotationPaymentFields::values(['mode'=>$mode,'days'=>45],1000);
            $this->assertSame('1.000 đ',$fields['gia_tri_thanh_toan_dau']);
            $this->assertSame('',$fields['nhan_thanh_toan_con_lai']);
            $this->assertSame('',$fields['gia_tri_thanh_toan_con_lai']);
            $this->assertSame('',$fields['tien_dat_coc']);
        }
        $this->assertSame('Theo thỏa thuận',QuotationPaymentFields::values(null,1000,'Theo thỏa thuận')['gia_tri_thanh_toan_dau']);
    }
}
