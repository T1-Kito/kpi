<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequirePermission
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user();

        if (! $user || ! collect(explode('|', $permission))->contains(fn ($code) => $user->hasPermission($code))) {
            return response()->json(['error_code' => 'FORBIDDEN', 'message' => 'Permission denied.'], 403);
        }

        return $next($request);
    }
}
