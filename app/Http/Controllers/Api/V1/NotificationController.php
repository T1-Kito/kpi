<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\VkNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $pageSize = min((int) $request->query('page_size', 20), 100);
        $query = VkNotification::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->where('recipient_id', $request->user()->id);

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        $notifications = $query->latest('id')->paginate($pageSize);

        return response()->json([
            'data' => $notifications->items(),
            'meta' => ['page' => $notifications->currentPage(), 'page_size' => $notifications->perPage(), 'total' => $notifications->total()],
        ]);
    }

    public function markRead(Request $request, VkNotification $notification): JsonResponse
    {
        abort_if($notification->tenant_id !== $request->user()->tenant_id || $notification->recipient_id !== $request->user()->id, 404);

        $notification->update(['status' => 'read', 'read_at' => now()]);

        return response()->json(['data' => $notification->refresh()]);
    }
}
