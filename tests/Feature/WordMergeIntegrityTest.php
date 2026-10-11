<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\PrintTemplate;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Services\Print\QuotationDocxMergeService;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;
use ZipArchive;

class WordMergeIntegrityTest extends TestCase
{
    private function document(): Quotation
    {
        $document = new Quotation(['tenant_id' => 1, 'code' => 'QUO-TEST', 'total_amount' => 1080]);
        $document->setRelation('customer', new Customer(['name' => 'Công ty A & B', 'code' => 'CUS-TEST']));
        $document->setRelation('items', collect());
        $document->setRelation('salesOwner', null);

        return $document;
    }

    private function template(string $body, string $header = ''): PrintTemplate
    {
        Storage::fake('public');
        Storage::disk('public')->makeDirectory('print-templates/1');
        $path = 'print-templates/1/test.docx';
        $zip = new ZipArchive;
        $zip->open(Storage::disk('public')->path($path), ZipArchive::CREATE);
        $xml = fn ($text) => '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'.$text.'</w:body></w:document>';
        $zip->addFromString('word/document.xml', $xml($body));
        if ($header) {
            $zip->addFromString('word/header1.xml', $xml($header));
        }
        $zip->close();

        return new PrintTemplate(['tenant_id' => 1, 'module' => 'quotation', 'status' => 'active', 'file_path' => $path]);
    }

    public function test_split_fields_and_headers_merge_without_destroying_labels(): void
    {
        $template = $this->template('<w:p><w:r><w:t>Tên: {{ten_</w:t></w:r><w:r><w:t>khach_hang}} / {{ma_khach_hang}}</w:t></w:r></w:p>', '<w:p><w:r><w:t>{{ma_bao_gia}}</w:t></w:r></w:p>');
        $path = app(QuotationDocxMergeService::class)->merge($this->document(), $template);
        try {
            $zip = new ZipArchive;
            $zip->open($path);
            $xml = $zip->getFromName('word/document.xml');
            $this->assertStringContainsString('Tên: Công ty A &amp; B', $xml);
            $this->assertStringContainsString('CUS-TEST', $xml);
            $this->assertStringNotContainsString('{{', $xml);
            $this->assertStringContainsString('QUO-TEST', $zip->getFromName('word/header1.xml'));
        } finally {
            $zip->close();
            unlink($path);
        }
    }

    public function test_unknown_fields_stop_export_and_delete_incomplete_file(): void
    {
        $template = $this->template('<w:p><w:r><w:t>{{unknown_field}}</w:t></w:r></w:p>');
        $before = glob(storage_path('app/generated/print-templates/*.docx'));
        try {
            app(QuotationDocxMergeService::class)->merge($this->document(), $template);
            $this->fail('Unknown fields must not leave an export.');
        } catch (ValidationException $error) {
            $this->assertStringContainsString('unknown_field', $error->getMessage());
            $this->assertSame($before, glob(storage_path('app/generated/print-templates/*.docx')));
        }
    }

    public function test_wrong_company_and_wrong_module_are_rejected(): void
    {
        foreach ([['tenant_id' => 2], ['module' => 'contract'], ['status' => 'inactive']] as $change) {
            $template = new PrintTemplate(['tenant_id' => 1, 'module' => 'quotation', 'status' => 'active', ...$change]);
            try {
                app(QuotationDocxMergeService::class)->merge($this->document(), $template);
                $this->fail('Invalid template must fail.');
            } catch (ValidationException $error) {
                $this->assertArrayHasKey('template_file', $error->errors());
            }
        }
    }

    public function test_upload_validation_reports_unknown_fields_before_saving(): void
    {
        $template = $this->template('<w:p><w:r><w:t>{{unsupported}}</w:t></w:r></w:p>');
        $this->expectException(ValidationException::class);
        app(QuotationDocxMergeService::class)->validateTemplateFile(Storage::disk('public')->path($template->file_path), ['ma_bao_gia']);
    }

    public function test_split_item_row_repeats_for_each_item(): void
    {
        $template = $this->template('<w:tbl><w:tr><w:tc><w:p><w:r><w:t>{{ma_</w:t></w:r><w:r><w:t>hang}}</w:t></w:r></w:p></w:tc></w:tr></w:tbl>');
        $document = $this->document();
        $items = collect(['SKU-A', 'SKU-B'])->map(function ($code) {
            $item = new QuotationItem(['sku_code' => $code, 'name' => $code, 'quantity' => 1]);
            $item->setRelation('sku', null);

            return $item;
        });
        $document->setRelation('items', $items);
        $path = app(QuotationDocxMergeService::class)->merge($document, $template);
        $zip = new ZipArchive;
        $zip->open($path);
        try {
            $xml = $zip->getFromName('word/document.xml');
            $this->assertSame(2, substr_count($xml, '<w:tr>'));
            $this->assertStringContainsString('SKU-A', $xml);
            $this->assertStringContainsString('SKU-B', $xml);
        } finally {
            $zip->close();
            unlink($path);
        }
    }
}
