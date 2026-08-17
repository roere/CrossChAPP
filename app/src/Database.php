<?php

declare(strict_types=1);

final class Database
{
    private const DEFAULT_PATH = '/var/www/data/bni-dach.sqlite';

    private PDO $connection;

    public function __construct(?string $path = null)
    {
        $path ??= self::DEFAULT_PATH;
        $directory = dirname($path);

        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('Das SQLite-Datenverzeichnis konnte nicht erstellt werden.');
        }

        $this->connection = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $this->connection->exec('PRAGMA foreign_keys = ON');
        $this->connection->exec('PRAGMA busy_timeout = 5000');
        $this->createSchema();
    }

    public function connection(): PDO
    {
        return $this->connection;
    }

    private function createSchema(): void
    {
        $this->connection->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS organizations (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                org_id INTEGER NOT NULL UNIQUE,
                cms_security_hash TEXT,
                country_code TEXT,
                org_type TEXT,
                longitude REAL,
                latitude REAL,
                chapter_name TEXT,
                region TEXT,
                region_id INTEGER,
                city TEXT,
                postal_code TEXT,
                street TEXT,
                venue TEXT,
                meeting_day TEXT,
                meeting_time TEXT,
                meeting_type TEXT,
                meeting_duration INTEGER,
                member_count INTEGER,
                chapter_url TEXT,
                visitor_registration_url TEXT,
                online_meeting_link TEXT,
                timezone TEXT,
                status TEXT,
                description TEXT,
                detail_status TEXT NOT NULL DEFAULT 'not_loaded'
                    CHECK (detail_status IN ('not_loaded', 'loaded', 'error')),
                map_loaded_at TEXT,
                details_loaded_at TEXT,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )
            SQL);
        $this->addColumnIfMissing('status', 'TEXT');
        $this->connection->exec('CREATE INDEX IF NOT EXISTS idx_organizations_detail_status ON organizations(detail_status)');
        $this->connection->exec('CREATE INDEX IF NOT EXISTS idx_organizations_country_type ON organizations(country_code, org_type)');
        $this->connection->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS automation_settings (
                id INTEGER PRIMARY KEY CHECK (id = 1),
                usage_refresh_enabled INTEGER NOT NULL DEFAULT 0,
                usage_refresh_days INTEGER NOT NULL DEFAULT 7,
                automatic_refresh_enabled INTEGER NOT NULL DEFAULT 0,
                automatic_refresh_days INTEGER NOT NULL DEFAULT 30,
                automatic_refresh_batch_size INTEGER NOT NULL DEFAULT 10,
                automatic_refresh_interval_minutes INTEGER NOT NULL DEFAULT 60,
                automatic_refresh_daily_limit INTEGER NOT NULL DEFAULT 50,
                updated_at TEXT NOT NULL
            )
            SQL);
        $this->connection->exec(<<<'SQL'
            INSERT OR IGNORE INTO automation_settings (
                id, usage_refresh_enabled, usage_refresh_days, automatic_refresh_enabled,
                automatic_refresh_days, automatic_refresh_batch_size,
                automatic_refresh_interval_minutes, updated_at
            ) VALUES (1, 0, 7, 0, 30, 10, 60, CURRENT_TIMESTAMP)
            SQL);
        $this->addTableColumnIfMissing('automation_settings', 'automatic_refresh_daily_limit', 'INTEGER NOT NULL DEFAULT 50');
        $this->connection->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS chapter_refresh_log (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                org_id INTEGER NOT NULL,
                trigger_type TEXT NOT NULL CHECK (trigger_type IN ('manual', 'usage_search', 'usage_detail', 'automatic')),
                started_at TEXT NOT NULL,
                finished_at TEXT,
                status TEXT NOT NULL CHECK (status IN ('started', 'success', 'error', 'rate_limited', 'forbidden', 'skipped')),
                http_status INTEGER,
                error_category TEXT
            )
            SQL);
        $this->connection->exec('CREATE INDEX IF NOT EXISTS idx_refresh_log_finished ON chapter_refresh_log(finished_at)');
        $this->connection->exec('CREATE INDEX IF NOT EXISTS idx_refresh_log_trigger_status ON chapter_refresh_log(trigger_type, status)');
        $this->connection->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS chapter_refresh_locks (
                org_id INTEGER PRIMARY KEY,
                owner_token TEXT NOT NULL,
                lock_until TEXT NOT NULL,
                created_at TEXT NOT NULL
            )
            SQL);
        $this->connection->exec('CREATE INDEX IF NOT EXISTS idx_refresh_locks_until ON chapter_refresh_locks(lock_until)');
        $this->connection->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS automation_runtime (
                id INTEGER PRIMARY KEY CHECK (id = 1),
                worker_last_seen_at TEXT,
                last_check_at TEXT,
                next_check_at TEXT,
                updated_at TEXT NOT NULL
            )
            SQL);
        $this->connection->exec("INSERT OR IGNORE INTO automation_runtime (id, updated_at) VALUES (1, CURRENT_TIMESTAMP)");
    }

    private function addColumnIfMissing(string $column, string $definition): void
    {
        $this->addTableColumnIfMissing('organizations', $column, $definition);
    }

    private function addTableColumnIfMissing(string $table, string $column, string $definition): void
    {
        $columns = $this->connection->query(sprintf('PRAGMA table_info(%s)', $table))->fetchAll();
        foreach ($columns as $existing) {
            if (($existing['name'] ?? null) === $column) {
                return;
            }
        }

        // Name und Definition sind ausschließlich interne Konstanten, keine Benutzereingaben.
        $this->connection->exec(sprintf('ALTER TABLE %s ADD COLUMN %s %s', $table, $column, $definition));
    }
}
