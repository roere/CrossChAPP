<?php

declare(strict_types=1);

$appRoot = is_file(__DIR__ . '/../app/src/Database.php') ? __DIR__ . '/../app' : '/var/www/html';
require_once $appRoot . '/src/Database.php';
require_once $appRoot . '/src/OrganizationRepository.php';
require_once $appRoot . '/src/ChapterSearchService.php';

$database = (new Database(':memory:'))->connection();
$repository = new OrganizationRepository($database);
$check = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$base = [
    ['orgId' => 1, 'cmsSecurityHash' => 'one', 'countryCode' => 'DE', 'orgType' => 'CHAPTER', 'longitude' => 7.0, 'latitude' => 50.0],
    ['orgId' => 2, 'cmsSecurityHash' => 'two', 'countryCode' => 'DE', 'orgType' => 'CHAPTER', 'longitude' => 7.0, 'latitude' => 51.0],
    ['orgId' => 3, 'cmsSecurityHash' => 'core', 'countryCode' => 'DE', 'orgType' => 'CORE_GROUP', 'longitude' => 7.0, 'latitude' => 50.1],
];
$repository->upsertMapOrganizations($base);
$details = static fn (int $id, string $day, string $time): array => [
    'chapterName' => 'Test ' . $id,
    'meetingDay' => $day,
    'meetingTime' => $time,
    'status' => 'CHAPTER',
];
$repository->saveDetails(1, $details(1, 'Montag', '08:59'));
$repository->saveDetails(2, $details(2, 'Dienstag', '09:00'));
$repository->saveDetails(3, $details(3, 'Montag', '07:00'));

$today = new DateTimeImmutable('today', new DateTimeZone('Europe/Berlin'));
$insertRequest = $database->prepare('INSERT INTO representation_requests (user_id, org_id, request_date, created_at, updated_at) VALUES (1, :org_id, :request_date, :created_at, :updated_at)');
foreach ([[1, '-1 day'], [2, '+1 day'], [2, '+2 days'], [3, 'today']] as [$orgId, $dateModifier]) {
    $insertRequest->execute([
        ':org_id' => $orgId,
        ':request_date' => $today->modify($dateModifier)->format('Y-m-d'),
        ':created_at' => gmdate('Y-m-d\TH:i:s\Z'),
        ':updated_at' => gmdate('Y-m-d\TH:i:s\Z'),
    ]);
}

$service = new ChapterSearchService($repository);
$representationOrganizations = $repository->representationOrganizations();
$check(array_column($representationOrganizations, 'orgId') === [1, 2] && array_unique(array_column($representationOrganizations, 'orgType')) === ['CHAPTER'], 'Vertretung anbieten erhält serverseitig ausschließlich CHAPTER-Organisationen.');
$all = $service->search(50.0, 7.0, [], 'any', 'distance', null);
$check(array_column($all['results'], 'orgId') === [1, 3, 2], 'Alle Organisationstypen mit nutzbaren lokalen Treffendaten berücksichtigen.');
$check(array_column($service->search(50.0, 7.0, ['Montag'], 'any', 'distance', null)['results'], 'orgId') === [1, 3], 'Montagfilter.');
$check(count($service->search(50.0, 7.0, ['Montag', 'Dienstag'], 'any', 'distance', null)['results']) === 3, 'Mehrere Tage.');
$check(array_column($service->search(50.0, 7.0, [], 'early', 'distance', null)['results'], 'orgId') === [1, 3], 'Früh vor 09:00.');
$check(array_column($service->search(50.0, 7.0, [], 'late', 'distance', null)['results'], 'orgId') === [2], 'Spät ab 09:00.');
$check(count($service->search(50.0, 7.0, [], 'any', 'distance', null)['results']) === 3, 'Egal ohne Zeitfilter.');
$check($all['results'][0]['distanceKm'] <= $all['results'][1]['distanceKm'], 'Entfernungssortierung.');
$withRequests = $service->search(50.0, 7.0, [], 'any', 'distance', null, true, $today->format('Y-m-d'));
$check(array_column($withRequests['results'], 'orgId') === [3, 2], 'Nur Chapter mit heutigen oder zukünftigen Gesuchen; vergangene Gesuche sind ausgeschlossen.');
$check(count(array_filter($withRequests['results'], static fn (array $chapter): bool => $chapter['orgId'] === 2)) === 1, 'Mehrere aktuelle Gesuche erzeugen keine doppelten Chaptertreffer.');
$check(array_column($service->search(50.0, 7.0, ['Montag'], 'early', 'distance', null, true, $today->format('Y-m-d'))['results'], 'orgId') === [3], 'Gesuchsfilter kombiniert sich mit Tages- und Zeitfilter.');

$more = [];
for ($id = 4; $id <= 25; $id++) {
    $more[] = ['orgId' => $id, 'cmsSecurityHash' => 'id-' . $id, 'countryCode' => 'DE', 'orgType' => 'CHAPTER', 'longitude' => 7.0, 'latitude' => 50.0 + ($id / 100)];
}
$repository->upsertMapOrganizations($more);
foreach ($more as $chapter) {
    $repository->saveDetails($chapter['orgId'], $details($chapter['orgId'], 'Mittwoch', '07:00'));
}
$limited = $service->search(50.0, 7.0, [], 'any', 'distance', 5);
$ten = $service->search(50.0, 7.0, [], 'any', 'distance', 10);
$twenty = $service->search(50.0, 7.0, [], 'any', 'distance', 20);
$fifty = $service->search(50.0, 7.0, [], 'any', 'distance', 50);
$unlimited = $service->search(50.0, 7.0, [], 'any', 'distance', null);
$check(count($limited['results']) === 5 && $limited['totalMatching'] === 25, 'Konfigurierbares Limit und Gesamtzahl.');
$check(count($ten['results']) === 10, '10er-Limit.');
$check(count($twenty['results']) === 20, '20er-Limit.');
$check(count($fifty['results']) === 25, '50er-Limit ohne Auffüllen.');
$check(count($unlimited['results']) === 25, 'Alle Treffer ohne künstliches Limit.');
$requiredDetailFields = [
    'orgId', 'orgType', 'countryCode', 'latitude', 'longitude', 'chapterName', 'region', 'regionId',
    'city', 'postalCode', 'street', 'venue', 'meetingDay', 'meetingTime', 'meetingType', 'meetingDuration',
    'memberCount', 'chapterUrl', 'visitorRegistrationUrl', 'onlineMeetingUrl', 'timezone', 'status',
    'description', 'detailStatus', 'detailsLoadedAt', 'distanceKm',
];
$check(array_diff($requiredDetailFields, array_keys($unlimited['results'][0])) === [], 'Vollständige lokale Detailfelder.');

echo "PASS ChapterSearchService: datengetriebene Typ-, Tages-, Zeit-, Distanz- und Ergebnislogik\n";
