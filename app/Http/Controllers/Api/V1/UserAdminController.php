<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Position;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Support\AuditLogger;
use App\Support\OrganizationHierarchy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class UserAdminController extends Controller
{
    public function __construct(private readonly AuditLogger $audit)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;
        $pageSize = min((int) $request->query('page_size', 20), 100);
        $query = User::query()
            ->where('tenant_id', $tenantId)
            ->whereDoesntHave('roles',fn($q)=>$q->where('code',\App\Support\SystemAccount::ROLE))
            ->with(['department:id,code,name', 'position:id,code,name', 'roles:id,code,name,data_scope']);

        if ($search = $request->query('q')) {
            $query->where(fn ($q) => $q
                ->where('name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%"));
        }

        if ($status = $request->query('status')) {
            $query->where('is_active', $status === 'active');
        }

        $users = $query->latest('id')->paginate($pageSize);

        return response()->json([
            'data' => $users->items(),
            'meta' => [
                'page' => $users->currentPage(),
                'page_size' => $users->perPage(),
                'total' => $users->total(),
                'departments' => Department::where('tenant_id', $tenantId)->select('id', 'code', 'name')->orderBy('code')->get(),
                'positions' => Position::where('tenant_id', $tenantId)->select('id', 'code', 'name')->orderBy('code')->get(),
                'can_assign_roles' => $request->user()->hasPermission('role.manage'),
                'can_handover' => $request->user()->dataScope() === 'company' && $request->user()->hasPermission('master.manage'),
                'roles' => $request->user()->hasPermission('role.manage') ? Role::where('tenant_id', $tenantId)->where('code','!=',\App\Support\SystemAccount::ROLE)->select('id', 'code', 'name', 'data_scope')->orderBy('code')->get() : [],
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'string', 'min:8'],
            'department_id' => ['nullable', Rule::exists('departments', 'id')->where('tenant_id', $tenantId)],
            'position_id' => ['nullable', Rule::exists('positions', 'id')->where('tenant_id', $tenantId)],
            'manager_id' => ['nullable', Rule::exists('users', 'id')->where('tenant_id', $tenantId)->where('is_active', true)],
            'role_ids' => ['nullable', 'array'],
            'role_ids.*' => ['integer', Rule::exists('roles', 'id')->where('tenant_id', $tenantId)],
        ]);

        abort_if(!empty($data['role_ids']) && !$request->user()->hasPermission('role.manage'), 403, 'Bạn cần quyền quản lý vai trò để cấp quyền cho người dùng.');

        \App\Support\SystemAccount::assertOrdinaryRoles($data['role_ids'] ?? [],$tenantId);
        $user = User::create([
            'tenant_id' => $tenantId,
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'department_id' => $data['department_id'] ?? null,
            'position_id' => $data['position_id'] ?? null,
            'manager_id' => $data['manager_id'] ?? null,
            'is_active' => true,
        ]);

        if (! empty($data['role_ids'])) {
            $user->roles()->sync($data['role_ids']);
        }

        $this->audit->record('user', $user->id, 'create_user', $request->user(), null, $user->toArray(), $request);

        return response()->json(['data' => $user->load(['department:id,code,name', 'position:id,code,name', 'roles:id,code,name,data_scope'])], 201);
    }

    public function syncRoles(Request $request, User $user): JsonResponse
    {
        \App\Support\SystemAccount::protect($user);
        abort_if($user->tenant_id !== $request->user()->tenant_id, 404);
        abort_unless($request->user()->hasPermission('role.manage'), 403, 'Bạn cần quyền quản lý vai trò để cấp quyền cho người dùng.');
        $data = $request->validate([
            'role_ids' => ['required', 'array'],
            'role_ids.*' => ['integer', Rule::exists('roles', 'id')->where('tenant_id', $request->user()->tenant_id)],
        ]);

        \App\Support\SystemAccount::assertOrdinaryRoles($data['role_ids'],$request->user()->tenant_id);
        $old = $user->roles()->pluck('roles.id')->all();
        $user->roles()->sync($data['role_ids']);
        $this->audit->record('user', $user->id, 'sync_user_roles', $request->user(), ['role_ids' => $old], ['role_ids' => $data['role_ids']], $request);

        return response()->json(['data' => $user->refresh()->load(['department:id,code,name', 'position:id,code,name', 'roles:id,code,name,data_scope'])]);
    }

    public function updateOrganization(Request $request, User $user): JsonResponse
    {
        \App\Support\SystemAccount::protect($user);
        abort_if($user->tenant_id !== $request->user()->tenant_id, 404);
        $tenantId = $request->user()->tenant_id;
        $data = $request->validate([
            'department_id' => ['nullable', Rule::exists('departments', 'id')->where('tenant_id', $tenantId)],
            'position_id' => ['nullable', Rule::exists('positions', 'id')->where('tenant_id', $tenantId)],
            'manager_id' => ['nullable', Rule::exists('users', 'id')->where('tenant_id', $tenantId)->where('is_active', true)],
        ]);

        return DB::transaction(function () use ($data, $user, $tenantId, $request) {
            Tenant::whereKey($tenantId)->lockForUpdate()->firstOrFail();
            $user->refresh();
            if (array_key_exists('manager_id', $data)) {
                OrganizationHierarchy::assertAcyclic($user, 'manager_id', isset($data['manager_id']) ? (int) $data['manager_id'] : null);
            }
            $old = $user->only(['department_id', 'position_id', 'manager_id']);
            $user->update($data);
            $this->audit->record('user', $user->id, 'update_user_organization', $request->user(), $old, $user->fresh()->only(['department_id', 'position_id', 'manager_id']), $request);

            return response()->json(['data' => $user->refresh()->load(['department:id,code,name', 'position:id,code,name', 'roles:id,code,name,data_scope'])]);
        });
    }

    public function updateStatus(Request $request, User $user): JsonResponse
    {
        \App\Support\SystemAccount::protect($user);
        return \Illuminate\Support\Facades\DB::transaction(function () use ($request,$user) {
        abort_if($user->tenant_id !== $request->user()->tenant_id, 404);
        \Illuminate\Support\Facades\DB::table('tenants')->where('id',$user->tenant_id)->lockForUpdate()->first();
        abort_unless(User::whereKey($request->user()->id)->where('is_active',true)->exists(),403);
        $data = $request->validate(['is_active' => ['required', 'boolean']]);
        abort_if(!$data['is_active'] && $user->id === $request->user()->id, 422, 'Không thể tự khóa tài khoản đang đăng nhập.');

        if (!$data['is_active'] && $user->hasPermission('user.manage') && $user->hasPermission('role.manage')) {
            $otherAdmin = User::where('tenant_id',$user->tenant_id)->where('is_active',true)->whereKeyNot($user->id)
                ->whereHas('roles.permissions',fn($q)=>$q->where('code','user.manage'))
                ->whereHas('roles.permissions',fn($q)=>$q->where('code','role.manage'))->exists();
            abort_unless($otherAdmin,422,'Không thể khóa tài khoản quản trị cuối cùng của doanh nghiệp.');
        }

        $old = $user->is_active;
        $user->update(['is_active' => $data['is_active']]);
        $this->audit->record('user', $user->id, 'change_user_status', $request->user(), ['is_active' => $old], ['is_active' => $user->is_active], $request);

        return response()->json(['data' => $user->refresh()->load(['department:id,code,name', 'position:id,code,name', 'roles:id,code,name,data_scope'])]);
        });
    }
}
