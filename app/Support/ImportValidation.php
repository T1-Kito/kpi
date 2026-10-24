<?php
namespace App\Support;

final class ImportValidation
{
    public const MESSAGES=[
        'required'=>'Vui lòng nhập :attribute.',
        'string'=>':attribute phải là văn bản.',
        'integer'=>':attribute phải là số nguyên.',
        'numeric'=>':attribute phải là số.',
        'email'=>':attribute không đúng định dạng, ví dụ ten@congty.vn.',
        'in'=>':attribute không nằm trong danh sách được phép. Vui lòng chọn theo mẫu.',
        'regex'=>':attribute không đúng định dạng trong hướng dẫn.',
        'min'=>':attribute nhỏ hơn giới hạn cho phép (:min).',
        'max'=>':attribute vượt giới hạn cho phép (:max).',
        'boolean'=>':attribute chỉ nhận Có/Không hoặc 1/0.',
        'exists'=>':attribute không tồn tại hoặc không thuộc doanh nghiệp của bạn.',
        'unique'=>':attribute đã tồn tại. Hãy kiểm tra hồ sơ có sẵn.',
        'file'=>'Vui lòng chọn file Excel hợp lệ.',
    ];
    public const TECHNICAL_LABELS=['schema_version'=>'Phiên bản mẫu','external_id'=>'Mã ngoài','source_revision'=>'Lần cập nhật','customer_ref'=>'Mã khách hàng'];
}
