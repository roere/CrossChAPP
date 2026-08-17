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
    }

    private function addColumnIfMissing(string $column, string $definition): void
    {
        $columns = $this->connection->query('PRAGMA table_info(organizations)')->fetchAll();
        foreach ($columns as $existing) {
            if (($existing['name'] ?? null) === $column) {
                return;
            }
        }

        // Name und Definition sind ausschließlich interne Konstanten, keine Benutzereingaben.
        $this->connection->exec(sprintf('ALTER TABLE organizations ADD COLUMN %s %s', $column, $definition));
    }
}
