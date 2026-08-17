<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/Auth.php';
require_once dirname(__DIR__, 2) . '/src/AutomationRepository.php';
require_once dirname(__DIR__, 2) . '/src/Database.php';
require_once dirname(__DIR__, 2) . '/src/JsonResponse.php';

Auth::requireAdminJson();
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    JsonResponse::send(['error' => 'Nur GET ist erlaubt.'], 405);
}
$repository = new AutomationRepository((new Database())->connection());
$settings = $repository->settings();
$statistics = $repository->statistics($settings['usageRefreshDays'], $settings['automaticRefreshDays'], $settings['automaticRefreshDailyLimit']);
$lastSeen = is_string($statistics['workerLastSeenAt'] ?? null) ? strtotime($statistics['workerLastSeenAt']) : false;
$statistics['workerActive'] = $lastSeen !== false && $lastSeen >= time() - ($settings['automaticRefreshIntervalMinutes'] * 120);
JsonResponse::send([
    'statistics' => $statistics,
]);
