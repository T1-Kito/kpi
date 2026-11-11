<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class PhoneNumberValidationTest extends TestCase
{
    public function test_phone_rule_rejects_letters_and_preserves_formatted_numbers(): void
    {
        foreach (['abc', '090abc123', '+84 hello', '１２３', '++84901234567', '090+123', '0987654', '901234567', '000000000000000000'] as $value) {
            $validator = Validator::make(['phone'=>$value], ['phone'=>'nullable|string|max:50|phone_number']);
            $this->assertTrue($validator->fails(), $value);
            $this->assertStringContainsString('Số điện thoại', $validator->errors()->first('phone'));
        }
        foreach ([null, '', '0901234567', '+84 901 234 567', '(028) 1234-5678', '090.123.4567'] as $value) {
            $this->assertFalse(Validator::make(['phone'=>$value], ['phone'=>'nullable|string|max:50|phone_number'])->fails(), (string)$value);
        }
    }
}
