<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CustomerGroup;
use App\Support\CatalogAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CustomerGroupController extends Controller
{
    public function template(Request $request)
    {
        abort_unless(CatalogAccess::full($request->user()), 403);
        $rows=[array_values(\App\Support\CustomerGroupImport::LABELS)];
        for ($i=0;$i<30;$i++) $rows[]=['','',''];
        $guide=[['HƯỚNG DẪN NHẬP NHÓM KHÁCH HÀNG'],['Mỗi dòng là một đường dẫn từ nhóm cấp 1 đến nhóm chi tiết.'],['Không bắt buộc đủ 3 cấp; không bỏ trống cấp ở giữa.'],['Nhóm cha chưa có sẽ được tạo tự động; nhóm đã có được giữ nguyên.'],['Không dùng file để đổi tên, chuyển nhánh hoặc ngừng sử dụng nhóm.'],['Tối đa 300 dòng, 1 MB. Tên nhóm tối đa 100 ký tự.'],[],['Nhóm cấp 1','Nhóm cấp 2','Nhóm cấp 3'],['Khách đoàn','Trường học','Tiểu học'],['Khách đoàn','Trường học','Trung học'],['Khách lẻ','Gia đình','']];
        $path=app(\App\Services\Kpi\KpiReportWorkbook::class)->writeSheets([['Danh sách',$rows,1],['Hướng dẫn',$guide,8]]);
        return response()->download($path,'Mẫu nhóm khách hàng.xlsx',['Cache-Control'=>'private, no-store'])->deleteFileAfterSend(true);
    }

    public function preview(Request $request, \App\Support\CustomerGroupImport $import)
    {
        abort_unless(CatalogAccess::full($request->user()), 403);
        $request->validate(['file'=>['required','file','max:1024']],['file.required'=>'Vui lòng chọn file Excel.','file.max'=>'File Excel tối đa 1 MB.']);
        abort_unless(strtolower($request->file('file')->getClientOriginalExtension())==='xlsx',422,'Vui lòng chọn file Excel .xlsx theo mẫu.');
        try {
            $rows=app(\App\Support\CustomerGroupWorkbook::class)->read($request->file('file')->getRealPath());
        } catch (ValidationException $e) {
            $messages=[];
            foreach ($e->errors() as $field=>$errors) $messages[$field]=array_map(fn($message)=>str_replace(['khách hàng','khách'],['nhóm khách hàng','nhóm'],$message),$errors);
            throw ValidationException::withMessages($messages);
        }
        $groups=CustomerGroup::where('tenant_id',$request->user()->tenant_id)->get();
        $plan=$import->plan($rows,$groups);
        $token=\Illuminate\Support\Facades\Crypt::encryptString(json_encode(['actor'=>$request->user()->id,'tenant'=>$request->user()->tenant_id,'expires'=>time()+900,'snapshot'=>$import->snapshot($groups),'rows'=>$rows]));
        return response()->json(['data'=>['rows'=>$plan['rows'],'create_count'=>$plan['create_count'],'token'=>$token]]);
    }

    public function commit(Request $request, \App\Support\CustomerGroupImport $import)
    {
        abort_unless(CatalogAccess::full($request->user()),403);
        $request->validate(['token'=>['required','string','max:500000']]);
        try { $payload=json_decode(\Illuminate\Support\Facades\Crypt::decryptString($request->input('token')),true,512,JSON_THROW_ON_ERROR); }
        catch (\Throwable $e) { abort(422,'Bản xem trước không hợp lệ. Vui lòng tải file để kiểm tra lại.'); }
        abort_unless((int)$payload['actor']===(int)$request->user()->id && (int)$payload['tenant']===(int)$request->user()->tenant_id,403);
        abort_if($payload['expires']<time(),422,'Bản xem trước đã hết hạn. Vui lòng kiểm tra file lại.');
        return DB::transaction(function () use ($request,$payload,$import) {
            \App\Models\Tenant::whereKey($request->user()->tenant_id)->lockForUpdate()->firstOrFail();
            $groups=CustomerGroup::where('tenant_id',$request->user()->tenant_id)->lockForUpdate()->get();
            abort_unless(hash_equals($payload['snapshot'],$import->snapshot($groups)),409,'Danh mục đã thay đổi. Vui lòng xem trước file lại trước khi nhập.');
            $plan=$import->plan($payload['rows'],$groups); $ids=[];
            foreach ($plan['new'] as $node) {
                $parent=str_starts_with($node['parent'],'new:') ? $ids[$node['parent']] : ($node['parent'] ?: null);
                $ids[$node['ref']]=CustomerGroup::create(['tenant_id'=>$request->user()->tenant_id,'parent_id'=>$parent,'name'=>$node['name'],'is_active'=>true])->id;
            }
            app(\App\Support\AuditLogger::class)->record('customer_group',0,'import_customer_groups',$request->user(),[],['created'=>count($ids)],$request);
            return response()->json(['data'=>['created'=>count($ids)]]);
        });
    }

    public function index(Request $request)
    {
        return response()->json([
            'data' => CustomerGroup::where('tenant_id', $request->user()->tenant_id)->orderBy('name')->get(),
            'can_manage' => CatalogAccess::full($request->user()),
        ]);
    }

    public function save(Request $request, ?int $id = null)
    {
        abort_unless(CatalogAccess::full($request->user()), 403, 'Chỉ Admin hoặc Chủ tịch được quản lý phân nhóm.');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'parent_id' => ['nullable', 'integer'],
            'is_active' => ['required', 'boolean'],
        ], ['required'=>':attribute không được để trống.','string'=>':attribute phải là văn bản.','max'=>':attribute tối đa :max ký tự.','integer'=>':attribute không hợp lệ.','boolean'=>':attribute không hợp lệ.'], ['name'=>'Tên nhóm','parent_id'=>'Nhóm cha','is_active'=>'Trạng thái']);
        $data['name'] = trim($data['name']);
        if ($data['name'] === '') throw ValidationException::withMessages(['name' => 'Vui lòng nhập tên nhóm.']);
        $tenant = $request->user()->tenant_id;
        return DB::transaction(function () use ($id, $data, $tenant) {
            \App\Models\Tenant::whereKey($tenant)->lockForUpdate()->firstOrFail();
            $all = CustomerGroup::where('tenant_id', $tenant)->lockForUpdate()->get()->keyBy('id');
            $group = $id ? ($all[$id] ?? abort(404)) : new CustomerGroup(['tenant_id' => $tenant]);
            // Parent is immutable after creation, keeping all customer paths stable.
            $parent = $data['parent_id'] ?? null;
            if ($id && (int) $parent !== (int) $group->parent_id) {
                throw ValidationException::withMessages(['parent_id' => 'Không thể chuyển nhóm sang nhánh khác. Hãy tạo nhóm mới.']);
            }
            $seen = []; $depth = 1; $cursor = $parent;
            while ($cursor) {
                if (isset($seen[$cursor]) || !isset($all[$cursor]) || $cursor == $id) {
                    throw ValidationException::withMessages(['parent_id' => 'Nhóm cha không hợp lệ.']);
                }
                $seen[$cursor] = true;
                if (++$depth > 3) throw ValidationException::withMessages(['parent_id' => 'Chỉ hỗ trợ tối đa 3 cấp nhóm.']);
                if ($data['is_active'] && !$all[$cursor]->is_active) throw ValidationException::withMessages(['parent_id' => 'Nhóm cha đã ngừng sử dụng.']);
                $cursor = $all[$cursor]->parent_id;
            }
            foreach ($all as $other) {
                if ($other->id != $id && (int)$other->parent_id === (int)$parent && mb_strtolower($other->name) === mb_strtolower($data['name'])) {
                    throw ValidationException::withMessages(['name' => 'Tên nhóm đã tồn tại trong cấp này.']);
                }
            }
            $group->fill($data + ['parent_id' => null])->save();
            return response()->json(['data' => $group], $id ? 200 : 201);
        });
    }
}
