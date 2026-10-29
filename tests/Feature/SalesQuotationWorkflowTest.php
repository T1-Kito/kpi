<?php

namespace Tests\Feature;

use App\Models\{Customer, PrintTemplate, Quotation, QuotationIssue, Sku, User};
use App\Services\Print\QuotationDocxMergeService;
use App\Support\{QuotationService, QuotationWorkflow, SalesOrderService};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class SalesQuotationWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function actors(): array
    {
        $this->seed();
        // These cases exercise the assigned-only policy; universal approval is tested separately.
        \Illuminate\Support\Facades\DB::table('role_permissions')->where('permission_id', \App\Models\Permission::where('code', \App\Support\SalesApprovalAccess::ANY)->value('id'))->delete();
        $sales = User::where('email', 'sales@vk-kpi.local')->firstOrFail();
        $admin = User::where('email', 'admin@vk-kpi.local')->firstOrFail();
        $settings = $sales->tenant->ui_settings ?? [];
        $settings['sales_quotation_approval'] = ['approvers' => [$admin->id], 'exception_approvers' => [], 'minimum_margin' => 15, 'maximum_discount' => 0];
        $sales->tenant->update(['ui_settings' => $settings]);
        return [$sales, $admin];
    }

    private function draft(User $sales): Quotation
    {
        return app(QuotationService::class)->create($sales, ['customer_id' => Customer::where('tenant_id', $sales->tenant_id)->first()->id,
            'valid_until' => now()->addDays(15)->toDateString(), 'discount_percent' => 10,
            'items' => [['sku_id' => Sku::where('tenant_id', $sales->tenant_id)->first()->id, 'quantity' => 2, 'unit_price' => 1000.01, 'vat_rate' => 8]]]);
    }

    public function test_role_permission_can_cover_absent_approver_and_preserves_sequential_steps(): void
    {
        [$sales, $admin] = $this->actors();
        $director = User::where('email','director@vk-kpi.local')->firstOrFail();
        $settings = $sales->tenant->ui_settings;
        // Use two eligible assigned approvers; the role reviewer handles one step at a time.
        $settings['sales_quotation_approval']['approvers'] = [$director->id, $admin->id];
        $sales->tenant->update(['ui_settings'=>$settings]);
        $permission = \App\Models\Permission::where('code',\App\Support\SalesApprovalAccess::ANY)->firstOrFail();
        $admin->roles->first()->permissions()->syncWithoutDetaching([$permission->id]);
        $quote = app(QuotationWorkflow::class)->submit($this->draft($sales),$sales);
        $token = $this->postJson('/api/v1/auth/login',['email'=>$admin->email,'password'=>'Admin@123'])->json('data.access_token');
        $this->withToken($token)->getJson('/api/v1/quotations/'.$quote->id)->assertOk()->assertJsonPath('data.can_approve',true);
        $rows = $this->withToken($token)->getJson('/api/v1/approval-center')->assertOk()->json('data.pending');
        $this->assertContains($quote->id, array_column(array_filter($rows,fn($row)=>$row['type']==='quotation'),'id'));
        $quote = app(QuotationWorkflow::class)->decide($quote,$admin,'approved',null);
        $this->assertSame('pending_approval',$quote->status);
        $this->assertSame($admin->id,$quote->approval_steps[0]['decided_by']);
        $this->assertTrue($quote->approval_steps[0]['permission_override']);
        $this->assertSame('pending',$quote->approval_steps[1]['status']);
    }

    public function test_no_levels_uses_role_pool_and_still_enforces_self_approval_policy(): void
    {
        [$sales,$admin] = $this->actors();
        $permission = \App\Models\Permission::where('code',\App\Support\SalesApprovalAccess::ANY)->firstOrFail();
        $admin->roles->first()->permissions()->syncWithoutDetaching([$permission->id]);
        $sales->tenant->update(['ui_settings'=>['sales_quotation_approval'=>['approvers'=>[]]]]);
        $quote = app(QuotationWorkflow::class)->submit($this->draft($sales),$sales);
        $this->assertTrue($quote->approval_policy_snapshot['role_pool']);
        $this->assertTrue(app(\App\Support\SalesApprovalAccess::class)->canApprove($quote,$admin));
        $this->assertFalse(app(\App\Support\SalesApprovalAccess::class)->canApprove($quote,$sales));
        $quote = app(QuotationWorkflow::class)->decide($quote,$admin,'approved',null);
        $this->assertSame('approved',$quote->status);
        $quote->status='pending_approval';
        $quote->created_by=$admin->id;
        $quote->approval_steps=[['user_id'=>$admin->id,'status'=>'pending']];
        $quote->approval_policy_snapshot=['separation_of_duties'=>true];
        $this->assertFalse(app(\App\Support\SalesApprovalAccess::class)->canApprove($quote,$admin));
    }

    public function test_permission_only_reviewer_can_open_queue_and_approve_without_assigned_role(): void
    {
        [$sales,$admin] = $this->actors();
        $role=\App\Models\Role::create(['tenant_id'=>$admin->tenant_id,'code'=>'ROLE-REVIEW-ANY','name'=>'Duyệt chung','data_scope'=>'own']);
        $role->permissions()->sync([\App\Models\Permission::where('code',\App\Support\SalesApprovalAccess::ANY)->value('id')]);
        $reviewer=User::create(['tenant_id'=>$admin->tenant_id,'name'=>'Reviewer','email'=>'review-any@example.test','password'=>'Reviewer-Test-123','is_active'=>true]);
        $reviewer->roles()->sync([$role->id]);
        $quote=app(QuotationWorkflow::class)->submit($this->draft($sales),$sales);
        $token=$this->postJson('/api/v1/auth/login',['email'=>$reviewer->email,'password'=>'Reviewer-Test-123'])->assertOk()->json('data.access_token');
        $this->withToken($token)->getJson('/api/v1/quotations/'.$quote->id)->assertOk()->assertJsonPath('data.can_approve',true);
        $this->withToken($token)->getJson('/api/v1/approval-center')->assertOk();
        $this->withToken($token)->postJson('/api/v1/quotations/'.$quote->id.'/approve',['status'=>'approved'])->assertOk();
        $this->assertSame('approved',$quote->fresh()->status);
        $reviewer->tenant_id=99999;
        $this->assertFalse(app(\App\Support\SalesApprovalAccess::class)->canApprove($quote,$reviewer));
    }

    public function test_unsaved_preview_uses_saved_quote_calculations_without_writes(): void
    {
        [$sales] = $this->actors();
        $data=['customer_id'=>Customer::where('tenant_id',$sales->tenant_id)->firstOrFail()->id,'discount_percent'=>12.35,'payment_terms'=>'Đặt cọc 30%; thanh toán 70% còn lại.','payment_plan'=>['mode'=>'deposit','percent'=>30,'deposit_date'=>'2026-11-01','balance_date'=>'2026-11-05'],
            'items'=>[['sku_id'=>Sku::where('tenant_id',$sales->tenant_id)->firstOrFail()->id,'quantity'=>3.333,'unit_price'=>1234.56,'vat_rate'=>8]]];
        $service=app(QuotationService::class);
        $queries=[];
        \Illuminate\Support\Facades\DB::listen(function($query)use(&$queries){$queries[]=$query->sql;});
        $count=Quotation::count();
        $preview=$service->preview($sales,$data);
        $this->assertFalse($preview->exists);
        $this->assertSame('BẢN NHÁP',$preview->code);
        $this->assertSame($count,Quotation::count());
        $this->assertSame([],array_values(array_filter($queries,fn($sql)=>preg_match('/^\s*(insert|update|delete)/i',$sql))));
        $saved=$service->create($sales,$data);
        $this->assertSame($data['payment_plan'],$saved->fresh()->payment_plan);
        foreach(['subtotal_amount','discount_amount','tax_amount','total_amount'] as $field) $this->assertEquals($saved->$field,$preview->$field);
        $this->assertEquals($saved->items->first()->line_total,$preview->items->first()->line_total);
    }

    public function test_draft_preview_endpoint_returns_merged_word_without_creating_a_quote(): void
    {
        [$sales] = $this->actors();
        Storage::fake('local');
        $temp=tempnam(sys_get_temp_dir(),'preview-template');
        $zip=new \ZipArchive;$zip->open($temp,\ZipArchive::CREATE|\ZipArchive::OVERWRITE);
        $zip->addFromString('word/document.xml','<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>{{ma_bao_gia}} / {{ten_khach_hang}} / {{tong_cong}}</w:t></w:r></w:p></w:body></w:document>');$zip->close();
        Storage::disk('local')->put('preview-test.docx',file_get_contents($temp));unlink($temp);
        $template=PrintTemplate::create(['tenant_id'=>$sales->tenant_id,'module'=>'quotation','code'=>'PREVIEW-TEST','name'=>'Mẫu preview','status'=>'active','file_path'=>'preview-test.docx','is_default'=>true]);
        $token=$this->postJson('/api/v1/auth/login',['email'=>$sales->email,'password'=>'Admin@123'])->json('data.access_token');
        $data=['template_id'=>$template->id,'customer_id'=>Customer::where('tenant_id',$sales->tenant_id)->firstOrFail()->id,'items'=>[['sku_id'=>Sku::where('tenant_id',$sales->tenant_id)->firstOrFail()->id,'quantity'=>1,'unit_price'=>1000,'vat_rate'=>8]]];
        $before=Quotation::count();
        $response=$this->withToken($token)->postJson('/api/v1/quotations/preview-draft',$data);
        $response->assertOk()->assertDownload('xem-truoc-bao-gia.docx');
        $path=$response->baseResponse->getFile()->getPathname();
        try {
            $zip=new \ZipArchive;$zip->open($path);$xml=$zip->getFromName('word/document.xml');$zip->close();
            $this->assertStringContainsString('BẢN NHÁP',$xml);
            $this->assertStringContainsString('1.080 đ',$xml);
            $this->assertStringNotContainsString('{{',$xml);
            $this->assertSame($before,Quotation::count());
        } finally {if(is_file($path))unlink($path);}
        $data['template_id']=999999;
        $this->withToken($token)->postJson('/api/v1/quotations/preview-draft',$data)->assertUnprocessable();
    }

    public function test_quote_remembers_selected_contact_and_rejects_another_customers_contact(): void
    {
        [$sales] = $this->actors();
        $customer = Customer::where('tenant_id', $sales->tenant_id)->firstOrFail();
        $selected = $customer->contacts()->create(['name'=>'Người nhận báo giá riêng','phone'=>'0901112223','is_primary'=>false]);
        $data = ['customer_id'=>$customer->id,'contact_id'=>$selected->id,'payment_terms'=>'Đặt cọc 30%; thanh toán 70% còn lại khi giao hàng.',
            'items'=>[['sku_id'=>Sku::where('tenant_id',$sales->tenant_id)->firstOrFail()->id,'quantity'=>1,'unit_price'=>1000,'vat_rate'=>0]]];
        $service = app(QuotationService::class);
        $quote = $service->create($sales,$data);
        $this->assertEquals($selected->id,$quote->contact_id);
        $this->assertSame($data['payment_terms'],$quote->payment_terms);
        unset($data['contact_id']);
        $quote = $service->update($quote,$sales,$data);
        $this->assertEquals($selected->id,$quote->contact_id);
        $other = Customer::create(['tenant_id'=>$sales->tenant_id,'code'=>'CUS-CONTACT-OTHER','name'=>'Khách khác','status'=>'active']);
        $foreign = $other->contacts()->create(['name'=>'Sai khách']);
        $data['contact_id']=$foreign->id;
        $this->denied(fn()=>$service->update($quote,$sales,$data),422);
        $this->assertEquals($selected->id,$quote->fresh()->contact_id);
    }

    private function denied(callable $operation, int $status): void
    {
        try { $operation(); $this->fail('Operation must be blocked.'); }
        catch (HttpException $error) { $this->assertSame($status, $error->getStatusCode()); }
    }

    public function test_complete_flow_locks_snapshot_and_requires_customer_evidence(): void
    {
        [$sales, $admin] = $this->actors();
        $quote = $this->draft($sales);
        $this->assertSame('draft', $quote->status);
        $this->assertEquals(2000.02, $quote->subtotal_amount);
        $this->assertEquals(200.00, $quote->discount_amount);
        $this->assertEquals(1944.02, $quote->total_amount);
        $workflow = app(QuotationWorkflow::class);
        $this->denied(fn () => app(SalesOrderService::class)->createFromQuotation($quote, $sales), 422);
        $quote = $workflow->submit($quote, $sales);
        $this->assertSame('pending_approval', $quote->status);
        $originalName = $quote->document_snapshot['customer']['name'];
        $quote->customer->update(['name' => 'Changed master name']);
        $this->assertSame($originalName, $quote->fresh()->document_snapshot['customer']['name']);
        $this->denied(fn () => app(QuotationService::class)->update($quote, $sales, []), 422);
        $this->denied(fn () => $workflow->decide($quote, $sales, 'approved', null), 403);
        $quote = $workflow->decide($quote, $admin, 'approved', null);
        $this->assertSame('approved', $quote->status);
        $this->denied(fn () => app(SalesOrderService::class)->createFromQuotation($quote, $sales), 422);
        Storage::fake('local');
        PrintTemplate::create(['tenant_id' => $sales->tenant_id, 'code' => 'TEST-ISSUE', 'name' => 'Approved template', 'module' => 'quotation', 'status' => 'active', 'file_path' => 'test.docx', 'is_default' => true]);
        $temp = tempnam(sys_get_temp_dir(), 'quote-test-');
        file_put_contents($temp, 'archived-test-document');
        $this->mock(QuotationDocxMergeService::class)->shouldReceive('merge')->once()->andReturn($temp);
        $quote = $workflow->issue($quote, $sales, ['recipient' => 'Customer', 'channel' => 'email', 'delivery_evidence' => 'Email reference TEST-1']);
        $issue = QuotationIssue::where('quotation_id', $quote->id)->firstOrFail();
        Storage::disk('local')->assertExists($issue->file_path);
        $this->assertSame(hash('sha256', 'archived-test-document'), $issue->file_hash);
        $quote = $workflow->customerDecision($quote, $sales, ['decision' => 'accepted', 'evidence' => 'Customer email TEST-2']);
        $this->assertSame('accepted', $quote->status);
        $order = $quote->salesOrders()->where('status','draft')->firstOrFail();
        $this->assertEquals(1800.02, $order->subtotal_amount);
        $this->assertEquals($quote->total_amount, $order->total_amount);
        $this->denied(fn () => app(SalesOrderService::class)->createFromQuotation($quote, $sales), 422);
    }

    public function test_customer_acceptance_automatically_creates_sales_order_for_authorized_owner(): void
    {
        [$sales, $admin] = $this->actors();
        $workflow = app(QuotationWorkflow::class);
        $quote = $workflow->submit($this->draft($sales), $sales);
        $quote = $workflow->decide($quote, $admin, 'approved', null);
        $prepared = $quote->salesOrders()->sole();
        $this->assertSame('awaiting_customer',$prepared->status);
        $this->assertSame('unchecked',$prepared->stock_status);
        $this->assertEquals($quote->total_amount,$prepared->total_amount);
        $this->assertSame($prepared->id,app(SalesOrderService::class)->prepareApprovedQuotation($quote,$admin)->id);
        $this->assertSame(1,$quote->salesOrders()->count());
        $this->denied(fn()=>app(SalesOrderService::class)->confirm($prepared,$sales),422);

        Storage::fake('local');
        PrintTemplate::create(['tenant_id' => $sales->tenant_id, 'code' => 'TEST-AUTO-ORDER', 'name' => 'Auto order template', 'module' => 'quotation', 'status' => 'active', 'file_path' => 'auto.docx', 'is_default' => true]);
        $temp = tempnam(sys_get_temp_dir(), 'quote-auto-');
        file_put_contents($temp, 'auto-order-document');
        $this->mock(QuotationDocxMergeService::class)->shouldReceive('merge')->once()->andReturn($temp);
        $quote = $workflow->issue($quote, $sales, ['recipient' => 'Customer', 'channel' => 'email', 'delivery_evidence' => 'Email AUTO-1']);

        $token = $this->postJson('/api/v1/auth/login', [
            'email' => 'sales@vk-kpi.local',
            'password' => 'Admin@123',
        ])->assertOk()->json('data.access_token');
        $response = $this->withToken($token)->postJson("/api/v1/quotations/{$quote->id}/workflow/customer-response", [
            'decision' => 'accepted',
            'evidence' => 'Customer accepted AUTO-2',
        ])->assertOk()->assertJsonPath('data.status', 'accepted');

        $orderId = $response->json('sales_order.id');
        $this->assertNotNull($orderId);
        $this->assertSame($prepared->id,$orderId);
        $this->assertDatabaseHas('sales_orders', ['id' => $orderId, 'quotation_id' => $quote->id, 'status' => 'draft']);
    }

    public function test_revising_approved_quote_cancels_only_the_unconfirmed_automatic_order(): void
    {
        [$sales,$admin]=$this->actors();
        $workflow=app(QuotationWorkflow::class);
        $quote=$workflow->decide($workflow->submit($this->draft($sales),$sales),$admin,'approved',null);
        $order=$quote->salesOrders()->sole();
        $copy=$workflow->revise($quote,$sales);
        $this->assertSame('cancelled',$order->fresh()->status);
        $this->assertSame('draft',$copy->status);
        $this->assertSame(0,$copy->salesOrders()->count());
    }

    public function test_creator_can_approve_only_when_assigned_with_permission_and_at_their_turn(): void
    {
        [$sales, $admin] = $this->actors();
        $policy = $admin->tenant->fresh()->ui_settings;
        $policy['sales_quotation_approval']['approvers'] = [$admin->id];
        $admin->tenant->update(['ui_settings' => $policy]);
        $workflow = app(QuotationWorkflow::class);
        $quote = $workflow->submit($this->draft($admin), $admin);
        $this->assertSame($admin->id, $quote->created_by);
        $this->denied(fn () => $workflow->decide($quote, $sales, 'approved', null), 403);
        $quote = $workflow->decide($quote, $admin, 'approved', null);
        $this->assertSame('approved', $quote->status);
        $this->denied(fn () => $workflow->decide($quote, $admin, 'approved', null), 422);

        $director = User::where('email', 'director@vk-kpi.local')->firstOrFail();
        $policy['sales_quotation_approval']['approvers'] = [$director->id, $admin->id];
        $admin->tenant->update(['ui_settings' => $policy]);
        $quote = $workflow->submit($this->draft($admin), $admin);
        $this->denied(fn () => $workflow->decide($quote, $admin, 'approved', null), 403);
        $quote = $workflow->decide($quote, $director, 'approved', null);
        $adminRoleIds = $admin->roles->pluck('id')->all();
        $admin->roles()->detach();
        $admin->unsetRelation('roles');
        $this->denied(fn () => $workflow->decide($quote, $admin, 'approved', null), 403);
        $admin->roles()->sync($adminRoleIds);
        $admin->unsetRelation('roles');
        $quote = $workflow->decide($quote, $admin, 'approved', null);
        $this->assertSame('approved', $quote->status);
    }

    public function test_change_request_preserves_old_version_and_cannot_bypass_approval(): void
    {
        [$sales, $admin] = $this->actors(); $workflow = app(QuotationWorkflow::class);
        $quote = $workflow->submit($this->draft($sales), $sales);
        $this->denied(fn () => $workflow->decide($quote, $admin, 'change_requested', ''), 422);
        $quote = $workflow->decide($quote, $admin, 'change_requested', 'Correct payment terms');
        $copy = $workflow->revise($quote, $sales);
        $this->assertSame('superseded', $quote->fresh()->status);
        $this->assertSame('draft', $copy->status);
        $this->assertSame(2, $copy->revision_number);
        $this->assertEquals($quote->total_amount, $copy->total_amount);
        $this->assertEquals(1000.01, $copy->items()->first()->list_unit_price);
        $this->denied(fn () => $workflow->decide($quote, $admin, 'approved', null), 422);
    }

    public function test_missing_approver_and_expired_quote_are_not_auto_approved(): void
    {
        [$sales] = $this->actors();
        $sales->tenant->update(['ui_settings' => []]); $sales->update(['manager_id' => null]);
        $quote = $this->draft($sales);
        $this->denied(fn () => app(QuotationWorkflow::class)->submit($quote, $sales), 422);
        $this->assertSame('draft', $quote->fresh()->status);
        $quote->update(['valid_until' => now()->subDay()]);
        $this->denied(fn () => app(QuotationWorkflow::class)->submit($quote, $sales), 422);
    }

    public function test_sequential_approval_uses_frozen_policy_and_only_assigned_reviewer_can_read(): void
    {
        [$sales, $admin] = $this->actors();
        $director = User::where('email', 'director@vk-kpi.local')->firstOrFail();
        $settings = $sales->tenant->ui_settings;
        $settings['sales_quotation_approval']['approvers'] = [$director->id, $admin->id];
        $sales->tenant->update(['ui_settings' => $settings]);
        $workflow = app(QuotationWorkflow::class);
        $draft = $this->draft($sales);
        $quote = $workflow->submit($this->draft($sales), $sales);
        $directorToken = $this->postJson('/api/v1/auth/login', ['email' => $director->email, 'password' => 'Admin@123'])->json('data.access_token');
        $this->withToken($directorToken)->getJson('/api/v1/quotations/'.$draft->id)->assertNotFound();
        $this->withToken($directorToken)->getJson('/api/v1/quotations/'.$quote->id)->assertOk()->assertJsonPath('data.can_approve', true);
        $rows = $this->withToken($directorToken)->getJson('/api/v1/quotations')->assertOk()->json('data');
        $this->assertContains($quote->id, array_column($rows, 'id'));
        $this->assertNotContains($draft->id, array_column($rows, 'id'));
        $this->denied(fn () => $workflow->decide($quote, $admin, 'approved', null), 403);
        $settings['sales_quotation_approval']['approvers'] = [$admin->id];
        $sales->tenant->update(['ui_settings' => $settings]);
        $quote = $workflow->decide($quote, $director, 'approved', null);
        $this->assertSame('pending_approval', $quote->status);
        $this->assertSame('approved', $quote->approval_steps[0]['status']);
        $this->assertSame($admin->id, $quote->approval_steps[1]['user_id']);
        $quote = $workflow->decide($quote, $admin, 'approved', null);
        $this->assertSame('approved', $quote->status);
        $adminToken = $this->postJson('/api/v1/auth/login', ['email' => $admin->email, 'password' => 'Admin@123'])->json('data.access_token');
        $rows = $this->withToken($adminToken)->getJson('/api/v1/quotations')->assertOk()->json('data');
        $this->assertContains($draft->id, array_column($rows, 'id'));
    }

    public function test_expired_accepted_quote_cannot_create_order_and_cross_tenant_cannot_submit(): void
    {
        [$sales] = $this->actors();
        $quote = $this->draft($sales);
        $quote->update(['status' => 'accepted', 'valid_until' => now()->subDay()]);
        $this->denied(fn () => app(SalesOrderService::class)->createFromQuotation($quote, $sales), 422);
        $other = \App\Models\Tenant::create(['code' => 'OTHER', 'name' => 'Other tenant', 'status' => 'active']);
        $quote->update(['tenant_id' => $other->id, 'status' => 'draft']);
        $token = $this->postJson('/api/v1/auth/login', ['email' => $sales->email, 'password' => 'Admin@123'])->json('data.access_token');
        $this->withToken($token)->postJson('/api/v1/quotations/'.$quote->id.'/workflow/submit')->assertNotFound();
    }

    public function test_missing_template_does_not_mark_quote_issued(): void
    {
        [$sales, $admin] = $this->actors();
        $workflow = app(QuotationWorkflow::class);
        $quote = $workflow->decide($workflow->submit($this->draft($sales), $sales), $admin, 'approved', null);
        PrintTemplate::where('module', 'quotation')->update(['status' => 'inactive']);
        $this->denied(fn () => $workflow->issue($quote, $sales, ['recipient' => 'Test', 'channel' => 'email', 'delivery_evidence' => 'Reference']), 422);
        $this->assertSame('approved', $quote->fresh()->status);
        $this->assertDatabaseMissing('quotation_issues', ['quotation_id' => $quote->id]);
    }
}
