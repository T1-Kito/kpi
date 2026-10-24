<?php
namespace App\Support;

use App\Models\{Customer,User};
use Illuminate\Validation\ValidationException;
use ZipArchive;

/** Add tenant-scoped reference lists to a disposable copy; never mutate the shared template. */
final class ImportTemplateOptions
{
    private const NS='http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    public function build(string $template,User $actor,string $kind):string
    {
        $product=$kind==='skus';
        $users=$product?\App\Models\ProductCategory::where('tenant_id',$actor->tenant_id)->where('status','active')->orderBy('name')->get()->map(fn($v)=>(object)['email'=>'crm:'.$v->name,'name'=>$v->name]):User::where('tenant_id',$actor->tenant_id)->where('is_active',true)->orderBy('email')->get(['email','name']);
        $customers=$product?\App\Models\ProductBrand::where('tenant_id',$actor->tenant_id)->where('status','active')->orderBy('name')->get()->map(fn($v)=>(object)['code'=>$v->name,'name'=>$v->name]):($kind!=='customers' && $actor->hasPermission('customer.manage')
            ? Customer::where('tenant_id',$actor->tenant_id)->where('customer_type','organization')->where('status','active')->whereNull('merged_into_id')->orderBy('code')->get(['code','name']) : collect());
        if($kind==='warehouses')$customers=collect();
        $positions=collect();
        if(in_array($kind,['users','departments'],true)){
            $users=User::where('tenant_id',$actor->tenant_id)->where('is_active',true)->whereDoesntHave('roles',fn($q)=>$q->where('code',SystemAccount::ROLE))->orderBy('email')->get(['email','name']);
            $customers=\App\Models\Department::where('tenant_id',$actor->tenant_id)->orderBy('code')->get(['code','name']);
            if($kind==='users')$positions=\App\Models\Position::where('tenant_id',$actor->tenant_id)->orderBy('code')->get(['code','name']);
        }
        if($kind==='locations'){
            $users=\App\Models\Warehouse::where('tenant_id',$actor->tenant_id)->where('status','active')->orderBy('code')->get()->map(fn($v)=>(object)['email'=>'crm:'.$v->code,'name'=>$v->name]);$customers=collect();
        }
        if($users->count()>10000 || $customers->count()>10000 || $positions->count()>10000)throw ValidationException::withMessages(['file'=>'Danh mục tham chiếu vượt 10.000 hồ sơ. Liên hệ quản trị để chia danh mục.']);
        $path=tempnam(sys_get_temp_dir(),'vk-template-');
        if($path===false || !copy($template,$path))throw new \RuntimeException('Không chuẩn bị được file mẫu.');
        $zip=new ZipArchive;
        try{
            if($zip->open($path)!==true)throw new \RuntimeException('Không mở được file mẫu.');
            $book=$this->xml($zip,'xl/workbook.xml');$rels=$this->xml($zip,'xl/_rels/workbook.xml.rels');$types=$this->xml($zip,'[Content_Types].xml');
            $sheet=$this->xml($zip,'xl/worksheets/sheet1.xml');
            $id=100;$relId='rIdImportOptions';
            $node=$book->children(self::NS)->sheets->addChild('sheet',null,self::NS);$node->addAttribute('name','Danh mục tham chiếu');$node->addAttribute('sheetId',(string)$id);$node->addAttribute('r:id',$relId,'http://schemas.openxmlformats.org/officeDocument/2006/relationships');
            $rel=$rels->addChild('Relationship',null,'http://schemas.openxmlformats.org/package/2006/relationships');$rel->addAttribute('Id',$relId);$rel->addAttribute('Type','http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet');$rel->addAttribute('Target','worksheets/sheet100.xml');
            $type=$types->addChild('Override',null,'http://schemas.openxmlformats.org/package/2006/content-types');$type->addAttribute('PartName','/xl/worksheets/sheet100.xml');$type->addAttribute('ContentType','application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml');
            $rows=[$kind==='locations'?['Mã kho tham chiếu','Tên kho','','']:($kind==='warehouses'?['Email người quản lý','Họ tên','','']:($product?['Mã danh mục','Tên danh mục','Mã hãng','Tên hãng']:['Email người phụ trách','Họ tên','Mã khách hàng','Tên khách hàng']))];
            for($i=0;$i<max($users->count(),$customers->count());$i++)$rows[]=[$users[$i]->email ?? '',$users[$i]->name ?? '',isset($customers[$i])?'crm:'.$customers[$i]->code:'',$customers[$i]->name ?? ''];
            if(in_array($kind,['users','departments'],true)){
                $rows=[['Email người quản lý','Họ tên','Mã phòng ban','Tên phòng ban','Mã chức vụ','Tên chức vụ']];
                for($i=0;$i<max($users->count(),$customers->count(),$positions->count());$i++)$rows[]=[$users[$i]->email ?? '',$users[$i]->name ?? '',isset($customers[$i])?'crm:'.$customers[$i]->code:'',$customers[$i]->name ?? '',isset($positions[$i])?'crm:'.$positions[$i]->code:'',$positions[$i]->name ?? ''];
            }
            $reference='<worksheet xmlns="'.self::NS.'"><sheetViews><sheetView workbookViewId="0"/></sheetViews><cols><col min="1" max="'.(in_array($kind,['users','departments'],true)?'6':'4').'" width="35" customWidth="1"/></cols><sheetData>';
            foreach($rows as $r=>$values){$reference.='<row r="'.($r+1).'">';foreach($values as $c=>$value)$reference.='<c r="'.chr(65+$c).($r+1).'" t="inlineStr"><is><t>'.htmlspecialchars($value,ENT_XML1|ENT_QUOTES,'UTF-8').'</t></is></c>';$reference.='</row>';}
            $reference.='</sheetData></worksheet>';
            $sheetNode=dom_import_simplexml($sheet);$doc=$sheetNode->ownerDocument;
            $validations=$doc->getElementsByTagNameNS(self::NS,'dataValidations')->item(0);
            if(!$validations){
                $validations=$doc->createElementNS(self::NS,'dataValidations');$before=null;
                foreach($sheetNode->childNodes as $child)if(in_array($child->localName,['hyperlinks','printOptions','pageMargins','pageSetup','headerFooter','drawing','legacyDrawing','extLst'],true)){$before=$child;break;}
                $sheetNode->insertBefore($validations,$before);
            }
            if($users->isNotEmpty() && $kind!=='contacts')$this->validation($validations,match($kind){'users'=>'F2:F301','departments'=>'D2:D301','skus'=>'F2:F301','customers'=>'N2:N301','warehouses'=>'D2:D301','locations'=>'B2:B301',default=>'G2:G301'},'A',$users->count());
            if($customers->isNotEmpty())$this->validation($validations,match($kind){'users'=>'D2:D301','departments'=>'C2:C301','skus'=>'G2:G301','contacts'=>'B2:B301',default=>'H2:H301'},'C',$customers->count());
            if($positions->isNotEmpty())$this->validation($validations,'E2:E301','E',$positions->count());
            $validations->setAttribute('count',(string)$validations->getElementsByTagNameNS(self::NS,'dataValidation')->length);
            foreach(['xl/workbook.xml'=>$book->asXML(),'xl/_rels/workbook.xml.rels'=>$rels->asXML(),'[Content_Types].xml'=>$types->asXML(),'xl/worksheets/sheet1.xml'=>$sheet->asXML(),'xl/worksheets/sheet100.xml'=>$reference] as $name=>$bytes)if(!$zip->addFromString($name,$bytes))throw new \RuntimeException('Không hoàn tất được file mẫu.');
            $zip->close();return $path;
        }catch(\Throwable $e){$zip->close();@unlink($path);throw $e;}
    }
    private function validation(\DOMElement $parent,string $range,string $column,int $count):void
    {
        $doc=$parent->ownerDocument;$rule=$doc->createElementNS(self::NS,'dataValidation');$parent->appendChild($rule);
        foreach(['type'=>'list','allowBlank'=>'1','showErrorMessage'=>'0','sqref'=>$range] as $key=>$value)$rule->setAttribute($key,$value);
        $formula=$doc->createElementNS(self::NS,'formula1');$formula->appendChild($doc->createTextNode('INDIRECT("\'Danh mục tham chiếu\'!$'.$column.'$2:$'.$column.'$'.($count+1).'")'));$rule->appendChild($formula);
    }
    private function xml(ZipArchive $zip,string $name):\SimpleXMLElement
    {
        $raw=$zip->getFromName($name);if($raw===false)throw new \RuntimeException('Mẫu Excel thiếu thành phần.');
        $xml=simplexml_load_string($raw,\SimpleXMLElement::class,LIBXML_NONET);if($xml===false)throw new \RuntimeException('Mẫu Excel không hợp lệ.');return $xml;
    }
}
