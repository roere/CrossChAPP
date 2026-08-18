<?php

declare(strict_types=1);

final class RepresentationCleanupService
{
    public function __construct(private readonly PDO $database) {}

    /** @return array{requests:int,offerDates:int,offers:int} */
    public function runCleanup(): array
    {
        $requests = $this->database->query(<<<'SQL'
            SELECT requests.id, requests.request_date, organizations.timezone
            FROM representation_requests requests
            JOIN organizations ON organizations.org_id = requests.org_id
            SQL)->fetchAll();
        $dates = $this->database->query(<<<'SQL'
            SELECT dates.id, dates.offer_date, organizations.timezone
            FROM representation_offer_dates dates
            JOIN representation_offers offers ON offers.id = dates.offer_id
            JOIN organizations ON organizations.org_id = offers.org_id
            WHERE offers.all_dates = 0
            SQL)->fetchAll();
        $expiredRequests = $this->expiredIds($requests, 'request_date');
        $expiredDates = $this->expiredIds($dates, 'offer_date');

        $this->database->exec('BEGIN IMMEDIATE TRANSACTION');
        try {
            $requestCount = $this->deleteIds('representation_requests', $expiredRequests);
            $dateCount = $this->deleteIds('representation_offer_dates', $expiredDates);
            $signature = $this->database->prepare("UPDATE representation_offers SET date_signature = COALESCE((SELECT GROUP_CONCAT(offer_date, '|') FROM (SELECT offer_date FROM representation_offer_dates WHERE offer_id = :offer_id ORDER BY offer_date)), ''), updated_at = :updated WHERE id = :offer_id");
            foreach ($this->database->query('SELECT id FROM representation_offers WHERE all_dates = 0')->fetchAll(PDO::FETCH_COLUMN) as $offerId) $signature->execute([':offer_id' => (int) $offerId, ':updated' => gmdate('Y-m-d\TH:i:s\Z')]);
            $statement = $this->database->prepare(<<<'SQL'
                DELETE FROM representation_offers
                WHERE all_dates = 0
                  AND NOT EXISTS (SELECT 1 FROM representation_offer_dates dates WHERE dates.offer_id = representation_offers.id)
                SQL);
            $statement->execute(); $offerCount = $statement->rowCount();
            $this->database->exec('COMMIT');
        } catch (Throwable $exception) {
            try { $this->database->exec('ROLLBACK'); } catch (Throwable) {}
            throw $exception;
        }
        if ($requestCount + $dateCount + $offerCount > 0) {
            error_log(sprintf('CrossChAPP representation cleanup: requests=%d offer_dates=%d offers=%d', $requestCount, $dateCount, $offerCount));
        }
        return ['requests' => $requestCount, 'offerDates' => $dateCount, 'offers' => $offerCount];
    }

    /** @param list<array<string,mixed>> $rows @return list<int> */
    private function expiredIds(array $rows, string $dateKey): array
    {
        $todayByZone = []; $ids = [];
        foreach ($rows as $row) {
            $zoneName = is_string($row['timezone'] ?? null) && $row['timezone'] !== '' ? $row['timezone'] : 'Europe/Berlin';
            try { $zone = new DateTimeZone($zoneName); } catch (Throwable) { $zoneName = 'Europe/Berlin'; $zone = new DateTimeZone($zoneName); }
            $todayByZone[$zoneName] ??= (new DateTimeImmutable('today', $zone))->format('Y-m-d');
            if ((string) $row[$dateKey] < $todayByZone[$zoneName]) $ids[] = (int) $row['id'];
        }
        return $ids;
    }

    /** @param list<int> $ids */
    private function deleteIds(string $table, array $ids): int
    {
        if ($ids === []) return 0;
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $statement = $this->database->prepare("DELETE FROM {$table} WHERE id IN ({$placeholders})");
        $statement->execute($ids); return $statement->rowCount();
    }
}
