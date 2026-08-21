<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/Auth.php';
require_once dirname(__DIR__, 2) . '/src/JsonResponse.php';
require_once dirname(__DIR__, 2) . '/src/RoutingService.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') JsonResponse::send(['error' => 'Nur POST ist erlaubt.'], 405);
Auth::requireUserJson(); Auth::requireCsrfJson();
try {
    $payload = json_decode(file_get_contents('php://input') ?: '', true, 16, JSON_THROW_ON_ERROR);
    if (!is_array($payload)) throw new InvalidArgumentException('Ungültige Routenanfrage.');
    foreach (['startLatitude','startLongitude','endLatitude','endLongitude'] as $field) if (!is_numeric($payload[$field] ?? null)) throw new InvalidArgumentException('Die Routenkoordinaten sind ungültig.');
    JsonResponse::send(['route' => (new RoutingService())->route((float) $payload['startLatitude'], (float) $payload['startLongitude'], (float) $payload['endLatitude'], (float) $payload['endLongitude'])]);
} catch (JsonException|InvalidArgumentException $exception) { JsonResponse::send(['error' => $exception->getMessage()], 400); }
catch (Throwable) { JsonResponse::send(['error' => 'Die Route konnte derzeit nicht geladen werden.'], 502); }
