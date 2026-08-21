<?php

declare(strict_types=1);
require_once __DIR__ . '/DatabaseDialect.php';

final class RepresentationRequestContactService
{
    public function __construct(private readonly PDO $database, private readonly RepresentationRequestRepository $requests, private readonly MailSettingsRepository $settings, private readonly MailService $mailer, private readonly ?RepresentationAssignmentService $assignments = null) {}

    /** @return array{subject:string,before:string,after:string,customMessage:string,hint:string} */
    public function preview(int $contactUserId, int $requestId, string $customMessage = ''): array
    {
        [, $mail] = $this->compose($contactUserId, $requestId, $customMessage === '' ? $this->settings->requestCustomMessage() : $customMessage); return $mail + ['hint' => $this->settings->requestContactHint()];
    }

    public function send(int $contactUserId, int $requestId, string $customMessage): void
    {
        [$context, $mail] = $this->compose($contactUserId, $requestId, trim($customMessage)); $logId = $this->reserve($contactUserId, $requestId, (int) $context['recipient_id']);
        $name = trim($context['contact_first_name'] . ' ' . $context['contact_last_name']);
        $acceptance=$this->assignments?->issueForRequest($context,$logId);
        try {
            if($acceptance!==null){$rendered=$this->settings->render('request_contact_acceptance',['requester_first_name'=>(string)$context['owner_first_name'],'representative_full_name'=>$name,'representative_email'=>(string)$context['contact_email'],'chapter'=>(string)$context['requested_chapter'],'requested_date'=>self::formatDate(DateTimeImmutable::createFromFormat('!Y-m-d',(string)$context['request_date'])?:new DateTimeImmutable()),'acceptance_link'=>$acceptance['link'],'custom_message'=>$mail['customMessage'],'app_name'=>'CrossChAPP','base_url'=>(string)$this->settings->settings()['baseUrl']]);$mail=['subject'=>$rendered['subject'],'before'=>$rendered['body'],'customMessage'=>'','after'=>''];}
            $this->mailer->send((string) $context['owner_email'], trim($context['owner_first_name'] . ' ' . $context['owner_last_name']), $mail['subject'], $mail['before'] . $mail['customMessage'] . $mail['after']); $this->finish($logId, 'success'); }
        catch (Throwable) {if($acceptance!==null)$this->assignments?->invalidate($acceptance['token']); $this->finish($logId, 'error'); throw new RuntimeException('Die Rückmeldung konnte nicht gesendet werden.'); }
    }

    /** @return array{subject:string,before:string,after:string,customMessage:string,hint:string} */
    public function previewAnonymous(int $requestId, string $firstName, string $lastName, string $email, string $customMessage = ''): array
    {
        $context = $this->anonymousContext($requestId, $firstName, $lastName, $email, false);
        $mail = $this->composeContext($context, $customMessage === '' ? $this->settings->requestCustomMessage() : $customMessage, true);
        return $mail + ['hint' => $this->settings->requestContactHint()];
    }

    public function sendAnonymous(int $requestId, string $firstName, string $lastName, string $email, string $customMessage, string $ip): void
    {
        $context = $this->anonymousContext($requestId, $firstName, $lastName, $email, true);
        $mail = $this->composeContext($context, trim($customMessage), true);
        $logId = $this->reserveAnonymous($requestId, (int) $context['recipient_id'], (string) $context['contact_email'], $ip);
        $name = trim((string) $context['contact_first_name'].' '.(string) $context['contact_last_name']);
        try { $this->mailer->send((string) $context['owner_email'], trim((string) $context['owner_first_name'].' '.(string) $context['owner_last_name']), $mail['subject'], $mail['before'].$mail['customMessage'].$mail['after'], (string) $context['contact_email'], $name); $this->finishAnonymous($logId, 'success'); }
        catch (Throwable) { $this->finishAnonymous($logId, 'error'); throw new RuntimeException('Die Rückmeldung konnte nicht gesendet werden.'); }
    }

    /** @return array{0:array<string,mixed>,1:array{subject:string,before:string,after:string,customMessage:string}} */
    private function compose(int $contactUserId, int $requestId, string $customMessage): array
    {
        $customMessage = trim($customMessage); if (strlen($customMessage) < 20 || strlen($customMessage) > 3000) throw new InvalidArgumentException('Die Rückmeldung ist unvollständig oder ungültig.');
        $context = $this->requests->contactContext($requestId, $contactUserId); if ($context === null) throw new DomainException('Dieses Vertretungsgesuch ist nicht verfügbar.');
        $assigned=$this->database->prepare("SELECT COUNT(*) FROM representation_assignments WHERE request_id=:request AND status='active'");$assigned->execute([':request'=>$requestId]);if((int)$assigned->fetchColumn()>0)throw new DomainException('Dieses Vertretungsgesuch ist bereits vergeben.');
        return [$context, $this->composeContext($context, $customMessage, false)];
    }

    /** @param array<string,mixed> $context @return array{subject:string,before:string,after:string,customMessage:string} */
    private function composeContext(array $context, string $customMessage, bool $anonymous): array
    {
        $customMessage = trim($customMessage); if (strlen($customMessage) < 20 || strlen($customMessage) > 3000) throw new InvalidArgumentException('Die Rückmeldung ist unvollständig oder ungültig.');
        try { $zone = new DateTimeZone((string) ($context['timezone'] ?: 'Europe/Berlin')); } catch (Throwable) { $zone = new DateTimeZone('Europe/Berlin'); }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', (string) $context['request_date'], $zone); if (!$date || $date < new DateTimeImmutable('today', $zone)) throw new DomainException('Dieses Vertretungsgesuch ist abgelaufen.');
        $fullName = trim($context['contact_first_name'] . ' ' . $context['contact_last_name']); $formattedDate = self::formatDate($date); $contactChapter = (string) ($context['contact_chapter'] ?? '');
        $variables = ['request_owner_first_name' => $anonymous ? '' : (string) $context['owner_first_name'], 'contact_first_name' => (string) $context['contact_first_name'], 'contact_last_name' => (string) $context['contact_last_name'], 'contact_full_name' => $fullName,
            'contact_email' => (string) $context['contact_email'], 'contact_chapter' => $contactChapter, 'requested_chapter' => (string) $context['requested_chapter'], 'requested_date' => $formattedDate, 'custom_message' => '{{custom_message}}', 'app_name' => 'CrossChAPP'];
        $template = $this->settings->templates()['representation_request_contact'] ?? throw new RuntimeException('E-Mail-Vorlage fehlt.'); $rendered = $this->settings->render('representation_request_contact', $variables); $body = $rendered['body'];
        if ($anonymous && $contactChapter === '') $body = (string) preg_replace('/^\s*BNI-Chapter:\s*$(?:\R)?/mi', '', $body);
        $requiredFields = $anonymous ? ['contact_full_name','contact_email','requested_chapter','requested_date'] : ['contact_full_name','contact_email','contact_chapter','requested_chapter','requested_date'];
        foreach ($requiredFields as $required) if (!str_contains($template['body'], '{{' . $required . '}}')) { $chapterLine = $contactChapter !== '' ? "\nBNI-Chapter: {$contactChapter}" : ''; $body .= "\n\n---\nRückmeldung von:\n{$fullName}\n{$context['contact_email']}{$chapterLine}\nVertretung für: {$context['requested_chapter']}\nTermin: {$formattedDate}"; break; }
        [$before,$after] = array_pad(explode('{{custom_message}}', $body, 2), 2, '');
        $before = self::removeRawPlaceholders($before); $after = self::removeRawPlaceholders($after);
        return ['subject' => $rendered['subject'], 'before' => $before, 'after' => $after, 'customMessage' => $customMessage];
    }

    /** @return array<string,mixed> */
    private function anonymousContext(int $requestId, string $firstName, string $lastName, string $email, bool $strict): array
    {
        $firstName = trim($firstName); $lastName = trim($lastName); $email = trim($email);
        if ($strict && ($firstName === '' || $lastName === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false)) throw new InvalidArgumentException('Bitte gib vollständige und gültige Kontaktdaten an.');
        foreach ([$firstName, $lastName] as $name) if (strlen($name) > 100 || preg_match('/[\r\n]/', $name)) throw new InvalidArgumentException('Die Kontaktdaten sind ungültig.');
        if (strlen($email) > 254 || preg_match('/[\r\n]/', $email) || ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false)) throw new InvalidArgumentException('Bitte gib eine gültige E-Mail-Adresse ein.');
        $context = $this->requests->anonymousContactContext($requestId); if ($context === null) throw new DomainException('Dieses Vertretungsgesuch ist nicht verfügbar.');
        return $context + ['contact_first_name'=>$firstName,'contact_last_name'=>$lastName,'contact_email'=>$email,'contact_chapter'=>''];
    }

    private function reserveAnonymous(int $requestId, int $recipientId, string $email, string $ip): int
    {
        $now=gmdate('Y-m-d\TH:i:s\Z');$hour=gmdate('Y-m-d\TH:i:s\Z',time()-3600);$ten=gmdate('Y-m-d\TH:i:s\Z',time()-600);$ipHash=hash('sha256',trim($ip));$emailHash=hash('sha256',strtolower(trim($email)));DatabaseDialect::beginWrite($this->database);
        try{$count=$this->database->prepare('SELECT COUNT(*) FROM representation_anonymous_request_contact_log WHERE ip_hash=:ip AND sent_at>=:since');$count->execute([':ip'=>$ipHash,':since'=>$hour]);if((int)$count->fetchColumn()>=5)throw new DomainException('Zu viele Anfragen. Bitte versuche es später erneut.');$duplicate=$this->database->prepare("SELECT COUNT(*) FROM representation_anonymous_request_contact_log WHERE request_id=:request AND sender_email_hash=:email AND sent_at>=:since AND status IN ('started','success')");$duplicate->execute([':request'=>$requestId,':email'=>$emailHash,':since'=>$ten]);if((int)$duplicate->fetchColumn()>0)throw new DomainException('Diese Rückmeldung wurde kürzlich bereits gesendet.');$insert=$this->database->prepare("INSERT INTO representation_anonymous_request_contact_log(request_id,recipient_user_id,sender_email_hash,ip_hash,sent_at,status)VALUES(:request,:recipient,:email,:ip,:sent,'started')");$insert->execute([':request'=>$requestId,':recipient'=>$recipientId,':email'=>$emailHash,':ip'=>$ipHash,':sent'=>$now]);$id=(int)$this->database->lastInsertId();$this->database->exec('COMMIT');return $id;}catch(Throwable $e){try{$this->database->exec('ROLLBACK');}catch(Throwable){}throw $e;}
    }

    private function reserve(int $contactUserId, int $requestId, int $recipientId): int
    {
        $now=gmdate('Y-m-d\TH:i:s\Z');$hour=gmdate('Y-m-d\TH:i:s\Z',time()-3600);$ten=gmdate('Y-m-d\TH:i:s\Z',time()-600);DatabaseDialect::beginWrite($this->database);
        try { $offer=$this->database->prepare('SELECT COUNT(*) FROM representation_contact_log WHERE requester_user_id=:user AND sent_at>=:since');$offer->execute([':user'=>$contactUserId,':since'=>$hour]);$request=$this->database->prepare('SELECT COUNT(*) FROM representation_request_contact_log WHERE contact_user_id=:user AND sent_at>=:since');$request->execute([':user'=>$contactUserId,':since'=>$hour]);if((int)$offer->fetchColumn()+(int)$request->fetchColumn()>=10)throw new DomainException('Zu viele Anfragen. Bitte versuche es später erneut.');
            $duplicate=$this->database->prepare("SELECT COUNT(*) FROM representation_request_contact_log WHERE contact_user_id=:user AND request_id=:request AND sent_at>=:since AND status IN ('started','success')");$duplicate->execute([':user'=>$contactUserId,':request'=>$requestId,':since'=>$ten]);if((int)$duplicate->fetchColumn()>0)throw new DomainException('Diese Rückmeldung wurde kürzlich bereits gesendet.');
            $insert=$this->database->prepare("INSERT INTO representation_request_contact_log(contact_user_id,request_id,recipient_user_id,sent_at,status)VALUES(:user,:request,:recipient,:sent,'started')");$insert->execute([':user'=>$contactUserId,':request'=>$requestId,':recipient'=>$recipientId,':sent'=>$now]);$id=(int)$this->database->lastInsertId();$this->database->exec('COMMIT');return $id;
        } catch(Throwable $e){try{$this->database->exec('ROLLBACK');}catch(Throwable){}throw $e;}
    }
    private function finish(int $id,string $status):void{$s=$this->database->prepare('UPDATE representation_request_contact_log SET status=:status WHERE id=:id');$s->execute([':status'=>$status,':id'=>$id]);}
    private function finishAnonymous(int $id,string $status):void{$s=$this->database->prepare('UPDATE representation_anonymous_request_contact_log SET status=:status WHERE id=:id');$s->execute([':status'=>$status,':id'=>$id]);}
    private static function removeRawPlaceholders(string $value):string{return (string)preg_replace('/{{[^{}]+}}/','',$value);}
    private static function formatDate(DateTimeImmutable $date):string{return ['Montag','Dienstag','Mittwoch','Donnerstag','Freitag','Samstag','Sonntag'][(int)$date->format('N')-1].', '.$date->format('d.m.Y');}
}
