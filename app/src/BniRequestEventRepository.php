<?php

declare(strict_types=1);

final class BniRequestEventRepository
{
    public const IMMEDIATE_WAIT_TOLERANCE_MS=10;
    private const REQUEST_TYPES=['map','chapter_detail','member_list','member_discovery','other'];
    private const RESULT_TYPES=['success','rate_limited','forbidden','network_error','http_error'];

    public function __construct(private readonly PDO $database) {}

    public function start(string $requestType,?string $triggerType=null,?int $startedAtMs=null,?int $throttleReservedAtMs=null):int
    {
        if(!in_array($requestType,self::REQUEST_TYPES,true))$requestType='other';
        $startedAtMs??=(int)floor(microtime(true)*1000);$waitMs=$throttleReservedAtMs===null?null:max(0,$startedAtMs-$throttleReservedAtMs);
        $statement=$this->database->prepare('INSERT INTO bni_request_events(started_at_ms,request_type,trigger_type,throttle_reserved_at_ms,throttle_wait_ms)VALUES(:started,:request,:trigger,:reserved,:wait)');
        $statement->execute([':started'=>$startedAtMs,':request'=>$requestType,':trigger'=>$triggerType,':reserved'=>$throttleReservedAtMs,':wait'=>$waitMs]);
        return(int)$this->database->lastInsertId();
    }

    public function finish(int $id,?int $httpStatus,string $resultType):void
    {
        if($id<=0||!in_array($resultType,self::RESULT_TYPES,true))return;
        $statement=$this->database->prepare('UPDATE bni_request_events SET http_status=:status,result_type=:result WHERE id=:id');
        $statement->execute([':status'=>$httpStatus,':result'=>$resultType,':id'=>$id]);
    }

    /** @return array<string,mixed> */
    public function performance(int $windowMinutes=5,?int $nowMs=null):array
    {
        if(!in_array($windowMinutes,[1,5,10,30,60],true))throw new InvalidArgumentException('Ungültiger Zeitraum.');
        $nowMs??=(int)floor(microtime(true)*1000);$currentMinute=intdiv($nowMs,60000);$firstMinute=$currentMinute-1439;
        $historyStart=($firstMinute-$windowMinutes+1)*60000;$thirtyDaysAgo=$nowMs-30*86400000;
        $statement=$this->database->prepare('SELECT started_at_ms,throttle_wait_ms FROM bni_request_events WHERE started_at_ms>=:start AND started_at_ms<=:now ORDER BY started_at_ms');
        $statement->execute([':start'=>min($historyStart,$thirtyDaysAgo),':now'=>$nowMs]);$eventRows=$statement->fetchAll();$events=array_map(static fn(array$row):int=>(int)$row['started_at_ms'],$eventRows);
        $buckets=[];$requests24=0;$requests60=0;$cutoff24=$nowMs-86400000;$cutoff60=$nowMs-3600000;
        foreach($events as$started){$minute=intdiv($started,60000);$buckets[$minute]=($buckets[$minute]??0)+1;if($started>=$cutoff24)$requests24++;if($started>=$cutoff60)$requests60++;}
        $points=[];$rolling=0;for($minute=$firstMinute-$windowMinutes+1;$minute<=$currentMinute;$minute++){ $rolling+=$buckets[$minute]??0;if($minute-$windowMinutes>=$firstMinute-$windowMinutes+1)$rolling-=$buckets[$minute-$windowMinutes]??0;if($minute>=$firstMinute)$points[]=['minute'=>gmdate('Y-m-d\TH:i:00\Z',$minute*60),'count'=>$rolling];}
        $peakCount=0;$peakMinute=null;foreach($buckets as$minute=>$count){if($minute*60000<$thirtyDaysAgo)continue;if($count>$peakCount||($count===$peakCount&&($peakMinute===null||$minute>$peakMinute))){$peakCount=$count;$peakMinute=$minute;}}
        $waits=[];$waitBuckets=[];foreach($eventRows as$row){$started=(int)$row['started_at_ms'];if($started<$cutoff24||$row['throttle_wait_ms']===null)continue;$wait=max(0,(int)$row['throttle_wait_ms']);$waits[]=$wait;$bucket=intdiv($started,300000);$waitBuckets[$bucket]??=[];$waitBuckets[$bucket][]=$wait;}
        sort($waits);$waited=count(array_filter($waits,static fn(int$wait):bool=>$wait>self::IMMEDIATE_WAIT_TOLERANCE_MS));$immediate=count($waits)-$waited;$average=$waits===[]?null:(int)round(array_sum($waits)/count($waits));$maximum=$waits===[]?null:max($waits);$p95=$waits===[]?null:$waits[(int)ceil(count($waits)*.95)-1];$series=[];$firstBucket=intdiv($nowMs-86400000,300000);$lastBucket=intdiv($nowMs,300000);for($bucket=$firstBucket;$bucket<=$lastBucket;$bucket++){$values=$waitBuckets[$bucket]??[];$series[]=['bucket'=>gmdate('Y-m-d\TH:i:00\Z',$bucket*300),'average_wait_ms'=>$values===[]?null:(int)round(array_sum($values)/count($values)),'maximum_wait_ms'=>$values===[]?null:max($values)];}
        $reserved=(int)$this->database->query('SELECT last_reserved_start_ms FROM bni_request_throttle WHERE id=1')->fetchColumn();$lastActual=$this->database->query('SELECT MAX(started_at_ms) FROM bni_request_events')->fetchColumn();$lastActual=$lastActual===false||$lastActual===null?null:(int)$lastActual;
        return['window_minutes'=>$windowMinutes,'last_24h'=>$points,'requests_24h'=>$requests24,'requests_60m'=>$requests60,'peak_30d'=>['count'=>$peakCount,'minute'=>$peakMinute===null?null:gmdate('Y-m-d\TH:i:00\Z',$peakMinute*60)],'throttle'=>['enabled'=>true,'minimum_interval_ms'=>1500,'last_reserved_start_ms'=>$reserved>0?$reserved:null,'last_actual_start_ms'=>$lastActual,'requests_24h'=>$requests24,'measured_requests_24h'=>count($waits),'waited_requests_24h'=>$waited,'immediate_requests_24h'=>$immediate,'avg_wait_ms_24h'=>$average,'max_wait_ms_24h'=>$maximum,'p95_wait_ms_24h'=>$p95,'immediate_tolerance_ms'=>self::IMMEDIATE_WAIT_TOLERANCE_MS,'series_24h'=>$series]];
    }

    public function cleanup(int $retentionDays=35,?int $nowMs=null):int
    {
        $cutoff=($nowMs??(int)floor(microtime(true)*1000))-$retentionDays*86400000;$statement=$this->database->prepare('DELETE FROM bni_request_events WHERE started_at_ms<:cutoff');$statement->execute([':cutoff'=>$cutoff]);return$statement->rowCount();
    }
}
