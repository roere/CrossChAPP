<?php

declare(strict_types=1);

require_once __DIR__ . '/BniMemberListClient.php';
require_once __DIR__ . '/BniMemberDirectoryConfigResolver.php';

final class BniMemberDirectoryService
{
    private readonly BniMemberListClient $client;
    private readonly BniMemberDirectoryConfigResolver $resolver;
    public function __construct(private readonly PDO $database, ?Closure $transport = null, ?Closure $delay = null, ?Closure $resolverTransport = null) { $this->client=new BniMemberListClient($database,$transport,$delay);$this->resolver=new BniMemberDirectoryConfigResolver($database,$resolverTransport,$delay); }

    /** @return array{status:string,externalRef:?string,matches?:list<array{externalRef:?string,profileUrl:?string}>} */
    public function match(string $firstName, string $lastName, int $orgId): array
    {
        if ($this->chapter($orgId) === null) return ['status' => 'unavailable', 'externalRef' => null];
        $resolved=$this->resolver->resolve($orgId);if($resolved['status']!=='configured')return['status'=>$resolved['status'],'externalRef'=>null];
        $owner = bin2hex(random_bytes(16));
        if (!$this->acquire($owner)) throw new RuntimeException('Die BNI-Mitgliederprüfung läuft bereits. Bitte versuche es später erneut.');
        try {
            $response=$this->client->fetch($orgId);
            if($response['status']!=='ok')return['status'=>$response['status'],'externalRef'=>null];
            return $this->exactMatches($response['body'], $firstName, $lastName, $orgId);
        } finally { $this->release($owner); }
    }

    /** @return array<string,mixed>|null */
    private function chapter(int $orgId): ?array
    {
        $statement = $this->database->prepare("SELECT org_id FROM organizations WHERE org_id=:id AND org_type='CHAPTER'");
        $statement->execute([':id' => $orgId]); $row = $statement->fetch(); return is_array($row) ? $row : null;
    }

    /** @return array{status:string,externalRef:?string,matches?:list<array{externalRef:?string,profileUrl:?string}>} */
    private function exactMatches(string $html, string $firstName, string $lastName, int $orgId): array
    {
        $statement=$this->database->prepare('SELECT referer FROM bni_member_directory_configs WHERE org_id=:org');$statement->execute([':org'=>$orgId]);$config=$statement->fetch();
        $referer=is_array($config)?(string)($config['referer']??''):'';
        libxml_use_internal_errors(true); $dom = new DOMDocument(); $dom->loadHTML('<?xml encoding="UTF-8">' . $html); $xpath = new DOMXPath($dom); $matches = [];
        foreach ($xpath->query('//a[contains(@href,"memberdetails")]') ?: [] as $link) {
            $href = html_entity_decode($link->getAttribute('href'), ENT_QUOTES | ENT_HTML5, 'UTF-8'); parse_str((string) parse_url($href, PHP_URL_QUERY), $query);
            $name = is_string($query['name'] ?? null) ? (string) $query['name'] : trim($link->textContent);
            if (!$this->sameName($name, $firstName, $lastName)) continue;
            $externalRef=is_string($query['encryptedMemberId']??null)?trim((string)$query['encryptedMemberId']):'';$profileUrl=$this->profileUrl($href,$referer);
            $matches[]=['externalRef'=>$externalRef!==''?$externalRef:null,'profileUrl'=>$externalRef!==''?$profileUrl:null];
        }
        if (count($matches) === 1) return ['status' => 'match', 'externalRef' => $matches[0]['externalRef']];
        return ['status' => count($matches) > 1 ? 'ambiguous' : 'not_found', 'externalRef' => null, 'matches'=>$matches];
    }

    private function profileUrl(string $href,string $referer):?string
    {
        $base=parse_url($referer);if(!is_array($base))return null;$host=strtolower(rtrim((string)($base['host']??''),'.'));
        if(!preg_match('/(^|\.)bni[^.]*\.(de|at|com|hamburg)$/',$host)||strtolower((string)($base['scheme']??''))!=='https')return null;
        $target=parse_url($href);if($target===false||isset($target['user'])||isset($target['pass'])||isset($target['fragment']))return null;
        if(isset($target['scheme'])||isset($target['host'])){$scheme=strtolower((string)($target['scheme']??''));$targetHost=strtolower(rtrim((string)($target['host']??''),'.'));if($scheme!=='https'||$targetHost!==$host)return null;$path=(string)($target['path']??'');}
        else{$path=(string)($target['path']??'');if(str_starts_with($path,'/')){}else{$basePath=(string)($base['path']??'/');$path=rtrim(str_replace('\\','/',dirname($basePath)),'/').'/'.$path;}}
        if($path===''||str_contains($path,'..')||!str_contains(strtolower($path),'memberdetails'))return null;
        return'https://'.$host.$path.(isset($target['query'])?'?'.$target['query']:'');
    }

    private function sameName(string $candidate, string $first, string $last): bool
    {
        $normalize = static function (string $value): string { $value = trim((string) preg_replace('/\s+/u', ' ', $value)); return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value); };
        return $normalize($candidate) === $normalize(trim($first) . ' ' . trim($last));
    }

    private function acquire(string $owner): bool { $now=gmdate('Y-m-d\TH:i:s\Z');$this->database->prepare("DELETE FROM bni_member_check_lock WHERE lock_until<=:now")->execute([':now'=>$now]); try { $s=$this->database->prepare("INSERT INTO bni_member_check_lock(id,owner_token,lock_until) VALUES(1,:owner,:until)"); $s->execute([':owner'=>$owner,':until'=>gmdate('Y-m-d\TH:i:s\Z',time()+45)]); return true; } catch (PDOException) { return false; } }
    private function release(string $owner): void { $s=$this->database->prepare('DELETE FROM bni_member_check_lock WHERE id=1 AND owner_token=:owner'); $s->execute([':owner'=>$owner]); }
}
