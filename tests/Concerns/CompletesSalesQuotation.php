<?php
namespace Tests\Concerns;

use App\Models\{PrintTemplate, Quotation, User};
use App\Services\Print\QuotationDocxMergeService;
use App\Support\QuotationWorkflow;
use Illuminate\Support\Facades\Storage;

trait CompletesSalesQuotation
{
    private function completeSalesQuotation(int $id): void
    {
        $quote = Quotation::findOrFail($id);
        $creator = User::findOrFail($quote->created_by);
        $reviewer = User::where('email', 'director@vk-kpi.local')->firstOrFail();
        $settings = $creator->tenant->ui_settings ?? [];
        $settings['sales_quotation_approval'] = ['approvers' => [$reviewer->id], 'exception_approvers' => []];
        $creator->tenant->update(['ui_settings' => $settings]);
        $quote->update(['valid_until' => now()->addDays(15)]);
        $workflow = app(QuotationWorkflow::class);
        $quote = $workflow->submit($quote, $creator);
        $quote = $workflow->decide($quote, $reviewer, 'approved', null);
        Storage::fake('local');
        PrintTemplate::firstOrCreate(['tenant_id' => $creator->tenant_id, 'code' => 'TEST-FLOW'], ['name' => 'Test issue', 'module' => 'quotation', 'status' => 'active', 'file_path' => 'test.docx', 'is_default' => true]);
        $temp = tempnam(sys_get_temp_dir(), 'test-quote-'); file_put_contents($temp, 'test quotation');
        $this->mock(QuotationDocxMergeService::class)->shouldReceive('merge')->once()->andReturn($temp);
        $quote = $workflow->issue($quote, $creator, ['recipient' => 'Test customer', 'channel' => 'direct', 'delivery_evidence' => 'Test delivery']);
        $workflow->customerDecision($quote, $creator, ['decision' => 'accepted', 'evidence' => 'Test customer acceptance']);
    }
}
