<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\{Customer,Tenant};
use App\Support\BusinessIdentity;
use App\Support\CustomerImportWorkbook;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB,Validator};
use Illuminate\Validation\{Rule,ValidationException};

class CustomerImportController extends Controller
{
    public function template()
    {
        return response()->download(resource_path('templates/import/mau-khach-hang.xlsx'),'mau-khach-hang.xlsx');
    }

    public function store(Request $request, CustomerController $customers)
    {
        $request->validate(['file'=>['required','file','max:1024'],'confirm'=>['sometimes','boolean']]);
        $file = $request->file('file');
        $extension=strtolower($file->getClientOriginalExtension());
        if (!in_array($extension,['csv','xlsx'],true)) {
            throw ValidationException::withMessages(['file'=>'Chọn file Excel .xlsx theo mẫu hoặc CSV UTF-8.']);
        }
        $rows = $extension==='xlsx' ? app(CustomerImportWorkbook::class)->read($file->getRealPath()) : $this->read($file->getRealPath());
        return DB::transaction(function () use ($request,$customers,$rows) {
            // Serialize confirmations within a tenant, including duplicate detection.
            if ($request->boolean('confirm')) Tenant::whereKey($request->user()->tenant_id)->lockForUpdate()->firstOrFail();
            $errors = []; $seen = []; $wanted = [];
            foreach ($rows as $row) {
                $validator = Validator::make($row['data'],[
                    'name'=>['required','string','max:255'],
                    'customer_type'=>['required',Rule::in(['person','organization'])],
                    'phone'=>['nullable','string','max:50'], 'email'=>['nullable','email','max:255'],
                    'tax_code'=>['nullable','regex:/^[0-9.\s-]+$/','max:30'],
                    'address'=>['nullable','string','max:255'], 'contact_name'=>['nullable','string','max:255'],
                    'identity_number'=>['nullable','regex:/^[0-9]{12}$/'],
                ],[
                    'required'=>':attribute không được để trống.', 'email'=>':attribute không hợp lệ.',
                    'max'=>':attribute vượt quá độ dài cho phép.', 'in'=>':attribute không đúng giá trị trong hướng dẫn.',
                    'regex'=>':attribute không đúng định dạng.', 'string'=>':attribute phải là văn bản.',
                ],['name'=>'Tên khách hàng','customer_type'=>'Loại khách hàng','phone'=>'Điện thoại','email'=>'Email',
                    'tax_code'=>'Mã số thuế','address'=>'Địa chỉ','contact_name'=>'Người liên hệ','identity_number'=>'CCCD']);
                if ($validator->fails()) { $errors[]=['row'=>$row['row'],'message'=>implode(' ', $validator->errors()->all())]; continue; }
                $keys = $this->keys($row['data']);
                if (!$keys) { $errors[]=['row'=>$row['row'],'message'=>'Cần ít nhất số điện thoại, email, mã số thuế hoặc CCCD để kiểm tra trùng.']; continue; }
                foreach ($keys as $key) {
                    if (isset($seen[$key])) $errors[]=['row'=>$row['row'],'message'=>'Thông tin nhận diện trùng dòng '.$seen[$key].' trong file.'];
                    $seen[$key] ??= $row['row'];
                    $wanted[$key][] = $row['row'];
                }
            }
            Customer::where('tenant_id',$request->user()->tenant_id)
                ->select(['id','customer_type','phone','email','tax_code','identity_number'])
                ->chunkById(500,function ($existing) use (&$errors,&$wanted) {
                    foreach ($existing as $customer) foreach ($this->keys($customer->toArray()) as $key) {
                        foreach ($wanted[$key] ?? [] as $line) $errors[]=['row'=>$line,'message'=>'Thông tin nhận diện đã tồn tại trong hệ thống. Không tạo mới hoặc ghi đè.'];
                        unset($wanted[$key]);
                    }
                });
            $errors = array_values(array_unique($errors,SORT_REGULAR));
            $imported = 0;
            if (!$errors && $request->boolean('confirm')) {
                foreach ($rows as $row) {
                    $child = Request::create('/api/v1/customers','POST',$row['data'] + ['sales_owner_id'=>$request->user()->id],[],[],$request->server->all());
                    $child->setUserResolver(fn ()=>$request->user());
                    $response = $customers->store($child);
                    if ($response->getStatusCode() !== 201) throw ValidationException::withMessages(['file'=>'Dòng '.$row['row'].' không thể nhập. Chưa lưu bất kỳ khách hàng nào.']);
                    $imported++;
                }
            }
            return response()->json(['data'=>['total'=>count($rows),'imported'=>$imported,'can_import'=>!$errors,'errors'=>$errors,
                'preview'=>array_map(fn ($row)=>['row'=>$row['row'],'name'=>$row['data']['name'],'customer_type'=>$row['data']['customer_type']],array_slice($rows,0,20))]]);
        });
    }

    private function read(string $path): array
    {
        $raw = file_get_contents($path);
        if (!mb_check_encoding($raw,'UTF-8') || str_contains($raw,"\0")) throw ValidationException::withMessages(['file'=>'File phải ở dạng CSV UTF-8.']);
        $stream = fopen($path,'r');
        try {
            $first = fgets($stream);
            $delimiter = count(str_getcsv($first ?: '', ';','"','')) > count(str_getcsv($first ?: '', ',','"','')) ? ';' : ',';
            rewind($stream);
            $header = fgetcsv($stream,0,$delimiter,'"','');
            if (!$header) throw ValidationException::withMessages(['file'=>'File không có tiêu đề.']);
            $header = CustomerImportWorkbook::header($header);
            $rows = []; $line = 1;
            while (($values=fgetcsv($stream,0,$delimiter,'"','')) !== false) {
                $line++;
                if (count(array_filter($values,fn($value)=>trim((string)$value)!=='')) === 0) continue;
                if (count($values) !== count($header)) throw ValidationException::withMessages(['file'=>'Dòng '.$line.' không đúng số cột.']);
                $data = array_combine($header,array_map(fn($value)=>trim((string)$value)==='' ? null : trim((string)$value),$values));
                $data['customer_type'] = CustomerImportWorkbook::type($data['customer_type'] ?? null);
                $rows[] = ['row'=>$line,'data'=>$data];
                if (count($rows)>300) throw ValidationException::withMessages(['file'=>'Mỗi lần nhập tối đa 300 khách hàng.']);
            }
            if (!$rows) throw ValidationException::withMessages(['file'=>'File chưa có khách hàng.']);
            return $rows;
        } finally { fclose($stream); }
    }

    private function keys(array $data): array
    {
        $phone = preg_replace('/\D/','',$data['phone'] ?? '');
        if (str_starts_with($phone,'84') && strlen($phone)>=11) $phone='0'.substr($phone,2);
        $organization = ($data['customer_type'] ?? 'organization') !== 'person';
        $values=['phone'=>$phone,'email'=>mb_strtolower(trim($data['email'] ?? '')),
            'tax'=>$organization ? BusinessIdentity::tax($data['tax_code'] ?? null) : null,
            'identity'=>$organization ? null : ($data['identity_number'] ?? null)];
        $keys=[];
        foreach ($values as $kind=>$value) if ($value !== null && $value !== '') $keys[]=$kind.':'.$value;
        return $keys;
    }
}
