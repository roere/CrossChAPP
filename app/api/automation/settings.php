<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/Auth.php';
require_once dirname(__DIR__, 2) . '/src/AutomationRepository.php';
require_once dirname(__DIR__, 2) . '/src/Database.php';
require_once dirname(__DIR__, 2) . '/src/JsonResponse.php';

Auth::requireAdminJson();
$repository = new AutomationRepository((new Database())->connection());

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    JsonResponse::send(['settings' => $repository->settings()]);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    JsonResponse::send(['error' => 'Nur GET und POST sind erlaubt.'], 405);
}

try {
    $payload = json_decode(file_get_contents('php://input') ?: '', true, 32, JSON_THROW_ON_ERROR);
} catch (JsonException) {
    JsonResponse::send(['error' => 'Der Request enthält kein valides JSON.'], 400);
}

$usageEnabled = is_array($payload) ? ($payload['usage_refresh_enabled'] ?? null) : null;
$automaticEnabled = is_array($payload) ? ($payload['automatic_refresh_enabled'] ?? null) : null;
$usageDays = is_array($payload) ? filter_var($payload['usage_refresh_days'] ?? null, FILTER_VALIDATE_INT) : false;
$automaticDays = is_array($payload) ? filter_var($payload['automatic_refresh_days'] ?? null, FILTER_VALIDATE_INT) : false;
$dailyLimit = is_array($payload) ? filter_var($payload['automatic_refresh_daily_limit'] ?? null, FILTER_VALIDATE_INT) : false;
if (!is_bool($usageEnabled) || !is_bool($automaticEnabled) || $usageDays === false || $automaticDays === false || $dailyLimit === false) {
    JsonResponse::send(['error' => 'Die Automatisierungseinstellungen sind ungültig.'], 400);
}

try {
    $repository->updateSettings($usageEnabled, $usageDays, $automaticEnabled, $automaticDays, $dailyLimit);
    JsonResponse::send(['settings' => $repository->settings()]);
} catch (InvalidArgumentException $exception) {
    JsonResponse::send(['error' => $exception->getMessage()], 400);
}
