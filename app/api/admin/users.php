<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/Auth.php';
require_once dirname(__DIR__, 2) . '/src/AccountFactory.php';
require_once dirname(__DIR__, 2) . '/src/AccountService.php';
require_once dirname(__DIR__, 2) . '/src/Database.php';
require_once dirname(__DIR__, 2) . '/src/JsonResponse.php';
require_once dirname(__DIR__, 2) . '/src/MailService.php';
require_once dirname(__DIR__, 2) . '/src/MailSettingsRepository.php';
require_once dirname(__DIR__, 2) . '/src/UserRepository.php';

if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'DELETE'], true)) JsonResponse::send(['error' => 'Nur GET und DELETE sind erlaubt.'], 405);
$identity = Auth::user();
if ($identity === null) JsonResponse::send(['error' => 'Admin-Anmeldung erforderlich.'], 401);
if (!Auth::isAdmin()) JsonResponse::send(['error' => 'Admin-Berechtigung erforderlich.'], 403);

if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    Auth::requireCsrfJson();
    try {
        $payload = json_decode(file_get_contents('php://input') ?: '', true, 8, JSON_THROW_ON_ERROR);
        $userId = is_array($payload) ? filter_var($payload['userId'] ?? null, FILTER_VALIDATE_INT) : false;
        if ($userId === false || $userId < 1) JsonResponse::send(['error' => 'Ungültiger Anwender.'], 400);
        if (!AccountFactory::create()['service']->deleteAccount((int) $userId)) JsonResponse::send(['error' => 'Der Anwender wurde nicht gefunden.'], 404);
        JsonResponse::send(['deleted' => true, 'message' => 'Der Anwender wurde gelöscht.']);
    } catch (JsonException) { JsonResponse::send(['error' => 'Ungültige Anfrage.'], 400); }
    catch (DomainException) { JsonResponse::send(['error' => 'Administratorkonten können nicht gelöscht werden.'], 403); }
    catch (Throwable) { JsonResponse::send(['error' => 'Der Anwender konnte nicht gelöscht werden.'], 500); }
}

try {
    $timezone = new DateTimeZone('Europe/Berlin');
    $now = new DateTimeImmutable('now', $timezone);
    $users = (new UserRepository((new Database())->connection()))->adminUsersOverview($now->format('Y-m-d'), $now->modify('-30 days')->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'));
    $stats = [
        'total' => count($users),
        'active' => count(array_filter($users, static fn(array $user): bool => $user['status'] === 'active')),
        'verified' => count(array_filter($users, static fn(array $user): bool => $user['verificationStatus'] === 'manual_verified')),
        'withHomeChapter' => count(array_filter($users, static fn(array $user): bool => $user['homeChapterName'] !== null)),
    ];
    JsonResponse::send(['users' => $users, 'stats' => $stats]);
} catch (Throwable) {
    JsonResponse::send(['error' => 'Die Anwenderübersicht konnte nicht geladen werden.'], 500);
}
