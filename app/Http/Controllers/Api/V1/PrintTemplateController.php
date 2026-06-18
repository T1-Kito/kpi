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
                'fields' => self::fields(),
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

        return response()->json([
            'data' => $template ?: [
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
            ],
        ]);
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
                'content_html' => $data['content_html'] ?: self::defaultQuotationTemplate(),
                'merge_fields' => self::fields(),
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
                'content_html' => $data['content_html'] ?: self::defaultQuotationTemplate(),
                'merge_fields' => self::fields(),
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
        ];
    }

    public static function fields(): array
    {
        return [
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
