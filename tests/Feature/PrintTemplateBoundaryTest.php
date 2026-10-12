<?php

namespace Tests\Feature;

use App\Models\PrintTemplate;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PrintTemplateBoundaryTest extends TestCase
{
    use RefreshDatabase;

    private function token(string $email): string
    {
        return $this->postJson('/api/v1/auth/login', ['email' => $email, 'password' => 'Admin@123'])->json('data.access_token');
    }

    private function file(string $field): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'word-upload-');
        $zip = new \ZipArchive;
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>');
        $zip->addFromString('word/document.xml', '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>{{'.$field.'}}</w:t></w:r></w:p></w:body></w:document>');
        $zip->close();
        $this->beforeApplicationDestroyed(fn () => is_file($path) ? unlink($path) : null);

        return new UploadedFile($path, 'mau.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', null, true);
    }

    public function test_valid_upload_is_private_and_original_download_requires_management_permission(): void
    {
        $this->seed();
        Storage::fake('local');
        Storage::fake('public');
        $token = $this->token('admin@vk-kpi.local');
        $response = $this->withToken($token)->post('/api/v1/print-templates', ['name' => 'Private quote', 'module' => 'quotation', 'content_html' => '', 'template_file' => $this->file('ma_bao_gia'), 'is_default' => false], ['Accept' => 'application/json']);
        $this->assertSame(201, $response->status(), $response->getContent());
        $path = $response->json('data.file_path');
        $id = $response->json('data.id');
        Storage::disk('local')->assertExists($path);
        Storage::disk('public')->assertMissing($path);
        $this->withToken($token)->get('/api/v1/print-templates/'.$id.'/original')->assertOk();
        $this->withToken($this->token('sales@vk-kpi.local'))->getJson('/api/v1/print-templates/'.$id.'/original')->assertForbidden();
        $this->withToken($token)->getJson('/api/v1/print-templates/999999/original')->assertNotFound();
    }

    public function test_admin_can_download_all_real_reference_docx_files(): void
    {
        $this->seed();
        $token = $this->token('admin@vk-kpi.local');

        foreach ([
            'quotation' => 'mau-bao-gia-tham-khao.docx',
            'sales_order' => 'mau-don-ban-hang-tham-khao.docx',
            'goods_issue' => 'mau-phieu-xuat-kho-tham-khao.docx',
            'contract' => 'mau-hop-dong-tham-khao.docx',
        ] as $module => $fileName) {
            $response = $this->withToken($token)->get('/api/v1/print-templates/reference/'.$module);
            $response->assertOk()->assertDownload($fileName);
            $this->assertStringStartsWith('PK', $response->streamedContent());
        }
        $this->withToken($this->token('sales@vk-kpi.local'))
            ->get('/api/v1/print-templates/reference/quotation')
            ->assertForbidden();
    }

    public function test_unknown_field_upload_does_not_create_template_or_change_default(): void
    {
        $this->seed();
        Storage::fake('local');
        $token = $this->token('admin@vk-kpi.local');
        $before = PrintTemplate::count();
        $defaults = PrintTemplate::where('is_default', true)->pluck('id')->all();
        $this->withToken($token)->post('/api/v1/print-templates', ['name' => 'Invalid quote', 'module' => 'quotation', 'template_file' => $this->file('unknown_field'), 'is_default' => true], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonStructure(['fields' => ['template_file']]);
        $this->assertSame($before, PrintTemplate::count());
        $this->assertSame($defaults, PrintTemplate::where('is_default', true)->pluck('id')->all());
        $this->assertEmpty(Storage::disk('local')->allFiles());
    }

    public function test_admin_can_delete_template_file_and_default_is_promoted_safely(): void
    {
        $this->seed();
        Storage::fake('local');
        Storage::fake('public');
        $token = $this->token('admin@vk-kpi.local');
        $tenantId = User::where('email', 'admin@vk-kpi.local')->firstOrFail()->tenant_id;
        $replacement = PrintTemplate::create([
            'tenant_id' => $tenantId,
            'code' => 'TPL-REPLACEMENT',
            'name' => 'Replacement',
            'module' => 'quotation',
            'status' => 'active',
            'is_default' => false,
        ]);
        $created = $this->withToken($token)->post('/api/v1/print-templates', [
            'name' => 'Temporary default',
            'module' => 'quotation',
            'content_html' => '',
            'template_file' => $this->file('ma_bao_gia'),
            'is_default' => true,
        ], ['Accept' => 'application/json'])->assertCreated();
        $id = $created->json('data.id');
        $path = $created->json('data.file_path');

        $this->withToken($token)->deleteJson('/api/v1/print-templates/'.$id)->assertOk()
            ->assertJsonPath('data.deleted_id', $id)
            ->assertJsonPath('data.promoted_default_id', $replacement->id);

        $this->assertDatabaseMissing('print_templates', ['id' => $id]);
        $this->assertDatabaseHas('print_templates', ['id' => $replacement->id, 'is_default' => true]);
        Storage::disk('local')->assertMissing($path);
    }

    public function test_template_delete_is_permission_and_tenant_scoped(): void
    {
        $this->seed();
        $tenantId = User::where('email', 'admin@vk-kpi.local')->firstOrFail()->tenant_id;
        $template = PrintTemplate::create([
            'tenant_id' => $tenantId,
            'code' => 'TPL-LOCAL',
            'name' => 'Local template',
            'module' => 'quotation',
            'status' => 'active',
        ]);

        $this->withToken($this->token('sales@vk-kpi.local'))
            ->deleteJson('/api/v1/print-templates/'.$template->id)
            ->assertForbidden();

        $otherTenant = Tenant::create(['code' => 'PRINT-OTHER', 'name' => 'Other company', 'status' => 'active']);
        $other = PrintTemplate::create([
            'tenant_id' => $otherTenant->id,
            'code' => 'TPL-OTHER',
            'name' => 'Other tenant',
            'module' => 'quotation',
            'status' => 'active',
        ]);
        $this->withToken($this->token('admin@vk-kpi.local'))
            ->deleteJson('/api/v1/print-templates/'.$other->id)
            ->assertNotFound();
        $this->assertDatabaseHas('print_templates', ['id' => $other->id]);
    }
}
