<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Support\AuditLogger;
use App\Support\CodeGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DepartmentController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly CodeGenerator $codes,
    )
    {
    }

    public function index(Request $request): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;
        $pageSize = min((int) $request->query('page_size', 50), 100);
        $rows = Department::query()
            ->where('tenant_id', $tenantId)
            ->with(['tenant:id,name'])
            ->withCount('users')
            ->orderBy('code')
            ->paginate($pageSize);

        return response()->json([
            'data' => $rows->items(),
            'meta' => ['page' => $rows->currentPage(), 'page_size' => $rows->perPage(), 'total' => $rows->total()],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'parent_id' => ['nullable', Rule::exists('departments', 'id')->where('tenant_id', $tenantId)],
            'manager_id' => ['nullable', Rule::exists('users', 'id')->where('tenant_id', $tenantId)],
        ]);

        $department = Department::create($data + [
            'tenant_id' => $tenantId,
            'code' => $this->codes->next('departments', 'code', 'DEP-', fn ($query) => $query->where('tenant_id', $tenantId)),
        ]);
        $this->audit->record('department', $department->id, 'create_department', $request->user(), null, $department->toArray(), $request);

        return response()->json(['data' => $department->loadCount('users')], 201);
    }

    public function update(Request $request, Department $department): JsonResponse
    {
        abort_if($department->tenant_id !== $request->user()->tenant_id, 404);
        $tenantId = $request->user()->tenant_id;
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'parent_id' => ['nullable', Rule::exists('departments', 'id')->where('tenant_id', $tenantId)],
            'manager_id' => ['nullable', Rule::exists('users', 'id')->where('tenant_id', $tenantId)],
        ]);

        abort_if(($data['parent_id'] ?? null) === $department->id, 422, 'Phòng ban cha không được trùng chính nó.');
        $old = $department->toArray();
        $department->update($data);
        $this->audit->record('department', $department->id, 'update_department', $request->user(), $old, $department->fresh()->toArray(), $request);

        return response()->json(['data' => $department->refresh()->loadCount('users')]);
    }
}
