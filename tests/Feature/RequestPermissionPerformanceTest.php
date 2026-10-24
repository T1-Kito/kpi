<?php
namespace Tests\Feature;
use App\Models\{User,Permission};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
class RequestPermissionPerformanceTest extends TestCase
{
    use RefreshDatabase;
    public function test_request_snapshot_avoids_repeated_queries_and_new_request_sees_revocation():void {
        $this->seed();$actor=User::with('roles.permissions')->where('email','admin@vk-kpi.local')->firstOrFail();$actor->snapshotAuthorizationForRequest();
        DB::enableQueryLog();DB::flushQueryLog();
        for($i=0;$i<20;$i++){$this->assertTrue($actor->hasPermission('customer.manage'));$actor->isSpAdmin();$actor->effectivePermissionCodes();}
        $this->assertCount(0,DB::getQueryLog());DB::disableQueryLog();
        $permission=Permission::where('code','customer.manage')->firstOrFail();foreach($actor->roles as $role)$role->permissions()->detach($permission->id);
        $fresh=User::with('roles.permissions')->findOrFail($actor->id);$fresh->snapshotAuthorizationForRequest();$this->assertFalse($fresh->hasPermission('customer.manage'));
    }
    public function test_reserved_chairman_permission_is_not_inferred_from_ordinary_grants():void {
        $this->seed();$actor=User::where('email','sales@vk-kpi.local')->firstOrFail();$actor->snapshotAuthorizationForRequest();
        $this->assertFalse($actor->isSpAdmin());$this->assertFalse($actor->hasPermission(\App\Support\SystemAccount::PERMISSION));
    }
}
