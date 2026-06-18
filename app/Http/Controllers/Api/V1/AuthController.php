<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\AuditLogger;
use App\Support\JwtTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function __construct(
        private readonly JwtTokenService $tokens,
        private readonly AuditLogger $audit,
    ) {
    }

    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::query()
            ->with(['tenant', 'roles.permissions'])
            ->where('email', $credentials['email'])
            ->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            $this->audit->record('auth', null, 'login_failed', null, null, ['email' => $credentials['email']], $request);
            throw ValidationException::withMessages(['email' => 'Email hoặc mật khẩu không đúng.']);
        }

        if (! $user->is_active || $user->tenant?->status !== 'active') {
            $this->audit->record('auth', $user->id, 'login_blocked', $user, null, null, $request, 'inactive_user_or_tenant');

            return response()->json(['error_code' => 'USER_INACTIVE', 'message' => 'Tài khoản không hoạt động.'], 403);
        }

        $this->audit->record('auth', $user->id, 'login_success', $user, null, null, $request);

        return response()->json([
            'data' => [
                'token_type' => 'Bearer',
                'access_token' => $this->tokens->issue($user),
                'user' => $this->userPayload($user),
            ],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $this->audit->record('auth', $request->user()?->id, 'logout', $request->user(), null, null, $request);

        return response()->json(['data' => ['message' => 'Đã đăng xuất.']]);
    }

    /** @return array<string, mixed> */
    private function userPayload(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'tenant_id' => $user->tenant_id,
            'tenant' => $user->tenant ? [
                'id' => $user->tenant->id,
                'code' => $user->tenant->code,
                'name' => $user->tenant->name,
                'logo_url' => $user->tenant->logo_path ? Storage::url($user->tenant->logo_path) : null,
                'ui_settings' => $user->tenant->ui_settings ?? [],
            ] : null,
            'roles' => $user->roles->pluck('code')->values(),
            'permissions' => $user->roles
                ->flatMap(fn ($role) => $role->permissions->pluck('code'))
                ->unique()
                ->values(),
        ];
    }
}
