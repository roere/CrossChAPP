<?php

declare(strict_types=1);

require_once __DIR__ . '/HomeChapterVerificationService.php';

final class AccountResetException extends DomainException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}

final class RegistrationException extends RuntimeException
{
    public function __construct(public readonly string $reason, public readonly string $technicalReason, string $message = 'Anmeldung momentan nicht möglich.')
    {
        parent::__construct($message);
    }
}

final class AccountService
{
    private readonly HomeChapterVerificationService $homeChapterVerification;

    public function __construct(private readonly UserRepository $users, private readonly MailSettingsRepository $mailSettings, private readonly MailService $mailer, private readonly ?BniMemberDirectoryService $directory = null)
    {
        $this->homeChapterVerification = new HomeChapterVerificationService($users, $directory);
    }

    /** @param array<string, mixed> $input @return array{user:array<string,mixed>,mailSent:bool,status:string} */
    public function register(array $input, string $ip = ''): array
    {
        $first = trim((string) ($input['first_name'] ?? '')); $last = trim((string) ($input['last_name'] ?? ''));
        $email = strtolower(trim((string) ($input['email'] ?? ''))); $password = (string) ($input['password'] ?? '');
        $confirmation = (string) ($input['password_confirmation'] ?? ''); $home = $input['home_chapter_org_id'] ?? null;
        $skipChapterVerification = $input['skip_chapter_verification'] ?? false;
        if (!is_bool($skipChapterVerification) || $skipChapterVerification) throw new InvalidArgumentException('Die Chapter-Prüfung darf bei der Selbstregistrierung nicht übersprungen werden.');
        if ($first === '' || strlen($first) > 120) throw new InvalidArgumentException('Bitte gib deinen Vornamen an.');
        if ($last === '' || strlen($last) > 120) throw new InvalidArgumentException('Bitte gib deinen Nachnamen an.');
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($email) > 254) throw new InvalidArgumentException('Bitte gib eine gültige E-Mail-Adresse an.');
        if (strlen($password) < 8) throw new InvalidArgumentException('Das Passwort muss mindestens 8 Zeichen lang sein.');
        if (!hash_equals($password, $confirmation)) throw new InvalidArgumentException('Die Passwörter stimmen nicht überein.');
        if (!$this->mailer->isReady()) throw new RegistrationException('registration_mail_unavailable', 'Mail transport is not configured or not ready');
        $homeId = $home === null || $home === '' ? null : filter_var($home, FILTER_VALIDATE_INT);
        if ($homeId === false || ($homeId !== null && !$this->users->isValidHomeChapter((int) $homeId))) throw new InvalidArgumentException('Das gewählte Heimatchapter ist ungültig.');
        if ($this->users->findByLogin($email) !== null) throw new DomainException('Für diese E-Mail-Adresse existiert bereits ein Konto.');
        $bniStatus = 'unverified'; $externalRef = null;
        if ($homeId !== null && !$skipChapterVerification) {
            try {
                $verification = $this->homeChapterVerification->verify($first, $last, (int) $homeId, false, $ip);
            } catch (HomeChapterVerificationException $exception) {
                throw new HomeChapterVerificationException($exception->reason, $exception->getMessage(), false, $exception->technicalReason, $exception->diagnosticMatches);
            }
            $bniStatus = $verification['verificationStatus'];
            $externalRef = $verification['externalRef'];
        } elseif ($homeId !== null) {
            $verification = $this->homeChapterVerification->verify($first, $last, (int) $homeId, true, $ip);
            $bniStatus = $verification['verificationStatus'];
        }
        try {
            return $this->users->transaction(function () use ($first, $last, $email, $password, $homeId, $bniStatus, $externalRef): array {
                $user = $this->users->create($first, $last, $email, password_hash($password, PASSWORD_DEFAULT), $homeId === null ? null : (int) $homeId, $bniStatus, $externalRef);
                if (!$this->sendVerification($user)) throw new RegistrationException('registration_mail_delivery_failed', 'Verification email transport failed');
                return ['user' => $user, 'mailSent' => true, 'status' => 'registered'];
            });
        } catch (PDOException $exception) {
            if ($exception->getCode() === '23000') throw new DomainException('Für diese E-Mail-Adresse existiert bereits ein Konto.');
            throw $exception;
        }
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
        $this->sendPasswordReset($user);
    }

    public function requestPasswordResetForUser(int $userId): bool
    {
        $user = $this->users->findById($userId);
        if ($user === null) throw new AccountResetException('user_not_found', 'Der ausgewählte Anwender wurde nicht gefunden.');
        if ($user['role'] !== 'user') throw new AccountResetException('invalid_role', 'Administratorkonten können hier nicht zurückgesetzt werden.');
        if (trim((string) ($user['email'] ?? '')) === '') throw new AccountResetException('email_missing', 'Für dieses Konto kann kein Passwortreset versendet werden, weil keine E-Mail-Adresse hinterlegt ist.');
        if ($user['email_verified_at'] === null) throw new AccountResetException('email_not_verified', 'Für dieses Konto kann kein Passwortreset versendet werden, weil die E-Mail-Adresse noch nicht bestätigt wurde.');
        if ($user['status'] !== 'active') throw new AccountResetException('account_inactive', 'Für dieses Konto kann kein Passwortreset versendet werden, weil das Konto nicht aktiv ist.');
        return $this->sendPasswordReset($user);
    }

    /** @param array<string,mixed> $user */
    private function sendPasswordReset(array $user): bool
    {
        $token = $this->users->issueToken('password_reset_tokens', (int) $user['id'], 3600);
        $settings = $this->mailSettings->settings(); $link = $settings['baseUrl'] . '/?reset=' . rawurlencode($token);
        $message = $this->mailSettings->render('reset_password', ['first_name' => $user['first_name'], 'last_name' => $user['last_name'], 'reset_link' => $link, 'app_name' => 'CrossChAPP']);
        try { $this->mailer->send($user['email'], trim($user['first_name'] . ' ' . $user['last_name']), $message['subject'], $message['body']); return true; }
        catch (RuntimeException) { return false; }
    }

    public function resetPassword(string $token, string $password, string $confirmation): bool
    {
        if (strlen($password) < 8) throw new InvalidArgumentException('Das Passwort muss mindestens 8 Zeichen lang sein.');
        if (!hash_equals($password, $confirmation)) throw new InvalidArgumentException('Die Passwörter stimmen nicht überein.');
        return $this->users->resetPassword($token, password_hash($password, PASSWORD_DEFAULT));
    }

    public function changePassword(int $userId, string $password, string $confirmation): bool
    {
        if (strlen($password) < 8) throw new InvalidArgumentException('Das Passwort muss mindestens 8 Zeichen lang sein.');
        if (!hash_equals($password, $confirmation)) throw new InvalidArgumentException('Die Passwörter stimmen nicht überein.');
        return $this->users->updatePassword($userId, password_hash($password, PASSWORD_DEFAULT));
    }

    /** @return array{firstName:string,lastName:string,email:string,homeChapterOrgId:?int,homeChapterName:?string,verificationStatus:string,canDelete:bool} */
    public function account(int $userId): array
    {
        $account = $this->users->accountDetails($userId);
        if ($account === null) throw new DomainException('Das Benutzerkonto wurde nicht gefunden.');
        return [
            'firstName' => (string) $account['first_name'],
            'lastName' => (string) $account['last_name'],
            'email' => (string) $account['email'],
            'homeChapterOrgId' => $account['home_chapter_org_id'] === null ? null : (int) $account['home_chapter_org_id'],
            'homeChapterName' => $account['home_chapter_name'] === null ? null : (string) $account['home_chapter_name'],
            'homeChapterShortLinkSlug'=>$account['home_chapter_short_link_slug']===null?null:(string)$account['home_chapter_short_link_slug'],
            'homeChapterShortLinkUrl'=>$account['home_chapter_short_link_slug']===null?null:rtrim((string)$this->mailSettings->settings()['baseUrl'],'/').'/'.(string)$account['home_chapter_short_link_slug'],
            'verificationStatus' => (string) $account['bni_verification_status'],
            'canDelete' => $account['role'] !== 'admin',
        ];
    }

    /** @return array{result:string,account:array<string,mixed>} */
    public function updateHomeChapter(int $userId, mixed $homeChapterOrgId, mixed $skipChapterVerification, string $ip = ''): array
    {
        if (!is_bool($skipChapterVerification)) throw new InvalidArgumentException('Die Auswahl zur Chapter-Prüfung ist ungültig.');
        $user = $this->users->findById($userId);
        if ($user === null) throw new DomainException('Das Benutzerkonto wurde nicht gefunden.');
        if (!in_array($user['role'], ['user', 'user_manager'], true)) throw new DomainException('Administratorkonten können hier nicht bearbeitet werden.');
        if ($homeChapterOrgId === null || $homeChapterOrgId === '') {
            if ($user['home_chapter_org_id'] === null) return ['result' => 'unchanged', 'account' => $this->account($userId)];
            $this->users->updateHomeChapterVerification($userId, null, 'unverified', null);
            return ['result' => 'removed', 'account' => $this->account($userId)];
        }
        $orgId = filter_var($homeChapterOrgId, FILTER_VALIDATE_INT);
        if ($orgId === false || !$this->users->isValidHomeChapter((int) $orgId)) throw new InvalidArgumentException('Das gewählte Heimatchapter ist ungültig.');
        if ((int) ($user['home_chapter_org_id'] ?? 0) === (int) $orgId && !$skipChapterVerification) {
            return ['result' => 'unchanged', 'account' => $this->account($userId)];
        }
        $verification = $this->homeChapterVerification->verify((string) $user['first_name'], (string) $user['last_name'], (int) $orgId, $skipChapterVerification, $ip);
        $this->users->updateHomeChapterVerification($userId, (int) $orgId, $verification['verificationStatus'], $verification['externalRef']);
        return ['result' => $verification['result'], 'account' => $this->account($userId)];
    }

    public function deleteAccount(int $userId): bool
    {
        return $this->users->deleteAccount($userId);
    }
}
