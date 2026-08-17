<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/Auth.php';
require_once dirname(__DIR__, 2) . '/src/BniRequestPolicy.php';
require_once dirname(__DIR__, 2) . '/src/Database.php';
require_once dirname(__DIR__, 2) . '/src/JsonResponse.php';
require_once dirname(__DIR__, 2) . '/src/OrganizationRepository.php';

Auth::requireAdminJson();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    JsonResponse::send(['error' => 'Nur GET ist erlaubt.'], 405);
}

$limitInput = (string) ($_GET['limit'] ?? '25');
if (!in_array($limitInput, ['10', '25', '50'], true)) {
    JsonResponse::send(['error' => 'Erlaubte Batch-Größen sind 10, 25 oder 50.'], 400);
}

try {
    $repository = new OrganizationRepository((new Database())->connection());
    JsonResponse::send([
        'limit' => (int) $limitInput,
        'detail_delay_ms' => BniRequestPolicy::DETAIL_DELAY_MS,
        'statistics' => $repository->chapterDetailStatistics(),
        'chapters' => $repository->findPendingChapters((int) $limitInput),
    ]);
} catch (Throwable) {
    JsonResponse::send(['error' => 'Die fehlenden Chapter konnten nicht ermittelt werden.'], 500);
}
