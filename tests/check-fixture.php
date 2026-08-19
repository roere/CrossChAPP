<?php
declare(strict_types=1);
require_once '/var/www/html/src/Database.php';
require_once '/var/www/html/src/UserRepository.php';
require_once '/var/www/html/src/RepresentationRequestRepository.php';
require_once '/var/www/html/src/RepresentationOfferRepository.php';

if (getenv('CROSSCHAPP_TEST_MODE') !== '1') throw new RuntimeException('Fixture darf nur im Testmodus laufen.');
$db=(new Database())->connection();$now=gmdate('Y-m-d\TH:i:s\Z');
$organization=$db->prepare("INSERT INTO organizations(org_id,country_code,org_type,latitude,longitude,chapter_name,region,city,postal_code,meeting_day,meeting_time,meeting_type,member_count,timezone,detail_status,details_loaded_at,created_at,updated_at)VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,'loaded',?,?,?)");
$rows=[
    [910001,'DE','CHAPTER',50.9500,7.3000,'Testchapter Königsforst','Testregion','Overath','51491','Freitag','06:45','Präsenz',25,'Europe/Berlin'],
    [910002,'DE','CHAPTER',50.9600,7.3100,'Testchapter Rhein','Testregion','Köln','50667','Mittwoch','07:00','Präsenz',30,'Europe/Berlin'],
    [910003,'AT','CHAPTER',50.9700,7.3200,'Testchapter Alpen','Testregion Österreich','Wien','1010','Montag','08:00','Präsenz',18,'Europe/Vienna'],
    [910004,'DE','CORE_GROUP',50.9800,7.3300,'Testgruppe Aufbau','Testregion','Bonn','53111','Dienstag','09:00','Online',8,'Europe/Berlin'],
];
foreach($rows as$row)$organization->execute([...$row,$now,$now,$now]);
$users=new UserRepository($db);$a=$users->create('Anna','Alpha','check-a@example.test',password_hash('check-password-123',PASSWORD_DEFAULT),910001,'manual_verified');$b=$users->create('Bernd','Beta','check-b@example.test',password_hash('check-password-123',PASSWORD_DEFAULT),910002,'directory_match');$c=$users->create('Carla','Gamma','check-c@example.test',password_hash('check-password-123',PASSWORD_DEFAULT),null);$deletable=$users->create('Dora','Delete','check-delete@example.test',password_hash('check-password-123',PASSWORD_DEFAULT),910003);$db->exec("UPDATE users SET status='active',email_verified_at='$now' WHERE id IN (".(int)$a['id'].','.(int)$b['id'].','.(int)$deletable['id'].')');
$nextFriday=(new DateTimeImmutable('next friday',new DateTimeZone('Europe/Berlin')))->format('Y-m-d');
$nextWednesday=(new DateTimeImmutable('next wednesday',new DateTimeZone('Europe/Berlin')))->format('Y-m-d');
$request=(new RepresentationRequestRepository($db))->create((int)$a['id'],$nextFriday);
$offer=(new RepresentationOfferRepository($db))->createMany((int)$a['id'],[910002],false,[$nextWednesday])[0];
(new RepresentationOfferRepository($db))->createMany((int)$a['id'],[910003],true,[]);
(new RepresentationRequestRepository($db))->create((int)$b['id'],(new DateTimeImmutable('next wednesday +7 days',new DateTimeZone('Europe/Berlin')))->format('Y-m-d'));
$pastRequest=$db->prepare('INSERT INTO representation_requests(user_id,org_id,request_date,created_at,updated_at) VALUES(?,?,?,?,?)');$pastRequest->execute([(int)$a['id'],910003,(new DateTimeImmutable('yesterday',new DateTimeZone('Europe/Berlin')))->format('Y-m-d'),$now,$now]);
echo json_encode(['userA'=>(int)$a['id'],'userB'=>(int)$b['id'],'userC'=>(int)$c['id'],'deletableUser'=>(int)$deletable['id'],'requestId'=>(int)$request['id'],'offerId'=>$offer,'requestDate'=>$nextFriday,'offerDate'=>$nextWednesday],JSON_UNESCAPED_UNICODE),"\n";
