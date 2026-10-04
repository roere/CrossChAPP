<?php

declare(strict_types=1);

final class PushSubscriptionRepository
{
    public function __construct(private readonly PDO $db) {}

    public static function endpoint(mixed $value): string
    {
        if (!is_string($value) || strlen($value)>2048) throw new InvalidArgumentException('Ungültige Push-Adresse.');
        $url = parse_url($value);
        if (!$url || ($url['scheme']??'')!=='https' || empty($url['host']) || isset($url['user']) || isset($url['pass']) || isset($url['fragment']) || (isset($url['port']) && $url['port']!==443)) throw new InvalidArgumentException('Ungültige Push-Adresse.');
        // Restrict network destinations to known browser push services. Do not resolve arbitrary user URLs.
        $host = strtolower($url['host']);
        $allowed = in_array($host, ['fcm.googleapis.com','updates.push.services.mozilla.com','updates-autopush.stage.mozaws.net','web.push.apple.com'], true)
            || str_ends_with($host,'.notify.windows.com') || str_ends_with($host,'.push.apple.com');
        if (!$allowed) throw new InvalidArgumentException('Dieser Push-Dienst wird nicht unterstützt.');
        return $value;
    }

    private static function key(mixed $value, int $length): string
    {
        if (!is_string($value) || !preg_match('/^[A-Za-z0-9_-]+={0,2}$/D',$value)) throw new InvalidArgumentException('Ungültige Push-Schlüssel.');
        $decoded = base64_decode(strtr($value,'-_','+/'),true);
        if ($decoded===false || strlen($decoded)!==$length || ($length===65 && ord($decoded[0])!==4)) throw new InvalidArgumentException('Ungültige Push-Schlüssel.');
        return rtrim($value,'=');
    }

    public function save(int $userId, array $subscription, ?string $now=null): void
    {
        $endpoint = self::endpoint($subscription['endpoint']??null);
        $public = self::key($subscription['keys']['p256dh']??null,65);
        $auth = self::key($subscription['keys']['auth']??null,16);
        $now ??= gmdate('Y-m-d\TH:i:s\Z');
        $hash = hash('sha256',$endpoint);
        $existing=$this->db->prepare('SELECT id,user_id,p256dh,auth FROM push_subscriptions WHERE endpoint_hash=?');$existing->execute([$hash]);$row=$existing->fetch();
        if ($row) {
            if ((int)$row['user_id']!==$userId) throw new InvalidArgumentException('Dieses Gerät konnte nicht zugeordnet werden. Bitte melde Dich im Browser mit dem zugehörigen Konto an.');
            // Idempotent saves preserve the activation cutoff. Changed keys represent a new activation.
            $changed = $row['p256dh']!==$public || $row['auth']!==$auth;
            $stmt=$this->db->prepare('UPDATE push_subscriptions SET p256dh=?,auth=?,updated_at=?,enabled_at=CASE WHEN ?=1 THEN ? ELSE enabled_at END WHERE id=?');
            $stmt->execute([$public,$auth,$now,$changed?1:0,$now,$row['id']]);
            if ($changed) {$stmt=$this->db->prepare('DELETE FROM push_deliveries WHERE subscription_id=?');$stmt->execute([$row['id']]);}
            return;
        }
        $stmt=$this->db->prepare('INSERT INTO push_subscriptions(user_id,endpoint,endpoint_hash,p256dh,auth,enabled_at,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?)');
        $stmt->execute([$userId,$endpoint,$hash,$public,$auth,$now,$now,$now]);
    }
    public function contains(int $userId,string $endpoint): bool
    {
        $stmt=$this->db->prepare('SELECT 1 FROM push_subscriptions WHERE user_id=? AND endpoint_hash=?');$stmt->execute([$userId,hash('sha256',$endpoint)]);return $stmt->fetchColumn()!==false;
    }
    public function remove(int $userId,string $endpoint): void
    {
        $stmt=$this->db->prepare('DELETE FROM push_subscriptions WHERE user_id=? AND endpoint_hash=?');$stmt->execute([$userId,hash('sha256',$endpoint)]);
    }
}
