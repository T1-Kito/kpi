<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PurchaseRequest;
use App\Models\SupplierQuotation;
use App\Support\AuditLogger;
use App\Support\CodeGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class SupplierQuotationController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly CodeGenerator $codes,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $pageSize = min((int) $request->query('page_size', 20), 100);
        $query = SupplierQuotation::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->with(['supplier:id,code,name', 'purchaseRequest:id,code,status', 'creator:id,name,email', 'selector:id,name,email'])
            ->withCount('lines')
            ->latest('id');

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        $quotations = $query->paginate($pageSize);

        return response()->json([
            'data' => $quotations->items(),
            'meta' => [
                'page' => $quotations->currentPage(),
                'page_size' => $quotations->perPage(),
                'total' => $quotations->total(),
            ],
        ]);
    }

    public function show(Request $request, SupplierQuotation $supplierQuotation): JsonResponse
    {
        abort_if($supplierQuotation->tenant_id !== $request->user()->tenant_id, 404);

        return response()->json([
            'data' => $supplierQuotation->load([
                'supplier:id,code,name,phone,email',
                'purchaseRequest:id,code,status,reason',
                'creator:id,name,email',
                'selector:id,name,email',
                'lines.sku:id,sku_code,name,unit',
                'purchaseOrders:id,code,supplier_quotation_id,created_at',
            ]),
            'can_configure_approval' => false,
            'approval_candidates' => $request->user()->hasPermission('role.manage') ? \App\Models\User::where('tenant_id', $supplierQuotation->tenant_id)->where('is_active', true)->get()->filter(fn ($user) => $user->hasPermission('procurement.po.approve'))->map(fn ($user) => ['id' => $user->id, 'name' => $user->name])->values() : [],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;
        $data = $request->validate([
            'purchase_request_id' => ['nullable', Rule::exists('purchase_requests', 'id')->where('tenant_id', $tenantId)],
            'supplier_id' => ['required', Rule::exists('suppliers', 'id')->where('tenant_id', $tenantId)],
            'quoted_at' => ['required', 'date'],
            'valid_until' => ['nullable', 'date', 'after_or_equal:quoted_at'],
            'note' => ['nullable', 'string', 'max:1000'],
            'document' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,xlsx,xls', 'max:10240'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.sku_id' => ['required', Rule::exists('skus', 'id')->where('tenant_id', $tenantId)],
            'lines.*.quantity' => ['required', 'numeric', 'min:0.01'],
            'lines.*.unit_price' => ['required', 'numeric', 'min:0'],
            'lines.*.note' => ['nullable', 'string', 'max:500'],
        ]);

        $quotation = DB::transaction(function () use ($data, $tenantId, $request) {
            if (!empty($data['purchase_request_id'])) {
                $pr = PurchaseRequest::where('tenant_id', $tenantId)->with('items')->findOrFail($data['purchase_request_id']);
                abort_if($pr->status !== 'approved', 422, 'Cần duyệt yêu cầu mua trước khi lấy báo giá.');
                $limits = $pr->items->groupBy('sku_id')->map(fn ($items) => $items->sum('quantity'));
                foreach (collect($data['lines'])->groupBy('sku_id') as $skuId => $lines) {
                    abort_if(!$limits->has($skuId) || $lines->sum('quantity') > (float)$limits[$skuId], 422, 'Hàng hóa và số lượng báo giá không được vượt yêu cầu đã duyệt.');
                }
            }
            $quotation = SupplierQuotation::create([
                'tenant_id' => $tenantId,
                'code' => $this->nextCode($tenantId),
                'purchase_request_id' => $data['purchase_request_id'] ?? null,
                'supplier_id' => $data['supplier_id'],
                'created_by' => $request->user()->id,
                'quoted_at' => $data['quoted_at'],
                'valid_until' => $data['valid_until'] ?? null,
                'status' => 'draft',
                'note' => $data['note'] ?? null,
                'total_amount' => 0,
            ]);

            $total = 0;
            foreach ($data['lines'] as $line) {
                $lineTotal = (float) $line['quantity'] * (float) $line['unit_price'];
                $total += $lineTotal;
                $quotation->lines()->create([
                    'sku_id' => $line['sku_id'],
                    'quantity' => $line['quantity'],
                    'unit_price' => $line['unit_price'],
                    'line_total' => $lineTotal,
                    'note' => $line['note'] ?? null,
                ]);
            }

            $quotation->update(['total_amount' => $total]);
            if ($request->hasFile('document')) {
                $file = $request->file('document');
                $path = $file->store('supplier-quotations/'.$tenantId.'/'.$quotation->id, 'local');
                abort_unless($path, 500, 'Không lưu được file báo giá.');
                $quotation->update(['document_path' => $path, 'document_name' => $file->getClientOriginalName(), 'document_mime' => $file->getMimeType()]);
            }
            $config = $request->user()->tenant->ui_settings['procurement_approval'] ?? [];
            if (!empty($config['enabled'])) {
                $rule = collect($config['rules'] ?? [])->filter(fn ($rule) => (float)$rule['minimum_amount'] <= $total)->sortByDesc('minimum_amount')->first();
                abort_unless($rule, 422, 'Chưa có cấu hình duyệt phù hợp với giá trị báo giá.');
                $users = \App\Models\User::where('tenant_id', $tenantId)->where('is_active', true)->whereIn('id', $rule['approvers'])->get()->keyBy('id');
                foreach ($rule['approvers'] as $id) abort_unless(isset($users[$id]) && $users[$id]->hasPermission('procurement.po.approve'), 422, 'Nhân sự trong cấu hình duyệt không còn hoạt động hoặc thiếu quyền. Vui lòng cập nhật Cài đặt.');
                $quotation->update(['approval_flow' => collect($rule['approvers'])->map(fn ($id, $index) => ['user_id' => $id, 'name' => $users[$id]->name, 'final' => $index === count($rule['approvers']) - 1, 'decided_at' => null])->all()]);
            }

            return $quotation;
        });

        $this->audit->record('supplier_quotation', $quotation->id, 'create_supplier_quotation', $request->user(), null, $quotation->toArray(), $request);

        return response()->json(['data' => $quotation->load(['supplier:id,code,name', 'purchaseRequest:id,code', 'lines.sku:id,sku_code,name,unit'])], 201);
    }

    public function configureApprovalFlow(Request $request, SupplierQuotation $supplierQuotation): JsonResponse
    {
        abort_if($supplierQuotation->tenant_id !== $request->user()->tenant_id, 404);
        abort_unless($request->user()->hasPermission('role.manage'), 403);
        abort_if(!empty($request->user()->tenant->ui_settings['procurement_approval']['enabled']), 422, 'Luồng duyệt được tự lấy từ Cài đặt, không điều chỉnh trên báo giá.');
        $data = $request->validate(['approvers' => ['required', 'array', 'min:2', 'max:10'], 'approvers.*' => ['required', 'integer', 'distinct', Rule::exists('users', 'id')->where('tenant_id', $supplierQuotation->tenant_id)->where('is_active', true)]]);
        return DB::transaction(function () use ($supplierQuotation, $data) {
            $quotation = SupplierQuotation::whereKey($supplierQuotation->id)->lockForUpdate()->firstOrFail();
            abort_if($quotation->status !== 'draft' || $quotation->purchaseOrders()->exists() || collect($quotation->approval_flow ?? [])->contains(fn ($step) => !empty($step['decided_at'])), 422, 'Không thể đổi luồng báo giá đã có người duyệt hoặc đã tạo đơn mua.');
            $users = \App\Models\User::whereIn('id', $data['approvers'])->get()->keyBy('id');
            foreach ($users as $user) abort_unless($user->hasPermission('procurement.po.approve'), 422, 'Nhân sự chưa có quyền duyệt mua hàng.');
            $quotation->update(['approval_flow' => collect($data['approvers'])->map(fn ($id, $index) => ['user_id' => $id, 'name' => $users[$id]->name, 'final' => $index === count($data['approvers']) - 1, 'decided_at' => null])->all()]);
            return response()->json(['data' => $quotation]);
        });
    }

    public function uploadDocument(Request $request, SupplierQuotation $supplierQuotation): JsonResponse
    {
        abort_if($supplierQuotation->tenant_id !== $request->user()->tenant_id, 404);
        $request->validate(['document' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,xlsx,xls', 'max:10240']]);
        $quotation = DB::transaction(function () use ($request, $supplierQuotation) {
            $quotation = SupplierQuotation::whereKey($supplierQuotation->id)->lockForUpdate()->firstOrFail();
            abort_if($quotation->status !== 'draft' || $quotation->purchaseOrders()->exists() || collect($quotation->approval_flow ?? [])->contains(fn ($step) => !empty($step['decided_at'])), 422, 'Đã có lượt duyệt; không được thay chứng từ gốc.');
            $file = $request->file('document');
            $path = $file->store('supplier-quotations/'.$quotation->tenant_id.'/'.$quotation->id, 'local');
            abort_unless($path, 500, 'Không lưu được file báo giá.');
            $quotation->update(['document_path' => $path, 'document_name' => $file->getClientOriginalName(), 'document_mime' => $file->getMimeType()]);
            return $quotation;
        });
        $this->audit->record('supplier_quotation', $quotation->id, 'upload_original_document', $request->user(), null, ['file_name' => $quotation->document_name], $request);
        return response()->json(['data' => $quotation]);
    }

    public function document(Request $request, SupplierQuotation $supplierQuotation)
    {
        abort_if($supplierQuotation->tenant_id !== $request->user()->tenant_id, 404);
        abort_unless($supplierQuotation->document_path && Storage::disk('local')->exists($supplierQuotation->document_path), 404, 'Chưa có file báo giá gốc.');
        $mime = $supplierQuotation->document_mime;
        return Storage::disk('local')->response($supplierQuotation->document_path, $supplierQuotation->document_name, ['Content-Type' => $mime, 'X-Content-Type-Options' => 'nosniff'], in_array($mime, ['application/pdf', 'image/jpeg', 'image/png']) ? 'inline' : 'attachment');
    }

    public function select(Request $request, SupplierQuotation $supplierQuotation): JsonResponse
    {
        abort_if($supplierQuotation->tenant_id !== $request->user()->tenant_id, 404);
        $selection = $request->validate([
            'create_purchase_order' => ['nullable', 'boolean'],
            'receiving_warehouse_id' => ['nullable', Rule::exists('warehouses', 'id')->where('tenant_id', $request->user()->tenant_id)->where('status', 'active')],
        ]);

        $order = DB::transaction(function () use ($supplierQuotation, $request, $selection) {
            $supplierQuotation = SupplierQuotation::whereKey($supplierQuotation->id)->lockForUpdate()->firstOrFail();
            if ($supplierQuotation->purchase_request_id) {
                PurchaseRequest::whereKey($supplierQuotation->purchase_request_id)->lockForUpdate()->firstOrFail();
            }
            if ($request->boolean('create_purchase_order')) {
                $existing = $supplierQuotation->purchaseOrders()->first();
                if ($existing) return $existing;
            }
            if ($supplierQuotation->purchase_request_id) {
                $pr = PurchaseRequest::where('tenant_id', $supplierQuotation->tenant_id)->findOrFail($supplierQuotation->purchase_request_id);
                abort_if($pr->status !== 'approved', 422, 'Yêu cầu mua chưa được duyệt.');
                abort_if(\App\Models\PurchaseOrder::where('purchase_request_id', $pr->id)->whereNotIn('status', ['cancelled', 'rejected'])->exists(), 422, 'Yêu cầu đã có đơn mua; không thể đổi báo giá được chọn.');
            }
            if ($supplierQuotation->approval_flow) {
                abort_unless($supplierQuotation->document_path && Storage::disk('local')->exists($supplierQuotation->document_path), 422, 'Cần đính kèm file báo giá gốc trước khi duyệt.');
                $flow = $supplierQuotation->approval_flow;
                $index = collect($flow)->search(fn ($step) => $step['user_id'] === $request->user()->id);
                abort_if($index === false, 403, 'Bạn không thuộc danh sách duyệt báo giá này.');
                abort_if(!empty($flow[$index]['decided_at']), 422, 'Bạn đã duyệt báo giá này.');
                if ($flow[$index]['final']) abort_if(collect($flow)->reject(fn ($step) => $step['final'])->contains(fn ($step) => empty($step['decided_at'])), 422, 'Chưa đủ người duyệt trước giám đốc.');
                $flow[$index]['decided_at'] = now()->toIso8601String();
                $supplierQuotation->update(['approval_flow' => $flow]);
                $this->audit->record('supplier_quotation', $supplierQuotation->id, 'approve_supplier_quotation_step', $request->user(), null, ['step' => $index + 1], $request);
                if (collect($flow)->contains(fn ($step) => empty($step['decided_at']))) return null;
            }
            SupplierQuotation::where('tenant_id', $supplierQuotation->tenant_id)
                ->when(
                    $supplierQuotation->purchase_request_id,
                    fn ($query) => $query->where('purchase_request_id', $supplierQuotation->purchase_request_id),
                    fn ($query) => $query->whereNull('purchase_request_id'),
                )
                ->where('id', '!=', $supplierQuotation->id)
                ->where('status', 'selected')
                ->update(['status' => 'rejected']);

            $supplierQuotation->update([
                'status' => 'selected',
                'selected_by' => $request->user()->id,
                'selected_at' => now(),
            ]);

            if ($request->boolean('create_purchase_order') || $supplierQuotation->approval_flow) {
                $service = app(\App\Services\Procurement\ProcurementService::class);
                $po = $service
                    ->createPoFromSupplierQuotation($supplierQuotation->load(['purchaseRequest.items', 'lines.sku']), $request->user(), null, [
                        'receiving_warehouse_id' => $selection['receiving_warehouse_id'] ?? null,
                    ]);
                return $supplierQuotation->approval_flow && !$po->approval_flow ? $service->approvePo($po, $request->user(), 'Đã đủ người duyệt tại báo giá nhà cung cấp.') : $po;
            }
            return null;

        });

        if ($order || !$supplierQuotation->refresh()->approval_flow) $this->audit->record('supplier_quotation', $supplierQuotation->id, 'select_supplier_quotation', $request->user(), null, ['status' => $supplierQuotation->status], $request);

        return response()->json(['data' => $supplierQuotation->refresh()->load(['supplier:id,code,name', 'purchaseRequest:id,code', 'lines.sku:id,sku_code,name,unit']), 'purchase_order' => $order]);
    }

    private function nextCode(int $tenantId): string
    {
        return $this->codes->next('supplier_quotations', 'code', 'SQ-', fn ($query) => $query->where('tenant_id', $tenantId));
    }
}
