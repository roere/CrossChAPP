<?php
declare(strict_types=1);
$root=is_file(__DIR__.'/../app/src/Database.php')?__DIR__.'/../app':'/var/www/html';require_once $root.'/src/Database.php';require_once $root.'/src/LegalSettingsRepository.php';
$check=static function(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);};
$database=(new Database(':memory:'))->connection();$repository=new LegalSettingsRepository($database);$defaults=$repository->settings();
$check(str_contains($defaults['imprintText'],'Bitte vor Veröffentlichung')&&str_contains($defaults['privacyText'],'DATENSCHUTZERKLÄRUNG')&&str_contains($defaults['privacyText'],'technisch notwendiges Session-Cookie'),'Rechtliche Dummy-/Ausgangstexte sind vorhanden.');
$imprint="Test Impressum\n<script>window.legalXss=true</script>";$privacy="Test Datenschutz\nZweite Zeile";$repository->save($imprint,$privacy);$reloaded=(new LegalSettingsRepository($database))->settings();
$check($reloaded['imprintText']===$imprint&&$reloaded['privacyText']===$privacy,'Rechtliche Texte bleiben mit Zeilenumbrüchen persistent.');
$rendered=htmlspecialchars($reloaded['imprintText'],ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');$check(str_contains($rendered,'&lt;script&gt;')&&!str_contains($rendered,'<script>'),'Rechtstext wird für die View HTML-escaped.');
echo "PASS Rechtliche Texte: Defaults, Persistenz, Zeilenumbrüche und XSS-Schutz\n";
