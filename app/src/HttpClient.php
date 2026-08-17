<?php

declare(strict_types=1);

final class HttpClient
{
    /** @return array<string, mixed> */
    public function getJson(string $url, int $timeout = 25): array
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => "Accept: application/json\r\nUser-Agent: BNI-DACH-Finder/0.1\r\n",
                'ignore_errors' => true,
                'timeout' => $timeout,
            ],
        ]);

        $body = @file_get_contents($url, false, $context);
        $headers = $http_response_header ?? [];
        $status = $this->statusCode($headers);

        if ($body === false) {
            throw new RuntimeException('Die öffentliche BNI-Datenquelle ist nicht erreichbar.');
        }

        if ($status < 200 || $status >= 300) {
            throw new RuntimeException(sprintf('Die BNI-Datenquelle antwortete mit HTTP %d.', $status));
        }

        try {
            $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('Die BNI-Datenquelle lieferte kein valides JSON.');
        }

        if (!is_array($data)) {
            throw new RuntimeException('Die BNI-Datenquelle lieferte ein unerwartetes JSON-Format.');
        }

        return $data;
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
