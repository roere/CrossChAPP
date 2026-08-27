<?php

declare(strict_types=1);

final class MysqlSchema
{
    public const LATEST_VERSION = 10;

    public static function migrate(PDO $db): void
    {
        $statements = self::statements();
        $db->exec(array_shift($statements));
        $version = (int) $db->query('SELECT COALESCE(MAX(version),0) FROM schema_migrations')->fetchColumn();
        if ($version < 1) {
            foreach ($statements as $sql) {
                $db->exec($sql);
            }
            $db->exec("INSERT INTO schema_migrations(version,applied_at) VALUES(1,UTC_TIMESTAMP())");
        }
        if ($version < 2) {
            $engine = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
            $db->exec("CREATE TABLE IF NOT EXISTS legal_settings(id INT PRIMARY KEY,imprint_text LONGTEXT NOT NULL,privacy_text LONGTEXT NOT NULL,updated_at VARCHAR(32) NOT NULL)$engine");
            $db->exec("INSERT INTO schema_migrations(version,applied_at) VALUES(2,UTC_TIMESTAMP())");
        }
        if ($version < 3) {
            $engine = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
            $db->exec("CREATE TABLE IF NOT EXISTS representation_assignments(id BIGINT AUTO_INCREMENT PRIMARY KEY,request_id BIGINT NULL,offer_id BIGINT NULL,chapter_org_id BIGINT NOT NULL,representation_date CHAR(10) NOT NULL,requester_user_id BIGINT NOT NULL,representative_user_id BIGINT NOT NULL,status VARCHAR(16) NOT NULL CHECK(status IN ('active','cancelled')),active_slot_key VARCHAR(160) NULL UNIQUE,accepted_at VARCHAR(32) NOT NULL,accepted_by_user_id BIGINT NOT NULL,cancelled_at VARCHAR(32) NULL,cancelled_by_user_id BIGINT NULL,cancellation_reason VARCHAR(500) NULL,created_at VARCHAR(32) NOT NULL,updated_at VARCHAR(32) NOT NULL,INDEX idx_assignment_requester(requester_user_id,status,representation_date),INDEX idx_assignment_representative(representative_user_id,status,representation_date),FOREIGN KEY(request_id) REFERENCES representation_requests(id) ON DELETE SET NULL,FOREIGN KEY(offer_id) REFERENCES representation_offers(id) ON DELETE SET NULL,FOREIGN KEY(chapter_org_id) REFERENCES organizations(org_id),FOREIGN KEY(requester_user_id) REFERENCES users(id) ON DELETE CASCADE,FOREIGN KEY(representative_user_id) REFERENCES users(id) ON DELETE CASCADE,FOREIGN KEY(accepted_by_user_id) REFERENCES users(id),FOREIGN KEY(cancelled_by_user_id) REFERENCES users(id) ON DELETE SET NULL)$engine");
            $db->exec("CREATE TABLE IF NOT EXISTS representation_acceptance_tokens(id BIGINT AUTO_INCREMENT PRIMARY KEY,token_hash CHAR(64) NOT NULL UNIQUE,direction VARCHAR(32) NOT NULL CHECK(direction IN ('request_contact','offer_contact')),request_id BIGINT NULL,offer_id BIGINT NULL,contact_log_id BIGINT NULL,representation_date CHAR(10) NOT NULL,requester_user_id BIGINT NOT NULL,representative_user_id BIGINT NOT NULL,created_at VARCHAR(32) NOT NULL,used_at VARCHAR(32) NULL,invalidated_at VARCHAR(32) NULL,INDEX idx_acceptance_token_context(direction,request_id,offer_id,representation_date),FOREIGN KEY(request_id) REFERENCES representation_requests(id) ON DELETE CASCADE,FOREIGN KEY(offer_id) REFERENCES representation_offers(id) ON DELETE CASCADE,FOREIGN KEY(requester_user_id) REFERENCES users(id) ON DELETE CASCADE,FOREIGN KEY(representative_user_id) REFERENCES users(id) ON DELETE CASCADE)$engine");
            $db->exec("INSERT INTO schema_migrations(version,applied_at) VALUES(3,UTC_TIMESTAMP())");
        }
        if ($version <= 4) {
            self::ensureUserRoleConstraint($db);
            $grantExists=(int)$db->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='user_invitations' AND COLUMN_NAME='verification_grant'")->fetchColumn();
            if($grantExists===0)$db->exec("ALTER TABLE user_invitations ADD COLUMN verification_grant VARCHAR(32) NOT NULL DEFAULT 'manual_verified' CHECK(verification_grant IN ('manual_verified')) AFTER created_by_user_id");
            if ($version < 4) {
                $db->exec("INSERT INTO schema_migrations(version,applied_at) VALUES(4,UTC_TIMESTAMP())");
            }
        }
        if ($version < 5) {
            $slugExists=(int)$db->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='organizations' AND COLUMN_NAME='short_link_slug'")->fetchColumn();
            if($slugExists===0)$db->exec('ALTER TABLE organizations ADD COLUMN short_link_slug VARCHAR(255) NULL AFTER chapter_name');
            $indexExists=(int)$db->query("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='organizations' AND INDEX_NAME='uq_organizations_short_link_slug'")->fetchColumn();
            if($indexExists===0)$db->exec('ALTER TABLE organizations ADD UNIQUE KEY uq_organizations_short_link_slug(short_link_slug)');
            require_once __DIR__.'/ChapterShortLink.php';ChapterShortLink::backfill($db);
            $db->exec("INSERT INTO schema_migrations(version,applied_at) VALUES(5,UTC_TIMESTAMP())");
        }
        if ($version < 6) {
            $engine = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
            $db->exec("CREATE TABLE IF NOT EXISTS bni_request_throttle(id INT PRIMARY KEY,last_reserved_start_ms BIGINT NOT NULL)$engine");
            $db->exec('INSERT IGNORE INTO bni_request_throttle(id,last_reserved_start_ms) VALUES(1,0)');
            $db->exec("INSERT INTO schema_migrations(version,applied_at) VALUES(6,UTC_TIMESTAMP())");
        }
        if ($version < 7) {
            $engine = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
            $db->exec("CREATE TABLE IF NOT EXISTS bni_request_events(id BIGINT AUTO_INCREMENT PRIMARY KEY,started_at_ms BIGINT NOT NULL,request_type VARCHAR(40) NOT NULL,trigger_type VARCHAR(40),http_status INT,result_type VARCHAR(40),INDEX idx_bni_request_events_started(started_at_ms))$engine");
            $db->exec("INSERT INTO schema_migrations(version,applied_at) VALUES(7,UTC_TIMESTAMP())");
        }
        if ($version < 8) {
            $engine = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
            $db->exec("CREATE TABLE IF NOT EXISTS user_error_log(id BIGINT AUTO_INCREMENT PRIMARY KEY,created_at VARCHAR(32) NOT NULL,created_at_ms BIGINT NOT NULL,user_message VARCHAR(500) NOT NULL,technical_message VARCHAR(1000) NOT NULL,error_code VARCHAR(80),context VARCHAR(80),user_id BIGINT NULL,route VARCHAR(190),INDEX idx_user_error_log_created(created_at_ms),FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE SET NULL)$engine");
            $db->exec("INSERT INTO schema_migrations(version,applied_at) VALUES(8,UTC_TIMESTAMP())");
        }
        if ($version < 9) {
            foreach(['checked_first_name VARCHAR(120)','checked_last_name VARCHAR(120)','org_id BIGINT','chapter_name VARCHAR(255)','match_count INT','match_references TEXT']as$definition){[$column]=explode(' ',$definition,2);$exists=(int)$db->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='user_error_log' AND COLUMN_NAME='{$column}'")->fetchColumn();if($exists===0)$db->exec("ALTER TABLE user_error_log ADD COLUMN {$definition}");}
            $db->exec("INSERT INTO schema_migrations(version,applied_at) VALUES(9,UTC_TIMESTAMP())");
        }
        if($version<10){$engine=' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';$db->exec("CREATE TABLE IF NOT EXISTS user_keywords(id BIGINT AUTO_INCREMENT PRIMARY KEY,user_id BIGINT NOT NULL,keyword VARCHAR(40) NOT NULL,normalized_keyword VARCHAR(40) NOT NULL,created_at VARCHAR(32) NOT NULL,UNIQUE KEY uq_user_keywords_normalized(user_id,normalized_keyword),INDEX idx_user_keywords_user(user_id),FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE)$engine");$db->exec("INSERT INTO schema_migrations(version,applied_at)VALUES(10,UTC_TIMESTAMP())");}
        if (getenv('CROSSCHAPP_DB_SKIP_SEED') === '1') {
            return;
        }
        $db->exec("INSERT IGNORE INTO automation_settings(id,updated_at) VALUES(1,UTC_TIMESTAMP())");
        $db->exec("INSERT IGNORE INTO automation_runtime(id,updated_at) VALUES(1,UTC_TIMESTAMP())");
        $db->exec("INSERT IGNORE INTO mail_settings(id,updated_at) VALUES(1,UTC_TIMESTAMP())");
        require_once __DIR__ . '/LegalSettingsRepository.php';
        $legal=$db->prepare('INSERT IGNORE INTO legal_settings(id,imprint_text,privacy_text,updated_at)VALUES(1,:imprint,:privacy,:updated)');
        $legal->execute([':imprint'=>LegalSettingsRepository::DEFAULT_IMPRINT,':privacy'=>LegalSettingsRepository::DEFAULT_PRIVACY,':updated'=>gmdate('Y-m-d\TH:i:s\Z')]);
        self::seed($db);
    }

    private static function ensureUserRoleConstraint(PDO $db, string $table = 'users'): void
    {
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
            throw new InvalidArgumentException('Invalid table name.');
        }
        $quotedTable = '`' . $table . '`';
        $invalidRoles = $db->query("SELECT COUNT(*) FROM {$quotedTable} WHERE role NOT IN ('user','user_manager','admin')")->fetchColumn();
        if ((int) $invalidRoles > 0) {
            throw new RuntimeException('The users table contains unsupported roles.');
        }

        $query = $db->prepare(<<<'SQL'
            SELECT tc.CONSTRAINT_NAME, cc.LEVEL, cc.CHECK_CLAUSE
            FROM information_schema.TABLE_CONSTRAINTS tc
            INNER JOIN information_schema.CHECK_CONSTRAINTS cc
                ON cc.CONSTRAINT_SCHEMA = tc.CONSTRAINT_SCHEMA
               AND cc.TABLE_NAME = tc.TABLE_NAME
               AND cc.CONSTRAINT_NAME = tc.CONSTRAINT_NAME
            WHERE tc.CONSTRAINT_SCHEMA = DATABASE()
              AND tc.TABLE_NAME = :table_name
              AND tc.CONSTRAINT_TYPE = 'CHECK'
            SQL);
        $query->execute([':table_name' => $table]);
        $roleConstraints = [];
        foreach ($query->fetchAll() as $constraint) {
            $clause = strtolower((string) $constraint['CHECK_CLAUSE']);
            if (preg_match('/(?:`role`|\brole\b)\s+in\s*\(/', $clause) === 1) {
                $roleConstraints[] = $constraint;
            }
        }

        if (count($roleConstraints) === 1) {
            $constraint = $roleConstraints[0];
            $clause = strtolower((string) $constraint['CHECK_CLAUSE']);
            if (
                (string) $constraint['CONSTRAINT_NAME'] === 'chk_users_role'
                && str_contains($clause, 'user_manager')
                && str_contains($clause, 'admin')
                && str_contains($clause, 'user')
            ) {
                return;
            }
        }

        $columnCheckPresent = false;
        foreach ($roleConstraints as $constraint) {
            if (strcasecmp((string) $constraint['LEVEL'], 'Column') === 0) {
                $columnCheckPresent = true;
                continue;
            }
            $name = str_replace('`', '``', (string) $constraint['CONSTRAINT_NAME']);
            $db->exec("ALTER TABLE {$quotedTable} DROP CONSTRAINT `{$name}`");
        }
        if ($columnCheckPresent) {
            // MariaDB exposes an inline column CHECK in information_schema, but it
            // cannot be removed with DROP CONSTRAINT. MODIFY removes only that
            // inline CHECK and preserves the column values and table identity.
            $db->exec("ALTER TABLE {$quotedTable} MODIFY COLUMN role VARCHAR(20) NOT NULL DEFAULT 'user'");
        }

        $db->exec("ALTER TABLE {$quotedTable} ADD CONSTRAINT chk_users_role CHECK(role IN ('user','user_manager','admin'))");
    }

    /** @return list<string> */
    private static function statements(): array
    {
        $engine = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
        return [
            "CREATE TABLE IF NOT EXISTS schema_migrations(version INT PRIMARY KEY,applied_at VARCHAR(32) NOT NULL)$engine",
            "CREATE TABLE IF NOT EXISTS bni_request_throttle(id INT PRIMARY KEY,last_reserved_start_ms BIGINT NOT NULL)$engine",
            "CREATE TABLE IF NOT EXISTS bni_request_events(id BIGINT AUTO_INCREMENT PRIMARY KEY,started_at_ms BIGINT NOT NULL,request_type VARCHAR(40) NOT NULL,trigger_type VARCHAR(40),http_status INT,result_type VARCHAR(40),INDEX idx_bni_request_events_started(started_at_ms))$engine",
            "CREATE TABLE IF NOT EXISTS organizations(id BIGINT AUTO_INCREMENT PRIMARY KEY,org_id BIGINT NOT NULL UNIQUE,cms_security_hash VARCHAR(255),country_code VARCHAR(8),org_type VARCHAR(64),longitude DOUBLE,latitude DOUBLE,chapter_name VARCHAR(255),short_link_slug VARCHAR(255),region VARCHAR(255),region_id BIGINT,city VARCHAR(255),postal_code VARCHAR(32),street VARCHAR(255),venue VARCHAR(255),meeting_day VARCHAR(32),meeting_time VARCHAR(32),meeting_type VARCHAR(64),meeting_duration INT,member_count INT,chapter_url TEXT,visitor_registration_url TEXT,online_meeting_link TEXT,timezone VARCHAR(128),status VARCHAR(64),description TEXT,detail_status VARCHAR(32) NOT NULL DEFAULT 'not_loaded' CHECK(detail_status IN ('not_loaded','loaded','error')),map_loaded_at VARCHAR(32),details_loaded_at VARCHAR(32),created_at VARCHAR(32) NOT NULL,updated_at VARCHAR(32) NOT NULL,UNIQUE KEY uq_organizations_short_link_slug(short_link_slug),INDEX idx_organizations_detail_status(detail_status),INDEX idx_organizations_country_type(country_code,org_type))$engine",
            "CREATE TABLE IF NOT EXISTS automation_settings(id INT PRIMARY KEY,usage_refresh_enabled TINYINT(1) NOT NULL DEFAULT 0,usage_refresh_days INT NOT NULL DEFAULT 7,automatic_refresh_enabled TINYINT(1) NOT NULL DEFAULT 0,automatic_refresh_days INT NOT NULL DEFAULT 30,automatic_refresh_batch_size INT NOT NULL DEFAULT 10,automatic_refresh_interval_minutes INT NOT NULL DEFAULT 60,automatic_refresh_daily_limit INT NOT NULL DEFAULT 50,map_refresh_enabled TINYINT(1) NOT NULL DEFAULT 0,map_refresh_days INT NOT NULL DEFAULT 1,updated_at VARCHAR(32) NOT NULL)$engine",
            "CREATE TABLE IF NOT EXISTS chapter_refresh_log(id BIGINT AUTO_INCREMENT PRIMARY KEY,org_id BIGINT NOT NULL,trigger_type VARCHAR(32) NOT NULL,started_at VARCHAR(32) NOT NULL,finished_at VARCHAR(32),status VARCHAR(32) NOT NULL,http_status INT,error_category VARCHAR(64),INDEX idx_refresh_log_finished(finished_at),INDEX idx_refresh_log_trigger_status(trigger_type,status))$engine",
            "CREATE TABLE IF NOT EXISTS chapter_refresh_locks(org_id BIGINT PRIMARY KEY,owner_token VARCHAR(128) NOT NULL,lock_until VARCHAR(32) NOT NULL,created_at VARCHAR(32) NOT NULL,INDEX idx_refresh_locks_until(lock_until))$engine",
            "CREATE TABLE IF NOT EXISTS automation_runtime(id INT PRIMARY KEY,worker_last_seen_at VARCHAR(32),last_check_at VARCHAR(32),next_check_at VARCHAR(32),last_map_refresh_at VARCHAR(32),map_lock_token VARCHAR(128),map_lock_until VARCHAR(32),map_retry_after_until VARCHAR(32),updated_at VARCHAR(32) NOT NULL)$engine",
            "CREATE TABLE IF NOT EXISTS map_refresh_log(id BIGINT AUTO_INCREMENT PRIMARY KEY,trigger_type VARCHAR(32) NOT NULL,started_at VARCHAR(32) NOT NULL,finished_at VARCHAR(32),status VARCHAR(32) NOT NULL,http_status INT,error_category VARCHAR(64),INDEX idx_map_refresh_log_time(started_at,trigger_type,status))$engine",
            "CREATE TABLE IF NOT EXISTS users(id BIGINT AUTO_INCREMENT PRIMARY KEY,first_name VARCHAR(120) NOT NULL,last_name VARCHAR(120) NOT NULL,username VARCHAR(190) UNIQUE,email VARCHAR(254) NOT NULL UNIQUE,password_hash VARCHAR(255) NOT NULL,home_chapter_org_id BIGINT,role VARCHAR(20) NOT NULL DEFAULT 'user' CHECK(role IN ('user','user_manager','admin')),status VARCHAR(20) NOT NULL DEFAULT 'pending' CHECK(status IN ('pending','active','disabled')),email_verified_at VARCHAR(32),created_at VARCHAR(32) NOT NULL,updated_at VARCHAR(32) NOT NULL,last_login_at VARCHAR(32),bni_verification_status VARCHAR(32) NOT NULL DEFAULT 'unverified' CHECK(bni_verification_status IN ('unverified','directory_match','manual_verified')),bni_verified_at VARCHAR(32),bni_verified_by_user_id BIGINT,bni_external_member_ref VARCHAR(255),INDEX idx_users_home_chapter(home_chapter_org_id),CONSTRAINT fk_users_home FOREIGN KEY(home_chapter_org_id) REFERENCES organizations(org_id) ON DELETE SET NULL,CONSTRAINT fk_users_verifier FOREIGN KEY(bni_verified_by_user_id) REFERENCES users(id) ON DELETE SET NULL)$engine",
            "CREATE TABLE IF NOT EXISTS user_keywords(id BIGINT AUTO_INCREMENT PRIMARY KEY,user_id BIGINT NOT NULL,keyword VARCHAR(40) NOT NULL,normalized_keyword VARCHAR(40) NOT NULL,created_at VARCHAR(32) NOT NULL,UNIQUE KEY uq_user_keywords_normalized(user_id,normalized_keyword),INDEX idx_user_keywords_user(user_id),FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE)$engine",
            "CREATE TABLE IF NOT EXISTS user_error_log(id BIGINT AUTO_INCREMENT PRIMARY KEY,created_at VARCHAR(32) NOT NULL,created_at_ms BIGINT NOT NULL,user_message VARCHAR(500) NOT NULL,technical_message VARCHAR(1000) NOT NULL,error_code VARCHAR(80),context VARCHAR(80),user_id BIGINT NULL,route VARCHAR(190),checked_first_name VARCHAR(120),checked_last_name VARCHAR(120),org_id BIGINT,chapter_name VARCHAR(255),match_count INT,match_references TEXT,INDEX idx_user_error_log_created(created_at_ms),FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE SET NULL)$engine",
            "CREATE TABLE IF NOT EXISTS email_verification_tokens(id BIGINT AUTO_INCREMENT PRIMARY KEY,user_id BIGINT,token_hash CHAR(64) NOT NULL UNIQUE,expires_at VARCHAR(32) NOT NULL,created_at VARCHAR(32) NOT NULL,used_at VARCHAR(32),INDEX idx_email_verification_tokens_user_expiry(user_id,expires_at),FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE SET NULL)$engine",
            "CREATE TABLE IF NOT EXISTS password_reset_tokens(id BIGINT AUTO_INCREMENT PRIMARY KEY,user_id BIGINT,token_hash CHAR(64) NOT NULL UNIQUE,expires_at VARCHAR(32) NOT NULL,created_at VARCHAR(32) NOT NULL,used_at VARCHAR(32),INDEX idx_password_reset_tokens_user_expiry(user_id,expires_at),FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE SET NULL)$engine",
            "CREATE TABLE IF NOT EXISTS mail_settings(id INT PRIMARY KEY,smtp_host VARCHAR(255),smtp_port INT NOT NULL DEFAULT 587,smtp_username VARCHAR(255),smtp_password TEXT,encryption VARCHAR(20) NOT NULL DEFAULT 'starttls',sender_email VARCHAR(254),sender_name VARCHAR(255) NOT NULL DEFAULT 'CrossChAPP',base_url VARCHAR(512) NOT NULL DEFAULT 'http://localhost:8082',updated_at VARCHAR(32) NOT NULL)$engine",
            "CREATE TABLE IF NOT EXISTS email_templates(template_key VARCHAR(100) PRIMARY KEY,subject TEXT NOT NULL,body LONGTEXT NOT NULL,updated_at VARCHAR(32) NOT NULL)$engine",
            "CREATE TABLE IF NOT EXISTS auth_attempts(id BIGINT AUTO_INCREMENT PRIMARY KEY,attempt_type VARCHAR(40) NOT NULL CHECK(attempt_type IN ('login','password_reset','resend_verification')),identifier_hash CHAR(64) NOT NULL,ip_hash CHAR(64) NOT NULL,successful TINYINT(1) NOT NULL DEFAULT 0 CHECK(successful IN (0,1)),attempted_at VARCHAR(32) NOT NULL,INDEX idx_auth_attempts_limit(attempt_type,identifier_hash,ip_hash,attempted_at))$engine",
            "CREATE TABLE IF NOT EXISTS bni_member_check_attempts(id BIGINT AUTO_INCREMENT PRIMARY KEY,ip_hash CHAR(64) NOT NULL,attempted_at VARCHAR(32) NOT NULL,INDEX idx_bni_member_check_rate(ip_hash,attempted_at))$engine",
            "CREATE TABLE IF NOT EXISTS bni_member_directory_configs(org_id BIGINT PRIMARY KEY,endpoint TEXT NOT NULL,parameters LONGTEXT NOT NULL,languages LONGTEXT NOT NULL,website_type VARCHAR(32) NOT NULL,website_id VARCHAR(64) NOT NULL,mapped_widget_settings LONGTEXT NOT NULL,referer TEXT NOT NULL,updated_at VARCHAR(32) NOT NULL,FOREIGN KEY(org_id) REFERENCES organizations(org_id) ON DELETE CASCADE)$engine",
            "CREATE TABLE IF NOT EXISTS bni_member_check_lock(id INT PRIMARY KEY,owner_token VARCHAR(128) NOT NULL,lock_until VARCHAR(32) NOT NULL)$engine",
            "CREATE TABLE IF NOT EXISTS user_invitations(id BIGINT AUTO_INCREMENT PRIMARY KEY,first_name VARCHAR(120) NOT NULL,last_name VARCHAR(120) NOT NULL,email VARCHAR(254) NOT NULL,home_chapter_org_id BIGINT NOT NULL,token_hash CHAR(64) NOT NULL UNIQUE,expires_at VARCHAR(32) NOT NULL,created_at VARCHAR(32) NOT NULL,sent_at VARCHAR(32),accepted_at VARCHAR(32),created_by_user_id BIGINT NOT NULL,verification_grant VARCHAR(32) NOT NULL DEFAULT 'manual_verified' CHECK(verification_grant IN ('manual_verified')),status VARCHAR(32) NOT NULL DEFAULT 'pending' CHECK(status IN ('pending','accepted','expired','cancelled')),pending_email VARCHAR(254) AS (CASE WHEN status='pending' THEN LOWER(email) ELSE NULL END) PERSISTENT,UNIQUE KEY uq_user_invitations_pending_email(pending_email),INDEX idx_user_invitations_email_status(email,status,expires_at),FOREIGN KEY(home_chapter_org_id) REFERENCES organizations(org_id),FOREIGN KEY(created_by_user_id) REFERENCES users(id))$engine",
            "CREATE TABLE IF NOT EXISTS representation_offers(id BIGINT AUTO_INCREMENT PRIMARY KEY,user_id BIGINT NOT NULL,org_id BIGINT NOT NULL,all_dates TINYINT(1) NOT NULL DEFAULT 0 CHECK(all_dates IN (0,1)),date_signature VARCHAR(255) NOT NULL,created_at VARCHAR(32) NOT NULL,updated_at VARCHAR(32) NOT NULL,INDEX idx_representation_offers_user(user_id),INDEX idx_representation_offers_chapter(org_id,user_id),UNIQUE KEY uq_representation_offer(user_id,org_id,all_dates,date_signature),FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,FOREIGN KEY(org_id) REFERENCES organizations(org_id) ON DELETE CASCADE)$engine",
            "CREATE TABLE IF NOT EXISTS representation_offer_chapters(id BIGINT AUTO_INCREMENT PRIMARY KEY,offer_id BIGINT,org_id BIGINT NOT NULL,UNIQUE KEY uq_offer_chapter(offer_id,org_id),INDEX idx_representation_chapters_org(org_id,offer_id),FOREIGN KEY(offer_id) REFERENCES representation_offers(id) ON DELETE SET NULL,FOREIGN KEY(org_id) REFERENCES organizations(org_id) ON DELETE CASCADE)$engine",
            "CREATE TABLE IF NOT EXISTS representation_offer_dates(id BIGINT AUTO_INCREMENT PRIMARY KEY,offer_id BIGINT NOT NULL,offer_date CHAR(10) NOT NULL,UNIQUE KEY uq_offer_date(offer_id,offer_date),INDEX idx_representation_dates_offer_date(offer_id,offer_date),FOREIGN KEY(offer_id) REFERENCES representation_offers(id) ON DELETE CASCADE)$engine",
            "CREATE TABLE IF NOT EXISTS representation_settings(id INT PRIMARY KEY,contact_hint TEXT NOT NULL,request_contact_hint TEXT NOT NULL,offer_custom_message TEXT NOT NULL,request_custom_message TEXT NOT NULL,updated_at VARCHAR(32) NOT NULL)$engine",
            "CREATE TABLE IF NOT EXISTS representation_contact_log(id BIGINT AUTO_INCREMENT PRIMARY KEY,requester_user_id BIGINT NOT NULL,offer_id BIGINT,recipient_user_id BIGINT NOT NULL,requested_date CHAR(10) NOT NULL,sent_at VARCHAR(32) NOT NULL,status VARCHAR(32) NOT NULL,INDEX idx_representation_contact_rate(requester_user_id,sent_at),INDEX idx_representation_contact_duplicate(requester_user_id,offer_id,requested_date,sent_at),FOREIGN KEY(requester_user_id) REFERENCES users(id) ON DELETE CASCADE,FOREIGN KEY(offer_id) REFERENCES representation_offers(id) ON DELETE SET NULL,FOREIGN KEY(recipient_user_id) REFERENCES users(id) ON DELETE CASCADE)$engine",
            "CREATE TABLE IF NOT EXISTS representation_requests(id BIGINT AUTO_INCREMENT PRIMARY KEY,user_id BIGINT NOT NULL,org_id BIGINT NOT NULL,request_date CHAR(10) NOT NULL,created_at VARCHAR(32) NOT NULL,updated_at VARCHAR(32) NOT NULL,UNIQUE KEY uq_representation_request(user_id,org_id,request_date),INDEX idx_representation_requests_user_date(user_id,org_id,request_date),INDEX idx_representation_requests_org_date(org_id,request_date),FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,FOREIGN KEY(org_id) REFERENCES organizations(org_id) ON DELETE CASCADE)$engine",
            "CREATE TABLE IF NOT EXISTS representation_request_contact_log(id BIGINT AUTO_INCREMENT PRIMARY KEY,contact_user_id BIGINT NOT NULL,request_id BIGINT NOT NULL,recipient_user_id BIGINT NOT NULL,sent_at VARCHAR(32) NOT NULL,status VARCHAR(32) NOT NULL,INDEX idx_request_contact_rate(contact_user_id,sent_at),INDEX idx_request_contact_duplicate(contact_user_id,request_id,sent_at),FOREIGN KEY(contact_user_id) REFERENCES users(id) ON DELETE CASCADE,FOREIGN KEY(request_id) REFERENCES representation_requests(id) ON DELETE CASCADE,FOREIGN KEY(recipient_user_id) REFERENCES users(id) ON DELETE CASCADE)$engine",
            "CREATE TABLE IF NOT EXISTS representation_anonymous_request_contact_log(id BIGINT AUTO_INCREMENT PRIMARY KEY,request_id BIGINT NOT NULL,recipient_user_id BIGINT NOT NULL,sender_email_hash CHAR(64) NOT NULL,ip_hash CHAR(64) NOT NULL,sent_at VARCHAR(32) NOT NULL,status VARCHAR(32) NOT NULL,INDEX idx_anonymous_request_contact_rate(ip_hash,sent_at),INDEX idx_anonymous_request_contact_duplicate(request_id,sender_email_hash,sent_at),FOREIGN KEY(request_id) REFERENCES representation_requests(id) ON DELETE CASCADE,FOREIGN KEY(recipient_user_id) REFERENCES users(id) ON DELETE CASCADE)$engine",
        ];
    }

    private static function seed(PDO $db): void
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $admin = $db->prepare("INSERT IGNORE INTO users(first_name,last_name,username,email,password_hash,role,status,email_verified_at,created_at,updated_at) VALUES('admin','','admin','admin@localhost.invalid',:hash,'admin','active',:verified,:created,:updated)");
        $admin->execute([':hash'=>'$2y$10$/w.85OIJmzun7pFjgRPPaeD4Q4p.otU/T4wBIzkhPlniP1OY79sbW',':verified'=>$now,':created'=>$now,':updated'=>$now]);
        $setting = $db->prepare("INSERT IGNORE INTO representation_settings(id,contact_hint,request_contact_hint,offer_custom_message,request_custom_message,updated_at) VALUES(1,:contact,:request,:offer_message,:request_message,:now)");
        $setting->execute([':contact'=>'Möchtest Du eine Anfrage senden? Die Person erhält Deinen Namen und Deine eMail Adresse und kann sich bei Dir zurückmelden.',':request'=>'Möchtest Du anbieten, die Vertretung zu übernehmen? Die Person erhält Deinen Namen und Deine eMail-Adresse und kann sich bei Dir zurückmelden.',':offer_message'=>"Hallo,\n\nich suche für diesen Termin eine Vertretung für mein BNI-Chapter und würde mich freuen, wenn Du Dich bei mir meldest.\n\nViele Grüße",':request_message'=>"Hallo,\n\nich kann mir vorstellen, die Vertretung an diesem Termin zu übernehmen. Melde Dich gerne bei mir, damit wir die Details abstimmen können.\n\nViele Grüße",':now'=>$now]);
        $templates = [
            'verify_email'=>['Bitte bestätige deine E-Mail-Adresse bei CrossChAPP',"Hallo {{first_name}},\n\nvielen Dank für deine Registrierung bei CrossChAPP.\n\nBitte bestätige deine E-Mail-Adresse über folgenden Link:\n\n{{verification_link}}\n\nDer Link ist 24 Stunden gültig.\n\nViele Grüße\nCrossChAPP"],
            'reset_password'=>['Neues Passwort für CrossChAPP festlegen',"Hallo {{first_name}},\n\nfür dein CrossChAPP-Konto wurde das Zurücksetzen des Passworts angefordert.\n\nÜber folgenden Link kannst du ein neues Passwort vergeben:\n\n{{reset_link}}\n\nDer Link ist 60 Minuten gültig.\n\nFalls du das Zurücksetzen nicht angefordert hast, kannst du diese Nachricht ignorieren.\n\nViele Grüße\nCrossChAPP"],
            'user_invitation'=>['Einladung zu CrossChAPP',"Hallo {{first_name}},\n\ndu wurdest zu CrossChAPP eingeladen.\n\nÜber den folgenden Link kannst du dein Konto aktivieren und ein Passwort vergeben:\n\n{{invitation_link}}\n\nChapter: {{chapter}}\n\nViele Grüße\n{{app_name}}"],
            'representation_contact'=>['CrossChAPP – Vertretungsanfrage für {{requested_date}}',"Hallo {{provider_first_name}},\n\n{{custom_message}}\n\n---\nAnfrage von:\n{{requester_full_name}}\n{{requester_email}}\nChapter: {{requester_chapter}}\nTermin: {{requested_date}}\n\nViele Grüße\n{{app_name}}"],
            'representation_request_contact'=>['CrossChAPP – Rückmeldung zu deinem Vertretungsgesuch am {{requested_date}}',"Hallo {{request_owner_first_name}},\n\n{{custom_message}}\n\n---\nRückmeldung von:\n{{contact_full_name}}\n{{contact_email}}\nBNI-Chapter: {{contact_chapter}}\nVertretung für: {{requested_chapter}}\nTermin: {{requested_date}}\n\nViele Grüße\n{{app_name}}"],
            'request_contact_acceptance'=>['CrossChAPP – Vertretungsangebot für {{requested_date}}',"Hallo {{requester_first_name}},\n\n{{custom_message}}\n\n---\nVertretungsangebot von:\n{{representative_full_name}}\n{{representative_email}}\nChapter: {{chapter}}\nTermin: {{requested_date}}\n\nWenn Du dieses Vertretungsangebot annehmen möchtest, öffne den folgenden Link:\n{{acceptance_link}}\n\nDiese Nachricht wurde automatisch versendet. Bitte antworte nicht auf diese E-Mail."],
            'offer_contact_acceptance'=>['CrossChAPP – Vertretungsgesuch für {{requested_date}}',"Hallo {{representative_first_name}},\n\n{{custom_message}}\n\n---\nVertretungsgesuch von:\n{{requester_full_name}}\n{{requester_email}}\nChapter: {{chapter}}\nTermin: {{requested_date}}\n\nWenn Du dieses Vertretungsgesuch annehmen möchtest, öffne den folgenden Link:\n{{acceptance_link}}\n\nDiese Nachricht wurde automatisch versendet. Bitte antworte nicht auf diese E-Mail."],
            'representation_assignment_confirmed_requester'=>['CrossChAPP – Vertretung vereinbart',"Hallo {{requester_first_name}},\n\ndie Vertretung am {{requested_date}} für {{chapter}} wurde vereinbart.\n\nVertreter: {{representative_full_name}}\nE-Mail: {{representative_email}}\n\nDie Vereinbarung ist in CrossChAPP unter „Gefundene Vertreter“ sichtbar.\n\nDiese Nachricht wurde automatisch versendet. Bitte antworte nicht auf diese E-Mail."],
            'representation_assignment_confirmed_representative'=>['CrossChAPP – Vertretung vereinbart',"Hallo {{representative_first_name}},\n\ndie Vertretung am {{requested_date}} für {{chapter}} wurde vereinbart.\n\nSuchender: {{requester_full_name}}\nE-Mail: {{requester_email}}\n\nDie Vereinbarung ist in CrossChAPP unter „Angenommene Vertretungen“ sichtbar.\n\nDiese Nachricht wurde automatisch versendet. Bitte antworte nicht auf diese E-Mail."],
            'representation_assignment_cancelled_requester'=>['CrossChAPP – Vertretung storniert',"Hallo {{requester_first_name}},\n\ndie vereinbarte Vertretung am {{requested_date}} für {{chapter}} wurde von {{cancelled_by}} storniert. Der Termin ist wieder für neue Vertretungen freigegeben.\n\nDiese Nachricht wurde automatisch versendet. Bitte antworte nicht auf diese E-Mail."],
            'representation_assignment_cancelled_representative'=>['CrossChAPP – Vertretung storniert',"Hallo {{representative_first_name}},\n\ndie vereinbarte Vertretung am {{requested_date}} für {{chapter}} wurde von {{cancelled_by}} storniert. Der Termin ist wieder für neue Vertretungen freigegeben.\n\nDiese Nachricht wurde automatisch versendet. Bitte antworte nicht auf diese E-Mail."],
        ];
        $insert = $db->prepare('INSERT IGNORE INTO email_templates(template_key,subject,body,updated_at) VALUES(:key,:subject,:body,:now)');
        foreach ($templates as $key=>$template) $insert->execute([':key'=>$key,':subject'=>$template[0],':body'=>$template[1],':now'=>$now]);
        $memberConfig = $db->prepare(<<<'SQL'
            INSERT IGNORE INTO bni_member_directory_configs
                (org_id,endpoint,parameters,languages,website_type,website_id,mapped_widget_settings,referer,updated_at)
            SELECT 44628,:endpoint,:parameters,:languages,'3','27966',:settings,:referer,:updated_at
            WHERE EXISTS (SELECT 1 FROM organizations WHERE org_id=44628)
            SQL);
        $memberConfig->execute([
            ':endpoint'=>'https://bni-rheinruhr.de/bnicms/v3/frontend/memberlist/display',
            ':parameters'=>'chapterName=44628&regionIds=11805,5843,9614,5925,5921,5939,11553&chapterWebsite=1',
            ':languages'=>'{"availableLanguages":[{"type":"published","url":"http://bni-rheinruhr.de/koenigsforst/de/memberlist","descriptionKey":"Deutsch","id":18,"localeCode":"de"}],"activeLanguage":{"id":18,"localeCode":"de","descriptionKey":"Deutsch","cookieBotCode":"de"}}',
            ':settings'=>'[{"key":113,"name":"Member Names","value":"Namen der Mitglieder"},{"key":117,"name":"Profession/Specialty","value":"Wirtschaftszweig/Fachgebiet"},{"key":118,"name":"Company","value":"Unternehmen"},{"key":119,"name":"Showing","value":"Zeige"},{"key":120,"name":"to","value":"bis"},{"key":121,"name":"of","value":"von"},{"key":122,"name":"entries","value":"Einträgen"},{"key":304,"name":"Zero Records","value":"Keine Einträge gefunden"},{"key":343,"name":"Phone","value":"Telefon"},{"key":344,"name":"Send Mail","value":"Nachricht senden"}]',
            ':referer'=>'https://bni-rheinruhr.de/koenigsforst/de/memberlist',
            ':updated_at'=>$now,
        ]);
    }
}
