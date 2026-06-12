<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Position;
use App\Support\AuditLogger;
use App\Support\CodeGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PositionController extends Controller
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
        $rows = Position::query()
            ->where('tenant_id', $tenantId)
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
        ]);

        $position = Position::create($data + [
            'tenant_id' => $tenantId,
            'code' => $this->codes->next('positions', 'code', 'POS-', fn ($query) => $query->where('tenant_id', $tenantId)),
        ]);
        $this->audit->record('position', $position->id, 'create_position', $request->user(), null, $position->toArray(), $request);

        return response()->json(['data' => $position->loadCount('users')], 201);
    }

    public function update(Request $request, Position $position): JsonResponse
    {
        abort_if($position->tenant_id !== $request->user()->tenant_id, 404);
        $tenantId = $request->user()->tenant_id;
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        $old = $position->toArray();
        $position->update($data);
        $this->audit->record('position', $position->id, 'update_position', $request->user(), $old, $position->fresh()->toArray(), $request);

        return response()->json(['data' => $position->refresh()->loadCount('users')]);
    }
}
