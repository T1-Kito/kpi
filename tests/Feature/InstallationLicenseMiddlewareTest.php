<?php

namespace Tests\Feature;

use Tests\TestCase;

class InstallationLicenseMiddlewareTest extends TestCase
{
    public function test_protected_release_blocks_requests_without_license_even_without_launcher(): void
    {
        config(['installation-license.required'=>true]);
        $this->getJson('/api/v1/nonexistent-license-test')->assertForbidden()->assertJsonPath('error_code','LICENSE_REQUIRED');
        $this->get('/up')->assertForbidden()->assertSee('Bản quyền');
    }

    public function test_development_does_not_require_customer_license(): void
    {
        config(['installation-license.required'=>false]);
        $this->get('/up')->assertOk();
    }
}
