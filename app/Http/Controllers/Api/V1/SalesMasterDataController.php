<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\SalesOpportunitySource;
use App\Models\SalesPaymentTerm;
use App\Models\SalesPipelineStage;
use App\Models\SalesPriceBook;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SalesMasterDataController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;
        return response()->json(['data' => [
            'pipeline_stages' => SalesPipelineStage::where('tenant_id', $tenantId)->orderBy('sort_order')->get(),
            'opportunity_sources' => SalesOpportunitySource::where('tenant_id', $tenantId)->orderBy('name')->get(),
            'price_books' => SalesPriceBook::where('tenant_id', $tenantId)->orderByDesc('is_default')->orderBy('name')->get(),
            'payment_terms' => SalesPaymentTerm::where('tenant_id', $tenantId)->orderByDesc('is_default')->orderBy('due_days')->get(),
        ]]);
    }

    public function storeStage(Request $request): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;
        $data = $request->validate([
            'code' => ['required', 'alpha_dash', 'max:50', Rule::unique('sales_pipeline_stages')->where('tenant_id', $tenantId)],
            'name' => ['required', 'string', 'max:100'],
            'probability' => ['required', 'integer', 'between:0,100'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'type' => ['required', Rule::in(['open', 'won', 'lost'])],
            'is_active' => ['nullable', 'boolean'],
        ]);
        $stage = SalesPipelineStage::create($data + ['tenant_id' => $tenantId, 'sort_order' => $data['sort_order'] ?? 999, 'is_active' => $data['is_active'] ?? true]);
        return response()->json(['data' => $stage], 201);
    }

    public function updateStage(Request $request, SalesPipelineStage $stage): JsonResponse
    {
        abort_if($stage->tenant_id !== $request->user()->tenant_id, 404);
        $data = $request->validate(['name' => ['required', 'string', 'max:100'], 'probability' => ['required', 'integer', 'between:0,100'], 'sort_order' => ['required', 'integer', 'min:0'], 'type' => ['required', Rule::in(['open', 'won', 'lost'])], 'is_active' => ['required', 'boolean']]);
        $stage->update($data);
        return response()->json(['data' => $stage->refresh()]);
    }

    public function storeSource(Request $request): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;
        $data = $request->validate(['code' => ['required', 'alpha_dash', 'max:50', Rule::unique('sales_opportunity_sources')->where('tenant_id', $tenantId)], 'name' => ['required', 'string', 'max:100'], 'is_active' => ['nullable', 'boolean']]);
        return response()->json(['data' => SalesOpportunitySource::create($data + ['tenant_id' => $tenantId, 'is_active' => $data['is_active'] ?? true])], 201);
    }

    public function updateSource(Request $request, SalesOpportunitySource $source): JsonResponse
    {
        abort_if($source->tenant_id !== $request->user()->tenant_id, 404);
        $data = $request->validate(['name' => ['required', 'string', 'max:100'], 'is_active' => ['required', 'boolean']]);
        $source->update($data);
        return response()->json(['data' => $source->refresh()]);
    }

    public function storePriceBook(Request $request): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;
        $data = $request->validate(['code' => ['required', 'alpha_dash', 'max:50', Rule::unique('sales_price_books')->where('tenant_id', $tenantId)], 'name' => ['required', 'string', 'max:100'], 'discount_percent' => ['required', 'numeric', 'between:0,100'], 'effective_from' => ['nullable', 'date'], 'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'], 'is_default' => ['nullable', 'boolean'], 'is_active' => ['nullable', 'boolean']]);
        $this->validateDefaultStatus($data);
        $record = DB::transaction(function () use ($data, $tenantId) {
            DB::table('tenants')->where('id', $tenantId)->lockForUpdate()->first();
            if ($data['is_default'] ?? false) SalesPriceBook::where('tenant_id', $tenantId)->update(['is_default' => false]);
            return SalesPriceBook::create($data + ['tenant_id' => $tenantId, 'is_default' => false, 'is_active' => true]);
        });
        return response()->json(['data' => $record], 201);
    }

    public function updatePriceBook(Request $request, SalesPriceBook $priceBook): JsonResponse
    {
        abort_if($priceBook->tenant_id !== $request->user()->tenant_id, 404);
        $data = $request->validate(['name' => ['required', 'string', 'max:100'], 'discount_percent' => ['required', 'numeric', 'between:0,100'], 'effective_from' => ['nullable', 'date'], 'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'], 'is_default' => ['required', 'boolean'], 'is_active' => ['required', 'boolean']]);
        $this->validateDefaultStatus($data);
        DB::transaction(function () use ($data, $priceBook) {
            DB::table('tenants')->where('id', $priceBook->tenant_id)->lockForUpdate()->first();
            if ($data['is_default']) SalesPriceBook::where('tenant_id', $priceBook->tenant_id)->whereKeyNot($priceBook->id)->update(['is_default' => false]);
            $priceBook->update($data);
        });
        return response()->json(['data' => $priceBook->refresh()]);
    }

    public function storePaymentTerm(Request $request): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;
        $data = $request->validate(['code' => ['required', 'alpha_dash', 'max:50', Rule::unique('sales_payment_terms')->where('tenant_id', $tenantId)], 'name' => ['required', 'string', 'max:100'], 'due_days' => ['required', 'integer', 'min:0', 'max:3650'], 'is_default' => ['nullable', 'boolean'], 'is_active' => ['nullable', 'boolean']]);
        $this->validateDefaultStatus($data);
        $record = DB::transaction(function () use ($data, $tenantId) {
            DB::table('tenants')->where('id', $tenantId)->lockForUpdate()->first();
            if ($data['is_default'] ?? false) SalesPaymentTerm::where('tenant_id', $tenantId)->update(['is_default' => false]);
            return SalesPaymentTerm::create($data + ['tenant_id' => $tenantId, 'is_default' => false, 'is_active' => true]);
        });
        return response()->json(['data' => $record], 201);
    }

    public function updatePaymentTerm(Request $request, SalesPaymentTerm $paymentTerm): JsonResponse
    {
        abort_if($paymentTerm->tenant_id !== $request->user()->tenant_id, 404);
        $data = $request->validate(['name' => ['required', 'string', 'max:100'], 'due_days' => ['required', 'integer', 'min:0', 'max:3650'], 'is_default' => ['required', 'boolean'], 'is_active' => ['required', 'boolean']]);
        $this->validateDefaultStatus($data);
        DB::transaction(function () use ($data, $paymentTerm) {
            DB::table('tenants')->where('id', $paymentTerm->tenant_id)->lockForUpdate()->first();
            if ($data['is_default']) SalesPaymentTerm::where('tenant_id', $paymentTerm->tenant_id)->whereKeyNot($paymentTerm->id)->update(['is_default' => false]);
            $paymentTerm->update($data);
        });
        return response()->json(['data' => $paymentTerm->refresh()]);
    }

    private function validateDefaultStatus(array $data): void
    {
        if (($data['is_default'] ?? false) && ! ($data['is_active'] ?? true)) {
            throw ValidationException::withMessages(['is_default' => 'Danh mục ngừng dùng không thể được đặt mặc định.']);
        }
    }
}
