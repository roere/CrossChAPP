<?php

declare(strict_types=1);

final class RepresentationRequestRepository
{
    public function __construct(private readonly PDO $database) {}

    /** @return array<string,mixed>|null */
    public function chapterForUser(int $userId): ?array
    {
        $statement = $this->database->prepare(<<<'SQL'
            SELECT users.home_chapter_org_id AS org_id, organizations.chapter_name,
                   organizations.meeting_day, organizations.timezone
            FROM users JOIN organizations ON organizations.org_id = users.home_chapter_org_id
            WHERE users.id = :user_id AND users.status = 'active'
            SQL);
        $statement->execute([':user_id' => $userId]); $row = $statement->fetch();
        return is_array($row) ? $row : null;
    }

    /** @return list<array{id:int,requestDate:string}> */
    public function forUser(int $userId, string $today): array
    {
        $statement = $this->database->prepare(<<<'SQL'
            SELECT requests.id, requests.request_date
            FROM representation_requests requests
            JOIN users ON users.id = requests.user_id AND users.home_chapter_org_id = requests.org_id
            WHERE requests.user_id = :user_id AND requests.request_date >= :today
            ORDER BY requests.request_date, requests.id
            SQL);
        $statement->execute([':user_id' => $userId, ':today' => $today]);
        return array_map(static fn (array $row): array => ['id' => (int) $row['id'], 'requestDate' => (string) $row['request_date']], $statement->fetchAll());
    }

    /** @return array{id:int,requestDate:string} */
    public function create(int $userId, string $requestDate): array
    {
        $chapter = $this->chapterForUser($userId);
        if ($chapter === null) throw new DomainException('Ein Heimatchapter ist erforderlich.');
        $zone = self::timezone((string) ($chapter['timezone'] ?? '')); $date = DateTimeImmutable::createFromFormat('!Y-m-d', $requestDate, $zone);
        $today = new DateTimeImmutable('today', $zone);
        if (!$date || $date->format('Y-m-d') !== $requestDate || $date < $today) throw new InvalidArgumentException('Bitte wähle einen heutigen oder zukünftigen Termin.');
        if (self::weekday($date) !== self::normalizeWeekday((string) ($chapter['meeting_day'] ?? ''))) throw new InvalidArgumentException('Der Termin passt nicht zum regulären Meeting-Wochentag deines Heimatchapters.');
        $now = gmdate('Y-m-d\TH:i:s\Z');
        try {
            $statement = $this->database->prepare('INSERT INTO representation_requests (user_id, org_id, request_date, created_at, updated_at) VALUES (:user_id, :org_id, :request_date, :created_at, :updated_at)');
            $statement->execute([':user_id' => $userId, ':org_id' => $chapter['org_id'], ':request_date' => $requestDate, ':created_at' => $now, ':updated_at' => $now]);
        } catch (PDOException $exception) {
            if ((string) $exception->getCode() === '23000') throw new DomainException('Für diesen Termin besteht bereits ein Vertretungsgesuch.');
            throw $exception;
        }
        return ['id' => (int) $this->database->lastInsertId(), 'requestDate' => $requestDate];
    }

    public function deleteForUser(int $requestId, int $userId): bool
    {
        $statement = $this->database->prepare(<<<'SQL'
            DELETE FROM representation_requests
            WHERE id = :id AND user_id = :user_id
              AND org_id = (SELECT home_chapter_org_id FROM users WHERE id = :user_id)
            SQL);
        $statement->execute([':id' => $requestId, ':user_id' => $userId]); return $statement->rowCount() === 1;
    }

    /** @param list<int> $orgIds @return array<int,list<array<string,mixed>>> */
    public function activeForOrganizations(array $orgIds, ?int $viewerUserId, string $today): array
    {
        $orgIds = array_values(array_unique(array_filter($orgIds, static fn ($id): bool => is_int($id) && $id > 0)));
        if ($orgIds === []) return [];
        $placeholders = implode(',', array_fill(0, count($orgIds), '?'));
        $statement = $this->database->prepare("SELECT requests.id,requests.user_id,requests.org_id,requests.request_date,users.first_name,users.last_name FROM representation_requests requests JOIN users ON users.id=requests.user_id AND users.status='active' AND users.email_verified_at IS NOT NULL WHERE requests.org_id IN ({$placeholders}) AND requests.request_date >= ? ORDER BY requests.org_id,requests.request_date,requests.id");
        $statement->execute([...$orgIds, $today]); $result = [];
        foreach ($statement->fetchAll() as $row) {
            $own = $viewerUserId !== null && (int) $row['user_id'] === $viewerUserId;
            $item = ['requestDate' => (string) $row['request_date'], 'isOwn' => $own, 'canContact' => !$own];
            if ($viewerUserId !== null) { $initial = function_exists('mb_substr') ? mb_substr((string) $row['last_name'], 0, 1) : substr((string) $row['last_name'], 0, 1); $item['displayName'] = trim((string) $row['first_name'] . ' ' . $initial . '.'); }
            if (!$own) $item['requestId'] = (int) $row['id'];
            $result[(int) $row['org_id']][] = $item;
        }
        return $result;
    }

    /** @return array<string,mixed>|null */
    public function contactContext(int $requestId, int $contactUserId): ?array
    {
        $statement = $this->database->prepare(<<<'SQL'
            SELECT requests.id,requests.user_id AS recipient_id,requests.request_date,requests.org_id,
                   owner.first_name AS owner_first_name,owner.last_name AS owner_last_name,owner.email AS owner_email,
                   contact.first_name AS contact_first_name,contact.last_name AS contact_last_name,contact.email AS contact_email,
                   contact.home_chapter_org_id,contact_chapter.chapter_name AS contact_chapter,requested_chapter.chapter_name AS requested_chapter,
                   requested_chapter.timezone
            FROM representation_requests requests
            JOIN users owner ON owner.id=requests.user_id AND owner.status='active' AND owner.email_verified_at IS NOT NULL
            JOIN users contact ON contact.id=:contact_user_id AND contact.status='active' AND contact.email_verified_at IS NOT NULL
            JOIN organizations requested_chapter ON requested_chapter.org_id=requests.org_id
            LEFT JOIN organizations contact_chapter ON contact_chapter.org_id=contact.home_chapter_org_id
            WHERE requests.id=:request_id AND requests.user_id != contact.id
            SQL);
        $statement->execute([':request_id' => $requestId, ':contact_user_id' => $contactUserId]); $row = $statement->fetch(); return is_array($row) ? $row : null;
    }

    /** @return array<string,mixed>|null */
    public function anonymousContactContext(int $requestId): ?array
    {
        $statement = $this->database->prepare(<<<'SQL'
            SELECT requests.id,requests.user_id AS recipient_id,requests.request_date,requests.org_id,
                   owner.first_name AS owner_first_name,owner.last_name AS owner_last_name,owner.email AS owner_email,
                   requested_chapter.chapter_name AS requested_chapter,requested_chapter.timezone
            FROM representation_requests requests
            JOIN users owner ON owner.id=requests.user_id AND owner.status='active' AND owner.email_verified_at IS NOT NULL
            JOIN organizations requested_chapter ON requested_chapter.org_id=requests.org_id
            WHERE requests.id=:request_id
            SQL);
        $statement->execute([':request_id' => $requestId]); $row = $statement->fetch(); return is_array($row) ? $row : null;
    }

    private static function timezone(string $timezone): DateTimeZone
    {
        try { return new DateTimeZone($timezone !== '' ? $timezone : 'Europe/Berlin'); }
        catch (Throwable) { return new DateTimeZone('Europe/Berlin'); }
    }
    private static function weekday(DateTimeImmutable $date): string { return ['Montag','Dienstag','Mittwoch','Donnerstag','Freitag','Samstag','Sonntag'][(int) $date->format('N') - 1]; }
    private static function normalizeWeekday(string $day): string
    {
        $day = trim(explode(',', $day)[0]);
        $aliases = ['monday'=>'Montag','tuesday'=>'Dienstag','wednesday'=>'Mittwoch','thursday'=>'Donnerstag','friday'=>'Freitag','saturday'=>'Samstag','sunday'=>'Sonntag'];
        return $aliases[strtolower($day)] ?? ucfirst(strtolower($day));
    }
}
