<?php

declare(strict_types=1);
require_once __DIR__.'/PushConfig.php';
require_once __DIR__.'/PushSubscriptionRepository.php';

final class PushTransport
{
    private mixed $client = null;
    public function __construct(private readonly ?Closure $fake = null) {}
    public function ready(): bool { return $this->fake !== null || PushConfig::ready(); }

    /** Returns only a status code; never exposes endpoints, keys or provider error messages. */
    public function send(array $subscription,array $payload): int
    {
        if ($this->fake !== null) return (int)($this->fake)($subscription,$payload);
        if (getenv('CROSSCHAPP_TEST_MODE')==='1' || getenv('CROSSCHAPP_DISABLE_EXTERNAL_HTTP')==='1') throw new RuntimeException('Externer Push-Versand ist in dieser Umgebung deaktiviert.');
        if (!PushConfig::ready()) throw new RuntimeException('Web Push ist nicht konfiguriert.');
        PushSubscriptionRepository::endpoint($subscription['endpoint']);
        require_once dirname(__DIR__,2).'/vendor/autoload.php';
        $this->client ??= new Minishlink\WebPush\WebPush(['VAPID'=>[
            'subject'=>(string)getenv('CROSSCHAPP_VAPID_SUBJECT'), 'publicKey'=>PushConfig::publicKey(),
            'privateKey'=>(string)getenv('CROSSCHAPP_VAPID_PRIVATE_KEY')]], ['TTL'=>300], 10,
            ['allow_redirects'=>false,'connect_timeout'=>5],new Psr\Log\NullLogger());
        $report=$this->client->sendOneNotification(Minishlink\WebPush\Subscription::create([
            'endpoint'=>$subscription['endpoint'],'keys'=>['p256dh'=>$subscription['p256dh'],'auth'=>$subscription['auth']],
            'contentEncoding'=>'aes128gcm']),json_encode($payload,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
        return $report->isSuccess()?201:($report->getResponse()?->getStatusCode()??0);
    }
}
