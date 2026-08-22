<?php

declare(strict_types=1);

$root=is_file(__DIR__.'/../app/src/Database.php')?__DIR__.'/../app':'/var/www/html';
require_once $root.'/src/Database.php';
require_once $root.'/src/MailSettingsRepository.php';
require_once $root.'/src/TextTemplateCatalog.php';
$check=static function(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);};

$expected=['verify_email','reset_password','user_invitation','representation_contact','representation_request_contact','request_contact_acceptance','offer_contact_acceptance','representation_assignment_confirmed_requester','representation_assignment_confirmed_representative','representation_assignment_cancelled_requester','representation_assignment_cancelled_representative','contact_hint','request_contact_hint','offer_custom_message','request_custom_message'];
$definitions=TextTemplateCatalog::definitions();
$check(count($definitions)===15&&array_keys($definitions)===$expected,'Das Textbaustein-Inventar ist nicht vollständig oder falsch sortiert.');
$check(count(array_unique(array_keys($definitions)))===15&&!isset($definitions['{{custom_message}}']),'Dubletten oder Platzhalter als Template-Key gefunden.');
foreach($definitions as$key=>$definition){$check(trim($definition['label'])!==''&&trim($definition['description'])!==''&&trim($definition['category'])!=='',"Metadaten fehlen für {$key}.");}
$mapped=array_filter($definitions,static fn(array $definition):bool=>$definition['placeholderName']!==null);
$check(array_keys($mapped)===['offer_custom_message','request_custom_message'],'Es wurden fehlende oder erfundene Textbaustein-Platzhalter definiert.');
foreach($mapped as$key=>$definition)$check($definition['placeholderName']==='{{custom_message}}'&&str_contains($definition['description'],'{{custom_message}}'),"Die Platzhalterzuordnung für {$key} entspricht nicht der realen Verwendung.");
$check(str_contains($definitions['request_contact_acceptance']['description'],'Ersteller eines Vertretungsgesuchs')&&str_contains($definitions['offer_contact_acceptance']['description'],'Anbieter eines Vertretungsangebots'),'Kontaktbeschreibungen entsprechen nicht der realen Empfängerrichtung.');
foreach(['representation_assignment_confirmed_requester','representation_assignment_confirmed_representative','representation_assignment_cancelled_requester','representation_assignment_cancelled_representative']as$key)$check(str_contains($definitions[$key]['description'],'mail'),'Assignment-Beschreibung fehlt.');

$db=(new Database(':memory:'))->connection();$repository=new MailSettingsRepository($db);$blocks=$repository->textBlocks();
$check(count($blocks)===15&&count(array_unique(array_column($blocks,'key')))===15,'Geladene Textbausteine sind unvollständig.');
$original=$repository->templates()['verify_email'];$repository->saveTextBlock('verify_email','Test {{app_name}}',$original['body']);$saved=$repository->templates()['verify_email'];
$check($saved['subject']==='Test {{app_name}}'&&$saved['body']===$original['body'],'Einzelnes E-Mail-Template wurde nicht gezielt gespeichert.');
try{$repository->saveTextBlock('verify_email','Nicht erlaubt {{acceptance_link}}',$original['body']);throw new RuntimeException('Nicht erlaubter Platzhalter akzeptiert.');}catch(InvalidArgumentException $exception){$check(str_contains($exception->getMessage(),'nicht erlaubt'),'Falscher Platzhalterfehler.');}
try{$repository->saveTextBlock('{{custom_message}}',null,'Text');throw new RuntimeException('Platzhalter wurde als Key akzeptiert.');}catch(InvalidArgumentException){}

echo "PASS Textbausteine: 15 Keys, 2 reale Referenzplatzhalter, Einzel-Speichern und serverseitige Platzhaltervalidierung\n";
