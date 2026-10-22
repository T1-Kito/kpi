<?php
namespace Tests\Feature;

use App\Models\{Customer,User,SalesOrder,ServiceTicket,Permission};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChairmanCareReportTest extends TestCase
{
    use RefreshDatabase;
    private function token(string $email):string { return $this->postJson('/api/v1/auth/login',['email'=>$email,'password'=>'Admin@123'])->assertOk()->json('data.access_token'); }

    public function test_month_uses_vietnam_boundaries_and_matches_order_report():void
    {
        $this->seed();$admin=User::where('email','admin@vk-kpi.local')->firstOrFail();$customer=Customer::firstOrFail();
        foreach(['2030-09-30 16:59:59','2030-09-30 17:00:00','2030-10-31 16:59:59','2030-10-31 17:00:00'] as $i=>$created) {
            $order=SalesOrder::create(['tenant_id'=>$admin->tenant_id,'customer_id'=>$customer->id,'sales_owner_id'=>$admin->id,'code'=>'BOUNDARY-'.$i,'status'=>'draft','total_amount'=>100]);
            $order->forceFill(['created_at'=>$created])->save();
        }
        $token=$this->token($admin->email);
        $this->withToken($token)->getJson('/api/v1/dashboard/summary?type=admin&month=2030-10')->assertOk()->assertJsonPath('data.executive.period','2030-10')->assertJsonPath('data.executive.orders',2)->assertJsonPath('data.executive.timezone','Asia/Ho_Chi_Minh');
        $this->withToken($token)->getJson('/api/v1/business-reports?type=sales&from=2030-10-01&to=2030-10-31&q=BOUNDARY-')->assertOk()->assertJsonPath('data.meta.count',2);
    }

    public function test_care_and_service_exports_are_scoped_require_export_permission_and_contain_real_xlsx():void
    {
        $this->seed();$sales=User::where('email','sales@vk-kpi.local')->firstOrFail();$admin=User::where('email','admin@vk-kpi.local')->firstOrFail();
        $mine=Customer::create(['tenant_id'=>$sales->tenant_id,'code'=>'CARE-REPORT-MINE','name'=>'Care report mine','sales_owner_id'=>$sales->id,'status'=>'active']);
        $other=Customer::create(['tenant_id'=>$sales->tenant_id,'code'=>'CARE-REPORT-OTHER','name'=>'Care report other','sales_owner_id'=>$admin->id,'status'=>'active']);
        $token=$this->token($sales->email);$adminToken=$this->token($admin->email);
        $this->withToken($token)->postJson('/api/v1/customers/'.$mine->id.'/care',['title'=>'Report call','due_at'=>now()->addDay()->toIso8601String()])->assertCreated();
        $this->withToken($adminToken)->postJson('/api/v1/customers/'.$other->id.'/care',['title'=>'PRIVATE-CARE','due_at'=>now()->addDay()->toIso8601String()])->assertCreated();
        $ticket=$this->withToken($token)->postJson('/api/v1/service-tickets',['customer_id'=>$mine->id,'assignee_id'=>$sales->id,'subject'=>'Report feedback','category'=>'complaint','priority'=>'normal'])->assertCreated()->json('data');
        $this->withToken($adminToken)->postJson('/api/v1/service-tickets',['customer_id'=>$other->id,'assignee_id'=>$admin->id,'subject'=>'PRIVATE-SERVICE','category'=>'complaint','priority'=>'normal'])->assertCreated();
        $exportId=Permission::where('code','sales.report.export')->value('id');$role=$sales->roles()->first();$role->permissions()->detach($exportId);
        foreach(['care','service'] as $type) {
            $query='type='.$type.'&from=2000-01-01&to=2099-12-31';
            $response=$this->withToken($token)->getJson('/api/v1/business-reports?'.$query)->assertOk()->assertJsonPath('data.meta.count',1)->assertJsonMissing(['PRIVATE-CARE'])->assertJsonMissing(['PRIVATE-SERVICE']);
            $this->assertSame('Care report mine',$response->json('data.rows.0.1'));
            $this->withToken($token)->getJson('/api/v1/business-reports/export?'.$query)->assertForbidden();
            $role->permissions()->attach($exportId);
            $download=$this->withToken($token)->get('/api/v1/business-reports/export?'.$query)->assertOk();
            $path=$download->baseResponse->getFile()->getPathname();
            try {
                $zip=new \ZipArchive();$this->assertTrue($zip->open($path));
                $xml=$zip->getFromName('xl/worksheets/sheet2.xml');$this->assertNotFalse(simplexml_load_string($xml));
                $this->assertStringContainsString('Care report mine',$xml);$this->assertStringNotContainsString('PRIVATE-',$xml);$this->assertStringContainsString('s="4"',$xml);$zip->close();
            }finally{@unlink($path);}
            $role->permissions()->detach($exportId);
        }
        $this->withToken($token)->putJson('/api/v1/service-tickets/'.$ticket['id'],['status'=>'resolved','resolution_code'=>'FIX','resolution_note'=>'Đã xử lý'])->assertOk();
        $summary=$this->withToken($adminToken)->getJson('/api/v1/dashboard/summary?type=admin')->assertOk()->assertJsonPath('data.executive.customer_care.waiting_confirmation',1);
        $this->assertGreaterThanOrEqual(1,$summary->json('data.executive.customer_care.without_next_action'));
    }

    public function test_gap_report_agrees_with_current_dashboard_and_rejects_historical_date_filter():void
    {
        $this->seed();$admin=User::where('email','admin@vk-kpi.local')->firstOrFail();$sales=User::where('email','sales@vk-kpi.local')->firstOrFail();
        $customer=Customer::create(['tenant_id'=>$admin->tenant_id,'code'=>'REPORT-NEXT-GAP','name'=>'Khách cần hẹn','sales_owner_id'=>$sales->id,'status'=>'active']);
        $adminToken=$this->token($admin->email);$token=$this->token($sales->email);
        $query='/api/v1/business-reports?type=care_gaps';
        $report=$this->withToken($adminToken)->getJson($query)->assertOk();
        $summary=$this->withToken($adminToken)->getJson('/api/v1/dashboard/summary?type=admin')->assertOk();
        $this->assertSame($summary->json('data.executive.customer_care.without_next_action'),$report->json('data.meta.count'));
        $this->withToken($token)->getJson($query.'&q=REPORT-NEXT-GAP')->assertOk()->assertJsonPath('data.meta.count',1);
        $this->withToken($token)->postJson('/api/v1/customers/'.$customer->id.'/care',['title'=>'Có lịch tiếp theo','due_at'=>now()->addDay()->toIso8601String()])->assertCreated();
        $this->withToken($token)->getJson($query.'&q=REPORT-NEXT-GAP')->assertOk()->assertJsonPath('data.meta.count',0);
        $this->withToken($adminToken)->getJson($query.'&from=2026-10-01&to=2026-10-31')->assertUnprocessable();
        $download=$this->withToken($adminToken)->get('/api/v1/business-reports/export?type=care_gaps')->assertOk();
        @unlink($download->baseResponse->getFile()->getPathname());
    }
}
