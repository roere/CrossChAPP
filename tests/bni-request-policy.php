<?php

declare(strict_types=1);

$appRoot = is_file(__DIR__ . '/../app/src/Database.php') ? __DIR__ . '/../app' : '/var/www/html';
require_once $appRoot . '/src/BniRequestPolicy.php';
require_once $appRoot . '/src/Database.php';
require_once $appRoot . '/src/HttpClient.php';
require_once $appRoot . '/src/OrganizationRepository.php';

$check = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$check(BniRequestPolicy::DETAIL_DELAY_MS === 1500, 'Zentraler Mindestabstand.');
$check(BniRequestPolicy::stopReason(429) === 'rate_limited', 'HTTP 429 stoppt wegen Rate-Limit.');
$check(BniRequestPolicy::stopReason(403) === 'forbidden', 'HTTP 403 stoppt wegen Zugriffsschutz.');
$check(BniRequestPolicy::stopReason(500) === null, 'HTTP 500 stoppt den Batch nicht global.');
$check(HttpClient::retryAfterSeconds(['Retry-After: 120'], 1_700_000_000) === 120, 'Retry-After als Sekundenwert.');
$check(
    HttpClient::retryAfterSeconds(['Retry-After: Tue, 14 Nov 2023 22:15:20 GMT'], 1_700_000_000) === 120,
    'Retry-After als HTTP-Datum.',
);

$repository = new OrganizationRepository((new Database(':memory:'))->connection());
$repository->upsertMapOrganizations([
    ['orgId' => 1, 'cmsSecurityHash' => 'one', 'countryCode' => 'DE', 'orgType' => 'CHAPTER', 'longitude' => 7, 'latitude' => 50],
    ['orgId' => 2, 'cmsSecurityHash' => 'two', 'countryCode' => 'DE', 'orgType' => 'CHAPTER', 'longitude' => 7, 'latitude' => 50],
]);
$repository->saveDetails(1, ['chapterName' => 'Bereits gespeichert']);
$repository->markDetailError(2);
$repository->markDetailNotLoaded(1);
$repository->markDetailNotLoaded(2);
$check($repository->find(1)['detailStatus'] === 'loaded', 'Bereits geladene Chapter bleiben erhalten.');
$check($repository->find(2)['detailStatus'] === 'not_loaded', '429-/403-Kandidat bleibt erneut importierbar.');
$check($repository->findPendingChapters(10)[0]['orgId'] === 2, 'Temporär blockierter Datensatz wird erneut Kandidat.');

echo "PASS BNI-Request-Policy: Delay, 429, 403, Retry-After und fortsetzbarer Status\n";
