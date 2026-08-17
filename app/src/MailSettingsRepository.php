<?php

declare(strict_types=1);

final class MailSettingsRepository
{
    public function __construct(private readonly PDO $database) {}

    /** @return array<string, mixed> */
    public function settings(bool $includePassword = false): array
    {
        $row = $this->database->query('SELECT * FROM mail_settings WHERE id = 1')->fetch();
        if (!is_array($row)) throw new RuntimeException('E-Mail-Einstellungen fehlen.');
        return [
            'smtpHost' => $row['smtp_host'], 'smtpPort' => (int) $row['smtp_port'], 'smtpUsername' => $row['smtp_username'],
            'smtpPassword' => $includePassword ? $row['smtp_password'] : null, 'hasSmtpPassword' => is_string($row['smtp_password']) && $row['smtp_password'] !== '',
            'encryption' => $row['encryption'], 'senderEmail' => $row['sender_email'], 'senderName' => $row['sender_name'],
            'baseUrl' => $row['base_url'], 'updatedAt' => $row['updated_at'],
        ];
    }

    /** @param array<string, mixed> $settings */
    public function saveSettings(array $settings): void
    {
        $port = filter_var($settings['smtpPort'] ?? null, FILTER_VALIDATE_INT);
        $encryption = (string) ($settings['encryption'] ?? '');
        $senderEmail = trim((string) ($settings['senderEmail'] ?? ''));
        $baseUrl = rtrim(trim((string) ($settings['baseUrl'] ?? '')), '/');
        if ($port === false || $port < 1 || $port > 65535 || !in_array($encryption, ['starttls', 'tls', 'none'], true)
            || ($senderEmail !== '' && filter_var($senderEmail, FILTER_VALIDATE_EMAIL) === false)
            || filter_var($baseUrl, FILTER_VALIDATE_URL) === false || !in_array(parse_url($baseUrl, PHP_URL_SCHEME), ['http', 'https'], true)) {
            throw new InvalidArgumentException('Die E-Mail-Einstellungen sind ungültig.');
        }
        $current = $this->settings(true); $password = (string) ($settings['smtpPassword'] ?? '');
        if ($password === '') $password = (string) ($current['smtpPassword'] ?? '');
        $statement = $this->database->prepare(<<<'SQL'
            UPDATE mail_settings SET smtp_host = :host, smtp_port = :port, smtp_username = :username,
              smtp_password = :password, encryption = :encryption, sender_email = :sender_email,
              sender_name = :sender_name, base_url = :base_url, updated_at = :updated_at WHERE id = 1
            SQL);
        $statement->execute([':host' => trim((string) ($settings['smtpHost'] ?? '')), ':port' => $port, ':username' => trim((string) ($settings['smtpUsername'] ?? '')), ':password' => $password, ':encryption' => $encryption, ':sender_email' => $senderEmail, ':sender_name' => trim((string) ($settings['senderName'] ?? 'CrossChAPP')) ?: 'CrossChAPP', ':base_url' => $baseUrl, ':updated_at' => self::now()]);
    }

    /** @return array<string, array<string, string>> */
    public function templates(): array
    {
        $result = [];
        foreach ($this->database->query('SELECT * FROM email_templates ORDER BY template_key')->fetchAll() as $row) {
            $result[$row['template_key']] = ['subject' => $row['subject'], 'body' => $row['body'], 'updatedAt' => $row['updated_at']];
        }
        return $result;
    }

    public function saveTemplate(string $key, string $subject, string $body): void
    {
        if (!in_array($key, ['verify_email', 'reset_password'], true) || trim($subject) === '' || trim($body) === '' || strlen($subject) > 250 || strlen($body) > 20000) throw new InvalidArgumentException('Die E-Mail-Vorlage ist ungültig.');
        $statement = $this->database->prepare('UPDATE email_templates SET subject = :subject, body = :body, updated_at = :updated_at WHERE template_key = :key');
        $statement->execute([':subject' => trim($subject), ':body' => trim($body), ':updated_at' => self::now(), ':key' => $key]);
    }

    /** @param array<string, string> $variables @return array{subject:string,body:string} */
    public function render(string $key, array $variables): array
    {
        $templates = $this->templates(); if (!isset($templates[$key])) throw new RuntimeException('E-Mail-Vorlage fehlt.');
        $allowed = $key === 'verify_email' ? ['first_name', 'last_name', 'email', 'verification_link', 'app_name'] : ['first_name', 'last_name', 'reset_link', 'app_name'];
        $replace = [];
        foreach ($allowed as $name) $replace['{{' . $name . '}}'] = $variables[$name] ?? '';
        return ['subject' => strtr($templates[$key]['subject'], $replace), 'body' => strtr($templates[$key]['body'], $replace)];
    }

    private static function now(): string { return gmdate('Y-m-d\TH:i:s\Z'); }
}
