<?php

namespace Tests\Concerns;

use App\Models\PrintTemplate;
use App\Models\Quotation;
use App\Models\User;
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
        Storage::fake('public');
        Storage::disk('public')->makeDirectory('print-templates/'.$creator->tenant_id);
        $file = 'print-templates/'.$creator->tenant_id.'/flow.docx';
        $zip = new \ZipArchive;
        $zip->open(Storage::disk('public')->path($file), \ZipArchive::CREATE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>');
        $zip->addFromString('word/document.xml', '<?xml version="1.0" encoding="UTF-8"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>{{ma_bao_gia}} / {{ten_khach_hang}} / {{tong_cong}}</w:t></w:r></w:p><w:tbl><w:tr><w:tc><w:p><w:r><w:t>{{ma_hang}} / {{so_luong}} / {{thanh_tien}}</w:t></w:r></w:p></w:tc></w:tr></w:tbl></w:body></w:document>');
        $zip->close();
        PrintTemplate::updateOrCreate(['tenant_id' => $creator->tenant_id, 'code' => 'TEST-FLOW'], ['name' => 'Test issue', 'module' => 'quotation', 'status' => 'active', 'file_path' => $file, 'is_default' => true]);
        $quote = $workflow->issue($quote, $creator, ['recipient' => 'Test customer', 'channel' => 'direct', 'delivery_evidence' => 'Test delivery']);
        $workflow->customerDecision($quote, $creator, ['decision' => 'accepted', 'evidence' => 'Test customer acceptance']);
    }
}
