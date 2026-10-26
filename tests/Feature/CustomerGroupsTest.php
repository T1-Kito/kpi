<?php
namespace Tests\Feature;

use App\Models\{Customer, CustomerGroup, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerGroupsTest extends TestCase
{
    use RefreshDatabase;

    public function test_tree_is_tenant_scoped_three_levels_and_customer_selection_is_validated(): void
    {
        $this->seed();
        $admin=User::where('email','admin@vk-kpi.local')->firstOrFail();
        $this->withToken(app(\App\Support\JwtTokenService::class)->issue($admin));
        $create=fn($name,$parent=null)=>$this->postJson('/api/v1/customer-groups',['name'=>$name,'parent_id'=>$parent,'is_active'=>true])->assertCreated()->json('data.id');
        $a=$create('Khách đoàn'); $b=$create('Trường học',$a); $c=$create('Tiểu học',$b);
        $this->postJson('/api/v1/customer-groups',['name'=>'Cấp bốn','parent_id'=>$c,'is_active'=>true])->assertUnprocessable();
        $this->postJson('/api/v1/customer-groups',['name'=>'Trường học','parent_id'=>$a,'is_active'=>true])->assertUnprocessable();
        $id=$this->postJson('/api/v1/customers',['name'=>'Trường mới','customer_group_id'=>$c])->assertCreated()->json('data.id');
        $this->getJson('/api/v1/customers?customer_group_id='.$a)->assertOk()->assertJsonPath('meta.total',1);
        $this->putJson('/api/v1/customer-groups/'.$b,['name'=>'Giáo dục','parent_id'=>$a,'is_active'=>false])->assertOk();
        $this->postJson('/api/v1/customers',['name'=>'Không hợp lệ','customer_group_id'=>$c])->assertUnprocessable();
        $this->putJson('/api/v1/customers/'.$id,['name'=>'Giữ nhóm cũ','customer_group_id'=>$c])->assertOk();
        $this->putJson('/api/v1/customer-groups/'.$a,['name'=>'Chuyển sai','parent_id'=>$c,'is_active'=>true])->assertUnprocessable();
        $foreign=CustomerGroup::create(['tenant_id'=>999,'name'=>'Khác công ty','is_active'=>true]);
        $this->postJson('/api/v1/customers',['name'=>'Sai công ty','customer_group_id'=>$foreign->id])->assertUnprocessable();
        $this->getJson('/api/v1/customer-groups')->assertJsonCount(3,'data');
        $sales=User::where('email','sales@vk-kpi.local')->firstOrFail();
        $this->withToken(app(\App\Support\JwtTokenService::class)->issue($sales));
        $this->postJson('/api/v1/customer-groups',['name'=>'Tự tạo','is_active'=>true])->assertForbidden();
    }
}
