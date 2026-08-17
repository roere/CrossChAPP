<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/BniClient.php';
require_once dirname(__DIR__, 2) . '/src/JsonResponse.php';

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
$results = [];

foreach ($items as $index => $item) {
    if (!is_array($item)) {
        $results[] = ['orgId' => null, 'status' => 'error', 'error' => 'Ungültiger Datensatz.'];
        continue;
    }

    $orgId = isset($item['orgId']) ? (int) $item['orgId'] : null;
    $hash = trim((string) ($item['cmsSecurityHash'] ?? ''));

    try {
        $results[] = [
            'orgId' => $orgId,
            'status' => 'loaded',
            'details' => $client->getChapterDetails($hash),
        ];
    } catch (Throwable $exception) {
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
