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
        $this->createAccountSchema();
    }

    private function createAccountSchema(): void
    {
        $this->connection->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                first_name TEXT NOT NULL,
                last_name TEXT NOT NULL,
                username TEXT COLLATE NOCASE,
                email TEXT NOT NULL COLLATE NOCASE UNIQUE,
                password_hash TEXT NOT NULL,
                home_chapter_org_id INTEGER,
                role TEXT NOT NULL DEFAULT 'user' CHECK (role IN ('user', 'admin')),
                status TEXT NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'active', 'disabled')),
                email_verified_at TEXT,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL,
                last_login_at TEXT,
                FOREIGN KEY (home_chapter_org_id) REFERENCES organizations(org_id) ON DELETE SET NULL
            )
            SQL);
        $this->addTableColumnIfMissing('users', 'username', 'TEXT COLLATE NOCASE');
        $this->connection->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_users_username ON users(username) WHERE username IS NOT NULL');
        $this->connection->exec('CREATE INDEX IF NOT EXISTS idx_users_home_chapter ON users(home_chapter_org_id)');
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $admin = $this->connection->prepare(<<<'SQL'
            INSERT INTO users (first_name, last_name, username, email, password_hash, role, status, email_verified_at, created_at, updated_at)
            SELECT 'admin', '', 'admin', 'admin@localhost.invalid', :password_hash, 'admin', 'active', :verified_at, :created_at, :updated_at
            WHERE NOT EXISTS (SELECT 1 FROM users WHERE username = 'admin' COLLATE NOCASE)
            SQL);
        $admin->execute([
            ':password_hash' => '$2y$10$/w.85OIJmzun7pFjgRPPaeD4Q4p.otU/T4wBIzkhPlniP1OY79sbW',
            ':verified_at' => $now, ':created_at' => $now, ':updated_at' => $now,
        ]);
        foreach (['email_verification_tokens', 'password_reset_tokens'] as $table) {
            $this->connection->exec(sprintf(<<<'SQL'
                CREATE TABLE IF NOT EXISTS %s (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    user_id INTEGER NOT NULL,
                    token_hash TEXT NOT NULL UNIQUE,
                    expires_at TEXT NOT NULL,
                    created_at TEXT NOT NULL,
                    used_at TEXT,
                    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
                )
                SQL, $table));
            $this->connection->exec(sprintf('CREATE INDEX IF NOT EXISTS idx_%s_user_expiry ON %s(user_id, expires_at)', $table, $table));
        }
        $this->connection->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS mail_settings (
                id INTEGER PRIMARY KEY CHECK (id = 1),
                smtp_host TEXT,
                smtp_port INTEGER NOT NULL DEFAULT 587,
                smtp_username TEXT,
                smtp_password TEXT,
                encryption TEXT NOT NULL DEFAULT 'starttls' CHECK (encryption IN ('starttls', 'tls', 'none')),
                sender_email TEXT,
                sender_name TEXT NOT NULL DEFAULT 'CrossChAPP',
                base_url TEXT NOT NULL DEFAULT 'http://localhost:8082',
                updated_at TEXT NOT NULL
            )
            SQL);
        $this->connection->exec("INSERT OR IGNORE INTO mail_settings (id, updated_at) VALUES (1, CURRENT_TIMESTAMP)");
        $this->connection->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS email_templates (
                template_key TEXT PRIMARY KEY CHECK (template_key IN ('verify_email', 'reset_password')),
                subject TEXT NOT NULL,
                body TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )
            SQL);
        $verificationBody = "Hallo {{first_name}},\n\nvielen Dank für deine Registrierung bei CrossChAPP.\n\nBitte bestätige deine E-Mail-Adresse über folgenden Link:\n\n{{verification_link}}\n\nDer Link ist 24 Stunden gültig.\n\nViele Grüße\nCrossChAPP";
        $resetBody = "Hallo {{first_name}},\n\nfür dein CrossChAPP-Konto wurde das Zurücksetzen des Passworts angefordert.\n\nÜber folgenden Link kannst du ein neues Passwort vergeben:\n\n{{reset_link}}\n\nDer Link ist 60 Minuten gültig.\n\nFalls du das Zurücksetzen nicht angefordert hast, kannst du diese Nachricht ignorieren.\n\nViele Grüße\nCrossChAPP";
        $statement = $this->connection->prepare('INSERT OR IGNORE INTO email_templates (template_key, subject, body, updated_at) VALUES (:key, :subject, :body, :updated_at)');
        $statement->execute([':key' => 'verify_email', ':subject' => 'Bitte bestätige deine E-Mail-Adresse bei CrossChAPP', ':body' => $verificationBody, ':updated_at' => gmdate('Y-m-d\TH:i:s\Z')]);
        $statement->execute([':key' => 'reset_password', ':subject' => 'Neues Passwort für CrossChAPP festlegen', ':body' => $resetBody, ':updated_at' => gmdate('Y-m-d\TH:i:s\Z')]);
        $this->connection->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS auth_attempts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                attempt_type TEXT NOT NULL CHECK (attempt_type IN ('login', 'password_reset', 'resend_verification')),
                identifier_hash TEXT NOT NULL,
                ip_hash TEXT NOT NULL,
                successful INTEGER NOT NULL DEFAULT 0,
                attempted_at TEXT NOT NULL
            )
            SQL);
        $this->connection->exec('CREATE INDEX IF NOT EXISTS idx_auth_attempts_limit ON auth_attempts(attempt_type, identifier_hash, ip_hash, attempted_at)');
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
