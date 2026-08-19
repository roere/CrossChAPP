<?php

declare(strict_types=1);

final class OrganizationRepository
{
    public function __construct(private readonly PDO $database)
    {
    }

    /** @param list<array<string, mixed>> $organizations */
    public function upsertMapOrganizations(array $organizations): void
    {
        $now = self::now();
        $statement = $this->database->prepare(<<<'SQL'
            INSERT INTO organizations (
                org_id, cms_security_hash, country_code, org_type, longitude, latitude,
                detail_status, map_loaded_at, created_at, updated_at
            ) VALUES (
                :org_id, :cms_security_hash, :country_code, :org_type, :longitude, :latitude,
                'not_loaded', :map_loaded_at, :created_at, :updated_at
            )
            ON CONFLICT(org_id) DO UPDATE SET
                cms_security_hash = excluded.cms_security_hash,
                country_code = excluded.country_code,
                org_type = excluded.org_type,
                longitude = excluded.longitude,
                latitude = excluded.latitude,
                map_loaded_at = excluded.map_loaded_at,
                updated_at = excluded.updated_at
            SQL);

        $this->database->beginTransaction();
        try {
            foreach ($organizations as $organization) {
                if (!isset($organization['orgId'])) {
                    continue;
                }
                $statement->execute([
                    ':org_id' => (int) $organization['orgId'],
                    ':cms_security_hash' => $organization['cmsSecurityHash'] ?? null,
                    ':country_code' => $organization['countryCode'] ?? null,
                    ':org_type' => $organization['orgType'] ?? null,
                    ':longitude' => $organization['longitude'] ?? null,
                    ':latitude' => $organization['latitude'] ?? null,
                    ':map_loaded_at' => $now,
                    ':created_at' => $now,
                    ':updated_at' => $now,
                ]);
            }
            $this->database->commit();
        } catch (Throwable $exception) {
            $this->database->rollBack();
            throw $exception;
        }
    }

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        $rows = $this->database->query('SELECT * FROM organizations ORDER BY org_id')->fetchAll();
        return array_map([$this, 'toApi'], $rows);
    }

    /** @return list<array<string, mixed>> */
    public function representationOrganizations(): array
    {
        $statement = $this->database->prepare('SELECT * FROM organizations WHERE org_type = :org_type ORDER BY org_id');
        $statement->execute([':org_type' => 'CHAPTER']);
        return array_map(static fn (array $organization): array => [
            'orgId' => $organization['orgId'],
            'countryCode' => $organization['countryCode'],
            'orgType' => $organization['orgType'],
            'longitude' => $organization['longitude'],
            'latitude' => $organization['latitude'],
            'chapterName' => $organization['chapterName'],
            'region' => $organization['region'],
            'city' => $organization['city'],
            'postalCode' => $organization['postalCode'],
            'meetingDay' => $organization['meetingDay'],
            'meetingTime' => $organization['meetingTime'],
        ], array_map([$this, 'toApi'], $statement->fetchAll()));
    }

    /** @return array{count: int, with_details: int} */
    public function statistics(): array
    {
        $row = $this->database->query(<<<'SQL'
            SELECT COUNT(*) AS count,
                   SUM(CASE WHEN details_loaded_at IS NOT NULL THEN 1 ELSE 0 END) AS with_details
            FROM organizations
            SQL)->fetch();

        return [
            'count' => (int) ($row['count'] ?? 0),
            'with_details' => (int) ($row['with_details'] ?? 0),
        ];
    }

    /** @return list<array<string, mixed>> */
    public function searchableChapters(bool $hasRepresentationRequests = false, ?string $today = null): array
    {
        if ($hasRepresentationRequests && ($today === null || preg_match('/^\d{4}-\d{2}-\d{2}$/', $today) !== 1)) {
            throw new InvalidArgumentException('Für den Gesuchsfilter ist ein gültiges Datum erforderlich.');
        }
        $requestFilter = $hasRepresentationRequests ? <<<'SQL'
              AND EXISTS (
                  SELECT 1
                  FROM representation_requests rr
                  WHERE rr.org_id = organizations.org_id
                    AND rr.request_date >= :today
              )
            SQL : '';
        $statement = $this->database->prepare(<<<SQL
            SELECT * FROM organizations
            WHERE detail_status = :detail_status
              AND chapter_name IS NOT NULL
              AND latitude IS NOT NULL
              AND longitude IS NOT NULL
              AND meeting_day IS NOT NULL
              AND meeting_time IS NOT NULL
            {$requestFilter}
            ORDER BY org_id
            SQL);
        $parameters = [':detail_status' => 'loaded'];
        if ($hasRepresentationRequests) {
            $parameters[':today'] = $today;
        }
        $statement->execute($parameters);

        return array_map([$this, 'toApi'], $statement->fetchAll());
    }

    public function searchableChapterCount(): int
    {
        $statement = $this->database->prepare(<<<'SQL'
            SELECT COUNT(*) FROM organizations
            WHERE detail_status = :detail_status
              AND chapter_name IS NOT NULL
              AND latitude IS NOT NULL
              AND longitude IS NOT NULL
              AND meeting_day IS NOT NULL
              AND meeting_time IS NOT NULL
            SQL);
        $statement->execute([
            ':detail_status' => 'loaded',
        ]);
        return (int) $statement->fetchColumn();
    }

    /** @return array{total: int, loaded: int, missing: int, error: int} */
    public function chapterDetailStatistics(): array
    {
        $statement = $this->database->prepare(<<<'SQL'
            SELECT COUNT(*) AS total,
                   SUM(CASE WHEN detail_status = :loaded_status THEN 1 ELSE 0 END) AS loaded,
                   SUM(CASE WHEN detail_status != :loaded_status THEN 1 ELSE 0 END) AS missing,
                   SUM(CASE WHEN detail_status = :error_status THEN 1 ELSE 0 END) AS error
            FROM organizations
            WHERE org_type = :org_type
            SQL);
        $statement->execute([
            ':loaded_status' => 'loaded',
            ':error_status' => 'error',
            ':org_type' => 'CHAPTER',
        ]);
        $row = $statement->fetch();

        return [
            'total' => (int) ($row['total'] ?? 0),
            'loaded' => (int) ($row['loaded'] ?? 0),
            'missing' => (int) ($row['missing'] ?? 0),
            'error' => (int) ($row['error'] ?? 0),
        ];
    }

    /** @return list<array{orgId: int}> */
    public function findPendingChapters(int $limit): array
    {
        if ($limit < 1 || $limit > 50) {
            throw new InvalidArgumentException('Das Batch-Limit muss zwischen 1 und 50 liegen.');
        }

        $statement = $this->database->prepare(<<<'SQL'
            SELECT org_id
            FROM organizations
            WHERE org_type = :org_type
              AND detail_status != :loaded_status
              AND cms_security_hash IS NOT NULL
              AND cms_security_hash != ''
            ORDER BY org_id ASC
            LIMIT :limit
            SQL);
        $statement->bindValue(':org_type', 'CHAPTER', PDO::PARAM_STR);
        $statement->bindValue(':loaded_status', 'loaded', PDO::PARAM_STR);
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        return array_map(
            static fn (array $row): array => ['orgId' => (int) $row['org_id']],
            $statement->fetchAll(),
        );
    }

    /** @return list<array<string, mixed>> */
    public function findAutomaticDueChapters(int $days, int $limit): array
    {
        if ($days < 1 || $days > 365 || $limit < 1 || $limit > 50) {
            throw new InvalidArgumentException('Ungültige Stale- oder Batch-Grenze.');
        }
        $statement = $this->database->prepare(<<<'SQL'
            SELECT * FROM organizations
            WHERE cms_security_hash IS NOT NULL AND cms_security_hash != ''
              AND (
                  detail_status IN ('not_loaded', 'error')
                  OR details_loaded_at IS NULL
                  OR (detail_status = 'loaded' AND datetime(details_loaded_at) < datetime('now', :age))
              )
            ORDER BY
              CASE
                WHEN detail_status = 'not_loaded' OR (details_loaded_at IS NULL AND detail_status != 'error') THEN 1
                WHEN detail_status = 'error' THEN 2
                ELSE 3
              END ASC,
              CASE WHEN detail_status = 'loaded' THEN details_loaded_at END ASC,
              org_id ASC
            LIMIT :limit
            SQL);
        $statement->bindValue(':age', '-' . $days . ' days', PDO::PARAM_STR);
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->execute();
        return array_map([$this, 'toApi'], $statement->fetchAll());
    }

    public function isAutomaticDueChapter(int $orgId, int $days): bool
    {
        if ($days < 1 || $days > 365) return false;
        $statement = $this->database->prepare(<<<'SQL'
            SELECT COUNT(*) FROM organizations
            WHERE org_id = :org_id
              AND (
                  detail_status IN ('not_loaded', 'error')
                  OR details_loaded_at IS NULL
                  OR (detail_status = 'loaded' AND datetime(details_loaded_at) < datetime('now', :age))
              )
            SQL);
        $statement->execute([':org_id' => $orgId, ':age' => '-' . $days . ' days']);
        return (int) $statement->fetchColumn() === 1;
    }

    public function isStaleLoadedChapter(int $orgId, int $days): bool
    {
        if ($days < 1 || $days > 365) {
            return false;
        }
        $statement = $this->database->prepare(<<<'SQL'
            SELECT COUNT(*) FROM organizations
            WHERE org_id = :org_id
              AND detail_status = 'loaded'
              AND details_loaded_at IS NOT NULL
              AND datetime(details_loaded_at) < datetime('now', :age)
            SQL);
        $statement->execute([':org_id' => $orgId, ':age' => '-' . $days . ' days']);
        return (int) $statement->fetchColumn() === 1;
    }

    public function preserveLoadedOrMarkError(int $orgId): void
    {
        $statement = $this->database->prepare(<<<'SQL'
            UPDATE organizations
            SET detail_status = CASE WHEN details_loaded_at IS NULL THEN 'error' ELSE detail_status END,
                updated_at = :updated_at
            WHERE org_id = :org_id
            SQL);
        $statement->execute([':org_id' => $orgId, ':updated_at' => self::now()]);
    }

    /** @return array<string, mixed>|null */
    public function find(int $orgId): ?array
    {
        $statement = $this->database->prepare('SELECT * FROM organizations WHERE org_id = :org_id');
        $statement->execute([':org_id' => $orgId]);
        $row = $statement->fetch();
        return is_array($row) ? $this->toApi($row) : null;
    }

    /** @param array<string, mixed> $details */
    public function saveDetails(int $orgId, array $details): void
    {
        $now = self::now();
        $statement = $this->database->prepare(<<<'SQL'
            UPDATE organizations SET
                chapter_name = COALESCE(:chapter_name, chapter_name),
                region = COALESCE(:region, region),
                region_id = COALESCE(:region_id, region_id),
                city = COALESCE(:city, city),
                postal_code = COALESCE(:postal_code, postal_code),
                street = COALESCE(:street, street),
                venue = COALESCE(:venue, venue),
                meeting_day = COALESCE(:meeting_day, meeting_day),
                meeting_time = COALESCE(:meeting_time, meeting_time),
                meeting_type = COALESCE(:meeting_type, meeting_type),
                meeting_duration = COALESCE(:meeting_duration, meeting_duration),
                member_count = COALESCE(:member_count, member_count),
                chapter_url = COALESCE(:chapter_url, chapter_url),
                visitor_registration_url = COALESCE(:visitor_registration_url, visitor_registration_url),
                online_meeting_link = COALESCE(:online_meeting_link, online_meeting_link),
                timezone = COALESCE(:timezone, timezone),
                status = COALESCE(:status, status),
                description = COALESCE(:description, description),
                detail_status = 'loaded',
                details_loaded_at = :details_loaded_at,
                updated_at = :updated_at
            WHERE org_id = :org_id
            SQL);
        $statement->execute([
            ':org_id' => $orgId,
            ':chapter_name' => $details['chapterName'] ?? null,
            ':region' => $details['region'] ?? null,
            ':region_id' => $details['regionId'] ?? null,
            ':city' => $details['city'] ?? null,
            ':postal_code' => $details['postalCode'] ?? null,
            ':street' => $details['street'] ?? null,
            ':venue' => $details['venue'] ?? null,
            ':meeting_day' => $details['meetingDay'] ?? null,
            ':meeting_time' => $details['meetingTime'] ?? null,
            ':meeting_type' => $details['meetingType'] ?? null,
            ':meeting_duration' => $details['meetingDuration'] ?? null,
            ':member_count' => $details['memberCount'] ?? null,
            ':chapter_url' => $details['chapterUrl'] ?? null,
            ':visitor_registration_url' => $details['visitorRegistrationUrl'] ?? null,
            ':online_meeting_link' => $details['onlineMeetingUrl'] ?? null,
            ':timezone' => $details['timezone'] ?? null,
            ':status' => $details['status'] ?? null,
            ':description' => $details['description'] ?? null,
            ':details_loaded_at' => $now,
            ':updated_at' => $now,
        ]);
    }

    public function markDetailError(int $orgId): void
    {
        $statement = $this->database->prepare(<<<'SQL'
            UPDATE organizations
            SET detail_status = 'error', updated_at = :updated_at
            WHERE org_id = :org_id
            SQL);
        $statement->execute([':org_id' => $orgId, ':updated_at' => self::now()]);
    }

    public function markDetailNotLoaded(int $orgId): void
    {
        $statement = $this->database->prepare(<<<'SQL'
            UPDATE organizations
            SET detail_status = 'not_loaded', updated_at = :updated_at
            WHERE org_id = :org_id
              AND detail_status != 'loaded'
            SQL);
        $statement->execute([':org_id' => $orgId, ':updated_at' => self::now()]);
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function toApi(array $row): array
    {
        return [
            'orgId' => (int) $row['org_id'],
            'cmsSecurityHash' => $row['cms_security_hash'],
            'countryCode' => $row['country_code'],
            'orgType' => $row['org_type'],
            'longitude' => $row['longitude'] !== null ? (float) $row['longitude'] : null,
            'latitude' => $row['latitude'] !== null ? (float) $row['latitude'] : null,
            'chapterName' => $row['chapter_name'],
            'region' => $row['region'],
            'regionId' => $row['region_id'] !== null ? (int) $row['region_id'] : null,
            'city' => $row['city'],
            'postalCode' => $row['postal_code'],
            'street' => $row['street'],
            'venue' => $row['venue'],
            'meetingDay' => $row['meeting_day'],
            'meetingTime' => $row['meeting_time'],
            'meetingType' => $row['meeting_type'],
            'meetingDuration' => $row['meeting_duration'] !== null ? (int) $row['meeting_duration'] : null,
            'memberCount' => $row['member_count'] !== null ? (int) $row['member_count'] : null,
            'chapterUrl' => $row['chapter_url'],
            'visitorRegistrationUrl' => $row['visitor_registration_url'],
            'onlineMeetingUrl' => $row['online_meeting_link'],
            'timezone' => $row['timezone'],
            'status' => $row['status'],
            'description' => $row['description'],
            'detailStatus' => $row['detail_status'],
            'mapLoadedAt' => $row['map_loaded_at'],
            'detailsLoadedAt' => $row['details_loaded_at'],
            'createdAt' => $row['created_at'],
            'updatedAt' => $row['updated_at'],
        ];
    }

    private static function now(): string
    {
        return gmdate('Y-m-d\TH:i:s\Z');
    }
}
