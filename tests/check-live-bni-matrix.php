<?php

declare(strict_types=1);

/**
 * Explicit, controlled live validation for the data-driven BNI member directory.
 * This file is deliberately not part of tests/check-all.sh.
 */

require_once '/var/www/html/src/Database.php';
require_once '/var/www/html/src/BniMemberDirectoryConfigResolver.php';
require_once '/var/www/html/src/BniMemberListClient.php';

const MAX_LIVE_REQUESTS = 16;

if (getenv('CROSSCHAPP_ALLOW_LIVE_BNI') !== '1') {
    fwrite(STDERR, "ACHTUNG: Dieser Test führt reale Requests an öffentliche BNI-Endpunkte aus.\n");
    fwrite(STDERR, "Explizit mit CROSSCHAPP_ALLOW_LIVE_BNI=1 starten.\n");
    exit(2);
}

$selection = [
    5853,  // BNI Baer Berlin
    5854,  // BNI Adler Berlin
    5995,  // Wilhelm Röntgen BNI (Hannover)
    5808,  // Bernstein BNI (München)
    5725,  // Mozart BNI (Wien)
    5740,  // Augustus IX (Tirol)
];

$sourcePath = getenv('CROSSCHAPP_LIVE_SQLITE_PATH') ?: '/var/www/data/bni-dach.sqlite';
$source = new PDO('sqlite:file:' . $sourcePath . '?mode=ro', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$placeholders = implode(',', array_fill(0, count($selection), '?'));
$query = $source->prepare(
    "SELECT org_id, chapter_name, country_code, region, region_id, chapter_url, cms_security_hash
     FROM organizations
     WHERE org_type = 'CHAPTER' AND org_id IN ($placeholders)"
);
$query->execute($selection);
$chaptersById = [];
foreach ($query->fetchAll() as $chapter) {
    $chaptersById[(int) $chapter['org_id']] = $chapter;
}
if (count($chaptersById) !== count($selection)) {
    throw new RuntimeException('Die lokale Stichprobe ist nicht vollständig verfügbar. Es wurden keine Live-Requests ausgeführt.');
}

$temporaryPath = sys_get_temp_dir() . '/crosschapp-live-bni-matrix-' . bin2hex(random_bytes(8)) . '.sqlite';
register_shutdown_function(static function () use ($temporaryPath): void {
    foreach ([$temporaryPath, $temporaryPath . '-wal', $temporaryPath . '-shm'] as $path) {
        if (is_file($path)) {
            unlink($path);
        }
    }
});

$database = (new Database($temporaryPath))->connection();
$insert = $database->prepare(
    "INSERT INTO organizations
     (org_id, chapter_name, country_code, org_type, region, region_id, chapter_url,
      cms_security_hash, detail_status, created_at, updated_at)
     VALUES (:org_id, :chapter_name, :country_code, 'CHAPTER', :region, :region_id,
             :chapter_url, :cms_security_hash, 'loaded', :created_at, :updated_at)"
);
$now = gmdate('Y-m-d\TH:i:s\Z');
foreach ($selection as $orgId) {
    $chapter = $chaptersById[$orgId];
    $insert->execute([
        ':org_id' => $orgId,
        ':chapter_name' => $chapter['chapter_name'],
        ':country_code' => $chapter['country_code'],
        ':region' => $chapter['region'],
        ':region_id' => $chapter['region_id'],
        ':chapter_url' => $chapter['chapter_url'],
        ':cms_security_hash' => $chapter['cms_security_hash'],
        ':created_at' => $now,
        ':updated_at' => $now,
    ]);
}

$requestCount = 0;
$currentOrgId = 0;
$currentPhase = '';
$httpByOrg = [];
$stopStatus = null;
$transport = static function (string $method, string $url, array $data, array $headers) use (
    &$requestCount,
    &$currentOrgId,
    &$currentPhase,
    &$httpByOrg,
    &$stopStatus
): array {
    if ($requestCount >= MAX_LIVE_REQUESTS) {
        throw new RuntimeException('Das harte Limit von 16 echten BNI-Requests wurde erreicht.');
    }
    ++$requestCount;
    $options = [
        'method' => $method,
        'header' => implode("\r\n", $headers),
        'ignore_errors' => true,
        'timeout' => 25,
    ];
    if ($method === 'POST') {
        $options['content'] = http_build_query($data);
    }
    $context = stream_context_create(['http' => $options]);
    $body = @file_get_contents($url, false, $context);
    $responseHeaders = $http_response_header ?? [];
    $status = 0;
    foreach (array_reverse($responseHeaders) as $header) {
        if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $header, $match)) {
            $status = (int) $match[1];
            break;
        }
    }
    $httpByOrg[$currentOrgId][$currentPhase] = $status;
    fwrite(STDOUT, sprintf("REQUEST %02d org=%d phase=%s http=%d\n", $requestCount, $currentOrgId, $currentPhase, $status));
    if ($status === 403 || $status === 429) {
        $stopStatus = $status;
    }
    return ['status' => $status, 'body' => $body === false ? '' : $body, 'headers' => $responseHeaders];
};
$results = [];
foreach ($selection as $orgId) {
    if ($stopStatus !== null) {
        break;
    }
    $currentOrgId = $orgId;
    $currentPhase = 'discovery';
    $resolver = new BniMemberDirectoryConfigResolver($database, $transport, null, null, true);
    $resolved = $resolver->resolve($orgId);
    $discoveryStatus = (string) $resolved['status'];
    if ($discoveryStatus !== 'configured') {
        $http = $httpByOrg[$orgId]['discovery'] ?? 0;
        $errorClass = match (true) {
            $discoveryStatus === 'forbidden' => 'forbidden',
            $discoveryStatus === 'rate_limited' => 'rate_limited',
            $discoveryStatus === 'upstream_error' => 'upstream_error',
            $http >= 200 && $http < 300 => 'discovery_invalid',
            default => 'unavailable',
        };
        $results[$orgId] = ['result' => $errorClass, 'configCount' => 0];
        continue;
    }

    $requestsBeforeSecondResolve = $requestCount;
    $secondResolve = $resolver->resolve($orgId);
    $noRediscovery = $secondResolve['status'] === 'configured' && $requestCount === $requestsBeforeSecondResolve;
    $configCountStatement = $database->prepare('SELECT COUNT(*) FROM bni_member_directory_configs WHERE org_id = :org');
    $configCountStatement->execute([':org' => $orgId]);
    $configCount = (int) $configCountStatement->fetchColumn();

    $currentPhase = 'memberlist';
    $client = new BniMemberListClient($database, $transport, null, null, true);
    $memberResult = $client->fetch($orgId);
    $memberStatus = (string) $memberResult['status'];
    $memberResult['body'] = '';
    unset($memberResult);
    $errorClass = match ($memberStatus) {
        'ok' => 'PASS',
        'forbidden' => 'forbidden',
        'rate_limited' => 'rate_limited',
        'upstream_error' => (($httpByOrg[$orgId]['memberlist'] ?? 0) >= 200 && ($httpByOrg[$orgId]['memberlist'] ?? 0) < 300)
            ? 'invalid_member_structure'
            : 'upstream_error',
        default => 'unavailable',
    };
    $results[$orgId] = [
        'result' => ($errorClass === 'PASS' && $configCount === 1 && $noRediscovery) ? 'PASS' : $errorClass,
        'configCount' => $configCount,
        'noRediscovery' => $noRediscovery,
    ];
}

foreach ($selection as $orgId) {
    $chapter = $chaptersById[$orgId];
    $result = $results[$orgId] ?? ['result' => 'not_run', 'configCount' => 0, 'noRediscovery' => false];
    fwrite(STDOUT, json_encode([
        'country' => $chapter['country_code'],
        'region' => $chapter['region'],
        'chapter' => $chapter['chapter_name'],
        'orgId' => $orgId,
        'discoveryHttp' => $httpByOrg[$orgId]['discovery'] ?? null,
        'memberlistHttp' => $httpByOrg[$orgId]['memberlist'] ?? null,
        'configCount' => $result['configCount'],
        'noRediscovery' => $result['noRediscovery'] ?? false,
        'result' => $result['result'],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
}
fwrite(STDOUT, "TOTAL_REQUESTS=$requestCount\n");
fwrite(STDOUT, 'STOP_STATUS=' . ($stopStatus ?? 'none') . "\n");

$failed = array_filter($results, static fn (array $result): bool => $result['result'] !== 'PASS');
exit($stopStatus !== null || $failed !== [] || count($results) !== count($selection) ? 1 : 0);
