<?php
namespace Tests\Feature;
use App\Models\{User,Department,Position,Tenant};
use App\Support\OrganizationSourceImport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class OrganizationSourceImportTest extends TestCase
{
    use RefreshDatabase;
    private User $actor;private string $token;
    protected function setUp():void{parent::setUp();$this->seed();$this->actor=User::where('email','admin@vk-kpi.local')->firstOrFail();$this->token=app(\App\Support\JwtTokenService::class)->issue($this->actor);}
    private function preview(string $kind,array $rows,bool $technical=false){
        $labels=OrganizationSourceImport::labels($kind);if(!$technical)unset($labels['schema_version'],$labels['source_revision']);
        $path=tempnam(sys_get_temp_dir(),'organization-test-');copy(resource_path('templates/import/'.$kind.'-v1.xlsx'),$path);
        try{$zip=new \ZipArchive;$zip->open($path);$xml='<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';$values=[array_values($labels)];foreach($rows as $row)$values[]=array_map(fn($key)=>(string)($row[$key] ?? ''),array_keys($labels));
            foreach($values as $r=>$cells){$xml.='<row r="'.($r+1).'">';foreach($cells as $c=>$value)$xml.='<c r="'.chr(65+$c).($r+1).'" t="inlineStr"><is><t>'.htmlspecialchars($value,ENT_XML1).'</t></is></c>';$xml.='</row>';}
            $zip->addFromString('xl/worksheets/sheet1.xml',$xml.'</sheetData></worksheet>');$zip->setMtimeName('xl/worksheets/sheet1.xml',946684800);$zip->close();
            return $this->withToken($this->token)->post('/api/v1/import-center/organization/'.$kind.'/preview',['file'=>UploadedFile::fake()->createWithContent('mau.xlsx',file_get_contents($path))],['Accept'=>'application/json']);
        }finally{unlink($path);}
    }
    private function commit($id){return $this->withToken($this->token)->postJson('/api/v1/import-center/batches/'.$id.'/commit',['confirmed'=>true]);}
    private function imported(string $kind,array $rows){$id=$this->preview($kind,$rows)->assertCreated()->assertJsonPath('data.status','ready')->json('data.id');$this->commit($id)->assertOk();return $id;}
    public function test_positions_update_auto_codes_duplicates_and_conflicts():void{
        $before=Position::count();$id=$this->imported('positions',[['external_id'=>'CV-01','name'=>'Chức vụ nhập thử']]);$item=Position::where('name','Chức vụ nhập thử')->firstOrFail();$this->assertStringStartsWith('POS-',$item->code);$this->assertSame($before+1,Position::count());
        $this->commit($id)->assertOk()->assertJsonPath('replayed',true);
        $this->imported('positions',[['external_id'=>'CV-01','name'=>'Chức vụ đổi tên']]);$this->assertSame('Chức vụ đổi tên',$item->fresh()->name);
        $this->preview('positions',[['name'=>'Chức vụ đổi tên']])->assertCreated()->assertJsonPath('data.status','invalid');
        $id=$this->preview('positions',[['external_id'=>'CV-01','name'=>'Không ghi']])->assertCreated()->json('data.id');$item->update(['name'=>'Sửa tay']);$this->commit($id)->assertUnprocessable();
    }
    public function test_department_reference_update_blanks_and_cycle_planned_across_rows():void{
        $this->imported('departments',[['external_id'=>'PB-A','name'=>'Phòng A','manager_ref'=>$this->actor->email],['external_id'=>'PB-B','name'=>'Phòng B']]);
        $a=Department::where('name','Phòng A')->firstOrFail();$b=Department::where('name','Phòng B')->firstOrFail();$this->assertStringStartsWith('DEP-',$a->code);
        $response=$this->preview('departments',[['external_id'=>'PB-A','name'=>'Phòng A','parent_ref'=>'ext:PB-B'],['external_id'=>'PB-B','name'=>'Phòng B','parent_ref'=>'ext:PB-A']])->assertCreated()->assertJsonPath('data.status','invalid');
        $this->assertStringContainsString('vòng lặp',$response->json('data.result.errors.0.message'));$this->commit($response->json('data.id'))->assertUnprocessable();$this->assertNull($a->fresh()->parent_id);$this->assertNull($b->fresh()->parent_id);
        $this->imported('departments',[['external_id'=>'PB-B','name'=>'Phòng B','parent_ref'=>'ext:PB-A']]);$this->assertSame($a->id,$b->fresh()->parent_id);
        $this->imported('departments',[['external_id'=>'PB-A','name'=>'Phòng A đổi']]);$this->assertSame($this->actor->id,$a->fresh()->manager_id);
    }
    public function test_users_created_inactive_roleless_with_exact_references_and_no_password_leak():void{
        $this->imported('positions',[['external_id'=>'CV-01','name'=>'Chức vụ thử']]);$this->imported('departments',[['external_id'=>'PB-01','name'=>'Phòng thử']]);
        $row=['external_id'=>'NV-01','name'=>'Nhân viên thử','email'=>'nv-import@example.test','department_ref'=>'ext:PB-01','position_ref'=>'ext:CV-01','manager_ref'=>$this->actor->email];
        $before=User::count();$r=$this->preview('users',[$row])->assertCreated()->assertJsonPath('data.status','ready');$this->assertSame($before,User::count());$this->assertStringNotContainsString('password',$r->getContent());$this->commit($r->json('data.id'))->assertOk();
        $user=User::where('email',$row['email'])->firstOrFail();$this->assertFalse($user->is_active);$this->assertSame(0,$user->roles()->count());$this->assertSame($this->actor->id,$user->manager_id);$this->assertSame(Department::where('name','Phòng thử')->value('id'),$user->department_id);$this->assertSame(Position::where('name','Chức vụ thử')->value('id'),$user->position_id);$this->assertNotEmpty($user->password);
        $this->preview('users',[$row])->assertOk()->assertJsonPath('replayed',true);
        $row['name']='Không cập nhật';$this->preview('users',[$row])->assertCreated()->assertJsonPath('data.status','invalid');$this->assertSame('Nhân viên thử',$user->fresh()->name);
    }
    public function test_duplicate_email_invalid_row_or_foreign_reference_blocks_every_row():void{
        $before=User::count();$r=$this->preview('users',[['name'=>'Hợp lệ','email'=>'ok@example.test'],['name'=>'Trùng','email'=>$this->actor->email]])->assertCreated()->assertJsonPath('data.status','invalid');$this->commit($r->json('data.id'))->assertUnprocessable();$this->assertSame($before,User::count());
        $tenant=Tenant::create(['code'=>'ORG-OTHER','name'=>'Khác','status'=>'active']);$foreign=Department::create(['tenant_id'=>$tenant->id,'code'=>'DEP-FOREIGN','name'=>'Riêng']);
        $this->preview('users',[['name'=>'Không nhập','email'=>'foreign@example.test','department_ref'=>'crm:'.$foreign->code]])->assertCreated()->assertJsonPath('data.status','invalid');
        $r=$this->preview('users',[['name'=>'Sai email','email'=>'abc']])->assertCreated()->assertJsonPath('data.status','invalid');$this->assertStringContainsString('Email không đúng định dạng',$r->json('data.result.errors.0.message'));
    }
    public function test_original_technical_template_and_permission_revocation():void{
        $r=$this->preview('positions',[['schema_version'=>'1','source_revision'=>'1','external_id'=>'CV-OLD','name'=>'Mẫu gốc']],true)->assertCreated()->assertJsonPath('data.status','ready');
        $permission=\App\Models\Permission::where('code','user.manage')->firstOrFail();foreach($this->actor->roles as $role)$role->permissions()->detach($permission->id);
        $this->commit($r->json('data.id'))->assertForbidden();$this->withToken($this->token)->get('/api/v1/import-center/organization/users/template')->assertForbidden();$this->assertDatabaseMissing('positions',['name'=>'Mẫu gốc']);
    }
    public function test_template_dropdowns_scoped_and_security_columns_rejected():void{
        $this->imported('positions',[['name'=>'Chức vụ mẫu']]);$this->imported('departments',[['name'=>'Phòng ban mẫu']]);
        foreach(['positions','departments','users'] as $kind){$r=$this->withToken($this->token)->get('/api/v1/import-center/organization/'.$kind.'/template')->assertOk()->assertDownload();if($kind==='positions')continue;
            $zip=new \ZipArchive;$zip->open($r->baseResponse->getFile()->getPathname());$xml=simplexml_load_string($zip->getFromName('xl/worksheets/sheet1.xml'));$ranges=$kind==='users'?['D2:D301','E2:E301','F2:F301']:['C2:C301','D2:D301'];foreach($ranges as $range)$this->assertCount(1,$xml->xpath('//*[local-name()="dataValidation" and @sqref="'.$range.'"]'));$zip->close();
        }
        $this->expectException(\Illuminate\Validation\ValidationException::class);\App\Support\CustomerImportWorkbook::header(['Mã ngoài','Họ tên','Email','password'],OrganizationSourceImport::labels('users'));
    }
    public function test_imported_account_requires_role_and_manual_password_activation():void{
        $this->imported('users',[['name'=>'Chờ kích hoạt','email'=>'pending@example.test']]);$user=User::where('email','pending@example.test')->firstOrFail();
        $rows=$this->withToken($this->token)->getJson('/api/v1/users')->assertOk()->json('data');$found=collect($rows)->firstWhere('id',$user->id);$this->assertTrue($found['import_pending_activation']);
        $this->withToken($this->token)->postJson('/api/v1/users/'.$user->id.'/status',['is_active'=>true])->assertUnprocessable();
        $body=['password'=>'Trial1234','password_confirmation'=>'Trial1234'];
        $this->withToken($this->token)->postJson('/api/v1/users/'.$user->id.'/activate-imported',$body)->assertUnprocessable();
        $role=\App\Models\Role::where('tenant_id',$this->actor->tenant_id)->where('code','!=',\App\Support\SystemAccount::ROLE)->firstOrFail();
        $this->withToken($this->token)->postJson('/api/v1/users/'.$user->id.'/roles',['role_ids'=>[$role->id]])->assertOk();
        $this->withToken($this->token)->postJson('/api/v1/users/'.$user->id.'/activate-imported',['password'=>'short','password_confirmation'=>'short'])->assertUnprocessable();
        $r=$this->withToken($this->token)->postJson('/api/v1/users/'.$user->id.'/activate-imported',$body)->assertOk();$this->assertStringNotContainsString('Trial1234',$r->getContent());$this->assertTrue($user->fresh()->is_active);$this->assertTrue(\Illuminate\Support\Facades\Hash::check('Trial1234',$user->fresh()->password));
        $this->withToken($this->token)->postJson('/api/v1/users/'.$user->id.'/activate-imported',$body)->assertUnprocessable();
        $this->assertStringNotContainsString('Trial1234',\Illuminate\Support\Facades\DB::table('audit_logs')->where('entity_type','user')->where('entity_id',$user->id)->get()->toJson());
    }
    public function test_invalid_existing_ancestry_inactive_manager_and_failed_write_do_not_partially_import():void{
        $a=Department::create(['tenant_id'=>$this->actor->tenant_id,'code'=>'DEP-CYCLE-A','name'=>'Vòng A']);$b=Department::create(['tenant_id'=>$this->actor->tenant_id,'code'=>'DEP-CYCLE-B','name'=>'Vòng B','parent_id'=>$a->id]);$a->update(['parent_id'=>$b->id]);
        $this->preview('departments',[['name'=>'Không thêm','parent_ref'=>'crm:'.$a->code]])->assertCreated()->assertJsonPath('data.status','invalid');
        $manager=User::where('tenant_id',$this->actor->tenant_id)->where('is_active',true)->whereKeyNot($this->actor->id)->firstOrFail();
        $id=$this->preview('users',[['name'=>'Nhân viên chưa lưu','email'=>'manager-test@example.test','manager_ref'=>$manager->email]])->assertCreated()->assertJsonPath('data.status','ready')->json('data.id');$manager->update(['is_active'=>false]);$this->commit($id)->assertUnprocessable();$this->assertDatabaseMissing('users',['email'=>'manager-test@example.test']);
        $id=$this->preview('positions',[['name'=>'Giao dịch một'],['name'=>'Giao dịch hai']])->assertCreated()->json('data.id');$count=Position::count();$original=app(\App\Http\Controllers\Api\V1\PositionController::class);$fault=\Mockery::mock($original)->makePartial();$calls=0;
        $fault->shouldReceive('store')->twice()->andReturnUsing(function($request)use($original,&$calls){$response=$original->store($request);if(++$calls===2)throw new \RuntimeException('SYNTHETIC_ORG_FAILURE');return $response;});$this->app->instance(\App\Http\Controllers\Api\V1\PositionController::class,$fault);
        $this->commit($id)->assertStatus(500);$this->assertSame($count,Position::count());$this->assertDatabaseHas('import_batches',['id'=>$id,'status'=>'ready']);
    }
}
