<?php

declare(strict_types=1);

final class ServerLoadRepository
{
    public function __construct(private readonly PDO $db) {}
    public function record(array $stats,int $start,int $finish,float $duration,?float $cpu): void
    {
        $stmt=$this->db->prepare('INSERT INTO push_runs(started_at_ms,finished_at_ms,duration_ms,candidate_notifications,subscriptions_targeted,delivery_attempts,success_count,failure_count,expired_subscription_count,cpu_ms) VALUES(?,?,?,?,?,?,?,?,?,?)');
        $stmt->execute([$start,$finish,max(0,$duration),$stats['candidate_notifications'],$stats['subscriptions_targeted'],$stats['delivery_attempts'],$stats['success_count'],$stats['failure_count'],$stats['expired_subscription_count'],$cpu===null?null:max(0,$cpu)]);
    }
    public function cleanup(?DateTimeImmutable $now=null): void
    {
        $now??=new DateTimeImmutable('now',new DateTimeZone('UTC'));
        $stmt=$this->db->prepare('DELETE FROM push_runs WHERE started_at_ms<?');$stmt->execute([($now->getTimestamp()-30*86400)*1000]);
        $stmt=$this->db->prepare('DELETE FROM push_deliveries WHERE created_at<?');$stmt->execute([$now->modify('-30 days')->format('Y-m-d\TH:i:s\Z')]);
    }
    public function overview(?DateTimeImmutable $now=null): array
    {
        $now??=new DateTimeImmutable('now',new DateTimeZone('UTC'));$end=$now->getTimestamp()*1000;$start=$end-86400000;
        $stmt=$this->db->prepare('SELECT * FROM push_runs WHERE started_at_ms>=? AND started_at_ms<=? ORDER BY started_at_ms DESC,id DESC');$stmt->execute([$start,$end]);$rows=$stmt->fetchAll();
        $totals=['runs'=>count($rows),'delivery_attempts'=>0,'success_count'=>0,'failure_count'=>0,'expired_subscription_count'=>0,'duration_ms'=>0.0,'maximum_duration_ms'=>0.0];
        $buckets=[];for($time=intdiv($start,900000)*900000;$time<=$end;$time+=900000) $buckets[$time]=['time'=>$time,'delivery_attempts'=>0,'duration_ms'=>0.0];
        foreach ($rows as &$row) {
            foreach (['id','started_at_ms','finished_at_ms','candidate_notifications','subscriptions_targeted','delivery_attempts','success_count','failure_count','expired_subscription_count'] as $key) $row[$key]=(int)$row[$key];
            $row['duration_ms']=(float)$row['duration_ms'];$row['cpu_ms']=$row['cpu_ms']===null?null:(float)$row['cpu_ms'];
            foreach (['delivery_attempts','success_count','failure_count','expired_subscription_count','duration_ms'] as $key) $totals[$key]+=$row[$key];
            $totals['maximum_duration_ms']=max($totals['maximum_duration_ms'],$row['duration_ms']);
            $bucket=intdiv($row['started_at_ms'],900000)*900000;$buckets[$bucket]['delivery_attempts']+=$row['delivery_attempts'];$buckets[$bucket]['duration_ms']+=$row['duration_ms'];
        }unset($row);
        $totals['average_duration_ms']=$totals['runs']?$totals['duration_ms']/$totals['runs']:null;
        $totals['subscriptions']=(int)$this->db->query('SELECT COUNT(*) FROM push_subscriptions')->fetchColumn();
        $totals['subscribed_users']=(int)$this->db->query('SELECT COUNT(DISTINCT user_id) FROM push_subscriptions')->fetchColumn();
        $heartbeat=$this->db->query('SELECT worker_last_seen_at FROM automation_runtime WHERE id=1')->fetchColumn();
        $status=$totals['failure_count']?'Push-Fehler vorhanden':($totals['delivery_attempts']?'Web Push aktiv':'Keine Push-Aktivität');
        return ['push'=>$totals,'status'=>$status,'series'=>array_values($buckets),'runs'=>array_slice($rows,0,200),
            'server'=>['load_average'=>function_exists('sys_getloadavg')?sys_getloadavg():null,
                'cpu_percent'=>null,'container_memory_bytes'=>self::cgroupMemory('memory.current'),'container_memory_limit_bytes'=>self::cgroupMemory('memory.max'),'process_memory_bytes'=>memory_get_usage(true),'process_peak_bytes'=>memory_get_peak_usage(true),
                'worker_heartbeat'=>$heartbeat?:null],
            'scope'=>'Load Average: Linux-System in der Sicht dieses Containers; Speicher: Container-Cgroup, soweit verfügbar, und aktueller PHP-Monitoringprozess. CPU-Prozentwerte sind nicht zuverlässig verfügbar. Eine Bewertung der Serverauslastung wird daraus nicht abgeleitet.',
            'retention_days'=>30,'bucket_minutes'=>15];
    }
    private static function cgroupMemory(string $file): ?int
    {
        // Runtime reads of local kernel counters, no external monitoring service.
        $value=@file_get_contents('/sys/fs/cgroup/'.$file);
        return is_string($value)&&ctype_digit(trim($value))?(int)trim($value):null;
    }

}
