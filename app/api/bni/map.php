<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/BniClient.php';
require_once dirname(__DIR__, 2) . '/src/Auth.php';
require_once dirname(__DIR__, 2) . '/src/Database.php';
require_once dirname(__DIR__, 2) . '/src/JsonResponse.php';
require_once dirname(__DIR__, 2) . '/src/OrganizationRepository.php';
require_once dirname(__DIR__, 2) . '/src/AutomationRepository.php';
require_once dirname(__DIR__, 2) . '/src/MapRefreshService.php';

Auth::requireAdminJson();
Auth::requireCsrfJson();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    JsonResponse::send(['error' => 'Nur GET ist erlaubt.'], 405);
}

$pageUrl = trim((string) ($_GET['url'] ?? ''));

try {
    BniClient::assertAllowedPageUrl($pageUrl);
    $database = (new Database())->connection();
    $repository = new OrganizationRepository($database);
    $result = (new MapRefreshService($repository, new AutomationRepository($database)))->refresh('map_manual');
    if ($result['status'] === 'skipped') JsonResponse::send(['error' => 'Ein Grunddatenimport läuft bereits.'], 409);
    if ($result['status'] !== 'success') JsonResponse::send(['error' => 'Die BNI-Grunddaten konnten derzeit nicht aktualisiert werden.', 'status' => $result['status']], 502);
    $statistics = $repository->statistics();
    JsonResponse::send([
        ...$statistics,
        'organizations' => $repository->all(),
    ]);
} catch (InvalidArgumentException $exception) {
    JsonResponse::send(['error' => $exception->getMessage()], 400);
} catch (Throwable $exception) {
    JsonResponse::send(['error' => $exception->getMessage()], 502);
}
