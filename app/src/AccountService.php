<?php

declare(strict_types=1);

final class AccountService
{
    public function __construct(private readonly UserRepository $users, private readonly MailSettingsRepository $mailSettings, private readonly MailService $mailer) {}

    /** @param array<string, mixed> $input @return array{user:array<string,mixed>,mailSent:bool} */
    public function register(array $input): array
    {
        $first = trim((string) ($input['first_name'] ?? '')); $last = trim((string) ($input['last_name'] ?? ''));
        $email = strtolower(trim((string) ($input['email'] ?? ''))); $password = (string) ($input['password'] ?? '');
        $confirmation = (string) ($input['password_confirmation'] ?? ''); $home = $input['home_chapter_org_id'] ?? null;
        if ($first === '' || strlen($first) > 120) throw new InvalidArgumentException('Bitte gib deinen Vornamen an.');
        if ($last === '' || strlen($last) > 120) throw new InvalidArgumentException('Bitte gib deinen Nachnamen an.');
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($email) > 254) throw new InvalidArgumentException('Bitte gib eine gültige E-Mail-Adresse an.');
        if (strlen($password) < 8) throw new InvalidArgumentException('Das Passwort muss mindestens 8 Zeichen lang sein.');
        if (!hash_equals($password, $confirmation)) throw new InvalidArgumentException('Die Passwörter stimmen nicht überein.');
        $homeId = $home === null || $home === '' ? null : filter_var($home, FILTER_VALIDATE_INT);
        if ($homeId === false || ($homeId !== null && !$this->users->isValidHomeChapter((int) $homeId))) throw new InvalidArgumentException('Das gewählte Heimatchapter ist ungültig.');
        if ($this->users->findByLogin($email) !== null) throw new DomainException('Für diese E-Mail-Adresse existiert bereits ein Konto.');
        try { $user = $this->users->create($first, $last, $email, password_hash($password, PASSWORD_DEFAULT), $homeId === null ? null : (int) $homeId); }
        catch (PDOException $exception) { if ($exception->getCode() === '23000') throw new DomainException('Für diese E-Mail-Adresse existiert bereits ein Konto.'); throw $exception; }
        return ['user' => $user, 'mailSent' => $this->sendVerification($user)];
    }

    public function sendVerification(array $user): bool
    {
        $token = $this->users->issueToken('email_verification_tokens', (int) $user['id'], 86400);
        $settings = $this->mailSettings->settings(); $link = $settings['baseUrl'] . '/?verify=' . rawurlencode($token);
        $message = $this->mailSettings->render('verify_email', ['first_name' => $user['first_name'], 'last_name' => $user['last_name'], 'email' => $user['email'], 'verification_link' => $link, 'app_name' => 'CrossChAPP']);
        try { $this->mailer->send($user['email'], trim($user['first_name'] . ' ' . $user['last_name']), $message['subject'], $message['body']); return true; }
        catch (RuntimeException) { return false; }
    }

    /** @return array{status:string,user?:array<string,mixed>} */
    public function authenticate(string $login, string $password): array
    {
        $user = $this->users->findByLogin($login);
        $hash = is_array($user) ? (string) $user['password_hash'] : '$2y$10$OmyiJaA8dJ5QvpxK7SFdX.rNp6pT2Q7o7lfWRVvkl9dSfRPbl92Vq';
        if (!password_verify($password, $hash) || $user === null) return ['status' => 'invalid'];
        if ($user['status'] === 'pending' || $user['email_verified_at'] === null) return ['status' => 'pending'];
        if ($user['status'] !== 'active') return ['status' => 'invalid'];
        $this->users->markLogin((int) $user['id']); return ['status' => 'success', 'user' => $this->users->findById((int) $user['id'])];
    }

    public function verify(string $token): bool { return $this->users->verifyEmail($token); }
    public function verifyResult(string $token): string { return $this->users->verifyEmailResult($token); }

    public function requestPasswordReset(string $email): void
    {
        $user = $this->users->findByLogin($email); if ($user === null || $user['status'] !== 'active' || $user['email_verified_at'] === null) return;
        $token = $this->users->issueToken('password_reset_tokens', (int) $user['id'], 3600);
        $settings = $this->mailSettings->settings(); $link = $settings['baseUrl'] . '/?reset=' . rawurlencode($token);
        $message = $this->mailSettings->render('reset_password', ['first_name' => $user['first_name'], 'last_name' => $user['last_name'], 'reset_link' => $link, 'app_name' => 'CrossChAPP']);
        try { $this->mailer->send($user['email'], trim($user['first_name'] . ' ' . $user['last_name']), $message['subject'], $message['body']); } catch (RuntimeException) {}
    }

    public function resetPassword(string $token, string $password, string $confirmation): bool
    {
        if (strlen($password) < 8) throw new InvalidArgumentException('Das Passwort muss mindestens 8 Zeichen lang sein.');
        if (!hash_equals($password, $confirmation)) throw new InvalidArgumentException('Die Passwörter stimmen nicht überein.');
        return $this->users->resetPassword($token, password_hash($password, PASSWORD_DEFAULT));
    }
}
