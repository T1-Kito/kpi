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
            ]),
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
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.sku_id' => ['required', Rule::exists('skus', 'id')->where('tenant_id', $tenantId)],
            'lines.*.quantity' => ['required', 'numeric', 'min:0.01'],
            'lines.*.unit_price' => ['required', 'numeric', 'min:0'],
            'lines.*.note' => ['nullable', 'string', 'max:500'],
        ]);

        $quotation = DB::transaction(function () use ($data, $tenantId, $request) {
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

            return $quotation;
        });

        $this->audit->record('supplier_quotation', $quotation->id, 'create_supplier_quotation', $request->user(), null, $quotation->toArray(), $request);

        return response()->json(['data' => $quotation->load(['supplier:id,code,name', 'purchaseRequest:id,code', 'lines.sku:id,sku_code,name,unit'])], 201);
    }

    public function select(Request $request, SupplierQuotation $supplierQuotation): JsonResponse
    {
        abort_if($supplierQuotation->tenant_id !== $request->user()->tenant_id, 404);

        DB::transaction(function () use ($supplierQuotation, $request) {
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

            if ($supplierQuotation->purchase_request_id) {
                PurchaseRequest::whereKey($supplierQuotation->purchase_request_id)
                    ->where('tenant_id', $supplierQuotation->tenant_id)
                    ->update(['status' => 'approved']);
            }
        });

        $this->audit->record('supplier_quotation', $supplierQuotation->id, 'select_supplier_quotation', $request->user(), null, ['status' => 'selected'], $request);

        return response()->json(['data' => $supplierQuotation->refresh()->load(['supplier:id,code,name', 'purchaseRequest:id,code', 'lines.sku:id,sku_code,name,unit'])]);
    }

    private function nextCode(int $tenantId): string
    {
        return $this->codes->next('supplier_quotations', 'code', 'SQ-', fn ($query) => $query->where('tenant_id', $tenantId));
    }
}
