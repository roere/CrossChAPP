<?php

declare(strict_types=1);

$appRoot = is_file(__DIR__ . '/../app/src/Database.php') ? __DIR__ . '/../app' : '/var/www/html';
require_once $appRoot . '/src/Database.php';
require_once $appRoot . '/src/BniGlobalThrottle.php';

/** Separate Verbindungen ohne Schema-/Seed-Nebenwirkungen für echte Parallelität. */
$mysqlConnection = static fn (): PDO => new PDO(
        sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            getenv('CROSSCHAPP_DB_HOST') ?: 'db',
            (int) (getenv('CROSSCHAPP_DB_PORT') ?: 3306),
            getenv('CROSSCHAPP_DB_NAME') ?: 'crosschapp',
        ),
        getenv('CROSSCHAPP_DB_USER') ?: 'crosschapp',
        (string) (getenv('CROSSCHAPP_DB_PASSWORD') ?: ''),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false],
    );

if (($argv[1] ?? '') === '--worker') {
    $database = $mysqlConnection();
    $measurement=(new BniGlobalThrottle($database))->awaitStartSlotMeasurement((string) ($argv[2] ?? 'test'));$startedMs=(int)floor(microtime(true)*1000);
    echo json_encode(['type' => $argv[2] ?? 'test', 'startedMs' => $startedMs,'waitMs'=>$startedMs-$measurement['reserved_at_ms']], JSON_THROW_ON_ERROR), "\n";
    exit;
}

$check = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

// Deterministische SQLite-Prüfung der Slotarithmetik und des kurzen Locks.
$sqlite = (new Database(':memory:'))->connection();
$now = 1_000_000;
$waits = [];
$throttle = new BniGlobalThrottle(
    $sqlite,
    static function () use (&$now): int { return $now; },
    static function (int $milliseconds) use (&$now, &$waits, $sqlite): void {
        if ($sqlite->inTransaction()) throw new RuntimeException('Die Wartezeit liegt innerhalb einer DB-Transaktion.');
        $waits[] = $milliseconds;
        $now += $milliseconds;
    },
);
$slots = [];
foreach (['usage_search', 'automatic', 'manual', 'member_verification'] as $type) {
    $slots[] = $throttle->awaitStartSlot($type);
}
$check($slots === [1_000_000, 1_001_600, 1_003_200, 1_004_800], 'SQLite reserviert keine globale Slotfolge mit 1500-ms-Mindestabstand und Schedulerreserve.');
$check($waits === [1600, 1600, 1600], 'SQLite wartet nicht außerhalb des Locks bis zum reservierten Slot.');

// Vier echte PHP-Prozesse teilen dieselbe MariaDB-Zeile.
$database = $mysqlConnection();
$database->exec('CREATE TABLE IF NOT EXISTS bni_request_throttle(id INT PRIMARY KEY,last_reserved_start_ms BIGINT NOT NULL) ENGINE=InnoDB');
$database->exec('INSERT IGNORE INTO bni_request_throttle(id,last_reserved_start_ms) VALUES(1,0)');
$database->exec('UPDATE bni_request_throttle SET last_reserved_start_ms=0 WHERE id=1');
$processes = [];
foreach (['usage_search', 'automatic', 'manual', 'member_verification'] as $type) {
    $pipes = [];
    $process = proc_open([PHP_BINARY, __FILE__, '--worker', $type], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Throttle-Testprozess konnte nicht gestartet werden.');
    $processes[] = [$process, $pipes, $type];
}
$starts = [];$measuredWaits=[];
foreach ($processes as [$process, $pipes, $type]) {
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $status = proc_close($process);
    if (!str_starts_with(trim($stdout), '{') || trim($stderr) !== '') throw new RuntimeException("Throttle-Testprozess {$type} fehlgeschlagen (Status {$status}): {$stderr}{$stdout}");
    $row = json_decode(trim($stdout), true, 8, JSON_THROW_ON_ERROR);
    $starts[] = (int) $row['startedMs'];
    $measuredWaits[]=(int)$row['waitMs'];
}
sort($starts);
$gaps = [];
for ($index = 1; $index < count($starts); $index++) $gaps[] = $starts[$index] - $starts[$index - 1];
$check(count($gaps) === 3 && min($gaps) >= 1490, 'Parallele Prozesse starteten mit weniger als 1490 ms Toleranzabstand: ' . implode(',', $gaps));
$sortedWaits=$measuredWaits;sort($sortedWaits);$check($sortedWaits[0]<100&&$sortedWaits[1]>=1490&&$sortedWaits[2]>=$sortedWaits[1]+1490&&$sortedWaits[3]>=$sortedWaits[2]+1490,'Parallele Wartezeiten steigen nicht entsprechend der Slotfolge: '.implode(',',$sortedWaits));

echo 'PASS globaler BNI-Throttle SQLite/MariaDB; gemessene Startabstände: ', implode(' ms, ', $gaps),' ms; Wartezeiten: ',implode(' ms, ',$sortedWaits), " ms\n";
