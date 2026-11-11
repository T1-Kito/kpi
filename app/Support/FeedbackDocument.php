<?php
namespace App\Support;
use App\Models\{Customer,Quotation,SalesOrder,Delivery,Contract,User};

final class FeedbackDocument
{
    public static function query(User $actor, ?int $customer, string $type)
    {
        if ($customer) abort_unless(DataScope::owned(Customer::where('tenant_id',$actor->tenant_id)->whereKey($customer),$actor,'sales_owner_id','salesOwner')->exists(),404);
        $permission = match($type) { 'quotation'=>'sales.quotation.view','order'=>'sales.order.view','delivery'=>'sales.delivery.view','contract'=>'sales.contract.manage',default=>null };
        abort_unless($permission && $actor->hasPermission($permission),403);
        return match($type) {
            'contract'=>DataScope::owned(Contract::where('tenant_id',$actor->tenant_id)->when($customer,fn($q)=>$q->where('customer_id',$customer)),$actor,'owner_id','owner'),
            'quotation'=>DataScope::owned(Quotation::where('tenant_id',$actor->tenant_id)->when($customer,fn($q)=>$q->where('customer_id',$customer)),$actor,'sales_owner_id','salesOwner'),
            'order'=>DataScope::owned(SalesOrder::where('tenant_id',$actor->tenant_id)->when($customer,fn($q)=>$q->where('customer_id',$customer)),$actor,'sales_owner_id','salesOwner'),
            'delivery'=>Delivery::where('tenant_id',$actor->tenant_id)->whereHas('salesOrder',fn($q)=>DataScope::owned($q->where('tenant_id',$actor->tenant_id)->when($customer,fn($q)=>$q->where('customer_id',$customer)),$actor,'sales_owner_id','salesOwner')),
        };
    }
}
