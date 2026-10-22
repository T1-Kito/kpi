<?php
namespace Tests\Feature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use App\Models\User;
use Illuminate\Support\Str;
class InternalChatTest extends TestCase {
    use RefreshDatabase;
    private function loginAs(string $email): string {
        return $this->postJson('/api/v1/auth/login',['email'=>$email,'password'=>'Admin@123'])->assertOk()->json('data.access_token');
    }
    public function test_chat_is_member_only_and_repeat_safe(): void {
        $this->seed();
        $admin=$this->loginAs('admin@vk-kpi.local');$sales=$this->loginAs('sales@vk-kpi.local');$outsider=$this->loginAs('warehouse@vk-kpi.local');
        $user=User::where('email','sales@vk-kpi.local')->firstOrFail();
        $id=$this->withToken($admin)->postJson('/api/v1/chat',['user_ids'=>[$user->id]])->assertCreated()->json('data.id');
        $this->withToken($admin)->postJson('/api/v1/chat',['user_ids'=>[$user->id]])->assertJsonPath('data.id',$id);
        $payload=['body'=>'Xin chào <script>','client_id'=>(string)Str::uuid()];
        $mid=$this->withToken($admin)->postJson("/api/v1/chat/$id/messages",$payload)->assertCreated()->json('data.id');
        $this->withToken($admin)->postJson("/api/v1/chat/$id/messages",$payload)->assertJsonPath('data.id',$mid);
        $this->assertDatabaseCount('chat_messages',1);
        $this->withToken($sales)->getJson('/api/v1/chat')->assertJsonPath('data.0.unread',1);
        $this->withToken($sales)->postJson("/api/v1/chat/$id/read",['message_id'=>$mid])->assertOk();
        $this->withToken($sales)->getJson('/api/v1/chat')->assertJsonPath('data.0.unread',0);
        $this->withToken($outsider)->getJson("/api/v1/chat/$id/messages")->assertNotFound();
        $this->withToken($outsider)->postJson("/api/v1/chat/$id/messages",['body'=>'No','client_id'=>(string)Str::uuid()])->assertNotFound();
        $this->withToken($admin)->postJson('/api/v1/chat',['user_ids'=>[99999]])->assertUnprocessable();
        $warehouse=User::where('email','warehouse@vk-kpi.local')->firstOrFail();
        $this->withToken($admin)->postJson('/api/v1/chat',['user_ids'=>[$user->id,$warehouse->id]])->assertUnprocessable();
        $group=$this->withToken($admin)->postJson('/api/v1/chat',['name'=>'Nhóm xử lý','user_ids'=>[$user->id,$warehouse->id]])->assertCreated()->json('data.id');
        $this->withToken($sales)->getJson("/api/v1/chat/$group/messages")->assertOk();
        $foreign=\App\Models\Tenant::create(['code'=>'OTHER-CHAT','name'=>'Other','status'=>'active']);
        $foreignUser=User::create(['tenant_id'=>$foreign->id,'name'=>'Other','email'=>'other-chat@example.com','password'=>'Test@123','is_active'=>true]);
        $this->withToken($admin)->postJson('/api/v1/chat',['user_ids'=>[$foreignUser->id]])->assertUnprocessable();
        $this->withToken($admin)->postJson("/api/v1/chat/$group/read",['message_id'=>$mid])->assertUnprocessable();
        $this->withToken($sales)->postJson("/api/v1/chat/$id/messages",['body'=>'   ','client_id'=>(string)Str::uuid()])->assertUnprocessable();
    }
}
