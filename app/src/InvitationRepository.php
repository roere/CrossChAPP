<?php

declare(strict_types=1);

final class InvitationRepository
{
    public function __construct(private readonly PDO $database) {}

    /** @return array<string,mixed> */
    public function create(string $first, string $last, string $email, int $chapterId, int $adminId): array
    {
        $this->expire();
        $existing=$this->database->prepare("SELECT 1 FROM users WHERE email=:email COLLATE NOCASE UNION SELECT 1 FROM user_invitations WHERE email=:email COLLATE NOCASE AND status='pending' AND datetime(expires_at)>datetime('now') LIMIT 1");
        $existing->execute([':email'=>$email]); if ($existing->fetchColumn()) throw new DomainException('Für diese E-Mail-Adresse besteht bereits ein Konto oder eine offene Einladung.');
        $token=rtrim(strtr(base64_encode(random_bytes(32)),'+/','-_'),'='); $now=self::now();
        $statement=$this->database->prepare("INSERT INTO user_invitations(first_name,last_name,email,home_chapter_org_id,token_hash,expires_at,created_at,created_by_user_id,status) VALUES(:first,:last,:email,:chapter,:hash,:expires,:created,:admin,'pending')");
        $statement->execute([':first'=>$first,':last'=>$last,':email'=>strtolower($email),':chapter'=>$chapterId,':hash'=>hash('sha256',$token),':expires'=>gmdate('Y-m-d\TH:i:s\Z',time()+604800),':created'=>$now,':admin'=>$adminId]);
        return ['id'=>(int)$this->database->lastInsertId(),'token'=>$token,'expiresAt'=>gmdate('Y-m-d\TH:i:s\Z',time()+604800)];
    }

    public function markSent(int $id): void { $s=$this->database->prepare('UPDATE user_invitations SET sent_at=:now WHERE id=:id');$s->execute([':now'=>self::now(),':id'=>$id]); }
    public function cancelUnsent(int $id): void { $s=$this->database->prepare("UPDATE user_invitations SET status='cancelled' WHERE id=:id AND status='pending' AND sent_at IS NULL");$s->execute([':id'=>$id]); }
    /** @return list<array<string,mixed>> */
    public function open(): array { $this->expire(); return $this->database->query("SELECT i.id,i.first_name AS firstName,i.last_name AS lastName,i.email,o.chapter_name AS chapterName,i.sent_at AS sentAt,i.expires_at AS expiresAt,i.status FROM user_invitations i JOIN organizations o ON o.org_id=i.home_chapter_org_id WHERE i.status='pending' ORDER BY i.created_at DESC")->fetchAll(); }
    public function cancel(int $id): bool { $s=$this->database->prepare("UPDATE user_invitations SET status='cancelled' WHERE id=:id AND status='pending'");$s->execute([':id'=>$id]);return $s->rowCount()===1; }

    /** @return array<string,mixed>|null */
    public function byToken(string $token): ?array
    {
        if (!preg_match('/^[A-Za-z0-9_-]{40,100}$/',$token)) return null;
        $s=$this->database->prepare('SELECT i.*,o.chapter_name FROM user_invitations i JOIN organizations o ON o.org_id=i.home_chapter_org_id WHERE i.token_hash=:hash');$s->execute([':hash'=>hash('sha256',$token)]);$row=$s->fetch();return is_array($row)?$row:null;
    }

    /** @return array{status:string,userId?:int} */
    public function accept(string $token,string $passwordHash): array
    {
        $row=$this->byToken($token); if ($row===null)return ['status'=>'invalid']; if($row['status']==='accepted')return ['status'=>'used']; if($row['status']!=='pending')return ['status'=>$row['status']==='expired'?'expired':'invalid']; if(strtotime((string)$row['expires_at'])<=time()){ $this->expire();return ['status'=>'expired']; }
        if ($this->emailExists((string)$row['email'])) return ['status'=>'email_exists']; $now=self::now(); $this->database->beginTransaction();
        try{$insert=$this->database->prepare("INSERT INTO users(first_name,last_name,email,password_hash,home_chapter_org_id,role,status,email_verified_at,bni_verification_status,bni_verified_at,bni_verified_by_user_id,created_at,updated_at) VALUES(:first,:last,:email,:hash,:chapter,'user','active',:now,'manual_verified',:now,:admin,:now,:now)");
            $insert->execute([':first'=>$row['first_name'],':last'=>$row['last_name'],':email'=>$row['email'],':hash'=>$passwordHash,':chapter'=>$row['home_chapter_org_id'],':now'=>$now,':admin'=>$row['created_by_user_id']]);$userId=(int)$this->database->lastInsertId();
            $update=$this->database->prepare("UPDATE user_invitations SET status='accepted',accepted_at=:now WHERE id=:id AND status='pending'");$update->execute([':now'=>$now,':id'=>$row['id']]);if($update->rowCount()!==1)throw new RuntimeException('Einladung wurde parallel verwendet.');$this->database->commit();return ['status'=>'accepted','userId'=>$userId];
        }catch(Throwable $e){if($this->database->inTransaction())$this->database->rollBack();throw $e;}
    }
    private function emailExists(string $email):bool{$s=$this->database->prepare('SELECT 1 FROM users WHERE email=:email COLLATE NOCASE');$s->execute([':email'=>$email]);return(bool)$s->fetchColumn();}
    private function expire():void{$this->database->exec("UPDATE user_invitations SET status='expired' WHERE status='pending' AND datetime(expires_at)<=datetime('now')");}
    private static function now():string{return gmdate('Y-m-d\TH:i:s\Z');}
}
