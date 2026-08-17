<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/AccountFactory.php';
require_once dirname(__DIR__, 2) . '/src/AccountService.php';
require_once dirname(__DIR__, 2) . '/src/Auth.php';
require_once dirname(__DIR__, 2) . '/src/Database.php';
require_once dirname(__DIR__, 2) . '/src/JsonResponse.php';
require_once dirname(__DIR__, 2) . '/src/MailService.php';
require_once dirname(__DIR__, 2) . '/src/MailSettingsRepository.php';
require_once dirname(__DIR__, 2) . '/src/UserRepository.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') JsonResponse::send(['error' => 'Nur POST ist erlaubt.'], 405);
$identity = Auth::requireUserJson();
Auth::requireCsrfJson();

try {
    $payload = json_decode(file_get_contents('php://input') ?: '', true, 8, JSON_THROW_ON_ERROR);
    $changed = AccountFactory::create()['service']->changePassword(
        (int) $identity['user_id'],
        (string) ($payload['password'] ?? ''),
        (string) ($payload['password_confirmation'] ?? '')
    );
    if (!$changed) JsonResponse::send(['error' => 'Das Passwort konnte nicht geändert werden.'], 400);
    JsonResponse::send(['changed' => true, 'message' => 'Passwort wurde erfolgreich geändert.']);
} catch (JsonException) {
    JsonResponse::send(['error' => 'Ungültige Anfrage.'], 400);
} catch (InvalidArgumentException $exception) {
    JsonResponse::send(['error' => $exception->getMessage()], 400);
} catch (Throwable) {
    JsonResponse::send(['error' => 'Das Passwort konnte nicht geändert werden.'], 500);
}
