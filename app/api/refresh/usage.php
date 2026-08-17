<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/AutomationRepository.php';
require_once dirname(__DIR__, 2) . '/src/BniRequestPolicy.php';
require_once dirname(__DIR__, 2) . '/src/ChapterRefreshService.php';
require_once dirname(__DIR__, 2) . '/src/Database.php';
require_once dirname(__DIR__, 2) . '/src/JsonResponse.php';
require_once dirname(__DIR__, 2) . '/src/OrganizationRepository.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    JsonResponse::send(['error' => 'Nur POST ist erlaubt.'], 405);
}
try {
    $payload = json_decode(file_get_contents('php://input') ?: '', true, 16, JSON_THROW_ON_ERROR);
} catch (JsonException) {
    JsonResponse::send(['error' => 'Der Request enthält kein valides JSON.'], 400);
}
$orgId = is_array($payload) ? filter_var($payload['org_id'] ?? null, FILTER_VALIDATE_INT) : false;
$trigger = is_array($payload) ? (string) ($payload['trigger_type'] ?? '') : '';
if ($orgId === false || $orgId < 1 || !in_array($trigger, ['usage_search', 'usage_detail'], true)) {
    JsonResponse::send(['error' => 'Die Aktualisierungsanfrage ist ungültig.'], 400);
}

$database = (new Database())->connection();
$automation = new AutomationRepository($database);
$settings = $automation->settings();
if (!$settings['usageRefreshEnabled']) {
    JsonResponse::send(['result' => ['orgId' => $orgId, 'status' => 'skipped', 'reason' => 'disabled'], 'detail_delay_ms' => BniRequestPolicy::DETAIL_DELAY_MS]);
}
$service = new ChapterRefreshService(new OrganizationRepository($database), $automation);
$result = $service->refresh($orgId, $trigger, $settings['usageRefreshDays']);
JsonResponse::send(['result' => $result, 'detail_delay_ms' => BniRequestPolicy::DETAIL_DELAY_MS]);
