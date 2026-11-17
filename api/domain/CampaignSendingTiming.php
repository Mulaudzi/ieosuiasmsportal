<?php
require_once __DIR__.'/SmsMessageState.php';

/** Read-only acceptance timing. Delivery and administrative completion are not send clocks. */
final class CampaignSendingTiming
{
    public static function attach(PDO $pdo,array $campaigns):array
    {
        if(!$campaigns)return [];
        $ids=array_values(array_unique(array_map(static fn($c)=>(int)$c['id'],$campaigns)));
        $marks=implode(',',array_fill(0,count($ids),'?'));
        $accepted=SmsMessageState::acceptedSql('m');
        $sql="SELECT m.campaign_id,COUNT(*) message_rows,
            SUM(CASE WHEN $accepted THEN 1 ELSE 0 END) accepted_count,
            SUM(CASE WHEN $accepted AND m.sent_at IS NOT NULL THEN 1 ELSE 0 END) timestamp_count,
            MIN(CASE WHEN $accepted THEN m.sent_at END) first_accepted_at,
            MAX(CASE WHEN $accepted THEN m.sent_at END) last_accepted_at,
            SUM(CASE WHEN NOT $accepted AND m.state IN ('pending','queued','retry','processing') THEN 1 ELSE 0 END) outstanding_count,
            SUM(CASE WHEN NOT $accepted AND m.next_attempt_at='9999-12-31 23:59:59' THEN 1 ELSE 0 END) held_count,
            SUM(CASE WHEN m.state='skipped' THEN 1 ELSE 0 END) skipped_count,
            MAX(CASE WHEN $accepted THEN m.attempt_count ELSE 0 END) max_accepted_attempts
            FROM sms_messages m JOIN sms_campaigns c ON c.id=m.campaign_id AND c.user_id=m.user_id
            WHERE m.campaign_id IN ($marks) GROUP BY m.campaign_id";
        $q=$pdo->prepare($sql);$q->execute($ids);$summaries=[];
        foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row)$summaries[(int)$row['campaign_id']]=$row;
        // Only consult original acknowledgements when accepted timestamps are missing.
        $fallback=[];
        foreach($summaries as $id=>$s)if((int)$s['accepted_count']>0&&(int)$s['timestamp_count']<(int)$s['accepted_count']&&(int)$s['max_accepted_attempts']===1)$fallback[]=$id;
        $acks=[];
        if($fallback){
            $ackMarks=implode(',',array_fill(0,count($fallback),'?'));
            $q=$pdo->prepare("SELECT a.campaign_id,COUNT(*) ack_count,MIN(a.first_ack) first_ack,MAX(a.first_ack) last_ack FROM (
                SELECT m.campaign_id,m.id,MIN(l.created_at) first_ack FROM sms_messages m
                JOIN sms_campaigns c ON c.id=m.campaign_id AND c.user_id=m.user_id
                JOIN operational_logs l ON l.sms_message_id=m.id AND l.campaign_id=m.campaign_id AND l.user_id=m.user_id
                WHERE m.campaign_id IN ($ackMarks) AND $accepted AND l.service='sms-provider' AND l.event='acknowledgement'
                GROUP BY m.campaign_id,m.id) a GROUP BY a.campaign_id");
            $q->execute($fallback);foreach($q->fetchAll(PDO::FETCH_ASSOC) as $a)$acks[(int)$a['campaign_id']]=$a;
        }
        $now=(string)$pdo->query('SELECT CURRENT_TIMESTAMP')->fetchColumn();
        return array_map(static function($c)use($summaries,$acks,$now){$c['sending_timing']=self::calculate($c,$summaries[(int)$c['id']]??[], $now,$acks[(int)$c['id']]??[]);return $c;},$campaigns);
    }

    private static function timestamp(?string $value):?int
    {
        if(!$value)return null;
        $date=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$value,new DateTimeZone('Africa/Johannesburg'));
        return $date&&$date->format('Y-m-d H:i:s')===$value?$date->getTimestamp():null;
    }
    public static function calculate(array $c,array $s,string $observedAt,array $ack=[]):array
    {
        $accepted=(int)($s['accepted_count']??0);$rows=(int)($s['message_rows']??0);
        $outstanding=(int)($s['outstanding_count']??0);$held=(int)($s['held_count']??0);
        $queued=self::timestamp($c['queued_at']??null);$start=self::timestamp($c['started_at']??null);$now=self::timestamp($observedAt);
        $source=null;$first=null;$last=null;
        if($accepted>0&&(int)($s['timestamp_count']??0)===$accepted){$first=$s['first_accepted_at']??null;$last=$s['last_accepted_at']??null;$source='accepted_timestamps';}
        elseif($accepted>0&&(int)($s['max_accepted_attempts']??0)===1&&(int)($ack['ack_count']??0)===$accepted){$first=$ack['first_ack']??null;$last=$ack['last_ack']??null;$source='original_acknowledgements';}
        $firstTime=self::timestamp($first);$end=self::timestamp($last);
        $valid=$source&&$start!==null&&$end!==null&&$firstTime!==null&&$firstTime>=$start&&$end>=$firstTime&&$now!==null&&$end<=$now&&($queued===null||$queued<=$start);
        $seconds=$valid?$end-$start:null;
        $state=(string)($c['state']??'');
        $active=in_array($state,['queued','processing'],true);
        $status=match(true){
            $held>0=>'held',
            $state==='draft'||$state==='scheduled'=>'not_started',
            $active&&$start===null=>'waiting',
            $active||$outstanding>0&&$state!=='cancelled'=>'in_progress',
            $state==='cancelled'=>'cancelled',
            $seconds!==null&&$rows===(int)($c['recipient_count']??0)=>'complete',
            default=>'unavailable',
        };
        if(in_array($status,['not_started','waiting'],true))$seconds=null;
        return ['status'=>$status,'source'=>$seconds!==null?$source:null,'queue_wait_seconds'=>$queued!==null&&$start!==null&&$start>=$queued?$start-$queued:null,
            'sending_seconds'=>$seconds,'total_seconds'=>$seconds!==null&&$queued!==null?$end-$queued:null,
            'elapsed_seconds'=>in_array($status,['in_progress','held'],true)&&$start!==null&&$now!==null&&$now>=$start?$now-$start:null,
            'accepted_per_minute'=>$seconds!==null&&$seconds>0?round($accepted*60/$seconds,2):null,
            'accepted_count'=>$accepted,'outstanding_count'=>$outstanding,'held_count'=>$held,'skipped_count'=>(int)($s['skipped_count']??0),
            'first_accepted_at'=>$valid?$first:null,'last_accepted_at'=>$valid?$last:null,'observed_at'=>$observedAt,'timezone'=>'Africa/Johannesburg'];
    }
    public static function summary(array $campaigns):array
    {
        $timed=array_values(array_filter($campaigns,static fn($c)=>($c['sending_timing']['status']??'')==='complete'&&in_array($c['state'],['completed','partially_failed'],true)));
        $seconds=array_column(array_column($timed,'sending_timing'),'sending_seconds');
        return ['sample_size'=>count($timed),'recent_campaign_count'=>count($campaigns),'average_sending_seconds'=>$seconds?array_sum($seconds)/count($seconds):null,
            'fastest_seconds'=>$seconds?min($seconds):null,'slowest_seconds'=>$seconds?max($seconds):null];
    }
}
