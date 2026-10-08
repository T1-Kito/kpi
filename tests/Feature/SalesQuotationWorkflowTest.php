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
        $order = app(SalesOrderService::class)->createFromQuotation($quote, $sales);
        $this->assertEquals(1800.02, $order->subtotal_amount);
        $this->assertEquals($quote->total_amount, $order->total_amount);
        $this->denied(fn () => app(SalesOrderService::class)->createFromQuotation($quote, $sales), 422);
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
