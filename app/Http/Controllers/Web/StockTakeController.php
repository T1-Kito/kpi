<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Sku;
use App\Models\StockTake;
use App\Models\Tenant;
use App\Models\Warehouse;
use Illuminate\View\View;

class StockTakeController extends Controller
{
    public function __invoke(): View
    {
        $tenant = Tenant::query()->where('status', 'active')->firstOrFail();

        return view('pages.stock-takes.index', [
            'stockTakes' => StockTake::query()
                ->where('tenant_id', $tenant->id)
                ->with(['warehouse:id,code,name', 'creator:id,name,email', 'confirmer:id,name,email', 'lines.sku:id,sku_code,name,unit'])
                ->withCount('lines')
                ->latest('id')
                ->get(),
            'warehouses' => Warehouse::query()->where('tenant_id', $tenant->id)->where('status', 'active')->orderBy('code')->get(['id', 'code', 'name']),
            'skus' => Sku::query()->where('tenant_id', $tenant->id)->where('status', 'active')->orderBy('sku_code')->get(['id', 'sku_code', 'name', 'unit']),
        ]);
    }
}
