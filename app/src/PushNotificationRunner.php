<?php

declare(strict_types=1);
require_once __DIR__.'/PushTransport.php';
require_once __DIR__.'/RepresentationExpiryPolicy.php';
require_once __DIR__.'/ServerLoadRepository.php';

/** Delivery receipts are per device; the existing Watchlist receipt remains per user/request/channel. */
final class PushNotificationRunner
{
    public function __construct(private readonly PDO $db,private readonly ?PushTransport $transport=null) {}

    public function run(?DateTimeImmutable $now=null): array
    {
        $transport=$this->transport??new PushTransport();
        $now??=new DateTimeImmutable('now',new DateTimeZone('UTC'));
        $started=(int)floor(microtime(true)*1000);$clock=hrtime(true);$cpu=self::cpuTime();
        $stats=['candidate_notifications'=>0,'subscriptions_targeted'=>0,'delivery_attempts'=>0,'success_count'=>0,'failure_count'=>0,'expired_subscription_count'=>0];
        $monitor=new ServerLoadRepository($this->db);$monitor->cleanup($now);
        if (!$transport->ready()) return $stats;
        $stamp=$now->format('Y-m-d\TH:i:s\Z');
        try {
            $candidates=$this->db->prepare("SELECT s.id subscription_id,r.id request_id,w.user_id,r.request_date
                FROM user_chapter_watchlist w JOIN users u ON u.id=w.user_id
                JOIN push_subscriptions s ON s.user_id=u.id
                JOIN representation_requests r ON r.org_id=w.organization_id AND r.user_id<>u.id
                LEFT JOIN watchlist_notifications n ON n.user_id=u.id AND n.representation_request_id=r.id AND n.channel='push'
                WHERE u.role IN ('user','user_manager') AND u.status='active' AND u.email_verified_at IS NOT NULL
                AND n.id IS NULL AND r.created_at>=w.created_at AND r.created_at>=s.enabled_at AND r.request_date>=? ORDER BY r.id,s.id LIMIT 5000");
            $candidates->execute([RepresentationExpiryPolicy::minimumActiveDate($now)]);
            $insert=$this->db->prepare(($this->mysql()?'INSERT IGNORE':'INSERT OR IGNORE')." INTO push_deliveries(subscription_id,request_id,created_at) VALUES(?,?,?)");
            foreach ($candidates->fetchAll() as $row) {
                if (RepresentationExpiryPolicy::isActive($row['request_date'],$now)) $insert->execute([$row['subscription_id'],$row['request_id'],$stamp]);
            }
            $queue=$this->db->prepare("SELECT d.id delivery_id,d.request_id,s.*,r.request_date,o.chapter_name,o.short_link_slug
                FROM push_deliveries d JOIN push_subscriptions s ON s.id=d.subscription_id JOIN users u ON u.id=s.user_id
                JOIN representation_requests r ON r.id=d.request_id JOIN organizations o ON o.org_id=r.org_id
                JOIN user_chapter_watchlist w ON w.user_id=u.id AND w.organization_id=r.org_id
                WHERE d.status='pending' AND (d.locked_until IS NULL OR d.locked_until<=?)
                AND u.role IN ('user','user_manager') AND u.status='active' AND u.email_verified_at IS NOT NULL
                AND r.user_id<>u.id AND r.created_at>=w.created_at AND r.created_at>=s.enabled_at AND r.request_date>=?
                ORDER BY d.id LIMIT 500");
            $queue->execute([$stamp,RepresentationExpiryPolicy::minimumActiveDate($now)]);
            $rows=$queue->fetchAll();$notifications=[];$subscriptions=[];
            foreach ($rows as $row) {
                if ((hrtime(true)-$clock)/1e9>=30) break;
                if (!RepresentationExpiryPolicy::isActive($row['request_date'],$now)) continue;
                // Atomic lease prevents concurrent workers from sending the same successful device twice.
                $claim=$this->db->prepare("UPDATE push_deliveries SET locked_until=?,last_attempt_at=?,attempt_count=attempt_count+1 WHERE id=? AND status='pending' AND (locked_until IS NULL OR locked_until<=?)");
                $claim->execute([$now->modify('+60 seconds')->format('Y-m-d\TH:i:s\Z'),$stamp,$row['delivery_id'],$stamp]);
                if ($claim->rowCount()!==1) continue;
                $notifications[$row['user_id'].':'.$row['request_id']]=true;$subscriptions[$row['id']]=true;
                $stats['delivery_attempts']++;
                $date=new DateTimeImmutable($row['request_date'],new DateTimeZone('Europe/Berlin'));
                $link=$row['short_link_slug']?'/'.$row['short_link_slug']:'/?view=crosschaptern';
                // Generic content only: no requester identity or contact details.
                $payload=['title'=>'Neue Vertretung gesucht','body'=>$row['chapter_name'].' sucht für den '.$date->format('d.m.').' eine Vertretung.',
                    'url'=>$link,'tag'=>'watchlist-'.$row['request_id']];
                try { $status=$transport->send($row,$payload); } catch (Throwable) { $status=0; }
                if ($status>=200 && $status<300) {
                    $stats['success_count']++;
                    $done=$this->db->prepare("UPDATE push_deliveries SET status='sent',sent_at=?,locked_until=NULL,last_status=? WHERE id=?");$done->execute([$stamp,$status,$row['delivery_id']]);
                    $success=$this->db->prepare('UPDATE push_subscriptions SET last_success_at=? WHERE id=?');$success->execute([$stamp,$row['id']]);
                    $receipt=$this->db->prepare(($this->mysql()?'INSERT IGNORE':'INSERT OR IGNORE')." INTO watchlist_notifications(user_id,representation_request_id,channel,sent_at,created_at) VALUES(?,?,'push',?,?)");
                    $receipt->execute([$row['user_id'],$row['request_id'],$stamp,$stamp]);
                } else {
                    $stats['failure_count']++;
                    if (in_array($status,[404,410],true)) {
                        $delete=$this->db->prepare('DELETE FROM push_subscriptions WHERE id=?');$delete->execute([$row['id']]);$stats['expired_subscription_count']+=$delete->rowCount();
                    } else {
                        $retry=$this->db->prepare('UPDATE push_deliveries SET locked_until=?,last_status=? WHERE id=?');$retry->execute([$now->modify('+5 minutes')->format('Y-m-d\TH:i:s\Z'),$status,$row['delivery_id']]);
                    }
                }
            }
            $stats['candidate_notifications']=count($notifications);$stats['subscriptions_targeted']=count($subscriptions);
        } finally {
            $monitor->record($stats,$started,(int)floor(microtime(true)*1000),(hrtime(true)-$clock)/1e6,$cpu===null?null:self::cpuTime()-$cpu);
        }
        return $stats;
    }
    private function mysql():bool { return $this->db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'; }
    private static function cpuTime():?float {
        if (!function_exists('getrusage')) return null;
        $r=getrusage();return $r===false?null:($r['ru_utime.tv_sec']+$r['ru_stime.tv_sec'])*1000+($r['ru_utime.tv_usec']+$r['ru_stime.tv_usec'])/1000;
    }
}
