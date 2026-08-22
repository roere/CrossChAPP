<?php

declare(strict_types=1);

final class Auth
{
    private const IDENTITY_KEY = 'crosschapp_identity';
    private const CSRF_KEY = 'crosschapp_csrf';

    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        session_name('crosschapp_session');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => self::isHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }

    /** @param array<string, mixed> $user */
    public static function loginUser(array $user): void
    {
        self::start();
        session_regenerate_id(true);
        $_SESSION[self::IDENTITY_KEY] = [
            'user_id' => (int) $user['id'], 'role' => (string) $user['role'],
            'first_name' => (string) $user['first_name'], 'last_name' => (string) $user['last_name'],
            'email' => (string) $user['email'], 'username' => isset($user['username']) ? (string) $user['username'] : null,
        ];
    }

    public static function logout(): void
    {
        self::start();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
    }

    public static function isAdmin(): bool
    {
        return self::isFullAdmin();
    }

    public static function isFullAdmin(): bool
    {
        return self::role() === 'admin';
    }

    public static function canManageUsers(): bool
    {
        return in_array(self::role(), ['admin', 'user_manager'], true);
    }

    public static function canVerifyUsers(): bool
    {
        return self::canManageUsers();
    }

    public static function canInviteUsers(): bool
    {
        return self::canManageUsers();
    }

    public static function role(): ?string
    {
        self::start();
        $role = $_SESSION[self::IDENTITY_KEY]['role'] ?? null;
        return is_string($role) ? $role : null;
    }

    /** @return array<string, mixed>|null */
    public static function user(): ?array
    {
        self::start();
        $identity = $_SESSION[self::IDENTITY_KEY] ?? null;
        return is_array($identity) ? $identity : null;
    }

    public static function csrfToken(): string
    {
        self::start();
        if (!is_string($_SESSION[self::CSRF_KEY] ?? null)) $_SESSION[self::CSRF_KEY] = bin2hex(random_bytes(32));
        return $_SESSION[self::CSRF_KEY];
    }

    public static function requireCsrfJson(): void
    {
        self::start();
        $provided = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (!is_string($provided) || !hash_equals(self::csrfToken(), $provided)) {
            http_response_code(403); header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => 'Die Sicherheitsprüfung ist fehlgeschlagen.'], JSON_UNESCAPED_UNICODE); exit;
        }
    }

    public static function requireAdminJson(): void
    {
        if (self::isFullAdmin()) return;
        http_response_code(self::user() === null ? 401 : 403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => self::user() === null ? 'Admin-Anmeldung erforderlich.' : 'Admin-Berechtigung erforderlich.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    public static function requireUserManagementJson(): array
    {
        $user = self::user();
        if ($user === null) self::denyJson('Anmeldung erforderlich.', 401);
        if (!self::canManageUsers()) self::denyJson('Berechtigung zur Anwenderverwaltung erforderlich.', 403);
        return $user;
    }

    /** @return array<string, mixed> */
    public static function requireUserJson(): array
    {
        $user = self::user();
        if ($user !== null && is_int($user['user_id'] ?? null) && $user['user_id'] > 0) return $user;
        http_response_code(401); header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Anmeldung erforderlich.'], JSON_UNESCAPED_UNICODE); exit;
    }

    private static function isHttps(): bool
    {
        if (getenv('APP_ENV') === 'production') return true;
        if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== '' && $_SERVER['HTTPS'] !== 'off') return true;
        require_once __DIR__ . '/ClientIp.php';
        return ClientIp::isTrustedProxy() && strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    }

    private static function denyJson(string $message, int $status): never
    {
        http_response_code($status); header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => $message], JSON_UNESCAPED_UNICODE); exit;
    }
}
