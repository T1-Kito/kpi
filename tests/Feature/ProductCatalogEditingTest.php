<?php
namespace Tests\Feature;
use App\Models\{ProductCategory,ProductBrand,Product,Tenant};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
class ProductCatalogEditingTest extends TestCase
{
    use RefreshDatabase;
    public function test_catalog_edit_delete_usage_guard_duplicates_and_tenant_boundary(): void
    {
        $this->seed();
        $token=$this->postJson('/api/v1/auth/login',['email'=>'admin@vk-kpi.local','password'=>'Admin@123'])->json('data.access_token');
        $tenant=\App\Models\User::where('email','admin@vk-kpi.local')->value('tenant_id');
        $other=Tenant::create(['code'=>'CAT-OTHER','name'=>'Other','status'=>'active']);
        foreach([['product-categories',ProductCategory::class,'category_id'],['product-brands',ProductBrand::class,'brand_id']] as [$path,$model,$column]){
            $item=$model::create(['tenant_id'=>$tenant,'name'=>'Editable','status'=>'active']);
            $duplicate=$model::create(['tenant_id'=>$tenant,'name'=>'Duplicate','status'=>'active']);
            $this->withToken($token)->putJson('/api/v1/'.$path.'/'.$item->id,['name'=>' Updated ','status'=>'inactive'])->assertOk()->assertJsonPath('data.name','Updated');
            $this->putJson('/api/v1/'.$path.'/'.$item->id,['name'=>'Duplicate','status'=>'active'])->assertUnprocessable();
            $foreign=$model::create(['tenant_id'=>$other->id,'name'=>'Foreign','status'=>'active']);
            $this->deleteJson('/api/v1/'.$path.'/'.$foreign->id)->assertNotFound();
            $this->putJson('/api/v1/'.$path.'/'.$foreign->id,['name'=>'Bad','status'=>'active'])->assertNotFound();
            $product=Product::create(['tenant_id'=>$tenant,'code'=>'CAT-'.$column,'name'=>'Linked','status'=>'active',$column=>$item->id]);
            $this->deleteJson('/api/v1/'.$path.'/'.$item->id)->assertUnprocessable();
            $this->assertNotNull($item->fresh());
            $product->delete();
            $this->deleteJson('/api/v1/'.$path.'/'.$item->id)->assertOk();
            $this->assertNull($item->fresh());
        }
        $token=$this->postJson('/api/v1/auth/login',['email'=>'sales@vk-kpi.local','password'=>'Admin@123'])->json('data.access_token');
        $this->withToken($token)->deleteJson('/api/v1/product-categories/1')->assertForbidden();
        $this->putJson('/api/v1/product-brands/1',['name'=>'No','status'=>'active'])->assertForbidden();
    }
}
