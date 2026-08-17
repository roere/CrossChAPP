<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/ChapterSearchService.php';
require_once dirname(__DIR__) . '/src/Database.php';
require_once dirname(__DIR__) . '/src/JsonResponse.php';
require_once dirname(__DIR__) . '/src/OrganizationRepository.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    JsonResponse::send(['error' => 'Nur GET ist erlaubt.'], 405);
}

try {
    $repository = new OrganizationRepository((new Database())->connection());
    $service = new ChapterSearchService($repository);
    JsonResponse::send(['data_basis' => $service->dataBasisCount()]);
} catch (Throwable) {
    JsonResponse::send(['error' => 'Die lokale Datengrundlage konnte nicht gelesen werden.'], 500);
}
