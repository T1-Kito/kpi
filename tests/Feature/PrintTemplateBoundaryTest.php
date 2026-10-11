<?php

namespace Tests\Feature;

use App\Models\PrintTemplate;
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
}
