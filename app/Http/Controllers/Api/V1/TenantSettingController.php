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

    public function procurementApproval(Request $request): JsonResponse
    {
        return response()->json(['data' => $request->user()->tenant->ui_settings['procurement_approval'] ?? ['enabled' => false, 'rules' => []],
            'users' => \App\Models\User::where('tenant_id', $request->user()->tenant_id)->where('is_active', true)->get()->filter(fn ($user) => $user->hasPermission('procurement.po.approve'))->map(fn ($user) => ['id' => $user->id, 'name' => $user->name])->values()]);
    }

    public function workAssignment(Request $request): JsonResponse
    {
        $service = app(\App\Support\WorkAssignmentService::class);
        $rules = [];
        foreach ($service::RULES as $key => $rule) {
            $rules[$key] = ['label' => $rule['label'], 'users' => $service->eligibleUsers($request->user()->tenant_id, $key)->map(fn ($user) => ['id' => $user->id, 'name' => $user->name])];
        }
        return response()->json(['data' => $request->user()->tenant->ui_settings['work_assignment'] ?? ['enabled' => false, 'pools' => []], 'rules' => $rules]);
    }

    public function operationalApproval(Request $request): JsonResponse
    {
        $candidates = [];
        foreach (\App\Support\OperationalApproval::TYPES as $type => $permission) {
            $candidates[$type] = \App\Models\User::where('tenant_id', $request->user()->tenant_id)->where('is_active', true)->get()
                ->filter(fn ($user) => $user->hasPermission($permission))
                ->map(fn ($user) => ['id' => $user->id, 'name' => $user->name])->values();
        }
        return response()->json(['data' => $request->user()->tenant->ui_settings['operational_approval'] ?? [], 'users' => $candidates]);
    }

    public function saveOperationalApproval(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(array_keys(\App\Support\OperationalApproval::TYPES))],
            'approvers' => ['required', 'array', 'min:2', 'max:50'],
            'approvers.*' => ['required', 'integer', 'distinct', Rule::exists('users', 'id')->where('tenant_id', $request->user()->tenant_id)->where('is_active', true)],
        ]);
        foreach ($data['approvers'] as $id) abort_unless(\App\Models\User::findOrFail($id)->hasPermission(\App\Support\OperationalApproval::TYPES[$data['type']]), 422, 'Người được chọn thiếu quyền duyệt tương ứng.');
        $count = \Illuminate\Support\Facades\DB::transaction(function () use ($request, $data) {
            $tenant = \App\Models\Tenant::whereKey($request->user()->tenant_id)->lockForUpdate()->firstOrFail();
            $settings = $tenant->ui_settings ?? [];
            $old = $settings['operational_approval'][$data['type']] ?? null;
            $settings['operational_approval'][$data['type']] = ['approvers' => $data['approvers']];
            $tenant->update(['ui_settings' => $settings]);
            $model = $data['type'] === 'goods_receipt' ? \App\Models\GoodsReceipt::class : \App\Models\PurchaseOrder::class;
            $rows = $model::where('tenant_id', $tenant->id)->where('status', 'draft')->orderBy('id')->lockForUpdate()->get();
            $flow = app(\App\Support\OperationalApproval::class)->flow($tenant->id, $data['type']);
            $count = 0;
            foreach ($rows as $row) {
                if (collect($row->approval_flow ?? [])->contains(fn ($step) => !empty($step['decided_at']))) continue;
                $previous = $row->approval_flow;
                if ($previous === $flow) continue;
                $row->update(['approval_flow' => $flow]);
                $this->audit->record($data['type'], $row->id, 'sync_operational_approval', $request->user(), ['approval_flow' => $previous], ['approval_flow' => $flow], $request);
                $count++;
            }
            $this->audit->record('tenant', $tenant->id, 'update_'.$data['type'].'_approval', $request->user(), $old, ['approvers' => $data['approvers']], $request);
            return $count;
        });
        return response()->json(['data' => $data, 'updated' => $count]);
    }

    public function saveWorkAssignment(Request $request): JsonResponse
    {
        $service = app(\App\Support\WorkAssignmentService::class);
        $validation = ['enabled' => ['required', 'boolean'], 'pools' => ['required', 'array:lead,warehouse_issue,goods_receipt,purchase_request']];
        foreach ($service::RULES as $key => $rule) {
            $validation["pools.$key"] = ['present', 'array', 'max:100'];
            $validation["pools.$key.*"] = ['integer', 'distinct'];
        }
        $data = $request->validate($validation);
        foreach ($data['pools'] as $key => $ids) {
            $eligible = $service->eligibleUsers($request->user()->tenant_id, $key)->pluck('id')->all();
            foreach ($ids as $id) abort_unless(in_array((int) $id, $eligible, true), 422, 'Người nhận phải đang hoạt động và có đúng quyền xử lý công việc.');
        }
        abort_if($data['enabled'] && !array_filter($data['pools']), 422, 'Chọn ít nhất một nhóm người nhận trước khi bật.');
        \Illuminate\Support\Facades\DB::transaction(function () use ($request, $data) {
            $tenant = \App\Models\Tenant::whereKey($request->user()->tenant_id)->lockForUpdate()->firstOrFail();
            $settings = $tenant->ui_settings ?? [];
            $old = $settings['work_assignment'] ?? null;
            $settings['work_assignment'] = $data;
            $tenant->update(['ui_settings' => $settings]);
            $this->audit->record('tenant', $tenant->id, 'update_work_assignment', $request->user(), $old, $data, $request);
        });
        return response()->json(['data' => $data]);
    }

    public function salesQuotationApproval(Request $request): JsonResponse
    {
        return response()->json(['data' => $request->user()->tenant->ui_settings['sales_quotation_approval'] ?? ['approvers' => [], 'exception_approvers' => [], 'minimum_margin' => 15, 'maximum_discount' => 0, 'amount_threshold' => null],
            'users' => \App\Models\User::where('tenant_id', $request->user()->tenant_id)->where('is_active', true)->get()->filter(fn ($user) => $user->hasPermission('sales.margin.approve'))->map(fn ($user) => ['id' => $user->id, 'name' => $user->name])->values()]);
    }

    public function saveSalesQuotationApproval(Request $request): JsonResponse
    {
        $userRule = ['integer', 'distinct', Rule::exists('users', 'id')->where('tenant_id', $request->user()->tenant_id)->where('is_active', true)];
        $data = $request->validate(['approvers' => ['required', 'array', 'min:1', 'max:3'], 'approvers.*' => $userRule,
            'exception_approvers' => ['present', 'array', 'max:1'], 'exception_approvers.*' => $userRule,
            'minimum_margin' => ['required', 'numeric', 'min:0', 'max:100'], 'maximum_discount' => ['required', 'numeric', 'min:0', 'max:99.99'], 'amount_threshold' => ['nullable', 'numeric', 'min:1']]);
        foreach (array_merge($data['approvers'], $data['exception_approvers']) as $id) abort_unless(\App\Models\User::findOrFail($id)->hasPermission('sales.margin.approve'), 422, 'Người được chọn chưa có quyền duyệt báo giá.');
        \Illuminate\Support\Facades\DB::transaction(function () use ($request, $data) {
            $tenant = \App\Models\Tenant::whereKey($request->user()->tenant_id)->lockForUpdate()->firstOrFail();
            $settings = $tenant->ui_settings ?? []; $old = $settings['sales_quotation_approval'] ?? null;
            $settings['sales_quotation_approval'] = $data; $tenant->update(['ui_settings' => $settings]);
            $this->audit->record('tenant', $tenant->id, 'update_sales_quotation_approval', $request->user(), $old, $data, $request);
        });
        return response()->json(['data' => $data]);
    }

    public function saveProcurementApproval(Request $request): JsonResponse
    {
        $data = $request->validate(['enabled' => ['required', 'boolean'], 'rules' => ['required', 'array', 'max:10'], 'rules.*.minimum_amount' => ['required', 'numeric', 'min:0', 'distinct'], 'rules.*.approvers' => ['required', 'array', 'min:1', 'max:50'], 'rules.*.approvers.*' => ['required', 'integer', Rule::exists('users', 'id')->where('tenant_id', $request->user()->tenant_id)->where('is_active', true)]]);
        if ($data['enabled']) abort_unless(collect($data['rules'])->contains(fn ($rule) => (float)$rule['minimum_amount'] === 0.0), 422, 'Cần một luồng mặc định từ 0 đồng.');
        foreach ($data['rules'] as $rule) {
            abort_if(count(array_unique($rule['approvers'])) !== count($rule['approvers']), 422, 'Không chọn trùng người trong một luồng.');
            foreach ($rule['approvers'] as $id) abort_unless(\App\Models\User::findOrFail($id)->hasPermission('procurement.po.approve'), 422, 'Người được chọn chưa có quyền duyệt mua hàng.');
        }
        \Illuminate\Support\Facades\DB::transaction(function () use ($request, $data) {
            $tenant = \App\Models\Tenant::whereKey($request->user()->tenant_id)->lockForUpdate()->firstOrFail();
            $settings = $tenant->ui_settings ?? [];
            $old = $settings['procurement_approval'] ?? null;
            $settings['procurement_approval'] = $data;
            $tenant->update(['ui_settings' => $settings]);
            app(\App\Support\ProcurementApprovalSync::class)->sync($request->user(), $data);
            $this->audit->record('tenant', $tenant->id, 'update_procurement_approval', $request->user(), $old, $data, $request);
        });
        return response()->json(['data' => $data]);
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
