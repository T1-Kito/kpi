<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Alert;
use App\Models\Approval;
use App\Models\Lead;
use App\Models\PrintTemplate;
use App\Models\Quotation;
use App\Models\SalesOrder;
use App\Models\Task;
use App\Services\Print\QuotationDocxMergeService;
use App\Support\DataScope;
use App\Support\QuotationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class QuotationController extends Controller
{
    public function __construct(
        private readonly QuotationService $quotations,
        private readonly QuotationDocxMergeService $docxMerge,
    )
    {
    }

    public function index(Request $request): JsonResponse
    {
        $pageSize = min((int) $request->query('page_size', 20), 100);
        $query = Quotation::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->with([
                'customer:id,code,name,contact_name,phone,email,address,billing_address,tax_code,credit_limit,legal_representative,representative_position,payment_terms,bank_name,bank_account_no,bank_account_name',
                'items.sku:id,sku_code,name,unit',
                'salesOwner:id,name,email',
                'duplicatedFrom:id,code,status,created_at',
            ])
            ->withCount('salesOrders');
        DataScope::owned($query, $request->user(), 'sales_owner_id', 'salesOwner');

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        $quotations = $query->latest('id')->paginate($pageSize);

        return response()->json([
            'data' => $quotations->items(),
            'meta' => [
                'page' => $quotations->currentPage(),
                'page_size' => $quotations->perPage(),
                'total' => $quotations->total(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validateWritableQuotation($request);

        $quotation = $this->quotations->create($request->user(), $data);

        return response()->json(['data' => $quotation], 201);
    }

    public function update(Request $request, Quotation $quotation): JsonResponse
    {
        abort_if($quotation->tenant_id !== $request->user()->tenant_id, 404);
        abort_if(! DataScope::owned(Quotation::whereKey($quotation->id), $request->user(), 'sales_owner_id', 'salesOwner')->exists(), 404);

        $data = $this->validateWritableQuotation($request);
        $quotation = $this->quotations->update($quotation, $request->user(), $data);

        return response()->json(['data' => $quotation]);
    }

    public function show(Request $request, Quotation $quotation): JsonResponse
    {
        abort_if($quotation->tenant_id !== $request->user()->tenant_id, 404);
        abort_if(! DataScope::owned(Quotation::whereKey($quotation->id), $request->user(), 'sales_owner_id', 'salesOwner')->exists(), 404);

        $quotation->load([
            'customer:id,code,name,contact_name,phone,email,address,billing_address,tax_code,credit_limit,legal_representative,representative_position,payment_terms,bank_name,bank_account_no,bank_account_name',
            'items.sku:id,sku_code,name,unit',
            'salesOwner:id,name,email',
            'duplicatedFrom:id,code,status,created_at',
        ]);

        $lead = $quotation->lead_id
            ? Lead::where('tenant_id', $quotation->tenant_id)->whereKey($quotation->lead_id)->first()
            : null;

        $salesOrders = SalesOrder::where('tenant_id', $quotation->tenant_id)
            ->where('quotation_id', $quotation->id)
            ->latest('id')
            ->get();

        $approvals = Approval::where('tenant_id', $quotation->tenant_id)
            ->where('source_type', 'Quotation')
            ->where('source_id', $quotation->id)
            ->latest('id')
            ->get();

        return response()->json(['data' => [
            ...$quotation->toArray(),
            'approval' => $approvals->first(),
            'approvals' => $approvals,
            'tasks' => Task::where('tenant_id', $quotation->tenant_id)
                ->where('source_type', 'Quotation')
                ->where('source_id', $quotation->id)
                ->with('assignee:id,name,email')
                ->latest('id')
                ->get(),
            'alerts' => Alert::where('tenant_id', $quotation->tenant_id)
                ->where('source_type', 'Quotation')
                ->where('source_id', $quotation->id)
                ->latest('id')
                ->get(),
            'related_documents' => array_merge(
                $lead ? [[
                    'type' => 'lead',
                    'label' => 'Khách hàng tiềm năng',
                    'code' => $lead->code,
                    'status' => $lead->status,
                    'path' => '/leads/'.$lead->id,
                ]] : [],
                $salesOrders->map(fn (SalesOrder $order) => [
                    'type' => 'salesOrder',
                    'label' => 'Đơn bán',
                    'code' => $order->code,
                    'status' => $order->status,
                    'path' => '/sales-orders/'.$order->id,
                ])->all(),
            ),
            'timeline' => [
                ['label' => 'Tạo báo giá', 'status' => 'completed', 'at' => $quotation->created_at],
                ['label' => 'Kiểm tra biên lợi nhuận', 'status' => $quotation->margin_percent < 15 ? 'pending_approval' : 'ready', 'at' => $quotation->updated_at],
                ['label' => 'Trạng thái báo giá', 'status' => $quotation->status, 'at' => $quotation->updated_at],
            ],
        ]]);
    }

    public function duplicate(Request $request, Quotation $quotation): JsonResponse
    {
        abort_if($quotation->tenant_id !== $request->user()->tenant_id, 404);
        abort_if(! DataScope::owned(Quotation::whereKey($quotation->id), $request->user(), 'sales_owner_id', 'salesOwner')->exists(), 404);

        $quotation->load('items');
        $copy = $this->quotations->create($request->user(), [
            'customer_id' => $quotation->customer_id,
            'duplicated_from_id' => $quotation->id,
            'valid_until' => optional($quotation->valid_until)->toDateString(),
            'payment_terms' => $quotation->payment_terms,
            'delivery_terms' => $quotation->delivery_terms,
            'note' => $quotation->note,
            'items' => $quotation->items->map(fn ($item): array => [
                'sku_id' => $item->sku_id,
                'name' => $item->name,
                'unit' => $item->unit,
                'quantity' => (float) $item->quantity,
                'unit_price' => (float) $item->unit_price,
                'vat_rate' => (float) ($item->vat_rate ?? 0),
            ])->all(),
        ]);

        return response()->json(['data' => $copy], 201);
    }

    public function exportWord(Request $request, Quotation $quotation): BinaryFileResponse|JsonResponse
    {
        abort_if($quotation->tenant_id !== $request->user()->tenant_id, 404);
        abort_if(! DataScope::owned(Quotation::whereKey($quotation->id), $request->user(), 'sales_owner_id', 'salesOwner')->exists(), 404);

        $templateId = $request->query('template_id');
        $templateQuery = PrintTemplate::where('tenant_id', $request->user()->tenant_id)
            ->where('module', 'quotation')
            ->where('status', 'active');

        if ($templateId && $templateId !== 'default') {
            $templateQuery->whereKey($templateId);
        } else {
            $templateQuery->orderByDesc('is_default')->latest('id');
        }

        $template = $templateQuery->first();

        if (! $template || ! $template->file_path) {
            return response()->json([
                'message' => 'Mẫu in này chưa có file Word gốc để trộn dữ liệu.',
            ], 422);
        }

        $quotation->load([
            'customer:id,code,name,contact_name,phone,email,address,billing_address,tax_code,legal_representative,representative_position,payment_terms,bank_name,bank_account_no,bank_account_name',
            'items.sku:id,sku_code,name,unit',
            'salesOwner:id,name,email',
        ]);

        $path = $this->docxMerge->merge($quotation, $template);
        $fileName = $this->safeFileName($quotation->code ?: 'bao-gia').'.docx';

        return response()->download($path, $fileName, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ])->deleteFileAfterSend(true);
    }

    public function approve(Request $request, Quotation $quotation): JsonResponse
    {
        abort_if($quotation->tenant_id !== $request->user()->tenant_id, 404);
        $data = $request->validate([
            'status' => ['required', Rule::in(['approved', 'rejected'])],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $quotation = $this->quotations->approve($quotation, $request->user(), $data['status'], $data['reason'] ?? null);

        return response()->json(['data' => $quotation]);
    }

    private function safeFileName(string $value): string
    {
        $name = preg_replace('/[^A-Za-z0-9\-_]+/', '-', $value) ?: 'bao-gia';

        return trim($name, '-') ?: 'bao-gia';
    }

    /** @return array<string, mixed> */
    private function validateWritableQuotation(Request $request): array
    {
        $tenantId = $request->user()->tenant_id;

        return $request->validate([
            'customer_id' => ['required', Rule::exists('customers', 'id')->where('tenant_id', $tenantId)],
            'lead_id' => ['nullable', Rule::exists('leads', 'id')->where('tenant_id', $tenantId)],
            'valid_until' => ['nullable', 'date'],
            'payment_terms' => ['nullable', 'string', 'max:255'],
            'delivery_terms' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.sku_id' => ['required', Rule::exists('skus', 'id')->where('tenant_id', $tenantId)],
            'items.*.quantity' => ['required', 'numeric', 'min:0.001'],
            'items.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            'items.*.vat_rate' => ['nullable', 'numeric', Rule::in([0, 5, 8, 10])],
        ]);
    }

}
