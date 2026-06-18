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
                'tenant' => $user->tenant ? [
                    'id' => $user->tenant->id,
                    'code' => $user->tenant->code,
                    'name' => $user->tenant->name,
                    'logo_url' => $user->tenant->logo_path ? Storage::url($user->tenant->logo_path) : null,
                    'ui_settings' => $user->tenant->ui_settings ?? [],
                ] : null,
                'department' => $user->department?->only(['id', 'code', 'name']),
                'roles' => $user->roles->pluck('code')->values(),
                'permissions' => $user->roles
                    ->flatMap(fn ($role) => $role->permissions->pluck('code'))
                    ->unique()
                    ->values(),
            ],
        ]);
    }
}
