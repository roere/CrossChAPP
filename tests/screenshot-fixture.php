<?php

declare(strict_types=1);

require_once '/var/www/html/src/Database.php';
require_once '/var/www/html/src/UserRepository.php';
require_once '/var/www/html/src/RepresentationOfferRepository.php';

if (getenv('CROSSCHAPP_TEST_MODE') !== '1') throw new RuntimeException('Screenshot-Fixtures dürfen nur im Testmodus laufen.');

$database = (new Database())->connection();
$now = gmdate('Y-m-d\TH:i:s\Z');
$database->exec("UPDATE organizations SET chapter_name='Königsforst BNI (Overath)', region='Bergisches Land', city='Rösrath-Forsbach', postal_code='51503' WHERE org_id=910001");
$database->exec("UPDATE organizations SET chapter_name='Rheinblick BNI', region='Köln', city='Köln', postal_code='50667' WHERE org_id=910002");
$database->exec("UPDATE organizations SET chapter_name='Alpenblick BNI', region='Wien', city='Wien', postal_code='1010' WHERE org_id=910003");
$database->exec("UPDATE organizations SET org_type='CHAPTER', chapter_name='Domstadt BNI', region='Bonn/Rhein-Sieg', city='Bonn', postal_code='53111', meeting_day='Mittwoch', meeting_time='07:15' WHERE org_id=910004");
$database->exec("UPDATE users SET first_name='Anna', last_name='Beispiel', email='anna.beispiel@example.test' WHERE email='check-a@example.test'");
$database->exec("UPDATE users SET first_name='Ben', last_name='Muster', email='ben.muster@example.test' WHERE email='check-b@example.test'");

$users = new UserRepository($database);
$anna = $users->findByLogin('anna.beispiel@example.test');
$ben = $users->findByLogin('ben.muster@example.test');
$clara = $users->create('Clara', 'Beispiel', 'clara.beispiel@example.test', password_hash('screenshot-password-123', PASSWORD_DEFAULT), 910003, 'directory_match');
$database->prepare("UPDATE users SET status='active', email_verified_at=:now WHERE id=:id")->execute([':now' => $now, ':id' => $clara['id']]);

$nextFriday = (new DateTimeImmutable('next friday', new DateTimeZone('Europe/Berlin')))->format('Y-m-d');
$offers = new RepresentationOfferRepository($database);
$offers->createMany((int) $ben['id'], [910001], false, [$nextFriday]);
$offers->createMany((int) $clara['id'], [910001], true, []);

echo json_encode([
    'annaId' => (int) $anna['id'],
    'requestDate' => $nextFriday,
], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), "\n";
