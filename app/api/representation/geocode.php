<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/Geocoder.php';
require_once dirname(__DIR__, 2) . '/src/JsonResponse.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    JsonResponse::send(['error' => 'Nur POST ist erlaubt.'], 405);
}
try {
    $payload = json_decode(file_get_contents('php://input') ?: '', true, 16, JSON_THROW_ON_ERROR);
    $query = is_array($payload) ? trim((string) ($payload['location'] ?? '')) : '';
    JsonResponse::send(['location' => (new Geocoder())->geocode($query)]);
} catch (JsonException|InvalidArgumentException $exception) {
    JsonResponse::send(['error' => $exception->getMessage()], 400);
} catch (DomainException $exception) {
    JsonResponse::send(['error' => $exception->getMessage()], 404);
} catch (Throwable) {
    JsonResponse::send(['error' => 'Der Ortsdienst ist momentan nicht erreichbar.'], 502);
}
