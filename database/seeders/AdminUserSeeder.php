<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\Permission;
use App\Models\Position;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::updateOrCreate(
            ['code' => 'VK-KPI'],
            ['name' => 'VK KPI Demo Company', 'status' => 'active'],
        );

        $department = Department::updateOrCreate(
            ['tenant_id' => $tenant->id, 'code' => 'DIR'],
            ['name' => 'Ban giám đốc'],
        );

        $position = Position::updateOrCreate(
            ['tenant_id' => $tenant->id, 'code' => 'DIR'],
            ['name' => 'Giám đốc'],
        );

        $role = Role::updateOrCreate(
            ['tenant_id' => $tenant->id, 'code' => 'ROLE-ADMIN'],
            ['name' => 'Quản trị hệ thống', 'data_scope' => 'company'],
        );

        $permissionIds = Permission::query()->pluck('id')->all();
        if ($permissionIds !== []) {
            $role->permissions()->sync($permissionIds);
        }

        $admin = User::updateOrCreate(
            ['email' => 'admin@vk-kpi.local'],
            [
                'tenant_id' => $tenant->id,
                'department_id' => $department->id,
                'position_id' => $position->id,
                'name' => 'Admin',
                'password' => Hash::make('Admin@123'),
                'is_active' => true,
            ],
        );

        $admin->roles()->sync([$role->id]);

        $this->command?->info('Đã khôi phục tài khoản admin@vk-kpi.local / Admin@123');
    }
}
