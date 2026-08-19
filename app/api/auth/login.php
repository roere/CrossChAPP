<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/Auth.php';
require_once dirname(__DIR__, 2) . '/src/ClientIp.php';
require_once dirname(__DIR__, 2) . '/src/AccountFactory.php';
require_once dirname(__DIR__, 2) . '/src/AccountService.php';
require_once dirname(__DIR__, 2) . '/src/Database.php';
require_once dirname(__DIR__, 2) . '/src/JsonResponse.php';
require_once dirname(__DIR__, 2) . '/src/MailService.php';
require_once dirname(__DIR__, 2) . '/src/MailSettingsRepository.php';
require_once dirname(__DIR__, 2) . '/src/UserRepository.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    JsonResponse::send(['error' => 'Nur POST ist erlaubt.'], 405);
}
Auth::requireCsrfJson();

try {
    $payload = json_decode(file_get_contents('php://input') ?: '', true, 32, JSON_THROW_ON_ERROR);
} catch (JsonException) {
    JsonResponse::send(['error' => 'Der Request enthält kein valides JSON.'], 400);
}

$username = is_array($payload) ? trim((string) ($payload['login'] ?? $payload['username'] ?? '')) : '';
$password = is_array($payload) ? (string) ($payload['password'] ?? '') : '';
$account = AccountFactory::create(); $ip = ClientIp::address();
if ($account['users']->rateLimited('login', $username, $ip, 5, 900)) JsonResponse::send(['error' => 'Zu viele Anmeldeversuche. Bitte versuche es später erneut.'], 429);
$result = $account['service']->authenticate($username, $password);
if ($result['status'] === 'pending') {
    $account['users']->recordAttempt('login', $username, $ip, false);
    JsonResponse::send(['error' => 'Bitte bestätige zuerst deine E-Mail-Adresse.'], 403);
}
if ($result['status'] !== 'success') {
    $account['users']->recordAttempt('login', $username, $ip, false);
    JsonResponse::send(['error' => 'Benutzername oder Passwort ist nicht korrekt.'], 401);
}
$account['users']->recordAttempt('login', $username, $ip, true); Auth::loginUser($result['user']);
JsonResponse::send(['authenticated' => true, 'role' => $result['user']['role']]);
