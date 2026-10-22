<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Validation\ValidationException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'jwt' => \App\Http\Middleware\AuthenticateJwt::class,
            'permission' => \App\Http\Middleware\RequirePermission::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (\Illuminate\Database\UniqueConstraintViolationException $exception) {
            // Never expose SQL or bindings in a concurrent duplicate response.
            return response()->json(['error_code'=>'DUPLICATE_RECORD','message'=>'Dữ liệu đã được ghi nhận. Vui lòng tải lại và kiểm tra hồ sơ hiện có.'],409);
        });
        $exceptions->render(function (ValidationException $exception) {
            return response()->json([
                'error_code' => 'VALIDATION_ERROR',
                'message' => 'Dữ liệu không hợp lệ.',
                'fields' => $exception->errors(),
            ], 422);
        });
    })->create();
