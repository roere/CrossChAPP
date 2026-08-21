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
        $baseUrl = self::configuredBaseUrl() ?? (string) $row['base_url'];
        return [
            'smtpHost' => $row['smtp_host'], 'smtpPort' => (int) $row['smtp_port'], 'smtpUsername' => $row['smtp_username'],
            'smtpPassword' => $includePassword ? $row['smtp_password'] : null, 'hasSmtpPassword' => is_string($row['smtp_password']) && $row['smtp_password'] !== '',
            'encryption' => $row['encryption'], 'senderEmail' => $row['sender_email'], 'senderName' => $row['sender_name'],
            'baseUrl' => $baseUrl, 'updatedAt' => $row['updated_at'],
        ];
    }

    public function isMailConfigured(): bool
    {
        try { $settings = $this->settings(true); }
        catch (RuntimeException) { return false; }
        $host = trim((string) ($settings['smtpHost'] ?? ''));
        $port = (int) ($settings['smtpPort'] ?? 0);
        $username = trim((string) ($settings['smtpUsername'] ?? ''));
        $password = (string) ($settings['smtpPassword'] ?? '');
        $sender = trim((string) ($settings['senderEmail'] ?? ''));
        $encryption = (string) ($settings['encryption'] ?? '');
        if ($host === '' || $port < 1 || $port > 65535 || filter_var($sender, FILTER_VALIDATE_EMAIL) === false) return false;
        if (!in_array($encryption, ['starttls', 'tls', 'none'], true)) return false;
        return ($username === '') === ($password === '');
    }

    /** @param array<string, mixed> $settings */
    public function saveSettings(array $settings): void
    {
        $port = filter_var($settings['smtpPort'] ?? null, FILTER_VALIDATE_INT);
        $encryption = (string) ($settings['encryption'] ?? '');
        $senderEmail = trim((string) ($settings['senderEmail'] ?? ''));
        $baseUrl = self::configuredBaseUrl() ?? rtrim(trim((string) ($settings['baseUrl'] ?? '')), '/');
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

    private static function configuredBaseUrl(): ?string
    {
        $value = getenv('APP_BASE_URL');
        if (!is_string($value) || trim($value) === '') return null;
        $url = rtrim(trim($value), '/');
        if (filter_var($url, FILTER_VALIDATE_URL) === false || !in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true)) throw new RuntimeException('APP_BASE_URL ist ungültig.');
        return $url;
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
        if (!in_array($key, self::templateKeys(), true) || trim($subject) === '' || trim($body) === '' || strlen($subject) > 250 || strlen($body) > 20000) throw new InvalidArgumentException('Die E-Mail-Vorlage ist ungültig.');
        $statement = $this->database->prepare('UPDATE email_templates SET subject = :subject, body = :body, updated_at = :updated_at WHERE template_key = :key');
        $statement->execute([':subject' => trim($subject), ':body' => trim($body), ':updated_at' => self::now(), ':key' => $key]);
    }

    /** @param array<string, string> $variables @return array{subject:string,body:string} */
    public function render(string $key, array $variables): array
    {
        $templates = $this->templates(); if (!isset($templates[$key])) throw new RuntimeException('E-Mail-Vorlage fehlt.');
        $allowed = match ($key) {
            'verify_email' => ['first_name', 'last_name', 'email', 'verification_link', 'app_name'],
            'reset_password' => ['first_name', 'last_name', 'reset_link', 'app_name'],
            'representation_contact' => ['provider_first_name', 'requester_first_name', 'requester_last_name', 'requester_full_name', 'requester_email', 'requester_chapter', 'requested_date', 'custom_message', 'app_name'],
            'representation_request_contact' => ['request_owner_first_name', 'contact_first_name', 'contact_last_name', 'contact_full_name', 'contact_email', 'contact_chapter', 'requested_chapter', 'requested_date', 'custom_message', 'app_name'],
            'request_contact_acceptance', 'offer_contact_acceptance',
            'representation_assignment_confirmed_requester', 'representation_assignment_confirmed_representative',
            'representation_assignment_cancelled_requester', 'representation_assignment_cancelled_representative' => [
                'requester_first_name','requester_full_name','requester_email','representative_first_name',
                'representative_full_name','representative_email','chapter','requested_date','acceptance_link',
                'cancelled_by','custom_message','app_name','base_url'
            ],
            'user_invitation' => ['first_name', 'last_name', 'email', 'chapter', 'invitation_link', 'app_name'],
            default => throw new RuntimeException('E-Mail-Vorlage fehlt.'),
        };
        $replace = [];
        foreach ($allowed as $name) $replace['{{' . $name . '}}'] = $variables[$name] ?? '';
        $clean = static function (string $text): string {
            $text = (string) preg_replace('/{{(?!custom_message\b)[^{}]+}}/', '', $text);
            return (string) preg_replace('/\h+([,.;:!?])/', '$1', $text);
        };
        return ['subject' => $clean(strtr($templates[$key]['subject'], $replace)), 'body' => $clean(strtr($templates[$key]['body'], $replace))];
    }

    public function contactHint(): string
    {
        return (string) $this->database->query('SELECT contact_hint FROM representation_settings WHERE id = 1')->fetchColumn();
    }

    public function saveContactHint(string $hint): void
    {
        $hint = trim($hint);
        if ($hint === '' || strlen($hint) > 4000) throw new InvalidArgumentException('Der Hinweistext ist ungültig.');
        $statement = $this->database->prepare('UPDATE representation_settings SET contact_hint = :hint, updated_at = :updated WHERE id = 1');
        $statement->execute([':hint' => $hint, ':updated' => self::now()]);
    }

    public function requestContactHint(): string { return (string) $this->database->query('SELECT request_contact_hint FROM representation_settings WHERE id = 1')->fetchColumn(); }
    public function saveRequestContactHint(string $hint): void
    {
        $hint = trim($hint); if ($hint === '' || strlen($hint) > 4000) throw new InvalidArgumentException('Der Hinweistext ist ungültig.');
        $statement = $this->database->prepare('UPDATE representation_settings SET request_contact_hint = :hint, updated_at = :updated WHERE id = 1');
        $statement->execute([':hint' => $hint, ':updated' => self::now()]);
    }

    public function offerCustomMessage(): string { return (string) $this->database->query('SELECT offer_custom_message FROM representation_settings WHERE id = 1')->fetchColumn(); }
    public function requestCustomMessage(): string { return (string) $this->database->query('SELECT request_custom_message FROM representation_settings WHERE id = 1')->fetchColumn(); }

    public function saveCustomMessages(string $offerMessage, string $requestMessage): void
    {
        $offerMessage = trim($offerMessage); $requestMessage = trim($requestMessage);
        if (strlen($offerMessage) < 20 || strlen($offerMessage) > 3000 || strlen($requestMessage) < 20 || strlen($requestMessage) > 3000) throw new InvalidArgumentException('Die Standardnachrichten sind ungültig.');
        $statement = $this->database->prepare('UPDATE representation_settings SET offer_custom_message = :offer, request_custom_message = :request, updated_at = :updated WHERE id = 1');
        $statement->execute([':offer' => $offerMessage, ':request' => $requestMessage, ':updated' => self::now()]);
    }

    private static function now(): string { return gmdate('Y-m-d\TH:i:s\Z'); }

    /** @return list<string> */
    private static function templateKeys(): array
    {
        return ['verify_email','reset_password','representation_contact','representation_request_contact','user_invitation',
            'request_contact_acceptance','offer_contact_acceptance','representation_assignment_confirmed_requester',
            'representation_assignment_confirmed_representative','representation_assignment_cancelled_requester',
            'representation_assignment_cancelled_representative'];
    }
}
