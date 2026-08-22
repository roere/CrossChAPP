<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/Auth.php';
require_once dirname(__DIR__, 2) . '/src/Database.php';
require_once dirname(__DIR__, 2) . '/src/JsonResponse.php';
require_once dirname(__DIR__, 2) . '/src/UserRepository.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') JsonResponse::send(['error' => 'Nur POST ist erlaubt.'], 405);
$identity = Auth::requireUserManagementJson();
Auth::requireCsrfJson();
try {
    $payload = json_decode(file_get_contents('php://input') ?: '', true, 8, JSON_THROW_ON_ERROR);
    if (!is_array($payload) || array_keys($payload) !== ['userId']) JsonResponse::send(['error' => 'Ungültige Anfrage.'], 400);
    $userId = filter_var($payload['userId'], FILTER_VALIDATE_INT);
    if ($userId === false || $userId < 1) JsonResponse::send(['error' => 'Ungültiger Anwender.'], 400);
    (new UserRepository((new Database())->connection()))->manuallyVerify((int) $userId, (int) $identity['user_id']);
    JsonResponse::send(['verified' => true, 'message' => 'Der Anwender wurde verifiziert.']);
} catch (JsonException) { JsonResponse::send(['error' => 'Ungültige Anfrage.'], 400); }
catch (AdminUserVerificationException $exception) {
    $status = match ($exception->reason) { 'user_not_found' => 404, 'invalid_role', 'invalid_verifier' => 403, default => 409 };
    JsonResponse::send(['error' => $exception->reason, 'message' => $exception->getMessage()], $status);
} catch (Throwable) { JsonResponse::send(['error' => 'verification_failed', 'message' => 'Der Anwender konnte nicht verifiziert werden.'], 500); }
