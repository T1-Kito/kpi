<?php

namespace Tests\Unit;

use App\Models\{User,Quotation,ContractMilestone,Customer,PurchaseOrder,KpiBundle,KpiBundleItem};
use PHPUnit\Framework\TestCase;

class DatabaseIdentifierCastsTest extends TestCase
{
    public function test_numeric_foreign_keys_are_typed_without_changing_business_identifiers(): void
    {
        foreach([new User,new Quotation,new Customer,new PurchaseOrder,new ContractMilestone,new KpiBundle,new KpiBundleItem] as $model) {
            foreach(['tenant_id','customer_id','sales_owner_id','created_by','contract_id','user_id'] as $key) {
                $model->setRawAttributes([$key=>'123']);
                $this->assertSame(123,$model->getAttribute($key),get_class($model).' '.$key);
                $model->setRawAttributes([$key=>null]);
                $this->assertNull($model->getAttribute($key));
            }
        }
        $customer=new Customer;
        $customer->setRawAttributes(['tax_code'=>'0012345678','phone'=>'0900123456']);
        $this->assertSame('0012345678',$customer->tax_code);
        $this->assertSame('0900123456',$customer->phone);
    }
}
