<?php
namespace App\Support;

use App\Models\{Customer,User};
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Explicit customer references only. Never matches by display name or guesses an ID. */
final class ImportCustomerReference
{
    public function resolve(User $actor,int $sourceId,string $reference): Customer
    {
        $source=DB::table('import_sources')->where('id',$sourceId)->where('tenant_id',$actor->tenant_id)->where('is_active',true)->exists();
        $member=DB::table('import_source_users')->where('source_id',$sourceId)->where('user_id',$actor->id)->exists();
        abort_unless($source && $member && $actor->hasPermission('master.view') && $actor->hasPermission('customer.manage') && $actor->dataScope()==='company',403,'Bạn chưa có quyền sử dụng nguồn hoặc danh mục khách hàng.');
        if(!preg_match('/^(ext|crm):([^\s].*)$/D',trim($reference),$match))$this->fail();
        $query=Customer::where('tenant_id',$actor->tenant_id)->where('customer_type','organization')->where('status','active')->whereNull('merged_into_id');
        if($match[1]==='ext'){
            if(!preg_match('/^[A-Za-z0-9_.-]{1,100}$/D',$match[2]))$this->fail();
            $id=DB::table('import_record_links')->where('tenant_id',$actor->tenant_id)->where('source_id',$sourceId)->where('catalog','customers')->where('external_id',strtolower($match[2]))->value('record_id');
            if(!$id)$this->fail();
            $query->whereKey($id);
        }else{
            if(strlen($match[2])>100)$this->fail();
            $query->where('code',$match[2]);
        }
        $customer=DataScope::owned($query,$actor,'sales_owner_id','salesOwner')->first();
        if(!$customer)$this->fail();
        return $customer;
    }
    private function fail(): never {
        throw ValidationException::withMessages(['customer_ref'=>'Không tìm thấy khách doanh nghiệp đang hoạt động trong phạm vi được phép. Dùng ext:mã-ngoài trong cùng nguồn hoặc crm:mã-khách; nhập khách hàng trước người liên hệ.']);
    }
}
