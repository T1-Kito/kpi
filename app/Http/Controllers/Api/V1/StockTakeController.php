<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\InventoryBalance;
use App\Models\InventoryTransaction;
use App\Models\Sku;
use App\Models\StockTake;
use App\Support\AuditLogger;
use App\Support\CodeGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class StockTakeController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly CodeGenerator $codes,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $pageSize = min((int) $request->query('page_size', 20), 100);
        $query = StockTake::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->with(['warehouse:id,code,name', 'creator:id,name,email', 'confirmer:id,name,email'])
            ->withCount('lines')
            ->latest('id');

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        $stockTakes = $query->paginate($pageSize);

        return response()->json([
            'data' => $stockTakes->items(),
            'meta' => [
                'page' => $stockTakes->currentPage(),
                'page_size' => $stockTakes->perPage(),
                'total' => $stockTakes->total(),
            ],
        ]);
    }

    public function show(Request $request, StockTake $stockTake): JsonResponse
    {
        abort_if($stockTake->tenant_id !== $request->user()->tenant_id, 404);

        return response()->json([
            'data' => $stockTake->load([
                'warehouse:id,code,name',
                'creator:id,name,email',
                'confirmer:id,name,email',
                'lines.sku:id,sku_code,name,unit',
            ]),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;
        $data = $request->validate([
            'warehouse_id' => ['required', Rule::exists('warehouses', 'id')->where('tenant_id', $tenantId)],
            'counted_at' => ['required', 'date'],
            'note' => ['nullable', 'string', 'max:1000'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.sku_id' => ['required', Rule::exists('skus', 'id')->where('tenant_id', $tenantId)],
            'lines.*.lot_no' => ['nullable', 'string', 'max:100'],
            'lines.*.counted_quantity' => ['required', 'numeric', 'min:0'],
            'lines.*.note' => ['nullable', 'string', 'max:500'],
        ]);

        $stockTake = DB::transaction(function () use ($data, $tenantId, $request) {
            $stockTake = StockTake::create([
                'tenant_id' => $tenantId,
                'code' => $this->nextCode($tenantId),
                'warehouse_id' => $data['warehouse_id'],
                'counted_at' => $data['counted_at'],
                'status' => 'draft',
                'note' => $data['note'] ?? null,
                'created_by' => $request->user()->id,
            ]);

            foreach ($data['lines'] as $line) {
                $systemQuantity = $this->systemQuantity($tenantId, (int) $data['warehouse_id'], (int) $line['sku_id'], $line['lot_no'] ?? null);
                $countedQuantity = (float) $line['counted_quantity'];
                $stockTake->lines()->create([
                    'sku_id' => $line['sku_id'],
                    'lot_no' => $line['lot_no'] ?? null,
                    'system_quantity' => $systemQuantity,
                    'counted_quantity' => $countedQuantity,
                    'difference_quantity' => $countedQuantity - $systemQuantity,
                    'note' => $line['note'] ?? null,
                ]);
            }

            return $stockTake;
        });

        $this->audit->record('stock_take', $stockTake->id, 'create_stock_take', $request->user(), null, $stockTake->toArray(), $request);

        return response()->json(['data' => $stockTake->load(['warehouse:id,code,name', 'lines.sku:id,sku_code,name,unit'])], 201);
    }

    public function confirm(Request $request, StockTake $stockTake): JsonResponse
    {
        abort_if($stockTake->tenant_id !== $request->user()->tenant_id, 404);
        abort_if($stockTake->status !== 'draft', 422, 'Chỉ phiếu nháp mới được xác nhận.');

        DB::transaction(function () use ($stockTake, $request) {
            $stockTake->load('lines');

            foreach ($stockTake->lines as $line) {
                $balance = $this->findOrCreateBalance($stockTake->tenant_id, $stockTake->warehouse_id, $line->sku_id, $line->lot_no);
                $reserved = (float) $balance->reserved;
                $balance->update([
                    'on_hand' => $line->counted_quantity,
                    'available' => max(0, (float) $line->counted_quantity - $reserved),
                ]);

                if ((float) $line->difference_quantity !== 0.0) {
                    InventoryTransaction::create([
                        'tenant_id' => $stockTake->tenant_id,
                        'warehouse_id' => $stockTake->warehouse_id,
                        'sku_id' => $line->sku_id,
                        'lot_no' => $line->lot_no,
                        'transaction_type' => 'stock_take_adjustment',
                        'quantity' => $line->difference_quantity,
                        'source_type' => 'stock_take',
                        'source_id' => $stockTake->id,
                        'created_by' => $request->user()->id,
                    ]);
                }
            }

            $stockTake->update([
                'status' => 'confirmed',
                'confirmed_by' => $request->user()->id,
                'confirmed_at' => now(),
            ]);
        });

        $this->audit->record('stock_take', $stockTake->id, 'confirm_stock_take', $request->user(), ['status' => 'draft'], ['status' => 'confirmed'], $request);

        return response()->json(['data' => $stockTake->refresh()->load(['warehouse:id,code,name', 'lines.sku:id,sku_code,name,unit'])]);
    }

    private function nextCode(int $tenantId): string
    {
        return $this->codes->next('stock_takes', 'code', 'ST-', fn ($query) => $query->where('tenant_id', $tenantId));
    }

    private function systemQuantity(int $tenantId, int $warehouseId, int $skuId, ?string $lotNo): float
    {
        return (float) InventoryBalance::query()
            ->where('tenant_id', $tenantId)
            ->where('warehouse_id', $warehouseId)
            ->where('sku_id', $skuId)
            ->when($lotNo, fn ($query) => $query->where('lot_no', $lotNo), fn ($query) => $query->whereNull('lot_no'))
            ->sum('on_hand');
    }

    private function findOrCreateBalance(int $tenantId, int $warehouseId, int $skuId, ?string $lotNo): InventoryBalance
    {
        $balance = InventoryBalance::query()
            ->where('tenant_id', $tenantId)
            ->where('warehouse_id', $warehouseId)
            ->where('sku_id', $skuId)
            ->when($lotNo, fn ($query) => $query->where('lot_no', $lotNo), fn ($query) => $query->whereNull('lot_no'))
            ->first();

        if ($balance) {
            return $balance;
        }

        return InventoryBalance::create([
            'tenant_id' => $tenantId,
            'warehouse_id' => $warehouseId,
            'sku_id' => $skuId,
            'lot_no' => $lotNo,
            'on_hand' => 0,
            'reserved' => 0,
            'available' => 0,
        ]);
    }
}
