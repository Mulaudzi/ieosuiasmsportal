<?php
declare(strict_types=1);
require __DIR__.'/../domain/SmsSchedule.php';
$checks=0;
function scheduleCheck(bool $ok,string $label):void{global $checks;$checks++;if(!$ok)throw new RuntimeException($label);}
$now=(new DateTimeImmutable('2026-11-05T08:00:00Z'))->getTimestamp();
foreach(['UTC','Africa/Johannesburg','America/New_York','Asia/Tokyo']as$zone){
    date_default_timezone_set($zone);
    foreach(['2026-11-05T11:00','2026-11-05 11:00:00','2026-11-05T11:00:00+02:00','2026-11-05T09:00:00.000Z']as$value){
        $r=SmsSchedule::fromInput(['scheduled_at'=>$value,'send_now'=>false],$now);
        scheduleCheck($r===['send_now'=>false,'scheduled_at'=>'2026-11-05 11:00:00','state'=>'scheduled'],'Explicit or legacy time is normalized to South Africa, independent of host timezone');
    }
    scheduleCheck(!SmsSchedule::isDue(['scheduled_at'=>'2026-11-05 11:00:00'],$now+3599),'Future date is not due one second early');
    scheduleCheck(SmsSchedule::isDue(['scheduled_at'=>'2026-11-05 11:00:00'],$now+3600),'Date becomes due at the exact instant');
}
scheduleCheck(SmsSchedule::fromInput(['send_now'=>true,'scheduled_at'=>null],$now)['state']==='queued','Immediate sends remain supported');
scheduleCheck(SmsSchedule::fromInput([],$now)['state']==='draft','API drafts remain supported');
scheduleCheck(SmsSchedule::fromInput(['scheduled_at'=>'2026-11-05T11:00:00-05:00'],$now)['scheduled_at']==='2026-11-05 18:00:00','Non-South-African offsets preserve the intended instant');
foreach([
    ['send_now'=>true,'scheduled_at'=>'2026-11-05T11:00:00+02:00'],
    ['send_now'=>'false'],['send_now'=>1],['scheduled_at'=>[]],
    ...array_map(fn($value)=>['scheduled_at'=>$value],['tomorrow','2026-02-30T11:00','2026-11-05T25:00','2026-11-05T11:60','2026-11-05T11:00:60Z','2026-11-05T11:00+15:00','2026-11-05T11:00+14:30','2026-11-05T11:00+02:99','2026-11-05T08:00:00Z','2026-11-05T07:59:59Z','2026-11-05','2026-11-05T11:00 garbage']),
]as$input){
    try{SmsSchedule::fromInput($input,$now);scheduleCheck(false,'Invalid or contradictory scheduling request accepted');}
    catch(InvalidArgumentException){scheduleCheck(true,'Malformed, elapsed and contradictory scheduling requests rejected');}
}
foreach(['garbage','2026-02-30 11:00:00',[],true]as$value)scheduleCheck(!SmsSchedule::isDue(['scheduled_at'=>$value],$now),'Malformed stored schedules fail closed');
scheduleCheck(SmsSchedule::isDue(['scheduled_at'=>null],$now),'Unscheduled existing campaigns stay eligible');
// Verify the real create entry point rejects conflicting inputs before preview,
// reservation or database writes. The only table read is an idempotency lookup.
class ScheduleEmptyQuery{public function where(...$args):self{return $this;}public function first():?array{return null;}}
function table(string $name):ScheduleEmptyQuery{if($name!=='sms_campaigns')throw new RuntimeException('Preview must not run');return new ScheduleEmptyQuery();}
function db():never{throw new RuntimeException('No database side effect is permitted');}
function env(string $key,mixed $default=null):mixed{return $default;}
require __DIR__.'/../domain/CampaignService.php';
try{CampaignService::create(1,['idempotency_key'=>'synthetic-schedule-conflict','send_now'=>true,'scheduled_at'=>'2099-01-01T11:00:00+02:00']);scheduleCheck(false,'Conflict entered creation');}
catch(InvalidArgumentException $e){scheduleCheck(str_contains($e->getMessage(),'not both'),'Real campaign creation rejects contradiction before recipient resolution or reservations');}
echo "PASS: $checks schedule validation, timezone, boundary and side-effect checks; no network or live database.\n";
