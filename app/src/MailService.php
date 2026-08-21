<?php

declare(strict_types=1);

final class MailService
{
    public function __construct(private readonly MailSettingsRepository $repository, private readonly ?Closure $transport = null) {}

    public function isReady(): bool
    {
        if ($this->transport !== null) return true;
        if (getenv('CROSSCHAPP_TEST_MODE') === '1') {
            $capturePath = getenv('CROSSCHAPP_MAIL_CAPTURE_PATH');
            return is_string($capturePath) && trim($capturePath) !== '';
        }
        return is_file(dirname(__DIR__, 2) . '/vendor/autoload.php') && $this->repository->isMailConfigured();
    }

    public function send(string $email, string $name, string $subject, string $body, ?string $replyToEmail = null, ?string $replyToName = null): void
    {
        if (!$this->isReady()) throw new RuntimeException('Der E-Mail-Versand ist noch nicht konfiguriert.');
        if ($this->transport !== null) { ($this->transport)($email, $name, $subject, $body, $replyToEmail, $replyToName); return; }
        if (getenv('CROSSCHAPP_TEST_MODE') === '1') {
            $capturePath = getenv('CROSSCHAPP_MAIL_CAPTURE_PATH');
            if (!is_string($capturePath) || trim($capturePath) === '') throw new RuntimeException('Mail-Capture ist im Testmodus nicht konfiguriert.');
            $record = json_encode(['to'=>$email,'name'=>$name,'subject'=>$subject,'body'=>$body,'replyToEmail'=>$replyToEmail,'replyToName'=>$replyToName,'capturedAt'=>gmdate('c')], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            if ($record === false || @file_put_contents($capturePath, $record."\n", FILE_APPEND|LOCK_EX) === false) throw new RuntimeException('Test-Mail konnte nicht aufgezeichnet werden.');
            return;
        }
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
