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

if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'DELETE'], true)) JsonResponse::send(['error' => 'Nur GET und DELETE sind erlaubt.'], 405);
$identity = Auth::requireUserJson();
$account = AccountFactory::create();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try { JsonResponse::send(['account' => $account['service']->account((int) $identity['user_id'])]); }
    catch (DomainException) { JsonResponse::send(['error' => 'Das Benutzerkonto wurde nicht gefunden.'], 404); }
    catch (Throwable) { JsonResponse::send(['error' => 'Die Kontodaten konnten nicht geladen werden.'], 500); }
}

Auth::requireCsrfJson();
try {
    $raw = file_get_contents('php://input') ?: '';
    $payload = $raw === '' ? [] : json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
    if (!is_array($payload)) throw new JsonException('Ungültige Anfrage.');
    if (array_intersect(['id', 'user_id', 'userId'], array_keys($payload)) !== []) JsonResponse::send(['error' => 'Eine Benutzerwahl ist nicht erlaubt.'], 400);
    if (!$account['service']->deleteAccount((int) $identity['user_id'])) JsonResponse::send(['error' => 'Das Konto konnte nicht gelöscht werden.'], 404);
    Auth::logout();
    JsonResponse::send(['deleted' => true]);
} catch (JsonException) {
    JsonResponse::send(['error' => 'Ungültige Anfrage.'], 400);
} catch (DomainException) {
    JsonResponse::send(['error' => 'Administratorkonten können nicht gelöscht werden.'], 403);
} catch (Throwable) {
    JsonResponse::send(['error' => 'Das Konto konnte nicht gelöscht werden.'], 500);
}
