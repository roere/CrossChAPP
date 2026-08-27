<?php

declare(strict_types=1);

final class BniRequestEventRepository
{
    private const REQUEST_TYPES=['map','chapter_detail','member_list','member_discovery','other'];
    private const RESULT_TYPES=['success','rate_limited','forbidden','network_error','http_error'];

    public function __construct(private readonly PDO $database) {}

    public function start(string $requestType,?string $triggerType=null,?int $startedAtMs=null):int
    {
        if(!in_array($requestType,self::REQUEST_TYPES,true))$requestType='other';
        $statement=$this->database->prepare('INSERT INTO bni_request_events(started_at_ms,request_type,trigger_type)VALUES(:started,:request,:trigger)');
        $statement->execute([':started'=>$startedAtMs??(int)floor(microtime(true)*1000),':request'=>$requestType,':trigger'=>$triggerType]);
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
        $statement=$this->database->prepare('SELECT started_at_ms FROM bni_request_events WHERE started_at_ms>=:start AND started_at_ms<=:now ORDER BY started_at_ms');
        $statement->execute([':start'=>min($historyStart,$thirtyDaysAgo),':now'=>$nowMs]);$events=array_map('intval',$statement->fetchAll(PDO::FETCH_COLUMN));
        $buckets=[];$requests24=0;$requests60=0;$cutoff24=$nowMs-86400000;$cutoff60=$nowMs-3600000;
        foreach($events as$started){$minute=intdiv($started,60000);$buckets[$minute]=($buckets[$minute]??0)+1;if($started>=$cutoff24)$requests24++;if($started>=$cutoff60)$requests60++;}
        $points=[];$rolling=0;for($minute=$firstMinute-$windowMinutes+1;$minute<=$currentMinute;$minute++){ $rolling+=$buckets[$minute]??0;if($minute-$windowMinutes>=$firstMinute-$windowMinutes+1)$rolling-=$buckets[$minute-$windowMinutes]??0;if($minute>=$firstMinute)$points[]=['minute'=>gmdate('Y-m-d\TH:i:00\Z',$minute*60),'count'=>$rolling];}
        $peakCount=0;$peakMinute=null;foreach($buckets as$minute=>$count){if($minute*60000<$thirtyDaysAgo)continue;if($count>$peakCount||($count===$peakCount&&($peakMinute===null||$minute>$peakMinute))){$peakCount=$count;$peakMinute=$minute;}}
        return['window_minutes'=>$windowMinutes,'last_24h'=>$points,'requests_24h'=>$requests24,'requests_60m'=>$requests60,'peak_30d'=>['count'=>$peakCount,'minute'=>$peakMinute===null?null:gmdate('Y-m-d\TH:i:00\Z',$peakMinute*60)]];
    }

    public function cleanup(int $retentionDays=35,?int $nowMs=null):int
    {
        $cutoff=($nowMs??(int)floor(microtime(true)*1000))-$retentionDays*86400000;$statement=$this->database->prepare('DELETE FROM bni_request_events WHERE started_at_ms<:cutoff');$statement->execute([':cutoff'=>$cutoff]);return$statement->rowCount();
    }
}
