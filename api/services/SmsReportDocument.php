<?php
require_once __DIR__.'/../domain/SmsReportingService.php';

/** Shared read-only report snapshot for portal downloads and email attachments. */
final class SmsReportDocument
{
    public const HEADERS=['Campaign','Number','Status','Segments','Sent','Details'];
    public const COLORS=['Delivered'=>'#2E9B5B','Delivery Confirmation Pending'=>'#2F6FED','Delivery Status Unavailable'=>'#5E7394','Not Delivered'=>'#C45C5C','Skipped'=>'#8B93A1'];

    public static function filter(string $state,string $search,array &$args,string $alias='m'):string
    {
        $sql='';
        if($state==='all')$state='';
        if($state==='dlr_unavailable')$sql=" AND $alias.state='sent' AND $alias.last_error LIKE 'DLR lookup:%'";
        elseif($state==='sent')$sql=" AND $alias.state='sent' AND ($alias.last_error IS NULL OR $alias.last_error NOT LIKE 'DLR lookup:%')";
        elseif($state!==''){
            if(!in_array($state,['pending','queued','processing','retry','delivered','failed','skipped'],true))throw new InvalidArgumentException('Invalid delivery filter');
            $sql=" AND $alias.state=?";$args[]=$state;
        }
        if($search!==''){
            $sql.=" AND ($alias.destination LIKE ? OR EXISTS(SELECT 1 FROM contacts contact WHERE contact.user_id=$alias.user_id AND contact.phone_normalized=$alias.destination AND (contact.name LIKE ? OR contact.surname LIKE ?)))";
            $term='%'.str_replace(['!','%','_'],['!!','!%','!_'],$search).'%';
            $sql=str_replace('LIKE ?','LIKE ? ESCAPE \'!\'',$sql);array_push($args,$term,$term,$term);
        }
        return $sql;
    }

    public static function load(int $userId,?int $campaignId=null,string $range='7d',string $state='',string $search=''):array
    {
        $args=[$userId];$where='m.user_id=?';$name='All campaigns';
        $period=SmsReportingService::period($range);
        if($campaignId!==null){
            $q=db()->prepare('SELECT id,name,created_at,completed_at FROM sms_campaigns WHERE id=? AND user_id=?');$q->execute([$campaignId,$userId]);$campaign=$q->fetch();
            if(!$campaign)throw new RuntimeException('Campaign not found');
            $name=$campaign['name'];$where.=' AND m.campaign_id=?';$args[]=$campaignId;
            $period=['start'=>$campaign['created_at'],'end'=>$campaign['completed_at']?:date('Y-m-d H:i:s')];
        }else{$where.=' AND m.created_at>=? AND m.created_at<=?';array_push($args,$period['start'],$period['end']);}
        $where.=self::filter($state,$search,$args);
        $q=db()->prepare("SELECT c.name campaign,m.destination,m.state,m.segment_count,m.sent_at,m.last_error FROM sms_messages m JOIN sms_campaigns c ON c.id=m.campaign_id AND c.user_id=m.user_id WHERE $where ORDER BY m.id ASC");$q->execute($args);
        return self::snapshot($name,$period,$q->fetchAll(),$campaignId,$range);
    }

    public static function snapshot(string $name,array $period,array $messages,?int $campaignId=null,string $range='7d'):array
    {
        $counts=array_fill_keys(array_keys(self::COLORS),0);$rows=[];
        foreach($messages as $m){
            $label=SmsDeliveryPresentation::label($m['state'],$m['last_error']??null);
            if(isset($counts[$label]))$counts[$label]++;
            $rows[]=[$m['campaign']??$name,$m['destination'],$label,(int)$m['segment_count'],$m['sent_at']??'',SmsDeliveryPresentation::details($m['state'],$m['last_error']??null)];
        }
        return ['name'=>$name,'period'=>$period,'rows'=>$rows,'counts'=>$counts,'total'=>count($rows),'campaign_id'=>$campaignId,'range'=>$range];
    }

    public static function filename(array $r):string
    {return 'IEOSUIA-'.(trim(preg_replace('/[^A-Za-z0-9_-]+/','-',$r['name']),'-')?:'campaign').'-'.date('Y-m-d');}
    private static function e(mixed $v):string{return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
    private static function safe(mixed $v):mixed{return is_string($v)&&preg_match('/^[\s]*[=+@\-]/u',$v)?"'".$v:$v;}
    public static function csv(array $r):string
    {
        $f=fopen('php://temp','w+');fputcsv($f,self::HEADERS,escape:'');
        foreach($r['rows'] as $row)fputcsv($f,array_map([self::class,'safe'],$row),escape:'');rewind($f);$data=stream_get_contents($f);fclose($f);return $data;
    }
    public static function excel(array $r):string
    {
        $html='<html><head><meta charset="UTF-8"></head><body><table><tr>';
        foreach(self::HEADERS as $h)$html.='<th>'.self::e($h).'</th>';
        $html.='</tr>';foreach($r['rows'] as $row){$html.='<tr>';foreach($row as $v)$html.='<td style="mso-number-format:\'\\@\'">'.self::e(self::safe($v)).'</td>';$html.='</tr>';}
        return $html.'</table></body></html>';
    }
    public static function period(array $r):string{return date('j M Y',strtotime($r['period']['start'])).' to '.date('j M Y',strtotime($r['period']['end']));}
    public static function note(array $r):string
    {
        $largest=array_search(max($r['counts']),$r['counts'],true);
        if(max($r['counts'])===0)return 'No delivery outcomes are available yet.';
        if($largest==='Delivery Status Unavailable')return 'These messages were sent. The network did not return a delivery status. This is not a confirmed failure.';
        $states=['Delivered'=>'delivered','Delivery Confirmation Pending'=>'sent','Not Delivered'=>'failed','Skipped'=>'skipped'];
        return SmsDeliveryPresentation::details($states[$largest],null);
    }
    public static function mask(string $number):string
    {$digits=preg_replace('/\D/','',$number);return strlen($digits)>6?'+'.substr($digits,0,2).' '.substr($digits,2,2).' *** '.substr($digits,-4):'***';}

    public static function email(array $r):string
    {
        $base=rtrim((string)env('FRONTEND_URL','https://sms.ieosuia.com'),'/');
        $url=$base.($r['campaign_id']?'/sms-campaigns/'.$r['campaign_id']:'/reports?range='.rawurlencode($r['range']));
        $separator=str_contains($url,'?')?'&':'?';$tiles=['Total'=>$r['total']]+$r['counts'];
        $html='<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"><style>@media(max-width:600px){.tile{display:block!important;width:100%!important;box-sizing:border-box}.pad{padding:16px!important}}</style></head><body style="margin:0;background:#f1f3f6;font-family:Arial,sans-serif;color:#0B1628"><table role="presentation" width="100%"><tr><td align="center"><table role="presentation" width="600" style="width:100%;max-width:600px;background:white;border-collapse:collapse"><tr><td style="background:#0B1628;padding:24px;text-align:center"><img src="'.self::e($base.'/branding/ieosuia-sms-portal-navy.png').'" width="180" alt="IEOSUIA SMS Portal" style="width:180px;max-width:100%;height:auto"></td></tr><tr><td class="pad" style="padding:24px"><h1 style="margin:0 0 12px;font-size:26px">SMS delivery report</h1><p>Period: '.self::e(self::period($r)).'</p><p>Campaign name: '.self::e($r['name']).'</p><h2 style="font-size:16px">Summary</h2><table role="presentation" width="100%" style="table-layout:fixed">';
        $i=0;foreach($tiles as $label=>$count){if($i%3===0)$html.='<tr>';$html.='<td class="tile" width="33%" style="padding:12px 8px;vertical-align:top;background:#f7f8fa"><div style="font-size:28px;font-weight:bold;color:'.(self::COLORS[$label]??'#0B1628').'">'.$count.'</div><div style="font-size:13px">'.self::e($label).'</div></td>';if(++$i%3===0)$html.='</tr>';}
        $html.='</table>'.(array_sum($r['counts'])>0?'<p style="padding:12px;background:#eef3f9">'.self::e(self::note($r)).'</p>':'').'<table role="presentation" width="100%" cellspacing="0" cellpadding="0"><tr>';
        $sum=array_sum($r['counts']);foreach($r['counts'] as $label=>$count)if($count)$html.='<td height="8" width="'.(100*$count/max(1,$sum)).'%" style="background:'.self::COLORS[$label].';font-size:0;line-height:0">&nbsp;</td>';
        $html.='</tr></table>';foreach($r['counts'] as $label=>$count)if($count)$html.='<p style="font-size:13px;margin:8px 0"><span style="color:'.self::COLORS[$label].'">&#9679;</span> '.self::e($label).' '.$count.'</p>';
        $html.='<h2 style="font-size:16px">'.($r['total']<=5?'All recipients':'Showing 5 of '.$r['total']).'</h2><table width="100%" cellpadding="6" style="border-collapse:collapse;font-size:12px;text-align:left"><tr><th>Number</th><th>Status</th><th>Sent</th></tr>';
        foreach(array_slice($r['rows'],0,5) as $row)$html.='<tr><td style="border-top:1px solid #ddd">'.self::e(self::mask($row[1])).'</td><td style="border-top:1px solid #ddd">'.self::e($row[2]).'</td><td style="border-top:1px solid #ddd">'.self::e($row[4]).'</td></tr>';
        return $html.'</table><p style="text-align:center;margin:24px 0"><a style="display:inline-block;background:#2F6FED;color:white;padding:12px 24px;text-decoration:none;border-radius:6px" href="'.self::e($url).'">View full report</a></p><p style="text-align:center"><a href="'.self::e($url.$separator.'download=pdf').'">Download PDF</a> &nbsp; <a href="'.self::e($url.$separator.'download=csv').'">Download CSV</a></p><p style="font-size:12px">Both files contain the full report and use the same labels.</p><p style="text-align:center;color:#5E7394">sms.ieosuia.com</p></td></tr></table></td></tr></table></body></html>';
    }

    public static function download(array $r,string $format):never
    {
        $type=match($format){'csv'=>'text/csv; charset=UTF-8','pdf'=>'application/pdf','excel','xls'=>'application/vnd.ms-excel; charset=UTF-8',default=>throw new InvalidArgumentException('Unsupported export format')};
        $bytes=match($format){'csv'=>self::csv($r),'pdf'=>self::pdf($r),default=>self::excel($r)};
        header('Cache-Control: no-store');header('Access-Control-Expose-Headers: Content-Disposition');header('Content-Type: '.$type);header('Content-Disposition: attachment; filename="'.self::filename($r).'.'.(in_array($format,['excel','xls'],true)?'xls':$format).'"');echo $bytes;exit;
    }
    public static function send(array $r,string $email):array
    {
        require_once __DIR__.'/EmailService.php';
        return EmailService::send($email,'IEOSUIA SMS report – '.$r['name'],self::email($r),null,null,[
            ['name'=>self::filename($r).'.pdf','type'=>'application/pdf','data'=>self::pdf($r)],
            ['name'=>self::filename($r).'.csv','type'=>'text/csv','data'=>self::csv($r)],
        ]);
    }

    public static function pdf(array $r):string
    {
        require_once __DIR__.'/SmsReportPdf.php';return SmsReportPdf::render($r);
    }
}
