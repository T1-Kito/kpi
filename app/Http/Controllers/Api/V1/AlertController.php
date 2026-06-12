<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Alert;
use App\Support\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AlertController extends Controller
{
    public function __construct(private readonly AuditLogger $audit)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $pageSize = min((int) $request->query('page_size', 20), 100);
        $query = Alert::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->with('recipient:id,name,email');

        if ($request->boolean('mine')) {
            $query->where('recipient_id', $request->user()->id);
        }

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        if ($level = $request->query('level')) {
            $query->where('level', $level);
        }

        $alerts = $query->latest('id')->paginate($pageSize);

        return response()->json([
            'data' => $alerts->items(),
            'meta' => ['page' => $alerts->currentPage(), 'page_size' => $alerts->perPage(), 'total' => $alerts->total()],
        ]);
    }

    public function updateStatus(Request $request, Alert $alert): JsonResponse
    {
        abort_if($alert->tenant_id !== $request->user()->tenant_id, 404);

        $data = $request->validate([
            'status' => ['required', Rule::in(['acknowledged', 'resolved'])],
        ]);

        $old = $alert->status;
        $alert->update(['status' => $data['status']]);
        $this->audit->record('alert', $alert->id, 'change_alert_status', $request->user(), ['status' => $old], ['status' => $alert->status], $request);

        return response()->json(['data' => $alert->refresh()]);
    }
}
