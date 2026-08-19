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
$identity = Auth::user();
if ($identity === null) JsonResponse::send(['error' => 'Admin-Anmeldung erforderlich.'], 401);
if (!Auth::isAdmin()) JsonResponse::send(['error' => 'Admin-Berechtigung erforderlich.'], 403);
Auth::requireCsrfJson();
try {
    $payload = json_decode(file_get_contents('php://input') ?: '', true, 8, JSON_THROW_ON_ERROR);
    if (!is_array($payload) || array_intersect(['email', 'recipient_email'], array_keys($payload)) !== []) JsonResponse::send(['error' => 'Eine Empfängeradresse darf nicht übermittelt werden.'], 400);
    $userId = filter_var($payload['userId'] ?? null, FILTER_VALIDATE_INT);
    if ($userId === false || $userId < 1) JsonResponse::send(['error' => 'Ungültiger Anwender.'], 400);
    if (!AccountFactory::create()['service']->requestPasswordResetForUser((int) $userId)) JsonResponse::send(['error' => 'Die Reset-E-Mail konnte nicht versendet werden.'], 500);
    JsonResponse::send(['sent' => true, 'message' => 'Der Link zum Zurücksetzen des Passworts wurde versendet.']);
} catch (JsonException) {
    JsonResponse::send(['error' => 'Ungültige Anfrage.'], 400);
} catch (AccountResetException $exception) {
    $status = match ($exception->reason) { 'user_not_found' => 404, 'invalid_role' => 403, default => 409 };
    JsonResponse::send(['error' => $exception->reason, 'message' => $exception->getMessage()], $status);
} catch (Throwable) {
    JsonResponse::send(['error' => 'Der Passwortreset konnte nicht versendet werden.'], 500);
}
