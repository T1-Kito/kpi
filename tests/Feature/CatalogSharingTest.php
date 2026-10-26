<?php

namespace Tests\Feature;

use App\Models\{Customer, Sku, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogSharingTest extends TestCase
{
    use RefreshDatabase;

    public function test_private_shared_and_full_admin_visibility_and_owner_only_toggle(): void
    {
        $this->seed();
        $owner = User::where('email', 'sales@vk-kpi.local')->firstOrFail();
        $other = User::where('email', 'director@vk-kpi.local')->firstOrFail();
        $customer = Customer::create(['tenant_id'=>$owner->tenant_id,'code'=>'SHARING-TEST','name'=>'Private sharing test','created_by'=>$owner->id,'sales_owner_id'=>$other->id,'status'=>'active']);
        $sku = Sku::create(['tenant_id'=>$owner->tenant_id,'sku_code'=>'SHARING-SKU','name'=>'Private sharing SKU','created_by'=>$owner->id,'status'=>'active']);
        $login = fn ($user) => $this->postJson('/api/v1/auth/login',['email'=>$user,'password'=>'Admin@123'])->json('data.access_token');
        $otherToken=$login($other->email);
        $this->withToken($otherToken)->getJson('/api/v1/customers?q=Private%20sharing')->assertOk()->assertJsonCount(0,'data');
        $this->withToken($otherToken)->getJson('/api/v1/skus?q=SHARING-SKU')->assertOk()->assertJsonCount(0,'data');
        $this->withToken($otherToken)->getJson('/api/v1/customers/'.$customer->id)->assertNotFound();
        $adminToken=$login('admin@vk-kpi.local');
        $this->withToken($adminToken)->getJson('/api/v1/customers?q=Private%20sharing')->assertOk()->assertJsonCount(1,'data');
        $ownerToken=$login($owner->email);
        $this->withToken($ownerToken)->putJson('/api/v1/catalog-sharing/customers/'.$customer->id,['is_shared'=>true])->assertOk()->assertJsonPath('data.is_shared',true);
        $this->withToken($ownerToken)->putJson('/api/v1/catalog-sharing/skus/'.$sku->id,['is_shared'=>true])->assertOk();
        $this->withToken($otherToken)->getJson('/api/v1/customers?q=Private%20sharing')->assertOk()->assertJsonCount(1,'data');
        $this->withToken($otherToken)->getJson('/api/v1/skus?q=SHARING-SKU')->assertOk()->assertJsonCount(1,'data');
        $this->withToken($otherToken)->putJson('/api/v1/catalog-sharing/customers/'.$customer->id,['is_shared'=>false])->assertForbidden();
        $this->withToken($ownerToken)->putJson('/api/v1/catalog-sharing/customers/'.$customer->id,['is_shared'=>false])->assertOk();
        $this->withToken($otherToken)->getJson('/api/v1/customers?q=Private%20sharing')->assertOk()->assertJsonCount(0,'data');
        $this->assertSame($owner->id,$customer->fresh()->created_by);
    }

    public function test_creation_captures_creator_defaults_private_and_rejects_hidden_reference(): void
    {
        $this->seed();
        $sales=User::where('email','sales@vk-kpi.local')->firstOrFail();
        $admin=User::where('email','admin@vk-kpi.local')->firstOrFail();
        $token=$this->postJson('/api/v1/auth/login',['email'=>$sales->email,'password'=>'Admin@123'])->json('data.access_token');
        $response=$this->withToken($token)->postJson('/api/v1/customers/quick',['name'=>'New private','phone'=>'0909888111','created_by'=>$admin->id]);
        $response->assertCreated()->assertJsonPath('data.created_by',$sales->id)->assertJsonPath('data.is_shared',false);
        $hidden=Customer::create(['tenant_id'=>$sales->tenant_id,'code'=>'HIDDEN-REFERENCE','name'=>'Hidden','created_by'=>$admin->id,'status'=>'active']);
        $this->withToken($token)->postJson('/api/v1/quotations',['customer_id'=>$hidden->id,'items'=>[['sku_id'=>Sku::first()->id,'quantity'=>1]]])->assertNotFound();
    }
}
