<?php

namespace App\Http\Middleware;

use App\Support\InstallationLicense;
use Closure;
use Illuminate\Http\Request;

final class RequireInstallationLicense
{
    public function handle(Request $request, Closure $next)
    {
        if (!config('installation-license.required')) return $next($request);
        try {
            $path = base_path('license.vkcrm');
            if (!is_file($path) || filesize($path) > 32768) throw new \RuntimeException('Missing license');
            InstallationLicense::validate(file_get_contents($path), config('installation-license.public_key'), InstallationLicense::machineCode());
        } catch (\Throwable $error) {
            $message = 'Bản quyền chưa được kích hoạt hoặc không hợp lệ. Mở trình quản lý máy chủ VK CRM để chọn file bản quyền.';
            return $request->is('api/*') || $request->expectsJson()
                ? response()->json(['error_code' => 'LICENSE_REQUIRED', 'message' => $message], 403)
                : response($message, 403)->header('Content-Type', 'text/plain; charset=UTF-8');
        }
        return $next($request);
    }
}
