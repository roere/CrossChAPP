<?php
declare(strict_types=1);
$path=getenv('CROSSCHAPP_MAIL_CAPTURE_PATH');if(!is_string($path)||!is_file($path))throw new RuntimeException('Mock-Mail-Capture fehlt.');
$lines=file($path,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES)?:[];if($lines===[])throw new RuntimeException('Smoke-Test hat keine Mock-Mail erzeugt.');
foreach($lines as$line){$mail=json_decode($line,true,16,JSON_THROW_ON_ERROR);if(!str_ends_with((string)$mail['to'],'@example.test'))throw new RuntimeException('Mail ging nicht an eine Testadresse.');if(str_contains((string)$mail['subject'].(string)$mail['body'],'{{'))throw new RuntimeException('Mock-Mail enthält rohe Platzhalter.');}
echo 'PASS Mail Guard: '.count($lines)." Mail(s) ausschließlich im lokalen Capture, keine Rohplatzhalter\n";
