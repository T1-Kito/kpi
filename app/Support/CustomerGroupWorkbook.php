<?php
namespace App\Support;

use Illuminate\Validation\ValidationException;
use ZipArchive;

/** Accepts the normal template or the explicitly recognized customer classification workbook. */
final class CustomerGroupWorkbook
{
    private const NS='http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    public function read(string $path,bool $services=false):array
    {
        $labels=$services?['level1'=>'Nhóm dịch vụ','level2'=>'Dịch vụ']:CustomerGroupImport::LABELS;
        $columns=$services?['E'=>'A','F'=>'B']:['A'=>'A','B'=>'B','C'=>'C'];
        $reader=new CustomerImportWorkbook($labels,[]);
        try {return $reader->read($path);} catch(ValidationException $original) {
            $zip=new ZipArchive;
            if($zip->open($path,ZipArchive::RDONLY)!==true)throw $original;
            $copy=null;
            try{
                if($zip->numFiles>100)throw $original;
                $bytes=0;
                for($i=0;$i<$zip->numFiles;$i++){
                    $stat=$zip->statIndex($i);$bytes+=$stat['size'];
                    if($stat['size']>2*1024*1024 || $bytes>8*1024*1024 || preg_match('~vbaProject|externalLinks/~i',$stat['name']))throw $original;
                }
                $raw=$zip->getFromName('xl/worksheets/sheet1.xml');
                if(!$raw || str_contains($raw,'<!DOCTYPE'))throw $original;
                $doc=new \DOMDocument;
                if(!@$doc->loadXML($raw,LIBXML_NONET))throw $original;
                $xp=new \DOMXPath($doc);$xp->registerNamespace('m',self::NS);
                $strings=[];$shared=$zip->getFromName('xl/sharedStrings.xml');
                if($shared){
                    if(str_contains($shared,'<!DOCTYPE'))throw $original;
                    $s=new \DOMDocument;if(!@$s->loadXML($shared,LIBXML_NONET))throw $original;
                    $sx=new \DOMXPath($s);$sx->registerNamespace('m',self::NS);
                    foreach($sx->query('//m:si') as $si){$text='';foreach($sx->query('.//m:t',$si) as $t)$text.=$t->textContent;$strings[]=$text;}
                }
                $value=function($cell)use($xp,$strings){if(!$cell)return '';return $cell->getAttribute('t')==='s'?($strings[(int)$xp->evaluate('string(m:v)',$cell)] ?? ''):($cell->getAttribute('t')==='inlineStr'?$xp->evaluate('string(m:is/m:t)',$cell):$xp->evaluate('string(m:v)',$cell));};
                $headers=$services?['E4'=>'NHÓM SẢN PHẨM CẤP 1','F4'=>'NHÓM SẢN PHẨM CẤP 2']:['A4'=>'NHÓM ĐỐI TƯỢNG CẤP 1','B4'=>'NHÓM ĐỐI TƯỢNG CẤP 2','C4'=>'ĐỐI TƯỢNG CỤ THỂ CẤP 3'];
                foreach($headers as $ref=>$expected){
                    if(trim($value($xp->query('//m:c[@r="'.$ref.'"]')->item(0)))!==$expected)throw $original;
                }
                $data=$xp->query('/m:worksheet/m:sheetData')->item(0);$rows=[];
                foreach($xp->query('m:row',$data) as $row){
                    $line=(int)$row->getAttribute('r');
                    if($line<4)continue;
                    $kept=$row->cloneNode(false);$hasValue=false;
                    foreach($xp->query('m:c',$row) as $cell){
                        if(!preg_match('/^([A-Z]+)[0-9]+$/D',$cell->getAttribute('r'),$match) || !isset($columns[$match[1]]))continue;
                        $target=$columns[$match[1]].$line;
                        $hasValue=$hasValue || trim($value($cell))!=='';
                        if($line===4){
                            $new=$cell->cloneNode(false);$new->setAttribute('r',$target);$new->setAttribute('t','inlineStr');$is=$doc->createElementNS(self::NS,'is');$t=$doc->createElementNS(self::NS,'t');$t->appendChild($doc->createTextNode(array_values($labels)[ord($target[0])-65]));$is->appendChild($t);$new->appendChild($is);$kept->appendChild($new);
                        }else {$new=$cell->cloneNode(true);$new->setAttribute('r',$target);$kept->appendChild($new);}
                    }
                    if($hasValue)$rows[]=$kept;
                }
                while($data->firstChild)$data->removeChild($data->firstChild);
                foreach($rows as $row)$data->appendChild($row);
                $copy=tempnam(sys_get_temp_dir(),'vk-group-source-');
                if(!$copy || !copy($path,$copy))throw new \RuntimeException('Không chuẩn bị được file kiểm tra.');
                $temp=new ZipArchive;$temp->open($copy);$temp->addFromString('xl/worksheets/sheet1.xml',$doc->saveXML());$temp->close();
                return $reader->read($copy);
            }finally{$zip->close();if($copy && is_file($copy))unlink($copy);}
        }
    }
}
