<?php

declare(strict_types=1);

final class BniRequestPolicy
{
    public const DETAIL_DELAY_MS = 1500;

    public static function stopReason(int $statusCode): ?string
    {
        return match ($statusCode) {
            429 => 'rate_limited',
            403 => 'forbidden',
            default => null,
        };
    }
}
