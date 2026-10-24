<?php
namespace Tests\Unit;
use App\Support\{ImportValidation,ContactSourceImport};
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;
class ImportValidationTest extends TestCase
{
    public function test_import_errors_use_vietnamese_field_names_and_instructions():void {
        $v=Validator::make(['email'=>'khong-hop-le'],['external_id'=>'required','customer_ref'=>'required','source_revision'=>'required','email'=>'email'],ImportValidation::MESSAGES,ContactSourceImport::LABELS);
        $this->assertSame('Vui lòng nhập Mã ngoài.',$v->errors()->first('external_id'));
        $this->assertSame('Vui lòng nhập Mã tham chiếu khách hàng.',$v->errors()->first('customer_ref'));
        $this->assertStringContainsString('không đúng định dạng',$v->errors()->first('email'));
        $this->assertStringNotContainsString('field is required',implode(' ',$v->errors()->all()));
    }
}
