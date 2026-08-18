<?php

declare(strict_types=1);

require_once '/var/www/html/src/Database.php';

$database = (new Database())->connection();
$emails = ['representation-a@example.invalid', 'representation-b@example.invalid', 'representation-c@example.invalid'];
$action = getenv('TEST_ACTION');
if (!in_array($action, ['setup', 'cleanup'], true)) { echo "SKIP representation browser fixture (TEST_ACTION fehlt)\n"; exit; }
$delete = $database->prepare('DELETE FROM users WHERE email IN (?, ?, ?)');
$delete->execute($emails);
if ($action === 'cleanup') { echo "PASS fixture cleanup\n"; exit; }
$chapters = $database->query("SELECT org_id, chapter_name FROM organizations WHERE org_type = 'CHAPTER' AND chapter_name IS NOT NULL ORDER BY org_id LIMIT 4")->fetchAll();
if (count($chapters) !== 4) throw new RuntimeException('Vier lokale Chapter werden für den Test benötigt.');
$insert = $database->prepare("INSERT INTO users (first_name,last_name,email,password_hash,home_chapter_org_id,role,status,email_verified_at,created_at,updated_at) VALUES (?,?,?,?,?,'user','active',?,?,?)");
$now = gmdate('Y-m-d\TH:i:s\Z');
$insert->execute(['Anna', 'Alpha', $emails[0], password_hash('representation-test-123', PASSWORD_DEFAULT), $chapters[0]['org_id'], $now, $now, $now]);
$insert->execute(['Bernd', 'Beta', $emails[1], password_hash('representation-test-123', PASSWORD_DEFAULT), $chapters[3]['org_id'], $now, $now, $now]);
$insert->execute(['Carla', 'Chapterlos', $emails[2], password_hash('representation-test-123', PASSWORD_DEFAULT), null, $now, $now, $now]);
echo json_encode(['chapterIds' => array_map(static fn (array $chapter): int => (int) $chapter['org_id'], array_slice($chapters, 0, 3)), 'homeChapterB' => (int) $chapters[3]['org_id']], JSON_UNESCAPED_UNICODE);
