<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\{Customer, Sku};
use App\Support\{AuditLogger, CatalogAccess};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class CatalogSharingController extends Controller
{
    public function update(Request $request, string $catalog, int $id, AuditLogger $audit)
    {
        abort_unless(in_array($catalog, ['customers', 'skus'], true), 404);
        $data = $request->validate(['is_shared' => ['required', 'boolean']]);
        return DB::transaction(function () use ($request, $catalog, $id, $data, $audit) {
            $model = $catalog === 'customers' ? Customer::class : Sku::class;
            $row = $model::withoutGlobalScope('catalog_visibility')->where('tenant_id', $request->user()->tenant_id)->whereKey($id)->lockForUpdate()->firstOrFail();
            abort_unless(CatalogAccess::mayShare($row, $request->user()), 403, 'Chỉ người tạo, Admin hoặc Chủ tịch được thay đổi dùng chung.');
            $before = (bool) $row->is_shared;
            $row->update(['is_shared' => (bool) $data['is_shared']]);
            if ($before !== (bool) $row->is_shared) $audit->record($catalog === 'customers' ? 'customer' : 'sku', $row->id, 'change_catalog_sharing', $request->user(), ['is_shared' => $before], ['is_shared' => $row->is_shared], $request);
            return response()->json(['data' => $row]);
        });
    }
}
