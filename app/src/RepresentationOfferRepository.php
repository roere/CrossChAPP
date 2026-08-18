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

    /** @return list<array<string,mixed>> */
    public function findForHomeChapter(int $orgId, int $currentUserId, string $today): array
    {
        $statement = $this->database->prepare(<<<'SQL'
            SELECT offers.id, offers.all_dates, offers.created_at, users.first_name, users.last_name,
                   home.chapter_name AS provider_home_chapter,
                   GROUP_CONCAT(DISTINCT CASE WHEN dates.offer_date >= :today THEN dates.offer_date END) AS offer_dates,
                   MIN(CASE WHEN dates.offer_date >= :today THEN dates.offer_date END) AS next_date
            FROM representation_offers offers
            JOIN users ON users.id = offers.user_id AND users.status = 'active'
            LEFT JOIN representation_offer_dates dates ON dates.offer_id = offers.id
            LEFT JOIN organizations home ON home.org_id = users.home_chapter_org_id
            WHERE offers.org_id = :org_id AND offers.user_id != :user_id
            GROUP BY offers.id HAVING offers.all_dates = 1 OR next_date IS NOT NULL
            ORDER BY offers.all_dates ASC, next_date ASC, users.first_name COLLATE NOCASE, offers.id
            SQL);
        $statement->execute([':today' => $today, ':org_id' => $orgId, ':user_id' => $currentUserId]);
        return array_map(static function (array $row): array {
            $initial = function_exists('mb_substr') ? mb_substr((string) $row['last_name'], 0, 1) : substr((string) $row['last_name'], 0, 1);
            return ['providerName' => trim((string) $row['first_name'] . ' ' . $initial . '.'), 'providerHomeChapter' => $row['provider_home_chapter'],
                'allDates' => (bool) $row['all_dates'], 'dates' => self::splitValues($row['offer_dates'])];
        }, $statement->fetchAll());
    }

    /** @return list<string> */
    private static function splitValues(mixed $value): array
    {
        if (!is_string($value) || $value === '') return [];
        $values = array_values(array_unique(explode(',', $value))); sort($values); return $values;
    }
}
