<?php
namespace App\Support;

use DOMDocument;
use DOMXPath;
use ZipArchive;

/** Extend the established Vietnamese template without changing its visual style. */
final class LeadImportTemplate
{
    public static function build(bool $source = false): string
    {
        $path = tempnam(sys_get_temp_dir(), 'lead-template-');
        copy(resource_path('templates/import/'.($source ? 'khach-tiem-nang-theo-nguon-v1.xlsx' : 'leads.xlsx')), $path);
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) throw new \RuntimeException('Không tạo được mẫu Excel.');
        try {
            $doc = new DOMDocument;
            $doc->loadXML($zip->getFromName('xl/worksheets/sheet1.xml'), LIBXML_NONET);
            $ns = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
            $xp = new DOMXPath($doc); $xp->registerNamespace('m', $ns);
            $labels = array_slice(\App\Http\Controllers\Api\V1\DirectoryImportController::LEAD, 5);
            $headerRow=$source ? 1 : 4; $firstColumn=$source ? 9 : 6;
            foreach ($xp->query('//m:sheetData/m:row') as $row) {
                $number = (int)$row->getAttribute('r');
                if ($number < $headerRow) continue;
                $last=$xp->query('m:c[last()]',$row)->item(0);
                foreach (array_values($labels) as $i => $label) {
                    $cell = $doc->createElementNS($ns, 'c');
                    $cell->setAttribute('r', chr(64 + $firstColumn + $i).$number);
                    $cell->setAttribute('s', $last ? $last->getAttribute('s') : '0');
                    if ($number === $headerRow) {
                        $cell->setAttribute('t', 'inlineStr');
                        $is = $doc->createElementNS($ns, 'is');
                        $text = $doc->createElementNS($ns, 't'); $text->appendChild($doc->createTextNode($label));
                        $is->appendChild($text); $cell->appendChild($is);
                    }
                    $row->appendChild($cell);
                }
            }
            $cols = $xp->query('//m:cols')->item(0);
            if ($cols) for ($i=$firstColumn; $i<$firstColumn+6; $i++) {
                $col=$doc->createElementNS($ns,'col');
                foreach (['min'=>$i,'max'=>$i,'width'=>in_array($i,[6,10,11])?38:24,'customWidth'=>1] as $k=>$v) $col->setAttribute($k,(string)$v);
                $cols->appendChild($col);
            }
            foreach ($xp->query('//m:mergeCell | //m:autoFilter | //m:dimension') as $node) {
                $node->setAttribute('ref', preg_replace($source ? '/(?<=:)H(?=\d)/' : '/(?<=:)E(?=\d)/',$source ? 'N' : 'K',$node->getAttribute('ref')));
            }
            $zip->addFromString('xl/worksheets/sheet1.xml',$doc->saveXML());
            if (!$source && $zip->locateName('xl/tables/table1.xml')!==false) {
                $table=new DOMDocument; $table->loadXML($zip->getFromName('xl/tables/table1.xml'),LIBXML_NONET);
                $tx=new DOMXPath($table);$tx->registerNamespace('m',$ns);
                foreach($tx->query('/m:table | /m:table/m:autoFilter') as $node) $node->setAttribute('ref',str_replace(':E',':K',$node->getAttribute('ref')));
                $columns=$tx->query('/m:table/m:tableColumns')->item(0);
                foreach(array_values($labels) as $i=>$label) {
                    $column=$table->createElementNS($ns,'tableColumn');$column->setAttribute('id',(string)($i+6));$column->setAttribute('name',$label);$columns->appendChild($column);
                }
                $columns->setAttribute('count','11');
                $zip->addFromString('xl/tables/table1.xml',$table->saveXML());
            }
        } finally { $zip->close(); }
        return $path;
    }
}
