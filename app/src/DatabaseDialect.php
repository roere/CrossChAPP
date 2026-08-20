<?php

declare(strict_types=1);

final class DatabaseDialect
{
    public static function driver(PDO $database): string
    {
        return (string) $database->getAttribute(PDO::ATTR_DRIVER_NAME);
    }

    public static function isMysql(PDO $database): bool
    {
        return self::driver($database) === 'mysql';
    }

    public static function beginWrite(PDO $database): void
    {
        if (self::isMysql($database)) {
            $database->beginTransaction();
        } else {
            $database->exec('BEGIN IMMEDIATE TRANSACTION');
        }
    }

    public static function insertIgnore(PDO $database, string $sql): string
    {
        return self::isMysql($database)
            ? preg_replace('/^\s*INSERT\s+/i', 'INSERT IGNORE ', $sql, 1) ?? $sql
            : preg_replace('/^\s*INSERT\s+/i', 'INSERT OR IGNORE ', $sql, 1) ?? $sql;
    }

    public static function ageCutoff(int $days): string
    {
        return gmdate('Y-m-d\TH:i:s\Z', time() - ($days * 86400));
    }

    public static function now(): string
    {
        return gmdate('Y-m-d\TH:i:s\Z');
    }
}
