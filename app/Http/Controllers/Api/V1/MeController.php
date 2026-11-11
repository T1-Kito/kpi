<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class MeController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $user = $request->user()->loadMissing(['tenant', 'department', 'roles.permissions']);

        return response()->json([
            'data' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'has_avatar' => (bool)$user->avatar_path,
                'is_spadmin'=>$user->isSpAdmin(),
                'tenant' => $user->tenant ? [
                    'id' => $user->tenant->id,
                    'code' => $user->tenant->code,
                    'name' => $user->tenant->name,
                    'logo_url' => \App\Support\TenantLogo::url($user->tenant->logo_path),
                    'ui_settings' => $user->tenant->ui_settings ?? [],
                ] : null,
                'department' => $user->department?->only(['id', 'code', 'name']),
                'roles' => $user->roles->pluck('code')->values(),
                'data_scope' => $user->dataScope(),
                'permissions' => $user->effectivePermissionCodes(),
            ],
        ]);
    }
}
