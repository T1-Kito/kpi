<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\PurchaseRequest;
use App\Models\Sku;
use App\Models\Supplier;
use App\Models\SupplierQuotation;
use App\Models\Tenant;
use Illuminate\View\View;

class SupplierQuotationController extends Controller
{
    public function __invoke(): View
    {
        $tenant = Tenant::query()->where('status', 'active')->firstOrFail();

        return view('pages.supplier-quotations.index', [
            'supplierQuotations' => SupplierQuotation::query()
                ->where('tenant_id', $tenant->id)
                ->with(['supplier:id,code,name,phone,email', 'purchaseRequest:id,code,status,reason', 'creator:id,name,email', 'selector:id,name,email', 'lines.sku:id,sku_code,name,unit'])
                ->withCount('lines')
                ->latest('id')
                ->get(),
            'purchaseRequests' => PurchaseRequest::query()
                ->where('tenant_id', $tenant->id)
                ->whereIn('status', ['draft', 'approved'])
                ->latest('id')
                ->get(['id', 'code', 'status', 'reason']),
            'suppliers' => Supplier::query()
                ->where('tenant_id', $tenant->id)
                ->where('status', 'active')
                ->orderBy('name')
                ->get(['id', 'code', 'name']),
            'skus' => Sku::query()
                ->where('tenant_id', $tenant->id)
                ->where('status', 'active')
                ->orderBy('sku_code')
                ->get(['id', 'sku_code', 'name', 'unit']),
        ]);
    }
}
