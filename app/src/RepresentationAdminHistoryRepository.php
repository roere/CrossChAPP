<?php

declare(strict_types=1);

require_once __DIR__.'/RepresentationExpiryPolicy.php';

final class RepresentationAdminHistoryRepository
{
    public const LIMIT=500;
    public function __construct(private readonly PDO$database){}

    /** @return array{items:list<array<string,mixed>>,total:int,limit:int} */
    public function history(?DateTimeImmutable$now=null):array
    {
        $items=[];
        $requestSql="SELECT 'request' kind,r.id source_id,r.request_date target_date,r.org_id,u.first_name,u.last_name,o.chapter_name,a.id assignment_id,a.status assignment_status,p.first_name second_first,p.last_name second_last,po.chapter_name second_chapter FROM representation_requests r JOIN users u ON u.id=r.user_id JOIN organizations o ON o.org_id=r.org_id LEFT JOIN representation_assignments a ON a.request_id=r.id LEFT JOIN users p ON p.id=a.representative_user_id LEFT JOIN organizations po ON po.org_id=p.home_chapter_org_id";
        foreach($this->database->query($requestSql)->fetchAll()as$row)$items[]=$this->map($row,$now);
        $offerSql="SELECT 'offer' kind,o.id source_id,d.offer_date target_date,o.org_id,u.first_name,u.last_name,c.chapter_name,a.id assignment_id,a.status assignment_status,p.first_name second_first,p.last_name second_last,pc.chapter_name second_chapter FROM representation_offers o JOIN representation_offer_dates d ON d.offer_id=o.id JOIN users u ON u.id=o.user_id JOIN organizations c ON c.org_id=o.org_id LEFT JOIN representation_assignments a ON a.offer_id=o.id AND a.representation_date=d.offer_date LEFT JOIN users p ON p.id=a.requester_user_id LEFT JOIN organizations pc ON pc.org_id=p.home_chapter_org_id";
        foreach($this->database->query($offerSql)->fetchAll()as$row)$items[]=$this->map($row,$now);
        $allDatesSql="SELECT 'offer' kind,o.id source_id,a.representation_date target_date,o.org_id,u.first_name,u.last_name,c.chapter_name,a.id assignment_id,a.status assignment_status,p.first_name second_first,p.last_name second_last,pc.chapter_name second_chapter FROM representation_offers o JOIN representation_assignments a ON a.offer_id=o.id JOIN users u ON u.id=o.user_id JOIN organizations c ON c.org_id=o.org_id LEFT JOIN users p ON p.id=a.requester_user_id LEFT JOIN organizations pc ON pc.org_id=p.home_chapter_org_id WHERE o.all_dates=1";
        foreach($this->database->query($allDatesSql)->fetchAll()as$row)$items[]=$this->map($row,$now);
        usort($items,static fn(array$a,array$b):int=>strcmp($b['date'],$a['date'])?:($b['sortId']<=>$a['sortId']));$total=count($items);return['items'=>array_slice($items,0,self::LIMIT),'total'=>$total,'limit'=>self::LIMIT];
    }

    /** @param array<string,mixed>$row @return array<string,mixed> */
    private function map(array$row,?DateTimeImmutable$now):array
    {
        $assigned=$row['assignment_id']!==null;$cancelled=$assigned&&$row['assignment_status']==='cancelled';$occurred=$assigned&&!$cancelled&&!RepresentationExpiryPolicy::isActive((string)$row['target_date'],$now);
        return['rowKey'=>$row['kind'].':'.$row['source_id'].':'.$row['target_date'].':'.($row['assignment_id']??'base'),'type'=>$row['kind']==='request'?'Gesuch':'Angebot','person'=>trim((string)$row['first_name'].' '.(string)$row['last_name']),'chapter'=>(string)$row['chapter_name'],'secondPerson'=>$assigned?trim((string)$row['second_first'].' '.(string)$row['second_last']):null,'secondChapter'=>$assigned&&$row['second_chapter']!==null?(string)$row['second_chapter']:null,'date'=>(string)$row['target_date'],'occurred'=>$occurred,'assignmentStatus'=>$assigned?(string)$row['assignment_status']:null,'sortId'=>(int)($row['assignment_id']??$row['source_id'])];
    }
}
