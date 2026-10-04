<?php

declare(strict_types=1);
$root=is_file(__DIR__.'/../app/src/Database.php')?__DIR__.'/../app':'/var/www/html';
foreach(['Database','PushSubscriptionRepository','PushNotificationRunner','WatchlistNotificationRunner','ServerLoadRepository'] as $file) require_once $root.'/src/'.$file.'.php';
$check=static function(bool $ok,string $message):void { if(!$ok)throw new RuntimeException($message); };
if (getenv('CROSSCHAPP_TEST_MODE')!=='1') throw new RuntimeException('Push-Tests benötigen die isolierte Testumgebung.');
$path=tempnam(sys_get_temp_dir(),'push-test-');
try {
    foreach (['sqlite','mysql'] as $driver) {
        $db=(new Database($driver==='sqlite'?$path:null))->connection();
        if ($driver==='mysql')$db->beginTransaction();
        try {
            $now=new DateTimeImmutable('now',new DateTimeZone('UTC'));$now=$now->setTime((int)$now->format('H'),(int)$now->format('i'),(int)$now->format('s'));
            $t0=$now->modify('-1 hour')->format('Y-m-d\TH:i:s\Z');$t1=$now->modify('-30 minutes')->format('Y-m-d\TH:i:s\Z');$t2=$now->modify('-20 minutes')->format('Y-m-d\TH:i:s\Z');
            $user=$db->prepare("INSERT INTO users(first_name,last_name,email,password_hash,role,status,email_verified_at,created_at,updated_at) VALUES('Push','Test',?,'not-a-login','user','active',?,?,?)");
            $ids=[];foreach(['watch','request','manager'] as $name){$user->execute(['push-'.$name.'@example.test',$t0,$t0,$t0]);$ids[]=(int)$db->lastInsertId();}[$watcher,$creator,$manager]=$ids;
            $db->exec("UPDATE users SET role='user_manager' WHERE id=$manager");
            $org=$db->prepare("INSERT INTO organizations(org_id,org_type,chapter_name,short_link_slug,created_at,updated_at) VALUES(920001,'CHAPTER','Push-Testchapter','bni_push_test',?,?)");$org->execute([$t0,$t0]);
            $w=$db->prepare('INSERT INTO user_chapter_watchlist(user_id,organization_id,created_at) VALUES(?,920001,?)');$w->execute([$watcher,$t0]);$w->execute([$manager,$t0]);
            $subs=new PushSubscriptionRepository($db);
            // Synthetic public bytes and auth fixture; these are not real browser tokens.
            $fixture=static fn(string $name):array=>['endpoint'=>'https://fcm.googleapis.com/fcm/send/test-'.$name,'keys'=>['p256dh'=>rtrim(strtr(base64_encode(chr(4).str_repeat('x',64)),'+/','-_'),'='),'auth'=>rtrim(strtr(base64_encode(str_repeat('x',16)),'+/','-_'),'=')]];
            $check((int)$db->query('SELECT COUNT(*) FROM push_subscriptions')->fetchColumn()===0,'Push standardmäßig aktiv.');
            foreach(['good','temporary','expired'] as $name)$subs->save($watcher,$fixture($name),$t0);
            $subs->save($manager,$fixture('manager'),$t0);
            $subs->save($watcher,$fixture('good'),$now->format('Y-m-d\TH:i:s\Z'));
            $check((int)$db->query('SELECT COUNT(*) FROM push_subscriptions')->fetchColumn()===4,'Mehrgeräte/Idempotenz fehlerhaft.');
            try{$subs->save($manager,$fixture('good'),$t0);throw new RuntimeException('Fremdes Gerät akzeptiert.');}catch(InvalidArgumentException){}
            try{$subs->save($watcher,['endpoint'=>'https://127.0.0.1/private','keys'=>$fixture('good')['keys']],$t0);throw new RuntimeException('SSRF-Adresse akzeptiert.');}catch(InvalidArgumentException){}
            try{$subs->save($watcher,['endpoint'=>$fixture('bad')['endpoint'],'keys'=>['p256dh'=>'bad','auth'=>'bad']],$t0);throw new RuntimeException('Ungültige Schlüssel akzeptiert.');}catch(InvalidArgumentException){}
            $r=$db->prepare('INSERT INTO representation_requests(user_id,org_id,request_date,created_at,updated_at) VALUES(?,920001,?,?,?)');
            $date=$now->modify('+1 day')->format('Y-m-d');
            $r->execute([$creator,$now->modify('+2 days')->format('Y-m-d'),$now->modify('-2 hours')->format('Y-m-d\TH:i:s\Z'),$t0]);
            $r->execute([$watcher,$date,$t1,$t1]);$self=(int)$db->lastInsertId();
            $r->execute([$creator,$date,$t1,$t1]);$request=(int)$db->lastInsertId();
            $subs->save($watcher,$fixture('late'),$t2);
            $calls=[];$retry=false;
            $fake=new PushTransport(static function(array $subscription,array $payload)use(&$calls,&$retry,$check):int {
                $calls[]=$subscription['endpoint'];
                $check($payload['url']==='/bni_push_test'&&!str_contains(json_encode($payload),'example.test')&&!array_key_exists('user_id',$payload),'Personendetails im Payload.');
                if(str_ends_with($subscription['endpoint'],'temporary'))return $retry?201:503;
                if(str_ends_with($subscription['endpoint'],'expired'))return 410;
                return 201;
            });
            $runner=new PushNotificationRunner($db,$fake);$stats=$runner->run($now);
            // Watcher own request is relevant to the manager, but never to the watcher.
            $check($stats['delivery_attempts']===5&&$stats['success_count']===3&&$stats['failure_count']===2&&$stats['expired_subscription_count']===1,'Push-Zähler fehlerhaft: '.json_encode($stats));
            $check(!in_array($fixture('late')['endpoint'],$calls,true),'Rückwirkende Geräte-Benachrichtigung.');
            $check((int)$db->query("SELECT COUNT(*) FROM push_deliveries d JOIN push_subscriptions s ON s.id=d.subscription_id WHERE s.user_id=$watcher AND d.request_id=$self")->fetchColumn()===0,'Selbstbenachrichtigung.');
            $check((int)$db->query("SELECT COUNT(*) FROM watchlist_notifications WHERE channel='push'")->fetchColumn()===3,'Fachliche Push-Deduplizierung fehlerhaft.');
            $check((int)$db->query("SELECT COUNT(*) FROM watchlist_notifications WHERE channel='email'")->fetchColumn()===0,'E-Mail und Push nicht unabhängig.');
            $before=count($calls);$runner->run($now);$check(count($calls)===$before,'Sofortige Wiederholung statt Backoff.');
            $retry=true;$second=$runner->run($now->modify('+6 minutes'));
            $check($second['delivery_attempts']===1&&$second['success_count']===1,'Temporäres Gerät nicht separat erneut versucht.');
            $runner->run($now->modify('+40 minutes'));$check(count($calls)===$before+1,'Erneute Push-Welle an erfolgreiche Geräte.');
            $subs->remove($watcher,$fixture('good')['endpoint']);$check(!$subs->contains($watcher,$fixture('good')['endpoint']),'Deaktivierung fehlerhaft.');
            $check(!$subs->contains($watcher,$fixture('expired')['endpoint']),'Ungültiges Gerät nicht entfernt.');
            $load=(new ServerLoadRepository($db))->overview($now->modify('+1 minute'));
            $check($load['push']['delivery_attempts']===6&&$load['push']['success_count']===4&&$load['push']['failure_count']===2,'24h-Last falsch.');
            $check(array_sum(array_column($load['series'],'delivery_attempts'))===6,'Bucket-Aggregation falsch.');
            $check($load['runs'][0]['duration_ms']>=0&&$load['runs'][0]['finished_at_ms']>=$load['runs'][0]['started_at_ms'],'Zeitmessung falsch.');
            $encoded=json_encode($load);$check(!str_contains($encoded,'fcm.googleapis.com')&&!str_contains($encoded,'p256dh')&&!str_contains($encoded,'Push-Testchapter')&&!str_contains($encoded,'user_id'),'Sensible Inhalte im Monitoring.');
            $guard=new PushTransport();try{$guard->send(['endpoint'=>$fixture('good')['endpoint']],[]);throw new RuntimeException('Externer Versand im Test möglich.');}catch(RuntimeException $error){$check(str_contains($error->getMessage(),'deaktiviert'),'Push-Test-Guard fehlerhaft.');}
            $monitor=new ServerLoadRepository($db);$empty=['candidate_notifications'=>0,'subscriptions_targeted'=>0,'delivery_attempts'=>0,'success_count'=>0,'failure_count'=>0,'expired_subscription_count'=>0];
            $old=($now->getTimestamp()-31*86400)*1000;$monitor->record($empty,$old,$old+125,125.0,2.0);$monitor->cleanup($now);
            $check((int)$db->query("SELECT COUNT(*) FROM push_runs WHERE started_at_ms=$old")->fetchColumn()===0,'Retention fehlt.');
            $db->exec("DELETE FROM users WHERE id=$watcher");$check((int)$db->query("SELECT COUNT(*) FROM push_subscriptions WHERE user_id=$watcher")->fetchColumn()===0,'Account-Cascade fehlt.');
            echo "PASS Web Push / Serverlast $driver: Geräte, Cutoff, Retry, Deduplizierung, Payload-Schutz, 24h, Buckets, Retention, Cascade\n";
        } finally { if($driver==='mysql'&&$db->inTransaction()) { $db->rollBack(); foreach(['users','organizations','representation_requests','watchlist_notifications','push_subscriptions','push_deliveries','push_runs'] as $table) { $next=(int)$db->query('SELECT COALESCE(MAX(id),0)+1 FROM '.$table)->fetchColumn(); $db->exec('ALTER TABLE '.$table.' AUTO_INCREMENT='.$next); } } }
    }
} finally {@unlink($path);@unlink($path.'-wal');@unlink($path.'-shm');}
