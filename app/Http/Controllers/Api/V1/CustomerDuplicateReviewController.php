<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CustomerDuplicateReview;
use App\Support\AuditLogger;
use App\Support\CustomerDuplicateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CustomerDuplicateReviewController extends Controller
{
    public function __construct(private readonly CustomerDuplicateService $duplicates, private readonly AuditLogger $audit) {}

    public function index(Request $request): JsonResponse
    {
        $this->requireCompanyScope($request);
        $status = $request->validate(['status' => ['nullable', Rule::in(['open', 'merged', 'not_duplicate'])]])['status'] ?? 'open';
        $rows = CustomerDuplicateReview::query()->where('tenant_id', $request->user()->tenant_id)
            ->where('status', $status)
            ->with(['firstCustomer:id,code,name,customer_type,tax_code,phone,email,status,merged_into_id',
                'secondCustomer:id,code,name,customer_type,tax_code,phone,email,status,merged_into_id', 'reviewer:id,name'])
            ->latest('id')->paginate(30);
        return response()->json(['data' => $rows->items(), 'meta' => ['total' => $rows->total(), 'page' => $rows->currentPage(), 'page_size' => 30]]);
    }

    public function scan(Request $request): JsonResponse
    {
        $this->requireCompanyScope($request);
        $result = $this->duplicates->scan($request->user()->tenant_id);
        $this->audit->record('customer_duplicate_review', null, 'scan_customer_duplicates', $request->user(), null, $result, $request);
        return response()->json(['data' => $result]);
    }

    public function dismiss(Request $request, CustomerDuplicateReview $review): JsonResponse
    {
        $this->requireCompanyScope($request);
        abort_if($review->tenant_id !== $request->user()->tenant_id, 404);
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:2000']]);
        DB::transaction(function () use ($review, $request, $data) {
            $review = CustomerDuplicateReview::whereKey($review->id)->lockForUpdate()->firstOrFail();
            abort_unless($review->status === 'open', 409, 'Trường hợp này đã được xử lý.');
            $old = $review->toArray();
            $review->update(['status' => 'not_duplicate', 'reason' => $data['reason'], 'reviewed_by' => $request->user()->id, 'reviewed_at' => now()]);
            $this->audit->record('customer_duplicate_review', $review->id, 'dismiss_customer_duplicate', $request->user(), $old, $review->fresh()->toArray(), $request, $data['reason']);
        });
        return response()->json(['message' => 'Đã đánh dấu không trùng.']);
    }

    public function merge(Request $request, CustomerDuplicateReview $review): JsonResponse
    {
        $this->requireCompanyScope($request);
        abort_if($review->tenant_id !== $request->user()->tenant_id, 404);
        $data = $request->validate([
            'retained_customer_id' => ['required', 'integer'],
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
        ]);
        $customer = $this->duplicates->merge($review, $data['retained_customer_id'], $request->user(), $data['reason'], $this->audit, $request);
        return response()->json(['data' => $customer, 'message' => 'Đã gộp hồ sơ, giữ lại '.$customer->code.'.']);
    }

    private function requireCompanyScope(Request $request): void
    {
        abort_unless($request->user()->dataScope() === 'company', 403, 'Cần phạm vi dữ liệu toàn công ty để xét khách trùng.');
    }
}
