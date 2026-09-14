<?php

declare(strict_types=1);

require_once __DIR__.'/RepresentationExpiryPolicy.php';

final class WatchlistRepository
{
    public function __construct(private readonly PDO $database) {}

    /** @return list<int> */
    public function organizationIds(int $userId): array
    {
        $statement=$this->database->prepare('SELECT organization_id FROM user_chapter_watchlist WHERE user_id=:user ORDER BY organization_id');
        $statement->execute([':user'=>$userId]);
        return array_map('intval',$statement->fetchAll(PDO::FETCH_COLUMN));
    }

    public function add(int $userId,int $organizationId): bool
    {
        $chapter=$this->database->prepare("SELECT 1 FROM organizations WHERE org_id=:org AND org_type='CHAPTER'");$chapter->execute([':org'=>$organizationId]);
        if($chapter->fetchColumn()===false)throw new InvalidArgumentException('Das Chapter wurde nicht gefunden.');
        $sql=$this->database->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'
            ?'INSERT IGNORE INTO user_chapter_watchlist(user_id,organization_id,created_at)VALUES(:user,:org,:created)'
            :'INSERT OR IGNORE INTO user_chapter_watchlist(user_id,organization_id,created_at)VALUES(:user,:org,:created)';
        $statement=$this->database->prepare($sql);$statement->execute([':user'=>$userId,':org'=>$organizationId,':created'=>self::now()]);return$statement->rowCount()===1;
    }

    public function remove(int $userId,int $organizationId): bool
    {
        $statement=$this->database->prepare('DELETE FROM user_chapter_watchlist WHERE user_id=:user AND organization_id=:org');$statement->execute([':user'=>$userId,':org'=>$organizationId]);return$statement->rowCount()===1;
    }

    public function setEmailNotifications(int $userId,bool $enabled): void
    {
        $statement=$this->database->prepare('UPDATE users SET watchlist_email_enabled=:enabled,watchlist_email_enabled_at=:enabled_at,updated_at=:updated WHERE id=:user');
        $now=self::now();$statement->execute([':enabled'=>$enabled?1:0,':enabled_at'=>$enabled?$now:null,':updated'=>$now,':user'=>$userId]);
    }

    /** @return array{emailNotifications:bool,activeRequestChapterCount:int,chapters:list<array<string,mixed>>,requests:list<array<string,mixed>>} */
    public function accountData(int $userId,?DateTimeImmutable $now=null): array
    {
        $enabled=$this->database->prepare('SELECT watchlist_email_enabled FROM users WHERE id=:user');$enabled->execute([':user'=>$userId]);
        $chapters=$this->database->prepare("SELECT o.org_id,o.chapter_name,o.city,o.short_link_slug FROM user_chapter_watchlist w JOIN organizations o ON o.org_id=w.organization_id AND o.org_type='CHAPTER' WHERE w.user_id=:user ORDER BY o.chapter_name,o.org_id");$chapters->execute([':user'=>$userId]);
        $chapterRows=array_map(static fn(array$row):array=>['organizationId'=>(int)$row['org_id'],'chapterName'=>(string)$row['chapter_name'],'city'=>$row['city']===null?null:(string)$row['city'],'chapterLink'=>$row['short_link_slug']?'/'.(string)$row['short_link_slug']:null],$chapters->fetchAll());
        $requests=$this->database->prepare("SELECT r.id,r.org_id,r.request_date,o.chapter_name,o.city,o.short_link_slug FROM user_chapter_watchlist w JOIN representation_requests r ON r.org_id=w.organization_id JOIN organizations o ON o.org_id=r.org_id WHERE w.user_id=:user AND r.request_date>=:minimum ORDER BY r.request_date,o.chapter_name,r.id");
        $requests->execute([':user'=>$userId,':minimum'=>RepresentationExpiryPolicy::minimumActiveDate($now)]);
        $requestRows=[];$activeChapters=[];foreach($requests->fetchAll()as$row){if(!RepresentationExpiryPolicy::isActive((string)$row['request_date'],$now))continue;$activeChapters[(int)$row['org_id']]=true;$requestRows[]=['requestId'=>(int)$row['id'],'chapterName'=>self::displayName($row),'requestedDate'=>(string)$row['request_date'],'chapterLink'=>$row['short_link_slug']?'/'.(string)$row['short_link_slug']:null];}
        return['emailNotifications'=>(bool)$enabled->fetchColumn(),'activeRequestChapterCount'=>count($activeChapters),'chapters'=>$chapterRows,'requests'=>$requestRows];
    }

    /** @return list<array<string,mixed>> */
    public function notificationCandidates(?DateTimeImmutable $now=null): array
    {
        $statement=$this->database->prepare("SELECT r.id request_id,r.request_date,r.created_at request_created_at,r.user_id requester_id,o.chapter_name,o.city,o.short_link_slug,w.user_id,w.created_at watch_created_at,u.first_name,u.last_name,u.email,u.watchlist_email_enabled_at FROM user_chapter_watchlist w JOIN users u ON u.id=w.user_id AND u.status='active' AND u.email_verified_at IS NOT NULL AND u.watchlist_email_enabled=1 JOIN representation_requests r ON r.org_id=w.organization_id AND r.user_id<>w.user_id JOIN organizations o ON o.org_id=r.org_id AND o.org_type='CHAPTER' LEFT JOIN watchlist_notifications n ON n.user_id=w.user_id AND n.representation_request_id=r.id AND n.channel='email' WHERE n.id IS NULL AND r.request_date>=:minimum AND r.created_at>=w.created_at AND u.watchlist_email_enabled_at IS NOT NULL AND r.created_at>=u.watchlist_email_enabled_at ORDER BY r.id,w.user_id");
        $statement->execute([':minimum'=>RepresentationExpiryPolicy::minimumActiveDate($now)]);return array_values(array_filter($statement->fetchAll(),static fn(array$row):bool=>RepresentationExpiryPolicy::isActive((string)$row['request_date'],$now)));
    }

    public function markSent(int $userId,int $requestId,string $sentAt): void
    {
        $statement=$this->database->prepare("INSERT INTO watchlist_notifications(user_id,representation_request_id,channel,sent_at,created_at)VALUES(:user,:request,'email',:sent,:created)");$statement->execute([':user'=>$userId,':request'=>$requestId,':sent'=>$sentAt,':created'=>$sentAt]);
    }

    private static function displayName(array$row):string{return trim((string)$row['chapter_name'].($row['city']?' ('.(string)$row['city'].')':''));}
    private static function now():string{return gmdate('Y-m-d\TH:i:s\Z');}
}
