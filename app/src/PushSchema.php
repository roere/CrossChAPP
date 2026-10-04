<?php

declare(strict_types=1);

/** Additive schema shared by SQLite and MariaDB; no existing Watchlist columns change. */
final class PushSchema
{
    public static function migrate(PDO $db): void
    {
        $mysql = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $id = $mysql ? 'BIGINT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $ref = $mysql ? 'BIGINT' : 'INTEGER';
        $text = $mysql ? 'VARCHAR(32)' : 'TEXT';
        $engine = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
        $db->exec("CREATE TABLE IF NOT EXISTS push_subscriptions (
            id $id, user_id $ref NOT NULL, endpoint TEXT NOT NULL, endpoint_hash CHAR(64) NOT NULL UNIQUE,
            p256dh VARCHAR(100) NOT NULL, auth VARCHAR(32) NOT NULL, enabled_at $text NOT NULL,
            created_at $text NOT NULL, updated_at $text NOT NULL, last_success_at $text NULL,
            FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE)$engine");
        $db->exec("CREATE TABLE IF NOT EXISTS push_deliveries (
            id $id, subscription_id $ref NOT NULL, request_id $ref NOT NULL,
            status VARCHAR(16) NOT NULL DEFAULT 'pending', attempt_count INT NOT NULL DEFAULT 0,
            locked_until $text NULL, last_attempt_at $text NULL, last_status INT NULL,
            created_at $text NOT NULL, sent_at $text NULL,
            UNIQUE(subscription_id,request_id),
            FOREIGN KEY(subscription_id) REFERENCES push_subscriptions(id) ON DELETE CASCADE,
            FOREIGN KEY(request_id) REFERENCES representation_requests(id) ON DELETE CASCADE)$engine");
        $db->exec("CREATE TABLE IF NOT EXISTS push_runs (
            id $id, started_at_ms BIGINT NOT NULL, finished_at_ms BIGINT NOT NULL, duration_ms DOUBLE NOT NULL,
            candidate_notifications INT NOT NULL, subscriptions_targeted INT NOT NULL, delivery_attempts INT NOT NULL,
            success_count INT NOT NULL, failure_count INT NOT NULL, expired_subscription_count INT NOT NULL,
            cpu_ms DOUBLE NULL)$engine");
        // IF NOT EXISTS is supported by SQLite; MariaDB indexes are installed once by the schema version.
        foreach (['push_subscriptions'=>['user_id'], 'push_deliveries'=>['status','locked_until'], 'push_runs'=>['started_at_ms']] as $table=>$columns) {
            $name = 'idx_'.$table.'_monitor';
            $db->exec('CREATE INDEX '.($mysql?'':'IF NOT EXISTS ').$name.' ON '.$table.'('.implode(',',$columns).')');
        }
    }
}
