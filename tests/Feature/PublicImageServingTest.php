<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PublicImageServingTest extends TestCase
{
    public function test_uploaded_public_image_is_served_without_symlink_or_signed_url(): void
    {
        Storage::fake('public');
        $path='tenants/1/products/portable-image.png';
        Storage::disk('public')->put($path,base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII='));
        $response=$this->get('/storage/'.$path)->assertOk();
        $this->assertStringContainsString('sandbox',$response->headers->get('Content-Security-Policy'));
    }

    public function test_public_url_cannot_read_private_file_with_same_path(): void
    {
        Storage::fake('public'); Storage::fake('local');
        Storage::disk('local')->put('tenants/1/products/private.png','PRIVATE_FIXTURE');
        $this->get('/storage/tenants/1/products/private.png')->assertNotFound();
        $this->get('/private-storage/tenants/1/products/private.png')->assertStatus(403);
    }

    public function test_missing_public_image_returns_not_found_instead_of_permission_error(): void
    {
        Storage::fake('public');
        $this->get('/storage/tenants/1/products/missing.png')->assertNotFound();
    }
}
