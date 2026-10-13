<?php

namespace Tests\Feature;

use App\Models\{AuditLog, Permission, Role, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportExportPermissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_view_export_and_audit_are_independent_for_each_report_domain(): void
    {
        $this->seed();
        $admin = User::where('email','admin@vk-kpi.local')->firstOrFail();
        $role = Role::create(['tenant_id'=>$admin->tenant_id,'code'=>'REPORT-READER','name'=>'Người xem báo cáo','data_scope'=>'own']);
        $role->permissions()->sync(Permission::whereIn('code',['master.view','sales.order.view','finance.invoice.view','finance.payment.view','procurement.po.view'])->pluck('id'));
        $user = User::create(['tenant_id'=>$admin->tenant_id,'name'=>'Người xem','email'=>'report-reader@test.local','password'=>'Admin@123','is_active'=>true]);
        $user->roles()->sync([$role->id]);
        $token=$this->postJson('/api/v1/auth/login',['email'=>$user->email,'password'=>'Admin@123'])->assertOk()->json('data.access_token');
        foreach (['sales'=>'sales.report.export','invoices'=>'finance.report.export','purchase'=>'procurement.report.export','inventory'=>'inventory.report.export','transactions'=>'inventory.report.export','receivables'=>'finance.report.export'] as $type=>$permission) {
            $role->permissions()->detach(Permission::where('code',$permission)->value('id'));
            $query=http_build_query(['type'=>$type]+(in_array($type,['inventory','receivables'])?[]:['from'=>'2026-10-01','to'=>'2026-10-31']));
            $this->withToken($token)->getJson('/api/v1/business-reports?'.$query)->assertOk()->assertJsonPath('data.meta.can_export',false);
            $before=AuditLog::where('action','export_business_report')->count();
            $this->withToken($token)->getJson('/api/v1/business-reports/export?'.$query)->assertForbidden();
            $this->assertSame($before,AuditLog::where('action','export_business_report')->count());
            $role->permissions()->syncWithoutDetaching([Permission::where('code',$permission)->value('id')]);
            $response=$this->withToken($token)->get('/api/v1/business-reports/export?'.$query)->assertOk();
            @unlink($response->baseResponse->getFile()->getPathname());
            $log=AuditLog::where('action','export_business_report')->latest('id')->firstOrFail();
            $this->assertSame($user->id,$log->user_id);
            $this->assertSame($type,$log->new_value['filters']['type']);
            $this->assertSame('own',$log->new_value['scope']);
            $this->assertArrayHasKey('row_count',$log->new_value);
            // Removing read permission still blocks export even when export permission exists.
            $read=\App\Services\Reports\BusinessReport::TYPES[$type][1];
            $role->permissions()->detach(Permission::where('code',$read)->value('id'));
            $this->withToken($token)->getJson('/api/v1/business-reports/export?'.$query)->assertForbidden();
            $role->permissions()->syncWithoutDetaching([Permission::where('code',$read)->value('id')]);
        }
        $this->withToken($token)->getJson('/api/v1/kpi/employee-report?date=2026-10-13')->assertOk()->assertJsonPath('meta.can_export',false);
        $this->withToken($token)->get('/api/v1/kpi/employee-report/export?date=2026-10-13')->assertForbidden();
        $role->permissions()->syncWithoutDetaching([Permission::where('code','kpi.report.export')->value('id')]);
        $response=$this->withToken($token)->get('/api/v1/kpi/employee-report/export?date=2026-10-13')->assertOk();
        @unlink($response->baseResponse->getFile()->getPathname());
        $this->assertDatabaseHas('audit_logs',['entity_type'=>'kpi_report','action'=>'export_kpi_report','user_id'=>$user->id]);
    }
}
