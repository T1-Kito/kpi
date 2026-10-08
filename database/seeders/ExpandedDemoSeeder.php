<?php

namespace Database\Seeders;

use App\Models\{Customer, CustomerContact, Lead, Product, Sku, Supplier, Task, Tenant, User, Quotation, PurchaseRequest, InventoryBalance, Warehouse};
use App\Support\QuotationService;
use App\Services\Procurement\ProcurementService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/** Additive, repeatable demo fixtures. Never use migrate:fresh to run this. */
class ExpandedDemoSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            // Bootstrap the existing demo only on an empty business database.
            // Preserve credentials, account attributes, roles and tenant settings.
            if (! Customer::exists() && ! Quotation::exists() && ! PurchaseRequest::exists()) {
                $users = User::all()->map(fn ($u) => [$u->getAttributes(), $u->roles()->pluck('roles.id')->all()]);
                $tenants = Tenant::all()->map->getAttributes();
                $this->call(DatabaseSeeder::class);
                foreach ($tenants as $attributes) DB::table('tenants')->where('id', $attributes['id'])->update($attributes);
                foreach ($users as [$attributes, $roles]) {
                    DB::table('users')->where('id', $attributes['id'])->update($attributes);
                    User::findOrFail($attributes['id'])->roles()->sync($roles);
                }
            }
            $actor = User::where('email', 'admin@vk-kpi.local')->firstOrFail();
            $tenantId = $actor->tenant_id;
            $sales = User::where('tenant_id', $tenantId)->where('email', 'sales@vk-kpi.local')->first() ?? $actor;
            $warehouse = Warehouse::where('tenant_id', $tenantId)->where('status', 'active')->first();
            $products = ['Camera IP 4MP', 'Đầu ghi 8 kênh', 'Máy chấm công', 'Khóa cửa thông minh', 'Máy quét mã vạch', 'Giấy in nhiệt', 'Switch PoE 8 cổng', 'Ổ cứng giám sát', 'Bộ nguồn 12V', 'Cáp mạng CAT6', 'Máy in tem', 'Mực in ribbon'];
            $skus = collect();
            foreach ($products as $i => $name) {
                $code = sprintf('DEMO-%03d', $i + 1);
                $product = Product::firstOrCreate(['tenant_id' => $tenantId, 'code' => 'PROD-'.$code], ['name' => $name.' (mẫu)', 'status' => 'active', 'description' => 'Dữ liệu mẫu để thử CRM, không dùng giao dịch thật.', 'technical_specs' => ['Bảo hành' => '12 tháng', 'Model' => $code]]);
                $sku = Sku::firstOrCreate(['tenant_id' => $tenantId, 'sku_code' => 'SKU-'.$code], ['product_id' => $product->id, 'name' => $name.' (mẫu)', 'unit' => 'cái', 'cost_price' => ($i+1)*150000, 'sale_price' => ($i+1)*220000, 'min_stock' => 5, 'max_stock' => 100, 'status' => 'active']);
                $skus->push($sku);
                if ($warehouse) InventoryBalance::firstOrCreate(['tenant_id' => $tenantId, 'warehouse_id' => $warehouse->id, 'sku_id' => $sku->id], ['on_hand' => 20 + $i * 3, 'reserved' => 0, 'available' => 20 + $i * 3]);
            }
            for ($i = 1; $i <= 8; $i++) Supplier::firstOrCreate(['tenant_id' => $tenantId, 'code' => sprintf('SUP-DEMO-%03d', $i)], ['name' => 'Nhà cung cấp mẫu '.$i, 'contact_name' => 'Đầu mối NCC '.$i, 'phone' => sprintf('0288000%04d', $i), 'email' => 'supplier'.$i.'@example.com', 'address' => 'TP. Hồ Chí Minh', 'status' => 'active']);
            $names = ['An Phát', 'Bình Minh', 'Đại Việt', 'Hưng Thịnh', 'Nam Long', 'Phú Gia', 'Tân Thành', 'Việt Anh'];
            for ($i = 1; $i <= 40; $i++) {
                $code = sprintf('CUS-DEMO-%03d', $i);
                $customer = Customer::firstOrCreate(['tenant_id' => $tenantId, 'code' => $code], ['name' => 'Công ty '.$names[($i-1)%8].' '.$i.' (mẫu)', 'customer_type' => 'organization', 'status' => 'active', 'sales_owner_id' => $sales->id, 'address' => $i.' Nguyễn Văn Linh, TP. Hồ Chí Minh', 'credit_limit' => 50000000]);
                for ($j = 1; $j <= 2; $j++) CustomerContact::firstOrCreate(['customer_id' => $customer->id, 'email' => 'contact'.$i.'-'.$j.'@example.com'], ['name' => 'Liên hệ mẫu '.$i.'-'.$j, 'phone' => sprintf('090%05d%02d', $i, $j), 'position' => $j === 1 ? 'Trưởng phòng mua hàng' : 'Kế toán', 'is_primary' => $j === 1]);
                Lead::firstOrCreate(['tenant_id' => $tenantId, 'code' => sprintf('LEAD-DEMO-%03d', $i)], ['name' => 'Khách tiềm năng mẫu '.$i, 'phone' => sprintf('091%07d', $i), 'email' => 'lead'.$i.'@example.com', 'source' => 'website', 'campaign_code' => 'DEMO-CRM', 'assigned_to' => $i%4 ? $sales->id : null, 'status' => $i%4 ? 'assigned' : 'new']);
                $marker = '[DEMO-CRM-'.$i.']';
                if ($i <= 30 && ! Quotation::where('tenant_id', $tenantId)->where('note', $marker)->exists()) {
                    $sku = $skus[($i-1)%$skus->count()];
                    app(QuotationService::class)->create($sales, ['customer_id' => $customer->id, 'note' => $marker, 'valid_until' => now()->addDays(15)->toDateString(), 'items' => [['sku_id' => $sku->id, 'quantity' => 1+$i%10, 'unit_price' => $sku->sale_price, 'vat_rate' => 8]]]);
                }
                if ($i <= 20 && ! PurchaseRequest::where('tenant_id', $tenantId)->where('reason', $marker)->exists()) app(ProcurementService::class)->createManualPr($actor, [['sku_id' => $skus[($i-1)%$skus->count()]->id, 'quantity' => 5+$i]], $marker);
                Task::firstOrCreate(['tenant_id' => $tenantId, 'code' => sprintf('TASK-DEMO-%03d', $i)], ['title' => 'Chăm sóc khách hàng mẫu '.$i, 'description' => 'Dữ liệu mẫu: gọi xác nhận nhu cầu và chuẩn bị báo giá.', 'module' => 'sales', 'task_type' => 'manual', 'priority' => $i%3 === 0 ? 'high' : 'normal', 'source_type' => 'Customer', 'source_id' => $customer->id, 'assignee_id' => $sales->id, 'created_by' => $actor->id, 'due_at' => now()->addDays(($i%12)-4), 'status' => 'new']);
            }
        });
    }
}
