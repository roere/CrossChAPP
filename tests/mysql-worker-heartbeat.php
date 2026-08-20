<?php

declare(strict_types=1);

require_once '/var/www/html/src/AutomationRepository.php';
require_once '/var/www/html/src/Database.php';
require_once '/var/www/html/src/WorkerHeartbeat.php';

$check = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$database = (new Database())->connection();
$automation = new AutomationRepository($database);
$automation->updateWorkerRuntime(60);
$cycle = $database->query('SELECT worker_last_seen_at,last_check_at,next_check_at FROM automation_runtime WHERE id=1')->fetch();
$check(strtotime((string) $cycle['next_check_at']) - strtotime((string) $cycle['last_check_at']) === 3600, 'MariaDB next_check_at ist +60 Minuten.');
$database->exec("UPDATE automation_runtime SET worker_last_seen_at='2020-01-01T00:00:00Z',last_check_at='2026-08-20T10:00:00Z',next_check_at='2026-08-20T11:00:00Z' WHERE id=1");
$heartbeats = 0; $sleeps = [];
WorkerHeartbeat::wait(95, static function () use ($automation, &$heartbeats): void { $automation->updateWorkerHeartbeat(); $heartbeats++; }, static function (int $seconds) use (&$sleeps): void { $sleeps[] = $seconds; });
$runtime = $database->query('SELECT worker_last_seen_at,last_check_at,next_check_at FROM automation_runtime WHERE id=1')->fetch();
$check($heartbeats === 4 && $sleeps === [30,30,30,5], 'MariaDB Heartbeat spätestens alle 30 Sekunden.');
$check($runtime['worker_last_seen_at'] !== '2020-01-01T00:00:00Z', 'MariaDB Heartbeat wurde geschrieben.');
$check($runtime['last_check_at'] === '2026-08-20T10:00:00Z' && $runtime['next_check_at'] === '2026-08-20T11:00:00Z', 'MariaDB Cycle-Zeitpunkte bleiben unverändert.');
echo "PASS MariaDB Worker-Heartbeat: 30 Sekunden, Cycle-Zeitpunkte stabil\n";
