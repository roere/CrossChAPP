<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/ChapterSearchService.php';
require_once dirname(__DIR__) . '/src/Database.php';
require_once dirname(__DIR__) . '/src/Geocoder.php';
require_once dirname(__DIR__) . '/src/JsonResponse.php';
require_once dirname(__DIR__) . '/src/OrganizationRepository.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    JsonResponse::send(['error' => 'Nur POST ist erlaubt.'], 405);
}

try {
    $payload = json_decode(file_get_contents('php://input') ?: '', true, 64, JSON_THROW_ON_ERROR);
} catch (JsonException) {
    JsonResponse::send(['error' => 'Der Request enthält kein valides JSON.'], 400);
}

$locationInput = is_array($payload) ? trim((string) ($payload['location'] ?? '')) : '';
$days = is_array($payload) && is_array($payload['days'] ?? null) ? array_values($payload['days']) : [];
$timeFilter = is_array($payload) ? (string) ($payload['time'] ?? 'any') : 'any';
$sort = is_array($payload) ? (string) ($payload['sort'] ?? 'distance') : 'distance';
$limitInput = is_array($payload) ? (string) ($payload['limit'] ?? '10') : '10';
$allowedDays = ['Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag', 'Sonntag'];
$allowedLimits = ['5' => 5, '10' => 10, '20' => 20, '50' => 50, 'all' => null];

if ($locationInput === '') {
    JsonResponse::send(['error' => 'Bitte PLZ oder Ort eingeben.'], 400);
}
if (count($days) > 7 || array_diff($days, $allowedDays) !== []) {
    JsonResponse::send(['error' => 'Die Wochentagsauswahl ist ungültig.'], 400);
}
if (!in_array($timeFilter, ['any', 'early', 'late'], true) || !in_array($sort, ['distance', 'time', 'members'], true)) {
    JsonResponse::send(['error' => 'Zeitfilter oder Sortierung ist ungültig.'], 400);
}
if (!array_key_exists($limitInput, $allowedLimits)) {
    JsonResponse::send(['error' => 'Die gewünschte Ergebnisanzahl ist ungültig.'], 400);
}

try {
    $location = (new Geocoder())->geocode($locationInput);
    $repository = new OrganizationRepository((new Database())->connection());
    $service = new ChapterSearchService($repository);
    $search = $service->search(
        $location['latitude'],
        $location['longitude'],
        $days,
        $timeFilter,
        $sort,
        $allowedLimits[$limitInput],
    );
    $searchLocation = [
        'display_name' => $location['label'],
        'latitude' => $location['latitude'],
        'longitude' => $location['longitude'],
    ];
    JsonResponse::send([
        'count' => count($search['results']),
        'result_count' => count($search['results']),
        'total_matching' => $search['totalMatching'],
        'data_basis' => $service->dataBasisCount(),
        'location' => $location,
        'search_location' => $searchLocation,
        'results' => $search['results'],
    ]);
} catch (InvalidArgumentException $exception) {
    JsonResponse::send(['error' => $exception->getMessage()], 400);
} catch (DomainException $exception) {
    JsonResponse::send(['error' => $exception->getMessage()], 404);
} catch (Throwable $exception) {
    JsonResponse::send(['error' => $exception->getMessage()], 502);
}
