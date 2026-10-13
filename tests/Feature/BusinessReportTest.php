<?php

namespace Tests\Feature;

use App\Models\{SalesOrder, User, PurchaseOrder, SalesInvoice};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use ZipArchive;

class BusinessReportTest extends TestCase
{
    use RefreshDatabase;

    private function token(User $user): string
    {
        return $this->postJson('/api/v1/auth/login', ['email'=>$user->email, 'password'=>'Admin@123'])->assertOk()->json('data.access_token');
    }

    public function test_all_reports_and_exports_are_authenticated_and_valid_xlsx(): void
    {
        $this->getJson('/api/v1/business-reports?type=sales')->assertUnauthorized();
        $this->get('/business-reports')->assertOk()->assertDontSee('Sales Demo');
        $this->seed();
        $admin = User::where('email','admin@vk-kpi.local')->firstOrFail();
        $token = $this->token($admin);
        foreach (['sales','invoices','purchase','inventory','transactions','receivables'] as $type) {
            $params = ['type'=>$type];
            if (!in_array($type,['inventory','receivables'])) $params += ['from'=>'2000-01-01','to'=>'2099-12-31'];
            $query = http_build_query($params);
            $this->withToken($token)->getJson('/api/v1/business-reports?'.$query)->assertOk()->assertJsonStructure(['data'=>['columns','rows','totals','options','meta'=>['count','basis','scope']]]);
            $response = $this->withToken($token)->get('/api/v1/business-reports/export?'.$query)->assertOk();
            $path = $response->baseResponse->getFile()->getPathname();
            try {
                $zip = new ZipArchive;
                $this->assertTrue($zip->open($path));
                foreach ([1,2] as $sheet) $this->assertNotFalse(simplexml_load_string($zip->getFromName('xl/worksheets/sheet'.$sheet.'.xml')));
                $this->assertStringContainsString('Chi tiết',$zip->getFromName('xl/workbook.xml'));
                $zip->close();
            } finally { @unlink($path); }
        }
    }

    public function test_export_contains_every_filtered_row_and_totals_not_just_current_page(): void
    {
        $this->seed();
        $admin = User::where('email','admin@vk-kpi.local')->firstOrFail();
        $sales = User::where('email','sales@vk-kpi.local')->firstOrFail();
        $customer = \App\Models\Customer::where('tenant_id',$admin->tenant_id)->firstOrFail();
        for ($i=0;$i<55;$i++) {
            $order = SalesOrder::create(['tenant_id'=>$admin->tenant_id,'customer_id'=>$customer->id,'sales_owner_id'=>$sales->id,'code'=>'REPORT-'.$i,'status'=>'draft','total_amount'=>100]);
            $order->forceFill(['created_at'=>'2026-10-01 00:00:00'])->save();
        }
        $token = $this->token($sales);
        $this->withToken($token)->get('/api/v1/business-reports/export?type=sales&from=2026-10-01&to=2026-10-01')->assertForbidden();
        $sales->roles()->first()->permissions()->syncWithoutDetaching([\App\Models\Permission::where('code', 'sales.report.export')->value('id')]);
        $query='type=sales&from=2026-10-01&to=2026-10-01&q=REPORT-';
        $this->withToken($token)->getJson('/api/v1/business-reports?'.$query)->assertOk()->assertJsonCount(50,'data.rows')->assertJsonPath('data.meta.count',55)->assertJsonPath('data.totals.5',5500);
        $this->withToken($token)->getJson('/api/v1/business-reports?'.$query.'&page=2')->assertOk()->assertJsonCount(5,'data.rows');
        $this->withToken($token)->getJson('/api/v1/business-reports?'.$query.'&user_id='.$admin->id)->assertOk()->assertJsonCount(0,'data.rows');
        $response=$this->withToken($token)->get('/api/v1/business-reports/export?'.$query)->assertOk();
        $path=$response->baseResponse->getFile()->getPathname();
        try {
            $zip=new ZipArchive; $this->assertTrue($zip->open($path));
            $xml=$zip->getFromName('xl/worksheets/sheet2.xml');
            $this->assertSame(55,substr_count($xml,'REPORT-'));
            $this->assertStringContainsString('s="4"><v>',$xml);
            $this->assertStringContainsString('<v>100</v>',$xml);
            $zip->close();
        } finally {@unlink($path);}
    }

    public function test_invalid_filters_and_missing_business_permissions_are_rejected(): void
    {
        $this->seed();
        $actor=User::where('email','sales@vk-kpi.local')->firstOrFail();
        $token=$this->token($actor);
        $this->withToken($token)->getJson('/api/v1/business-reports?type=sales&from=2026-10-20&to=2026-10-01')->assertUnprocessable();
        $this->withToken($token)->getJson('/api/v1/business-reports?type=inventory&from=2026-10-01&to=2026-10-31')->assertUnprocessable();
        $actor->roles()->detach();
        $this->withToken($token)->getJson('/api/v1/business-reports?type=sales&from=2026-10-01&to=2026-10-31')->assertForbidden();
        $this->withToken($token)->getJson('/api/v1/business-reports/export?type=sales&from=2026-10-01&to=2026-10-31')->assertForbidden();
    }

    public function test_date_filter_uses_vietnam_day_boundaries_and_current_debt_ignores_cancelled_invoices(): void
    {
        $this->seed();
        $admin=User::where('email','admin@vk-kpi.local')->firstOrFail();
        $token=$this->token($admin);
        $customer=\App\Models\Customer::firstOrFail();
        foreach (['2026-09-30 16:59:59','2026-09-30 17:00:00','2026-10-01 16:59:59','2026-10-01 17:00:00'] as $index=>$time) {
            $order=SalesOrder::create(['tenant_id'=>$admin->tenant_id,'customer_id'=>$customer->id,'sales_owner_id'=>$admin->id,'code'=>'BOUNDARY-'.$index,'status'=>'draft','total_amount'=>100]);
            $order->forceFill(['created_at'=>$time])->save();
        }
        $this->withToken($token)->getJson('/api/v1/business-reports?type=sales&from=2026-10-01&to=2026-10-01&q=BOUNDARY-')->assertOk()->assertJsonCount(2,'data.rows')->assertJsonPath('data.totals.5',200);
        $order=SalesOrder::where('code','BOUNDARY-1')->firstOrFail();
        foreach (['issued','cancelled'] as $status) SalesInvoice::create(['tenant_id'=>$admin->tenant_id,'sales_order_id'=>$order->id,'code'=>'DEBT-'.$status,'invoice_date'=>'2026-10-01','total_amount'=>100,'paid_amount'=>20,'balance_amount'=>80,'status'=>$status]);
        $this->withToken($token)->getJson('/api/v1/business-reports?type=receivables&q=DEBT-')->assertOk()->assertJsonCount(1,'data.rows')->assertJsonPath('data.totals.7',80);
    }
}
