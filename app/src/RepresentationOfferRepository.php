<?php

declare(strict_types=1);

final class RepresentationOfferRepository
{
    public function __construct(private readonly PDO $database) {}

    /** @param list<int> $orgIds @param list<string> $dates @return list<int> */
    public function createMany(int $userId, array $orgIds, bool $allDates, array $dates): array
    {
        sort($dates); $signature = $allDates ? '' : implode('|', $dates); $now = gmdate('Y-m-d\TH:i:s\Z');
        $this->database->exec('BEGIN IMMEDIATE TRANSACTION');
        try {
            $this->assertMeetingDates($orgIds, $allDates, $dates);
            $duplicate = $this->database->prepare('SELECT COUNT(*) FROM representation_offers WHERE user_id = :user_id AND org_id = :org_id AND all_dates = :all_dates AND date_signature = :signature');
            foreach ($orgIds as $orgId) {
                $duplicate->execute([':user_id' => $userId, ':org_id' => $orgId, ':all_dates' => $allDates ? 1 : 0, ':signature' => $signature]);
                if ((int) $duplicate->fetchColumn() > 0) throw new DomainException('duplicate_offer');
            }
            $insert = $this->database->prepare('INSERT INTO representation_offers (user_id, org_id, all_dates, date_signature, created_at, updated_at) VALUES (:user_id, :org_id, :all_dates, :signature, :created_at, :updated_at)');
            $date = $this->database->prepare('INSERT INTO representation_offer_dates (offer_id, offer_date) VALUES (:offer_id, :offer_date)');
            $ids = [];
            foreach ($orgIds as $orgId) {
                $insert->execute([':user_id' => $userId, ':org_id' => $orgId, ':all_dates' => $allDates ? 1 : 0, ':signature' => $signature, ':created_at' => $now, ':updated_at' => $now]);
                $offerId = (int) $this->database->lastInsertId(); $ids[] = $offerId;
                if (!$allDates) foreach ($dates as $offerDate) $date->execute([':offer_id' => $offerId, ':offer_date' => $offerDate]);
            }
            $this->database->exec('COMMIT'); return $ids;
        } catch (Throwable $exception) {
            try { $this->database->exec('ROLLBACK'); } catch (Throwable) {}
            throw $exception;
        }
    }

    /** @param list<int> $orgIds @param list<string> $dates */
    private function assertMeetingDates(array $orgIds, bool $allDates, array $dates): void
    {
        if ($allDates) return;
        $placeholders = implode(',', array_fill(0, count($orgIds), '?'));
        $statement = $this->database->prepare("SELECT org_id, chapter_name, meeting_day, timezone FROM organizations WHERE org_type = 'CHAPTER' AND org_id IN ($placeholders)");
        $statement->execute($orgIds);
        $chapters = [];
        foreach ($statement->fetchAll() as $chapter) $chapters[(int) $chapter['org_id']] = $chapter;
        if (count($chapters) !== count(array_unique($orgIds))) throw new InvalidArgumentException('Mindestens ein ausgewähltes Chapter ist nicht vorhanden.');

        $errors = [];
        foreach ($orgIds as $orgId) {
            $chapter = $chapters[$orgId];
            $expectedWeekday = self::normalizeWeekday((string) ($chapter['meeting_day'] ?? ''));
            if ($expectedWeekday === null) throw new InvalidArgumentException('Für ' . (string) $chapter['chapter_name'] . ' ist kein regelmäßiger Meetingtag hinterlegt.');
            $timezone = self::timezone((string) ($chapter['timezone'] ?? ''));
            foreach ($dates as $date) {
                $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date, $timezone);
                if (!$parsed || $parsed->format('Y-m-d') !== $date || (int) $parsed->format('N') !== $expectedWeekday) {
                    $label = $parsed && $parsed->format('Y-m-d') === $date ? $parsed->format('d.m.Y') : $date;
                    $errors[] = 'Der ' . $label . ' passt nicht zum Meetingtag von ' . (string) $chapter['chapter_name'] . '.';
                }
            }
        }
        if ($errors !== []) throw new InvalidArgumentException(implode(' ', array_unique($errors)));
    }

    private static function normalizeWeekday(string $weekday): ?int
    {
        $normalized = function_exists('mb_strtolower') ? mb_strtolower(trim($weekday), 'UTF-8') : strtolower(trim($weekday));
        return match ($normalized) {
            'montag', 'monday' => 1,
            'dienstag', 'tuesday' => 2,
            'mittwoch', 'wednesday' => 3,
            'donnerstag', 'thursday' => 4,
            'freitag', 'friday' => 5,
            'samstag', 'saturday' => 6,
            'sonntag', 'sunday' => 7,
            default => null,
        };
    }

    private static function timezone(string $timezone): DateTimeZone
    {
        try { return new DateTimeZone($timezone !== '' ? $timezone : 'Europe/Berlin'); }
        catch (Throwable) { return new DateTimeZone('Europe/Berlin'); }
    }

    /** @return list<array<string,mixed>> */
    public function forUser(int $userId): array
    {
        $statement = $this->database->prepare(<<<'SQL'
            SELECT offers.id, offers.org_id, offers.all_dates, offers.created_at, organizations.chapter_name
            FROM representation_offers offers JOIN organizations ON organizations.org_id = offers.org_id
            WHERE offers.user_id = :user_id ORDER BY offers.created_at DESC, offers.id DESC
            SQL);
        $statement->execute([':user_id' => $userId]);
        $dates = $this->database->prepare('SELECT offer_date FROM representation_offer_dates WHERE offer_id = :offer_id ORDER BY offer_date');
        return array_map(static function (array $row) use ($dates): array {
            $dates->execute([':offer_id' => $row['id']]);
            return ['id' => (int) $row['id'], 'orgId' => (int) $row['org_id'], 'chapterName' => $row['chapter_name'],
                'allDates' => (bool) $row['all_dates'], 'dates' => $dates->fetchAll(PDO::FETCH_COLUMN), 'createdAt' => $row['created_at']];
        }, $statement->fetchAll());
    }

    public function deleteForUser(int $offerId, int $userId): bool
    {
        $statement = $this->database->prepare('DELETE FROM representation_offers WHERE id = :id AND user_id = :user_id');
        $statement->execute([':id' => $offerId, ':user_id' => $userId]); return $statement->rowCount() === 1;
    }

    /** @return array{datedOffers:list<array<string,mixed>>,allDatesOffers:list<array<string,mixed>>} */
    public function findForHomeChapter(int $orgId, int $currentUserId, string $today): array
    {
        $statement = $this->database->prepare(<<<'SQL'
            SELECT offers.id, offers.user_id, offers.all_dates, users.first_name, users.last_name,
                   users.home_chapter_org_id, users.bni_verification_status, dates.offer_date
            FROM representation_offers offers
            JOIN users ON users.id = offers.user_id AND users.status = 'active' AND users.email_verified_at IS NOT NULL
            LEFT JOIN representation_offer_dates dates ON dates.offer_id = offers.id
            WHERE offers.org_id = :org_id AND offers.user_id != :user_id
              AND (offers.all_dates = 1 OR dates.offer_date >= :today)
            ORDER BY dates.offer_date, users.first_name COLLATE NOCASE, offers.id
            SQL);
        $statement->execute([':today' => $today, ':org_id' => $orgId, ':user_id' => $currentUserId]);
        $dated = []; $always = [];
        foreach ($statement->fetchAll() as $row) {
            $provider = self::publicProvider($row);
            if ((bool) $row['all_dates']) $always[(int) $row['user_id']] = $provider;
            elseif (is_string($row['offer_date'])) $dated[$row['offer_date']][(int) $row['user_id']] = $provider;
        }
        ksort($dated);
        $datedOffers = [];
        foreach ($dated as $date => $providers) {
            foreach ($always as $userId => $provider) if (!isset($providers[$userId])) $providers[$userId] = $provider;
            uasort($providers, static fn (array $a, array $b): int => strcasecmp($a['displayName'], $b['displayName']));
            $datedOffers[] = ['date' => $date, 'providers' => array_values($providers)];
        }
        uasort($always, static fn (array $a, array $b): int => strcasecmp($a['displayName'], $b['displayName']));
        return ['datedOffers' => $datedOffers, 'allDatesOffers' => array_values($always)];
    }

    /** @return list<array<string,mixed>> */
    public function overviewForHomeChapter(int $orgId, int $currentUserId, string $today): array
    {
        $statement = $this->database->prepare(<<<'SQL'
            SELECT offers.id, offers.all_dates, users.id AS user_id, users.first_name, users.last_name,
                   users.home_chapter_org_id, users.bni_verification_status,
                   GROUP_CONCAT(CASE WHEN dates.offer_date >= :today THEN dates.offer_date END) AS offer_dates,
                   MIN(CASE WHEN dates.offer_date >= :today THEN dates.offer_date END) AS next_date
            FROM representation_offers offers
            JOIN users ON users.id = offers.user_id AND users.status = 'active' AND users.email_verified_at IS NOT NULL
            LEFT JOIN representation_offer_dates dates ON dates.offer_id = offers.id
            WHERE offers.org_id = :org_id AND offers.user_id != :user_id
            GROUP BY offers.id HAVING offers.all_dates = 1 OR next_date IS NOT NULL
            ORDER BY offers.all_dates, next_date, users.first_name COLLATE NOCASE, offers.id
            SQL);
        $statement->execute([':today' => $today, ':org_id' => $orgId, ':user_id' => $currentUserId]);
        return array_map(static function (array $row): array {
            $provider = self::publicProvider($row); $dates = self::splitValues($row['offer_dates']);
            return $provider + ['allDates' => (bool) $row['all_dates'], 'dates' => $dates];
        }, $statement->fetchAll());
    }

    /** @return array<string,mixed>|null */
    public function contactContext(int $offerId, int $requesterId): ?array
    {
        $statement = $this->database->prepare(<<<'SQL'
            SELECT offers.id, offers.user_id AS recipient_id, offers.org_id, offers.all_dates,
                   provider.first_name AS provider_first_name, provider.last_name AS provider_last_name, provider.email AS provider_email,
                   requester.first_name AS requester_first_name, requester.last_name AS requester_last_name, requester.email AS requester_email,
                   requester.home_chapter_org_id, chapter.chapter_name AS requester_chapter, chapter.meeting_day
            FROM representation_offers offers
            JOIN users provider ON provider.id = offers.user_id AND provider.status = 'active' AND provider.email_verified_at IS NOT NULL
            JOIN users requester ON requester.id = :requester_id AND requester.status = 'active'
            JOIN organizations chapter ON chapter.org_id = requester.home_chapter_org_id AND chapter.org_id = offers.org_id
            WHERE offers.id = :offer_id AND offers.user_id != requester.id
            SQL);
        $statement->execute([':requester_id' => $requesterId, ':offer_id' => $offerId]); $row = $statement->fetch();
        return is_array($row) ? $row : null;
    }

    public function offerHasDate(int $offerId, string $date): bool
    {
        $statement = $this->database->prepare('SELECT COUNT(*) FROM representation_offer_dates WHERE offer_id = :id AND offer_date = :date');
        $statement->execute([':id' => $offerId, ':date' => $date]); return (int) $statement->fetchColumn() === 1;
    }

    private static function publicProvider(array $row): array
    {
        $initial = function_exists('mb_substr') ? mb_substr((string) $row['last_name'], 0, 1) : substr((string) $row['last_name'], 0, 1);
        return ['offerId' => (int) $row['id'], 'displayName' => trim((string) $row['first_name'] . ' ' . $initial . '.'), 'isBniMember' => $row['home_chapter_org_id'] !== null, 'isVerified' => ($row['bni_verification_status'] ?? '') === 'manual_verified'];
    }

    /** @return list<string> */
    private static function splitValues(mixed $value): array
    {
        if (!is_string($value) || $value === '') return [];
        $values = array_values(array_unique(explode(',', $value))); sort($values); return $values;
    }
}
