<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class TenantSettingController extends Controller
{
    public function __construct(private readonly AuditLogger $audit)
    {
    }

    public function updateLogo(Request $request): JsonResponse
    {
        $data = $request->validate([
            'logo' => ['required', 'image', 'mimes:png,jpg,jpeg,webp,svg', 'max:2048'],
        ]);

        $tenant = $request->user()->tenant;
        abort_if(! $tenant, 404);

        $oldPath = $tenant->logo_path;
        $path = $data['logo']->store("tenants/{$tenant->id}/branding", 'public');

        $tenant->update(['logo_path' => $path]);

        if ($oldPath && Storage::disk('public')->exists($oldPath)) {
            Storage::disk('public')->delete($oldPath);
        }

        $this->audit->record('tenant', $tenant->id, 'tenant_logo_updated', $request->user(), ['logo_path' => $oldPath], ['logo_path' => $path], $request);

        return response()->json([
            'data' => [
                'logo_path' => $path,
                'logo_url' => Storage::url($path),
            ],
        ]);
    }
}
