<?php

namespace Tests\Feature;

use App\Models\{Customer, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerPersonnelBoundaryTest extends TestCase
{
    use RefreshDatabase;

    private function login(string $email): string
    {
        return $this->postJson('/api/v1/auth/login', ['email'=>$email,'password'=>'Admin@123'])->assertOk()->json('data.access_token');
    }

    public function test_duplicate_lookup_does_not_disclose_another_employees_customer(): void
    {
        $this->seed();
        $admin=User::where('email','admin@vk-kpi.local')->firstOrFail();
        $customer=Customer::create(['tenant_id'=>$admin->tenant_id,'code'=>'PRIVATE-CUSTOMER','name'=>'Private customer','tax_code'=>'0123456789','phone'=>'0999876543','sales_owner_id'=>$admin->id,'status'=>'active']);
        $token=$this->login('sales@vk-kpi.local');
        foreach ([['name'=>'Attempt','phone'=>$customer->phone], ['name'=>'Attempt','phone'=>'0999111222','tax_code'=>$customer->tax_code]] as $payload) {
            $this->withToken($token)->postJson('/api/v1/customers/quick',$payload)->assertConflict()->assertJsonMissingPath('data');
        }
        \Illuminate\Support\Facades\Http::fake();
        $this->withToken($token)->getJson('/api/v1/customers/tax-lookup/'.$customer->tax_code)->assertConflict()->assertJsonMissingPath('data');
        \Illuminate\Support\Facades\Http::assertNothingSent();
        $this->withToken($this->login($admin->email))->postJson('/api/v1/customers/quick',['name'=>'Existing','phone'=>$customer->phone])->assertOk()->assertJsonPath('data.id',$customer->id);
    }

    public function test_customer_assignment_checks_scope_and_active_personnel(): void
    {
        $this->seed();
        $sales=User::where('email','sales@vk-kpi.local')->firstOrFail();
        $admin=User::where('email','admin@vk-kpi.local')->firstOrFail();
        $token=$this->login($sales->email);
        // Sales cannot change master data by default; grant only the existing write permission for this boundary test.
        $sales->roles()->first()->permissions()->syncWithoutDetaching([\App\Models\Permission::where('code','customer.manage')->value('id')]);
        $created=$this->withToken($token)->postJson('/api/v1/customers',['name'=>'Assigned automatically'])->assertCreated()->assertJsonPath('data.sales_owner_id',$sales->id)->json('data');
        $this->withToken($token)->putJson('/api/v1/customers/'.$created['id'],['name'=>'Not transferable','sales_owner_id'=>$admin->id])->assertForbidden();
        $this->withToken($token)->putJson('/api/v1/customers/'.$created['id'],['name'=>'Not unassigned','sales_owner_id'=>null])->assertForbidden();
        $adminToken=$this->login($admin->email);
        $this->withToken($adminToken)->postJson('/api/v1/users/'.$admin->id.'/status',['is_active'=>false])->assertUnprocessable();
        $sales->update(['is_active'=>false]);
        $this->withToken($adminToken)->postJson('/api/v1/users',['name'=>'New employee','email'=>'new.employee@example.test','password'=>'StrongPass123','manager_id'=>$sales->id])->assertUnprocessable();
        $this->withToken($adminToken)->postJson('/api/v1/customers',['name'=>'Inactive owner','sales_owner_id'=>$sales->id])->assertUnprocessable();
        $this->assertDatabaseHas('customers',['id'=>$created['id'],'sales_owner_id'=>$sales->id]);
    }
}
