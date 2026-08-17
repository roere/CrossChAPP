<?php

declare(strict_types=1);

$appRoot = is_file(__DIR__ . '/../app/src/Database.php') ? __DIR__ . '/../app' : '/var/www/html';
require_once $appRoot . '/src/Database.php';
require_once $appRoot . '/src/OrganizationRepository.php';
require_once $appRoot . '/src/ChapterSearchService.php';

$repository = new OrganizationRepository((new Database(':memory:'))->connection());
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

$service = new ChapterSearchService($repository);
$all = $service->search(50.0, 7.0, [], 'any', 'distance');
$check(array_column($all, 'orgId') === [1, 2], 'Nur bestehende Chapter berücksichtigen.');
$check(array_column($service->search(50.0, 7.0, ['Montag'], 'any', 'distance'), 'orgId') === [1], 'Montagfilter.');
$check(count($service->search(50.0, 7.0, ['Montag', 'Dienstag'], 'any', 'distance')) === 2, 'Mehrere Tage.');
$check(array_column($service->search(50.0, 7.0, [], 'early', 'distance'), 'orgId') === [1], 'Früh vor 09:00.');
$check(array_column($service->search(50.0, 7.0, [], 'late', 'distance'), 'orgId') === [2], 'Spät ab 09:00.');
$check(count($service->search(50.0, 7.0, [], 'any', 'distance')) === 2, 'Egal ohne Zeitfilter.');
$check($all[0]['distanceKm'] <= $all[1]['distanceKm'], 'Entfernungssortierung.');

$more = [];
for ($id = 4; $id <= 25; $id++) {
    $more[] = ['orgId' => $id, 'cmsSecurityHash' => 'id-' . $id, 'countryCode' => 'DE', 'orgType' => 'CHAPTER', 'longitude' => 7.0, 'latitude' => 50.0 + ($id / 100)];
}
$repository->upsertMapOrganizations($more);
foreach ($more as $chapter) {
    $repository->saveDetails($chapter['orgId'], $details($chapter['orgId'], 'Mittwoch', '07:00'));
}
$check(count($service->search(50.0, 7.0, [], 'any', 'distance')) === 20, '20er-Limit.');

echo "PASS ChapterSearchService: Typ-, Tages-, Zeit-, Distanz- und 20er-Limit\n";
