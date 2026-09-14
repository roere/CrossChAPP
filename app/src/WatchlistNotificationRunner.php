<?php

declare(strict_types=1);

require_once __DIR__.'/AutomationConfig.php';
require_once __DIR__.'/MailService.php';
require_once __DIR__.'/MailSettingsRepository.php';
require_once __DIR__.'/WatchlistRepository.php';

final class WatchlistNotificationRunner
{
    public function __construct(private readonly PDO $database,private readonly ?MailService $mail=null){}

    /** @return array{ran:bool,sent:int,failed:int} */
    public function run(?DateTimeImmutable $now=null):array
    {
        $now??=new DateTimeImmutable('now',new DateTimeZone('UTC'));$runtime=$this->database->query('SELECT last_watchlist_notification_check_at FROM automation_runtime WHERE id=1')->fetchColumn();
        if(is_string($runtime)&&$runtime!==''&&$now->getTimestamp()-(new DateTimeImmutable($runtime))->getTimestamp()<AutomationConfig::watchlistNotificationIntervalSeconds())return['ran'=>false,'sent'=>0,'failed'=>0];
        $settings=new MailSettingsRepository($this->database);$mail=$this->mail??new MailService($settings);$repository=new WatchlistRepository($this->database);$base=rtrim((string)$settings->settings()['baseUrl'],'/');$sent=0;$failed=0;
        foreach($repository->notificationCandidates($now)as$candidate){try{$chapter=trim((string)$candidate['chapter_name'].($candidate['city']?' ('.(string)$candidate['city'].')':''));$link=$base.'/'.(string)$candidate['short_link_slug'];$template=$settings->render('watchlist_representation_request',['chapter'=>$chapter,'requested_date'=>self::date((string)$candidate['request_date']),'chapter_link'=>$link,'app_name'=>'CrossChAPP']);$mail->send((string)$candidate['email'],trim((string)$candidate['first_name'].' '.(string)$candidate['last_name']),$template['subject'],$template['body']);$repository->markSent((int)$candidate['user_id'],(int)$candidate['request_id'],$now->format('Y-m-d\TH:i:s\Z'));$sent++;}catch(Throwable){$failed++;}}
        $statement=$this->database->prepare('UPDATE automation_runtime SET last_watchlist_notification_check_at=:checked,updated_at=:updated WHERE id=1');$checked=$now->format('Y-m-d\TH:i:s\Z');$statement->execute([':checked'=>$checked,':updated'=>$checked]);return['ran'=>true,'sent'=>$sent,'failed'=>$failed];
    }

    private static function date(string$date):string{$value=DateTimeImmutable::createFromFormat('!Y-m-d',$date,new DateTimeZone('Europe/Berlin'));return$value?$value->format('d.m.Y'):$date;}
}
