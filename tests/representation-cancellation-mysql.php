<?php
declare(strict_types=1);
$app=is_file(__DIR__.'/../app/src/Database.php')?__DIR__.'/../app':'/var/www/html';
require_once $app.'/src/Database.php';require_once $app.'/src/MailSettingsRepository.php';require_once $app.'/src/MailService.php';require_once $app.'/src/RepresentationAssignmentService.php';
$db=(new Database())->connection();if($db->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql'){echo "SKIP MariaDB assignment cancellation\n";return;}
$users=[];foreach(['check-a@example.test','check-b@example.test','check-c@example.test']as$email){$s=$db->prepare('SELECT id FROM users WHERE email=?');$s->execute([$email]);$users[$email]=(int)$s->fetchColumn();}
[$requester,$representative,$foreign]=[$users['check-a@example.test'],$users['check-b@example.test'],$users['check-c@example.test']];$now=gmdate('Y-m-d\TH:i:s\Z');$nonce=bin2hex(random_bytes(5));$ids=[];$mails=[];
try{
    $insert=$db->prepare("INSERT INTO representation_assignments(chapter_org_id,representation_date,requester_user_id,representative_user_id,status,active_slot_key,accepted_at,accepted_by_user_id,created_at,updated_at)VALUES(910001,'2099-12-10',:requester,:representative,'active',:slot,:now,:accepted_by,:now2,:now3)");
    foreach(['requester','representative']as$kind){$insert->execute([':requester'=>$requester,':representative'=>$representative,':slot'=>"cancel-$kind-$nonce",':now'=>$now,':accepted_by'=>$requester,':now2'=>$now,':now3'=>$now]);$ids[$kind]=(int)$db->lastInsertId();}
    $settings=new MailSettingsRepository($db);$service=new RepresentationAssignmentService($db,$settings,new MailService($settings,static function(...$args)use(&$mails){$mails[]=$args;}));
    try{$service->cancel($ids['representative'],$foreign);assert(false);}catch(RepresentationAssignmentException $e){assert($e->apiCode==='assignment_forbidden'&&$e->httpStatus===403);}assert(count($mails)===0);
    $service->cancel($ids['requester'],$requester);$service->cancel($ids['representative'],$representative);assert(count($mails)===4);
    foreach($ids as$id){$row=$db->query("SELECT status,active_slot_key,cancelled_at,cancelled_by_user_id FROM representation_assignments WHERE id=$id")->fetch();assert($row['status']==='cancelled'&&$row['active_slot_key']===null&&$row['cancelled_at']!==null);}
    assert((int)$db->query("SELECT cancelled_by_user_id FROM representation_assignments WHERE id={$ids['requester']}")->fetchColumn()===$requester);assert((int)$db->query("SELECT cancelled_by_user_id FROM representation_assignments WHERE id={$ids['representative']}")->fetchColumn()===$representative);
    $replacement="cancel-requester-$nonce";$insert->execute([':requester'=>$requester,':representative'=>$foreign,':slot'=>$replacement,':now'=>$now,':accepted_by'=>$requester,':now2'=>$now,':now3'=>$now]);$ids['replacement']=(int)$db->lastInsertId();
    try{$service->cancel($ids['requester'],$requester);assert(false);}catch(RepresentationAssignmentException $e){assert($e->apiCode==='assignment_already_cancelled'&&$e->httpStatus===409);}assert(count($mails)===4);
    echo "PASS MariaDB assignment cancellation: requester/representative HTTP semantics, audit fields, mail-after-commit und Slot-Freigabe\n";
}finally{if($ids){$db->exec('DELETE FROM representation_assignments WHERE id IN('.implode(',',array_map('intval',array_values($ids))).')');}}
