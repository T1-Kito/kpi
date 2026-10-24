<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;
use SimpleXMLElement;
use ZipArchive;

/** Bounded OOXML data reader. Never evaluates formulas or loads external resources. */
class CustomerImportWorkbook
{
    public const LABELS = ['name'=>'Tên khách hàng','customer_type'=>'Loại khách hàng','phone'=>'Số điện thoại',
        'email'=>'Email','tax_code'=>'Mã số thuế','address'=>'Địa chỉ','contact_name'=>'Người liên hệ','identity_number'=>'Số CCCD'];
    private const NS='http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    public function __construct(private ?array $importLabels=null, private array $textIdentifiers=['phone','tax_code','identity_number'], private array $dateColumns=[]) {}
    public static function header(array $values, ?array $labels=null): array
    {
        $labels ??= self::LABELS;
        $aliases=[];
        foreach ($labels as $key=>$label) { $aliases[mb_strtolower($label)]=$key; $aliases[$key]=$key; }
        if(array_key_exists('customer_ref',$labels))$aliases['mã khách hàng']='customer_ref';
        foreach(['phiên bản mẫu (điền 1)'=>'schema_version','mã khách trong danh sách'=>'external_id','lần cập nhật (bắt đầu 1)'=>'source_revision'] as $label=>$key)if(array_key_exists($key,$labels))$aliases[$label]=$key;
        $headers=array_map(fn($value)=>$aliases[mb_strtolower(trim(ltrim((string)$value,"\xEF\xBB\xBF")))] ?? trim((string)$value),$values);
        $first=array_key_first($labels);$required=$first==='schema_version' && !in_array('schema_version',$headers,true)?'external_id':$first;
        if (!in_array($required,$headers,true) || count(array_unique($headers))!==count($headers) || array_diff($headers,array_keys($labels))) {
            self::fail('Tiêu đề không đúng mẫu hoặc có cột trùng. Hãy tải file mẫu mới.');
        }
        return $headers;
    }

    public static function type(?string $value): string
    {
        return match(mb_strtolower(trim($value ?? ''))) {
            '', 'doanh nghiệp', 'organization'=>'organization', 'cá nhân', 'person'=>'person', default=>trim($value),
        };
    }

    public function read(string $path): array
    {
        $zip=new ZipArchive;
        if ($zip->open($path,ZipArchive::RDONLY)!==true) self::fail('Không đọc được file Excel. Hãy lưu lại dưới dạng .xlsx.');
        try {
            if ($zip->numFiles>100) self::fail('File Excel có quá nhiều thành phần. Hãy dùng file mẫu.');
            $bytes=0;
            for ($i=0;$i<$zip->numFiles;$i++) {
                $stat=$zip->statIndex($i); $bytes+=$stat['size'];
                if ($bytes>8*1024*1024 || $stat['size']>2*1024*1024) self::fail('Nội dung Excel vượt giới hạn cho phép.');
                if (preg_match('~vbaProject|externalLinks/~i',$stat['name'])) self::fail('Không nhận file có macro hoặc liên kết ngoài.');
            }
            $workbook=$this->xml($zip,'xl/workbook.xml');
            $properties=$workbook->children(self::NS)->workbookPr;
            $date1904=isset($properties[0]) && in_array((string)($properties->attributes()['date1904'] ?? ''),['1','true'],true);
            $workbook->registerXPathNamespace('m',self::NS);
            $first=($workbook->xpath('/m:workbook/m:sheets/m:sheet') ?: [])[0] ?? null;
            if ($first===null) self::fail('File Excel không có trang dữ liệu.');
            $rid=(string)$first->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
            $rels=$this->xml($zip,'xl/_rels/workbook.xml.rels');
            $target=null;
            foreach ($rels->children('http://schemas.openxmlformats.org/package/2006/relationships')->Relationship as $rel) {
                $attributes=$rel->attributes();
                if ((string)$attributes['TargetMode']==='External') self::fail('Không nhận file có liên kết ngoài.');
                if ((string)$attributes['Id']===$rid && str_ends_with((string)$attributes['Type'],'/worksheet')) $target=(string)$attributes['Target'];
            }
            $target=$target===null ? '' : (str_starts_with($target,'/') ? ltrim($target,'/') : 'xl/'.$target);
            if (!preg_match('~^xl/worksheets/[a-zA-Z0-9_-]+\.xml$~D',$target)) self::fail('Trang dữ liệu Excel không hợp lệ.');
            $strings=[];
            if ($zip->locateName('xl/sharedStrings.xml')!==false) {
                $shared=$this->xml($zip,'xl/sharedStrings.xml');$shared->registerXPathNamespace('m',self::NS);
                foreach ($shared->xpath('/m:sst/m:si') ?: [] as $item) {
                    $strings[]=$this->text($item);
                    if (count($strings)>10000) self::fail('File có quá nhiều chuỗi văn bản.');
                }
            }
            $sheet=$this->xml($zip,$target);$sheet->registerXPathNamespace('m',self::NS);
            $sourceRows=$sheet->xpath('/m:worksheet/m:sheetData/m:row') ?: [];
            if (count($sourceRows)>1000) self::fail('File có quá nhiều dòng. Tối đa 300 khách mỗi lần.');
            $header=null; $rows=[]; $previousLine=0;
            foreach ($sourceRows as $row) {
                $line=(int)$row->attributes()['r']; $cells=[]; $numeric=[];
                if ($line<1 || $line<=$previousLine) self::fail('Thứ tự dòng Excel không hợp lệ.');
                $previousLine=$line;
                foreach ($row->children(self::NS)->c as $cell) {
                    $content=$cell->children(self::NS);
                    if (isset($content->f)) self::fail('Dòng '.$line.' có công thức. Hãy dán giá trị trước khi nhập.');
                    $attributes=$cell->attributes();
                    if (!preg_match('/^([A-Z]+)([0-9]+)$/D',(string)$attributes['r'],$match) || (int)$match[2]!==$line) self::fail('Địa chỉ ô Excel không hợp lệ.');
                    $column=0; foreach (str_split($match[1]) as $char) $column=$column*26+ord($char)-64;
                    $type=(string)$attributes['t'];
                    if ($type==='inlineStr' && !isset($content->is)) self::fail('Chuỗi văn bản Excel không hợp lệ.');
                    $value=match($type) {
                        's'=>isset($strings[(string)$content->v]) ? $strings[(string)$content->v] : $this->invalidString(),
                        'inlineStr'=>$this->text($content->is), default=>(string)$content->v,
                    };
                    if ($value==='') continue;
                    if (mb_strlen($value)>2048) self::fail('Dòng '.$line.' có nội dung quá dài.');
                    if ($column>count($this->importLabels ?? self::LABELS)) self::fail('Có dữ liệu ngoài các cột của mẫu. Vui lòng kiểm tra file.');
                    if ($column<1 || isset($cells[$column-1])) self::fail('Ô dữ liệu Excel bị trùng hoặc không hợp lệ.');
                    $cells[$column-1]=trim($value);
                    if ($type==='' || $type==='n') $numeric[$column-1]=true;
                    if ($type==='e' || $type==='b') self::fail('Dòng '.$line.' có dữ liệu ô không hợp lệ.');
                }
                if (!$cells) continue;
                if (!$header) {
                    if ($line>10) self::fail('Không tìm thấy hàng tiêu đề theo mẫu.');
                    $values=array_replace(array_fill(0,max(array_keys($cells))+1,''),$cells);
                    $labels=array_map(fn($value)=>mb_strtolower($value),$values);
                    $spec=$this->importLabels ?? self::LABELS;
                    $looksLikeHeader=in_array(mb_strtolower(reset($spec)),$labels,true) || in_array(array_key_first($spec),$labels,true) || (array_key_first($spec)==='schema_version' && in_array('phiên bản mẫu (điền 1)',$labels,true));
                    if(array_key_first($spec)==='schema_version' && (in_array('mã ngoài',$labels,true) || in_array('external_id',$labels,true)))$looksLikeHeader=true;
                    if (!$looksLikeHeader) continue;
                    $header=self::header($values,$spec); continue;
                }
                $values=array_fill(0,count($header),null);
                foreach ($cells as $column=>$value) {
                    if (!isset($header[$column])) self::fail('Dòng '.$line.' có dữ liệu ngoài tiêu đề.');
                    if (isset($numeric[$column]) && in_array($header[$column],$this->textIdentifiers,true)) {
                        self::fail('Dòng '.$line.': mã và số nhận diện cần định dạng văn bản để giữ số 0 đầu.');
                    }
                    if (isset($numeric[$column]) && in_array($header[$column],$this->dateColumns,true)) {
                        if(!ctype_digit($value) || (int)$value<1 || (int)$value>200000 || (!$date1904 && (int)$value===60)) self::fail('Ngày Excel không hợp lệ ở dòng '.$line.'.');
                        $value=\Carbon\CarbonImmutable::parse($date1904 ? '1904-01-01' : ((int)$value<60 ? '1899-12-31' : '1899-12-30'))->addDays((int)$value)->format('d/m/Y');
                    }
                    $values[$column]=$value;
                }
                $data=array_combine($header,$values);
                if($this->importLabels===null) $data['customer_type']=self::type($data['customer_type'] ?? null);
                $rows[]=['row'=>$line,'data'=>$data];
                if (count($rows)>300) self::fail('Mỗi lần nhập tối đa 300 khách hàng.');
            }
            if (!$header || !$rows) self::fail('File chưa có khách hàng. Điền dữ liệu bên dưới hàng tiêu đề.');
            return $rows;
        } finally { $zip->close(); }
    }

    private function text(SimpleXMLElement $element): string
    {
        return implode('',array_map(fn($part)=>(string)$part,$element->xpath('.//*[local-name()="t"]') ?: []));
    }

    private function xml(ZipArchive $zip,string $name): SimpleXMLElement
    {
        $raw=$zip->getFromName($name);
        if ($raw===false || !mb_check_encoding($raw,'UTF-8') || str_contains($raw,"\0") || preg_match('/<!DOCTYPE|<!ENTITY/i',$raw)) self::fail('Cấu trúc Excel không hợp lệ.');
        $old=libxml_use_internal_errors(true);
        try { $xml=simplexml_load_string($raw,SimpleXMLElement::class,LIBXML_NONET); }
        finally { libxml_clear_errors();libxml_use_internal_errors($old); }
        if ($xml===false) self::fail('Cấu trúc Excel không hợp lệ.');
        return $xml;
    }

    private function invalidString(): never { self::fail('Chuỗi văn bản Excel không hợp lệ.'); }
    private static function fail(string $message): never { throw ValidationException::withMessages(['file'=>$message]); }
}
