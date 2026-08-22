<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/Auth.php';
require_once dirname(__DIR__, 2) . '/src/Database.php';
require_once dirname(__DIR__, 2) . '/src/JsonResponse.php';
require_once dirname(__DIR__, 2) . '/src/UserRepository.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') JsonResponse::send(['error' => 'Nur POST ist erlaubt.'], 405);
Auth::requireAdminJson();
Auth::requireCsrfJson();

try {
    $payload = json_decode(file_get_contents('php://input') ?: '', true, 8, JSON_THROW_ON_ERROR);
    if (!is_array($payload) || count($payload) !== 2 || !array_key_exists('user_id', $payload) || !array_key_exists('role', $payload)) {
        JsonResponse::send(['error' => 'invalid_request', 'message' => 'Ungültige Anfrage.'], 400);
    }
    $userId = filter_var($payload['user_id'], FILTER_VALIDATE_INT);
    $role = is_string($payload['role']) ? $payload['role'] : '';
    if ($userId === false || $userId < 1) JsonResponse::send(['error' => 'invalid_user', 'message' => 'Ungültiger Anwender.'], 400);
    if (!in_array($role, ['user', 'user_manager'], true)) JsonResponse::send(['error' => 'invalid_role', 'message' => 'Die ausgewählte Rolle ist nicht zulässig.'], 422);
    (new UserRepository((new Database())->connection()))->updateRole((int) $userId, $role);
    JsonResponse::send(['updated' => true, 'role' => $role, 'message' => 'Die Rolle wurde geändert.']);
} catch (JsonException) {
    JsonResponse::send(['error' => 'invalid_request', 'message' => 'Ungültige Anfrage.'], 400);
} catch (AdminUserRoleException $exception) {
    $status = match ($exception->reason) { 'user_not_found' => 404, 'protected_role' => 403, 'invalid_role' => 422, default => 409 };
    JsonResponse::send(['error' => $exception->reason, 'message' => $exception->getMessage()], $status);
} catch (Throwable) {
    JsonResponse::send(['error' => 'role_update_failed', 'message' => 'Die Rolle konnte nicht geändert werden.'], 500);
}
