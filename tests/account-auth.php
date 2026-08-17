<?php
declare(strict_types=1);
$root = is_file(__DIR__ . '/../app/src/Database.php') ? __DIR__ . '/../app' : '/var/www/html';
foreach (['Database','UserRepository','MailSettingsRepository','MailService','AccountService'] as $class) require_once $root . '/src/' . $class . '.php';
$check = static function (bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); };
$pdo = (new Database(':memory:'))->connection(); $users = new UserRepository($pdo); $settings = new MailSettingsRepository($pdo); $mails = [];
$mailer = new MailService($settings, static function (string $to, string $name, string $subject, string $body) use (&$mails): void { $mails[] = compact('to','name','subject','body'); });
$service = new AccountService($users, $settings, $mailer);
$pdo->exec("INSERT INTO organizations (org_id,country_code,org_type,created_at,updated_at) VALUES (99,'DE','CHAPTER',CURRENT_TIMESTAMP,CURRENT_TIMESTAMP),(100,'DE','CORE_GROUP',CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)");

$registration = $service->register(['first_name'=>'René','last_name'=>'Röderstein','email'=>'rene@example.test','home_chapter_org_id'=>99,'password'=>'sicher123','password_confirmation'=>'sicher123']);
$user = $registration['user']; $check($user['status']==='pending' && $user['email_verified_at']===null && (int)$user['home_chapter_org_id']===99, 'Registrierung pending mit Heimatchapter.');
$check(password_verify('sicher123',$user['password_hash']) && $user['password_hash']!=='sicher123', 'Passwort ausschließlich gehasht.');
$storedToken = $pdo->query('SELECT token_hash FROM email_verification_tokens')->fetchColumn();
preg_match('/verify=([A-Za-z0-9_-]+)/',$mails[0]['body'],$match); $verifyToken=$match[1]??'';
$check($verifyToken!=='' && $storedToken===hash('sha256',$verifyToken) && !str_contains((string)$storedToken,$verifyToken), 'Verifikationstoken nur gehasht gespeichert.');
$check($service->authenticate('rene@example.test','sicher123')['status']==='pending','Unbestätigter Login abgewiesen.');
$check($service->verifyResult($verifyToken)==='verified' && $service->verifyResult($verifyToken)==='used','Verifikation einmalig mit unterscheidbarem Status.');
$expired = $users->issueToken('email_verification_tokens',(int)$user['id'],3600); $pdo->exec("UPDATE email_verification_tokens SET expires_at='2000-01-01T00:00:00Z' WHERE token_hash='" . hash('sha256',$expired) . "'");
$check($service->verifyResult($expired)==='expired' && $service->verifyResult('ungueltig')==='invalid','Abgelaufener und ungültiger Verifikationstoken unterschieden.');
$check($service->authenticate('rene@example.test','sicher123')['status']==='success','Verifizierter Benutzer kann sich anmelden.');
$check($service->changePassword((int)$user['id'],'geandert123','geandert123'),'Authentifizierte Passwortänderung gespeichert.');
$check($service->authenticate('rene@example.test','sicher123')['status']==='invalid' && $service->authenticate('rene@example.test','geandert123')['status']==='success','Nur geändertes Benutzerpasswort gilt.');
$admin=$users->findByLogin('admin');$check(is_array($admin)&&$admin['role']==='admin'&&$service->authenticate('admin','admin')['status']==='success','Persistenter Entwicklungsadmin initialisiert.');
$check($service->changePassword((int)$admin['id'],'admin-neu-123','admin-neu-123')&&$service->authenticate('admin','admin')['status']==='invalid'&&$service->authenticate('admin','admin-neu-123')['status']==='success','Admin verwendet denselben persistenten Passwortpfad.');

$service->requestPasswordReset('rene@example.test'); preg_match('/reset=([A-Za-z0-9_-]+)/',$mails[1]['body'],$match); $resetToken=$match[1]??'';
$check($resetToken!=='' && $pdo->query('SELECT token_hash FROM password_reset_tokens ORDER BY id DESC LIMIT 1')->fetchColumn()===hash('sha256',$resetToken),'Reset-Token nur gehasht.');
$check($service->resetPassword($resetToken,'neuersicher123','neuersicher123') && !$service->resetPassword($resetToken,'nochmal123','nochmal123'),'Reset-Token einmalig.');
$check($service->authenticate('rene@example.test','sicher123')['status']==='invalid' && $service->authenticate('rene@example.test','neuersicher123')['status']==='success','Nur neues Passwort funktioniert.');

$optional = $service->register(['first_name'=>'Ohne','last_name'=>'Chapter','email'=>'ohne@example.test','password'=>'sicher123','password_confirmation'=>'sicher123']);
$check($optional['user']['home_chapter_org_id']===null,'Heimatchapter optional.');
$invalidChapter=false; try{$service->register(['first_name'=>'F','last_name'=>'N','email'=>'wrong@example.test','home_chapter_org_id'=>100,'password'=>'sicher123','password_confirmation'=>'sicher123']);}catch(InvalidArgumentException){$invalidChapter=true;}$check($invalidChapter,'Nur bestehendes CHAPTER als Heimatchapter.');
$duplicate=false; try{$service->register(['first_name'=>'R','last_name'=>'D','email'=>'RENE@example.test','password'=>'sicher123','password_confirmation'=>'sicher123']);}catch(DomainException){$duplicate=true;}$check($duplicate,'E-Mail eindeutig ohne Groß-/Kleinschreibung.');

for($i=0;$i<5;$i++)$users->recordAttempt('login','User-A@example.test','192.0.2.10',false);
$check($users->rateLimited('login',' user-a@EXAMPLE.test ','192.0.2.10',5,900),'Login-Identifier normalisiert und eigener Bucket gesperrt.');
$check(!$users->rateLimited('login','user-b@example.test','192.0.2.10',5,900) && !$users->rateLimited('login','admin','192.0.2.10',5,900),'User A sperrt weder User B noch Admin derselben IP.');
$check(!$users->rateLimited('login','user-a@example.test','192.0.2.11',5,900),'Gleiche Identität anderer IP besitzt eigenen Bucket.');
for($i=0;$i<5;$i++)$users->recordAttempt('login','admin','192.0.2.10',false);
$check($users->rateLimited('login','ADMIN','192.0.2.10',5,900) && !$users->rateLimited('login','user-c@example.test','192.0.2.10',5,900),'Adminbucket sperrt keinen normalen Benutzer.');
for($i=0;$i<5;$i++)$users->recordAttempt('login','nichtvorhanden@example.test','192.0.2.10',false);
$check($users->rateLimited('login','nichtvorhanden@example.test','192.0.2.10',5,900),'Unbekannte Identität wird begrenzt.');
for($i=0;$i<2;$i++)$users->recordAttempt('login','cleanup@example.test','192.0.2.10',false);
$users->recordAttempt('login',' CLEANUP@example.test ','192.0.2.10',true);
$check(!$users->rateLimited('login','cleanup@example.test','192.0.2.10',1,900) && $users->rateLimited('login','admin','192.0.2.10',5,900),'Erfolg bereinigt nur eigenen Identifier-IP-Bucket.');
for($i=0;$i<5;$i++)$users->recordAttempt('password_reset','reset-a@example.test','192.0.2.10',false);
$check($users->rateLimited('password_reset','reset-a@example.test','192.0.2.10',5,3600) && !$users->rateLimited('password_reset','reset-b@example.test','192.0.2.10',5,3600),'Passwortreset-Limit bleibt identifierbezogen aktiv.');
for($i=0;$i<5;$i++)$users->recordAttempt('resend_verification','verify-a@example.test','192.0.2.10',false);
$check($users->rateLimited('resend_verification','verify-a@example.test','192.0.2.10',5,3600) && !$users->rateLimited('resend_verification','verify-b@example.test','192.0.2.10',5,3600),'Verifikations-Limit bleibt identifierbezogen aktiv.');
$rendered=$settings->render('verify_email',['first_name'=>'René','last_name'=>'Röderstein','email'=>'rene@example.test','verification_link'=>'http://local/verify','app_name'=>'CrossChAPP']);
$check(str_contains($rendered['body'],'http://local/verify')&&!str_contains($rendered['body'],'{{first_name}}'),'Erlaubte Template-Platzhalter ersetzt.');
echo "PASS Accounts: Registrierung, Hashes, Verifikation, Login, Reset, Chapter, Rate-Limit, Templates\n";
