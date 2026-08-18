<?php

declare(strict_types=1);

final class RepresentationContactService
{
    public function __construct(private readonly PDO $database, private readonly RepresentationOfferRepository $offers,
        private readonly MailSettingsRepository $settings, private readonly MailService $mailer) {}

    /** @return array{subject:string,before:string,after:string,customMessage:string} */
    public function preview(int $requesterId, int $offerId, string $requestedDate, string $customMessage = ''): array
    {
        [, , $mail] = $this->compose($requesterId, $offerId, $requestedDate, $customMessage === '' ? $this->settings->offerCustomMessage() : $customMessage);
        return $mail;
    }

    public function send(int $requesterId, int $offerId, string $requestedDate, string $customMessage): void
    {
        [$context, $date, $mail] = $this->compose($requesterId, $offerId, $requestedDate, trim($customMessage));
        $logId = $this->reserve((int) $context['recipient_id'], $requesterId, $offerId, $requestedDate);
        $fullName = trim($context['requester_first_name'] . ' ' . $context['requester_last_name']);
        try {
            $this->mailer->send((string) $context['provider_email'], trim($context['provider_first_name'] . ' ' . $context['provider_last_name']), $mail['subject'], $mail['before'] . $mail['customMessage'] . $mail['after'], (string) $context['requester_email'], $fullName);
            $this->finish($logId, 'success');
        } catch (Throwable $exception) { $this->finish($logId, 'error'); throw new RuntimeException('Die Anfrage konnte nicht gesendet werden.'); }
    }

    /** @return array{0:array<string,mixed>,1:DateTimeImmutable,2:array{subject:string,before:string,after:string,customMessage:string}} */
    private function compose(int $requesterId, int $offerId, string $requestedDate, string $customMessage): array
    {
        $zone = new DateTimeZone('Europe/Berlin'); $today = new DateTimeImmutable('today', $zone);
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $requestedDate, $zone); $customMessage = trim($customMessage);
        if (!$date || $date->format('Y-m-d') !== $requestedDate || $date < $today || strlen($customMessage) < 20 || strlen($customMessage) > 3000) throw new InvalidArgumentException('Die Anfrage ist unvollständig oder ungültig.');
        $context = $this->offers->contactContext($offerId, $requesterId);
        if ($context === null) throw new DomainException('Dieses Vertretungsangebot ist nicht verfügbar.');
        if ((bool) $context['all_dates']) {
            if (self::weekday($date) !== self::normalizeWeekday((string) $context['meeting_day'])) throw new InvalidArgumentException('Der Termin passt nicht zum regulären Meeting-Wochentag.');
        } elseif (!$this->offers->offerHasDate($offerId, $requestedDate)) throw new InvalidArgumentException('Das Angebot gilt nicht für diesen Termin.');
        $fullName = trim($context['requester_first_name'] . ' ' . $context['requester_last_name']); $formattedDate = self::formatDate($date);
        $variables = ['provider_first_name' => (string) $context['provider_first_name'], 'requester_first_name' => (string) $context['requester_first_name'], 'requester_last_name' => (string) $context['requester_last_name'],
            'requester_full_name' => $fullName, 'requester_email' => (string) $context['requester_email'], 'requester_chapter' => (string) $context['requester_chapter'], 'requested_date' => $formattedDate, 'custom_message' => '{{custom_message}}', 'app_name' => 'CrossChAPP'];
        $template = $this->settings->templates()['representation_contact'] ?? throw new RuntimeException('E-Mail-Vorlage fehlt.');
        $rendered = $this->settings->render('representation_contact', $variables); $body = $rendered['body'];
        foreach (['requester_full_name', 'requester_email', 'requester_chapter', 'requested_date'] as $required) if (!str_contains($template['body'], '{{' . $required . '}}')) {
            $body .= "\n\n---\nAnfrage von:\n{$fullName}\n{$context['requester_email']}\nChapter: {$context['requester_chapter']}\nTermin: {$formattedDate}"; break;
        }
        [$before, $after] = array_pad(explode('{{custom_message}}', $body, 2), 2, '');
        $before = (string) preg_replace('/{{[^{}]+}}/', '', $before); $after = (string) preg_replace('/{{[^{}]+}}/', '', $after);
        return [$context, $date, ['subject' => $rendered['subject'], 'before' => $before, 'after' => $after, 'customMessage' => $customMessage]];
    }

    private function reserve(int $recipientId, int $requesterId, int $offerId, string $date): int
    {
        $now = gmdate('Y-m-d\TH:i:s\Z'); $hour = gmdate('Y-m-d\TH:i:s\Z', time() - 3600); $ten = gmdate('Y-m-d\TH:i:s\Z', time() - 600);
        $this->database->exec('BEGIN IMMEDIATE TRANSACTION');
        try {
            $count = $this->database->prepare('SELECT COUNT(*) FROM representation_contact_log WHERE requester_user_id = :user AND sent_at >= :since');
            $count->execute([':user' => $requesterId, ':since' => $hour]); $requestCount = $this->database->prepare('SELECT COUNT(*) FROM representation_request_contact_log WHERE contact_user_id = :user AND sent_at >= :since'); $requestCount->execute([':user' => $requesterId, ':since' => $hour]);
            if ((int) $count->fetchColumn() + (int) $requestCount->fetchColumn() >= 10) throw new DomainException('Zu viele Anfragen. Bitte versuche es später erneut.');
            $duplicate = $this->database->prepare("SELECT COUNT(*) FROM representation_contact_log WHERE requester_user_id = :user AND offer_id = :offer AND requested_date = :date AND sent_at >= :since AND status IN ('started','success')");
            $duplicate->execute([':user' => $requesterId, ':offer' => $offerId, ':date' => $date, ':since' => $ten]); if ((int) $duplicate->fetchColumn() > 0) throw new DomainException('Diese Anfrage wurde kürzlich bereits gesendet.');
            $insert = $this->database->prepare("INSERT INTO representation_contact_log (requester_user_id,offer_id,recipient_user_id,requested_date,sent_at,status) VALUES (:requester,:offer,:recipient,:date,:sent,'started')");
            $insert->execute([':requester' => $requesterId, ':offer' => $offerId, ':recipient' => $recipientId, ':date' => $date, ':sent' => $now]); $id = (int) $this->database->lastInsertId();
            $this->database->exec('COMMIT'); return $id;
        } catch (Throwable $exception) { try { $this->database->exec('ROLLBACK'); } catch (Throwable) {} throw $exception; }
    }

    private function finish(int $id, string $status): void { $statement = $this->database->prepare('UPDATE representation_contact_log SET status = :status WHERE id = :id'); $statement->execute([':status' => $status, ':id' => $id]); }
    private static function weekday(DateTimeImmutable $date): string { return ['Montag','Dienstag','Mittwoch','Donnerstag','Freitag','Samstag','Sonntag'][(int) $date->format('N') - 1]; }
    private static function normalizeWeekday(string $day): string { return ucfirst(strtolower(trim(explode(',', $day)[0]))); }
    private static function formatDate(DateTimeImmutable $date): string { return self::weekday($date) . ', ' . $date->format('d.m.Y'); }
}
