<?php

declare(strict_types=1);

final class UserRepository
{
    public function __construct(private readonly PDO $database) {}

    /** @return array<string, mixed> */
    public function create(string $firstName, string $lastName, string $email, string $passwordHash, ?int $homeChapterOrgId): array
    {
        $now = self::now();
        $statement = $this->database->prepare(<<<'SQL'
            INSERT INTO users (first_name, last_name, email, password_hash, home_chapter_org_id, role, status, created_at, updated_at)
            VALUES (:first_name, :last_name, :email, :password_hash, :home_chapter, 'user', 'pending', :created_at, :updated_at)
            SQL);
        $statement->execute([':first_name' => $firstName, ':last_name' => $lastName, ':email' => strtolower($email), ':password_hash' => $passwordHash, ':home_chapter' => $homeChapterOrgId, ':created_at' => $now, ':updated_at' => $now]);
        return $this->findById((int) $this->database->lastInsertId()) ?? throw new RuntimeException('Das Benutzerkonto konnte nicht gelesen werden.');
    }

    /** @return array<string, mixed>|null */
    public function findByLogin(string $login): ?array
    {
        $statement = $this->database->prepare('SELECT * FROM users WHERE email = :login COLLATE NOCASE');
        $statement->execute([':login' => trim($login)]); $row = $statement->fetch();
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
            FROM organizations WHERE org_type = 'CHAPTER' ORDER BY COALESCE(chapter_name, ''), org_id
            SQL)->fetchAll();
    }

    public function issueToken(string $table, int $userId, int $ttlSeconds): string
    {
        self::assertTokenTable($table); $token = self::randomToken(); $hash = hash('sha256', $token); $now = self::now();
        $this->database->beginTransaction();
        try {
            $invalidate = $this->database->prepare("UPDATE {$table} SET used_at = :used_at WHERE user_id = :user_id AND used_at IS NULL");
            $invalidate->execute([':used_at' => $now, ':user_id' => $userId]);
            $insert = $this->database->prepare("INSERT INTO {$table} (user_id, token_hash, expires_at, created_at) VALUES (:user_id, :token_hash, :expires_at, :created_at)");
            $insert->execute([':user_id' => $userId, ':token_hash' => $hash, ':expires_at' => gmdate('Y-m-d\TH:i:s\Z', time() + $ttlSeconds), ':created_at' => $now]);
            $this->database->commit(); return $token;
        } catch (Throwable $exception) { $this->database->rollBack(); throw $exception; }
    }

    public function verifyEmail(string $token): bool
    {
        $row = $this->validToken('email_verification_tokens', $token); if ($row === null) return false; $now = self::now();
        $this->database->beginTransaction();
        try {
            $tokenUpdate = $this->database->prepare('UPDATE email_verification_tokens SET used_at = :used_at WHERE id = :id AND used_at IS NULL');
            $tokenUpdate->execute([':used_at' => $now, ':id' => $row['id']]);
            if ($tokenUpdate->rowCount() !== 1) { $this->database->rollBack(); return false; }
            $userUpdate = $this->database->prepare("UPDATE users SET email_verified_at = :verified, status = 'active', updated_at = :updated WHERE id = :id AND status != 'disabled'");
            $userUpdate->execute([':verified' => $now, ':updated' => $now, ':id' => $row['user_id']]);
            $this->database->commit(); return $userUpdate->rowCount() === 1;
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
        $statement = $this->database->prepare('UPDATE users SET last_login_at = :now, updated_at = :now WHERE id = :id');
        $statement->execute([':now' => self::now(), ':id' => $userId]);
    }

    public function rateLimited(string $type, string $identifier, string $ip, int $maxAttempts, int $windowSeconds): bool
    {
        $statement = $this->database->prepare('SELECT COUNT(*) FROM auth_attempts WHERE attempt_type = :type AND (identifier_hash = :identifier OR ip_hash = :ip) AND attempted_at >= :since AND successful = 0');
        $statement->execute([':type' => $type, ':identifier' => hash('sha256', strtolower(trim($identifier))), ':ip' => hash('sha256', $ip), ':since' => gmdate('Y-m-d\TH:i:s\Z', time() - $windowSeconds)]);
        return (int) $statement->fetchColumn() >= $maxAttempts;
    }

    public function recordAttempt(string $type, string $identifier, string $ip, bool $successful): void
    {
        $statement = $this->database->prepare('INSERT INTO auth_attempts (attempt_type, identifier_hash, ip_hash, successful, attempted_at) VALUES (:type, :identifier, :ip, :successful, :at)');
        $statement->execute([':type' => $type, ':identifier' => hash('sha256', strtolower(trim($identifier))), ':ip' => hash('sha256', $ip), ':successful' => $successful ? 1 : 0, ':at' => self::now()]);
    }

    /** @return array<string, mixed>|null */
    private function validToken(string $table, string $token): ?array
    {
        self::assertTokenTable($table); if (!preg_match('/^[A-Za-z0-9_-]{40,100}$/', $token)) return null;
        $statement = $this->database->prepare("SELECT * FROM {$table} WHERE token_hash = :hash AND used_at IS NULL AND datetime(expires_at) > datetime('now')");
        $statement->execute([':hash' => hash('sha256', $token)]); $row = $statement->fetch(); return is_array($row) ? $row : null;
    }

    private static function assertTokenTable(string $table): void { if (!in_array($table, ['email_verification_tokens', 'password_reset_tokens'], true)) throw new InvalidArgumentException('Ungültige Tokenart.'); }
    private static function randomToken(): string { return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '='); }
    private static function now(): string { return gmdate('Y-m-d\TH:i:s\Z'); }
}
