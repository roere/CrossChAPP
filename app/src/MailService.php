<?php

declare(strict_types=1);

final class MailService
{
    public function __construct(private readonly MailSettingsRepository $repository, private readonly ?Closure $transport = null) {}

    public function send(string $email, string $name, string $subject, string $body, ?string $replyToEmail = null, ?string $replyToName = null): void
    {
        if ($this->transport !== null) { ($this->transport)($email, $name, $subject, $body, $replyToEmail, $replyToName); return; }
        $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
        if (!is_file($autoload)) throw new RuntimeException('Der Mailversand ist nicht verfügbar.');
        require_once $autoload;
        $settings = $this->repository->settings(true);
        if (!$settings['smtpHost'] || !$settings['senderEmail']) throw new RuntimeException('Der E-Mail-Versand ist noch nicht konfiguriert.');
        try {
            $mailer = new PHPMailer\PHPMailer\PHPMailer(true); $mailer->isSMTP();
            $mailer->Host = (string) $settings['smtpHost']; $mailer->Port = (int) $settings['smtpPort'];
            $mailer->SMTPAuth = (string) $settings['smtpUsername'] !== '';
            $mailer->Username = (string) $settings['smtpUsername']; $mailer->Password = (string) $settings['smtpPassword'];
            if ($settings['encryption'] === 'starttls') $mailer->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            elseif ($settings['encryption'] === 'tls') $mailer->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
            else { $mailer->SMTPSecure = ''; $mailer->SMTPAutoTLS = false; }
            $mailer->CharSet = 'UTF-8'; $mailer->setFrom((string) $settings['senderEmail'], (string) $settings['senderName']);
            $mailer->addAddress($email, $name);
            if ($replyToEmail !== null && filter_var($replyToEmail, FILTER_VALIDATE_EMAIL)) $mailer->addReplyTo($replyToEmail, $replyToName ?? '');
            $mailer->Subject = $subject; $mailer->Body = $body; $mailer->send();
        } catch (Throwable) { throw new RuntimeException('Die E-Mail konnte nicht versendet werden.'); }
    }
}
