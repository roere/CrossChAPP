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
$allowedDays = ['Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag', 'Sonntag'];

if ($locationInput === '') {
    JsonResponse::send(['error' => 'Bitte PLZ oder Ort eingeben.'], 400);
}
if (count($days) > 7 || array_diff($days, $allowedDays) !== []) {
    JsonResponse::send(['error' => 'Die Wochentagsauswahl ist ungültig.'], 400);
}
if (!in_array($timeFilter, ['any', 'early', 'late'], true) || !in_array($sort, ['distance', 'time', 'members'], true)) {
    JsonResponse::send(['error' => 'Zeitfilter oder Sortierung ist ungültig.'], 400);
}

try {
    $location = (new Geocoder())->geocode($locationInput);
    $repository = new OrganizationRepository((new Database())->connection());
    $service = new ChapterSearchService($repository);
    $results = $service->search($location['latitude'], $location['longitude'], $days, $timeFilter, $sort);
    JsonResponse::send([
        'count' => count($results),
        'data_basis' => $service->dataBasisCount(),
        'location' => $location,
        'results' => $results,
    ]);
} catch (InvalidArgumentException $exception) {
    JsonResponse::send(['error' => $exception->getMessage()], 400);
} catch (DomainException $exception) {
    JsonResponse::send(['error' => $exception->getMessage()], 404);
} catch (Throwable $exception) {
    JsonResponse::send(['error' => $exception->getMessage()], 502);
}
