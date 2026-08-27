<?php

declare(strict_types=1);

final class UserFacingErrorLogger
{
    public const RETENTION_DAYS = 60;

    public function __construct(private readonly PDO $db) {}

    public function log(string $userMessage, string $technicalMessage, ?string $errorCode = null, ?string $context = null, ?int $userId = null, ?string $route = null): int
    {
        $statement = $this->db->prepare('INSERT INTO user_error_log(created_at,created_at_ms,user_message,technical_message,error_code,context,user_id,route) VALUES(:created_at,:created_at_ms,:user_message,:technical_message,:error_code,:context,:user_id,:route)');
        $statement->execute([
            ':created_at' => gmdate('Y-m-d\TH:i:s\Z'),
            ':created_at_ms' => (int) floor(microtime(true) * 1000),
            ':user_message' => $this->clean($userMessage, 500),
            ':technical_message' => $this->clean($technicalMessage, 1000),
            ':error_code' => $this->nullable($errorCode, 80),
            ':context' => $this->nullable($context, 80),
            ':user_id' => $userId,
            ':route' => $this->nullable($route, 190),
        ]);
        return (int) $this->db->lastInsertId();
    }

    /** @return list<array<string,mixed>> */
    public function latest(int $limit = 100): array
    {
        $limit = max(1, min(200, $limit));
        $statement = $this->db->prepare('SELECT id,created_at,user_message,technical_message,error_code,context,user_id,route FROM user_error_log ORDER BY created_at_ms DESC,id DESC LIMIT :limit');
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->execute();
        return $statement->fetchAll();
    }

    public function cleanup(int $days = self::RETENTION_DAYS, ?int $nowMs = null): int
    {
        $cutoff = ($nowMs ?? (int) floor(microtime(true) * 1000)) - max(1, $days) * 86400000;
        $statement = $this->db->prepare('DELETE FROM user_error_log WHERE created_at_ms < :cutoff');
        $statement->execute([':cutoff' => $cutoff]);
        return $statement->rowCount();
    }

    private function clean(string $value, int $maxLength): string
    {
        $value = preg_replace('/([?&](?:token|code|key|secret|password)=)[^&\s]+/i', '$1[redacted]', $value) ?? $value;
        $value = preg_replace('/\bBearer\s+\S+/i', 'Bearer [redacted]', $value) ?? $value;
        $value = preg_replace('/\b[A-Za-z0-9_-]{40,}\b/', '[redacted]', $value) ?? $value;
        return mb_substr(trim($value), 0, $maxLength);
    }

    private function nullable(?string $value, int $maxLength): ?string
    {
        if ($value === null || trim($value) === '') return null;
        return $this->clean($value, $maxLength);
    }
}
