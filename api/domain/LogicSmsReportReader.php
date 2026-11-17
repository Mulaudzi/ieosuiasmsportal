<?php
declare(strict_types=1);

require_once __DIR__.'/PhoneNumber.php';
require_once __DIR__.'/LogicSmsDeliveryStatus.php';

/** Read an owner-supplied report locally. No extraction, network or database access. */
final class LogicSmsReportReader
{
    private const MAX_FILE = 5242880;
    private const MAX_ENTRY = 5242880;
    private const MAX_EXPANDED = 20971520;
    private const MAX_ROWS = 10000;
    private const MAX_COLUMNS = 20;
    private const XMLNS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    public static function readFile(string $path, string $originalName): array
    {
        if (!is_file($path) || !is_readable($path)) self::fail('Report file is unavailable');
        $size = filesize($path);
        if ($size === false || $size < 1 || $size > self::MAX_FILE) self::fail('Report exceeds the 5 MB file limit');
        $beforeHash=hash_file('sha256',$path);
        if ($beforeHash===false) self::fail('Report fingerprint is unavailable');
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $rows = match ($extension) {
            'xlsx' => self::xlsx($path),
            'csv' => self::csv($path),
            default => throw new InvalidArgumentException('Only XLSX or CSV reports are supported'),
        };
        $hash = hash_file('sha256', $path);
        if ($hash === false || !hash_equals($beforeHash,$hash)) self::fail('Report changed while it was being read');
        return ['file_sha256'=>$hash, 'row_count'=>count($rows), 'rows'=>$rows,
            'report_date_semantics'=>'provider_report_wall_time_unverified',
            'report_date_is_sent_or_delivered_timestamp'=>false];
    }

    private static function xlsx(string $path): array
    {
        if (!class_exists('ZipArchive')) self::fail('XLSX support is unavailable');
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::RDONLY) !== true) self::fail('Report is not a valid XLSX archive');
        try {
            if ($zip->numFiles < 1 || $zip->numFiles > 1000) self::fail('XLSX package entry limit exceeded');
            $entries=[]; $expanded=0;
            for ($i=0; $i<$zip->numFiles; $i++) {
                $stat=$zip->statIndex($i); $name=$stat['name']??'';
                if ($name==='' || strlen($name)>255 || str_contains($name,"\0") || str_contains($name,'\\')
                    || preg_match('~^(?:/|[A-Za-z]:)|(?:^|/)\.\.?(?:/|$)~D',$name)) self::fail('Unsafe XLSX archive path');
                $canonical=strtolower($name);
                if (isset($entries[$canonical])) self::fail('Duplicate XLSX archive entry');
                if (preg_match('~(?:^|/)(?:externalLinks|embeddings|activeX)(?:/|$)|vbaProject|\.(?:bin|xlsm|xlam)$~i',$name)) self::fail('Active or external XLSX content is not allowed');
                $length=(int)($stat['size']??-1);
                if ($length<0 || $length>self::MAX_ENTRY || ($expanded+=$length)>self::MAX_EXPANDED) self::fail('XLSX expanded-size limit exceeded');
                if ((int)($stat['encryption_method']??0)!==0) self::fail('Encrypted XLSX entries are not supported');
                $entries[$canonical]=['name'=>$name,'size'=>$length,'index'=>$i];
                if (preg_match('/\.(?:xml|rels)$/i',$name)) {
                    $document=self::xml(self::entry($zip,$entries,$name));
                    $xpath=new DOMXPath($document);
                    if ($xpath->query('//*[local-name()="f"]')->length>0) self::fail('XLSX formulas are not allowed');
                    foreach ($xpath->query('//*[local-name()="Relationship"]') as $relationship) {
                        $target=trim($relationship->getAttribute('Target'));
                        if (strcasecmp(trim($relationship->getAttribute('TargetMode')),'External')===0
                            || preg_match('~^(?:[A-Za-z][A-Za-z0-9+.-]*:|//)~',$target)) self::fail('External XLSX relationships are not allowed');
                    }
                    foreach ($xpath->query('//@ContentType') as $type) {
                        if (preg_match('/macroEnabled|vbaProject|activeX|oleObject/i',$type->nodeValue)) self::fail('Macro-enabled XLSX content is not allowed');
                    }
                }
            }
            self::entry($zip,$entries,'[Content_Types].xml');
            $workbook=self::xml(self::entry($zip,$entries,'xl/workbook.xml'));
            $bookXPath=self::xpath($workbook);
            $sheets=$bookXPath->query('/m:workbook/m:sheets/m:sheet');
            $selected=null;
            foreach ($sheets as $sheet) {
                if ($sheet->getAttribute('name')==='SMSSendReport') {
                    if ($selected!==null) self::fail('Ambiguous report worksheet');
                    $selected=$sheet;
                }
            }
            if ($selected===null && $sheets->length===1) $selected=$sheets->item(0);
            if (!$selected instanceof DOMElement) self::fail('A single SMS report worksheet could not be identified');
            $relationId=$selected->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships','id');
            $relations=self::xml(self::entry($zip,$entries,'xl/_rels/workbook.xml.rels'));
            $sheetPath=null;
            foreach ($relations->getElementsByTagNameNS('*','Relationship') as $relation) {
                if ($relation->getAttribute('Id')!==$relationId) continue;
                if ($sheetPath!==null || !str_ends_with($relation->getAttribute('Type'),'/worksheet')) self::fail('Invalid report worksheet relationship');
                $sheetPath=self::resolvePart('xl/workbook.xml',$relation->getAttribute('Target'));
            }
            if ($sheetPath===null) self::fail('Report worksheet relationship is missing');
            $shared=[];
            if (isset($entries['xl/sharedstrings.xml'])) {
                $sharedDocument=self::xml(self::entry($zip,$entries,'xl/sharedStrings.xml'));
                $sharedXPath=self::xpath($sharedDocument);
                foreach ($sharedXPath->query('/m:sst/m:si') as $string) {
                    if (count($shared)>=self::MAX_ROWS*self::MAX_COLUMNS) self::fail('XLSX shared-string limit exceeded');
                    $shared[]=self::text($string,$sharedXPath);
                }
            }
            $dateStyles=self::dateStyles($zip,$entries);
            $date1904=false;
            foreach ($bookXPath->query('/m:workbook/m:workbookPr') as $properties) $date1904=in_array($properties->getAttribute('date1904'),['1','true'],true);
            $sheetDocument=self::xml(self::entry($zip,$entries,$sheetPath));
            $sheetXPath=self::xpath($sheetDocument);
            $raw=[]; $seenRows=[]; $previousRow=0; $cells=0;
            foreach ($sheetXPath->query('/m:worksheet/m:sheetData/m:row') as $row) {
                $rowNumber=filter_var($row->getAttribute('r'),FILTER_VALIDATE_INT);
                if ($rowNumber===false || $rowNumber<1 || $rowNumber>self::MAX_ROWS || isset($seenRows[$rowNumber]) || $rowNumber<=$previousRow) self::fail('Invalid or excessive worksheet row');
                $seenRows[$rowNumber]=true; $previousRow=$rowNumber; $values=[]; $metadata=[];
                foreach ($sheetXPath->query('m:c',$row) as $cell) {
                    if (++$cells>self::MAX_ROWS*self::MAX_COLUMNS) self::fail('XLSX cell limit exceeded');
                    $reference=$cell->getAttribute('r');
                    if (!preg_match('/^([A-Z]{1,3})([1-9][0-9]*)$/D',$reference,$match) || (int)$match[2]!==$rowNumber) self::fail('Invalid worksheet cell reference');
                    $column=0;
                    foreach (str_split($match[1]) as $letter) $column=$column*26+ord($letter)-64;
                    $column--;
                    if ($column>=self::MAX_COLUMNS || array_key_exists($column,$values)) self::fail('Duplicate or excessive worksheet column');
                    $type=$cell->getAttribute('t');
                    $valueNodes=$sheetXPath->query('m:v',$cell);
                    if ($valueNodes->length>1) self::fail('Ambiguous worksheet cell value');
                    $value=$valueNodes->length ? $valueNodes->item(0)->textContent : '';
                    if ($type==='s') {
                        if (!preg_match('/^(?:0|[1-9][0-9]*)$/D',$value) || !array_key_exists((int)$value,$shared)) self::fail('Invalid shared-string reference');
                        $value=$shared[(int)$value];
                    } elseif ($type==='inlineStr') {
                        $inline=$sheetXPath->query('m:is',$cell);
                        if ($inline->length!==1) self::fail('Invalid inline worksheet string');
                        $value=self::text($inline->item(0),$sheetXPath);
                    } elseif (!in_array($type,['','n','str','d'],true)) self::fail('Unsupported worksheet cell type');
                    $values[$column]=$value;
                    $style=$cell->getAttribute('s');
                    if ($style!=='' && !preg_match('/^(?:0|[1-9][0-9]{0,5})$/D',$style)) self::fail('Invalid worksheet style reference');
                    $metadata[$column]=['numeric'=>in_array($type,['','n'],true),'date_style'=>isset($dateStyles[(int)$style]),'date1904'=>$date1904];
                }
                $raw[]=['source_row'=>$rowNumber,'values'=>$values,'metadata'=>$metadata];
            }
            return self::normalizeRows($raw);
        } finally { $zip->close(); }
    }

    private static function csv(string $path): array
    {
        $contents=file_get_contents($path);
        if ($contents===false || !mb_check_encoding($contents,'UTF-8')) self::fail('CSV must be UTF-8');
        $handle=fopen($path,'rb');
        if (!$handle) self::fail('Report file is unavailable');
        $raw=[]; $rowNumber=0;
        try {
            while (($values=fgetcsv($handle,0,',','"',''))!==false) {
                if (++$rowNumber>self::MAX_ROWS || count($values)>self::MAX_COLUMNS) self::fail('CSV row or column limit exceeded');
                $values=array_map(static fn($value):string=>(string)$value,$values);
                if ($rowNumber===1) $values[0]=preg_replace('/^\xEF\xBB\xBF/','',$values[0]);
                $raw[]=['source_row'=>$rowNumber,'values'=>$values,'metadata'=>[]];
            }
        } finally { fclose($handle); }
        return self::normalizeRows($raw);
    }

    private static function normalizeRows(array $raw): array
    {
        $headers=null; $rows=[];
        foreach ($raw as $source) {
            $values=$source['values'];
            if (!$values || count(array_filter($values,static fn($value):bool=>$value!==''))===0) continue;
            if ($headers===null) {
                $headers=[];
                foreach ($values as $column=>$value) {
                    $name=strtolower(trim($value));
                    if ($name==='') continue;
                    if (isset($headers[$name])) self::fail('Duplicate report header');
                    $headers[$name]=$column;
                }
                foreach (['date','mobile','message','status','credits','group'] as $required) if (!isset($headers[$required])) self::fail('Required report header is missing');
                continue;
            }
            $number=(int)$source['source_row'];
            try {
                $field=static fn(string $name):string=>$values[$headers[$name]]??'';
                $dateText=trim($field('date')); $dateMeta=$source['metadata'][$headers['date']]??[];
                if (($dateMeta['numeric']??false) && is_numeric($dateText)) {
                    if (!($dateMeta['date_style']??false)) self::fail('Numeric report date requires a recognized Excel date style');
                    $reportedAt=self::excelDate($dateText,(bool)$dateMeta['date1904']);
                } else $reportedAt=self::reportedAt($dateText);
                $destination=PhoneNumber::normalize($field('mobile'));
                $content=$field('message');
                if ($content==='' || strlen($content)>65536) self::fail('Report message is empty or too large');
                $status=strtoupper(trim($field('status')));
                if ($status==='' || strlen($status)>64 || !preg_match('/^[A-Z][A-Z0-9 _-]*$/D',$status)) self::fail('Invalid report status');
                $credits=trim($field('credits'));
                if (!preg_match('/^(?:0|[1-9][0-9]{0,11})(?:\.[0-9]{1,8})?$/D',$credits) || !is_finite((float)$credits)) self::fail('Invalid provider credit amount');
                $rows[]=['source_row'=>$number,'date_text'=>$dateText,'reported_at'=>$reportedAt,'destination'=>$destination,
                    'content'=>$content,'status'=>$status,'status_state'=>LogicSmsDeliveryStatus::normalize($status),
                    'provider_credits'=>$credits,'group'=>$field('group')];
            } catch (InvalidArgumentException $error) {
                // Only controlled validation messages escape; never include a row,
                // phone, message, XML fragment or parser error in an exception.
                throw new InvalidArgumentException('Report row '.$number.': '.$error->getMessage());
            }
        }
        if ($headers===null || !$rows) self::fail('Report contains no message rows');
        return $rows;
    }

    private static function reportedAt(string $date): string
    {
        if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4}) (0?[1-9]|1[0-2]):([0-5][0-9]):([0-5][0-9]) ([AP]M)$/iD',$date,$match)) {
            $month=(int)$match[1]; $day=(int)$match[2]; $year=(int)$match[3];
            $hour=(int)$match[4]%12+(strtoupper($match[7])==='PM'?12:0); $minute=(int)$match[5]; $second=(int)$match[6];
        } elseif (preg_match('/^(\d{4})-(\d{2})-(\d{2}) ([01][0-9]|2[0-3]):([0-5][0-9]):([0-5][0-9])$/D',$date,$match)) {
            $year=(int)$match[1]; $month=(int)$match[2]; $day=(int)$match[3]; $hour=(int)$match[4]; $minute=(int)$match[5]; $second=(int)$match[6];
        } else self::fail('Unsupported report date format');
        if ($year<1900 || $year>9999 || !checkdate($month,$day,$year)) self::fail('Invalid report calendar date');
        return sprintf('%04d-%02d-%02d %02d:%02d:%02d',$year,$month,$day,$hour,$minute,$second);
    }

    private static function excelDate(string $value, bool $date1904): string
    {
        if (!preg_match('/^[0-9]{1,7}(?:\.[0-9]{1,15})?$/D',$value) || !is_finite((float)$value)) self::fail('Invalid Excel report date');
        $serial=(float)$value; $days=(int)floor($serial);
        if ((!$date1904 && ($days===60 || $days<1)) || $days>2958465) self::fail('Invalid Excel report calendar date');
        $seconds=(int)round(($serial-$days)*86400);
        $base=$date1904?'1904-01-01':($days<60?'1899-12-31':'1899-12-30');
        $date=(new DateTimeImmutable($base,new DateTimeZone('UTC')))->modify('+'.$days.' days')->modify('+'.$seconds.' seconds')->format('Y-m-d H:i:s');
        return self::reportedAt($date);
    }

    private static function dateStyles(ZipArchive $zip, array $entries): array
    {
        if (!isset($entries['xl/styles.xml'])) return [];
        $document=self::xml(self::entry($zip,$entries,'xl/styles.xml')); $xpath=self::xpath($document); $custom=[]; $result=[];
        foreach ($xpath->query('/m:styleSheet/m:numFmts/m:numFmt') as $format) {
            $code=$format->getAttribute('formatCode');
            $stripped=preg_replace('/"[^"]*"|\\\\.|\[[^\]]*\]/','',$code);
            // Recognize only a date AND time format, never a numeric amount that
            // happens to occupy the Date column. Built-ins 14-22 are date formats.
            if (preg_match('/[dy]/i',$stripped) && preg_match('/[hm]/i',$stripped)) $custom[(int)$format->getAttribute('numFmtId')]=true;
        }
        foreach ($xpath->query('/m:styleSheet/m:cellXfs/m:xf') as $index=>$style) {
            $id=(int)$style->getAttribute('numFmtId');
            if (($id>=14 && $id<=22) || isset($custom[$id])) $result[$index]=true;
        }
        return $result;
    }

    private static function text(DOMNode $node, DOMXPath $xpath): string
    {
        $value='';
        foreach ($xpath->query('m:t|m:r/m:t',$node) as $part) $value.=$part->textContent;
        return preg_replace_callback('/(?:_x[0-9A-Fa-f]{4}_)+/',static function(array $match):string {
            preg_match_all('/_x([0-9A-Fa-f]{4})_/',$match[0],$units);
            $encoded=pack('H*',implode('',$units[1]));
            if (!mb_check_encoding($encoded,'UTF-16BE')) self::fail('Invalid Office text escape');
            return mb_convert_encoding($encoded,'UTF-8','UTF-16BE');
        },$value);
    }

    private static function entry(ZipArchive $zip, array $entries, string $name): string
    {
        $entry=$entries[strtolower($name)]??null;
        if (!$entry || $entry['name']!==$name || $entry['size']>self::MAX_ENTRY) self::fail('Required XLSX part is missing or invalid');
        $value=$zip->getFromIndex($entry['index'],self::MAX_ENTRY+1);
        if ($value===false || strlen($value)!==$entry['size'] || strlen($value)>self::MAX_ENTRY) self::fail('Invalid XLSX part data');
        return $value;
    }

    private static function xml(string $value): DOMDocument
    {
        // Reject UTF-16/NUL-bearing markup rather than let byte-level DTD checks
        // be bypassed. The provider's XLSX XML uses ordinary UTF-8 text.
        if (str_contains($value,"\0") || preg_match('/<!DOCTYPE|<!ENTITY/i',$value)) self::fail('XML declarations or entities are not allowed');
        $previous=libxml_use_internal_errors(true); libxml_clear_errors();
        try {
            $document=new DOMDocument();
            $document->resolveExternals=false; $document->substituteEntities=false;
            if (!$document->loadXML($value,LIBXML_NONET|LIBXML_COMPACT)) self::fail('Invalid XLSX XML');
            if ($document->doctype!==null) self::fail('XML declarations or entities are not allowed');
            return $document;
        } finally { libxml_clear_errors(); libxml_use_internal_errors($previous); }
    }

    private static function xpath(DOMDocument $document): DOMXPath
    {
        $xpath=new DOMXPath($document); $xpath->registerNamespace('m',self::XMLNS); return $xpath;
    }

    private static function resolvePart(string $base, string $target): string
    {
        if ($target==='' || str_contains($target,'\\') || str_contains($target,"\0") || preg_match('~[?#]|^[A-Za-z][A-Za-z0-9+.-]*:|^//~',$target)) self::fail('Unsafe XLSX part relationship');
        $parts=[];
        foreach (explode('/',str_starts_with($target,'/')?substr($target,1):dirname($base).'/'.$target) as $part) {
            if ($part==='' || $part==='.') continue;
            if ($part==='..') { if (!$parts) self::fail('XLSX relationship escapes the package'); array_pop($parts); }
            else $parts[]=$part;
        }
        return implode('/',$parts);
    }

    private static function fail(string $message): never { throw new InvalidArgumentException($message); }
}
