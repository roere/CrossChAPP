<?php

declare(strict_types=1);

final class UserRepository
{
    public function __construct(private readonly PDO $database) {}

    public function transaction(Closure $operation): mixed
    {
        $ownsTransaction = !$this->database->inTransaction();
        if ($ownsTransaction) $this->database->beginTransaction();
        try {
            $result = $operation();
            if ($ownsTransaction) $this->database->commit();
            return $result;
        } catch (Throwable $exception) {
            if ($ownsTransaction && $this->database->inTransaction()) $this->database->rollBack();
            throw $exception;
        }
    }

    /** @return array<string, mixed> */
    public function create(string $firstName, string $lastName, string $email, string $passwordHash, ?int $homeChapterOrgId, string $bniStatus = 'unverified', ?string $externalRef = null): array
    {
        $now = self::now();
        $statement = $this->database->prepare(<<<'SQL'
            INSERT INTO users (first_name, last_name, email, password_hash, home_chapter_org_id, role, status, bni_verification_status, bni_external_member_ref, created_at, updated_at)
            VALUES (:first_name, :last_name, :email, :password_hash, :home_chapter, 'user', 'pending', :bni_status, :external_ref, :created_at, :updated_at)
            SQL);
        $statement->execute([':first_name' => $firstName, ':last_name' => $lastName, ':email' => strtolower($email), ':password_hash' => $passwordHash, ':home_chapter' => $homeChapterOrgId, ':bni_status' => $bniStatus, ':external_ref' => $externalRef, ':created_at' => $now, ':updated_at' => $now]);
        return $this->findById((int) $this->database->lastInsertId()) ?? throw new RuntimeException('Das Benutzerkonto konnte nicht gelesen werden.');
    }

    /** @return array<string, mixed>|null */
    public function findByLogin(string $login): ?array
    {
        $statement = $this->database->prepare('SELECT * FROM users WHERE LOWER(email) = LOWER(:email_login) OR LOWER(username) = LOWER(:username_login)');
        $login = trim($login);
        $statement->execute([':email_login' => $login, ':username_login' => $login]); $row = $statement->fetch();
        return is_array($row) ? $row : null;
    }

    /** @return array<string, mixed>|null */
    public function findById(int $id): ?array
    {
        $statement = $this->database->prepare('SELECT * FROM users WHERE id = :id'); $statement->execute([':id' => $id]); $row = $statement->fetch();
        return is_array($row) ? $row : null;
    }

    public function isValidHomeChapter(int $orgId): bool
    {
        $statement = $this->database->prepare("SELECT COUNT(*) FROM organizations WHERE org_id = :org_id AND org_type = 'CHAPTER'");
        $statement->execute([':org_id' => $orgId]); return (int) $statement->fetchColumn() === 1;
    }

    /** @return list<array<string, mixed>> */
    public function homeChapters(): array
    {
        return $this->database->query(<<<'SQL'
            SELECT org_id AS orgId, chapter_name AS chapterName, city, postal_code AS postalCode,
                   region, country_code AS countryCode
            FROM organizations
            WHERE org_type = 'CHAPTER' AND NULLIF(TRIM(chapter_name), '') IS NOT NULL
            ORDER BY LOWER(chapter_name), org_id
            SQL)->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public function homeChapter(int $orgId): ?array
    {
        $statement = $this->database->prepare("SELECT org_id,chapter_name,chapter_url,country_code,region,city FROM organizations WHERE org_id=:id AND org_type='CHAPTER'");
        $statement->execute([':id' => $orgId]); $row = $statement->fetch(); return is_array($row) ? $row : null;
    }

    /** @return array<string,mixed>|null */
    public function accountDetails(int $userId): ?array
    {
        $statement = $this->database->prepare(<<<'SQL'
            SELECT users.id, users.first_name, users.last_name, users.email, users.role,
                   users.home_chapter_org_id,
                   users.bni_verification_status,
                   organizations.chapter_name AS home_chapter_name,
                   organizations.short_link_slug AS home_chapter_short_link_slug
            FROM users
            LEFT JOIN organizations ON organizations.org_id = users.home_chapter_org_id
            WHERE users.id = :id
            SQL);
        $statement->execute([':id' => $userId]); $row = $statement->fetch();
        return is_array($row) ? $row : null;
    }

    public function updateHomeChapterVerification(int $userId, ?int $orgId, string $verificationStatus, ?string $externalRef): void
    {
        $verifiedAt = $verificationStatus === 'directory_match' ? self::now() : null;
        $statement = $this->database->prepare(<<<'SQL'
            UPDATE users
            SET home_chapter_org_id = :org_id,
                bni_verification_status = :verification_status,
                bni_external_member_ref = :external_ref,
                bni_verified_at = :verified_at,
                bni_verified_by_user_id = NULL,
                updated_at = :updated_at
            WHERE id = :id AND role = 'user'
            SQL);
        $statement->execute([
            ':org_id' => $orgId,
            ':verification_status' => $verificationStatus,
            ':external_ref' => $externalRef,
            ':verified_at' => $verifiedAt,
            ':updated_at' => self::now(),
            ':id' => $userId,
        ]);
        if ($statement->rowCount() !== 1) throw new RuntimeException('Das Heimatchapter konnte nicht gespeichert werden.');
    }

    public function manuallyVerify(int $userId, int $adminUserId): void
    {
        $user = $this->findById($userId);
        if ($user === null) throw new AdminUserVerificationException('user_not_found', 'Der ausgewählte Anwender wurde nicht gefunden.');
        if (($user['role'] ?? null) !== 'user') throw new AdminUserVerificationException('invalid_role', 'Dieses Konto kann nicht manuell verifiziert werden.');
        if ($user['home_chapter_org_id'] === null) throw new AdminUserVerificationException('home_chapter_missing', 'Dieser Anwender kann erst verifiziert werden, wenn ein Heimatchapter hinterlegt ist.');
        if (($user['bni_verification_status'] ?? '') === 'manual_verified') throw new AdminUserVerificationException('already_verified', 'Dieser Anwender ist bereits verifiziert.');
        $admin = $this->findById($adminUserId);
        if ($admin === null || !in_array(($admin['role'] ?? null), ['admin','user_manager'], true)) throw new AdminUserVerificationException('invalid_verifier', 'Die Berechtigung zur Anwenderbetreuung konnte nicht bestätigt werden.');
        $now = self::now();
        $statement = $this->database->prepare(<<<'SQL'
            UPDATE users
            SET bni_verification_status = 'manual_verified',
                bni_verified_at = :verified_at,
                bni_verified_by_user_id = :admin_id,
                updated_at = :updated_at
            WHERE id = :id AND role = 'user' AND home_chapter_org_id IS NOT NULL
              AND bni_verification_status IN ('unverified', 'directory_match')
            SQL);
        $statement->execute([':verified_at' => $now, ':admin_id' => $adminUserId, ':updated_at' => $now, ':id' => $userId]);
        if ($statement->rowCount() !== 1) throw new AdminUserVerificationException('verification_conflict', 'Der Verifikationsstatus hat sich zwischenzeitlich geändert.');
    }

    public function updateRole(int $userId, string $role): void
    {
        if (!in_array($role, ['user', 'user_manager'], true)) {
            throw new AdminUserRoleException('invalid_role', 'Die ausgewählte Rolle ist nicht zulässig.');
        }
        $user = $this->findById($userId);
        if ($user === null) throw new AdminUserRoleException('user_not_found', 'Der ausgewählte Anwender wurde nicht gefunden.');
        if (!in_array(($user['role'] ?? null), ['user', 'user_manager'], true)) {
            throw new AdminUserRoleException('protected_role', 'Administratorkonten können nicht geändert werden.');
        }
        if ($user['role'] === $role) return;
        $statement = $this->database->prepare("UPDATE users SET role=:role,updated_at=:updated_at WHERE id=:id AND role IN ('user','user_manager')");
        $statement->execute([':role' => $role, ':updated_at' => self::now(), ':id' => $userId]);
        if ($statement->rowCount() !== 1) throw new AdminUserRoleException('role_conflict', 'Die Rolle hat sich zwischenzeitlich geändert.');
    }

    public function deleteAccount(int $userId): bool
    {
        $account = $this->accountDetails($userId);
        if ($account === null) return false;
        if ($account['role'] === 'admin') throw new DomainException('Administratorkonten können nicht gelöscht werden.');
        $this->database->beginTransaction();
        try {
            $attempts = $this->database->prepare('DELETE FROM auth_attempts WHERE identifier_hash = :identifier');
            $attempts->execute([':identifier' => self::identifierHash((string) $account['email'])]);
            $delete = $this->database->prepare("DELETE FROM users WHERE id = :id AND role != 'admin'");
            $delete->execute([':id' => $userId]);
            if ($delete->rowCount() !== 1) throw new RuntimeException('Das Benutzerkonto konnte nicht gelöscht werden.');
            $this->database->commit(); return true;
        } catch (Throwable $exception) {
            if ($this->database->inTransaction()) $this->database->rollBack();
            throw $exception;
        }
    }

    /** @return list<array<string,mixed>> */
    public function adminUsersOverview(string $today, string $contactsSince): array
    {
        $statement = $this->database->prepare(<<<'SQL'
            SELECT users.id AS userId, users.first_name AS firstName, users.last_name AS lastName, users.email, users.role,
                   users.home_chapter_org_id AS homeChapterOrgId, organizations.chapter_name AS homeChapterName, users.status,
                   users.bni_verification_status AS verificationStatus, users.created_at AS createdAt,
                   users.email_verified_at AS emailVerifiedAt,
                   COALESCE(offers.currentOffers, 0) AS currentOffers,
                   COALESCE(requests.currentRequests, 0) AS currentRequests,
                   COALESCE(contacts.contacts30Days, 0) AS contacts30Days
            FROM users
            LEFT JOIN organizations ON organizations.org_id = users.home_chapter_org_id
            LEFT JOIN (
                SELECT representation_offers.user_id, COUNT(*) AS currentOffers
                FROM representation_offers
                WHERE representation_offers.all_dates = 1 OR EXISTS (
                    SELECT 1 FROM representation_offer_dates
                    WHERE representation_offer_dates.offer_id = representation_offers.id
                      AND representation_offer_dates.offer_date >= :offer_today
                )
                GROUP BY representation_offers.user_id
            ) offers ON offers.user_id = users.id
            LEFT JOIN (
                SELECT user_id, COUNT(*) AS currentRequests
                FROM representation_requests
                WHERE request_date >= :request_today
                GROUP BY user_id
            ) requests ON requests.user_id = users.id
            LEFT JOIN (
                SELECT contact_user_id, COUNT(*) AS contacts30Days
                FROM (
                    SELECT requester_user_id AS contact_user_id, sent_at
                    FROM representation_contact_log WHERE status = 'success' AND sent_at >= :offer_contact_since
                    UNION ALL
                    SELECT contact_user_id, sent_at
                    FROM representation_request_contact_log WHERE status = 'success' AND sent_at >= :request_contact_since
                ) contact_activity
                GROUP BY contact_user_id
            ) contacts ON contacts.contact_user_id = users.id
            WHERE users.role IN ('user','user_manager')
            ORDER BY LOWER(users.last_name), LOWER(users.first_name), LOWER(users.email)
            SQL);
        $statement->execute([':offer_today' => $today, ':request_today' => $today, ':offer_contact_since' => $contactsSince, ':request_contact_since' => $contactsSince]);
        $rows = $statement->fetchAll();
        return array_map(static function (array $row): array {
            $row['currentOffers'] = (int) $row['currentOffers'];
            $row['currentRequests'] = (int) $row['currentRequests'];
            $row['contacts30Days'] = (int) $row['contacts30Days'];
            $row['homeChapterOrgId'] = $row['homeChapterOrgId'] !== null ? (int) $row['homeChapterOrgId'] : null;
            $row['emailVerified'] = $row['emailVerifiedAt'] !== null;
            return $row;
        }, $rows);
    }

    public function memberCheckRateLimited(string $ip): bool
    {
        $hash = self::ipHash($ip); $since = gmdate('Y-m-d\TH:i:s\Z', time() - 900);
        $query = $this->database->prepare('SELECT COUNT(*) FROM bni_member_check_attempts WHERE ip_hash=:ip AND attempted_at>=:since');
        $query->execute([':ip' => $hash, ':since' => $since]); return (int) $query->fetchColumn() >= 5;
    }

    public function recordMemberCheck(string $ip): void
    {
        $statement = $this->database->prepare('INSERT INTO bni_member_check_attempts (ip_hash,attempted_at) VALUES (:ip,:at)');
        $statement->execute([':ip' => self::ipHash($ip), ':at' => self::now()]);
    }

    public function issueToken(string $table, int $userId, int $ttlSeconds): string
    {
        self::assertTokenTable($table); $token = self::randomToken(); $hash = hash('sha256', $token); $now = self::now();
        $ownsTransaction = !$this->database->inTransaction();
        if ($ownsTransaction) $this->database->beginTransaction();
        try {
            $invalidate = $this->database->prepare("UPDATE {$table} SET used_at = :used_at WHERE user_id = :user_id AND used_at IS NULL");
            $invalidate->execute([':used_at' => $now, ':user_id' => $userId]);
            $insert = $this->database->prepare("INSERT INTO {$table} (user_id, token_hash, expires_at, created_at) VALUES (:user_id, :token_hash, :expires_at, :created_at)");
            $insert->execute([':user_id' => $userId, ':token_hash' => $hash, ':expires_at' => gmdate('Y-m-d\TH:i:s\Z', time() + $ttlSeconds), ':created_at' => $now]);
            if ($ownsTransaction) $this->database->commit();
            return $token;
        } catch (Throwable $exception) {
            if ($ownsTransaction && $this->database->inTransaction()) $this->database->rollBack();
            throw $exception;
        }
    }

    public function verifyEmail(string $token): bool { return $this->verifyEmailResult($token) === 'verified'; }

    public function verifyEmailResult(string $token): string
    {
        if (!preg_match('/^[A-Za-z0-9_-]{40,100}$/', $token)) return 'invalid';
        $statement = $this->database->prepare(<<<'SQL'
            SELECT token.*, users.email_verified_at, users.status AS user_status
            FROM email_verification_tokens token
            INNER JOIN users ON users.id = token.user_id
            WHERE token.token_hash = :hash
            SQL);
        $statement->execute([':hash' => hash('sha256', $token)]); $row = $statement->fetch();
        if (!is_array($row)) return 'invalid';
        if ($row['used_at'] !== null) return 'used';
        if (strtotime((string) $row['expires_at']) <= time()) return 'expired';
        if ($row['email_verified_at'] !== null || $row['user_status'] === 'active') return 'already_verified';
        $now = self::now();
        $this->database->beginTransaction();
        try {
            $tokenUpdate = $this->database->prepare('UPDATE email_verification_tokens SET used_at = :used_at WHERE id = :id AND used_at IS NULL');
            $tokenUpdate->execute([':used_at' => $now, ':id' => $row['id']]);
            if ($tokenUpdate->rowCount() !== 1) { $this->database->rollBack(); return 'used'; }
            $userUpdate = $this->database->prepare("UPDATE users SET email_verified_at = :verified, status = 'active', updated_at = :updated WHERE id = :id AND status != 'disabled'");
            $userUpdate->execute([':verified' => $now, ':updated' => $now, ':id' => $row['user_id']]);
            $this->database->commit(); return $userUpdate->rowCount() === 1 ? 'verified' : 'already_verified';
        } catch (Throwable $exception) { $this->database->rollBack(); throw $exception; }
    }

    public function resetPassword(string $token, string $passwordHash): bool
    {
        $row = $this->validToken('password_reset_tokens', $token); if ($row === null) return false; $now = self::now();
        $this->database->beginTransaction();
        try {
            $use = $this->database->prepare('UPDATE password_reset_tokens SET used_at = :used WHERE id = :id AND used_at IS NULL');
            $use->execute([':used' => $now, ':id' => $row['id']]); if ($use->rowCount() !== 1) { $this->database->rollBack(); return false; }
            $update = $this->database->prepare('UPDATE users SET password_hash = :hash, updated_at = :updated WHERE id = :id');
            $update->execute([':hash' => $passwordHash, ':updated' => $now, ':id' => $row['user_id']]);
            $close = $this->database->prepare('UPDATE password_reset_tokens SET used_at = :used WHERE user_id = :user_id AND used_at IS NULL');
            $close->execute([':used' => $now, ':user_id' => $row['user_id']]); $this->database->commit(); return true;
        } catch (Throwable $exception) { $this->database->rollBack(); throw $exception; }
    }

    public function markLogin(int $userId): void
    {
        $statement = $this->database->prepare('UPDATE users SET last_login_at = :login_at, updated_at = :updated_at WHERE id = :id');
        $now = self::now();
        $statement->execute([':login_at' => $now, ':updated_at' => $now, ':id' => $userId]);
    }

    public function updatePassword(int $userId, string $passwordHash): bool
    {
        $statement = $this->database->prepare('UPDATE users SET password_hash = :password_hash, updated_at = :updated_at WHERE id = :id AND status = \'active\'');
        $statement->execute([':password_hash' => $passwordHash, ':updated_at' => self::now(), ':id' => $userId]);
        return $statement->rowCount() === 1;
    }

    public function rateLimited(string $type, string $identifier, string $ip, int $maxAttempts, int $windowSeconds): bool
    {
        $statement = $this->database->prepare('SELECT COUNT(*) FROM auth_attempts WHERE attempt_type = :type AND identifier_hash = :identifier AND ip_hash = :ip AND attempted_at >= :since AND successful = 0');
        $statement->execute([':type' => $type, ':identifier' => self::identifierHash($identifier), ':ip' => self::ipHash($ip), ':since' => gmdate('Y-m-d\TH:i:s\Z', time() - $windowSeconds)]);
        return (int) $statement->fetchColumn() >= $maxAttempts;
    }

    public function recordAttempt(string $type, string $identifier, string $ip, bool $successful): void
    {
        if ($successful) {
            $statement = $this->database->prepare('DELETE FROM auth_attempts WHERE attempt_type = :type AND identifier_hash = :identifier AND ip_hash = :ip AND successful = 0');
            $statement->execute([':type' => $type, ':identifier' => self::identifierHash($identifier), ':ip' => self::ipHash($ip)]);
            return;
        }
        $statement = $this->database->prepare('INSERT INTO auth_attempts (attempt_type, identifier_hash, ip_hash, successful, attempted_at) VALUES (:type, :identifier, :ip, :successful, :at)');
        $statement->execute([':type' => $type, ':identifier' => self::identifierHash($identifier), ':ip' => self::ipHash($ip), ':successful' => 0, ':at' => self::now()]);
    }

    /** @return array<string, mixed>|null */
    private function validToken(string $table, string $token): ?array
    {
        self::assertTokenTable($table); if (!preg_match('/^[A-Za-z0-9_-]{40,100}$/', $token)) return null;
        $statement = $this->database->prepare("SELECT * FROM {$table} WHERE token_hash = :hash AND used_at IS NULL AND expires_at > :now");
        $statement->execute([':hash' => hash('sha256', $token), ':now' => self::now()]); $row = $statement->fetch(); return is_array($row) ? $row : null;
    }

    private static function assertTokenTable(string $table): void { if (!in_array($table, ['email_verification_tokens', 'password_reset_tokens'], true)) throw new InvalidArgumentException('Ungültige Tokenart.'); }
    private static function identifierHash(string $identifier): string { return hash('sha256', strtolower(trim($identifier))); }
    private static function ipHash(string $ip): string { return hash('sha256', trim($ip)); }
    private static function randomToken(): string { return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '='); }
    private static function now(): string { return gmdate('Y-m-d\TH:i:s\Z'); }
}

final class AdminUserVerificationException extends DomainException
{
    public function __construct(public readonly string $reason, string $message) { parent::__construct($message); }
}

final class AdminUserRoleException extends DomainException
{
    public function __construct(public readonly string $reason, string $message) { parent::__construct($message); }
}
