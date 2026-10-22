<?php
namespace Tests\Feature;
use App\Models\{Customer,ServiceTicket,TicketAttachment,User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
class TicketAttachmentTest extends TestCase
{
    use RefreshDatabase;
    private function setupTicket():array {
        $this->seed();Storage::fake('local');
        $u=User::where('email','admin@vk-kpi.local')->firstOrFail();
        $token=$this->postJson('/api/v1/auth/login',['email'=>$u->email,'password'=>'Admin@123'])->json('data.access_token');
        $customer=Customer::where('tenant_id',$u->tenant_id)->firstOrFail();
        $id=$this->withToken($token)->postJson('/api/v1/service-tickets',['customer_id'=>$customer->id,'subject'=>'Hàng hỏng','category'=>'product','priority'=>'normal'])->assertCreated()->json('data.id');
        return [$token,$id,$u];
    }
    private function image():UploadedFile {
        return UploadedFile::fake()->createWithContent('bang-chung.png',base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aJ1kAAAAASUVORK5CYII='));
    }
    public function test_private_upload_download_duplicate_and_closed_guards():void {
        [$token,$id]=$this->setupTicket();
        $data=$this->withToken($token)->post('/api/v1/service-tickets/'.$id.'/attachments',['file'=>$this->image()])->assertCreated()->json('data');
        $this->assertArrayNotHasKey('path',$data);$this->assertArrayNotHasKey('sha256',$data);
        $attachment=TicketAttachment::findOrFail($data['id']);Storage::disk('local')->assertExists($attachment->path);
        $this->withToken($token)->getJson('/api/v1/service-tickets/'.$id)->assertOk()->assertJsonCount(1,'data.attachments');
        $this->withToken($token)->get('/api/v1/service-tickets/'.$id.'/attachments/'.$attachment->id)->assertOk()->assertHeader('X-Content-Type-Options','nosniff');
        $this->withToken($token)->post('/api/v1/service-tickets/'.$id.'/attachments',['file'=>$this->image()])->assertUnprocessable();
        ServiceTicket::whereKey($id)->update(['status'=>'closed']);
        $this->withToken($token)->post('/api/v1/service-tickets/'.$id.'/attachments',['file'=>$this->image()])->assertUnprocessable();
        $this->assertDatabaseCount('ticket_attachments',1);
    }
    public function test_invalid_file_and_foreign_tenant_are_rejected():void {
        [$token,$id]=$this->setupTicket();
        $this->withToken($token)->post('/api/v1/service-tickets/'.$id.'/attachments',['file'=>UploadedFile::fake()->createWithContent('bad.svg','<svg/>')])->assertUnprocessable();
        $foreign=\App\Models\Tenant::create(['code'=>'ATT-FOREIGN','name'=>'Other','status'=>'active']);
        ServiceTicket::whereKey($id)->update(['tenant_id'=>$foreign->id]);
        $this->withToken($token)->post('/api/v1/service-tickets/'.$id.'/attachments',['file'=>$this->image()])->assertNotFound();
        $this->assertDatabaseCount('ticket_attachments',0);
        $this->assertSame([],Storage::disk('local')->allFiles());
    }
    public function test_unassigned_staff_cannot_read_or_upload_and_tampered_file_is_refused():void {
        [$token,$id]=$this->setupTicket();
        $attachmentId=$this->withToken($token)->post('/api/v1/service-tickets/'.$id.'/attachments',['file'=>$this->image()])->assertCreated()->json('data.id');
        $salesToken=$this->postJson('/api/v1/auth/login',['email'=>'sales@vk-kpi.local','password'=>'Admin@123'])->json('data.access_token');
        $this->withToken($salesToken)->getJson('/api/v1/service-tickets/'.$id)->assertNotFound();
        $this->withToken($salesToken)->get('/api/v1/service-tickets/'.$id.'/attachments/'.$attachmentId)->assertNotFound();
        $this->withToken($salesToken)->post('/api/v1/service-tickets/'.$id.'/attachments',['file'=>$this->image()])->assertNotFound();
        $sales=User::where('email','sales@vk-kpi.local')->firstOrFail();
        ServiceTicket::whereKey($id)->update(['assignee_id'=>$sales->id]);
        $this->withToken($salesToken)->get('/api/v1/service-tickets/'.$id.'/attachments/'.$attachmentId)->assertOk();
        $a=TicketAttachment::findOrFail($attachmentId);Storage::disk('local')->put($a->path,'corrupted');
        $this->withToken($salesToken)->get('/api/v1/service-tickets/'.$id.'/attachments/'.$attachmentId)->assertStatus(409);
    }
}
