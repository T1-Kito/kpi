<?php

namespace Tests\Feature;

use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoginBrandingTest extends TestCase
{
    use RefreshDatabase;

    public function test_sidebar_displays_default_logo_before_scripts_load(): void
    {
        $html = view('partials.sidebar')->render();
        $this->assertStringContainsString('class="brand-image" src="/assets/branding/vk-software-logo.png"', $html);
        $this->assertStringContainsString('data-default-logo="/assets/branding/vk-software-logo.png"', $html);
        $this->assertStringContainsString('class="brand-logo hidden"', $html);
    }

    public function test_default_login_uses_supplied_vk_logo(): void
    {
        $this->get('/')->assertOk()->assertSee('assets/branding/vk-software-logo.png')->assertSee('alt="VK Software"',false);
        $this->assertSame(hash_file('sha256',base_path('VK-Logo.png')),hash_file('sha256',public_path('assets/branding/vk-software-logo.png')));
    }

    public function test_company_name_does_not_replace_default_logo(): void
    {
        $tenant=Tenant::create(['code'=>'BRAND','name'=>'SƠN TIÊN123','status'=>'active']);
        $this->get('/')->assertOk()->assertSee('assets/branding/vk-software-logo.png');
        $this->assertSame('SƠN TIÊN123',$tenant->fresh()->name);
    }

    public function test_explicit_company_logo_is_preserved(): void
    {
        Tenant::create(['code'=>'BRAND','name'=>'Company','status'=>'active','logo_path'=>'logos/custom.png']);
        $this->get('/')->assertOk()->assertSee('/storage/logos/custom.png')->assertDontSee('assets/branding/vk-software-logo.png');
    }
}
