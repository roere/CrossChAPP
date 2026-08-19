<?php

declare(strict_types=1);

final class Database
{
    private const DEFAULT_PATH = '/var/www/data/bni-dach.sqlite';

    private PDO $connection;

    public function __construct(?string $path = null)
    {
        if ($path === null) {
            $override = getenv('CROSSCHAPP_DB_PATH');
            $path = is_string($override) && trim($override) !== '' ? trim($override) : self::DEFAULT_PATH;
        }
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
        $this->connection->exec('PRAGMA journal_mode = WAL');
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
                map_refresh_enabled INTEGER NOT NULL DEFAULT 0,
                map_refresh_days INTEGER NOT NULL DEFAULT 1,
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
        $this->addTableColumnIfMissing('automation_settings', 'map_refresh_enabled', 'INTEGER NOT NULL DEFAULT 0');
        $this->addTableColumnIfMissing('automation_settings', 'map_refresh_days', 'INTEGER NOT NULL DEFAULT 1');
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
                last_map_refresh_at TEXT,
                map_lock_token TEXT,
                map_lock_until TEXT,
                map_retry_after_until TEXT,
                updated_at TEXT NOT NULL
            )
            SQL);
        $this->connection->exec("INSERT OR IGNORE INTO automation_runtime (id, updated_at) VALUES (1, CURRENT_TIMESTAMP)");
        $this->addTableColumnIfMissing('automation_runtime', 'last_map_refresh_at', 'TEXT');
        $this->addTableColumnIfMissing('automation_runtime', 'map_lock_token', 'TEXT');
        $this->addTableColumnIfMissing('automation_runtime', 'map_lock_until', 'TEXT');
        $this->addTableColumnIfMissing('automation_runtime', 'map_retry_after_until', 'TEXT');
        $this->connection->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS map_refresh_log (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                trigger_type TEXT NOT NULL CHECK (trigger_type IN ('map_manual', 'map_automatic')),
                started_at TEXT NOT NULL,
                finished_at TEXT,
                status TEXT NOT NULL CHECK (status IN ('started', 'success', 'error', 'rate_limited', 'forbidden', 'skipped')),
                http_status INTEGER,
                error_category TEXT
            )
            SQL);
        $this->connection->exec('CREATE INDEX IF NOT EXISTS idx_map_refresh_log_time ON map_refresh_log(started_at, trigger_type, status)');
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
        $this->addTableColumnIfMissing('users', 'bni_verification_status', "TEXT NOT NULL DEFAULT 'unverified' CHECK (bni_verification_status IN ('unverified','directory_match','manual_verified'))");
        $this->addTableColumnIfMissing('users', 'bni_verified_at', 'TEXT');
        $this->addTableColumnIfMissing('users', 'bni_verified_by_user_id', 'INTEGER REFERENCES users(id) ON DELETE SET NULL');
        $this->addTableColumnIfMissing('users', 'bni_external_member_ref', 'TEXT');
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
                template_key TEXT PRIMARY KEY,
                subject TEXT NOT NULL,
                body TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )
            SQL);
        $this->migrateEmailTemplates();
        $verificationBody = "Hallo {{first_name}},\n\nvielen Dank für deine Registrierung bei CrossChAPP.\n\nBitte bestätige deine E-Mail-Adresse über folgenden Link:\n\n{{verification_link}}\n\nDer Link ist 24 Stunden gültig.\n\nViele Grüße\nCrossChAPP";
        $resetBody = "Hallo {{first_name}},\n\nfür dein CrossChAPP-Konto wurde das Zurücksetzen des Passworts angefordert.\n\nÜber folgenden Link kannst du ein neues Passwort vergeben:\n\n{{reset_link}}\n\nDer Link ist 60 Minuten gültig.\n\nFalls du das Zurücksetzen nicht angefordert hast, kannst du diese Nachricht ignorieren.\n\nViele Grüße\nCrossChAPP";
        $statement = $this->connection->prepare('INSERT OR IGNORE INTO email_templates (template_key, subject, body, updated_at) VALUES (:key, :subject, :body, :updated_at)');
        $statement->execute([':key' => 'verify_email', ':subject' => 'Bitte bestätige deine E-Mail-Adresse bei CrossChAPP', ':body' => $verificationBody, ':updated_at' => gmdate('Y-m-d\TH:i:s\Z')]);
        $statement->execute([':key' => 'reset_password', ':subject' => 'Neues Passwort für CrossChAPP festlegen', ':body' => $resetBody, ':updated_at' => gmdate('Y-m-d\TH:i:s\Z')]);
        $invitationBody = "Hallo {{first_name}},\n\ndu wurdest zu CrossChAPP eingeladen.\n\nÜber den folgenden Link kannst du dein Konto aktivieren und ein Passwort vergeben:\n\n{{invitation_link}}\n\nChapter: {{chapter}}\n\nViele Grüße\n{{app_name}}";
        $statement->execute([':key' => 'user_invitation', ':subject' => 'Einladung zu CrossChAPP', ':body' => $invitationBody, ':updated_at' => gmdate('Y-m-d\TH:i:s\Z')]);
        $contactBody = "Hallo {{provider_first_name}},\n\n{{custom_message}}\n\n---\nAnfrage von:\n{{requester_full_name}}\n{{requester_email}}\nChapter: {{requester_chapter}}\nTermin: {{requested_date}}\n\nViele Grüße\n{{app_name}}";
        $statement->execute([':key' => 'representation_contact', ':subject' => 'CrossChAPP – Vertretungsanfrage für {{requested_date}}', ':body' => $contactBody, ':updated_at' => gmdate('Y-m-d\TH:i:s\Z')]);
        $requestContactBody = "Hallo {{request_owner_first_name}},\n\n{{custom_message}}\n\n---\nRückmeldung von:\n{{contact_full_name}}\n{{contact_email}}\nBNI-Chapter: {{contact_chapter}}\nVertretung für: {{requested_chapter}}\nTermin: {{requested_date}}\n\nViele Grüße\n{{app_name}}";
        $statement->execute([':key' => 'representation_request_contact', ':subject' => 'CrossChAPP – Rückmeldung zu deinem Vertretungsgesuch am {{requested_date}}', ':body' => $requestContactBody, ':updated_at' => gmdate('Y-m-d\TH:i:s\Z')]);
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
        $this->connection->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS bni_member_check_attempts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                ip_hash TEXT NOT NULL,
                attempted_at TEXT NOT NULL
            )
            SQL);
        $this->connection->exec('CREATE INDEX IF NOT EXISTS idx_bni_member_check_rate ON bni_member_check_attempts(ip_hash, attempted_at)');
        $this->connection->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS bni_member_directory_configs (
                org_id INTEGER PRIMARY KEY,
                endpoint TEXT NOT NULL,
                parameters TEXT NOT NULL,
                languages TEXT NOT NULL,
                website_type TEXT NOT NULL,
                website_id TEXT NOT NULL,
                mapped_widget_settings TEXT NOT NULL,
                referer TEXT NOT NULL,
                updated_at TEXT NOT NULL,
                FOREIGN KEY (org_id) REFERENCES organizations(org_id) ON DELETE CASCADE
            )
            SQL);
        $memberLanguages = '{"availableLanguages":[{"type":"published","url":"http://bni-rheinruhr.de/koenigsforst/de/memberlist","descriptionKey":"Deutsch","id":18,"localeCode":"de"}],"activeLanguage":{"id":18,"localeCode":"de","descriptionKey":"Deutsch","cookieBotCode":"de"}}';
        $memberSettings = '[{"key":113,"name":"Member Names","value":"Namen der Mitglieder"},{"key":117,"name":"Profession/Specialty","value":"Wirtschaftszweig/Fachgebiet"},{"key":118,"name":"Company","value":"Unternehmen"},{"key":119,"name":"Showing","value":"Zeige"},{"key":120,"name":"to","value":"bis"},{"key":121,"name":"of","value":"von"},{"key":122,"name":"entries","value":"Einträgen"},{"key":304,"name":"Zero Records","value":"Keine Einträge gefunden"},{"key":343,"name":"Phone","value":"Telefon"},{"key":344,"name":"Send Mail","value":"Nachricht senden"}]';
        $memberConfig = $this->connection->prepare('INSERT OR IGNORE INTO bni_member_directory_configs (org_id,endpoint,parameters,languages,website_type,website_id,mapped_widget_settings,referer,updated_at) SELECT 44628,:endpoint,:parameters,:languages,\'3\',\'27966\',:settings,:referer,:updated WHERE EXISTS (SELECT 1 FROM organizations WHERE org_id=44628)');
        $memberConfig->execute([':endpoint'=>'https://bni-rheinruhr.de/bnicms/v3/frontend/memberlist/display',':parameters'=>'chapterName=44628&regionIds=11805,5843,9614,5925,5921,5939,11553&chapterWebsite=1',':languages'=>$memberLanguages,':settings'=>$memberSettings,':referer'=>'https://bni-rheinruhr.de/koenigsforst/de/memberlist',':updated'=>gmdate('Y-m-d\TH:i:s\Z')]);
        $this->connection->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS bni_member_check_lock (
                id INTEGER PRIMARY KEY CHECK (id = 1),
                owner_token TEXT NOT NULL,
                lock_until TEXT NOT NULL
            )
            SQL);
        $this->connection->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS user_invitations (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                first_name TEXT NOT NULL,
                last_name TEXT NOT NULL,
                email TEXT NOT NULL COLLATE NOCASE,
                home_chapter_org_id INTEGER NOT NULL,
                token_hash TEXT NOT NULL UNIQUE,
                expires_at TEXT NOT NULL,
                created_at TEXT NOT NULL,
                sent_at TEXT,
                accepted_at TEXT,
                created_by_user_id INTEGER NOT NULL,
                status TEXT NOT NULL DEFAULT 'pending' CHECK (status IN ('pending','accepted','expired','cancelled')),
                FOREIGN KEY (home_chapter_org_id) REFERENCES organizations(org_id),
                FOREIGN KEY (created_by_user_id) REFERENCES users(id)
            )
            SQL);
        $this->connection->exec('CREATE INDEX IF NOT EXISTS idx_user_invitations_email_status ON user_invitations(email, status, expires_at)');
        $this->createRepresentationSchema();
    }

    private function createRepresentationSchema(): void
    {
        $this->connection->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS representation_offers (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                org_id INTEGER NOT NULL,
                all_dates INTEGER NOT NULL DEFAULT 0 CHECK (all_dates IN (0, 1)),
                date_signature TEXT NOT NULL DEFAULT '',
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (org_id) REFERENCES organizations(org_id) ON DELETE CASCADE
            )
            SQL);
        $this->addTableColumnIfMissing('representation_offers', 'org_id', 'INTEGER');
        $this->addTableColumnIfMissing('representation_offers', 'date_signature', "TEXT NOT NULL DEFAULT ''");
        $this->connection->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS representation_offer_chapters (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                offer_id INTEGER,
                org_id INTEGER NOT NULL,
                UNIQUE (offer_id, org_id),
                FOREIGN KEY (offer_id) REFERENCES representation_offers(id) ON DELETE SET NULL,
                FOREIGN KEY (org_id) REFERENCES organizations(org_id) ON DELETE CASCADE
            )
            SQL);
        $this->connection->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS representation_offer_dates (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                offer_id INTEGER NOT NULL,
                offer_date TEXT NOT NULL,
                UNIQUE (offer_id, offer_date),
                FOREIGN KEY (offer_id) REFERENCES representation_offers(id) ON DELETE CASCADE
            )
            SQL);
        $this->connection->exec('CREATE INDEX IF NOT EXISTS idx_representation_offers_user ON representation_offers(user_id)');
        $this->connection->exec('CREATE INDEX IF NOT EXISTS idx_representation_offers_chapter ON representation_offers(org_id, user_id)');
        $this->connection->exec('CREATE INDEX IF NOT EXISTS idx_representation_chapters_org ON representation_offer_chapters(org_id, offer_id)');
        $this->connection->exec('CREATE INDEX IF NOT EXISTS idx_representation_dates_offer_date ON representation_offer_dates(offer_id, offer_date)');
        $this->connection->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS representation_settings (
                id INTEGER PRIMARY KEY CHECK (id = 1),
                contact_hint TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )
            SQL);
        $hint = 'Möchtest Du eine Anfrage senden? Die Person erhält Deinen Namen und Deine eMail Adresse und kann sich bei Dir zurückmelden.';
        $insertSetting = $this->connection->prepare('INSERT OR IGNORE INTO representation_settings (id, contact_hint, updated_at) VALUES (1, :hint, :updated)');
        $insertSetting->execute([':hint' => $hint, ':updated' => gmdate('Y-m-d\TH:i:s\Z')]);
        $this->addTableColumnIfMissing('representation_settings', 'request_contact_hint', "TEXT NOT NULL DEFAULT 'Möchtest Du anbieten, die Vertretung zu übernehmen? Die Person erhält Deinen Namen und Deine eMail-Adresse und kann sich bei Dir zurückmelden.'");
        $this->addTableColumnIfMissing('representation_settings', 'offer_custom_message', "TEXT NOT NULL DEFAULT 'Hallo,\n\nich suche für diesen Termin eine Vertretung für mein BNI-Chapter und würde mich freuen, wenn Du Dich bei mir meldest.\n\nViele Grüße'");
        $this->addTableColumnIfMissing('representation_settings', 'request_custom_message', "TEXT NOT NULL DEFAULT 'Hallo,\n\nich kann mir vorstellen, die Vertretung an diesem Termin zu übernehmen. Melde Dich gerne bei mir, damit wir die Details abstimmen können.\n\nViele Grüße'");
        $this->connection->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS representation_contact_log (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                requester_user_id INTEGER NOT NULL,
                offer_id INTEGER NOT NULL,
                recipient_user_id INTEGER NOT NULL,
                requested_date TEXT NOT NULL,
                sent_at TEXT NOT NULL,
                status TEXT NOT NULL CHECK (status IN ('started','success','error')),
                FOREIGN KEY (requester_user_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (offer_id) REFERENCES representation_offers(id) ON DELETE CASCADE,
                FOREIGN KEY (recipient_user_id) REFERENCES users(id) ON DELETE CASCADE
            )
            SQL);
        $this->migrateRepresentationContactLogHistory();
        $this->connection->exec('CREATE INDEX IF NOT EXISTS idx_representation_contact_rate ON representation_contact_log(requester_user_id, sent_at)');
        $this->connection->exec('CREATE INDEX IF NOT EXISTS idx_representation_contact_duplicate ON representation_contact_log(requester_user_id, offer_id, requested_date, sent_at)');
        $this->connection->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS representation_requests (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                org_id INTEGER NOT NULL,
                request_date TEXT NOT NULL,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL,
                UNIQUE (user_id, org_id, request_date),
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (org_id) REFERENCES organizations(org_id) ON DELETE CASCADE
            )
            SQL);
        $this->connection->exec('CREATE INDEX IF NOT EXISTS idx_representation_requests_user_date ON representation_requests(user_id, org_id, request_date)');
        $this->connection->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS representation_request_contact_log (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                contact_user_id INTEGER NOT NULL,
                request_id INTEGER NOT NULL,
                recipient_user_id INTEGER NOT NULL,
                sent_at TEXT NOT NULL,
                status TEXT NOT NULL CHECK (status IN ('started','success','error')),
                FOREIGN KEY (contact_user_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (request_id) REFERENCES representation_requests(id) ON DELETE CASCADE,
                FOREIGN KEY (recipient_user_id) REFERENCES users(id) ON DELETE CASCADE
            )
            SQL);
        $this->connection->exec('CREATE INDEX IF NOT EXISTS idx_request_contact_rate ON representation_request_contact_log(contact_user_id, sent_at)');
        $this->connection->exec('CREATE INDEX IF NOT EXISTS idx_request_contact_duplicate ON representation_request_contact_log(contact_user_id, request_id, sent_at)');
        $this->connection->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS representation_anonymous_request_contact_log (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                request_id INTEGER NOT NULL,
                recipient_user_id INTEGER NOT NULL,
                sender_email_hash TEXT NOT NULL,
                ip_hash TEXT NOT NULL,
                sent_at TEXT NOT NULL,
                status TEXT NOT NULL CHECK (status IN ('started','success','error')),
                FOREIGN KEY (request_id) REFERENCES representation_requests(id) ON DELETE CASCADE,
                FOREIGN KEY (recipient_user_id) REFERENCES users(id) ON DELETE CASCADE
            )
            SQL);
        $this->connection->exec('CREATE INDEX IF NOT EXISTS idx_anonymous_request_contact_rate ON representation_anonymous_request_contact_log(ip_hash, sent_at)');
        $this->connection->exec('CREATE INDEX IF NOT EXISTS idx_anonymous_request_contact_duplicate ON representation_anonymous_request_contact_log(request_id, sender_email_hash, sent_at)');
        $this->migrateRepresentationOffersToSingleChapter();
    }

    private function migrateRepresentationContactLogHistory(): void
    {
        $sql = (string) $this->connection->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='representation_contact_log'")->fetchColumn();
        if (!str_contains($sql, 'offer_id INTEGER NOT NULL') && str_contains($sql, 'ON DELETE SET NULL')) return;
        $this->connection->exec(<<<'SQL'
            CREATE TABLE representation_contact_log_new (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                requester_user_id INTEGER NOT NULL,
                offer_id INTEGER,
                recipient_user_id INTEGER NOT NULL,
                requested_date TEXT NOT NULL,
                sent_at TEXT NOT NULL,
                status TEXT NOT NULL CHECK (status IN ('started','success','error')),
                FOREIGN KEY (requester_user_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (offer_id) REFERENCES representation_offers(id) ON DELETE SET NULL,
                FOREIGN KEY (recipient_user_id) REFERENCES users(id) ON DELETE CASCADE
            )
            SQL);
        $this->connection->exec('INSERT INTO representation_contact_log_new SELECT * FROM representation_contact_log');
        $this->connection->exec('DROP TABLE representation_contact_log');
        $this->connection->exec('ALTER TABLE representation_contact_log_new RENAME TO representation_contact_log');
    }

    private function migrateEmailTemplates(): void
    {
        $sql = (string) $this->connection->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='email_templates'")->fetchColumn();
        if (!str_contains($sql, "'verify_email', 'reset_password'")) return;
        $this->connection->exec('ALTER TABLE email_templates RENAME TO email_templates_legacy');
        $this->connection->exec('CREATE TABLE email_templates (template_key TEXT PRIMARY KEY, subject TEXT NOT NULL, body TEXT NOT NULL, updated_at TEXT NOT NULL)');
        $this->connection->exec('INSERT INTO email_templates SELECT * FROM email_templates_legacy');
        $this->connection->exec('DROP TABLE email_templates_legacy');
    }

    private function migrateRepresentationOffersToSingleChapter(): void
    {
        $legacy = $this->connection->query('SELECT * FROM representation_offers WHERE org_id IS NULL ORDER BY id')->fetchAll();
        $dates = $this->connection->prepare('SELECT offer_date FROM representation_offer_dates WHERE offer_id = :offer_id ORDER BY offer_date');
        $signatureUpdate = $this->connection->prepare('UPDATE representation_offers SET date_signature = :signature WHERE id = :id');
        foreach ($this->connection->query("SELECT id FROM representation_offers WHERE org_id IS NOT NULL AND all_dates = 0 AND date_signature = ''")->fetchAll() as $offer) {
            $dates->execute([':offer_id' => $offer['id']]);
            $signatureUpdate->execute([':signature' => implode('|', $dates->fetchAll(PDO::FETCH_COLUMN)), ':id' => $offer['id']]);
        }
        if ($legacy === []) return;
        $this->connection->beginTransaction();
        try {
            $chapters = $this->connection->prepare('SELECT org_id FROM representation_offer_chapters WHERE offer_id = :offer_id ORDER BY org_id');
            $assign = $this->connection->prepare('UPDATE representation_offers SET org_id = :org_id, date_signature = :signature WHERE id = :id');
            $clone = $this->connection->prepare('INSERT INTO representation_offers (user_id, org_id, all_dates, date_signature, created_at, updated_at) VALUES (:user_id, :org_id, :all_dates, :signature, :created_at, :updated_at)');
            $cloneDate = $this->connection->prepare('INSERT INTO representation_offer_dates (offer_id, offer_date) VALUES (:offer_id, :offer_date)');
            foreach ($legacy as $offer) {
                $chapters->execute([':offer_id' => $offer['id']]); $orgIds = array_map('intval', $chapters->fetchAll(PDO::FETCH_COLUMN));
                if ($orgIds === []) continue;
                $dates->execute([':offer_id' => $offer['id']]); $offerDates = $dates->fetchAll(PDO::FETCH_COLUMN); $signature = implode('|', $offerDates);
                $assign->execute([':org_id' => array_shift($orgIds), ':signature' => $signature, ':id' => $offer['id']]);
                foreach ($orgIds as $orgId) {
                    $clone->execute([':user_id' => $offer['user_id'], ':org_id' => $orgId, ':all_dates' => $offer['all_dates'], ':signature' => $signature, ':created_at' => $offer['created_at'], ':updated_at' => $offer['updated_at']]);
                    $cloneId = (int) $this->connection->lastInsertId();
                    foreach ($offerDates as $offerDate) $cloneDate->execute([':offer_id' => $cloneId, ':offer_date' => $offerDate]);
                }
            }
            $this->connection->commit();
        } catch (Throwable $exception) {
            if ($this->connection->inTransaction()) $this->connection->rollBack();
            throw $exception;
        }
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
