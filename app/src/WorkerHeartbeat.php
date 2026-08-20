<?php

declare(strict_types=1);

final class WorkerHeartbeat
{
    public const INTERVAL_SECONDS = 30;
    public const ACTIVE_TIMEOUT_SECONDS = 90;

    public static function wait(int $totalSeconds, Closure $heartbeat, ?Closure $sleep = null): void
    {
        $sleep ??= static function (int $seconds): void { sleep($seconds); };
        $remaining = max(0, $totalSeconds);
        while ($remaining > 0) {
            $chunk = min(self::INTERVAL_SECONDS, $remaining);
            $sleep($chunk);
            $remaining -= $chunk;
            $heartbeat();
        }
    }
}
