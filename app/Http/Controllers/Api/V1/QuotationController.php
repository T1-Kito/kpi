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
        $user = $request->user();
        $assigned = Approval::where('tenant_id', $user->tenant_id)->where('source_type', 'Quotation')->where('approver_id', $user->id)->select('source_id');
        $query->where(function ($scope) use ($user, $assigned) {
            if ($user->hasPermission('sales.quotation.view')) {
                $scope->whereIn('id', DataScope::owned(Quotation::where('tenant_id', $user->tenant_id)->select('id'), $user, 'sales_owner_id', 'salesOwner'));
                if ($user->hasPermission('sales.margin.approve')) $scope->orWhereIn('id', $assigned);
            } else $scope->whereIn('id', $assigned);
        });

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        $quotations = $query->latest('id')->paginate($pageSize);
        $quotations->getCollection()->each(function ($quote) use ($user) {
            $quote->setAttribute('can_approve', $user->hasPermission('sales.margin.approve') && collect($quote->approval_steps ?? [])->contains(fn ($step) => $step['status'] === 'pending' && (int) $step['user_id'] === (int) $user->id));
        });

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

    public function destroy(Request $request, Quotation $quotation): JsonResponse
    {
        abort_if($quotation->tenant_id !== $request->user()->tenant_id, 404);
        abort_if(! DataScope::owned(Quotation::whereKey($quotation->id), $request->user(), 'sales_owner_id', 'salesOwner')->exists(), 404);
        abort_unless($quotation->status === 'draft', 422, 'Chỉ được xóa báo giá nháp.');
        abort_if($quotation->salesOrders()->exists(), 422, 'Báo giá đã có đơn hàng, không thể xóa.');
        abort_if(\App\Models\Contract::where('tenant_id', $quotation->tenant_id)->where('quotation_id', $quotation->id)->exists(), 422, 'Báo giá đã có hợp đồng, không thể xóa.');
        abort_if(Task::where('tenant_id', $quotation->tenant_id)->where('source_type', 'Quotation')->where('source_id', $quotation->id)->exists(), 422, 'Báo giá đã có công việc liên quan, không thể xóa.');
        abort_if(Alert::where('tenant_id', $quotation->tenant_id)->where('source_type', 'Quotation')->where('source_id', $quotation->id)->exists(), 422, 'Báo giá đã có cảnh báo liên quan, không thể xóa.');

        \Illuminate\Support\Facades\DB::transaction(function () use ($quotation, $request) {
            $this->quotations->deleteDraft($quotation, $request->user());
        });

        return response()->json(['message' => 'Đã xóa báo giá nháp.']);
    }

    public function show(Request $request, Quotation $quotation): JsonResponse
    {
        abort_if($quotation->tenant_id !== $request->user()->tenant_id, 404);
        $this->guardReadable($request, $quotation);

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

        if ($quotation->document_snapshot) $quotation->setRelation('customer', new \App\Models\Customer($quotation->document_snapshot['customer']));
        $rootId = $quotation->revision_root_id ?: $quotation->id;
        return response()->json(['data' => [
            ...$quotation->toArray(),
            'issue' => \App\Models\QuotationIssue::where('quotation_id', $quotation->id)->first(),
            'is_expired' => $quotation->valid_until && $quotation->valid_until->endOfDay()->isPast(),
            'revisions' => Quotation::where('tenant_id', $quotation->tenant_id)->where(fn ($query) => $query->whereKey($rootId)->orWhere('revision_root_id', $rootId))->orderBy('revision_number')->get(['id', 'code', 'revision_number', 'status', 'created_at']),
            'can_approve' => $request->user()->hasPermission('sales.margin.approve') && collect($quotation->approval_steps ?? [])->contains(fn ($step) => $step['status'] === 'pending' && (int) $step['user_id'] === (int) $request->user()->id),
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
            'timeline' => $quotation->workflow_version == 2 ? \App\Models\AuditLog::where('tenant_id', $quotation->tenant_id)->where('entity_type', 'quotation')->where('entity_id', $quotation->id)->orderBy('id')->get()->map(fn ($entry) => [
                'label' => match ($entry->action) {
                    'create_quotation' => 'Tạo báo giá', 'update_quotation' => 'Cập nhật nháp', 'submit_quotation' => 'Gửi duyệt và khóa nội dung',
                    'approved_quotation' => 'Ghi nhận lượt duyệt', 'rejected_quotation' => 'Từ chối báo giá', 'change_requested_quotation' => 'Yêu cầu sửa',
                    'issue_quotation' => 'Lưu bản phát hành', 'customer_quotation_response' => 'Ghi nhận phản hồi khách', 'revise_quotation' => 'Tạo phiên bản mới', default => $entry->action,
                }, 'status' => 'completed', 'at' => $entry->created_at,
            ])->all() : [
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
            'deal_id' => $quotation->deal_id,
            'lead_id' => $quotation->lead_id,
            'price_book_id' => $quotation->price_book_id,
            'payment_term_id' => $quotation->payment_term_id,
            'discount_percent' => $quotation->discount_percent,
            'valid_until' => optional($quotation->valid_until)->toDateString(),
            'payment_terms' => $quotation->payment_terms,
            'delivery_terms' => $quotation->delivery_terms,
            'note' => $quotation->note,
            'items' => $quotation->items->map(fn ($item): array => [
                'sku_id' => $item->sku_id,
                'name' => $item->name,
                'unit' => $item->unit,
                'quantity' => (float) $item->quantity,
                'unit_price' => (float) ($item->list_unit_price ?? $item->unit_price),
                'vat_rate' => (float) ($item->vat_rate ?? 0),
            ])->all(),
        ]);

        return response()->json(['data' => $copy], 201);
    }

    public function exportWord(Request $request, Quotation $quotation): BinaryFileResponse|JsonResponse
    {
        abort_if($quotation->tenant_id !== $request->user()->tenant_id, 404);
        $this->guardReadable($request, $quotation);
        if (\App\Models\QuotationIssue::where('quotation_id', $quotation->id)->exists()) return $this->issuedFile($request, $quotation);

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

        if ($quotation->document_snapshot) $quotation->setRelation('customer', new \App\Models\Customer($quotation->document_snapshot['customer']));
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
            'status' => ['required', Rule::in(['approved', 'rejected', 'change_requested'])],
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

    public function workflow(Request $request, Quotation $quotation, string $action): JsonResponse
    {
        abort_if($quotation->tenant_id !== $request->user()->tenant_id, 404);
        abort_unless(DataScope::owned(Quotation::whereKey($quotation->id), $request->user(), 'sales_owner_id', 'salesOwner')->exists(), 404);
        $workflow = app(\App\Support\QuotationWorkflow::class);
        $actor = $request->user();
        $result = match ($action) {
            'submit' => $workflow->submit($quotation, $actor),
            'revise' => $workflow->revise($quotation, $actor),
            'issue' => $workflow->issue($quotation, $actor, $request->validate([
                'recipient' => ['required', 'string', 'max:255'], 'channel' => ['required', Rule::in(['email', 'zalo', 'direct', 'other'])],
                'delivery_evidence' => ['required', 'string', 'max:2000'],
            ])),
            'customer-response' => $workflow->customerDecision($quotation, $actor, $request->validate([
                'decision' => ['required', Rule::in(['accepted', 'rejected'])], 'evidence' => ['required', 'string', 'max:2000'],
            ])),
            default => abort(404),
        };
        return response()->json(['data' => $result]);
    }

    public function issuedFile(Request $request, Quotation $quotation): BinaryFileResponse
    {
        abort_if($quotation->tenant_id !== $request->user()->tenant_id, 404);
        $this->guardReadable($request, $quotation);
        $issue = \App\Models\QuotationIssue::where('quotation_id', $quotation->id)->firstOrFail();
        $path = \Illuminate\Support\Facades\Storage::disk('local')->path($issue->file_path);
        abort_unless(is_file($path), 404, 'Không tìm thấy bản lưu phát hành.');
        abort_unless(hash_file('sha256', $path) === $issue->file_hash, 409, 'File lưu không còn khớp mã kiểm tra.');
        return response()->download($path, $this->safeFileName($quotation->code).'-v'.$quotation->revision_number.'.docx');
    }

    private function guardReadable(Request $request, Quotation $quotation): void
    {
        $user = $request->user();
        $owned = $user->hasPermission('sales.quotation.view') && DataScope::owned(Quotation::whereKey($quotation->id), $user, 'sales_owner_id', 'salesOwner')->exists();
        $assigned = $user->hasPermission('sales.margin.approve') && Approval::where('tenant_id', $user->tenant_id)->where('source_type', 'Quotation')->where('source_id', $quotation->id)->where('approver_id', $user->id)->exists();
        abort_unless($owned || $assigned, 404);
    }

    /** @return array<string, mixed> */
    private function validateWritableQuotation(Request $request): array
    {
        $tenantId = $request->user()->tenant_id;

        if ($request->filled('deal_id')) {
            $deal = \App\Models\Deal::where('tenant_id', $tenantId)->findOrFail($request->input('deal_id'));
            abort_unless(DataScope::owned(\App\Models\Deal::whereKey($deal->id), $request->user(), 'owner_id', 'owner')->exists(), 404);
            abort_unless($deal->customer_id, 422, 'Cơ hội chưa có khách hàng.');
            abort_if($request->filled('customer_id') && (int) $request->input('customer_id') !== $deal->customer_id, 422, 'Khách hàng của báo giá phải khớp cơ hội.');
            abort_if($request->filled('lead_id') && (int) $request->input('lead_id') !== $deal->lead_id, 422, 'Nguồn khách của báo giá phải khớp cơ hội.');
            $request->merge(['customer_id' => $deal->customer_id, 'lead_id' => $deal->lead_id]);
            if (! $request->has('items')) {
                $request->merge(['items' => $deal->items->map(fn ($item) => $item->only(['sku_id', 'quantity', 'unit_price']))->all()]);
            }
        }

        return $request->validate([
            'customer_id' => ['required', Rule::exists('customers', 'id')->where('tenant_id', $tenantId)],
            'lead_id' => ['nullable', Rule::exists('leads', 'id')->where('tenant_id', $tenantId)],
            'deal_id' => ['nullable', Rule::exists('deals', 'id')->where('tenant_id', $tenantId)],
            'price_book_id' => ['nullable', Rule::exists('sales_price_books', 'id')->where('tenant_id', $tenantId)->where('is_active', true)],
            'payment_term_id' => ['nullable', Rule::exists('sales_payment_terms', 'id')->where('tenant_id', $tenantId)->where('is_active', true)],
            'valid_until' => ['nullable', 'date'],
            'discount_percent' => ['nullable', 'numeric', 'min:0', 'max:99.99'],
            'payment_terms' => ['nullable', 'string', 'max:255'],
            'delivery_terms' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.sku_id' => ['required', Rule::exists('skus', 'id')->where('tenant_id', $tenantId)->where('status', 'active')],
            'items.*.quantity' => ['required', 'numeric', 'min:0.001', 'max:100000000', 'decimal:0,3'],
            'items.*.unit_price' => ['nullable', 'numeric', 'min:0', 'max:1000000000000', 'decimal:0,2'],
            'items.*.vat_rate' => ['nullable', 'numeric', Rule::in([0, 5, 8, 10])],
        ]);
    }

}
