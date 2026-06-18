<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

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

    public function updateUiSettings(Request $request): JsonResponse
    {
        $allowedIcons = ['home', 'tasks', 'business', 'warehouse', 'purchase', 'kpi', 'alert', 'admin', 'print', 'workflow', 'custom'];
        $iconRule = ['nullable', 'string', Rule::in($allowedIcons)];

        $data = $request->validate([
            'sidebar_icons' => ['nullable', 'array'],
            'sidebar_icons.dashboard' => $iconRule,
            'sidebar_icons.tasks' => $iconRule,
            'sidebar_icons.business' => $iconRule,
            'sidebar_icons.warehouse' => $iconRule,
            'sidebar_icons.purchase' => $iconRule,
            'sidebar_icons.kpi' => $iconRule,
            'sidebar_icons.alerts' => $iconRule,
            'sidebar_icons.admin' => $iconRule,
            'sidebar_icons.print' => $iconRule,
            'sidebar_icons.workflow' => $iconRule,
        ]);

        $tenant = $request->user()->tenant;
        abort_if(! $tenant, 404);

        $oldSettings = $tenant->ui_settings ?? [];
        $settings = $oldSettings;
        $settings['sidebar_icons'] = $data['sidebar_icons'] ?? [];

        $tenant->update(['ui_settings' => $settings]);
        $this->audit->record('tenant', $tenant->id, 'tenant_ui_settings_updated', $request->user(), $oldSettings, $settings, $request);

        return response()->json([
            'data' => [
                'ui_settings' => $settings,
            ],
        ]);
    }

    public function uploadSidebarIcon(Request $request): JsonResponse
    {
        $keys = ['dashboard', 'tasks', 'business', 'warehouse', 'purchase', 'kpi', 'alerts', 'admin', 'print', 'workflow'];
        $data = $request->validate([
            'key' => ['required', 'string', Rule::in($keys)],
            'icon' => ['required', 'image', 'mimes:png,jpg,jpeg,webp', 'max:512'],
        ]);

        $tenant = $request->user()->tenant;
        abort_if(! $tenant, 404);

        $oldSettings = $tenant->ui_settings ?? [];
        $settings = $oldSettings;
        $paths = $settings['custom_sidebar_icon_paths'] ?? [];
        $urls = $settings['custom_sidebar_icons'] ?? [];
        $icons = $settings['sidebar_icons'] ?? [];
        $key = $data['key'];

        $oldPath = $paths[$key] ?? null;
        $path = $data['icon']->store("tenants/{$tenant->id}/sidebar-icons", 'public');

        $paths[$key] = $path;
        $urls[$key] = Storage::url($path);
        $icons[$key] = 'custom';

        $settings['custom_sidebar_icon_paths'] = $paths;
        $settings['custom_sidebar_icons'] = $urls;
        $settings['sidebar_icons'] = $icons;

        $tenant->update(['ui_settings' => $settings]);

        if ($oldPath && Storage::disk('public')->exists($oldPath)) {
            Storage::disk('public')->delete($oldPath);
        }

        $this->audit->record('tenant', $tenant->id, 'tenant_sidebar_icon_uploaded', $request->user(), $oldSettings, $settings, $request);

        return response()->json([
            'data' => [
                'ui_settings' => $settings,
            ],
        ]);
    }
}
