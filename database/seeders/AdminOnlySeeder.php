<?php

namespace Database\Seeders;

use App\Models\{Tenant, User, Role, Permission};
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\{DB, Hash};

class AdminOnlySeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            $tenant = Tenant::firstOrCreate(['code' => 'VK-KPI'], ['name' => 'VK Software', 'status' => 'active']);
            $permissions = collect([
            'user.manage' => 'Quản lý người dùng',
            'role.manage' => 'Quản lý vai trò',
            'dashboard.executive.view' => 'Xem dashboard giám đốc',
            'dashboard.manager.view' => 'Xem dashboard trưởng phòng',
            'master.view' => 'Xem dữ liệu nền',
            'master.manage' => 'Quản lý dữ liệu nền',
            'master.merge' => 'Xét và gộp khách hàng trùng',
            'task.view' => 'Xem công việc',
            'task.create' => 'Tạo công việc',
            'task.approve' => 'Duyệt công việc',
            'alert.view' => 'Xem cảnh báo',
            'sales.lead.manage' => 'Quản lý khách hàng tiềm năng',
            'sales.quotation.create' => 'Tạo báo giá',
            'sales.quotation.view' => 'Xem báo giá',
            'sales.quotation.edit' => 'Sửa báo giá',
            'sales.quotation.delete' => 'Xóa báo giá nháp',
            'sales.deal.manage' => 'Quản lý cơ hội bán hàng',
            'sales.contract.manage' => 'Quản lý hợp đồng',
            'sales.margin.approve' => 'Duyệt biên lợi nhuận thấp',
            'sales.order.view' => 'Xem đơn bán',
            'sales.order.create' => 'Tạo đơn bán',
            'sales.order.edit' => 'Sửa đơn hàng nháp',
            'sales.order.delete' => 'Xóa đơn hàng nháp',
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
            'service.ticket.view' => 'Xem ticket dịch vụ và bảo hành',
            'service.ticket.manage' => 'Quản lý ticket dịch vụ và bảo hành',
        ])->map(fn ($name, $code) => Permission::firstOrCreate(['code' => $code], ['name' => $name]));
            $role = Role::firstOrCreate(['tenant_id' => $tenant->id, 'code' => 'ROLE-ADMIN'], ['name' => 'Quản trị hệ thống', 'data_scope' => 'company']);
            $role->permissions()->syncWithoutDetaching($permissions->pluck('id')->all());
            // Reruns preserve the existing account and password.
            $user = User::firstOrCreate(['email' => 'admin@vk-kpi.local'], [
                'tenant_id' => $tenant->id, 'name' => 'Admin',
                'password' => Hash::make('Admin@123'), 'is_active' => true,
            ]);
            abort_unless($user->tenant_id === $tenant->id, 409, 'Tài khoản Admin đã thuộc công ty khác.');
            $user->roles()->syncWithoutDetaching([$role->id]);
        });
    }
}

