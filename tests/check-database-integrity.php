<?php
declare(strict_types=1);
$root=is_file(__DIR__.'/../app/src/Database.php')?__DIR__.'/../app':'/var/www/html';
foreach(['Database','UserRepository','RepresentationRequestRepository','RepresentationOfferRepository','MailSettingsRepository','MailService','InvitationRepository','InvitationService']as$class)require_once"$root/src/$class.php";
$ok=static function(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);};
$db=(new Database(':memory:'))->connection();$now=gmdate('Y-m-d\TH:i:s\Z');
$db->exec("INSERT INTO organizations(org_id,country_code,org_type,chapter_name,meeting_day,timezone,created_at,updated_at)VALUES(1,'DE','CHAPTER','Integrität Freitag','Freitag','Europe/Berlin','$now','$now'),(2,'DE','CHAPTER','Integrität Montag','Montag','Europe/Berlin','$now','$now')");
$users=new UserRepository($db);$a=$users->create('Anna','Alpha','integrity-a@example.test',password_hash('password-123',PASSWORD_DEFAULT),1);$b=$users->create('Bernd','Beta','integrity-b@example.test',password_hash('password-123',PASSWORD_DEFAULT),2);$db->exec("UPDATE users SET status='active',email_verified_at='$now'");
$counts=static fn()=>['requests'=>(int)$db->query('SELECT COUNT(*) FROM representation_requests')->fetchColumn(),'offers'=>(int)$db->query('SELECT COUNT(*) FROM representation_offers')->fetchColumn(),'dates'=>(int)$db->query('SELECT COUNT(*) FROM representation_offer_dates')->fetchColumn()];
$before=$counts();$friday=(new DateTimeImmutable('next friday'))->format('Y-m-d');(new RepresentationRequestRepository($db))->create((int)$a['id'],$friday);$afterRequest=$counts();
$ok($afterRequest===['requests'=>$before['requests']+1,'offers'=>$before['offers'],'dates'=>$before['dates']],'Gesuch schreibt ausschließlich representation_requests.');
$monday=(new DateTimeImmutable('next monday'))->format('Y-m-d');(new RepresentationOfferRepository($db))->createMany((int)$a['id'],[2],false,[$monday]);$afterOffer=$counts();
$ok($afterOffer===['requests'=>$afterRequest['requests'],'offers'=>$afterRequest['offers']+1,'dates'=>$afterRequest['dates']+1],'Angebot schreibt ausschließlich Offer-Tabellen.');
$checks=[
 'orphan offer dates'=>'SELECT COUNT(*) FROM representation_offer_dates d LEFT JOIN representation_offers o ON o.id=d.offer_id WHERE o.id IS NULL',
 'orphan offer contacts'=>'SELECT COUNT(*) FROM representation_contact_log l LEFT JOIN representation_offers o ON o.id=l.offer_id WHERE o.id IS NULL',
 'orphan request contacts'=>'SELECT COUNT(*) FROM representation_request_contact_log l LEFT JOIN representation_requests r ON r.id=l.request_id WHERE r.id IS NULL',
 'invalid request orgs'=>'SELECT COUNT(*) FROM representation_requests r LEFT JOIN organizations o ON o.org_id=r.org_id WHERE o.org_id IS NULL',
 'invalid offer orgs'=>'SELECT COUNT(*) FROM representation_offers r LEFT JOIN organizations o ON o.org_id=r.org_id WHERE o.org_id IS NULL',
 'invalid verification states'=>"SELECT COUNT(*) FROM users WHERE bni_verification_status NOT IN ('unverified','directory_match','manual_verified')",
 'blank selectable chapters'=>"SELECT COUNT(*) FROM organizations WHERE org_type='CHAPTER' AND NULLIF(TRIM(chapter_name),'') IS NULL",
 'duplicate pending invitations'=>"SELECT COUNT(*) FROM (SELECT lower(email) FROM user_invitations WHERE status='pending' AND expires_at > '" . gmdate('Y-m-d\\TH:i:s\\Z') . "' GROUP BY lower(email) HAVING COUNT(*)>1) pending_duplicates",
 'pending invitation for active user'=>"SELECT COUNT(*) FROM user_invitations i JOIN users u ON lower(u.email)=lower(i.email) AND u.status='active' WHERE i.status='pending' AND i.expires_at > '" . gmdate('Y-m-d\\TH:i:s\\Z') . "'",
];
foreach($checks as$name=>$sql)$ok((int)$db->query($sql)->fetchColumn()===0,$name);
$settings=new MailSettingsRepository($db);$mail=new MailService($settings,static function():void{});$service=new InvitationService(new InvitationRepository($db),$users,$settings,$mail);$admin=$users->findByLogin('admin');$service->invite(['first_name'=>'Ina','last_name'=>'Invite','email'=>'invite@example.test','home_chapter_org_id'=>1],(int)$admin['id']);$duplicate=false;try{$service->invite(['first_name'=>'Ina','last_name'=>'Invite','email'=>'INVITE@example.test','home_chapter_org_id'=>1],(int)$admin['id']);}catch(DomainException){$duplicate=true;}$ok($duplicate,'Doppelte aktive Einladung wird fachlich verhindert.');
echo "PASS Database Integrity: Requests/Offers getrennt, FKs, Referenzen, Status und Einladungen\n";
