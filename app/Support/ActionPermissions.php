<?php

namespace App\Support;

/** Explicit compatibility migration, never an implicit permission fallback at request time. */
class ActionPermissions
{
    public const SPLITS = [
        'master.manage' => [
            'customer.manage'=>'Chỉnh sửa khách hàng và liên hệ',
            'supplier.manage'=>'Chỉnh sửa nhà cung cấp',
            'product.manage'=>'Chỉnh sửa sản phẩm và danh mục',
            'warehouse.manage'=>'Chỉnh sửa danh mục kho',
            'sales.master.manage'=>'Chỉnh sửa thiết lập bán hàng',
        ],
        'task.create' => ['task.update'=>'Cập nhật trạng thái công việc'],
        'alert.view' => ['alert.update'=>'Xử lý cảnh báo'],
        'kpi.lock' => [
            'kpi.configure'=>'Cấu hình và phân giao KPI',
            'kpi.adjustment.create'=>'Lập phiếu điều chỉnh điểm KPI',
            'kpi.adjustment.review'=>'Duyệt phiếu điều chỉnh điểm KPI',
            'kpi.exception.review'=>'Duyệt ngoại lệ KPI',
        ],
    ];

    public static function expand(array $codes): array
    {
        foreach (self::SPLITS as $legacy=>$permissions) {
            if (in_array($legacy,$codes,true)) $codes=array_merge($codes,array_keys($permissions));
        }
        return array_values(array_unique($codes));
    }
}
