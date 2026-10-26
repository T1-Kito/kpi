<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use SimpleXMLElement;
use ZipArchive;

/** Reads only the explicitly named intake sheet. Never evaluates Excel formulas. */
final class CrmIntakeWorkbook
{
    public const LABELS = [
        'external_ref'=>'Mã KH','created_date'=>'Ngày tạo','group1'=>'ĐỐI TƯỢNG CẤP 1','group2'=>'ĐỐI TƯỢNG CẤP 2','group3'=>'ĐỐI TƯỢNG CẤP 3',
        'service1'=>'Nhóm SP cấp 1','service2'=>'Nhóm SP cấp 2','channel1'=>'Kênh tiếp cận cấp 1','channel2'=>'Kênh tiếp cận cấp 2',
        'person_name'=>'Tên khách hàng','company_name'=>'Tên đơn vị','tax_code'=>'Mã số thuế','contact_name'=>'Người liên hệ','position'=>'Chức danh',
        'phone'=>'Số điện thoại','email'=>'Email','province'=>'Tỉnh/TP','source'=>'Nguồn khách hàng','contact_channel'=>'Kênh liên hệ',
        'interests'=>'Sản phẩm quan tâm','purpose'=>'Mục đích','group_size'=>'Quy mô nhóm','guest_count'=>'Số khách dự kiến','service_date'=>'Ngày dự kiến',
        'budget'=>'Ngân sách dự kiến','crm_status'=>'Trạng thái CRM','owner'=>'Người phụ trách','last_contact'=>'Ngày liên hệ gần nhất',
        'next_contact'=>'Ngày liên hệ tiếp theo','amount'=>'Giá trị cơ hội','probability'=>'Xác suất','forecast'=>'Doanh thu dự kiến',
        'special_requests'=>'Yêu cầu đặc biệt','note'=>'Ghi chú',
    ];
    private const NS='http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    public function read(string $path): array
    {
        $zip=new ZipArchive;
        if($zip->open($path,ZipArchive::RDONLY)!==true) self::fail('Không đọc được file Excel .xlsx.');
        try {
            if($zip->numFiles>100) self::fail('File có quá nhiều thành phần.');
            $bytes=0;
            for($i=0;$i<$zip->numFiles;$i++) {
                $s=$zip->statIndex($i);$bytes+=$s['size'];
                if($s['size']>2*1024*1024 || $bytes>8*1024*1024) self::fail('Nội dung Excel vượt giới hạn cho phép.');
                if(preg_match('~vbaProject|externalLinks/~i',$s['name'])) self::fail('Không nhận file có macro hoặc liên kết ngoài.');
            }
            $wb=$this->xml($zip,'xl/workbook.xml');$wb->registerXPathNamespace('m',self::NS);
            $sheets=$wb->xpath('/m:workbook/m:sheets/m:sheet') ?: [];
            $matches=array_values(array_filter($sheets,fn($s)=>trim((string)$s['name'])==='PHÂN NGUỒN DỮ LIỆU'));
            if(count($matches)!==1) self::fail('Không tìm thấy duy nhất trang “PHÂN NGUỒN DỮ LIỆU”.');
            $rid=(string)$matches[0]->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
            $rels=$this->xml($zip,'xl/_rels/workbook.xml.rels');$target='';
            foreach($rels->children('http://schemas.openxmlformats.org/package/2006/relationships')->Relationship as $rel) {
                $attributes=$rel->attributes();
                if((string)$attributes['TargetMode']==='External') self::fail('Không nhận liên kết ngoài.');
                if((string)$attributes['Id']===$rid && str_ends_with((string)$attributes['Type'],'/worksheet')) $target=(string)$attributes['Target'];
            }
            $target=str_starts_with($target,'/')?ltrim($target,'/'):'xl/'.$target;
            if(!preg_match('~^xl/worksheets/[a-zA-Z0-9_-]+\.xml$~D',$target)) self::fail('Trang dữ liệu không hợp lệ.');
            $strings=[];
            if($zip->locateName('xl/sharedStrings.xml')!==false) {
                $ss=$this->xml($zip,'xl/sharedStrings.xml');$ss->registerXPathNamespace('m',self::NS);
                foreach($ss->xpath('/m:sst/m:si') ?: [] as $si) $strings[]=$this->text($si);
                if(count($strings)>10000) self::fail('File có quá nhiều chuỗi văn bản.');
            }
            $sheet=$this->xml($zip,$target);$sheet->registerXPathNamespace('m',self::NS);
            $rows=[];$header=false;$previous=0;$ignored=0;
            $date1904=in_array((string)($wb->children(self::NS)->workbookPr->attributes()['date1904'] ?? ''),['1','true'],true);
            foreach($sheet->xpath('/m:worksheet/m:sheetData/m:row') ?: [] as $row) {
                $line=(int)$row->attributes()['r'];
                if($line<1 || $line<=$previous) self::fail('Thứ tự dòng Excel không hợp lệ.');
                $previous=$line;$cells=[];$formulas=[];$numeric=[];$errors=[];$seenCells=[];
                foreach($row->children(self::NS)->c as $cell) {
                    $attributes=$cell->attributes();
                    if(!preg_match('/^([A-Z]+)([0-9]+)$/D',(string)$attributes['r'],$m) || (int)$m[2]!==$line) self::fail('Địa chỉ ô Excel không hợp lệ.');
                    $col=0;foreach(str_split($m[1]) as $c)$col=$col*26+ord($c)-64;
                    if(isset($seenCells[$col])) self::fail('Ô dữ liệu bị trùng.');
                    $seenCells[$col]=true;
                    $v=$cell->children(self::NS);$type=(string)$attributes['t'];
                    if($type==='s' && (!ctype_digit((string)$v->v) || !isset($strings[(int)$v->v])))self::fail('Chuỗi Excel không hợp lệ.');
                    $value=match($type) {'s'=>$strings[(int)$v->v] ?? throw ValidationException::withMessages(['file'=>'Chuỗi Excel không hợp lệ.']),'inlineStr'=>$this->text($v->is),default=>(string)$v->v};
                    if($value==='' && !isset($v->f))continue;
                    if($col>34) self::fail('Dòng '.$line.' có dữ liệu ngoài 34 cột của mẫu.');
                    if(mb_strlen($value)>2048) self::fail('Dòng '.$line.' có ô vượt 2.048 ký tự.');
                    $cells[$col]=trim($value);
                    if(isset($v->f))$formulas[$col]=(string)$v->f;
                    if(in_array($type,['','n'],true))$numeric[$col]=true;
                    if(in_array($type,['e','b'],true))$errors[]='Ô '.$m[1].$line.' không phải dữ liệu hợp lệ.';
                }
                if(!$cells && !$formulas)continue;
                if(!$header) {
                    if($line>10)self::fail('Không tìm thấy tiêu đề 34 cột.');
                    if(($cells[1] ?? '')!=='Mã KH')continue;
                    if(array_values(array_replace(array_fill(1,34,''),$cells))!==array_values(self::LABELS) || $formulas) self::fail('Tiêu đề 34 cột không đúng mẫu khách đã gửi.');
                    $header=true;continue;
                }
                if(($cells[1] ?? '')==='QUY ƯỚC NHẬP LIỆU')break;
                // The supplied workbook contains code/date/formula-only filler rows.
                $substantive=array_filter($cells,fn($v,$k)=>$v!=='' && !in_array($k,[1,2,32],true) && !isset($formulas[$k]),ARRAY_FILTER_USE_BOTH);
                if(!$substantive){$ignored++;continue;}
                foreach($formulas as $col=>$formula) {
                    if($col===32 && preg_match('/^\$?AD\$?'.$line.'\s*\*\s*\$?AE\$?'.$line.'$/iD',trim($formula)))continue;
                    $errors[]='Cột “'.array_values(self::LABELS)[$col-1].'” có công thức. Hãy kiểm tra và dán giá trị đúng trước khi nhập.';
                }
                foreach([1,12,15] as $col) if(isset($numeric[$col]) && ($cells[$col] ?? '')!=='') $errors[]='Cột “'.array_values(self::LABELS)[$col-1].'” cần định dạng Văn bản để giữ số 0 đầu.';
                foreach([2,24,28,29] as $col) if(isset($numeric[$col]) && ($cells[$col] ?? '')!=='') {
                    $n=$cells[$col];
                    if(!is_numeric($n) || (float)$n<1 || (float)$n>200000 || (!$date1904 && (int)$n===60)){$errors[]='Ngày không hợp lệ ở cột “'.array_values(self::LABELS)[$col-1].'”.';continue;}
                    $cells[$col]=CarbonImmutable::parse($date1904?'1904-01-01':((int)$n<60?'1899-12-31':'1899-12-30'))->addSeconds((int)round((float)$n*86400))->format('Y-m-d H:i:s');
                }
                $rows[]=['row'=>$line,'data'=>array_combine(array_keys(self::LABELS),array_values(array_replace(array_fill(1,34,''),$cells))),'errors'=>array_values(array_unique($errors))];
                if(count($rows)>300) self::fail('Mỗi lần nhập tối đa 300 dòng có dữ liệu.');
            }
            if(!$header || !$rows)self::fail('Trang phân nguồn chưa có dữ liệu để nhập.');
            return ['rows'=>$rows,'ignored'=>$ignored];
        } finally {$zip->close();}
    }

    private function xml(ZipArchive $zip,string $name): SimpleXMLElement
    {
        $raw=$zip->getFromName($name);
        if($raw===false || !mb_check_encoding($raw,'UTF-8') || str_contains($raw,"\0") || preg_match('/<!DOCTYPE|<!ENTITY/i',$raw))self::fail('Cấu trúc Excel không hợp lệ.');
        $old=libxml_use_internal_errors(true);
        try{$xml=simplexml_load_string($raw,SimpleXMLElement::class,LIBXML_NONET);}finally{libxml_clear_errors();libxml_use_internal_errors($old);}
        if($xml===false)self::fail('Cấu trúc Excel không hợp lệ.');
        return $xml;
    }
    private function text(SimpleXMLElement $e):string {return implode('',array_map(fn($n)=>(string)$n,$e->xpath('.//*[local-name()="t"]') ?: []));}
    private static function fail(string $message):never {throw ValidationException::withMessages(['file'=>$message]);}
}
