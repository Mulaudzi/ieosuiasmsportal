<?php

require_once __DIR__.'/SmsDispatchService.php';
require_once __DIR__.'/../providers/ConcurrentSmsProvider.php';

/** One lock-owning process; concurrent HTTP, serialized database settlement. */
final class SmsWorkerRunner
{
    private float $nextStartAt=0.0;
    private bool $failed=false;
    private float $lastHeartbeat=0.0;

    public function __construct(
        private SmsProvider $provider,
        private int $concurrency=5,
        private int $rate=10,
        private ?float $startBefore=null,
    ) {
        $this->concurrency=self::boundedConcurrency($concurrency);
        $this->rate=max(1,$rate);
    }

    public static function boundedConcurrency(int $value):int { return max(1,min(10,$value)); }

    public static function startDeadline(bool $once,float $runUntil,int $timeout):?float
    {
        return $once?$runUntil-max(5,min(20,$timeout))-2:null;
    }

    public function shouldStop():bool
    {
        return $this->failed||($this->startBefore!==null&&microtime(true)>=$this->startBefore);
    }

    /** Unstarted claims stay untouched; the caller releases the batch lease. */
    public function run(array $messages,?callable $heartbeat=null):array
    {
        $stats=['processed'=>0,'failed'=>0,'skipped'=>0,'deferred_before_start'=>0,'requests_started'=>0,'peak_in_flight'=>0,'unstarted'=>count($messages),'deadline_reached'=>false];
        if(!$messages||$this->shouldStop()){
            $stats['deadline_reached']=$this->startBefore!==null&&microtime(true)>=$this->startBefore;
            return $stats;
        }
        if(!$this->provider instanceof ConcurrentSmsProvider)return $this->sequential($messages,$stats,$heartbeat);

        $multi=curl_multi_init();$active=[];$index=0;$total=count($messages);
        try{
            while(true){
                $this->heartbeat($heartbeat,count($active));
                do{$status=curl_multi_exec($multi,$running);}while($status===CURLM_CALL_MULTI_PERFORM);
                if($status!==CURLM_OK)throw new RuntimeException('SMS concurrent transport failed');

                // Drain every completed response before accepting more work. A
                // settlement failure stops starts but does not discard peers' ACKs.
                while($info=curl_multi_info_read($multi)){
                    $handle=$info['handle'];$key=spl_object_id($handle);
                    if(!isset($active[$key]))continue;
                    $entry=$active[$key];unset($active[$key]);
                    $http=(int)curl_getinfo($handle,CURLINFO_RESPONSE_CODE);
                    curl_multi_remove_handle($multi,$handle);curl_close($handle);
                    try{
                        $result=$this->provider->parseSubmissionResponse($entry['buffer']->body,(int)$info['result'],$http);
                        SmsDispatchService::completeSubmission($entry['message'],$this->provider,$result);
                        $stats['processed']++;
                    }catch(Throwable $e){$this->failure($entry['message'],$e,$stats);}
                }

                if($index<$total&&count($active)<$this->concurrency&&!$this->shouldStop()&&microtime(true)>=$this->nextStartAt){
                    $message=$messages[$index++];$handle=null;
                    try{
                        $prepared=SmsDispatchService::prepareSubmission($message);
                        if($prepared===null){$stats['skipped']++;continue;}
                        $message=$prepared;
                        $handle=$this->provider->createSubmissionHandle($message);
                        if(is_array($handle)){
                            SmsDispatchService::completeSubmission($message,$this->provider,$handle);
                            $stats['processed']++;$handle=null;continue;
                        }
                        if($this->shouldStop()){
                            curl_close($handle);$handle=null;
                            SmsDispatchService::releaseUnstartedSubmission($message);
                            $stats['deferred_before_start']++;continue;
                        }
                        // Bounded response storage, independent of provider output.
                        $buffer=(object)['body'=>''];
                        curl_setopt($handle,CURLOPT_WRITEFUNCTION,static function($handle,string $chunk)use($buffer):int{
                            if(strlen($buffer->body)+strlen($chunk)>65536)return 0;
                            $buffer->body.=$chunk;return strlen($chunk);
                        });
                        if(curl_multi_add_handle($multi,$handle)!==CURLM_OK)throw new RuntimeException('SMS request could not enter the transport');
                        $active[spl_object_id($handle)]=['handle'=>$handle,'message'=>$message,'buffer'=>$buffer];
                        $stats['requests_started']++;
                        $stats['peak_in_flight']=max($stats['peak_in_flight'],count($active));
                        // Persist across batches: no new-batch request-rate burst.
                        $this->nextStartAt=microtime(true)+1/$this->rate;
                    }catch(Throwable $e){
                        if($handle instanceof CurlHandle){curl_multi_remove_handle($multi,$handle);curl_close($handle);}
                        $this->failure($message,$e,$stats);
                    }
                    continue;
                }

                if(!$active&&($index===$total||$this->shouldStop()))break;
                $wait=.1;
                if($index<$total&&count($active)<$this->concurrency&&!$this->shouldStop())$wait=min($wait,max(.001,$this->nextStartAt-microtime(true)));
                if($active){
                    if(curl_multi_select($multi,$wait)===-1)usleep(10000);
                }else usleep((int)ceil($wait*1000000));
            }
        }finally{
            // If the process/DB fails, persisted intent prevents resending any
            // unfinished transfer. Caller/shutdown cleanup retains those holds.
            foreach($active as $entry){curl_multi_remove_handle($multi,$entry['handle']);curl_close($entry['handle']);}
            curl_multi_close($multi);
        }
        $stats['unstarted']=$total-$index;
        $stats['deadline_reached']=$this->startBefore!==null&&microtime(true)>=$this->startBefore;
        return $stats;
    }

    private function sequential(array $messages,array $stats,?callable $heartbeat):array
    {
        $index=0;
        foreach($messages as $message){
            if($this->shouldStop())break;
            while(microtime(true)<$this->nextStartAt&&!$this->shouldStop()){
                $this->heartbeat($heartbeat,0);usleep((int)ceil(min(.1,max(.001,$this->nextStartAt-microtime(true)))*1000000));
            }
            if($this->shouldStop())break;
            $this->heartbeat($heartbeat,0);$index++;
            try{
                $prepared=SmsDispatchService::prepareSubmission($message);
                if($prepared===null){$stats['skipped']++;continue;}
                $message=$prepared;
                if($this->shouldStop()){SmsDispatchService::releaseUnstartedSubmission($message);$stats['deferred_before_start']++;break;}
                $this->nextStartAt=microtime(true)+1/$this->rate;
                $stats['requests_started']++;$stats['peak_in_flight']=1;
                try{$result=$this->provider->send($message['destination'],$message['content'],$message['sender_id']??null,'portal-message-'.$message['id']);}
                catch(Throwable){$result=['success'=>false,'uncertain'=>true,'error'=>'Provider request ended without a confirmed outcome'];}
                SmsDispatchService::completeSubmission($message,$this->provider,$result);$stats['processed']++;
            }catch(Throwable $e){$this->failure($message,$e,$stats);}
        }
        $stats['unstarted']=count($messages)-$index;
        $stats['deadline_reached']=$this->startBefore!==null&&microtime(true)>=$this->startBefore;
        return $stats;
    }

    private function heartbeat(?callable $heartbeat,int $active):void
    {
        if($heartbeat!==null&&microtime(true)-$this->lastHeartbeat>=1){
            $heartbeat(['in_flight'=>$active,'concurrency'=>$this->provider instanceof ConcurrentSmsProvider?$this->concurrency:1]);
            $this->lastHeartbeat=microtime(true);
        }
    }

    private function failure(array $message,Throwable $error,array &$stats):void
    {
        $this->failed=true;$stats['failed']++;
        $reason='Dispatch exception ('.get_class($error).'); support reconciliation required';
        OperationalLogger::log('error','sms-worker','dispatch_exception',$reason,['campaign_id'=>$message['campaign_id'],'sms_message_id'=>$message['id'],'user_id'=>$message['user_id'],'error_fingerprint'=>substr(hash('sha256',get_class($error).':'.$error->getMessage()),0,16)]);
        try{SmsDispatchService::releaseMessageLease((int)$message['id'],$message['lease_token'],$reason);}
        catch(Throwable){error_log('SMS lease cleanup deferred; durable submission hold retained');}
    }
}
