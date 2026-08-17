<?php

declare(strict_types=1);

final class Auth
{
    private const SESSION_KEY = 'crosschapp_admin';
    private const DEV_USERNAME = 'admin';
    private const DEV_PASSWORD_HASH = '$2y$10$/w.85OIJmzun7pFjgRPPaeD4Q4p.otU/T4wBIzkhPlniP1OY79sbW';

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

    public static function login(string $username, string $password): bool
    {
        self::start();
        $validUsername = hash_equals(self::DEV_USERNAME, $username);
        $validPassword = password_verify($password, self::DEV_PASSWORD_HASH);

        if (!$validUsername || !$validPassword) {
            return false;
        }

        session_regenerate_id(true);
        $_SESSION[self::SESSION_KEY] = true;
        return true;
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
        self::start();
        return ($_SESSION[self::SESSION_KEY] ?? false) === true;
    }

    public static function requireAdminJson(): void
    {
        if (self::isAdmin()) {
            return;
        }

        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Admin-Anmeldung erforderlich.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    private static function isHttps(): bool
    {
        return isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== '' && $_SERVER['HTTPS'] !== 'off';
    }
}
