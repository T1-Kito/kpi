<?php
namespace App\Support;

use Illuminate\Support\Facades\Storage;

final class TenantLogo
{
    public static function url(?string $path): ?string
    {
        if (!$path || !Storage::disk('public')->exists($path)) return null;
        return '/storage/'.implode('/',array_map('rawurlencode',explode('/',str_replace('\\','/',$path))));
    }
}
