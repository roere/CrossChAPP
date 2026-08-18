<?php

declare(strict_types=1);

$app = is_file(__DIR__ . '/../app/src/Database.php') ? __DIR__ . '/../app' : '/var/www/html';
require_once $app . '/src/Database.php';
require_once $app . '/src/UserRepository.php';
require_once $app . '/src/RepresentationOfferRepository.php';

$path = sys_get_temp_dir() . '/crosschapp-representation-' . bin2hex(random_bytes(5)) . '.sqlite';
try {
    $database = (new Database($path))->connection(); $now = gmdate('Y-m-d\TH:i:s\Z');
    $organization = $database->prepare("INSERT INTO organizations (org_id, org_type, chapter_name, created_at, updated_at) VALUES (?, 'CHAPTER', ?, ?, ?)");
    foreach ([101 => 'Chapter X', 202 => 'Chapter Y', 303 => 'Chapter Z'] as $id => $name) $organization->execute([$id, $name, $now, $now]);
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

    $matches = $offers->findForHomeChapter(101, (int) $a['id'], '2099-08-20');
    assert(count($matches) === 1 && $matches[0]['providerName'] === 'Bernd B.' && !array_key_exists('email', $matches[0]));
    assert($offers->findForHomeChapter(101, (int) $b['id'], '2099-08-20') === []);
    assert(!$offers->deleteForUser($alwaysIds[0], (int) $a['id']));
    assert($offers->deleteForUser($alwaysIds[0], (int) $b['id']));

    echo "PASS representation offers: Einzelangebote, Transaktion, Dubletten, Ownership und Suche\n";
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
