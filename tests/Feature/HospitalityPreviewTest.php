<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\JwtTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class HospitalityPreviewTest extends TestCase
{
    use RefreshDatabase;
    private function adminToken(): string
    {
        $this->seed();
        $user=User::where('email','admin@vk-kpi.local')->firstOrFail();
        config(['hospitality.base_url'=>'https://sandbox.example.test','hospitality.username'=>'integration-test',
            'hospitality.password'=>'secret-test','hospitality.tenant_id'=>$user->tenant_id]);
        return app(JwtTokenService::class)->issue($user);
    }

    public function test_connection_is_scoped_and_ordinary_users_cannot_read(): void
    {
        Http::preventStrayRequests();$token=$this->adminToken();
        $this->withToken($token)->getJson('/api/v1/integrations/hospitality/status')->assertOk()->assertJsonPath('data.configured',true)->assertDontSee('secret-test');
        config(['hospitality.tenant_id'=>99999]);
        $this->withToken($token)->getJson('/api/v1/integrations/hospitality/read?resource=rooms')->assertStatus(422);
        $sales=User::where('email','sales@vk-kpi.local')->firstOrFail();
        $this->withToken(app(JwtTokenService::class)->issue($sales))->getJson('/api/v1/integrations/hospitality/status')->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_reader_authenticates_refreshes_csrf_reads_and_logs_out_without_exposing_private_fields(): void
    {
        $token=$this->adminToken();Http::preventStrayRequests();
        Http::fake([
            '*/session/csrf'=>Http::sequence()
                ->push(['headerName'=>'X-CSRF-TOKEN','requestToken'=>'csrf-test'])
                ->push(['headerName'=>'X-CSRF-TOKEN','requestToken'=>'csrf-refreshed']),
            '*/session/login'=>Http::response(['userId'=>1]),
            '*/hospitality/guests*'=>Http::response(['items'=>[['id'=>12,'fullName'=>'Khách sandbox','ccid'=>'sensitive','photo'=>'secret-photo']],'total'=>45,'page'=>2,'pageSize'=>20]),
            '*/session/logout'=>Http::response(null,204),
        ]);
        $this->withToken($token)->getJson('/api/v1/integrations/hospitality/read?resource=guests&page=2&search=Nguyen')->assertOk()
            ->assertJsonPath('data.total',45)->assertJsonPath('data.page',2)->assertJsonPath('data.items.0.id',12)->assertDontSee('sensitive')->assertDontSee('secret-photo');
        Http::assertSent(fn($r)=>str_ends_with($r->url(),'/session/login') && $r->hasHeader('X-CSRF-TOKEN','csrf-test') && $r['userName']==='integration-test');
        Http::assertSent(fn($r)=>str_contains($r->url(),'/hospitality/guests') && $r->method()==='GET' && $r['page']==2 && !$r->hasHeader('X-CSRF-TOKEN'));
        Http::assertSent(fn($r)=>str_ends_with($r->url(),'/session/logout') && $r->header('X-CSRF-TOKEN')===['csrf-refreshed']);
        Http::assertSentCount(5);
    }

    public function test_authentication_failure_and_invalid_contract_are_reported_without_raw_source_errors(): void
    {
        $token=$this->adminToken();
        Http::fake(['*/session/csrf'=>Http::response(['headerName'=>'X-CSRF','requestToken'=>'x']), '*/session/login'=>Http::response(['debug'=>'private server details'],401)]);
        $this->withToken($token)->getJson('/api/v1/integrations/hospitality/read?resource=rooms')->assertStatus(502)->assertJsonPath('message','Đăng nhập API không thành công. Kiểm tra tài khoản, quyền và CSRF.')->assertDontSee('private server details');
    }

    public function test_http_requires_opt_in_and_can_read_from_the_configured_port(): void
    {
        $token = $this->adminToken();
        $base = 'http://192.0.2.10:5210/api/v1';
        config(['hospitality.base_url' => 'http://192.0.2.10:5210', 'hospitality.allow_http' => false]);
        Http::preventStrayRequests();
        Http::fake([
            "$base/session/csrf" => Http::response(['headerName' => 'X-CSRF', 'requestToken' => 'test-token']),
            "$base/session/login" => Http::response(['userId' => 1]),
            "$base/hospitality/rooms*" => Http::response(['items' => [['id' => 12]]]),
            "$base/session/logout" => Http::response(null, 204),
        ]);

        $endpoint = '/api/v1/integrations/hospitality/read?resource=rooms';
        $this->withToken($token)->getJson($endpoint)->assertStatus(502)
            ->assertJsonPath('message', 'Kết nối HTTP nội bộ chưa được bật cho FaceID trên máy chủ CRM.');
        Http::assertNothingSent();

        config(['hospitality.allow_http' => true]);
        $this->withToken($token)->getJson($endpoint)->assertOk()->assertJsonPath('data.items.0.id', 12);
        Http::assertSentCount(5);
    }

    public function test_unconfigured_unknown_and_write_resources_never_call_source(): void
    {
        $token=$this->adminToken();Http::preventStrayRequests();
        $this->withToken($token)->getJson('/api/v1/integrations/hospitality/read?resource=device-access')->assertUnprocessable();
        $this->withToken($token)->getJson('/api/v1/integrations/hospitality/read?resource=stays')->assertUnprocessable();
        $this->withToken($token)->getJson('/api/v1/integrations/hospitality/read?resource=rooms&pageSize=201')->assertUnprocessable();
        config(['hospitality.base_url'=>'']);
        $this->withToken($token)->getJson('/api/v1/integrations/hospitality/read?resource=rooms')->assertUnprocessable();
        Http::assertNothingSent();
    }
}
