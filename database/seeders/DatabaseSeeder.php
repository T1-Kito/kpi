<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\Customer;
use App\Models\InventoryBalance;
use App\Models\KpiDefinition;
use App\Models\Permission;
use App\Models\Position;
use App\Models\Product;
use App\Models\Role;
use App\Models\Sku;
use App\Models\Alert;
use App\Models\Approval;
use App\Models\SlaPolicy;
use App\Models\Supplier;
use App\Models\Task;
use App\Models\Tenant;
use App\Models\User;
use App\Models\VkNotification;
use App\Models\Warehouse;
use App\Models\Lead;
use App\Models\Quotation;
use App\Models\SalesOrder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::updateOrCreate(
            ['code' => 'VK-KPI'],
            ['name' => 'VK KPI Demo Company', 'status' => 'active'],
        );

        foreach ([
            ['KPI-OVERALL', 'KPI tổng công ty', 'system', 'Điểm tổng hợp', 'điểm', 'increase', 1],
            ['KPI-SALES', 'KPI kinh doanh', 'sales', 'Hiệu suất khách hàng tiềm năng, báo giá và đơn bán', 'điểm', 'increase', 1],
            ['KPI-INVENTORY', 'KPI kho vận', 'inventory', 'Tồn khả dụng, xuất nhập và thiếu tồn', 'điểm', 'increase', 1],
            ['KPI-PROCUREMENT', 'KPI mua hàng', 'procurement', 'Duyệt PR/PO và nhận hàng', 'điểm', 'increase', 1],
            ['KPI-WORKFLOW', 'KPI công việc', 'workflow', 'SLA công việc và cảnh báo', 'điểm', 'increase', 1],
        ] as [$code, $name, $source, $formula, $unit, $direction, $weight]) {
            KpiDefinition::updateOrCreate(
                ['tenant_id' => $tenant->id, 'code' => $code],
                [
                    'name' => $name,
                    'source_type' => $source,
                    'formula' => $formula,
                    'unit' => $unit,
                    'target_direction' => $direction,
                    'weight' => $weight,
                    'status' => 'active',
                ],
            );
        }

        $departments = collect([
            'DIR' => 'Ban giám đốc',
            'SALES' => 'Kinh doanh',
            'WH' => 'Kho',
            'PUR' => 'Mua hàng',
            'MKT' => 'Marketing',
            'FIN' => 'Tài chính',
        ])->mapWithKeys(fn (string $name, string $code) => [
            $code => Department::updateOrCreate(
                ['tenant_id' => $tenant->id, 'code' => $code],
                ['name' => $name],
            ),
        ]);

        $positions = collect([
            'DIR' => 'Giám đốc',
            'MGR' => 'Trưởng phòng',
            'STAFF' => 'Nhân viên',
        ])->mapWithKeys(fn (string $name, string $code) => [
            $code => Position::updateOrCreate(
                ['tenant_id' => $tenant->id, 'code' => $code],
                ['name' => $name],
            ),
        ]);

        $permissions = collect([
            'user.manage' => 'Quản lý người dùng',
            'role.manage' => 'Quản lý vai trò',
            'dashboard.executive.view' => 'Xem dashboard giám đốc',
            'dashboard.manager.view' => 'Xem dashboard trưởng phòng',
            'master.view' => 'Xem dữ liệu nền',
            'master.manage' => 'Quản lý dữ liệu nền',
            'task.view' => 'Xem công việc',
            'task.create' => 'Tạo công việc',
            'task.approve' => 'Duyệt công việc',
            'alert.view' => 'Xem cảnh báo',
            'sales.lead.manage' => 'Quản lý khách hàng tiềm năng',
            'sales.quotation.create' => 'Tạo báo giá',
            'sales.margin.approve' => 'Duyệt biên lợi nhuận thấp',
            'sales.order.view' => 'Xem đơn bán',
            'sales.order.create' => 'Tạo đơn bán',
            'sales.delivery.view' => 'Xem sổ giao hàng',
            'sales.delivery.confirm' => 'Xác nhận giao hàng',
            'finance.invoice.view' => 'Xem hóa đơn bán hàng',
            'finance.invoice.manage' => 'Phát hành hóa đơn bán hàng',
            'finance.payment.view' => 'Xem phiếu thu khách hàng',
            'finance.payment.record' => 'Ghi nhận thanh toán khách hàng',
            'inventory.receipt.confirm' => 'Xác nhận nhập kho',
            'inventory.issue.confirm' => 'Xác nhận xuất kho',
            'procurement.pr.approve' => 'Duyệt yêu cầu mua',
            'procurement.po.approve' => 'Duyệt đơn mua',
            'marketing.campaign.manage' => 'Quản lý chiến dịch marketing',
            'kpi.lock' => 'Khóa kỳ KPI',
            'audit.view' => 'Xem nhật ký hệ thống',
        ])->mapWithKeys(fn (string $name, string $code) => [
            $code => Permission::updateOrCreate(['code' => $code], ['name' => $name]),
        ]);

        $roleDefinitions = [
            'ROLE-ADMIN' => ['name' => 'Quản trị hệ thống', 'scope' => 'company', 'permissions' => $permissions->keys()->all()],
            'ROLE-DIR' => ['name' => 'Ban giám đốc', 'scope' => 'company', 'permissions' => ['dashboard.executive.view', 'dashboard.manager.view', 'master.view', 'task.view', 'alert.view', 'audit.view', 'kpi.lock', 'sales.order.view', 'sales.delivery.view', 'finance.invoice.view', 'finance.payment.view']],
            'ROLE-MGR' => ['name' => 'Trưởng phòng', 'scope' => 'department', 'permissions' => ['dashboard.manager.view', 'master.view', 'task.view', 'task.create', 'task.approve', 'alert.view', 'sales.margin.approve', 'procurement.pr.approve', 'sales.order.view']],
            'ROLE-SALES' => ['name' => 'Kinh doanh', 'scope' => 'own', 'permissions' => ['master.view', 'task.view', 'task.create', 'alert.view', 'sales.lead.manage', 'sales.quotation.create', 'sales.order.view', 'sales.order.create', 'sales.delivery.view', 'sales.delivery.confirm', 'finance.invoice.view', 'finance.payment.view']],
            'ROLE-WH' => ['name' => 'Kho', 'scope' => 'warehouse', 'permissions' => ['master.view', 'task.view', 'task.create', 'alert.view', 'inventory.receipt.confirm', 'inventory.issue.confirm']],
            'ROLE-PUR' => ['name' => 'Mua hàng', 'scope' => 'department', 'permissions' => ['master.view', 'task.view', 'task.create', 'alert.view', 'procurement.pr.approve', 'procurement.po.approve']],
            'ROLE-MKT' => ['name' => 'Marketing', 'scope' => 'department', 'permissions' => ['master.view', 'task.view', 'task.create', 'alert.view', 'marketing.campaign.manage']],
            'ROLE-HR' => ['name' => 'Nhân sự', 'scope' => 'department', 'permissions' => ['task.view', 'task.create', 'alert.view']],
            'ROLE-FIN' => ['name' => 'Tài chính', 'scope' => 'department', 'permissions' => ['master.view', 'task.view', 'task.create', 'alert.view', 'sales.order.view', 'sales.delivery.view', 'finance.invoice.view', 'finance.invoice.manage', 'finance.payment.view', 'finance.payment.record']],
        ];

        $roles = collect($roleDefinitions)->mapWithKeys(function (array $definition, string $code) use ($tenant, $permissions) {
            $role = Role::updateOrCreate(
                ['tenant_id' => $tenant->id, 'code' => $code],
                ['name' => $definition['name'], 'data_scope' => $definition['scope']],
            );
            $role->permissions()->sync(
                collect($definition['permissions'])
                    ->map(fn (string $permissionCode) => $permissions[$permissionCode]->id)
                    ->all(),
            );

            return [$code => $role];
        });

        $users = [
            ['Admin', 'admin@vk-kpi.local', 'ROLE-ADMIN', 'DIR', 'DIR'],
            ['Director', 'director@vk-kpi.local', 'ROLE-DIR', 'DIR', 'DIR'],
            ['Sales Demo', 'sales@vk-kpi.local', 'ROLE-SALES', 'SALES', 'STAFF'],
            ['Warehouse Demo', 'warehouse@vk-kpi.local', 'ROLE-WH', 'WH', 'STAFF'],
            ['Procurement Demo', 'procurement@vk-kpi.local', 'ROLE-PUR', 'PUR', 'STAFF'],
            ['Marketing Demo', 'marketing@vk-kpi.local', 'ROLE-MKT', 'MKT', 'STAFF'],
        ];

        foreach ($users as [$name, $email, $roleCode, $departmentCode, $positionCode]) {
            $user = User::updateOrCreate(
                ['email' => $email],
                [
                    'tenant_id' => $tenant->id,
                    'department_id' => $departments[$departmentCode]->id,
                    'position_id' => $positions[$positionCode]->id,
                    'name' => $name,
                    'password' => Hash::make('Admin@123'),
                    'is_active' => true,
                ],
            );

            $user->roles()->sync([$roles[$roleCode]->id]);
        }

        $adminUser = User::where('email', 'admin@vk-kpi.local')->first();
        $directorUser = User::where('email', 'director@vk-kpi.local')->first();
        $salesUser = User::where('email', 'sales@vk-kpi.local')->first();
        $warehouseUser = User::where('email', 'warehouse@vk-kpi.local')->first();

        $salesUser?->update(['manager_id' => $directorUser?->id]);

        Customer::updateOrCreate(
            ['tenant_id' => $tenant->id, 'code' => 'CUS-001'],
            [
                'name' => 'Công ty Minh An',
                'contact_name' => 'Anh Minh',
                'phone' => '0901000001',
                'email' => 'minhan@example.com',
                'credit_limit' => 200000000,
                'sales_owner_id' => $salesUser?->id,
                'status' => 'active',
            ],
        );
        Customer::updateOrCreate(
            ['tenant_id' => $tenant->id, 'code' => 'CUS-002'],
            [
                'name' => 'Công ty Hòa Phát Demo',
                'contact_name' => 'Chị Hòa',
                'phone' => '0901000002',
                'email' => 'hoaphat.demo@example.com',
                'credit_limit' => 500000000,
                'sales_owner_id' => $salesUser?->id,
                'status' => 'active',
            ],
        );

        Supplier::updateOrCreate(
            ['tenant_id' => $tenant->id, 'code' => 'SUP-001'],
            [
                'name' => 'Nhà cung cấp Ánh Dương',
                'phone' => '0902000001',
                'email' => 'sales@anhduong.example.com',
                'terms' => 'Thanh toán 30 ngày',
                'rating' => 4,
                'supplied_products' => 'Thiết bị văn phòng, vật tư kho',
                'status' => 'active',
            ],
        );

        $product = Product::updateOrCreate(
            ['tenant_id' => $tenant->id, 'code' => 'PROD-PRINTER'],
            ['name' => 'Máy in mã vạch', 'status' => 'active'],
        );

        $skuPrinter = Sku::updateOrCreate(
            ['tenant_id' => $tenant->id, 'sku_code' => 'SKU-PRN-001'],
            [
                'product_id' => $product->id,
                'name' => 'Máy in mã vạch VK-100',
                'barcode' => '893000000001',
                'unit' => 'pcs',
                'min_stock' => 5,
                'max_stock' => 50,
                'sale_price' => 3500000,
                'cost_price' => 2600000,
                'status' => 'active',
            ],
        );

        $skuRibbon = Sku::updateOrCreate(
            ['tenant_id' => $tenant->id, 'sku_code' => 'SKU-RBN-001'],
            [
                'product_id' => $product->id,
                'name' => 'Ribbon in tem 110x300',
                'barcode' => '893000000002',
                'unit' => 'roll',
                'min_stock' => 20,
                'max_stock' => 200,
                'sale_price' => 120000,
                'cost_price' => 75000,
                'status' => 'active',
            ],
        );

        $warehouse = Warehouse::updateOrCreate(
            ['tenant_id' => $tenant->id, 'code' => 'WH-HCM'],
            [
                'name' => 'Kho TP.HCM',
                'address' => 'Quận 7, TP.HCM',
                'manager_id' => $warehouseUser?->id,
                'status' => 'active',
            ],
        );

        $locationA1 = $warehouse->locations()->updateOrCreate(
            ['code' => 'A1'],
            ['name' => 'Kệ A1', 'status' => 'active'],
        );

        InventoryBalance::updateOrCreate(
            [
                'tenant_id' => $tenant->id,
                'warehouse_id' => $warehouse->id,
                'warehouse_location_id' => $locationA1->id,
                'sku_id' => $skuPrinter->id,
                'lot_no' => 'OPENING',
            ],
            ['on_hand' => 12, 'reserved' => 0, 'available' => 12],
        );

        InventoryBalance::updateOrCreate(
            [
                'tenant_id' => $tenant->id,
                'warehouse_id' => $warehouse->id,
                'warehouse_location_id' => $locationA1->id,
                'sku_id' => $skuRibbon->id,
                'lot_no' => 'OPENING',
            ],
            ['on_hand' => 80, 'reserved' => 0, 'available' => 80],
        );

        foreach ([
            ['general', 'manual', 'normal', 480, 60],
            ['sales', 'lead_follow_up', 'normal', 240, 30],
            ['sales', 'quotation_follow_up', 'normal', 240, 30],
            ['sales', 'margin_approval', 'high', 120, 20],
            ['sales', 'sales_order_confirmation', 'normal', 180, 30],
            ['sales', 'delivery_confirmation', 'high', 180, 30],
            ['inventory', 'inventory_check', 'normal', 240, 30],
            ['inventory', 'warehouse_issue', 'high', 180, 30],
            ['inventory', 'goods_receipt', 'high', 180, 30],
            ['procurement', 'purchase_request', 'normal', 720, 120],
            ['procurement', 'purchase_order_follow_up', 'normal', 480, 60],
            ['procurement', 'supplier_follow_up', 'normal', 480, 60],
            ['finance', 'invoice_issue', 'high', 240, 30],
            ['finance', 'payment_follow_up', 'normal', 1440, 240],
            ['finance', 'receivable_follow_up', 'normal', 1440, 240],
            ['kpi', 'kpi_review', 'normal', 480, 60],
            ['kpi', 'alert_resolution', 'high', 240, 30],
        ] as [$module, $taskType, $priority, $duration, $warning]) {
            SlaPolicy::updateOrCreate(
                ['tenant_id' => $tenant->id, 'module' => $module, 'task_type' => $taskType, 'priority' => $priority],
                ['duration_minutes' => $duration, 'warning_before_minutes' => $warning, 'escalation_rules' => ['manager' => true]],
            );
        }

        $task = Task::updateOrCreate(
            ['tenant_id' => $tenant->id, 'code' => 'TASK-DEMO-001'],
            [
                'title' => 'Follow-up khách hàng Minh An',
                'description' => 'Gọi lại khách hàng sau khi nhận thông tin khách hàng tiềm năng demo.',
                'task_type' => 'lead_follow_up',
                'priority' => 'normal',
                'source_type' => 'Customer',
                'source_id' => Customer::where('tenant_id', $tenant->id)->where('code', 'CUS-001')->value('id'),
                'assignee_id' => $salesUser?->id,
                'department_id' => $departments['SALES']->id,
                'due_at' => now()->addHours(4),
                'status' => 'new',
                'created_by' => $salesUser?->id,
            ],
        );

        $task->history()->updateOrCreate(
            ['task_id' => $task->id, 'to_status' => 'new', 'reason' => 'seed_demo'],
            ['from_status' => null, 'changed_by' => $salesUser?->id],
        );

        $adminTask = Task::updateOrCreate(
            ['tenant_id' => $tenant->id, 'code' => 'TASK-DEMO-ADM'],
            [
                'title' => 'Kiểm tra cấu hình RBAC và master data',
                'description' => 'Công việc demo cho quản trị sau khi đăng nhập bảng tổng quan.',
                'task_type' => 'manual',
                'priority' => 'normal',
                'source_type' => 'System',
                'source_id' => null,
                'assignee_id' => $adminUser?->id,
                'department_id' => $departments['DIR']->id,
                'due_at' => now()->addHours(8),
                'status' => 'new',
                'created_by' => $adminUser?->id,
            ],
        );

        $adminTask->history()->updateOrCreate(
            ['task_id' => $adminTask->id, 'to_status' => 'new', 'reason' => 'seed_demo'],
            ['from_status' => null, 'changed_by' => $adminUser?->id],
        );

        Alert::updateOrCreate(
            ['tenant_id' => $tenant->id, 'alert_type' => 'stock_shortage', 'source_type' => 'Sku', 'source_id' => $skuPrinter->id],
            [
                'recipient_id' => $warehouseUser?->id,
                'level' => 'warning',
                'title' => 'Theo dõi tồn kho máy in VK-100',
                'message' => 'Tồn kho demo đang đủ, alert này dùng để kiểm tra Alert Center.',
                'action_url' => '/inventory-balances',
                'status' => 'open',
            ],
        );

        VkNotification::updateOrCreate(
            ['tenant_id' => $tenant->id, 'recipient_id' => $salesUser?->id, 'source_type' => 'Task', 'source_id' => $task->id],
            [
                'title' => 'Bạn có task follow-up mới',
                'message' => 'Khách hàng tiềm năng demo đã được giao để kiểm tra dashboard công việc.',
                'action_url' => '/tasks/'.$task->id,
                'status' => 'unread',
                'read_at' => null,
            ],
        );

        $demoLead = Lead::updateOrCreate(
            ['tenant_id' => $tenant->id, 'code' => 'LEAD-DEMO-001'],
            [
                'name' => 'Khách hàng tiềm năng Minh An',
                'phone' => '0903000001',
                'email' => 'lead.minhan@example.com',
                'source' => 'Website',
                'campaign_code' => 'CMP-DEMO',
                'assigned_to' => $salesUser?->id,
                'customer_id' => Customer::where('tenant_id', $tenant->id)->where('code', 'CUS-001')->value('id'),
                'status' => 'assigned',
            ],
        );

        $quotation = Quotation::updateOrCreate(
            ['tenant_id' => $tenant->id, 'code' => 'QUO-DEMO-001'],
            [
                'customer_id' => Customer::where('tenant_id', $tenant->id)->where('code', 'CUS-001')->value('id'),
                'lead_id' => $demoLead->id,
                'sales_owner_id' => $salesUser?->id,
                'subtotal_amount' => 3500000,
                'tax_amount' => 0,
                'total_amount' => 3500000,
                'total_cost' => 2600000,
                'margin_percent' => 25.71,
                'status' => 'ready',
            ],
        );

        $quotation->items()->updateOrCreate(
            ['sku_id' => $skuPrinter->id],
            [
                'quantity' => 1,
                'unit_price' => 3500000,
                'unit_cost' => 2600000,
                'line_subtotal' => 3500000,
                'vat_rate' => 0,
                'vat_amount' => 0,
                'line_total' => 3500000,
                'line_cost' => 2600000,
            ],
        );

        $lowMarginQuote = Quotation::updateOrCreate(
            ['tenant_id' => $tenant->id, 'code' => 'QUO-DEMO-LOW'],
            [
                'customer_id' => Customer::where('tenant_id', $tenant->id)->where('code', 'CUS-002')->value('id'),
                'lead_id' => null,
                'sales_owner_id' => $salesUser?->id,
                'subtotal_amount' => 2700000,
                'tax_amount' => 0,
                'total_amount' => 2700000,
                'total_cost' => 2600000,
                'margin_percent' => 3.70,
                'status' => 'pending_approval',
            ],
        );

        $lowMarginQuote->items()->updateOrCreate(
            ['sku_id' => $skuPrinter->id],
            [
                'quantity' => 1,
                'unit_price' => 2700000,
                'unit_cost' => 2600000,
                'line_subtotal' => 2700000,
                'vat_rate' => 0,
                'vat_amount' => 0,
                'line_total' => 2700000,
                'line_cost' => 2600000,
            ],
        );

        Approval::updateOrCreate(
            ['tenant_id' => $tenant->id, 'source_type' => 'Quotation', 'source_id' => $lowMarginQuote->id],
            [
                'approver_id' => $directorUser?->id,
                'status' => 'pending',
                'reason' => 'Margin thấp hơn 15%',
                'decided_at' => null,
            ],
        );

        $salesOrder = SalesOrder::updateOrCreate(
            ['tenant_id' => $tenant->id, 'code' => 'SO-DEMO-001'],
            [
                'quotation_id' => $quotation->id,
                'customer_id' => $quotation->customer_id,
                'sales_owner_id' => $salesUser?->id,
                'subtotal_amount' => $quotation->subtotal_amount ?: $quotation->total_amount,
                'tax_amount' => $quotation->tax_amount ?: 0,
                'total_amount' => $quotation->total_amount,
                'stock_status' => 'unchecked',
                'status' => 'draft',
            ],
        );

        $salesOrder->items()->updateOrCreate(
            ['sku_id' => $skuPrinter->id],
            [
                'quantity' => 1,
                'unit_price' => 3500000,
                'line_subtotal' => 3500000,
                'vat_rate' => 0,
                'vat_amount' => 0,
                'line_total' => 3500000,
            ],
        );
    }
}
