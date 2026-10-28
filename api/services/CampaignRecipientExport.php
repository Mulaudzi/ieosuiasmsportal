<?php
final class CampaignRecipientExport
{
    public const HEADERS=['Contact','Number','Status','Segments','Sent','Result'];
    private static function values(array $row):array { return array_map(static fn($key)=>$row[$key]??'', ['contact','number','status','segments','sent','result']); }
    public static function filename(array $r): string { return (strtolower(trim(preg_replace('/[^A-Za-z0-9]+/','-',$r['name']),'-'))?:'campaign').'-'.strtolower(str_replace(' ','-',$r['choice'])); }
    public static function csv(array $r): string {
        $f=fopen('php://temp','w+');fputcsv($f,self::HEADERS,escape:'');
        foreach($r['rows'] as $row){$values=self::values($row);$values=array_map(static fn($v)=>is_string($v)&&preg_match('/^[\s]*[=+@\-]/u',$v)?"'".$v:$v,$values);fputcsv($f,$values,escape:'');}
        rewind($f);$bytes=stream_get_contents($f);fclose($f);return $bytes;
    }
    public static function pdf(array $r): string {
        $pages=[];$stream='';$y=750;
        $text=static function($x,$y,$value,$size=9)use(&$stream){$s=iconv('UTF-8','Windows-1252//TRANSLIT',(string)$value);$s=str_replace(['\\','(',')',"\r","\n"],['\\\\','\\(','\\)',' ',' '],$s?:'');$stream.="BT /F1 $size Tf $x $y Td ($s) Tj ET\n";};
        $wrap=static fn($s,$n)=>explode("\n",wordwrap(str_replace(["\r","\n"],' ',(string)$s),$n,"\n",true));
        $header=static function()use(&$y,$text,$wrap,$r){$y=800;foreach($wrap($r['name'],65) as $part){$text(30,$y,$part,14);$y-=18;}$text(30,$y,$r['choice'].' | '.$r['total'].' recipients',10);$y-=25;foreach(CampaignRecipientExport::HEADERS as $i=>$label)$text([30,135,235,345,390,480][$i],$y,$label);$y-=20;};
        $header();
        foreach($r['rows'] as $row){$columns=[];foreach(self::values($row) as $i=>$value)$columns[]=$wrap($value,[20,18,21,7,16,19][$i]);$height=max(array_map('count',$columns))*12+12;if($y-$height<45){$pages[]=$stream;$stream='';$header();}foreach($columns as $i=>$parts)foreach($parts as $j=>$part)$text([30,135,235,345,390,480][$i],$y-$j*12,$part);$y-=$height;}
        $pages[]=$stream;$objects=[];$ids=[];$font=3+count($pages)*2;
        foreach($pages as $i=>$commands){$pid=3+$i*2;$sid=$pid+1;$ids[]=$pid;$commands.='BT /F1 9 Tf 30 25 Td (Page '.($i+1).' of '.count($pages).") Tj ET\n";$objects[$pid]="<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 $font 0 R >> >> /Contents $sid 0 R >>";$objects[$sid]='<< /Length '.strlen($commands)." >>\nstream\n$commands\nendstream";}
        $objects[1]='<< /Type /Catalog /Pages 2 0 R >>';$objects[2]='<< /Type /Pages /Kids ['.implode(' ',array_map(fn($id)=>"$id 0 R",$ids)).'] /Count '.count($ids).' >>';$objects[$font]='<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';ksort($objects);$pdf="%PDF-1.4\n";$offsets=[0];foreach($objects as $id=>$body){$offsets[$id]=strlen($pdf);$pdf.="$id 0 obj\n$body\nendobj\n";}$xref=strlen($pdf);$pdf.="xref\n0 ".($font+1)."\n0000000000 65535 f \n";for($id=1;$id<=$font;$id++)$pdf.=sprintf('%010d 00000 n ',$offsets[$id])."\n";return $pdf."trailer\n<< /Size ".($font+1)." /Root 1 0 R >>\nstartxref\n$xref\n%%EOF";
    }
    public static function download(array $r,string $format): never {
        if(!in_array($format,['csv','pdf'],true))throw new InvalidArgumentException('Invalid export format');
        header('Cache-Control: no-store');header('Access-Control-Expose-Headers: Content-Disposition');header('Content-Type: '.($format==='pdf'?'application/pdf':'text/csv; charset=UTF-8'));header('Content-Disposition: attachment; filename="'.self::filename($r).'.'.$format.'"');echo $format==='pdf'?self::pdf($r):self::csv($r);exit;
    }
}
