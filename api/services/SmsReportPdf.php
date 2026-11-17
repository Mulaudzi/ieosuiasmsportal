<?php
/** Small paginated PDF renderer. No external renderer or remote resource fetches. */
final class SmsReportPdf
{
    public static function render(array $r):string
    {
        $pages=[];$stream='';$y=790;
        $text=static function(float $x,float $y,string $text,int $size=9,string $color='#0B1628')use(&$stream):void{
            $encoded=iconv('UTF-8','Windows-1252//TRANSLIT',$text);if($encoded===false)$encoded=$text;
            $safe=str_replace(['\\','(',')',"\r","\n"],['\\\\','\\(','\\)',' ',' '],$encoded);
            $rgb=sscanf($color,'#%02x%02x%02x');$stream.=sprintf("%.3f %.3f %.3f rg BT /F1 %d Tf %.2f %.2f Td (%s) Tj ET\n",$rgb[0]/255,$rgb[1]/255,$rgb[2]/255,$size,$x,$y,$safe);
        };
        $rect=static function(float $x,float $y,float $w,float $h,string $color)use(&$stream):void{$rgb=sscanf($color,'#%02x%02x%02x');$stream.=sprintf("%.3f %.3f %.3f rg %.2f %.2f %.2f %.2f re f\n",$rgb[0]/255,$rgb[1]/255,$rgb[2]/255,$x,$y,$w,$h);};
        $wrap=static fn(string $s,int $chars):array=>explode("\n",wordwrap(str_replace(["\r","\n"],' ',$s),$chars,"\n",true));
        $line=static function(string $s,int $size=10)use(&$y,$text,$wrap):void{foreach($wrap($s,$size===22?42:90) as $part){$text(30,$y,$part,$size);$y-=$size+5;}};
        $line('SMS delivery report',22);$line('Period: '.SmsReportDocument::period($r));$line('Campaign name: '.$r['name']);$y-=10;$line('Summary',13);
        $tiles=['Total'=>$r['total']]+$r['counts'];$i=0;$top=$y;
        foreach($tiles as $label=>$count){$x=30+($i%3)*180;$ty=$top-intdiv($i,3)*68;$color=SmsReportDocument::COLORS[$label]??'#0B1628';$rect($x,$ty-55,170,62,'#F1F3F6');$text($x+8,$ty-14,(string)$count,22,$color);foreach($wrap($label,27) as $j=>$part)$text($x+8,$ty-32-$j*11,$part,9,$color);$i++;}
        $y=$top-(int)ceil(count($tiles)/3)*68-9;if(array_sum($r['counts'])>0)$line(SmsReportDocument::note($r));$y-=10;$x=30;$sum=array_sum($r['counts']);
        foreach($r['counts'] as $label=>$count)if($count){$width=535*$count/max(1,$sum);$rect($x,$y,$width,8,SmsReportDocument::COLORS[$label]);$x+=$width;}$y-=20;
        foreach($r['counts'] as $label=>$count)if($count){$text(30,$y,$label.': '.$count,9,SmsReportDocument::COLORS[$label]);$y-=14;}$y-=12;
        $header=static function()use(&$y,$text):void{foreach([[30,'Number'],[135,'Status'],[270,'Sent'],[370,'Details']] as [$x,$label])$text($x,$y,$label,10);$y-=20;};$header();
        foreach($r['rows'] as $row){
            $columns=[$wrap($row[1],18),$wrap($row[2],24),$wrap($row[4],18),$wrap($row[5],35)];$height=max(array_map('count',$columns))*12+12;
            if($y-$height<45){$pages[]=$stream;$stream='';$y=790;$text(30,$y,'SMS delivery report (continued)',14);$y-=30;$header();}
            foreach($columns as $i=>$parts)foreach($parts as $j=>$part)$text([30,135,270,370][$i],$y-$j*12,$part,9);
            $y-=$height;
        }
        if(!$r['rows'])$text(30,$y,'No recipients match this report.');$pages[]=$stream;
        $objects=[];$ids=[];$fontId=3+count($pages)*2;
        foreach($pages as $i=>$commands){$pid=3+$i*2;$sid=$pid+1;$ids[]=$pid;$commands.="BT /F1 9 Tf 30 25 Td (Page ".($i+1).' of '.count($pages).") Tj ET\n";$objects[$pid]="<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 $fontId 0 R >> >> /Contents $sid 0 R >>";$objects[$sid]='<< /Length '.strlen($commands)." >>\nstream\n$commands\nendstream";}
        $objects[1]='<< /Type /Catalog /Pages 2 0 R >>';$objects[2]='<< /Type /Pages /Kids ['.implode(' ',array_map(static fn($id)=>"$id 0 R",$ids)).'] /Count '.count($ids).' >>';$objects[$fontId]='<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';ksort($objects);$pdf="%PDF-1.4\n";$offsets=[0];foreach($objects as $id=>$body){$offsets[$id]=strlen($pdf);$pdf.="$id 0 obj\n$body\nendobj\n";}$xref=strlen($pdf);$pdf.='xref'."\n0 ".($fontId+1)."\n0000000000 65535 f \n";for($id=1;$id<=$fontId;$id++)$pdf.=sprintf('%010d 00000 n ',$offsets[$id])."\n";return $pdf.'trailer'."\n<< /Size ".($fontId+1)." /Root 1 0 R >>\nstartxref\n$xref\n%%EOF";
    }
}
