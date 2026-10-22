<?php
namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\{Customer,Tenant,WarrantyClaim};
use App\Services\ServiceDesk\ServiceDeskService;
use App\Support\{BusinessIdentity,DataScope};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB,Validator};
use Illuminate\Validation\ValidationException;

class WarrantyImportController extends Controller
{
    private const COLUMNS=['Mã khách hàng','Serial','Sản phẩm','Ngày mua','Ngày bắt đầu','Số tháng'];
    public function template()
    {
        return response()->download(resource_path('templates/import/mau-bao-hanh.xlsx'),'mau-bao-hanh.xlsx');
    }
    public function store(Request $request,ServiceDeskService $service)
    {
        $request->validate(['file'=>['required','file','max:1024'],'confirm'=>['sometimes','boolean']]);
        $file=$request->file('file');
        $extension=strtolower($file->getClientOriginalExtension());
        if(!in_array($extension,['csv','xlsx'],true)) $this->fail('Chọn Excel .xlsx hoặc CSV theo mẫu.');
        if($extension==='xlsx') {
            $labels=array_combine(['customer_code','serial','product','purchase','start','months'],self::COLUMNS);
            $rows=(new \App\Support\CustomerImportWorkbook($labels,['customer_code','serial'],['purchase','start']))->read($file->getRealPath());
            foreach($rows as &$row) $row['data']=array_replace(array_fill_keys(array_keys($labels),''),$row['data']); unset($row);
        } else {
        $raw=file_get_contents($file->getRealPath());
        if(str_starts_with($raw,"\xFF\xFE") || str_starts_with($raw,"\xFE\xFF")) {
            $encoding=str_starts_with($raw,"\xFF\xFE") ? 'UTF-16LE' : 'UTF-16BE';
            $text=substr($raw,2);
            if(!mb_check_encoding($text,$encoding)) $this->fail('Mã hóa Unicode của file không hợp lệ.');
            $raw=mb_convert_encoding($text,'UTF-8',$encoding);
        }
        if(!mb_check_encoding($raw,'UTF-8') || str_contains($raw,"\0")) $this->fail('File phải là CSV UTF-8.');
        $stream=fopen('php://temp','w+'); fwrite($stream,$raw); rewind($stream); $rows=[];
        try {
            $first=ltrim(fgets($stream) ?: '',"\xEF\xBB\xBF");
            $delimiter=trim($first)==='sep=;' ? ';' : (substr_count($first,';')>substr_count($first,',') ? ';' : ',');
            $line=trim($first)==='sep=;' ? 2 : 1;
            if($line===1) rewind($stream);
            $header=fgetcsv($stream,0,$delimiter,'"','');
            if(!$header || array_map(fn($x)=>trim(ltrim($x,"\xEF\xBB\xBF")),$header)!==self::COLUMNS) $this->fail('Tiêu đề không đúng mẫu. Tải lại mẫu bảo hành.');
            while(($cells=fgetcsv($stream,0,$delimiter,'"',''))!==false) {
                $line++;
                if(!array_filter($cells,fn($x)=>trim((string)$x)!=='')) continue;
                if(count($cells)!==6) $this->fail('Dòng '.$line.' không đúng số cột.');
                $rows[]=['row'=>$line,'data'=>array_combine(['customer_code','serial','product','purchase','start','months'],array_map(fn($x)=>trim((string)$x),$cells))];
                if(count($rows)>300) $this->fail('Tối đa 300 serial mỗi lần.');
            }
        } finally { fclose($stream); }
        }
        if(!$rows) $this->fail('File chưa có dữ liệu.');
        return DB::transaction(function()use($request,$service,$rows){
            if($request->boolean('confirm')) Tenant::whereKey($request->user()->tenant_id)->lockForUpdate()->firstOrFail();
            $errors=[]; $ready=[]; $seen=[];
            foreach($rows as $row) {
                $d=$row['data']; $d['serial']=mb_strtoupper($d['serial']);
                foreach(['purchase','start'] as $field) if(!empty($d[$field])) {
                    $value=$d[$field];
                    if(preg_match('~^\d{2}/\d{2}/\d{4}$~D',$value)) {
                        $date=\DateTimeImmutable::createFromFormat('!d/m/Y',$value);
                        if($date && $date->format('d/m/Y')===$value) $d[$field]=$date->format('Y-m-d');
                    }
                }
                $validator=Validator::make($d,['customer_code'=>'required|string|max:100','serial'=>'required|string|max:120','product'=>'required|string|max:255','purchase'=>'nullable|date_format:Y-m-d','start'=>'required|date_format:Y-m-d','months'=>'required|integer|min:1|max:120']);
                if($validator->fails()) { $errors[]=['row'=>$row['row'],'message'=>'Kiểm tra mã khách, serial, sản phẩm, ngày dd/mm/yyyy và số tháng 1–120.']; continue; }
                if($d['purchase'] && $d['purchase']>$d['start']) { $errors[]=['row'=>$row['row'],'message'=>'Ngày bắt đầu không được trước ngày mua.']; continue; }
                $customer=DataScope::owned(Customer::where('tenant_id',$request->user()->tenant_id)->where('code',$d['customer_code'])->where('status','active'),$request->user(),'sales_owner_id','salesOwner')->first();
                if(!$customer) { $errors[]=['row'=>$row['row'],'message'=>'Mã khách không tồn tại, ngừng hoạt động hoặc ngoài phạm vi phụ trách.']; continue; }
                $identity=BusinessIdentity::warranty($d['product'],$d['serial']);
                if(isset($seen[$identity]) || WarrantyClaim::where('tenant_id',$request->user()->tenant_id)->where('registration_identity',$identity)->exists()) { $errors[]=['row'=>$row['row'],'message'=>'Serial của sản phẩm bị trùng trong file hoặc đã đăng ký.']; continue; }
                $seen[$identity]=true;
                $ready[]=['customer'=>$customer,'data'=>$d];
            }
            if(!$errors && $request->boolean('confirm')) foreach($ready as $item) {
                $c=$item['customer']; $d=$item['data'];
                $service->registerWarranties($request->user(),['customer_id'=>$c->id,'serial_numbers'=>[$d['serial']],'product_name'=>$d['product'],'tax_code'=>$c->tax_code,'customer_name'=>$c->name,'customer_phone'=>$c->phone,'customer_email'=>$c->email,'customer_address'=>$c->address,'purchase_date'=>$d['purchase'] ?: null,'warranty_start_at'=>$d['start'],'warranty_months'=>(int)$d['months']]);
            }
            return response()->json(['data'=>['total'=>count($rows),'can_import'=>!$errors,'imported'=>!$errors && $request->boolean('confirm') ? count($ready) : 0,'errors'=>$errors,'preview'=>array_slice(array_map(fn($r)=>['row'=>$r['row'],...$r['data']],$rows),0,20)]]);
        });
    }
    private function fail(string $message):never { throw ValidationException::withMessages(['file'=>$message]); }
}
