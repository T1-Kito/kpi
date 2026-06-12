<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Models\Role;
use App\Support\AuditLogger;
use App\Support\CodeGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RoleAdminController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly CodeGenerator $codes,
    )
    {
    }

    public function index(Request $request): JsonResponse
    {
        $roles = Role::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->with('permissions:id,code,name')
            ->orderBy('code')
            ->get();

        return response()->json([
            'data' => $roles,
            'meta' => [
                'total' => $roles->count(),
                'permissions' => Permission::select('id', 'code', 'name')->orderBy('code')->get(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'data_scope' => ['nullable', Rule::in(['own', 'department', 'warehouse', 'company'])],
            'permission_ids' => ['nullable', 'array'],
            'permission_ids.*' => ['integer', Rule::exists('permissions', 'id')],
        ]);

        $role = Role::create([
            'tenant_id' => $tenantId,
            'code' => $this->codes->next('roles', 'code', 'ROLE-', fn ($query) => $query->where('tenant_id', $tenantId)),
            'name' => $data['name'],
            'data_scope' => $data['data_scope'] ?? 'own',
        ]);

        if (! empty($data['permission_ids'])) {
            $role->permissions()->sync($data['permission_ids']);
        }

        $this->audit->record('role', $role->id, 'create_role', $request->user(), null, $role->toArray(), $request);

        return response()->json(['data' => $role->load('permissions:id,code,name')], 201);
    }

    public function syncPermissions(Request $request, Role $role): JsonResponse
    {
        abort_if($role->tenant_id !== $request->user()->tenant_id, 404);
        $data = $request->validate([
            'permission_ids' => ['required', 'array'],
            'permission_ids.*' => ['integer', Rule::exists('permissions', 'id')],
        ]);

        $old = $role->permissions()->pluck('permissions.id')->all();
        $role->permissions()->sync($data['permission_ids']);
        $this->audit->record('role', $role->id, 'sync_role_permissions', $request->user(), ['permission_ids' => $old], ['permission_ids' => $data['permission_ids']], $request);

        return response()->json(['data' => $role->refresh()->load('permissions:id,code,name')]);
    }
}
