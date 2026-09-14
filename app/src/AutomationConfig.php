<?php

declare(strict_types=1);

final class AutomationConfig
{
    public const DEFAULT_WATCHLIST_NOTIFICATION_INTERVAL_SECONDS = 1800;

    public static function watchlistNotificationIntervalSeconds(): int
    {
        $value = getenv('CROSSCHAPP_WATCHLIST_INTERVAL_SECONDS');
        if (!is_string($value) || trim($value) === '') return self::DEFAULT_WATCHLIST_NOTIFICATION_INTERVAL_SECONDS;
        $interval = filter_var($value, FILTER_VALIDATE_INT);
        return $interval !== false && $interval >= 60 ? (int) $interval : self::DEFAULT_WATCHLIST_NOTIFICATION_INTERVAL_SECONDS;
    }
}
