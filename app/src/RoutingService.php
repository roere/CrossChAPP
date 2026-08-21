<?php

declare(strict_types=1);

final class RoutingService
{
    private const ENDPOINT = 'https://router.project-osrm.org/route/v1/driving';

    public function __construct(private readonly ?Closure $transport = null) {}

    /** @return array{coordinates:list<array{0:float,1:float}>,distanceKm:float,durationMinutes:int} */
    public function route(float $startLatitude, float $startLongitude, float $endLatitude, float $endLongitude): array
    {
        foreach ([[$startLatitude, -90, 90], [$endLatitude, -90, 90], [$startLongitude, -180, 180], [$endLongitude, -180, 180]] as [$value, $minimum, $maximum]) {
            if (!is_finite($value) || $value < $minimum || $value > $maximum) throw new InvalidArgumentException('Die Routenkoordinaten sind ungültig.');
        }
        if (getenv('CROSSCHAPP_TEST_MODE') === '1' && $this->transport === null) {
            return ['coordinates' => [[$startLongitude, $startLatitude], [$endLongitude, $endLatitude]], 'distanceKm' => 12.3, 'durationMinutes' => 17];
        }
        $url = self::ENDPOINT . '/' . rawurlencode((string) $startLongitude) . ',' . rawurlencode((string) $startLatitude) . ';' . rawurlencode((string) $endLongitude) . ',' . rawurlencode((string) $endLatitude) . '?overview=full&geometries=geojson&steps=false';
        $response = $this->transport !== null ? ($this->transport)($url) : $this->request($url);
        $status = (int) ($response['status'] ?? 0); $body = $response['body'] ?? false;
        if ($status < 200 || $status >= 300 || !is_string($body)) throw new RuntimeException('Die Route konnte derzeit nicht geladen werden.');
        try { $payload = json_decode($body, true, 128, JSON_THROW_ON_ERROR); } catch (JsonException) { throw new RuntimeException('Die Route konnte derzeit nicht geladen werden.'); }
        $route = is_array($payload['routes'][0] ?? null) ? $payload['routes'][0] : null; $coordinates = $route['geometry']['coordinates'] ?? null;
        if (!is_array($route) || !is_array($coordinates) || count($coordinates) < 2 || !is_numeric($route['distance'] ?? null) || !is_numeric($route['duration'] ?? null)) throw new RuntimeException('Die Route konnte derzeit nicht geladen werden.');
        $points = [];
        foreach ($coordinates as $coordinate) {
            if (!is_array($coordinate) || !is_numeric($coordinate[0] ?? null) || !is_numeric($coordinate[1] ?? null)) throw new RuntimeException('Die Route konnte derzeit nicht geladen werden.');
            $points[] = [(float) $coordinate[0], (float) $coordinate[1]];
        }
        return ['coordinates' => $points, 'distanceKm' => round((float) $route['distance'] / 1000, 1), 'durationMinutes' => max(1, (int) round((float) $route['duration'] / 60))];
    }

    /** @return array{status:int,body:string|false} */
    private function request(string $url): array
    {
        if (getenv('CROSSCHAPP_DISABLE_EXTERNAL_HTTP') === '1') throw new RuntimeException('Die Route konnte derzeit nicht geladen werden.');
        $context = stream_context_create(['http' => ['method' => 'GET', 'header' => "Accept: application/json\r\nUser-Agent: CrossChAPP/1.0\r\n", 'ignore_errors' => true, 'timeout' => 15]]);
        $body = @file_get_contents($url, false, $context); $status = 0;
        foreach (array_reverse($http_response_header ?? []) as $header) if (preg_match('/^HTTP\/\S+\s+(\d{3})\b/', $header, $matches) === 1) { $status = (int) $matches[1]; break; }
        return ['status' => $status, 'body' => $body];
    }
}
