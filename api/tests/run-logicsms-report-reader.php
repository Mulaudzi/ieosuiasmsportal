<?php
declare(strict_types=1);

require_once __DIR__.'/../domain/LogicSmsReportReader.php';
$checks=0; $temporary=[];
function reportCheck(bool $condition,string $label):void { global $checks; if(!$condition)throw new RuntimeException($label);$checks++; }
function reportTemp():string { global $temporary;$path=tempnam(sys_get_temp_dir(),'ieosuia-report-test-');if($path===false)throw new RuntimeException('Cannot create fixture');$temporary[]=$path;return $path; }
register_shutdown_function(static function():void { global $temporary;foreach($temporary as $path)if(is_file($path)&&str_starts_with(basename($path),'ieosuia-report-test-'))unlink($path); });
function reportXml(string $value):string { return htmlspecialchars($value,ENT_XML1|ENT_QUOTES,'UTF-8'); }
function reportPackage(array $rows,array $overrides=[],array $extra=[],bool $shared=false):string {
    $ns='http://schemas.openxmlformats.org/spreadsheetml/2006/main';$strings=[];$sheet='<worksheet xmlns="'.$ns.'"><sheetData>';
    foreach($rows as $index=>$values){$number=$index+1;$sheet.='<row r="'.$number.'">';
        foreach($values as $column=>$value){$ref=chr(65+$column).$number;
            if(is_array($value)){$sheet.='<c r="'.$ref.'"'.(isset($value['style'])?' s="'.$value['style'].'"':'').'><v>'.reportXml($value['number']).'</v></c>';continue;}
            if($shared){$key=count($strings);$strings[]=$value;$sheet.='<c r="'.$ref.'" t="s"><v>'.$key.'</v></c>';}
            else $sheet.='<c r="'.$ref.'" t="inlineStr"><is><t xml:space="preserve">'.reportXml($value).'</t></is></c>';
        }$sheet.='</row>';
    }$sheet.='</sheetData></worksheet>';
    $files=[
        '[Content_Types].xml'=>'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="xml" ContentType="application/xml"/></Types>',
        'xl/workbook.xml'=>'<workbook xmlns="'.$ns.'" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="SMSSendReport" sheetId="1" r:id="rId1"/></sheets></workbook>',
        'xl/_rels/workbook.xml.rels'=>'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>',
        'xl/worksheets/sheet1.xml'=>$sheet,
        'xl/styles.xml'=>'<styleSheet xmlns="'.$ns.'"><cellXfs count="2"><xf numFmtId="0"/><xf numFmtId="22"/></cellXfs></styleSheet>',
    ];
    if($shared){$xml='<sst xmlns="'.$ns.'">';foreach($strings as $string)$xml.='<si><r><t xml:space="preserve">'.reportXml(mb_substr($string,0,3,'UTF-8')).'</t></r><r><t xml:space="preserve">'.reportXml(mb_substr($string,3,null,'UTF-8')).'</t></r><rPh><t>ignored phonetic guide</t></rPh></si>';$files['xl/sharedStrings.xml']=$xml.'</sst>';}
    foreach($overrides as $name=>$value){if($value===null)unset($files[$name]);else $files[$name]=$value;}
    $files=array_merge($files,$extra);$path=reportTemp();$zip=new ZipArchive();reportCheck($zip->open($path,ZipArchive::OVERWRITE)===true,'Synthetic ZIP opens');foreach($files as $name=>$value)$zip->addFromString($name,$value);$zip->close();return $path;
}
function reportReject(callable $operation,string $label):void {
    $caught=false;try{$operation();}catch(InvalidArgumentException $error){$caught=true;reportCheck(!str_contains($error->getMessage(),'private-test-message')&&!str_contains($error->getMessage(),'27821234567'),'Failure does not print row content or destination');}
    reportCheck($caught,$label);
}
$headers=['Date','Mobile','Message','Status','Credits','Group'];
$valid=['10/23/2026 9:04:03 PM','27821234567',"  private-test-message\nsecond line  ",'delivered','1.0000','test'];
$rows=[$headers,$valid,['2026-10-24 00:59:05','0638082493','Second message','working','0.2500','test']];
$path=reportPackage($rows);$before=hash_file('sha256',$path);$report=LogicSmsReportReader::readFile($path,'report.xlsx');
reportCheck($report['row_count']===2&&$report['file_sha256']===$before&&hash_file('sha256',$path)===$before,'File is fingerprinted and never modified');
reportCheck($report['rows'][0]['source_row']===2&&$report['rows'][0]['content']===$valid[2],'Message whitespace and source row are preserved exactly');
reportCheck($report['rows'][0]['destination']==='+27821234567'&&$report['rows'][1]['destination']==='+27638082493','Numbers use existing canonical PhoneNumber normalization');
reportCheck($report['rows'][0]['reported_at']==='2026-10-23 21:04:03'&&$report['rows'][1]['reported_at']==='2026-10-24 00:59:05','US and unzoned ISO report dates parse deterministically');
reportCheck($report['rows'][0]['status']==='DELIVERED'&&$report['rows'][0]['status_state']==='delivered'&&$report['rows'][1]['status']==='WORKING'&&$report['rows'][1]['status_state']==='unknown','Known statuses normalize; working is not invented acceptance');
reportCheck($report['rows'][0]['provider_credits']==='1.0000'&&$report['report_date_is_sent_or_delivered_timestamp']===false,'Provider decimal and unverified report-date semantics retained');
$rich=LogicSmsReportReader::readFile(reportPackage($rows,[],[],true),'report.XLSX');reportCheck($rich['rows']===$report['rows'],'Rich shared strings preserve exact text and ignore phonetic annotations');
$unicode=$valid;$unicode[2]="😀 é & < >\nline";$unicodeReport=LogicSmsReportReader::readFile(reportPackage([$headers,$unicode],[],[],true),'unicode.xlsx');reportCheck($unicodeReport['rows'][0]['content']===$unicode[2],'Unicode rich text and escaped XML characters survive exactly');
$office=$valid;$office[2]='line_x000A_break_x005F_x0041_';$officeReport=LogicSmsReportReader::readFile(reportPackage([$headers,$office]),'office.xlsx');reportCheck($officeReport['rows'][0]['content']==="line\nbreak_x0041_",'Office text escapes decode exactly once, including escaped literal underscore');
$office[2]='_xD83D__xDE00_';$officeEmoji=LogicSmsReportReader::readFile(reportPackage([$headers,$office]),'office-emoji.xlsx');reportCheck($officeEmoji['rows'][0]['content']==='😀','Valid Office surrogate pair decodes without loss');
$office[2]='_xD83D_';reportReject(fn()=>LogicSmsReportReader::readFile(reportPackage([$headers,$office]),'invalid-office.xlsx'),'Unpaired Office surrogate is rejected instead of silently changing message text');
foreach(['10/23/2026 12:04:03 AM'=>'2026-10-23 00:04:03','10/23/2026 12:04:03 PM'=>'2026-10-23 12:04:03','10/23/2026 09:04:03 PM'=>'2026-10-23 21:04:03'] as $input=>$expected){$row=$valid;$row[0]=$input;$dateReport=LogicSmsReportReader::readFile(reportPackage([$headers,$row]),'date.xlsx');reportCheck($dateReport['rows'][0]['reported_at']===$expected,'Noon, midnight and padded12-hour dates are unambiguous');}
$excel=$valid;$excel[0]=['number'=>'46318.5','style'=>1];$numeric=LogicSmsReportReader::readFile(reportPackage([$headers,$excel]),'report.xlsx');reportCheck($numeric['rows'][0]['reported_at']==='2026-10-23 12:00:00','Explicit Excel date style converts numeric serial without timezone claims');
$csv=reportTemp();$handle=fopen($csv,'wb');foreach($rows as $row)fputcsv($handle,$row,',','"','');fclose($handle);$parsedCsv=LogicSmsReportReader::readFile($csv,'report.csv');reportCheck($parsedCsv['rows']===$report['rows'],'UTF-8 CSV with multiline quoted message matches XLSX semantics');
foreach(['02/30/2026 9:04:03 PM','10/23/2026 13:04:03 PM','2026-13-23 21:04:03','2026-10-23 21:04:60','not a date'] as $bad){$row=$valid;$row[0]=$bad;reportReject(fn()=>LogicSmsReportReader::readFile(reportPackage([$headers,$row]),'report.xlsx'),'Invalid dates fail closed');}
foreach(['NaN','INF','1e300','-1','1.2.3','1,000'] as $bad){$row=$valid;$row[4]=$bad;reportReject(fn()=>LogicSmsReportReader::readFile(reportPackage([$headers,$row]),'report.xlsx'),'Invalid provider credits fail closed');}
$row=$valid;$row[1]='bad';reportReject(fn()=>LogicSmsReportReader::readFile(reportPackage([$headers,$row]),'report.xlsx'),'Invalid phone fails closed');
$row=$valid;$row[0]=['number'=>'46318.5'];reportReject(fn()=>LogicSmsReportReader::readFile(reportPackage([$headers,$row]),'report.xlsx'),'Unstyled numeric date is not guessed');
$row=$valid;$row[0]=['number'=>'60','style'=>1];reportReject(fn()=>LogicSmsReportReader::readFile(reportPackage([$headers,$row]),'report.xlsx'),'Fictitious Excel 1900 leap day is rejected');
reportReject(fn()=>LogicSmsReportReader::readFile(reportPackage([['Date','Mobile','Message','Status','Credits'],array_slice($valid,0,5)]),'report.xlsx'),'Missing required header fails closed');
reportReject(fn()=>LogicSmsReportReader::readFile(reportPackage([$headers],[],['../traversal.xml'=>'<x/>']),'report.xlsx'),'Archive traversal is rejected without extraction');
reportReject(fn()=>LogicSmsReportReader::readFile(reportPackage([$headers],[],['/absolute.xml'=>'<x/>']),'report.xlsx'),'Absolute archive path is rejected');
reportReject(fn()=>LogicSmsReportReader::readFile(reportPackage([$headers],[],['xl/vbaProject.bin'=>'payload']),'report.xlsx'),'Macro payload is rejected');
reportReject(fn()=>LogicSmsReportReader::readFile(reportPackage([$headers],[],['extra.xml'=>'<!DOCTYPE x [<!ENTITY e SYSTEM "file:///private-test-message">]><x>&e;</x>']),'report.xlsx'),'DTD and external entity declarations are rejected');
reportReject(fn()=>LogicSmsReportReader::readFile(reportPackage([$headers],[],['extra.xml'=>mb_convert_encoding('<?xml version="1.0" encoding="UTF-16"?><!DOCTYPE x [<!ENTITY e SYSTEM "file:///private-test-message">]><x>&e;</x>','UTF-16LE','UTF-8')]),'report.xlsx'),'NUL-bearing UTF16 cannot bypass the DTD/entity guard');
reportReject(fn()=>LogicSmsReportReader::readFile(reportPackage([$headers],[],['extra.xml'=>'<invalid>']),'report.xlsx'),'Bad XML anywhere in package fails closed');
reportReject(fn()=>LogicSmsReportReader::readFile(reportPackage([$headers],[],['xl/worksheets/extra.xml'=>'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData><row r="1"><c r="A1"><f>1+1</f><v>2</v></c></row></sheetData></worksheet>']),'report.xlsx'),'Formula on even an unselected worksheet is rejected');
reportReject(fn()=>LogicSmsReportReader::readFile(reportPackage([$headers],[],['extra.rels'=>'<Relationships><Relationship TargetMode="External" Target="https://example.invalid/private-test-message"/></Relationships>']),'report.xlsx'),'External relationships are rejected');
reportReject(fn()=>LogicSmsReportReader::readFile(reportPackage([$headers],[],['extra.rels'=>'<Relationships><Relationship Target=" https://example.invalid/private-test-message"/></Relationships>']),'report.xlsx'),'Whitespace-prefixed external relationship cannot bypass URL checks');
reportReject(fn()=>LogicSmsReportReader::readFile(reportPackage([$headers],[],['large.txt'=>str_repeat('A',5242881)]),'report.xlsx'),'Compressed oversized entry is rejected before decompression');
$extras=[];for($i=0;$i<5;$i++)$extras['large'.$i.'.txt']=str_repeat('A',4500000);reportReject(fn()=>LogicSmsReportReader::readFile(reportPackage([$headers],[],$extras),'report.xlsx'),'Aggregate expanded archive bound prevents zip bombs');
reportReject(fn()=>LogicSmsReportReader::readFile(reportPackage([$headers,$valid],['xl/worksheets/sheet1.xml'=>'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData><row r="10001"><c r="A10001" t="inlineStr"><is><t>Date</t></is></c></row></sheetData></worksheet>']),'report.xlsx'),'Worksheet sparse row outside bound is rejected');
reportReject(fn()=>LogicSmsReportReader::readFile(reportPackage([array_merge($headers,array_fill(0,15,'extra')),$valid]),'report.xlsx'),'Column outside20 bound is rejected');
reportReject(fn()=>LogicSmsReportReader::readFile($path,'report.xlsm'),'Unsupported file extension rejected');
$oversize=reportTemp();file_put_contents($oversize,str_repeat('A',5242881));reportReject(fn()=>LogicSmsReportReader::readFile($oversize,'large.xlsx'),'Compressed upload above5MB is rejected');
$owner='C:/Users/venda/Downloads/SMSSendReport_20261024.xlsx';
if(is_file($owner)){
    $expected='59b298114f2f451925b30219c57cac01b7c26804ea2d2ea5405dc3d463ae3665';
    reportCheck(filesize($owner)===43913&&hash_file('sha256',$owner)===$expected,'Owner report identity verified before reading');
    $ownerReport=LogicSmsReportReader::readFile($owner,'SMSSendReport_20261024.xlsx');
    reportCheck($ownerReport['file_sha256']===$expected&&hash_file('sha256',$owner)===$expected,'Owner original is unchanged');
    $targets=array_values(array_filter($ownerReport['rows'],static fn($row)=>in_array($row['content'],['Test Message from IEOSUIA SMS','Test 2 from IEOSUIA SMS'],true)||str_starts_with($row['content'],'Hello! This is a reminder to come join us for our Special Sunday Service @NWU Potch Campus')));
    $counts=[];foreach($targets as $row)$counts[$row['status']]=($counts[$row['status']]??0)+1;ksort($counts);
    reportCheck(count($targets)===14,'Fourteen target test/SUPER SUNDAY rows identified without exposing other recipients');
    reportCheck($counts===['DELIVERED'=>7,'INVALID'=>2,'SENT'=>4,'WORKING'=>1],'Owner target statuses retained exactly; neither invalid nor working becomes sent');
    reportCheck($ownerReport['row_count']===1390,'All1390 data rows are retained, excluding only the header');
    echo 'Owner report: '.json_encode(['data_rows'=>$ownerReport['row_count'],'target_rows'=>count($targets),'target_status_counts'=>$counts,'original_unchanged'=>true],JSON_UNESCAPED_SLASHES).PHP_EOL;
}
echo 'PASS '.$checks." local report-reader checks; no database or network access.\n";
