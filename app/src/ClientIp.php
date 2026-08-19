<?php

declare(strict_types=1);

final class ClientIp
{
    public static function address(): string
    {
        $remote = self::validIp($_SERVER['REMOTE_ADDR'] ?? null) ?? 'unknown';
        if (!self::isTrustedProxy($remote)) return $remote;
        $forwarded = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null;
        if (!is_string($forwarded)) return $remote;
        foreach (explode(',', $forwarded) as $candidate) {
            $ip = self::validIp(trim($candidate));
            if ($ip !== null) return $ip;
        }
        return $remote;
    }

    public static function isTrustedProxy(?string $remote = null): bool
    {
        $remote ??= self::validIp($_SERVER['REMOTE_ADDR'] ?? null);
        if ($remote === null) return false;
        $configured = getenv('CROSSCHAPP_TRUSTED_PROXIES');
        if (!is_string($configured) || trim($configured) === '') return false;
        $trusted = array_filter(array_map('trim', explode(',', $configured)), static fn(string $ip): bool => self::validIp($ip) !== null);
        return in_array($remote, $trusted, true);
    }

    private static function validIp(mixed $value): ?string
    {
        return is_string($value) && filter_var($value, FILTER_VALIDATE_IP) !== false ? $value : null;
    }
}
