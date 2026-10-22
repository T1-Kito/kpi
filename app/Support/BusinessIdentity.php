<?php

namespace App\Support;

class BusinessIdentity
{
    public static function tax(?string $value): ?string
    {
        $value = mb_strtoupper(preg_replace('/[\s.\-]+/u', '', trim($value ?? '')));
        return $value === '' ? null : $value;
    }

    public static function warranty(string $product, string $serial): string
    {
        $normalize = fn(string $value) => mb_strtoupper(preg_replace('/\s+/u', ' ', trim($value)));
        return hash('sha256', json_encode([$normalize($product), $normalize($serial)], JSON_UNESCAPED_UNICODE));
    }
}
