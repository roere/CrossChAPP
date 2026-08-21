<?php

declare(strict_types=1);

require_once __DIR__ . '/DatabaseDialect.php';

final class RepresentationAssignmentService
{
    public function __construct(
        private readonly PDO $database,
        private readonly MailSettingsRepository $settings,
        private readonly MailService $mailer,
    ) {}

    /** @param array<string,mixed> $context @return array{token:string,link:string} */
    public function issueForRequest(array $context, int $contactLogId): array
    {
        return $this->issue('request_contact', (int)$context['id'], null, $contactLogId,
            (string)$context['request_date'], (int)$context['recipient_id'], (int)$context['contact_user_id']);
    }

    /** @param array<string,mixed> $context @return array{token:string,link:string} */
    public function issueForOffer(array $context, string $date, int $contactLogId): array
    {
        return $this->issue('offer_contact', null, (int)$context['id'], $contactLogId,
            $date, (int)$context['requester_id'], (int)$context['recipient_id']);
    }

    /** @return array<string,mixed> */
    public function inspect(string $rawToken): array
    {
        $token = $this->tokenRow($rawToken, false);
        if ($token === null || $token['used_at'] !== null || $token['invalidated_at'] !== null) {
            throw new DomainException('Dieser Link ist nicht mehr gültig.');
        }
        $context = $this->context($token);
        if ($context === null || !$this->underlyingAvailable($token)) throw new DomainException('Dieser Link ist nicht mehr gültig.');
        return $context + [
            'direction'=>(string)$token['direction'],
            'requiredUserId'=>$token['direction']==='request_contact' ? (int)$token['requester_user_id'] : (int)$token['representative_user_id'],
        ];
    }

    /** @return array<string,mixed> */
    public function accept(string $rawToken, int $userId): array
    {
        DatabaseDialect::beginWrite($this->database);
        try {
            $token = $this->tokenRow($rawToken, true);
            if ($token === null || $token['used_at'] !== null || $token['invalidated_at'] !== null) throw new DomainException('Dieser Link ist nicht mehr gültig.');
            $required = $token['direction']==='request_contact' ? (int)$token['requester_user_id'] : (int)$token['representative_user_id'];
            if ($required !== $userId) throw new DomainException('Dieser Link ist für ein anderes Benutzerkonto bestimmt.');
            if (!$this->underlyingAvailable($token)) throw new DomainException('Diese Vertretung wurde bereits vergeben.');
            $now = gmdate('Y-m-d\TH:i:s\Z');
            $context = $this->context($token);
            if ($context === null) throw new DomainException('Dieser Link ist nicht mehr gültig.');
            $slot = implode(':', [(int)$token['requester_user_id'], (int)$context['chapter_org_id'], (string)$token['representation_date']]);
            $insert=$this->database->prepare('INSERT INTO representation_assignments(request_id,offer_id,chapter_org_id,representation_date,requester_user_id,representative_user_id,status,active_slot_key,accepted_at,accepted_by_user_id,created_at,updated_at) VALUES(:request,:offer,:chapter,:date,:requester,:representative,\'active\',:slot,:accepted,:accepted_by,:created,:updated)');
            try {$insert->execute([':request'=>$token['request_id'],':offer'=>$token['offer_id'],':chapter'=>$context['chapter_org_id'],':date'=>$token['representation_date'],':requester'=>$token['requester_user_id'],':representative'=>$token['representative_user_id'],':slot'=>$slot,':accepted'=>$now,':accepted_by'=>$userId,':created'=>$now,':updated'=>$now]);}
            catch(PDOException $e){if((string)$e->getCode()==='23000')throw new DomainException('Diese Vertretung wurde bereits vergeben.');throw $e;}
            $assignmentId=(int)$this->database->lastInsertId();
            $used=$this->database->prepare('UPDATE representation_acceptance_tokens SET used_at=:now WHERE id=:id');$used->execute([':now'=>$now,':id'=>$token['id']]);
            if($token['direction']==='request_contact'){
                $invalidate=$this->database->prepare('UPDATE representation_acceptance_tokens SET invalidated_at=:now WHERE used_at IS NULL AND invalidated_at IS NULL AND id<>:id AND request_id=:request');
                $invalidate->execute([':now'=>$now,':id'=>$token['id'],':request'=>$token['request_id']]);
            }else{
                $invalidate=$this->database->prepare('UPDATE representation_acceptance_tokens SET invalidated_at=:now WHERE used_at IS NULL AND invalidated_at IS NULL AND id<>:id AND requester_user_id=:requester AND representation_date=:date AND offer_id IN(SELECT id FROM representation_offers WHERE org_id=:chapter)');
                $invalidate->execute([':now'=>$now,':id'=>$token['id'],':requester'=>$token['requester_user_id'],':date'=>$token['representation_date'],':chapter'=>$context['chapter_org_id']]);
            }
            $this->database->exec('COMMIT');
        } catch(Throwable $e) { try{$this->database->exec('ROLLBACK');}catch(Throwable){} throw $e; }
        $result=$this->assignmentContext($assignmentId);
        if ($result !== null) $this->sendPair($result, 'confirmed');
        return $result ?? ['id'=>$assignmentId];
    }

    /** @return list<array<string,mixed>> */
    public function forUser(int $userId): array
    {
        $statement=$this->database->prepare($this->assignmentSelect().' WHERE a.status=\'active\' AND (a.requester_user_id=:requester OR a.representative_user_id=:representative) ORDER BY a.representation_date,a.id');
        $statement->execute([':requester'=>$userId,':representative'=>$userId]);
        return array_map(fn(array $row)=>$this->publicAssignment($this->withFullNames($row),$userId),$statement->fetchAll());
    }

    /** @return array<string,mixed> */
    public function cancel(int $assignmentId, int $userId): array
    {
        DatabaseDialect::beginWrite($this->database);
        try {
            $suffix=DatabaseDialect::isMysql($this->database)?' FOR UPDATE':'';
            $statement=$this->database->prepare($this->assignmentSelect().' WHERE a.id=:id'.$suffix);$statement->execute([':id'=>$assignmentId]);$row=$statement->fetch();
            if(!is_array($row)||(int)$row['requester_user_id']!==$userId&&(int)$row['representative_user_id']!==$userId)throw new DomainException('Diese Vereinbarung wurde nicht gefunden.');
            $row=$this->withFullNames($row);
            if($row['status']!=='active')throw new DomainException('Diese Vertretung ist bereits storniert.');
            $now=gmdate('Y-m-d\TH:i:s\Z');$update=$this->database->prepare("UPDATE representation_assignments SET status='cancelled',active_slot_key=NULL,cancelled_at=:now,cancelled_by_user_id=:user,updated_at=:now WHERE id=:id AND status='active'");
            $update->execute([':now'=>$now,':user'=>$userId,':id'=>$assignmentId]);if($update->rowCount()!==1)throw new DomainException('Diese Vertretung ist bereits storniert.');
            $this->database->exec('COMMIT');
        } catch(Throwable $e){try{$this->database->exec('ROLLBACK');}catch(Throwable){}throw $e;}
        $row['cancelled_by_name']=$userId===(int)$row['requester_user_id']?$row['requester_full_name']:$row['representative_full_name'];
        $this->sendPair($row,'cancelled');
        return $this->publicAssignment($row,$userId)+['status'=>'cancelled'];
    }

    private function issue(string $direction, ?int $requestId, ?int $offerId, int $logId, string $date, int $requesterId, int $representativeId): array
    {
        $raw=self::base64Url(random_bytes(32));$hash=hash('sha256',$raw);$now=gmdate('Y-m-d\TH:i:s\Z');
        $statement=$this->database->prepare('INSERT INTO representation_acceptance_tokens(token_hash,direction,request_id,offer_id,contact_log_id,representation_date,requester_user_id,representative_user_id,created_at) VALUES(:hash,:direction,:request,:offer,:log,:date,:requester,:representative,:created)');
        $statement->execute([':hash'=>$hash,':direction'=>$direction,':request'=>$requestId,':offer'=>$offerId,':log'=>$logId,':date'=>$date,':requester'=>$requesterId,':representative'=>$representativeId,':created'=>$now]);
        $base=rtrim((string)$this->settings->settings()['baseUrl'],'/');
        return ['token'=>$raw,'link'=>$base.'/?accept_representation='.rawurlencode($raw)];
    }

    public function invalidate(string $rawToken): void
    {
        $statement=$this->database->prepare('UPDATE representation_acceptance_tokens SET invalidated_at=:now WHERE token_hash=:hash AND used_at IS NULL');
        $statement->execute([':now'=>gmdate('Y-m-d\TH:i:s\Z'),':hash'=>hash('sha256',$rawToken)]);
    }

    /** @return array<string,mixed>|null */
    private function tokenRow(string $rawToken,bool $lock):?array
    {
        if(strlen($rawToken)<40||strlen($rawToken)>100)return null;
        $sql='SELECT * FROM representation_acceptance_tokens WHERE token_hash=:hash'.($lock&&DatabaseDialect::isMysql($this->database)?' FOR UPDATE':'');
        $statement=$this->database->prepare($sql);$statement->execute([':hash'=>hash('sha256',$rawToken)]);$row=$statement->fetch();return is_array($row)?$row:null;
    }

    /** @param array<string,mixed> $token */
    private function underlyingAvailable(array $token):bool
    {
        $slot=$this->database->prepare("SELECT COUNT(*) FROM representation_assignments WHERE requester_user_id=:requester AND chapter_org_id=(SELECT org_id FROM ".($token['direction']==='request_contact'?'representation_requests WHERE id=:source':'representation_offers WHERE id=:source').") AND representation_date=:date AND status='active'");
        $slot->execute([':requester'=>$token['requester_user_id'],':source'=>$token['direction']==='request_contact'?$token['request_id']:$token['offer_id'],':date'=>$token['representation_date']]);
        if((int)$slot->fetchColumn()>0)return false;
        if($token['direction']==='request_contact'){$s=$this->database->prepare('SELECT COUNT(*) FROM representation_requests WHERE id=:id AND user_id=:user AND request_date=:date');$s->execute([':id'=>$token['request_id'],':user'=>$token['requester_user_id'],':date'=>$token['representation_date']]);return(int)$s->fetchColumn()===1;}
        $s=$this->database->prepare('SELECT COUNT(*) FROM representation_offers o WHERE o.id=:id AND o.user_id=:user AND (o.all_dates=1 OR EXISTS(SELECT 1 FROM representation_offer_dates d WHERE d.offer_id=o.id AND d.offer_date=:date))');$s->execute([':id'=>$token['offer_id'],':user'=>$token['representative_user_id'],':date'=>$token['representation_date']]);return(int)$s->fetchColumn()===1;
    }

    /** @param array<string,mixed> $token @return array<string,mixed>|null */
    private function context(array $token):?array
    {
        $source=$token['direction']==='request_contact'?'representation_requests':'representation_offers';$sourceId=$token['direction']==='request_contact'?$token['request_id']:$token['offer_id'];
        $sql="SELECT s.org_id chapter_org_id,o.chapter_name chapter,u1.first_name requester_first_name,u1.last_name requester_last_name,u1.email requester_email,u1.bni_verification_status requester_verification_status,u2.first_name representative_first_name,u2.last_name representative_last_name,u2.email representative_email,u2.bni_verification_status representative_verification_status FROM $source s JOIN organizations o ON o.org_id=s.org_id JOIN users u1 ON u1.id=:requester JOIN users u2 ON u2.id=:representative WHERE s.id=:source";
        $st=$this->database->prepare($sql);$st->execute([':requester'=>$token['requester_user_id'],':representative'=>$token['representative_user_id'],':source'=>$sourceId]);$r=$st->fetch();if(!is_array($r))return null;
        return $r+['representationDate'=>(string)$token['representation_date'],'requesterName'=>trim($r['requester_first_name'].' '.$r['requester_last_name']),'representativeName'=>trim($r['representative_first_name'].' '.$r['representative_last_name'])];
    }

    private function assignmentSelect():string{return "SELECT a.*,o.chapter_name chapter,r.first_name requester_first_name,r.last_name requester_last_name,r.email requester_email,r.bni_verification_status requester_verification_status,p.first_name representative_first_name,p.last_name representative_last_name,p.email representative_email,p.bni_verification_status representative_verification_status FROM representation_assignments a JOIN organizations o ON o.org_id=a.chapter_org_id JOIN users r ON r.id=a.requester_user_id JOIN users p ON p.id=a.representative_user_id";}
    /** @return array<string,mixed>|null */ private function assignmentContext(int $id):?array{$s=$this->database->prepare($this->assignmentSelect().' WHERE a.id=:id');$s->execute([':id'=>$id]);$r=$s->fetch();return is_array($r)?$this->withFullNames($r):null;}
    /** @param array<string,mixed> $row @return array<string,mixed> */ private function withFullNames(array $row):array{$row['requester_full_name']=trim((string)$row['requester_first_name'].' '.(string)$row['requester_last_name']);$row['representative_full_name']=trim((string)$row['representative_first_name'].' '.(string)$row['representative_last_name']);return$row;}
    /** @param array<string,mixed> $r @return array<string,mixed> */
    private function publicAssignment(array $r,int $userId):array{$requester=$userId===(int)$r['requester_user_id'];return['id'=>(int)$r['id'],'role'=>$requester?'requester':'representative','date'=>(string)$r['representation_date'],'chapter'=>(string)$r['chapter'],'counterpartName'=>(string)($requester?$r['representative_full_name']:$r['requester_full_name']),'counterpartEmail'=>(string)($requester?$r['representative_email']:$r['requester_email']),'counterpartVerificationStatus'=>(string)($requester?$r['representative_verification_status']:$r['requester_verification_status']),'status'=>(string)$r['status'],'acceptedAt'=>(string)$r['accepted_at']];}
    /** @param array<string,mixed> $r */
    private function sendPair(array $r,string $event):void
    {
        $vars=['requester_first_name'=>(string)$r['requester_first_name'],'requester_full_name'=>(string)$r['requester_full_name'],'requester_email'=>(string)$r['requester_email'],'representative_first_name'=>(string)$r['representative_first_name'],'representative_full_name'=>(string)$r['representative_full_name'],'representative_email'=>(string)$r['representative_email'],'chapter'=>(string)$r['chapter'],'requested_date'=>self::formatDate((string)$r['representation_date']),'cancelled_by'=>(string)($r['cancelled_by_name']??''),'app_name'=>'CrossChAPP','base_url'=>(string)$this->settings->settings()['baseUrl']];
        foreach(['requester','representative'] as $role){$key="representation_assignment_{$event}_{$role}";$mail=$this->settings->render($key,$vars);try{$this->mailer->send((string)$vars[$role.'_email'],(string)$vars[$role.'_full_name'],$mail['subject'],$mail['body']);}catch(Throwable){} }
    }
    private static function formatDate(string $date):string{$d=DateTimeImmutable::createFromFormat('!Y-m-d',$date,new DateTimeZone('Europe/Berlin'));return$d?$d->format('d.m.Y'):$date;}
    private static function base64Url(string $value):string{return rtrim(strtr(base64_encode($value),'+/','-_'),'=');}
}
