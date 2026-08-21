<?php

declare(strict_types=1);

require_once '/var/www/html/src/RoutingService.php';

$calls = 0;
$service = new RoutingService(static function (string $url) use (&$calls): array {
    $calls++;
    if (!str_starts_with($url, 'https://router.project-osrm.org/route/v1/driving/')) throw new RuntimeException('Unerwarteter Routing-Endpunkt.');
    return ['status' => 200, 'body' => json_encode([
        'routes' => [[
            'distance' => 24680,
            'duration' => 1680,
            'geometry' => ['coordinates' => [[6.95, 50.94], [7.3, 50.95]]],
        ]],
    ], JSON_THROW_ON_ERROR)];
});
$route = $service->route(50.94, 6.95, 50.95, 7.3);
if ($calls !== 1 || $route !== ['coordinates' => [[6.95, 50.94], [7.3, 50.95]], 'distanceKm' => 24.7, 'durationMinutes' => 28]) throw new RuntimeException('OSRM-Antwort wurde nicht korrekt normalisiert.');

$invalid = false;
try { $service->route(91, 6.95, 50.95, 7.3); } catch (InvalidArgumentException) { $invalid = true; }
if (!$invalid || $calls !== 1) throw new RuntimeException('Ungültige Koordinaten wurden nicht vor dem Request abgewiesen.');

$failure = new RoutingService(static fn (string $url): array => ['status' => 503, 'body' => '{}']);
try { $failure->route(50.94, 6.95, 50.95, 7.3); throw new RuntimeException('Routingfehler wurde nicht weitergegeben.'); }
catch (RuntimeException $exception) { if ($exception->getMessage() !== 'Die Route konnte derzeit nicht geladen werden.') throw $exception; }

echo "PASS representation routing\n";
