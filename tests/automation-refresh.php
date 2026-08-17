<?php

declare(strict_types=1);

$appRoot = is_file(__DIR__ . '/../app/src/Database.php') ? __DIR__ . '/../app' : '/var/www/html';
require_once $appRoot . '/src/AutomationRepository.php';
require_once $appRoot . '/src/AutomaticRefreshRunner.php';
require_once $appRoot . '/src/BniClient.php';
require_once $appRoot . '/src/ChapterRefreshService.php';
require_once $appRoot . '/src/ChapterSearchService.php';
require_once $appRoot . '/src/Database.php';
require_once $appRoot . '/src/HttpClient.php';
require_once $appRoot . '/src/OrganizationRepository.php';

$check = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$database = (new Database(':memory:'))->connection();
$organizations = new OrganizationRepository($database);
$automation = new AutomationRepository($database);
$organizations->upsertMapOrganizations([
    ['orgId' => 1, 'cmsSecurityHash' => 'one', 'countryCode' => 'DE', 'orgType' => 'CHAPTER', 'longitude' => 7, 'latitude' => 50],
    ['orgId' => 2, 'cmsSecurityHash' => 'two', 'countryCode' => 'DE', 'orgType' => 'CHAPTER', 'longitude' => 7, 'latitude' => 50],
    ['orgId' => 3, 'cmsSecurityHash' => 'core', 'countryCode' => 'DE', 'orgType' => 'CORE_GROUP', 'longitude' => 7, 'latitude' => 50],
    ['orgId' => 4, 'cmsSecurityHash' => 'four', 'countryCode' => 'DE', 'orgType' => 'CHAPTER', 'longitude' => 7, 'latitude' => 50],
]);
$organizations->saveDetails(1, ['chapterName' => 'Alt', 'meetingDay' => 'Montag', 'meetingTime' => '07:00', 'status' => 'CHAPTER']);
$organizations->saveDetails(4, ['chapterName' => 'Alt 4', 'meetingDay' => 'Montag', 'meetingTime' => '07:00', 'status' => 'CHAPTER']);
$database->exec("UPDATE organizations SET details_loaded_at = '2020-01-01T00:00:00Z' WHERE org_id IN (1, 4)");

$defaults = $automation->settings();
$check($defaults['usageRefreshEnabled'] === false && $defaults['usageRefreshDays'] === 7, 'X-Standardwerte.');
$check($defaults['automaticRefreshEnabled'] === false && $defaults['automaticRefreshDays'] === 30, 'Y-Standardwerte.');
$check($defaults['automaticRefreshBatchSize'] === 10 && $defaults['automaticRefreshIntervalMinutes'] === 60, 'Worker-Standardwerte.');
$check($defaults['automaticRefreshDailyLimit'] === 50, 'Tageslimit-Standardwert.');
$automation->updateSettings(true, 5, true, 20, 75);
$saved = $automation->settings();
$check($saved['usageRefreshEnabled'] && $saved['usageRefreshDays'] === 5, 'X persistent speichern.');
$check($saved['automaticRefreshEnabled'] && $saved['automaticRefreshDays'] === 20, 'Y persistent speichern.');
$check($saved['automaticRefreshDailyLimit'] === 75, 'Tageslimit persistent speichern.');
$invalid = false;
try { $automation->updateSettings(true, 0, true, 366, 0); } catch (InvalidArgumentException) { $invalid = true; }
$check($invalid, 'Ungültige Tage abweisen.');
$invalidLimit = false;
try { $automation->updateSettings(true, 5, true, 20, 1001); } catch (InvalidArgumentException) { $invalidLimit = true; }
$check($invalidLimit, 'Tageslimit außerhalb 1 bis 1000 abweisen.');
$automaticDue = $organizations->findAutomaticDueChapters(1, 10);
$check(count($automaticDue) === 3, 'Y findet ungeladene und stale CHAPTER und schließt CORE_GROUP aus.');
$check(array_column($automaticDue, 'orgId') === [2, 1, 4], 'Y priorisiert not_loaded vor alten loaded-Datensätzen stabil.');
$check($organizations->isStaleLoadedChapter(1, 1), 'X erkennt stale.');
$check($organizations->isAutomaticDueChapter(2, 1), 'not_loaded und details_loaded_at NULL sind Y-fällig.');
$check(!$organizations->isAutomaticDueChapter(3, 1), 'Nicht-CHAPTER ist nicht Y-fällig.');

$detailsPayload = static fn (int $orgId): string => json_encode([
    'content' => ['orgId' => $orgId, 'orgType' => 'CHAPTER', 'chapterDetails' => [
        'name' => 'Aktualisiert ' . $orgId, 'meetingDay' => 'Montag', 'meetingTime' => '07:00',
    ]],
], JSON_THROW_ON_ERROR);
$serviceFor = static function (Closure $transport) use ($organizations, $automation): ChapterRefreshService {
    return new ChapterRefreshService($organizations, $automation, new BniClient(new HttpClient($transport)));
};
$basisService = new ChapterSearchService($organizations);
$basisBefore = $basisService->dataBasisCount();
$firstFill = $serviceFor(static fn () => ['status' => 200, 'headers' => [], 'body' => $detailsPayload(2)])
    ->refresh(2, 'automatic', 1);
$firstFilled = $organizations->find(2);
$check($firstFill['status'] === 'success' && $firstFilled['detailStatus'] === 'loaded' && $firstFilled['detailsLoadedAt'] !== null, 'Y-Erstbefüllung setzt loaded und details_loaded_at.');
$check(!$organizations->isAutomaticDueChapter(2, 1), 'Erfolgreich erstbefülltes Chapter ist nicht mehr Y-fällig.');
$check($basisService->dataBasisCount() === $basisBefore + 1, 'Erstbefüllung erweitert die lokale Suchbasis.');
$organizations->upsertMapOrganizations([
    ['orgId' => 5, 'cmsSecurityHash' => 'five', 'countryCode' => 'DE', 'orgType' => 'CHAPTER', 'longitude' => 7, 'latitude' => 50],
]);
$errorCandidate = $serviceFor(static fn () => ['status' => 503, 'headers' => [], 'body' => '{}'])
    ->refresh(5, 'automatic', 1);
$check($errorCandidate['status'] === 'error' && $organizations->isAutomaticDueChapter(5, 1), 'Fehlerhaftes Chapter bleibt für Y erneut versuchbar.');
$check(array_column($organizations->findAutomaticDueChapters(1, 10), 'orgId') === [5, 1, 4], 'Y priorisiert error vor alten loaded-Datensätzen.');
$dueStats = $automation->statistics(1, 1);
$check($dueStats['automaticNeverLoaded'] === 0 && $dueStats['automaticRetryableErrors'] === 1 && $dueStats['staleAutomatic'] === 2 && $dueStats['automaticDueTotal'] === 3, 'Y-Kategorien sind überschneidungsfrei und korrekt.');
$calls = 0;
$success = $serviceFor(static function () use (&$calls, $detailsPayload): array {
    $calls++;
    return ['status' => 200, 'headers' => [], 'body' => $detailsPayload(1)];
})->refresh(1, 'usage_search', 1);
$check($success['status'] === 'success' && $calls === 1, 'Stale X-Refresh erfolgreich und ohne Retry.');
$check(!$organizations->isStaleLoadedChapter(1, 1), 'Erfolg aktualisiert details_loaded_at.');
$freshCalls = 0;
$fresh = $serviceFor(static function () use (&$freshCalls): array { $freshCalls++; return []; })->refresh(1, 'usage_detail', 1);
$check($fresh['status'] === 'skipped' && $freshCalls === 0, 'Frische Details ohne BNI-Request.');

$database->exec("UPDATE organizations SET details_loaded_at = '2020-01-01T00:00:00Z' WHERE org_id = 1");
$rate = $serviceFor(static fn (): array => ['status' => 429, 'headers' => ['Retry-After: 120'], 'body' => '{}'])
    ->refresh(1, 'usage_search', 1);
$check($rate['status'] === 'rate_limited' && $rate['retryAfter'] === 120, '429 und Retry-After.');
$check($organizations->find(1)['detailStatus'] === 'loaded', '429 bewahrt lokale geladene Daten.');

$forbidden = $serviceFor(static fn (): array => ['status' => 403, 'headers' => [], 'body' => '{}'])
    ->refresh(1, 'automatic', 1);
$check($forbidden['status'] === 'forbidden' && $organizations->find(1)['detailStatus'] === 'loaded', '403 bewahrt lokale Daten.');

$temporary = $serviceFor(static fn (): array => ['status' => 503, 'headers' => [], 'body' => '{}'])
    ->refresh(1, 'automatic', 1);
$check($temporary['status'] === 'error' && $organizations->find(1)['detailStatus'] === 'loaded', '5xx betrifft nur Refresh, lokale Daten bleiben sichtbar.');
$network = $serviceFor(static function (): array { throw new RuntimeException('timeout'); })->refresh(1, 'automatic', 1);
$check($network['status'] === 'error', 'Timeout ohne automatischen Retry.');

$lockOwner = 'test-owner';
$check($automation->acquireLock(1, $lockOwner), 'Ersten Lock erwerben.');
$lockedCalls = 0;
$locked = $serviceFor(static function () use (&$lockedCalls): array { $lockedCalls++; return []; })->refresh(1, 'usage_detail', 1);
$check($locked['status'] === 'skipped' && $locked['reason'] === 'locked' && $lockedCalls === 0, 'Deduplizierung per SQLite-Lock.');
$automation->releaseLock(1, $lockOwner);

// Der automatische Runner stoppt Schutzfehler sofort, setzt bei 5xx aber fort.
$database->exec("UPDATE organizations SET details_loaded_at = '2020-01-01T00:00:00Z' WHERE org_id IN (1, 4)");
$runnerCalls = 0;
$rateRunner = new AutomaticRefreshRunner(
    $organizations,
    $serviceFor(static function () use (&$runnerCalls): array { $runnerCalls++; return ['status' => 429, 'headers' => [], 'body' => '{}']; }),
    static function (): void {},
);
$check(count($rateRunner->run(1, 10)) === 1 && $runnerCalls === 1, 'Y stoppt nach 429 ohne weiteren Request.');

$runnerCalls = 0;
$forbiddenRunner = new AutomaticRefreshRunner(
    $organizations,
    $serviceFor(static function () use (&$runnerCalls): array { $runnerCalls++; return ['status' => 403, 'headers' => [], 'body' => '{}']; }),
    static function (): void {},
);
$check(count($forbiddenRunner->run(1, 10)) === 1 && $runnerCalls === 1, 'Y stoppt nach 403 ohne weiteren Request.');

$runnerCalls = 0; $delays = [];
$temporaryRunner = new AutomaticRefreshRunner(
    $organizations,
    $serviceFor(static function (string $url) use (&$runnerCalls, $detailsPayload): array {
        $runnerCalls++;
        if ($runnerCalls === 1) return ['status' => 503, 'headers' => [], 'body' => '{}'];
        return ['status' => 200, 'headers' => [], 'body' => $detailsPayload(str_contains($url, 'four') ? 4 : 1)];
    }),
    static function (int $milliseconds) use (&$delays): void { $delays[] = $milliseconds; },
);
$temporaryResults = $temporaryRunner->run(1, 2);
$check(count($temporaryResults) === 2 && $runnerCalls === 2, 'Y setzt nach 5xx mit dem nächsten Chapter fort.');
$check($delays === [1500], 'Y verwendet zentralen 1500-ms-Abstand.');

$statuses = $database->query("SELECT status FROM chapter_refresh_log WHERE status != 'started'")->fetchAll(PDO::FETCH_COLUMN);
$check(in_array('success', $statuses, true), 'Success protokolliert.');
$check(in_array('error', $statuses, true), 'Error protokolliert.');
$check(in_array('rate_limited', $statuses, true), 'Rate-Limit protokolliert.');
$check(in_array('forbidden', $statuses, true), 'Forbidden protokolliert.');
$triggers = $database->query('SELECT DISTINCT trigger_type FROM chapter_refresh_log')->fetchAll(PDO::FETCH_COLUMN);
$check(array_diff(['usage_search', 'usage_detail', 'automatic'], $triggers) === [], 'Trigger-Typen protokolliert.');
$stats = $automation->statistics(1, 1);
$check($stats['usageToday'] >= 1 && $stats['successSevenDays'] >= 1, 'Heute- und 7-Tage-Statistik.');
$check($stats['protectionStopsSevenDays'] >= 2, '429-/403-Statistik.');

// Ein gemeinsames, atomares Tagesbudget zählt nur tatsächlich gestartete nicht-manuelle Requests.
$limitDatabase = (new Database(':memory:'))->connection();
$limitOrganizations = new OrganizationRepository($limitDatabase);
$limitAutomation = new AutomationRepository($limitDatabase);
$limitAutomation->updateSettings(true, 1, true, 1, 2);
$limitOrganizations->upsertMapOrganizations([
    ['orgId' => 11, 'cmsSecurityHash' => 'eleven', 'countryCode' => 'DE', 'orgType' => 'CHAPTER', 'longitude' => 7, 'latitude' => 50],
    ['orgId' => 12, 'cmsSecurityHash' => 'twelve', 'countryCode' => 'DE', 'orgType' => 'CHAPTER', 'longitude' => 7, 'latitude' => 50],
    ['orgId' => 13, 'cmsSecurityHash' => 'thirteen', 'countryCode' => 'DE', 'orgType' => 'CHAPTER', 'longitude' => 7, 'latitude' => 50],
]);
foreach ([11, 12, 13] as $orgId) {
    $limitOrganizations->saveDetails($orgId, ['chapterName' => 'Limit ' . $orgId, 'meetingDay' => 'Montag', 'meetingTime' => '07:00', 'status' => 'CHAPTER']);
}
$limitDatabase->exec("UPDATE organizations SET details_loaded_at = '2020-01-01T00:00:00Z'");
$limitCalls = 0;
$limitClient = new BniClient(new HttpClient(static function (string $url) use (&$limitCalls, $detailsPayload): array {
    $limitCalls++;
    preg_match('/(eleven|twelve|thirteen)/', $url, $match);
    $ids = ['eleven' => 11, 'twelve' => 12, 'thirteen' => 13];
    return ['status' => $limitCalls === 2 ? 503 : 200, 'headers' => [], 'body' => $detailsPayload($ids[$match[1]] ?? 11)];
}));
$limitService = new ChapterRefreshService($limitOrganizations, $limitAutomation, $limitClient);
$check($limitService->refresh(11, 'usage_search', 1)['status'] === 'success', 'usage_search zählt als gestarteter Request.');
$check($limitService->refresh(12, 'usage_detail', 1)['status'] === 'error', 'Fehler nach gestartetem usage_detail zählt.');
$limited = $limitService->refresh(13, 'automatic', 1);
$check($limited['status'] === 'skipped' && $limited['reason'] === 'daily_limit' && $limitCalls === 2, 'Dritter Request wird serverseitig vor BNI gestoppt.');
$limitStats = $limitAutomation->statistics(1, 1, 2);
$check($limitStats['dailyUsed'] === 2 && $limitStats['dailyRemaining'] === 0 && $limitStats['dailyLimitReached'], 'Tagesverbrauch und Rest korrekt.');
$manualCalls = 0;
$manualService = new ChapterRefreshService($limitOrganizations, $limitAutomation, new BniClient(new HttpClient(static function () use (&$manualCalls, $detailsPayload): array {
    $manualCalls++;
    return ['status' => 200, 'headers' => [], 'body' => $detailsPayload(13)];
})));
$check($manualService->refresh(13, 'manual', null, true)['status'] === 'success' && $manualCalls === 1, 'Manueller Request bleibt trotz Tageslimit möglich.');
$check($limitAutomation->statistics(1, 1, 2)['dailyUsed'] === 2, 'Manual zählt nicht zum Tageslimit.');
$limitAutomation->updateSettings(false, 1, false, 1, 50);
$disabledCalls = 0;
$disabledService = new ChapterRefreshService($limitOrganizations, $limitAutomation, new BniClient(new HttpClient(static function () use (&$disabledCalls): array {
    $disabledCalls++;
    return [];
})));
$check($disabledService->refresh(11, 'usage_search', 1)['reason'] === 'disabled', 'X AUS startet keinen Request.');
$disabledRunner = new AutomaticRefreshRunner($limitOrganizations, $disabledService, static function (): void {});
$check(count($disabledRunner->run(1, 10)) === 1 && $disabledCalls === 0, 'Y AUS beendet den Worker-Lauf ohne Request.');

echo "PASS Automation: Settings, stale, Tageslimit, Refresh, Lock, Schutzstatus, Historie und Statistik\n";
