<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/BniClient.php';
require_once dirname(__DIR__, 2) . '/src/Database.php';
require_once dirname(__DIR__, 2) . '/src/JsonResponse.php';
require_once dirname(__DIR__, 2) . '/src/OrganizationRepository.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    JsonResponse::send(['error' => 'Nur POST ist erlaubt.'], 405);
}

try {
    $payload = json_decode(file_get_contents('php://input') ?: '', true, 512, JSON_THROW_ON_ERROR);
} catch (JsonException) {
    JsonResponse::send(['error' => 'Der Request enthält kein valides JSON.'], 400);
}

$items = is_array($payload) ? ($payload['items'] ?? null) : null;
if (!is_array($items) || $items === []) {
    JsonResponse::send(['error' => 'Mindestens ein Datensatz muss ausgewählt sein.'], 400);
}

if (count($items) > 50) {
    JsonResponse::send(['error' => 'Maximal 50 Detaildatensätze pro Vorgang sind erlaubt.'], 400);
}

$client = new BniClient();
$repository = new OrganizationRepository((new Database())->connection());
$reload = ($payload['reload'] ?? false) === true;
$results = [];
$processed = [];

foreach ($items as $index => $item) {
    if (!is_array($item)) {
        $results[] = ['orgId' => null, 'status' => 'error', 'error' => 'Ungültiger Datensatz.'];
        continue;
    }

    $orgId = isset($item['orgId']) ? (int) $item['orgId'] : null;
    if ($orgId === null || $orgId <= 0 || isset($processed[$orgId])) {
        $results[] = ['orgId' => $orgId, 'status' => 'error', 'error' => 'Ungültige oder doppelte Organisations-ID.'];
        continue;
    }
    $processed[$orgId] = true;
    $organization = $repository->find($orgId);

    if ($organization === null) {
        $results[] = ['orgId' => $orgId, 'status' => 'error', 'error' => 'Die Organisation ist lokal nicht vorhanden.'];
        continue;
    }

    if ($organization['detailStatus'] === 'loaded' && !$reload) {
        $results[] = ['orgId' => $orgId, 'status' => 'loaded', 'skipped' => true, 'details' => $organization];
        continue;
    }

    try {
        $details = $client->getChapterDetails((string) $organization['cmsSecurityHash']);
        if (($details['orgId'] ?? null) !== $orgId) {
            throw new RuntimeException('Die BNI-Detailantwort gehört zu einer anderen Organisation.');
        }
        $repository->saveDetails($orgId, $details);
        $results[] = [
            'orgId' => $orgId,
            'status' => 'loaded',
            'skipped' => false,
            'details' => $repository->find($orgId),
        ];
    } catch (Throwable $exception) {
        $repository->markDetailError($orgId);
        $results[] = [
            'orgId' => $orgId,
            'status' => 'error',
            'error' => $exception->getMessage(),
        ];
    }

    if ($index < count($items) - 1) {
        usleep(300_000);
    }
}

JsonResponse::send(['results' => $results]);
