<?php

declare(strict_types=1);

final class AutomationRepository
{
    public function __construct(private readonly PDO $database)
    {
    }

    /** @return array<string, int|bool|string> */
    public function settings(): array
    {
        $row = $this->database->query('SELECT * FROM automation_settings WHERE id = 1')->fetch();
        if (!is_array($row)) {
            throw new RuntimeException('Die Automatisierungseinstellungen fehlen.');
        }
        return [
            'usageRefreshEnabled' => (bool) $row['usage_refresh_enabled'],
            'usageRefreshDays' => (int) $row['usage_refresh_days'],
            'automaticRefreshEnabled' => (bool) $row['automatic_refresh_enabled'],
            'automaticRefreshDays' => (int) $row['automatic_refresh_days'],
            'automaticRefreshBatchSize' => (int) $row['automatic_refresh_batch_size'],
            'automaticRefreshIntervalMinutes' => (int) $row['automatic_refresh_interval_minutes'],
            'automaticRefreshDailyLimit' => (int) $row['automatic_refresh_daily_limit'],
            'mapRefreshEnabled' => (bool) $row['map_refresh_enabled'],
            'mapRefreshDays' => (int) $row['map_refresh_days'],
            'updatedAt' => (string) $row['updated_at'],
        ];
    }

    public function updateSettings(bool $usageEnabled, int $usageDays, bool $automaticEnabled, int $automaticDays, int $dailyLimit = 50, bool $mapEnabled = false, int $mapDays = 1): void
    {
        if ($usageDays < 1 || $usageDays > 365 || $automaticDays < 1 || $automaticDays > 365) {
            throw new InvalidArgumentException('Die Anzahl der Tage muss zwischen 1 und 365 liegen.');
        }
        if ($dailyLimit < 1 || $dailyLimit > 1000) {
            throw new InvalidArgumentException('Das Tageslimit muss zwischen 1 und 1000 liegen.');
        }
        if ($mapDays < 1 || $mapDays > 30) throw new InvalidArgumentException('Das Grunddatenintervall muss zwischen 1 und 30 Tagen liegen.');
        $statement = $this->database->prepare(<<<'SQL'
            UPDATE automation_settings
            SET usage_refresh_enabled = :usage_enabled,
                usage_refresh_days = :usage_days,
                automatic_refresh_enabled = :automatic_enabled,
                automatic_refresh_days = :automatic_days,
                automatic_refresh_daily_limit = :daily_limit,
                map_refresh_enabled = :map_enabled,
                map_refresh_days = :map_days,
                updated_at = :updated_at
            WHERE id = 1
            SQL);
        $statement->execute([
            ':usage_enabled' => $usageEnabled ? 1 : 0,
            ':usage_days' => $usageDays,
            ':automatic_enabled' => $automaticEnabled ? 1 : 0,
            ':automatic_days' => $automaticDays,
            ':daily_limit' => $dailyLimit,
            ':map_enabled' => $mapEnabled ? 1 : 0,
            ':map_days' => $mapDays,
            ':updated_at' => self::now(),
        ]);
    }

    public function mapRefreshDue(int $days): bool
    {
        $statement = $this->database->prepare("SELECT COUNT(*) FROM automation_runtime WHERE id = 1 AND (last_map_refresh_at IS NULL OR datetime(last_map_refresh_at) < datetime('now', :age)) AND (map_retry_after_until IS NULL OR datetime(map_retry_after_until) <= datetime('now'))");
        $statement->execute([':age' => '-' . $days . ' days']); return (int) $statement->fetchColumn() === 1;
    }

    public function acquireMapLock(string $token, int $seconds = 120): bool
    {
        $now = self::now(); $until = gmdate('Y-m-d\TH:i:s\Z', time() + $seconds);
        $statement = $this->database->prepare("UPDATE automation_runtime SET map_lock_token = :token, map_lock_until = :until, updated_at = :now WHERE id = 1 AND (map_lock_until IS NULL OR map_lock_until <= :now)");
        $statement->execute([':token' => $token, ':until' => $until, ':now' => $now]); return $statement->rowCount() === 1;
    }

    public function releaseMapLock(string $token): void
    {
        $statement = $this->database->prepare('UPDATE automation_runtime SET map_lock_token = NULL, map_lock_until = NULL, updated_at = :now WHERE id = 1 AND map_lock_token = :token');
        $statement->execute([':now' => self::now(), ':token' => $token]);
    }

    public function startMapLog(string $trigger): int
    {
        $statement = $this->database->prepare("INSERT INTO map_refresh_log (trigger_type, started_at, status) VALUES (:trigger, :started, 'started')");
        $statement->execute([':trigger' => $trigger, ':started' => self::now()]); return (int) $this->database->lastInsertId();
    }

    public function finishMapLog(int $id, string $status, ?int $httpStatus = null, ?string $category = null): void
    {
        $statement = $this->database->prepare('UPDATE map_refresh_log SET finished_at = :finished, status = :status, http_status = :http, error_category = :category WHERE id = :id');
        $statement->execute([':finished' => self::now(), ':status' => $status, ':http' => $httpStatus, ':category' => $category, ':id' => $id]);
    }

    public function markMapRefreshSuccess(): void
    {
        $statement = $this->database->prepare('UPDATE automation_runtime SET last_map_refresh_at = :now, map_retry_after_until = NULL, updated_at = :now WHERE id = 1');
        $statement->execute([':now' => self::now()]);
    }

    public function setMapRetryAfter(?int $seconds): void
    {
        if ($seconds === null) return;
        $statement = $this->database->prepare('UPDATE automation_runtime SET map_retry_after_until = :until, updated_at = :now WHERE id = 1');
        $statement->execute([':until' => gmdate('Y-m-d\TH:i:s\Z', time() + max(0, $seconds)), ':now' => self::now()]);
    }

    public function acquireLock(int $orgId, string $ownerToken, int $seconds = 90): bool
    {
        $now = self::now();
        $lockUntil = gmdate('Y-m-d\TH:i:s\Z', time() + $seconds);
        $statement = $this->database->prepare(<<<'SQL'
            INSERT INTO chapter_refresh_locks (org_id, owner_token, lock_until, created_at)
            VALUES (:org_id, :owner_token, :lock_until, :created_at)
            ON CONFLICT(org_id) DO UPDATE SET
                owner_token = excluded.owner_token,
                lock_until = excluded.lock_until,
                created_at = excluded.created_at
            WHERE chapter_refresh_locks.lock_until <= :now
            SQL);
        $statement->execute([
            ':org_id' => $orgId,
            ':owner_token' => $ownerToken,
            ':lock_until' => $lockUntil,
            ':created_at' => $now,
            ':now' => $now,
        ]);
        return $statement->rowCount() === 1;
    }

    public function releaseLock(int $orgId, string $ownerToken): void
    {
        $statement = $this->database->prepare('DELETE FROM chapter_refresh_locks WHERE org_id = :org_id AND owner_token = :owner_token');
        $statement->execute([':org_id' => $orgId, ':owner_token' => $ownerToken]);
    }

    public function startLog(int $orgId, string $triggerType): int
    {
        $statement = $this->database->prepare(<<<'SQL'
            INSERT INTO chapter_refresh_log (org_id, trigger_type, started_at, status)
            VALUES (:org_id, :trigger_type, :started_at, 'started')
            SQL);
        $statement->execute([':org_id' => $orgId, ':trigger_type' => $triggerType, ':started_at' => self::now()]);
        return (int) $this->database->lastInsertId();
    }

    public function startLimitedLog(int $orgId, string $triggerType, int $dailyLimit): ?int
    {
        if (!in_array($triggerType, ['usage_search', 'usage_detail', 'automatic'], true)) {
            throw new InvalidArgumentException('Dieser Trigger unterliegt keinem automatischen Tageslimit.');
        }
        [$dayStart, $dayEnd] = self::localDayBounds();
        $this->database->exec('BEGIN IMMEDIATE');
        try {
            $statement = $this->database->prepare(<<<'SQL'
                SELECT COUNT(*) FROM chapter_refresh_log
                WHERE trigger_type IN ('usage_search', 'usage_detail', 'automatic')
                  AND status != 'skipped'
                  AND started_at >= :day_start AND started_at < :day_end
                SQL);
            $statement->execute([':day_start' => $dayStart, ':day_end' => $dayEnd]);
            if ((int) $statement->fetchColumn() >= $dailyLimit) {
                $this->database->exec('COMMIT');
                return null;
            }
            $logId = $this->startLog($orgId, $triggerType);
            $this->database->exec('COMMIT');
            return $logId;
        } catch (Throwable $exception) {
            try { $this->database->exec('ROLLBACK'); } catch (Throwable) {}
            throw $exception;
        }
    }

    public function finishLog(int $logId, string $status, ?int $httpStatus = null, ?string $errorCategory = null): void
    {
        $statement = $this->database->prepare(<<<'SQL'
            UPDATE chapter_refresh_log
            SET finished_at = :finished_at, status = :status,
                http_status = :http_status, error_category = :error_category
            WHERE id = :id
            SQL);
        $statement->execute([
            ':id' => $logId,
            ':finished_at' => self::now(),
            ':status' => $status,
            ':http_status' => $httpStatus,
            ':error_category' => $errorCategory,
        ]);
    }

    public function recordSkipped(int $orgId, string $triggerType, string $category): void
    {
        $now = self::now();
        $statement = $this->database->prepare(<<<'SQL'
            INSERT INTO chapter_refresh_log (org_id, trigger_type, started_at, finished_at, status, error_category)
            VALUES (:org_id, :trigger_type, :started_at, :finished_at, 'skipped', :category)
            SQL);
        $statement->execute([
            ':org_id' => $orgId,
            ':trigger_type' => $triggerType,
            ':started_at' => $now,
            ':finished_at' => $now,
            ':category' => $category,
        ]);
    }

    public function updateWorkerRuntime(int $intervalMinutes): void
    {
        $now = self::now();
        $next = gmdate('Y-m-d\TH:i:s\Z', time() + ($intervalMinutes * 60));
        $statement = $this->database->prepare(<<<'SQL'
            UPDATE automation_runtime
            SET worker_last_seen_at = :now, last_check_at = :now,
                next_check_at = :next_check, updated_at = :now
            WHERE id = 1
            SQL);
        $statement->execute([':now' => $now, ':next_check' => $next]);
    }

    /** @return array<string, mixed> */
    public function statistics(int $usageDays, int $automaticDays, int $dailyLimit = 50): array
    {
        [$dayStart, $dayEnd] = self::localDayBounds();
        $statement = $this->database->prepare(<<<'SQL'
            SELECT
                MAX(CASE WHEN trigger_type = 'automatic' AND status = 'success' THEN finished_at END) AS last_automatic,
                MAX(CASE WHEN trigger_type IN ('usage_search', 'usage_detail') AND status = 'success' THEN finished_at END) AS last_usage,
                SUM(CASE WHEN trigger_type = 'automatic' AND status = 'success' AND finished_at >= :day_start AND finished_at < :day_end THEN 1 ELSE 0 END) AS automatic_today,
                SUM(CASE WHEN trigger_type IN ('usage_search', 'usage_detail') AND status = 'success' AND finished_at >= :day_start AND finished_at < :day_end THEN 1 ELSE 0 END) AS usage_today,
                SUM(CASE WHEN trigger_type IN ('usage_search', 'usage_detail', 'automatic') AND status != 'skipped' AND started_at >= :day_start AND started_at < :day_end THEN 1 ELSE 0 END) AS automatic_requests_today,
                SUM(CASE WHEN finished_at >= datetime('now', '-7 days') THEN 1 ELSE 0 END) AS updates_seven_days,
                SUM(CASE WHEN status = 'success' AND finished_at >= datetime('now', '-7 days') THEN 1 ELSE 0 END) AS success_seven_days,
                SUM(CASE WHEN status = 'error' AND finished_at >= datetime('now', '-7 days') THEN 1 ELSE 0 END) AS errors_seven_days,
                SUM(CASE WHEN status IN ('rate_limited', 'forbidden') AND finished_at >= datetime('now', '-7 days') THEN 1 ELSE 0 END) AS protection_stops_seven_days,
                MAX(CASE WHEN status IN ('rate_limited', 'forbidden') THEN finished_at END) AS last_protection_stop
            FROM chapter_refresh_log
            SQL);
        $statement->execute([':day_start' => $dayStart, ':day_end' => $dayEnd]);
        $log = $statement->fetch();
        $runtime = $this->database->query('SELECT * FROM automation_runtime WHERE id = 1')->fetch();
        $mapLog = $this->database->query(<<<'SQL'
            SELECT
              MAX(CASE WHEN status = 'success' THEN finished_at END) AS last_success,
              MAX(CASE WHEN trigger_type = 'map_automatic' THEN started_at END) AS last_automatic_attempt,
              SUM(CASE WHEN status = 'success' AND date(finished_at, 'localtime') = date('now', 'localtime') THEN 1 ELSE 0 END) AS success_today,
              SUM(CASE WHEN finished_at >= datetime('now', '-7 days') THEN 1 ELSE 0 END) AS attempts_seven_days,
              SUM(CASE WHEN status = 'error' AND finished_at >= datetime('now', '-7 days') THEN 1 ELSE 0 END) AS errors_seven_days,
              SUM(CASE WHEN status IN ('rate_limited','forbidden') AND finished_at >= datetime('now', '-7 days') THEN 1 ELSE 0 END) AS protection_seven_days
            FROM map_refresh_log
            SQL)->fetch();
        $automaticDue = $this->automaticDueCounts($automaticDays);
        $usedToday = (int) ($log['automatic_requests_today'] ?? 0);
        return [
            'lastAutomaticRefresh' => $log['last_automatic'] ?? null,
            'lastUsageRefresh' => $log['last_usage'] ?? null,
            'automaticToday' => (int) ($log['automatic_today'] ?? 0),
            'usageToday' => (int) ($log['usage_today'] ?? 0),
            'dailyLimit' => $dailyLimit,
            'dailyUsed' => $usedToday,
            'dailyRemaining' => max(0, $dailyLimit - $usedToday),
            'dailyPercent' => min(100, round(($usedToday / $dailyLimit) * 100, 1)),
            'dailyLimitReached' => $usedToday >= $dailyLimit,
            'updatesSevenDays' => (int) ($log['updates_seven_days'] ?? 0),
            'successSevenDays' => (int) ($log['success_seven_days'] ?? 0),
            'errorsSevenDays' => (int) ($log['errors_seven_days'] ?? 0),
            'protectionStopsSevenDays' => (int) ($log['protection_stops_seven_days'] ?? 0),
            'lastProtectionStop' => $log['last_protection_stop'] ?? null,
            'staleUsage' => $this->staleCount($usageDays),
            'staleAutomatic' => $automaticDue['staleLoaded'],
            'automaticNeverLoaded' => $automaticDue['neverLoaded'],
            'automaticRetryableErrors' => $automaticDue['errors'],
            'automaticDueTotal' => $automaticDue['total'],
            'chaptersWithDetails' => $this->loadedCount(),
            'workerLastSeenAt' => $runtime['worker_last_seen_at'] ?? null,
            'nextAutomaticCheckAt' => $runtime['next_check_at'] ?? null,
            'lastMapRefreshAt' => $runtime['last_map_refresh_at'] ?? null,
            'lastAutomaticMapAttempt' => $mapLog['last_automatic_attempt'] ?? null,
            'mapRefreshToday' => (int) ($mapLog['success_today'] ?? 0),
            'mapRefreshSevenDays' => (int) ($mapLog['attempts_seven_days'] ?? 0),
            'mapErrorsSevenDays' => (int) ($mapLog['errors_seven_days'] ?? 0),
            'mapProtectionStopsSevenDays' => (int) ($mapLog['protection_seven_days'] ?? 0),
            'mapRefreshDue' => $this->mapRefreshDue((int) ($this->settings()['mapRefreshDays'] ?? 1)),
            'nextMapRefreshDueAt' => isset($runtime['last_map_refresh_at']) && $runtime['last_map_refresh_at'] !== null
                ? gmdate('Y-m-d\TH:i:s\Z', strtotime((string) $runtime['last_map_refresh_at']) + ((int) ($this->settings()['mapRefreshDays'] ?? 1) * 86400)) : null,
        ];
    }

    private function staleCount(int $days): int
    {
        $statement = $this->database->prepare(<<<'SQL'
            SELECT COUNT(*) FROM organizations
            WHERE org_type = 'CHAPTER' AND detail_status = 'loaded'
              AND details_loaded_at IS NOT NULL
              AND datetime(details_loaded_at) < datetime('now', :age)
            SQL);
        $statement->execute([':age' => '-' . $days . ' days']);
        return (int) $statement->fetchColumn();
    }

    private function loadedCount(): int
    {
        return (int) $this->database->query("SELECT COUNT(*) FROM organizations WHERE org_type = 'CHAPTER' AND detail_status = 'loaded'")->fetchColumn();
    }

    /** @return array{neverLoaded:int,errors:int,staleLoaded:int,total:int} */
    private function automaticDueCounts(int $days): array
    {
        $statement = $this->database->prepare(<<<'SQL'
            SELECT
              SUM(CASE WHEN detail_status = 'not_loaded' OR (details_loaded_at IS NULL AND detail_status != 'error') THEN 1 ELSE 0 END) AS never_loaded,
              SUM(CASE WHEN detail_status = 'error' THEN 1 ELSE 0 END) AS errors,
              SUM(CASE WHEN detail_status = 'loaded' AND details_loaded_at IS NOT NULL AND datetime(details_loaded_at) < datetime('now', :age) THEN 1 ELSE 0 END) AS stale_loaded
            FROM organizations
            WHERE org_type = 'CHAPTER'
            SQL);
        $statement->execute([':age' => '-' . $days . ' days']);
        $row = $statement->fetch();
        $neverLoaded = (int) ($row['never_loaded'] ?? 0);
        $errors = (int) ($row['errors'] ?? 0);
        $staleLoaded = (int) ($row['stale_loaded'] ?? 0);
        return ['neverLoaded' => $neverLoaded, 'errors' => $errors, 'staleLoaded' => $staleLoaded, 'total' => $neverLoaded + $errors + $staleLoaded];
    }

    private static function now(): string
    {
        return gmdate('Y-m-d\TH:i:s\Z');
    }

    /** @return array{0:string,1:string} */
    private static function localDayBounds(): array
    {
        $timezone = new DateTimeZone('Europe/Berlin');
        $start = new DateTimeImmutable('today', $timezone);
        $end = $start->modify('+1 day');
        $utc = new DateTimeZone('UTC');
        return [$start->setTimezone($utc)->format('Y-m-d\TH:i:s\Z'), $end->setTimezone($utc)->format('Y-m-d\TH:i:s\Z')];
    }
}
