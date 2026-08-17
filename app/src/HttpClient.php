<?php

declare(strict_types=1);

require_once __DIR__ . '/HttpException.php';

final class HttpClient
{
    public function __construct(private readonly ?Closure $transport = null)
    {
    }

    /** @return array<string, mixed> */
    public function getJson(string $url, int $timeout = 25): array
    {
        if ($this->transport !== null) {
            $response = ($this->transport)($url, $timeout);
            $body = $response['body'] ?? false;
            $headers = is_array($response['headers'] ?? null) ? $response['headers'] : [];
            $status = isset($response['status']) ? (int) $response['status'] : $this->statusCode($headers);
        } else {
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
        }

        if ($status < 200 || $status >= 300) {
            if ($status === 0) {
                throw new RuntimeException('Die öffentliche BNI-Datenquelle ist nicht erreichbar.');
            }
            throw new HttpException(
                sprintf('Die BNI-Datenquelle antwortete mit HTTP %d.', $status),
                $status,
                self::retryAfterSeconds($headers),
            );
        }

        if ($body === false) {
            throw new RuntimeException('Die öffentliche BNI-Datenquelle ist nicht erreichbar.');
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
    public static function retryAfterSeconds(array $headers, ?int $now = null): ?int
    {
        foreach ($headers as $header) {
            if (preg_match('/^Retry-After:\s*(.+)$/i', trim($header), $matches) !== 1) {
                continue;
            }
            $value = trim($matches[1]);
            if (ctype_digit($value)) {
                return (int) $value;
            }
            $timestamp = strtotime($value);
            if ($timestamp !== false) {
                return max(0, $timestamp - ($now ?? time()));
            }
        }
        return null;
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
