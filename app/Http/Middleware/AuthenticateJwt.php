<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\JwtTokenService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class AuthenticateJwt
{
    public function __construct(private readonly JwtTokenService $tokens)
    {
    }

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $bearer = $request->bearerToken();

        if (! $bearer) {
            return response()->json(['error_code' => 'UNAUTHENTICATED', 'message' => 'Missing bearer token.'], 401);
        }

        try {
            $claims = $this->tokens->verify($bearer);
        } catch (Throwable) {
            return response()->json(['error_code' => 'UNAUTHENTICATED', 'message' => 'Invalid or expired token.'], 401);
        }

        $user = User::query()
            ->with(['tenant', 'roles.permissions', 'department'])
            ->find($claims['user_id'] ?? null);

        if (! $user || ! $user->is_active || $user->tenant?->status !== 'active') {
            return response()->json(['error_code' => 'UNAUTHENTICATED', 'message' => 'User is inactive.'], 401);
        }

        $request->setUserResolver(fn () => $user);

        return $next($request);
    }
}
