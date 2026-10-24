<?php
namespace Tests\Unit;
use App\Support\ImportRevision;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ImportRevisionTest extends TestCase
{
    private function link(array $values): array {return ['source_revision'=>2,'source_hash'=>ImportRevision::hash($values),'crm_hash'=>ImportRevision::hash($values)];}
    public function test_create_repeat_update_and_blank_preservation(): void {
        $old=['name'=>'Khách cũ','phone'=>'00123','limit'=>100];$link=$this->link($old);
        $this->assertSame('create',ImportRevision::plan(null,1,$old,[],true)['action']);
        $this->assertSame('skip',ImportRevision::plan($link,2,$old,$old,true)['action']);
        $plan=ImportRevision::plan($link,3,['name'=>'Khách mới','phone'=>'','limit'=>0],$old,true);
        $this->assertSame(['name'=>'Khách mới','phone'=>'00123','limit'=>0],$plan['values']);
        $this->assertSame(['before'=>'Khách cũ','after'=>'Khách mới'],$plan['changes']['name']);
        $this->assertArrayNotHasKey('phone',$plan['changes']);
        $this->assertSame(ImportRevision::hash(['a'=>1,'b'=>2]),ImportRevision::hash(['b'=>2,'a'=>1]));
    }
    public function test_conflicts_and_create_only_catalogs_are_blocked(): void {
        $old=['name'=>'Khách cũ'];$link=$this->link($old);
        foreach([[1,$old,$old,true],[2,['name'=>'Khác'],$old,true],[3,$old,['name'=>'Sửa tay'],true],[3,$old,$old,false],[0,$old,$old,true],[3,['role'=>'SPAdmin'],$old,true]] as [$revision,$incoming,$current,$allow]){
            try{ImportRevision::plan($link,$revision,$incoming,$current,$allow);$this->fail('Expected conflict');}
            catch(ValidationException $e){$this->assertNotEmpty($e->errors()['file']);}
        }
    }
}
