<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PrintTemplate;
use App\Support\CodeGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class PrintTemplateController extends Controller
{
    public function __construct(private readonly CodeGenerator $codes)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $pageSize = min((int) $request->query('page_size', 100), 100);
        $query = PrintTemplate::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->latest('is_default')
            ->latest('id');

        if ($module = $request->query('module')) {
            $query->where('module', $module);
        }

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        if ($search = $request->query('q')) {
            $query->where(fn ($q) => $q
                ->where('code', 'like', "%{$search}%")
                ->orWhere('name', 'like', "%{$search}%"));
        }

        $templates = $query->paginate($pageSize);

        return response()->json([
            'data' => $templates->items(),
            'meta' => [
                'page' => $templates->currentPage(),
                'page_size' => $templates->perPage(),
                'total' => $templates->total(),
                'fields' => self::fields($request->query('module', 'quotation')),
                'field_sets' => collect(array_keys(self::modules()))->mapWithKeys(fn ($module) => [$module => self::fields($module)]),
                'modules' => self::modules(),
            ],
        ]);
    }

    public function active(Request $request): JsonResponse
    {
        $module = $request->validate([
            'module' => ['required', Rule::in(array_keys(self::modules()))],
        ])['module'];

        $template = PrintTemplate::where('tenant_id', $request->user()->tenant_id)
            ->where('module', $module)
            ->where('status', 'active')
            ->orderByDesc('is_default')
            ->latest('id')
            ->first();

        return response()->json(['data' => $template ?: [
            'id' => null,
            'code' => 'TPL-'.strtoupper($module).'-DEFAULT',
            'name' => (self::modules()[$module] ?? 'Chứng từ').' mặc định',
            'module' => $module,
            'content_html' => self::defaultTemplate($module),
            'merge_fields' => self::fields($module),
            'file_path' => null,
            'file_name' => null,
            'is_default' => true,
            'is_system' => true,
            'status' => 'active',
        ]]);
    }

    public function choices(Request $request): JsonResponse
    {
        $module = $request->validate([
            'module' => ['required', Rule::in(array_keys(self::modules()))],
        ])['module'];

        $templates = PrintTemplate::where('tenant_id', $request->user()->tenant_id)
            ->where('module', $module)
            ->where('status', 'active')
            ->orderByDesc('is_default')
            ->latest('id')
            ->get();

        if ($templates->isEmpty() && $module === 'quotation') {
            $templates = collect([self::defaultTemplateRecord()]);
        }

        return response()->json(['data' => $templates->values()]);
    }

    public function store(Request $request): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;
        $data = $this->validated($request);
        $file = $this->storeTemplateFile($request, $tenantId);

        $template = DB::transaction(function () use ($data, $file, $tenantId) {
            if (! empty($data['is_default'])) {
                PrintTemplate::where('tenant_id', $tenantId)
                    ->where('module', $data['module'])
                    ->update(['is_default' => false]);
            }

            return PrintTemplate::create([
                ...$data,
                ...$file,
                'tenant_id' => $tenantId,
                'code' => $this->codes->next('print_templates', 'code', 'TPL-', fn ($query) => $query->where('tenant_id', $tenantId)),
                'content_html' => $data['content_html'] ?: self::defaultTemplate($data['module']),
                'merge_fields' => self::fields($data['module']),
                'is_default' => (bool) ($data['is_default'] ?? false),
                'status' => $data['status'] ?? 'active',
            ]);
        });

        return response()->json(['data' => $template], 201);
    }

    public function update(Request $request, PrintTemplate $printTemplate): JsonResponse
    {
        abort_if($printTemplate->tenant_id !== $request->user()->tenant_id, 404);

        $data = $this->validated($request);
        $file = $this->storeTemplateFile($request, $request->user()->tenant_id);

        DB::transaction(function () use ($data, $file, $printTemplate) {
            if (! empty($data['is_default'])) {
                PrintTemplate::where('tenant_id', $printTemplate->tenant_id)
                    ->where('module', $data['module'])
                    ->where('id', '!=', $printTemplate->id)
                    ->update(['is_default' => false]);
            }

            $printTemplate->update([
                ...$data,
                ...$file,
                'content_html' => $data['content_html'] ?: self::defaultTemplate($data['module']),
                'merge_fields' => self::fields($data['module']),
                'is_default' => (bool) ($data['is_default'] ?? false),
            ]);
        });

        return response()->json(['data' => $printTemplate->refresh()]);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'module' => ['required', Rule::in(array_keys(self::modules()))],
            'content_html' => ['nullable', 'string'],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
            'is_default' => ['nullable', 'boolean'],
            'template_file' => ['nullable', 'file', 'mimes:doc,docx,html,txt', 'max:5120'],
        ]);
    }

    private function storeTemplateFile(Request $request, int $tenantId): array
    {
        if (! $request->hasFile('template_file')) {
            return [];
        }

        $file = $request->file('template_file');

        return [
            'file_path' => $file->store("print-templates/{$tenantId}", 'public'),
            'file_name' => $file->getClientOriginalName(),
        ];
    }

    public static function modules(): array
    {
        return [
            'quotation' => 'Báo giá',
            'sales_order' => 'Đơn bán hàng',
            'goods_issue' => 'Phiếu xuất kho',
            'contract' => 'Hợp đồng',
        ];
    }

    public static function fields(string $module = 'quotation'): array
    {
        $common = [
            ['key' => 'ma_chung_tu', 'label' => 'Mã chứng từ'],
            ['key' => 'ngay_chung_tu', 'label' => 'Ngày chứng từ'],
            ['key' => 'ten_khach_hang', 'label' => 'Tên khách hàng'],
            ['key' => 'ma_khach_hang', 'label' => 'Mã khách hàng'],
            ['key' => 'nguoi_lien_he', 'label' => 'Người liên hệ / nhận hàng'],
            ['key' => 'so_dien_thoai', 'label' => 'Số điện thoại'],
            ['key' => 'dia_chi_giao_hang', 'label' => 'Địa chỉ giao hàng'],
            ['key' => 'stt', 'label' => 'STT dòng hàng'],
            ['key' => 'ma_hang', 'label' => 'Mã hàng'],
            ['key' => 'ten_hang', 'label' => 'Tên hàng'],
            ['key' => 'dvt', 'label' => 'Đơn vị tính'],
            ['key' => 'so_luong', 'label' => 'Số lượng'],
            ['key' => 'bang_dong_hang', 'label' => 'Bảng dòng hàng dạng text'],
            ['key' => 'nguoi_lap', 'label' => 'Người lập'],
        ];
        if ($module === 'goods_issue') {
            return [...$common, ['key' => 'ma_don_ban', 'label' => 'Mã đơn bán'], ['key' => 'kho_xuat', 'label' => 'Kho xuất'], ['key' => 'ly_do_xuat', 'label' => 'Lý do xuất'], ['key' => 'dia_diem_giao_hang', 'label' => 'Địa điểm giao'], ['key' => 'chung_tu_goc', 'label' => 'Chứng từ gốc'], ['key' => 'co_tru_ton', 'label' => 'Có trừ tồn kho']];
        }
        if ($module === 'sales_order') {
            return [...$common, ['key' => 'ma_don_ban', 'label' => 'Mã đơn bán'], ['key' => 'ma_bao_gia', 'label' => 'Mã báo giá nguồn'], ['key' => 'hinh_thuc_giao_hang', 'label' => 'Hình thức giao hàng'], ['key' => 'ghi_chu_giao_hang', 'label' => 'Ghi chú giao hàng'], ['key' => 'tong_cong', 'label' => 'Tổng cộng'], ['key' => 'tong_tien_bang_chu', 'label' => 'Tổng tiền bằng chữ']];
        }
        if ($module === 'contract') {
            return [...$common,
                ['key' => 'ma_hop_dong', 'label' => 'Số hợp đồng'],
                ['key' => 'ten_hop_dong', 'label' => 'Tên hợp đồng'],
                ['key' => 'ngay_hop_dong', 'label' => 'Ngày lập hợp đồng'],
                ['key' => 'ngay_hieu_luc', 'label' => 'Ngày hiệu lực'],
                ['key' => 'ngay_het_han', 'label' => 'Ngày hết hạn'],
                ['key' => 'gia_tri_hop_dong', 'label' => 'Giá trị hợp đồng'],
                ['key' => 'gia_tri_bang_chu', 'label' => 'Giá trị bằng chữ'],
                ['key' => 'ma_bao_gia', 'label' => 'Báo giá tham chiếu'],
                ['key' => 'ma_don_hang', 'label' => 'Đơn hàng tham chiếu'],
                ['key' => 'dieu_khoan', 'label' => 'Ghi chú / điều khoản'],
                ['key' => 'moc_thanh_toan', 'label' => 'Các mốc thanh toán'],
            ];
        }
        return [
            ...$common,
            ['key' => 'ma_bao_gia', 'label' => 'Mã báo giá'],
            ['key' => 'ngay_bao_gia', 'label' => 'Ngày báo giá'],
            ['key' => 'ten_khach_hang', 'label' => 'Tên khách hàng'],
            ['key' => 'ma_khach_hang', 'label' => 'Mã khách hàng'],
            ['key' => 'nguoi_lien_he', 'label' => 'Người liên hệ'],
            ['key' => 'so_dien_thoai', 'label' => 'Số điện thoại'],
            ['key' => 'email', 'label' => 'Email'],
            ['key' => 'ten_cong_ty', 'label' => 'Tên công ty'],
            ['key' => 'ma_so_thue', 'label' => 'Mã số thuế'],
            ['key' => 'dia_chi_cong_ty', 'label' => 'Địa chỉ công ty'],
            ['key' => 'stt', 'label' => 'STT dòng hàng'],
            ['key' => 'ma_hang', 'label' => 'Mã hàng'],
            ['key' => 'ten_hang', 'label' => 'Tên hàng'],
            ['key' => 'dvt', 'label' => 'Đơn vị tính'],
            ['key' => 'so_luong', 'label' => 'Số lượng'],
            ['key' => 'don_gia', 'label' => 'Đơn giá'],
            ['key' => 'vat_percent', 'label' => 'VAT (%)'],
            ['key' => 'vat_dong', 'label' => 'Tiền VAT dòng'],
            ['key' => 'thanh_tien', 'label' => 'Thành tiền dòng'],
            ['key' => 'bang_dong_hang', 'label' => 'Bảng dòng hàng dạng text'],
            ['key' => 'tam_tinh', 'label' => 'Tạm tính'],
            ['key' => 'chiet_khau', 'label' => 'Chiết khấu'],
            ['key' => 'vat', 'label' => 'VAT'],
            ['key' => 'tong_cong', 'label' => 'Tổng cộng'],
            ['key' => 'tong_tien_bang_chu', 'label' => 'Tổng tiền bằng chữ'],
        ];
    }

    public static function defaultTemplate(string $module = 'quotation'): string
    {
        if ($module === 'contract') {
            return '<section class="doc-header"><div><p class="muted">HỢP ĐỒNG</p><h1>{{ma_hop_dong}}</h1><p><strong>{{ten_hop_dong}}</strong></p></div><div class="doc-meta"><span>Ngày lập</span><strong>{{ngay_hop_dong}}</strong></div></section><section class="doc-grid"><div class="doc-card"><h2>Bên A</h2><p><strong>{{ten_cong_ty}}</strong></p><p>Mã số thuế: {{ma_so_thue}}</p><p>Địa chỉ: {{dia_chi_cong_ty}}</p></div><div class="doc-card"><h2>Bên B</h2><p><strong>{{ten_khach_hang}}</strong></p><p>Người liên hệ: {{nguoi_lien_he}}</p><p>Địa chỉ: {{dia_chi_giao_hang}}</p></div></section><section class="doc-card"><h2>Giá trị và thời hạn</h2><p>Giá trị hợp đồng: <strong>{{gia_tri_hop_dong}}</strong></p><p>Bằng chữ: {{gia_tri_bang_chu}}</p><p>Hiệu lực: {{ngay_hieu_luc}} đến {{ngay_het_han}}</p></section><section class="doc-card"><h2>Mốc thanh toán</h2><p>{{moc_thanh_toan}}</p></section><section class="doc-card"><h2>Điều khoản khác</h2><p>{{dieu_khoan}}</p></section>';
        }
        $title = self::modules()[$module] ?? 'Chứng từ';
        return '<section class="doc-header"><div><p class="muted">'.$title.'</p><h1>{{ma_chung_tu}}</h1><p><strong>{{ten_khach_hang}}</strong> · {{nguoi_lien_he}} · {{so_dien_thoai}}</p></div><div class="doc-meta"><span>Ngày lập</span><strong>{{ngay_chung_tu}}</strong></div></section><section class="doc-card"><h2>Thông tin giao nhận</h2><p>Địa chỉ: <strong>{{dia_chi_giao_hang}}</strong></p></section>{{bang_dong_hang}}';
    }

    private static function defaultTemplateRecord(): array
    {
        return [
            'id' => null,
            'code' => 'TPL-QUOTATION-DEFAULT',
            'name' => 'Mẫu báo giá mặc định',
            'module' => 'quotation',
            'content_html' => self::defaultQuotationTemplate(),
            'merge_fields' => self::fields(),
            'file_path' => null,
            'file_name' => null,
            'is_default' => true,
            'is_system' => true,
            'status' => 'active',
        ];
    }

    public static function defaultQuotationTemplate(): string
    {
        return <<<'HTML'
<section class="doc-header">
    <div>
        <p class="muted">Báo giá</p>
        <h1>{{ma_bao_gia}}</h1>
        <p><strong>{{ten_khach_hang}}</strong> · {{nguoi_lien_he}} · {{so_dien_thoai}}</p>
    </div>
    <div class="doc-meta">
        <span>Ngày báo giá</span>
        <strong>{{ngay_bao_gia}}</strong>
    </div>
</section>

<section class="doc-grid">
    <div class="doc-card">
        <h2>Thông tin khách hàng</h2>
        <p>Tên khách hàng: <strong>{{ten_khach_hang}}</strong></p>
        <p>Mã khách hàng: <strong>{{ma_khach_hang}}</strong></p>
        <p>Người liên hệ: <strong>{{nguoi_lien_he}}</strong></p>
        <p>Email: <strong>{{email}}</strong></p>
    </div>
    <div class="doc-card">
        <h2>Thông tin xuất hóa đơn</h2>
        <p>Tên công ty: <strong>{{ten_cong_ty}}</strong></p>
        <p>Mã số thuế: <strong>{{ma_so_thue}}</strong></p>
        <p>Địa chỉ công ty: <strong>{{dia_chi_cong_ty}}</strong></p>
    </div>
</section>

{{bang_dong_hang}}

<table class="summary-table">
    <tr><td>Tạm tính</td><td>{{tam_tinh}}</td></tr>
    <tr><td>Chiết khấu</td><td>{{chiet_khau}}</td></tr>
    <tr><td>VAT</td><td>{{vat}}</td></tr>
    <tr class="total"><td>Tổng cộng</td><td>{{tong_cong}}</td></tr>
</table>
HTML;
    }
}
