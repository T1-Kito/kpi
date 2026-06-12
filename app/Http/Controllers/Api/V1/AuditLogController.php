<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $pageSize = min((int) $request->query('page_size', 20), 100);
        $query = AuditLog::query()
            ->where(fn ($q) => $q
                ->where('tenant_id', $request->user()->tenant_id)
                ->orWhereNull('tenant_id'))
            ->with('user:id,name,email');

        if ($entityType = $request->query('entity_type')) {
            $query->where('entity_type', $entityType);
        }

        if ($action = $request->query('action')) {
            $query->where('action', $action);
        }

        if ($userId = $request->query('user_id')) {
            $query->where('user_id', $userId);
        }

        if ($from = $request->query('from')) {
            $query->whereDate('created_at', '>=', $from);
        }

        if ($to = $request->query('to')) {
            $query->whereDate('created_at', '<=', $to);
        }

        $logs = $query->latest('id')->paginate($pageSize);

        return response()->json([
            'data' => $logs->items(),
            'meta' => ['page' => $logs->currentPage(), 'page_size' => $logs->perPage(), 'total' => $logs->total()],
        ]);
    }

    public function show(Request $request, AuditLog $auditLog): JsonResponse
    {
        abort_if($auditLog->tenant_id !== null && $auditLog->tenant_id !== $request->user()->tenant_id, 404);

        return response()->json([
            'data' => $auditLog->load('user:id,name,email'),
        ]);
    }
}
