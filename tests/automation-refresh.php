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
require_once $appRoot . '/src/MapRefreshService.php';
require_once $appRoot . '/src/WorkerHeartbeat.php';

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
    ['orgId' => 6, 'cmsSecurityHash' => 'planned', 'countryCode' => 'DE', 'orgType' => 'PLANNED_GROUP', 'longitude' => 7, 'latitude' => 50],
]);
$organizations->saveDetails(1, ['chapterName' => 'Alt', 'meetingDay' => 'Montag', 'meetingTime' => '07:00', 'status' => 'CHAPTER']);
$organizations->saveDetails(4, ['chapterName' => 'Alt 4', 'meetingDay' => 'Montag', 'meetingTime' => '07:00', 'status' => 'CHAPTER']);
$database->exec("UPDATE organizations SET details_loaded_at = '2020-01-01T00:00:00Z' WHERE org_id IN (1, 4)");

$defaults = $automation->settings();
$check($defaults['usageRefreshEnabled'] === false && $defaults['usageRefreshDays'] === 7, 'X-Standardwerte.');
$check($defaults['automaticRefreshEnabled'] === false && $defaults['automaticRefreshDays'] === 30, 'Y-Standardwerte.');
$check($defaults['automaticRefreshBatchSize'] === 10 && $defaults['automaticRefreshIntervalMinutes'] === 60, 'Worker-Standardwerte.');
$check($defaults['automaticRefreshDailyLimit'] === 50, 'Tageslimit-Standardwert.');
$check($defaults['mapRefreshEnabled'] === false && $defaults['mapRefreshDays'] === 1, 'Z-Standardwerte.');
$automation->updateWorkerRuntime(60);
$runtime = $database->query('SELECT worker_last_seen_at,last_check_at,next_check_at FROM automation_runtime WHERE id=1')->fetch();
$check(strtotime((string) $runtime['next_check_at']) - strtotime((string) $runtime['last_check_at']) === 3600, 'Worker-Cycle setzt next_check_at einmalig auf +60 Minuten.');
$database->exec("UPDATE automation_runtime SET worker_last_seen_at='2020-01-01T00:00:00Z',last_check_at='2026-08-20T10:00:00Z',next_check_at='2026-08-20T11:00:00Z' WHERE id=1");
$sleeps = []; $heartbeats = 0;
WorkerHeartbeat::wait(120, static function () use ($automation, &$heartbeats): void { $automation->updateWorkerHeartbeat(); $heartbeats++; }, static function (int $seconds) use (&$sleeps): void { $sleeps[] = $seconds; });
$heartbeatRuntime = $database->query('SELECT worker_last_seen_at,last_check_at,next_check_at FROM automation_runtime WHERE id=1')->fetch();
$check($heartbeats === 4 && $sleeps === [30,30,30,30], '60-Minuten-Wartephase wird in Heartbeat-Abschnitte geteilt.');
$check($heartbeatRuntime['worker_last_seen_at'] !== '2020-01-01T00:00:00Z', 'Heartbeat aktualisiert worker_last_seen_at.');
$check($heartbeatRuntime['last_check_at'] === '2026-08-20T10:00:00Z' && $heartbeatRuntime['next_check_at'] === '2026-08-20T11:00:00Z', 'Heartbeat verschiebt weder last_check_at noch next_check_at.');
$automation->updateSettings(true, 5, true, 20, 75, true, 2);
$saved = $automation->settings();
$check($saved['usageRefreshEnabled'] && $saved['usageRefreshDays'] === 5, 'X persistent speichern.');
$check($saved['automaticRefreshEnabled'] && $saved['automaticRefreshDays'] === 20, 'Y persistent speichern.');
$check($saved['automaticRefreshDailyLimit'] === 75, 'Tageslimit persistent speichern.');
$check($saved['mapRefreshEnabled'] && $saved['mapRefreshDays'] === 2, 'Z persistent speichern.');
$invalid = false;
try { $automation->updateSettings(true, 0, true, 366, 0); } catch (InvalidArgumentException) { $invalid = true; }
$check($invalid, 'Ungültige Tage abweisen.');
$invalidLimit = false;
try { $automation->updateSettings(true, 5, true, 20, 1001); } catch (InvalidArgumentException) { $invalidLimit = true; }
$check($invalidLimit, 'Tageslimit außerhalb 1 bis 1000 abweisen.');
$invalidMapDays = false;
try { $automation->updateSettings(true, 5, true, 20, 50, true, 31); } catch (InvalidArgumentException) { $invalidMapDays = true; }
$check($invalidMapDays, 'Z-Tage außerhalb 1 bis 30 abweisen.');
$automaticDue = $organizations->findAutomaticDueChapters(1, 10);
$check(count($automaticDue) === 5, 'Y findet ungeladene und stale Organisationen aller bekannten Typen.');
$check(array_column($automaticDue, 'orgId') === [2, 3, 6, 1, 4], 'Y priorisiert not_loaded typunabhängig vor alten loaded-Datensätzen stabil.');
$check($organizations->isStaleLoadedChapter(1, 1), 'X erkennt stale.');
$check($organizations->isAutomaticDueChapter(2, 1), 'not_loaded und details_loaded_at NULL sind Y-fällig.');
$check($organizations->isAutomaticDueChapter(3, 1), 'CORE_GROUP ist Y-fällig.');
$check($organizations->isAutomaticDueChapter(6, 1), 'PLANNED_GROUP ist Y-fällig.');

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
$check(array_column($organizations->findAutomaticDueChapters(1, 10), 'orgId') === [3, 6, 5, 1, 4], 'Y priorisiert not_loaded, dann error, dann alte loaded-Datensätze.');
$dueStats = $automation->statistics(1, 1);
$check($dueStats['automaticNeverLoaded'] === 2 && $dueStats['automaticRetryableErrors'] === 1 && $dueStats['staleAutomatic'] === 2 && $dueStats['automaticDueTotal'] === 5, 'Y-Kategorien sind typübergreifend, überschneidungsfrei und korrekt.');
$check($dueStats['automaticDueChapter'] === 3 && $dueStats['automaticDueCoreGroup'] === 1 && $dueStats['automaticDuePlannedGroup'] === 1, 'Y-Statistik schlüsselt die fälligen Organisationstypen korrekt auf.');

// Erfolgreiche automatische und nutzungsabhängige Detailantworten sind nicht vom Organisationstyp abhängig.
$typeDatabase = (new Database(':memory:'))->connection();
$typeOrganizations = new OrganizationRepository($typeDatabase); $typeAutomation = new AutomationRepository($typeDatabase);
$typeAutomation->updateSettings(true, 1, true, 1, 50);
$typeOrganizations->upsertMapOrganizations([
    ['orgId' => 31, 'cmsSecurityHash' => 'core-success', 'countryCode' => 'DE', 'orgType' => 'CORE_GROUP', 'longitude' => 7, 'latitude' => 50],
    ['orgId' => 32, 'cmsSecurityHash' => 'planned-success', 'countryCode' => 'DE', 'orgType' => 'PLANNED_GROUP', 'longitude' => 7, 'latitude' => 50],
]);
$typeCalls = [];
$typeService = new ChapterRefreshService($typeOrganizations, $typeAutomation, new BniClient(new HttpClient(static function (string $url) use (&$typeCalls): array {
    $orgId = str_contains($url, 'core-success') ? 31 : 32; $typeCalls[] = $orgId;
    return ['status' => 200, 'headers' => [], 'body' => json_encode(['content' => ['orgId' => $orgId, 'orgType' => $orgId === 31 ? 'CORE_GROUP' : 'PLANNED_GROUP', 'chapterDetails' => ['name' => 'Organisation ' . $orgId, 'meetingDay' => 'Freitag', 'meetingTime' => '07:00']]], JSON_THROW_ON_ERROR)];
})));
$check($typeService->refresh(31, 'automatic', 1)['status'] === 'success' && $typeOrganizations->find(31)['detailStatus'] === 'loaded', 'CORE_GROUP-Detailantwort wird durch Y gespeichert.');
$check($typeService->refresh(32, 'automatic', 1)['status'] === 'success' && $typeOrganizations->find(32)['detailStatus'] === 'loaded', 'PLANNED_GROUP-Detailantwort wird durch Y gespeichert.');
$typeDatabase->exec("UPDATE organizations SET details_loaded_at = '2020-01-01T00:00:00Z' WHERE org_id = 31");
$check($typeService->refresh(31, 'usage_search', 1)['status'] === 'success' && $typeCalls === [31, 32, 31], 'X aktualisiert eine tatsächlich verwendete CORE_GROUP ohne Typ-Sperre.');

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
$check($delays === [], 'Y enthält keine redundante Runner-Wartezeit; der globale Transport-Throttle ist zuständig.');

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

// Limit 1: alle automatischen/nutzungsabhängigen Trigger sind gesperrt, ein manueller Batch bleibt limitfrei.
$manualDatabase = (new Database(':memory:'))->connection();
$manualOrganizations = new OrganizationRepository($manualDatabase); $manualAutomation = new AutomationRepository($manualDatabase);
$manualAutomation->updateSettings(true, 1, true, 1, 1);
$manualRows = [];
foreach (range(41, 47) as $orgId) $manualRows[] = ['orgId'=>$orgId,'cmsSecurityHash'=>'manual-'.$orgId,'countryCode'=>'DE','orgType'=>'CHAPTER','longitude'=>7,'latitude'=>50];
$manualOrganizations->upsertMapOrganizations($manualRows);
foreach (range(41, 47) as $orgId) $manualOrganizations->saveDetails($orgId, ['chapterName'=>'Manual '.$orgId,'meetingDay'=>'Montag','meetingTime'=>'07:00','status'=>'CHAPTER']);
$manualDatabase->exec("UPDATE organizations SET details_loaded_at='2020-01-01T00:00:00Z'");
$manualBatchCalls=[];
$manualBatchClient = new BniClient(new HttpClient(static function(string $url) use (&$manualBatchCalls,$detailsPayload):array {
    preg_match('/manual-(\d+)/',$url,$match);$orgId=(int)($match[1]??0);$manualBatchCalls[]=$orgId;
    return ['status'=>200,'headers'=>[],'body'=>$detailsPayload($orgId)];
}));
$manualBatchService = new ChapterRefreshService($manualOrganizations,$manualAutomation,$manualBatchClient);
$check($manualBatchService->refresh(41,'automatic',1)['status']==='success','Automatic Request 1 ist bei Limit 1 erlaubt.');
$check(($manualBatchService->refresh(42,'automatic',1)['reason']??null)==='daily_limit','Automatic Request 2 wird bei Limit 1 blockiert.');
$check(($manualBatchService->refresh(43,'usage_search',1)['reason']??null)==='daily_limit','usage_search wird bei erreichtem Limit blockiert.');
$check(($manualBatchService->refresh(44,'usage_detail',1)['reason']??null)==='daily_limit','usage_detail wird bei erreichtem Limit blockiert.');
$beforeManualStats=$manualAutomation->statistics(1,1,1);$beforeManualLogs=(int)$manualDatabase->query('SELECT COUNT(*) FROM chapter_refresh_log')->fetchColumn();
$manualResults=[];foreach([42,43,44] as $orgId)$manualResults[]=$manualBatchService->refresh($orgId,'manual',null,true);
$check(array_column($manualResults,'status')===['success','success','success']&&$manualBatchCalls===[41,42,43,44],'Mehrere manuelle Chapter werden trotz ausgeschöpftem Tageslimit vollständig versucht.');
$afterManualStats=$manualAutomation->statistics(1,1,1);$afterManualLogs=(int)$manualDatabase->query('SELECT COUNT(*) FROM chapter_refresh_log')->fetchColumn();
$check($beforeManualStats['dailyUsed']===1&&$afterManualStats['dailyUsed']===1&&$afterManualStats['dailyRemaining']===0&&(float)$afterManualStats['dailyPercent']===100.0,'Manual verändert Requests heute, Restkontingent und Auslastung nicht.');
$check($afterManualLogs===$beforeManualLogs+3&&(int)$manualDatabase->query("SELECT COUNT(*) FROM chapter_refresh_log WHERE trigger_type='manual' AND status='success'")->fetchColumn()===3,'Manuelle Requests bleiben im allgemeinen Refresh-Log sichtbar.');
$manualRateService=new ChapterRefreshService($manualOrganizations,$manualAutomation,new BniClient(new HttpClient(static fn():array=>['status'=>429,'headers'=>['Retry-After: 90'],'body'=>'{}'])));
$manualRate=$manualRateService->refresh(45,'manual',null,true);$check($manualRate['status']==='rate_limited'&&$manualRate['retryAfter']===90,'Manual respektiert 429 und Retry-After.');
$manualForbiddenService=new ChapterRefreshService($manualOrganizations,$manualAutomation,new BniClient(new HttpClient(static fn():array=>['status'=>403,'headers'=>[],'body'=>'{}'])));
$check($manualForbiddenService->refresh(46,'manual',null,true)['status']==='forbidden','Manual respektiert 403.');
$manualLock='manual-lock';$check($manualAutomation->acquireLock(47,$manualLock),'Manual-Testlock erworben.');$manualLockedCalls=0;
$manualLockedService=new ChapterRefreshService($manualOrganizations,$manualAutomation,new BniClient(new HttpClient(static function()use(&$manualLockedCalls):array{$manualLockedCalls++;return[];})));
$manualLocked=$manualLockedService->refresh(47,'manual',null,true);$check($manualLocked['status']==='skipped'&&$manualLocked['reason']==='locked'&&$manualLockedCalls===0,'Manual respektiert den bestehenden Lock.');$manualAutomation->releaseLock(47,$manualLock);
$check(BniRequestPolicy::DETAIL_DELAY_MS===1500,'Zentrale 1500-ms-Policy bleibt unverändert.');
$limitAutomation->updateSettings(false, 1, false, 1, 50);
$disabledCalls = 0;
$disabledService = new ChapterRefreshService($limitOrganizations, $limitAutomation, new BniClient(new HttpClient(static function () use (&$disabledCalls): array {
    $disabledCalls++;
    return [];
})));
$check($disabledService->refresh(11, 'usage_search', 1)['reason'] === 'disabled', 'X AUS startet keinen Request.');
$disabledRunner = new AutomaticRefreshRunner($limitOrganizations, $disabledService, static function (): void {});
$check(count($disabledRunner->run(1, 10)) === 1 && $disabledCalls === 0, 'Y AUS beendet den Worker-Lauf ohne Request.');

// Z: genau ein Maprequest, eigenes Lock/Log und kein Verbrauch des Detail-Tageslimits.
$mapDatabase = (new Database(':memory:'))->connection(); $mapOrganizations = new OrganizationRepository($mapDatabase); $mapAutomation = new AutomationRepository($mapDatabase);
$mapAutomation->updateSettings(false, 7, false, 30, 2, false, 1);
$check($mapAutomation->mapRefreshDue(1), 'Noch nie geladene Grunddaten sind fällig.');
$mapCalls = 0;
$mapPayload = json_encode(['orgMaps' => [['orgId' => 99, 'cmsSecurityHash' => 'map', 'countryCode' => 'DE', 'orgType' => 'CHAPTER', 'coordinates' => '7.1,50.2']]], JSON_THROW_ON_ERROR);
$mapService = new MapRefreshService($mapOrganizations, $mapAutomation, new BniClient(new HttpClient(static function () use (&$mapCalls, $mapPayload): array { $mapCalls++; return ['status' => 200, 'headers' => [], 'body' => $mapPayload]; })));
$mapResult = $mapService->refresh('map_automatic');
$check($mapResult['status'] === 'success' && $mapCalls === 1 && !$mapAutomation->mapRefreshDue(1), 'Z stale startet exakt einen Request und setzt last_map_refresh_at.');
$mapOrganizations->saveDetails(99, ['chapterName' => 'Detail bleibt', 'meetingDay' => 'Montag', 'meetingTime' => '07:00']);
$mapService->refresh('map_manual');
$check($mapOrganizations->find(99)['chapterName'] === 'Detail bleibt' && $mapOrganizations->statistics()['count'] === 1, 'Map-UPsert bewahrt Details und erzeugt keine Dublette.');
$check($mapAutomation->statistics(7, 30, 2)['dailyUsed'] === 0, 'Maprequests zählen nicht zum Detail-Tageslimit.');
$check($mapAutomation->acquireMapLock('occupied'), 'Maplock kann belegt werden.');
$check($mapService->refresh('map_automatic')['reason'] === 'locked' && $mapCalls === 2, 'Paralleler Maprefresh wird ohne Request verhindert.');
$mapAutomation->releaseMapLock('occupied');
$rateMap = new MapRefreshService($mapOrganizations, $mapAutomation, new BniClient(new HttpClient(static fn (): array => ['status' => 429, 'headers' => ['Retry-After: 120'], 'body' => '{}'])));
$check($rateMap->refresh('map_automatic')['status'] === 'rate_limited', 'Map-429 wird ohne Retry protokolliert.');
$forbiddenMap = new MapRefreshService($mapOrganizations, $mapAutomation, new BniClient(new HttpClient(static fn (): array => ['status' => 403, 'headers' => [], 'body' => '{}'])));
$check($forbiddenMap->refresh('map_automatic')['status'] === 'forbidden', 'Map-403 stoppt ohne Retry.');
$errorMap = new MapRefreshService($mapOrganizations, $mapAutomation, new BniClient(new HttpClient(static fn (): array => ['status' => 503, 'headers' => [], 'body' => '{}'])));
$check($errorMap->refresh('map_automatic')['status'] === 'error', 'Map-5xx wird als Einzelfehler protokolliert.');
$mapStatuses = $mapDatabase->query('SELECT status FROM map_refresh_log')->fetchAll(PDO::FETCH_COLUMN);
$check(in_array('success', $mapStatuses, true) && in_array('rate_limited', $mapStatuses, true) && in_array('forbidden', $mapStatuses, true) && in_array('error', $mapStatuses, true), 'Map-Historie enthält Erfolgs- und Fehlerstatus.');

echo "PASS Automation: X/Y/Z, Tageslimit, Refresh, Lock, Schutzstatus, Historie und Statistik\n";
