<?php

declare(strict_types=1);

final class UserKeywordRepository
{
    public const MAX_KEYWORDS=10;
    public const MAX_LENGTH=40;

    public function __construct(private readonly PDO $db) {}

    /** @return list<array{id:int,keyword:string}> */
    public function forUser(int $userId):array
    {
        $statement=$this->db->prepare('SELECT id,keyword FROM user_keywords WHERE user_id=:user ORDER BY id');$statement->execute([':user'=>$userId]);
        return array_map(static fn(array$row):array=>['id'=>(int)$row['id'],'keyword'=>(string)$row['keyword']],$statement->fetchAll());
    }

    /** @return array{id:int,keyword:string} */
    public function add(int $userId,string $keyword):array
    {
        $keyword=trim((string)preg_replace('/\s+/u',' ',$keyword));if($keyword==='')throw new InvalidArgumentException('Bitte gib ein Schlagwort ein.');if(mb_strlen($keyword)>self::MAX_LENGTH)throw new InvalidArgumentException('Ein Schlagwort darf maximal 40 Zeichen lang sein.');
        $normalized=mb_strtolower($keyword,'UTF-8');$driver=$this->db->getAttribute(PDO::ATTR_DRIVER_NAME);if($driver==='sqlite')$this->db->exec('BEGIN IMMEDIATE');else$this->db->beginTransaction();
        try{$lock=$this->db->prepare('SELECT id FROM users WHERE id=:user'.($driver==='mysql'?' FOR UPDATE':''));$lock->execute([':user'=>$userId]);if($lock->fetchColumn()===false)throw new DomainException('Das Benutzerkonto wurde nicht gefunden.');$existing=$this->db->prepare('SELECT id,keyword FROM user_keywords WHERE user_id=:user AND normalized_keyword=:normalized');$existing->execute([':user'=>$userId,':normalized'=>$normalized]);$row=$existing->fetch();if(is_array($row)){$this->commit($driver);return['id'=>(int)$row['id'],'keyword'=>(string)$row['keyword']];}$count=$this->db->prepare('SELECT COUNT(*) FROM user_keywords WHERE user_id=:user');$count->execute([':user'=>$userId]);if((int)$count->fetchColumn()>=self::MAX_KEYWORDS)throw new DomainException('Maximal 10 Schlagwörter.');$insert=$this->db->prepare('INSERT INTO user_keywords(user_id,keyword,normalized_keyword,created_at)VALUES(:user,:keyword,:normalized,:created)');$insert->execute([':user'=>$userId,':keyword'=>$keyword,':normalized'=>$normalized,':created'=>gmdate('Y-m-d\TH:i:s\Z')]);$id=(int)$this->db->lastInsertId();$this->commit($driver);return['id'=>$id,'keyword'=>$keyword];}catch(Throwable$exception){$this->rollback($driver);throw$exception;}
    }

    public function delete(int $userId,int $keywordId):bool
    {
        $statement=$this->db->prepare('DELETE FROM user_keywords WHERE id=:id AND user_id=:user');$statement->execute([':id'=>$keywordId,':user'=>$userId]);return$statement->rowCount()===1;
    }

    private function commit(string$driver):void{if($driver==='sqlite')$this->db->exec('COMMIT');else$this->db->commit();}
    private function rollback(string$driver):void{try{if($driver==='sqlite')$this->db->exec('ROLLBACK');elseif($this->db->inTransaction())$this->db->rollBack();}catch(Throwable){}}
}
