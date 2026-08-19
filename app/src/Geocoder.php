<?php

declare(strict_types=1);

final class Geocoder
{
    private const ENDPOINT = 'https://nominatim.openstreetmap.org/search';

    /** @return array{latitude: float, longitude: float, label: string} */
    public function geocode(string $query): array
    {
        $query = trim($query);
        if ($query === '' || strlen($query) > 320) {
            throw new InvalidArgumentException('Bitte eine gültige PLZ oder einen Ort eingeben.');
        }
        if (getenv('CROSSCHAPP_TEST_MODE') === '1') {
            return ['latitude' => 50.95, 'longitude' => 7.30, 'label' => $query];
        }

        $url = self::ENDPOINT . '?' . http_build_query([
            'q' => $query,
            'format' => 'jsonv2',
            'addressdetails' => '1',
            'countrycodes' => 'de,at,ch',
            'limit' => '1',
            'accept-language' => 'de',
        ]);
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => "Accept: application/json\r\nAccept-Language: de\r\nUser-Agent: CrossChAPP-local-development/0.1\r\n",
                'ignore_errors' => true,
                'timeout' => 12,
            ],
        ]);

        $lock = fopen('/var/www/data/geocoder.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            throw new RuntimeException('Der Ortsdienst konnte nicht exklusiv gestartet werden.');
        }
        try {
            $body = @file_get_contents($url, false, $context);
            $status = $this->statusCode($http_response_header ?? []);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        if ($body === false || $status < 200 || $status >= 300) {
            throw new RuntimeException('Der Ortsdienst ist momentan nicht erreichbar.');
        }

        try {
            $results = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('Der Ortsdienst lieferte eine ungültige Antwort.');
        }

        $result = is_array($results) ? ($results[0] ?? null) : null;
        if (!is_array($result) || !is_numeric($result['lat'] ?? null) || !is_numeric($result['lon'] ?? null)) {
            throw new DomainException('Ort oder PLZ konnte nicht gefunden werden.');
        }

        return [
            'latitude' => (float) $result['lat'],
            'longitude' => (float) $result['lon'],
            'label' => $this->label($result, $query),
        ];
    }

    /** @param array<string, mixed> $result */
    private function label(array $result, string $fallback): string
    {
        $address = is_array($result['address'] ?? null) ? $result['address'] : [];
        $place = $address['city'] ?? $address['town'] ?? $address['village'] ?? $address['municipality'] ?? null;
        $postcode = $address['postcode'] ?? null;
        $short = trim(implode(' ', array_filter([$postcode, $place], fn ($value) => is_string($value) && $value !== '')));
        return $short !== '' ? $short : (string) ($result['display_name'] ?? $fallback);
    }

    /** @param list<string> $headers */
    private function statusCode(array $headers): int
    {
        foreach (array_reverse($headers) as $header) {
            if (preg_match('/^HTTP\/\S+\s+(\d{3})\b/', $header, $matches) === 1) {
                return (int) $matches[1];
            }
        }
        return 0;
    }
}
