<?php

declare(strict_types=1);

$app = is_file(__DIR__ . '/../app/src/Database.php') ? __DIR__ . '/../app' : '/var/www/html';
require_once $app . '/src/Database.php';
require_once $app . '/src/UserRepository.php';
require_once $app . '/src/RepresentationOfferRepository.php';

$path = sys_get_temp_dir() . '/crosschapp-representation-' . bin2hex(random_bytes(5)) . '.sqlite';
try {
    $database = (new Database($path))->connection(); $now = gmdate('Y-m-d\TH:i:s\Z');
    $organization = $database->prepare("INSERT INTO organizations (org_id, org_type, chapter_name, meeting_day, timezone, created_at, updated_at) VALUES (?, 'CHAPTER', ?, ?, 'Europe/Berlin', ?, ?)");
    foreach ([101 => ['Chapter X','Freitag'], 202 => ['Chapter Y','Freitag'], 303 => ['Chapter Z','Freitag'], 404 => ['Chapter Mittwoch','Mittwoch']] as $id => [$name,$day]) $organization->execute([$id, $name, $day, $now, $now]);
    $users = new UserRepository($database);
    $a = $users->create('Anna', 'Alpha', 'anna@example.invalid', password_hash('password1', PASSWORD_DEFAULT), 101);
    $b = $users->create('Bernd', 'Beta', 'bernd@example.invalid', password_hash('password2', PASSWORD_DEFAULT), 202);
    $database->exec("UPDATE users SET status = 'active', email_verified_at = '$now'");
    $offers = new RepresentationOfferRepository($database);

    $alwaysIds = $offers->createMany((int) $b['id'], [101, 303], true, []);
    assert(count($alwaysIds) === 2);
    $own = $offers->forUser((int) $b['id']);
    assert(count($own) === 2 && count(array_unique(array_column($own, 'orgId'))) === 2);
    assert(array_reduce($own, static fn (bool $valid, array $offer): bool => $valid && $offer['allDates'] && isset($offer['chapterName']), true));

    $datedIds = $offers->createMany((int) $b['id'], [202, 303], false, ['2099-08-28', '2099-08-21']);
    assert(count($datedIds) === 2);
    foreach (array_filter($offers->forUser((int) $b['id']), static fn (array $offer): bool => !$offer['allDates']) as $offer) assert($offer['dates'] === ['2099-08-21', '2099-08-28']);

    $beforeDuplicate = count($offers->forUser((int) $b['id']));
    try { $offers->createMany((int) $b['id'], [202], false, ['2099-08-21', '2099-08-28']); assert(false); } catch (DomainException $exception) { assert($exception->getMessage() === 'duplicate_offer'); }
    assert(count($offers->forUser((int) $b['id'])) === $beforeDuplicate);
    try { $offers->createMany((int) $b['id'], [101, 202], true, []); assert(false); } catch (DomainException) {}
    assert(count($offers->forUser((int) $b['id'])) === $beforeDuplicate); // Kein Teil-Insert für 202.

    $beforeMismatch = [(int) $database->query('SELECT COUNT(*) FROM representation_offers')->fetchColumn(), (int) $database->query('SELECT COUNT(*) FROM representation_offer_dates')->fetchColumn()];
    try { $offers->createMany((int) $b['id'], [202], false, ['2099-08-26']); assert(false); } catch (InvalidArgumentException $exception) { assert(str_contains($exception->getMessage(), 'Chapter Y')); }
    assert([(int) $database->query('SELECT COUNT(*) FROM representation_offers')->fetchColumn(), (int) $database->query('SELECT COUNT(*) FROM representation_offer_dates')->fetchColumn()] === $beforeMismatch);
    try { $offers->createMany((int) $b['id'], [101, 404], false, ['2099-08-28']); assert(false); } catch (InvalidArgumentException $exception) { assert(str_contains($exception->getMessage(), 'Chapter Mittwoch')); }
    assert([(int) $database->query('SELECT COUNT(*) FROM representation_offers')->fetchColumn(), (int) $database->query('SELECT COUNT(*) FROM representation_offer_dates')->fetchColumn()] === $beforeMismatch);

    $matches = $offers->findForHomeChapter(101, (int) $a['id'], '2099-08-20');
    assert(count($matches['allDatesOffers']) === 1 && $matches['allDatesOffers'][0]['displayName'] === 'Bernd B.' && $matches['allDatesOffers'][0]['verificationStatus']==='unverified' && !array_key_exists('email', $matches['allDatesOffers'][0]));
    assert($offers->findForHomeChapter(101, (int) $b['id'], '2099-08-20') === ['datedOffers' => [], 'allDatesOffers' => []]);
    $unverified=$users->create('Aaron','Ohne','priority-unverified@example.invalid',password_hash('password3',PASSWORD_DEFAULT),202);
    $manualA=$users->create('Zed','Verifiziert','priority-manual-z@example.invalid',password_hash('password4',PASSWORD_DEFAULT),202,'manual_verified');
    $directory=$users->create('Mia','Directory','priority-directory@example.invalid',password_hash('password5',PASSWORD_DEFAULT),202,'directory_match');
    $manualB=$users->create('Yara','Verifiziert','priority-manual-y@example.invalid',password_hash('password6',PASSWORD_DEFAULT),202,'manual_verified');
    $database->exec("UPDATE users SET status='active',email_verified_at='$now'");
    foreach([$unverified,$manualA,$directory,$manualB]as$provider)$offers->createMany((int)$provider['id'],[101],false,['2099-08-28']);
    $prioritized=$offers->findForHomeChapter(101,(int)$a['id'],'2099-08-20');$providers=$prioritized['datedOffers'][0]['providers'];
    assert(array_column($providers,'displayName')===['Yara V.','Zed V.','Mia D.','Aaron O.','Bernd B.']);
    assert(array_column($providers,'verificationStatus')===['manual_verified','manual_verified','directory_match','unverified','unverified']);
    assert(array_column($prioritized['datedOffers'],'date')===['2099-08-28']);
    $overview=$offers->overviewForHomeChapter(101,(int)$a['id'],'2099-08-20');assert(array_slice(array_column($overview,'verificationStatus'),0,3)===['manual_verified','manual_verified','directory_match']);
    assert(!$offers->deleteForUser($alwaysIds[0], (int) $a['id']));
    assert($offers->deleteForUser($alwaysIds[0], (int) $b['id']));

    echo "PASS representation offers: Meetingtage, Multi-Chapter-Rollback, Dubletten, Ownership und Suche\n";
} finally { if (is_file($path)) unlink($path); }

$migrationPath = sys_get_temp_dir() . '/crosschapp-representation-migration-' . bin2hex(random_bytes(5)) . '.sqlite';
try {
    $database = (new Database($migrationPath))->connection(); $now = gmdate('Y-m-d\TH:i:s\Z');
    $database->exec("INSERT INTO organizations (org_id,org_type,chapter_name,created_at,updated_at) VALUES (1,'CHAPTER','Eins','$now','$now'),(2,'CHAPTER','Zwei','$now','$now')");
    $user = (new UserRepository($database))->create('Legacy', 'User', 'legacy@example.invalid', password_hash('password1', PASSWORD_DEFAULT), null);
    $database->exec('DROP TABLE representation_offer_dates'); $database->exec('DROP TABLE representation_offer_chapters'); $database->exec('DROP TABLE representation_offers');
    $database->exec('CREATE TABLE representation_offers (id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER NOT NULL,all_dates INTEGER NOT NULL DEFAULT 0,created_at TEXT NOT NULL,updated_at TEXT NOT NULL)');
    $database->exec('CREATE TABLE representation_offer_chapters (id INTEGER PRIMARY KEY AUTOINCREMENT,offer_id INTEGER NOT NULL,org_id INTEGER NOT NULL,UNIQUE(offer_id,org_id))');
    $database->exec('CREATE TABLE representation_offer_dates (id INTEGER PRIMARY KEY AUTOINCREMENT,offer_id INTEGER NOT NULL,offer_date TEXT NOT NULL,UNIQUE(offer_id,offer_date))');
    $database->exec("INSERT INTO representation_offers (user_id,all_dates,created_at,updated_at) VALUES ({$user['id']},0,'$now','$now')");
    $database->exec("INSERT INTO representation_offer_chapters (offer_id,org_id) VALUES (1,1),(1,2)");
    $database->exec("INSERT INTO representation_offer_dates (offer_id,offer_date) VALUES (1,'2099-09-01')");
    $database = (new Database($migrationPath))->connection();
    $migrated = (new RepresentationOfferRepository($database))->forUser((int) $user['id']);
    assert(count($migrated) === 2 && array_column($migrated, 'orgId') === [2, 1]);
    assert($migrated[0]['dates'] === ['2099-09-01'] && $migrated[1]['dates'] === ['2099-09-01']);
    echo "PASS representation migration: Multi-Chapter-Bucket wurde verlustfrei aufgeteilt\n";
} finally { if (is_file($migrationPath)) unlink($migrationPath); }
